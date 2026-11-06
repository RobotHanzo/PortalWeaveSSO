import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { safeUrl } = createRequire(import.meta.url)('../assets/provider-logo.js');

test('Provider logo preview accepts exact HTTPS media and remote image URLs', () => {
    for (const url of ['https://site.example/wp-content/uploads/2026/11/logo.png', 'https://cdn.example/logo.svg?v=2&size=64', 'https://site.example:8448/logo.webp']) {
        assert.equal(safeUrl(url), url);
    }
});

test('Provider logo preview refuses unsafe media selections and URL mutations', () => {
    for (const url of [undefined, null, {}, '', 'http://site.example/logo.png', '//site.example/logo.png', 'javascript:alert(1)', 'data:image/png;base64,opaque',
        'https://user:password@site.example/logo.png', 'https://@site.example/logo.png', 'https://site.example/logo.png#fragment',
        'https://site.example/logo\n.png', 'https://site.example/logo\\.png', 'https://site.example/logo|.png', ' https://site.example/logo.png',
        'https://', 'https://site.example/' + 'x'.repeat(2048)]) {
        assert.equal(safeUrl(url), '', String(url));
    }
});
