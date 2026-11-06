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

async function inspect(jar) {
    const response = await request('/?portalweave_sso_test_inspect=1', jar);
    assert.equal(response.status, 200, response.body);
    return JSON.parse(response.body);
}

async function administrator() {
    const jar = new Cookies();
    await request('/wp-login.php', jar);
    const body = new URLSearchParams({ log: 'portalweave_sso_e2e_admin', pwd: 'Local-E2E-Fixture-Password-Only-2026!',
        'wp-submit': 'Log In', redirect_to: `${origin}/wp-admin/`, testcookie: '1' }).toString();
    const response = await request('/wp-login.php', jar, { method: 'POST', body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
    assert.equal(response.status, 302, response.body.slice(0, 1000));
    const identity = await inspect(jar);
    assert.equal(identity.login, 'portalweave_sso_e2e_admin');
    assert.ok(identity.roles.includes('administrator'));
    return { jar, identity };
}

const authenticationCookies = jar => [...jar.values].filter(([name]) => /^wordpress_(?:logged_in|sec)_/.test(name)).sort();

async function draftForm(jar) {
    const response = await request('/wp-admin/admin.php?page=portalweave-sso&tab=providers&new_provider=1&setup_provider=custom', jar);
    assert.equal(response.status, 200, response.body.slice(0, 1000));
    const form = response.body.match(/<form\b[^>]*\bdata-setup-form\b[^>]*>([\s\S]*?)<\/form>/i)?.[1];
    assert.ok(form, 'The administrator guided setup form is missing');
    const nonce = form.match(/<input\b(?=[^>]*\bname="_wpnonce")(?=[^>]*\bvalue="([a-f0-9]+)")[^>]*>/i)?.[1];
    assert.ok(nonce, 'Guided setup did not provide its real WordPress form nonce');
    const id = `draft-${randomBytes(8).toString('hex')}`;
    const draft = {
        provider: 'custom', id, name: `Unsaved ${id}`, callback_registered: '1', protocol: 'oauth2',
        client_id: 'portalweave-sso-e2e-client', client_secret: 'local-only-draft-secret+with&opaque=bytes', credential_method: 'body',
        authorize_url: 'https://example.org/portalweave-sso-test-authorize', token_url: 'https://example.org/portalweave-sso-test-token',
        userinfo_url: 'https://example.org/portalweave-sso-test-userinfo', scope: 'profile email', subject_attribute: 'sub',
    };
    const params = new URLSearchParams({ action: 'portalweave_sso_save', _wpnonce: nonce, portalweave_sso_complete: '1',
        portalweave_sso_screen: 'providers', portalweave_sso_provider: '', portalweave_sso_creating: '1' });
    for (const [key, value] of Object.entries(draft)) params.set(`settings[provider_setup][${key}]`, value);
    return { params, draft };
}

async function submitDraft(jar, params, method = 'POST') {
    return request('/?portalweave_sso_action=test_setup', jar, {
        method,
        ...(method === 'POST' ? { body: params.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } } : {}),
    });
}

async function startDraft(jar, form) {
    const response = await submitDraft(jar, form.params);
    assert.equal(response.status, 302, `Draft test start failed: ${response.body.slice(0, 1200)}`);
    const authorize = new URL(response.headers.location);
    assert.equal(authorize.origin + authorize.pathname, form.draft.authorize_url);
    assert.equal(authorize.searchParams.get('client_id'), form.draft.client_id);
    assert.equal(authorize.searchParams.get('scope'), 'profile email');
    assert.equal(authorize.searchParams.get('response_type'), 'code');
    assert.equal(authorize.searchParams.get('code_challenge_method'), 'S256');
    assert.ok(authorize.searchParams.get('code_challenge')?.length >= 43);
    assert.ok(authorize.searchParams.get('state')?.length >= 43);
    assert.equal(authorize.searchParams.get('redirect_uri'), `${origin}/?portalweave_sso_action=callback`);
    assert.equal(authorize.searchParams.has('nonce'), false, 'OAuth2 unexpectedly used an OIDC nonce parameter');
    assert.ok(!authorize.href.includes(form.draft.client_secret), 'Draft credentials entered the authorization URL');
    const stateCookie = response.headers['set-cookie']?.find(value => value.startsWith('__Host-portalweave_sso_'));
    assert.ok(stateCookie && /; secure/i.test(stateCookie) && /; HttpOnly/i.test(stateCookie) && /SameSite=Lax/i.test(stateCookie), 'Draft test lost browser-bound state');
    noAuthCookies(response);
    return authorize;
}

async function providerIds(jar) {
    const response = await request('/wp-admin/admin.php?page=portalweave-sso&tab=providers', jar);
    assert.equal(response.status, 200, response.body.slice(0, 1000));
    assert.ok(response.body.includes('portalweave-sso-provider-list'), 'Saved provider list is missing');
    const ids = new Set();
    for (const match of response.body.matchAll(/<a\b[^>]*\bhref="([^"]+)"/gi)) {
        const url = new URL(match[1].replace(/&amp;|&#0*38;|&#x0*26;/gi, '&'), origin);
        if (url.origin === origin && url.searchParams.get('tab') === 'providers' && url.searchParams.has('provider')) ids.add(url.searchParams.get('provider'));
    }
    assert.ok(ids.has('e2e'), 'The previously saved fixture provider disappeared');
    return [...ids].sort();
}

async function assertDraftUnchanged(jar, before, cookies, saved, subject) {
    const after = await inspect(jar);
    assert.deepEqual({ id: after.id, login: after.login, roles: after.roles }, { id: before.id, login: before.login, roles: before.roles }, 'Draft testing changed the administrator account');
    assert.deepEqual(authenticationCookies(jar), cookies, 'Draft testing replaced the original administrator session');
    assert.deepEqual(await providerIds(jar), saved, 'Draft testing persisted or removed a provider');
    if (subject) {
        const users = await request(`/wp-admin/users.php?${new URLSearchParams({ s: subject })}`, jar);
        assert.equal(users.status, 200, users.body.slice(0, 1000));
        assert.match(users.body, /<table\b[^>]*\bclass=["'][^"']*\busers\b/, 'Unable to inspect the real administrator users table');
        assert.ok(users.body.includes(`value="${subject}"`), 'The administrator user search did not use the tested provider subject');
        assert.doesNotMatch(users.body, /\bid=["']user-\d+["']/, 'Connection testing created a provider user account');
    }
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

await test('The administrator sidebar opens PortalWeave SSO and existing Settings bookmarks still work', async () => {
    const { jar } = await administrator();
    for (const route of ['admin.php', 'options-general.php']) {
        const response = await request(`/wp-admin/${route}?page=portalweave-sso&tab=providers`, jar);
        assert.equal(response.status, 200, response.body.slice(0, 1000));
        assert.match(response.body, /id=["']toplevel_page_portalweave-sso["']/);
        assert.match(response.body, /href=["']admin\.php\?page=portalweave-sso["']/);
        assert.match(response.body, /portalweave-sso-provider-list/);
        assert.doesNotMatch(response.body, /href=["'][^"']*options-general\.php\?page=portalweave-sso/);
    }
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
    assert.ok(result.body.includes(`${origin}/wp-admin/admin.php?page=portalweave-sso`), 'Configuration test returned to the previous Settings route');
    noAuthCookies(result);
    const after = JSON.parse((await request('/?portalweave_sso_test_inspect=1', jar)).body);
    assert.equal(after.id, before.id, 'Test configuration impersonated the provider user');
    assert.equal(after.login, 'portalweave_sso_e2e_admin');
});

await test('Unsaved guided provider test completes real HTTPS PKCE without saving or signing in an account', async () => {
    const { jar, identity } = await administrator();
    const saved = await providerIds(jar);
    const cookies = authenticationCookies(jar);
    const form = await draftForm(jar);
    assert.ok(!saved.includes(form.draft.id));
    const authorize = await startDraft(jar, form);
    const subject = `draft-test-${randomBytes(8).toString('hex')}`;
    const result = await request(callback(authorize, { oauth2: true, nonce: '', sub: subject }), jar);
    assert.equal(result.status, 200, result.body);
    assert.match(result.body, /Connection test succeeded/);
    assert.match(result.body, /These details have not been saved/);
    assert.ok(result.body.includes(subject) && result.body.includes('email_verified'), 'Draft test did not display actual profile attributes');
    assert.ok(!result.body.includes(form.draft.client_secret), 'Draft test displayed the client secret');
    noAuthCookies(result);
    await assertDraftUnchanged(jar, identity, cookies, saved, subject);
});

await test('Unsaved provider callbacks reject a wrong browser and replay while preserving the original session', async () => {
    const { jar, identity } = await administrator();
    const saved = await providerIds(jar);
    const cookies = authenticationCookies(jar);
    const form = await draftForm(jar);
    const authorize = await startDraft(jar, form);
    const subject = `draft-replay-${randomBytes(8).toString('hex')}`;
    const url = callback(authorize, { oauth2: true, nonce: '', sub: subject });
    const wrong = await request(url, new Cookies());
    assert.equal(wrong.status, 403);
    assert.match(wrong.body, /portalweave_sso_state/);
    noAuthCookies(wrong);
    const before = new Cookies(jar);
    const result = await request(url, jar);
    assert.equal(result.status, 200, result.body);
    assert.ok(result.body.includes(subject));
    noAuthCookies(result);
    const replay = await request(url, before);
    assert.equal(replay.status, 403);
    assert.match(replay.body, /portalweave_sso_state/);
    noAuthCookies(replay);
    await assertDraftUnchanged(jar, identity, cookies, saved, subject);
});

await test('Unsaved test results require the initiating administrator session even with its state cookie', async () => {
    const first = await administrator();
    const second = await administrator();
    const saved = await providerIds(first.jar);
    const firstCookies = authenticationCookies(first.jar);
    const secondCookies = authenticationCookies(second.jar);
    assert.notDeepEqual(firstCookies, secondCookies, 'Fixture did not create independent administrator sessions');
    const form = await draftForm(first.jar);
    const authorize = await startDraft(first.jar, form);
    for (const [name, value] of first.jar.values) if (name.startsWith('__Host-portalweave_sso_')) second.jar.values.set(name, value);
    const subject = `draft-session-${randomBytes(8).toString('hex')}`;
    const response = await request(callback(authorize, { oauth2: true, nonce: '', sub: subject }), second.jar);
    assert.equal(response.status, 403);
    assert.match(response.body, /portalweave_sso_test_permission/);
    noAuthCookies(response);
    await assertDraftUnchanged(first.jar, first.identity, firstCookies, saved, subject);
    await assertDraftUnchanged(second.jar, second.identity, secondCookies, saved);
});

await test('Unsaved provider test rejects a mismatched PKCE verifier without changing accounts or settings', async () => {
    const { jar, identity } = await administrator();
    const saved = await providerIds(jar);
    const cookies = authenticationCookies(jar);
    const form = await draftForm(jar);
    const authorize = await startDraft(jar, form);
    const subject = `draft-pkce-${randomBytes(8).toString('hex')}`;
    const response = await request(callback(authorize, { oauth2: true, nonce: '', sub: subject, challenge: 'mismatched' }), jar);
    assert.equal(response.status, 403);
    assert.match(response.body, /portalweave_sso_http_status/);
    noAuthCookies(response);
    await assertDraftUnchanged(jar, identity, cookies, saved, subject);
});

await test('Unsaved test starts reject malformed nonce, incomplete forms and schema without persistence', async () => {
    const { jar, identity } = await administrator();
    const saved = await providerIds(jar);
    const cookies = authenticationCookies(jar);
    const cases = [
        ['GET', params => params, 'portalweave_sso_test_permission'],
        ['POST', params => { params.set('_wpnonce', 'forged'); }, 'portalweave_sso_test_permission'],
        ['POST', params => { params.delete('_wpnonce'); params.set('_wpnonce[nested]', 'forged'); }, 'portalweave_sso_test_permission'],
        ['POST', params => { params.delete('portalweave_sso_complete'); }, 'portalweave_sso_form_incomplete'],
        ['POST', params => { params.delete('settings[provider_setup][client_secret]'); params.set('settings[provider_setup][client_secret][nested]', 'fixture'); }, 'portalweave_sso_provider_setup'],
        ['POST', params => { params.set('settings[force_auth]', '1'); }, 'portalweave_sso_settings_scope'],
        ['POST', params => { params.set('settings[provider_setup][id]', 'e2e'); }, 'portalweave_sso_app_exists'],
    ];
    for (const [method, mutate, code] of cases) {
        const { params } = await draftForm(jar);
        mutate(params);
        const response = await submitDraft(jar, params, method);
        assert.equal(response.status, 403, `${code}: ${response.body.slice(0, 1000)}`);
        assert.ok(response.body.includes(code), `Expected ${code}: ${response.body.slice(0, 1000)}`);
        noAuthCookies(response);
        assert.ok(!(response.headers['set-cookie'] ?? []).some(value => value.startsWith('__Host-portalweave_sso_')), 'Rejected draft created OAuth state');
        await assertDraftUnchanged(jar, identity, cookies, saved);
    }
});

console.log(`${tests} HTTPS protocol tests, ${failures} failures`);
process.exitCode = failures ? 1 : 0;
finishReports();
