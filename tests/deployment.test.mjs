import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkWordPressRelease } from '../scripts/check-wordpress-release.mjs';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
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
  const root = mkdtempSync(path.join(tmpdir(), 'portalweave-sso-release-ref-'));
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
    assert.ok(path.basename(root).startsWith('portalweave-sso-release-ref-'));
    rmSync(root, { recursive: true, force: true });
  }
});

function workflow(name) {
  return readFileSync(new URL(`../.github/workflows/${name}.yml`, import.meta.url), 'utf8');
}

// These small readers inspect our job/dependency contract. Actionlint separately
// validates the complete YAML and GitHub expression schema on every CI run.
function jobs(source) {
  const entries = [...source.matchAll(/^  ([a-z][a-z0-9-]*):\r?\n/gm)];
  return new Map(entries.map((entry, index) => [entry[1], source.slice(entry.index, entries[index + 1]?.index ?? source.length)]));
}

function dependencies(job) {
  const value = job.match(/^    needs: (.+)$/m)?.[1].trim() ?? '';
  return value.startsWith('[') ? value.slice(1, -1).split(',').map(name => name.trim()) : value ? [value] : [];
}

test('Required CI and packaged releases cannot bypass WordPress directory compliance', () => {
  const ci = jobs(workflow('verify'));
  const prerequisite = [...dependencies(ci.get('required'))];
  const ancestors = new Set();
  while (prerequisite.length) {
    const name = prerequisite.pop();
    if (ancestors.has(name)) continue;
    assert.ok(ci.has(name), `Unknown CI prerequisite ${name}`);
    ancestors.add(name);
    prerequisite.push(...dependencies(ci.get(name)));
  }
  for (const name of ['quality', 'integration', 'multisite', 'directory', 'package']) {
    assert.ok(ancestors.has(name), `Required CI lost ${name}`);
  }
  assert.ok(dependencies(ci.get('package')).includes('directory'), 'A distribution can be uploaded before directory review');
  assert.match(ci.get('directory'), /uses: \.\/\.github\/workflows\/wordpress-review\.yml/);
  assert.match(ci.get('directory'), /checkout_ref: \$\{\{ inputs\.checkout_ref \|\| github\.sha \}\}/);
  const release = jobs(workflow('release'));
  assert.match(release.get('verify'), /uses: \.\/\.github\/workflows\/verify\.yml/);
  assert.match(release.get('verify'), /checkout_ref: \$\{\{ needs\.resolve\.outputs\.sha \}\}/);
  assert.ok(dependencies(release.get('deploy')).includes('verify'), 'Publication lost its full CI prerequisite');
  assert.ok(dependencies(release.get('deploy')).includes('candidate'), 'Publication lost artifact verification');
  assert.equal((workflow('release').match(/uses: \.\/\.github\/workflows\/wordpress-review\.yml/g) ?? []).length, 0,
    'A second official check in the same release run would collide with its native artifact');
});

test('Directory review retains native failures and always publishes complete parsed reports', () => {
  const review = workflow('wordpress-review');
  const steps = review.split(/^      - /m).slice(1);
  const native = steps.find(step => step.includes('uses: WordPress/plugin-check-action@'));
  assert.ok(native, 'The official WordPress check is missing');
  assert.doesNotMatch(review, /continue-on-error:\s*true|ignore-errors:\s*['"]?true|ignore-warnings:\s*['"]?true/);
  for (const marker of ['node scripts/plugin-check.mjs', 'node scripts/report-results.mjs', 'name: tests-plugin-check']) {
    const step = steps.find(candidate => candidate.includes(marker));
    assert.ok(step, `Missing reporting step ${marker}`);
    assert.match(step, /if: always\(\)/, `Reports would be skipped on native failure: ${marker}`);
  }
  assert.match(review, /\$RUNNER_TEMP\/plugin-check-results\.txt/);
  assert.match(review, /--expect plugin-check/);
  assert.match(review, /if-no-files-found: error/);
  assert.doesNotMatch(review, /--strict|strict:\s*true/,
    'Changing warnings into failures requires an explicit policy decision');
});

test('Release and directory defaults use the public PortalWeave package slug', () => {
  const ci = workflow('verify');
  const review = workflow('wordpress-review');
  const release = workflow('release');
  assert.match(ci, /default: portalweave-sso/);
  assert.match(review, /default: portalweave-sso/);
  assert.match(release, /package_slug="\$\{PLUGIN_SLUG:-portalweave-sso\}"/);
  for (const fallback of ci.matchAll(/inputs\.package_slug \|\| '([^']+)'/g)) {
    assert.equal(fallback[1], 'portalweave-sso', 'CI package consumers disagree with the public slug');
  }
  assert.match(ci, /node --check assets\/auth\.js/);
});
