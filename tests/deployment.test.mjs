import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkWordPressRelease } from '../scripts/check-wordpress-release.mjs';

function fixture(statuses) {
  const urls = [];
  return { urls, request: async (url, options) => {
    urls.push(url);
    assert.equal(options.redirect, 'error');
    assert.equal(new URL(url).origin, 'https://plugins.svn.wordpress.org');
    return { status: statuses.shift() };
  } };
}
test('SVN preflight requires an existing repository and an unused release version', async () => {
  const http = fixture([200, 404]);
  const result = await checkWordPressRelease({ slug: 'approved-plugin', version: '1.2.3', request: http.request });
  assert.equal(result.version, '1.2.3');
  assert.deepEqual(http.urls, ['https://plugins.svn.wordpress.org/approved-plugin/', 'https://plugins.svn.wordpress.org/approved-plugin/tags/1.2.3/']);
});
test('SVN preflight rejects missing approvals and immutable existing versions', async () => {
  await assert.rejects(checkWordPressRelease({ slug: 'approved-plugin', version: '1.2.3', request: fixture([404]).request }), /approval is required/);
  await assert.rejects(checkWordPressRelease({ slug: 'approved-plugin', version: '1.2.3', request: fixture([200, 200]).request }), /must not be overwritten/);
});
test('SVN preflight fails closed on service errors and network failures', async () => {
  await assert.rejects(checkWordPressRelease({ slug: 'approved-plugin', version: '1.2.3', request: fixture([200, 503]).request }), /HTTP 503/);
  await assert.rejects(checkWordPressRelease({ slug: 'approved-plugin', version: '1.2.3', request: async () => { throw new Error('network failed'); } }), /network failed/);
});
test('SVN preflight rejects URLs, traversal, invalid versions and missing configuration before any request', async () => {
  for (const [slug, version] of [['../../elsewhere', '1.2.3'], ['https://evil.example', '1.2.3'], ['', '1.2.3'], ['approved-plugin', 'v1.2.3'], ['approved-plugin', '1.2.3-beta'], ['approved-plugin', '01.2.3']]) {
    await assert.rejects(checkWordPressRelease({ slug, version, request: () => { assert.fail('Invalid configuration reached network'); } }));
  }
});
