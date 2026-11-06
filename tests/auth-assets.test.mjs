import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/auth.js', import.meta.url), 'utf8');

function run({ mode = 'relay', fragment = '', opener = null, redirect = 'https://site.example/members/?q=%3Cscript%3E' } = {}) {
    const result = { inputs: [], messages: [], replacements: [], closed: false, submitted: false };
    const status = {};
    const form = {
        appendChild(input) { result.inputs.push(input); },
        submit() { result.submitted = true; },
    };
    vm.runInNewContext(source, {
        URLSearchParams,
        document: {
            body: { dataset: { wpssoMode: mode, wpssoRedirect: redirect, wpssoOrigin: 'https://site.example:443', wpssoNoResponse: '<script>missing response</script>' } },
            getElementById(id) { return id === 'wpsso-relay' ? form : status; },
            createElement(tag) { assert.equal(tag, 'input'); return {}; },
        },
        window: {
            opener: opener ? { postMessage(message, origin) { result.messages.push({ ...message, origin }); } } : null,
            location: { hash: fragment, pathname: '/', search: '?wpsso_action=callback', replace(url) { result.replacements.push(url); } },
            history: { replaceState(state, title, url) { result.history = [state, title, url]; } },
            close() { result.closed = true; },
        },
    });
    return { ...result, status };
}

test('Implicit relay preserves opaque credentials as input values and removes the fragment', () => {
    const token = '<img src=x onerror=alert(1)>+\\opaque"\'token';
    const fragment = '#' + new URLSearchParams({ state: 'browser-state', id_token: token, access_token: 'access', token_type: 'Bearer', arbitrary: 'ignored' });
    const result = run({ fragment });
    assert.equal(result.submitted, true);
    assert.deepEqual(result.history, [null, '', '/?wpsso_action=callback']);
    assert.deepEqual(result.inputs.map(input => input.name), ['state', 'id_token', 'access_token', 'token_type']);
    assert.equal(result.inputs.find(input => input.name === 'id_token').value, token);
    assert.ok(result.inputs.every(input => input.type === 'hidden' && !('innerHTML' in input)));
});

test('Implicit relay reports missing state as text without submitting', () => {
    const result = run({ fragment: '#access_token=unbound-token' });
    assert.equal(result.submitted, false);
    assert.equal(result.status.textContent, '<script>missing response</script>');
    assert.equal(result.status.innerHTML, undefined);
});

test('Popup completion posts only to the explicit WordPress origin then closes', () => {
    const result = run({ mode: 'popup', opener: true });
    assert.deepEqual(result.messages, [{ type: 'wpsso-complete', redirect: 'https://site.example/members/?q=%3Cscript%3E', origin: 'https://site.example:443' }]);
    assert.equal(result.closed, true);
    assert.deepEqual(result.replacements, []);
});

test('Popup completion navigates to the server-validated redirect without an opener', () => {
    const result = run({ mode: 'popup' });
    assert.deepEqual(result.replacements, ['https://site.example/members/?q=%3Cscript%3E']);
    assert.equal(result.closed, false);
    assert.deepEqual(result.messages, []);
});
