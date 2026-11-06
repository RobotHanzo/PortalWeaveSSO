import https from 'node:https';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { reportGuard } from './report.mjs';

// The only TLS exception is the self-signed, loopback-only disposable fixture.
const origin = 'https://localhost:8448';
let failures = 0;
let tests = 0;
const results = [];
const finishReports = reportGuard('https', results);

class Cookies {
    constructor(source) { this.values = new Map(source?.values ?? []); }
    accept(headers = []) {
        for (const header of headers) {
            const pair = header.split(';', 1)[0];
            const offset = pair.indexOf('=');
            if (offset < 1) continue;
            const name = pair.slice(0, offset);
            const value = pair.slice(offset + 1);
            if (!value || /max-age=0(?:;|$)/i.test(header)) this.values.delete(name);
            else this.values.set(name, value);
        }
    }
    header() { return [...this.values].map(([key, value]) => `${key}=${value}`).join('; '); }
}

function request(path, jar = new Cookies(), { method = 'GET', body, headers = {} } = {}) {
    const url = new URL(path, origin);
    assert.equal(url.origin, origin, 'Tests must never send requests or cookies outside loopback');
    return new Promise((resolve, reject) => {
        const req = https.request(url, {
            method, rejectUnauthorized: false, family: 4,
            headers: { 'User-Agent': 'PortalWeave-SSO-local-protocol-test', Cookie: jar.header(), ...headers,
                ...(body === undefined ? {} : { 'Content-Length': Buffer.byteLength(body) }) },
        }, response => {
            const chunks = [];
            let length = 0;
            response.on('data', chunk => {
                length += chunk.length;
                if (length > 1048576) { req.destroy(new Error('Unexpectedly large response')); return; }
                chunks.push(chunk);
            });
            response.on('end', () => {
                jar.accept(response.headers['set-cookie']);
                resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks).toString('utf8') });
            });
        });
        req.on('error', reject);
        req.setTimeout(15000, () => req.destroy(new Error('Local test request timed out')));
        if (body !== undefined) req.write(body);
        req.end();
    });
}

async function test(name, work) {
    tests++;
    const started = performance.now();
    try {
        await work();
        results.push({ name, pass: true, duration: (performance.now() - started) / 1000 });
        console.log(`PASS ${name}`);
    } catch (error) {
        failures++;
        results.push({ name, pass: false, duration: (performance.now() - started) / 1000, error: String(error.stack ?? error) });
        console.error(`FAIL ${name}: ${error.stack ?? error}`);
    }
}

async function start(jar, { redirect = `${origin}/origin-login-initiator/`, url } = {}) {
    const target = url ?? `/?${new URLSearchParams({ portalweave_sso_action: 'login', app: 'e2e', redirect_to: redirect })}`;
    const response = await request(target, jar);
    assert.equal(response.status, 302, `Login start failed: ${response.body.slice(0, 1200)}`);
    const authorize = new URL(response.headers.location);
    assert.equal(authorize.origin, 'https://example.org');
    assert.equal(authorize.searchParams.get('response_type'), 'code');
    assert.equal(authorize.searchParams.get('code_challenge_method'), 'S256');
    assert.ok(authorize.searchParams.get('state')?.length >= 43);
    assert.ok(authorize.searchParams.get('nonce')?.length >= 43);
    assert.equal(authorize.searchParams.get('redirect_uri'), `${origin}/?portalweave_sso_action=callback`);
    const stateCookie = response.headers['set-cookie']?.find(value => value.startsWith('__Host-portalweave_sso_'));
    assert.ok(stateCookie && /; secure/i.test(stateCookie) && /; HttpOnly/i.test(stateCookie) && /SameSite=Lax/i.test(stateCookie), 'Missing hardened state cookie');
    return authorize;
}

function callback(authorize, extra = {}) {
    const fixture = { nonce: authorize.searchParams.get('nonce'), challenge: authorize.searchParams.get('code_challenge'),
        sub: `e2e-${randomBytes(8).toString('hex')}`, ...extra };
    const code = Buffer.from(JSON.stringify(fixture)).toString('base64url');
    return `${origin}/?${new URLSearchParams({ portalweave_sso_action: 'callback', state: authorize.searchParams.get('state'), code })}`;
}

function noAuthCookies(response) {
    assert.ok(!(response.headers['set-cookie'] ?? []).some(cookie => /^wordpress_(?:logged_in|sec)_/.test(cookie)), 'Rejected login set a WordPress auth cookie');
}

await test('HTTPS authorization-code PKCE login preserves initiator and creates subscriber session', async () => {
    const jar = new Cookies();
    const authorize = await start(jar);
    const result = await request(callback(authorize), jar);
    assert.equal(result.status, 302, result.body);
    assert.equal(result.headers.location, `${origin}/origin-login-initiator/`);
    assert.ok((result.headers['set-cookie'] ?? []).some(cookie => /^wordpress_logged_in_/.test(cookie) && /; secure/i.test(cookie) && /; HttpOnly/i.test(cookie)), 'Secure login cookie was not emitted');
    const identity = JSON.parse((await request('/?portalweave_sso_test_inspect=1', jar)).body);
    assert.ok(identity.id > 0 && identity.login.startsWith('e2e-'), 'Authenticated user was not resolved');
    assert.deepEqual(identity.roles, ['subscriber']);
});

await test('Forged callback state fails before a session is created', async () => {
    const result = await request('/?portalweave_sso_action=callback&state=attacker-selected-state&code=forged');
    assert.equal(result.status, 403);
    assert.match(result.body, /portalweave_sso_state/);
    noAuthCookies(result);
});

await test('Wrong-browser state fails, valid browser succeeds once, and replay is rejected', async () => {
    const jar = new Cookies();
    const authorize = await start(jar);
    const url = callback(authorize);
    const wrong = await request(url, new Cookies());
    assert.equal(wrong.status, 403);
    noAuthCookies(wrong);
    const before = new Cookies(jar);
    const success = await request(url, jar);
    assert.equal(success.status, 302, success.body);
    const replay = await request(url, before);
    assert.equal(replay.status, 403);
    assert.match(replay.body, /portalweave_sso_state/);
    noAuthCookies(replay);
});

await test('Token endpoint rejects a code with a mismatched PKCE challenge', async () => {
    const jar = new Cookies();
    const authorize = await start(jar);
    const result = await request(callback(authorize, { challenge: 'wrong-challenge' }), jar);
    assert.equal(result.status, 403);
    assert.match(result.body, /portalweave_sso_http_status/);
    noAuthCookies(result);
});

await test('Unlisted external login redirect falls back to the canonical site', async () => {
    const jar = new Cookies();
    const authorize = await start(jar, { redirect: 'https://evil.example.net/capture' });
    const result = await request(callback(authorize), jar);
    assert.equal(result.status, 302, result.body);
    assert.equal(result.headers.location, `${origin}/`);
});

await test('Test configuration requires an authenticated administrator and a fresh nonce', async () => {
    const result = await request('/?portalweave_sso_action=login&app=e2e&test=1&_wpnonce=forged');
    assert.equal(result.status, 403);
    assert.match(result.body, /portalweave_sso_test_permission/);
    noAuthCookies(result);
});

await test('Administrator Test SSO Config displays attributes and preserves the original administrator session', async () => {
    const jar = new Cookies();
    await request('/wp-login.php', jar);
    const form = new URLSearchParams({ log: 'portalweave_sso_e2e_admin', pwd: 'Local-E2E-Fixture-Password-Only-2026!',
        'wp-submit': 'Log In', redirect_to: `${origin}/wp-admin/`, testcookie: '1' }).toString();
    const login = await request('/wp-login.php', jar, { method: 'POST', body: form, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
    assert.equal(login.status, 302, login.body.slice(0, 1000));
    const before = JSON.parse((await request('/?portalweave_sso_test_inspect=1', jar)).body);
    assert.equal(before.login, 'portalweave_sso_e2e_admin');
    assert.ok(before.test_url);
    const authorize = await start(jar, { url: before.test_url });
    const result = await request(callback(authorize, { sub: `e2e-test-only-${randomBytes(5).toString('hex')}` }), jar);
    assert.equal(result.status, 200, result.body);
    assert.match(result.body, /email_verified/);
    assert.match(result.body, /e2e-test-only-/);
    noAuthCookies(result);
    const after = JSON.parse((await request('/?portalweave_sso_test_inspect=1', jar)).body);
    assert.equal(after.id, before.id, 'Test configuration impersonated the provider user');
    assert.equal(after.login, 'portalweave_sso_e2e_admin');
});

console.log(`${tests} HTTPS protocol tests, ${failures} failures`);
process.exitCode = failures ? 1 : 0;
finishReports();
