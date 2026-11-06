import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkWordPressRelease } from '../scripts/check-wordpress-release.mjs';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

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

test('Remote tag guard accepts lightweight and annotated tags and rejects moved refs', { skip: process.platform === 'win32' && 'Bash ref verification runs on the Linux CI runner' }, () => {
  const root = mkdtempSync(path.join(tmpdir(), 'wpsso-release-ref-'));
  const source = path.join(root, 'source');
  const origin = path.join(root, 'origin.git');
  const script = fileURLToPath(new URL('../scripts/check-release-ref.sh', import.meta.url));
  const git = (...args) => execFileSync('git', ['-c', 'user.name=CI Fixture', '-c', 'user.email=fixture@example.org', ...args], { cwd: source, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  try {
    execFileSync('git', ['init', '--bare', origin], { stdio: 'ignore' });
    execFileSync('git', ['clone', origin, source], { stdio: 'ignore' });
    git('commit', '--allow-empty', '-m', 'fixture');
    const commit = git('rev-parse', 'HEAD');
    git('tag', 'v1.2.3');
    git('tag', '-a', 'v1.2.4', '-m', 'annotated fixture');
    git('push', 'origin', '--tags');
    for (const tag of ['v1.2.3', 'v1.2.4']) {
      assert.equal(spawnSync('bash', [script, tag, commit], { cwd: source, encoding: 'utf8' }).status, 0);
    }
    git('commit', '--allow-empty', '-m', 'fixture change');
    git('tag', '-f', 'v1.2.3');
    git('push', '--force', 'origin', 'refs/tags/v1.2.3'); // Only the disposable local bare repository.
    const moved = spawnSync('bash', [script, 'v1.2.3', commit], { cwd: source, encoding: 'utf8' });
    assert.equal(moved.status, 1);
    assert.match(moved.stderr, /refusing publication/);
    assert.equal(spawnSync('bash', [script, '--malformed', commit], { cwd: source, encoding: 'utf8' }).status, 1);
  } finally {
    assert.equal(path.dirname(root), path.resolve(tmpdir()));
    assert.ok(path.basename(root).startsWith('wpsso-release-ref-'));
    rmSync(root, { recursive: true, force: true });
  }
});
