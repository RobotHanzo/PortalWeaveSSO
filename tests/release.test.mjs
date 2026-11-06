import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, rmSync, symlinkSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { validateRelease, stageRelease, verifyStage } from '../scripts/release.mjs';

const plugin = `<?php
/**
 * Plugin Name: PortalWeave SSO
 * Version: 1.2.3
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: portalweave-sso
 * Domain Path: /languages
 */
define('PORTALWEAVE_SSO_VERSION', '1.2.3');
`;
const readme = `=== PortalWeave SSO ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.2.3
License: GPLv2 or later

== Changelog ==
= 1.2.3 =
Fixture release.
`;

function fixture(work) {
  const root = mkdtempSync(path.join(os.tmpdir(), 'portalweave-sso-release-'));
  try {
    for (const dir of ['includes', 'assets', 'docs', 'languages']) mkdirSync(path.join(root, dir));
    for (const [name, value] of Object.entries({
      'portalweave-sso.php': plugin, 'readme.txt': readme, 'uninstall.php': '<?php // fixture',
      'README.md': '# Fixture', LICENSE: 'GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\n',
      'includes/class-fixture.php': '<?php // production', 'assets/login.js': '// production',
      'languages/portalweave-sso.pot': 'msgid ""\nmsgstr ""\n"X-Domain: portalweave-sso\\n"\n',
      'docs/provider-setup.md': '# Provider setup', 'docs/integrations.md': '# Integrations',
      'docs/external-services.md': '# External services', 'docs/privacy.md': '# Privacy',
      'docs/setup.md': '# Excluded draft', 'docs/verification.md': '# Development evidence',
      'docs/releasing.md': '# Development releases', 'docs/directory-review.md': '# Review notes',
      '.env': 'SECRET=must-not-deploy', 'compose.test.yml': 'test: fixture',
    })) writeFileSync(path.join(root, name), value);
    mkdirSync(path.join(root, 'tests'));
    writeFileSync(path.join(root, 'tests/local-idp.php'), '<?php // must not deploy');
    return work(root);
  } finally { rmSync(root, { recursive: true, force: true }); }
}

test('release metadata matches PHP header, constant, readme and stable tag', () => fixture(root => {
  assert.deepEqual(validateRelease({ root, tag: 'v1.2.3' }), {
    version: '1.2.3', php: '8.1', wordpress: '6.5', slug: 'portalweave-sso',
  });
}));

test('version mismatches and prereleases cannot become marketplace releases', () => fixture(root => {
  for (const replacement of ["define('PORTALWEAVE_SSO_VERSION', '9.9.9');", "define('PORTALWEAVE_SSO_VERSION', '1.2.3-beta.1');"]) {
    writeFileSync(path.join(root, 'portalweave-sso.php'), plugin.replace("define('PORTALWEAVE_SSO_VERSION', '1.2.3');", replacement));
    assert.throws(() => validateRelease({ root }), /version/i);
  }
  writeFileSync(path.join(root, 'portalweave-sso.php'), plugin);
  for (const tag of ['v1.2.4', '1.2.3', 'v1.2.3-rc.1', 'refs/heads/main']) {
    assert.throws(() => validateRelease({ root, tag }), /tag/i);
  }
  writeFileSync(path.join(root, 'readme.txt'), readme.replace('Stable tag: 1.2.3', 'Stable tag: trunk'));
  assert.throws(() => validateRelease({ root }), /stable tag/i);
}));

test('minimum runtime headers must agree across the distributed files', () => fixture(root => {
  for (const [from, to] of [['Requires PHP: 8.1', 'Requires PHP: 8.2'], ['Requires at least: 6.5', 'Requires at least: 6.6']]) {
    writeFileSync(path.join(root, 'readme.txt'), readme.replace(from, to));
    assert.throws(() => validateRelease({ root }), /minimum|requires/i);
  }
}));

test('release metadata refuses missing and ambiguous header declarations', () => fixture(root => {
  writeFileSync(path.join(root, 'portalweave-sso.php'), plugin.replace(' * Version: 1.2.3', ' * Version: 1.2.3\n * Version: 1.2.4'));
  assert.throws(() => validateRelease({ root }), /version|ambiguous/i);
  writeFileSync(path.join(root, 'portalweave-sso.php'), plugin.replace("define('PORTALWEAVE_SSO_VERSION', '1.2.3');", ''));
  assert.throws(() => validateRelease({ root }), /version/i);
}));

test('release branding requires matching plugin and readme titles', () => fixture(root => {
  writeFileSync(path.join(root, 'readme.txt'), readme.replace('=== PortalWeave SSO ===', '=== Another plugin ==='));
  assert.throws(() => validateRelease({ root }), /title|Plugin Name/i);
  writeFileSync(path.join(root, 'readme.txt'), `${readme}\n=== PortalWeave SSO ===\n`);
  assert.throws(() => validateRelease({ root }), /title/i);
  writeFileSync(path.join(root, 'readme.txt'), readme);
  writeFileSync(path.join(root, 'portalweave-sso.php'), plugin.replace(' * Plugin Name: PortalWeave SSO\n', ''));
  assert.throws(() => validateRelease({ root }), /Plugin Name/i);
}));

test('slug metadata requires a valid text domain matching the main filename and POT', () => fixture(root => {
  for (const replacement of ['', ' * Text Domain: Another_Slug\n']) {
    writeFileSync(path.join(root, 'portalweave-sso.php'), plugin.replace(' * Text Domain: portalweave-sso\n', replacement));
    assert.throws(() => validateRelease({ root }), /Text Domain|slug/i);
  }
  writeFileSync(path.join(root, 'portalweave-sso.php'), plugin.replace('portalweave-sso', 'another-valid-slug'));
  writeFileSync(path.join(root, 'languages/another-valid-slug.pot'), 'msgid ""\nmsgstr ""\n"X-Domain: another-valid-slug\\n"\n');
  assert.throws(() => validateRelease({ root }), /filename|slug/i);
}));

test('the declared translation template is mandatory and must match the text domain', () => fixture(root => {
  const template = path.join(root, 'languages/portalweave-sso.pot');
  rmSync(template);
  assert.throws(() => validateRelease({ root }), /ENOENT|portalweave-sso\.pot/);
  writeFileSync(template, 'msgid ""\nmsgstr ""\n"X-Domain: another-plugin\\n"\n');
  assert.throws(() => validateRelease({ root }), /translation|text domain/i);
  writeFileSync(template, 'msgid ""\nmsgstr ""\n"X-Domain: portalweave-sso\\n"\n"X-Domain: another-plugin\\n"\n');
  assert.throws(() => validateRelease({ root }), /translation|text domain/i);
  writeFileSync(template, 'msgid ""\nmsgstr ""\n"X-Domain: portalweave-sso\\n"\n');
  writeFileSync(path.join(root, 'portalweave-sso.php'), plugin.replace(' * Domain Path: /languages', ' * Domain Path: /other'));
  assert.throws(() => validateRelease({ root }), /Domain Path/i);
}));

test('production staging excludes root development files and preserves exact production bytes', () => fixture(root => {
  const output = path.join(root, '.test-runtime/release-stage');
  const result = stageRelease({ root, output, tag: 'v1.2.3' });
  assert.equal(result.slug, 'portalweave-sso');
  assert.equal(result.files.length, 11);
  assert.deepEqual(result.files.map(file => file.path), ['LICENSE', 'assets/login.js', 'docs/external-services.md', 'docs/integrations.md', 'docs/privacy.md', 'docs/provider-setup.md', 'includes/class-fixture.php', 'languages/portalweave-sso.pot', 'portalweave-sso.php', 'readme.txt', 'uninstall.php']);
  assert.equal(readFileSync(path.join(output, 'portalweave-sso.php'), 'utf8'), plugin);
  assert.equal(existsSync(path.join(output, 'languages/portalweave-sso.pot')), true);
  for (const excluded of ['README.md', '.env', 'tests', 'compose.test.yml', 'scripts', '.git', 'docs/setup.md', 'docs/verification.md', 'docs/releasing.md', 'docs/directory-review.md']) assert.equal(existsSync(path.join(output, excluded)), false);
  assert.equal(verifyStage({ root, output, slug: 'portalweave-sso' }).files.length, 11);
}));

test('staging refuses destinations outside the repository or within production source', () => fixture(root => {
  for (const output of [root, path.join(root, '../outside'), path.join(root, 'includes/new'), path.join(root, 'assets/new'), path.join(root, 'docs/new'), path.join(root, 'DOCS/new'), path.join(root, 'languages/new'), path.join(root, '.git/new')]) {
    assert.throws(() => stageRelease({ root, output }), /destination|output|outside|source/i);
  }
}));

test('staging never destroys an existing nonempty destination', () => fixture(root => {
  const output = path.join(root, '.test-runtime/release-stage');
  mkdirSync(output, { recursive: true });
  writeFileSync(path.join(output, 'important.txt'), 'keep me');
  assert.throws(() => stageRelease({ root, output }), /empty|exists/i);
  assert.equal(readFileSync(path.join(output, 'important.txt'), 'utf8'), 'keep me');
}));

test('invalid slugs and secret-like files in production directories fail staging', () => fixture(root => {
  const output = path.join(root, '.test-runtime/release-stage');
  for (const slug of ['../other', 'Uppercase', 'wrong_slug', '-wrong', 'wrong-', '', 'one/two', 'another-valid-slug']) {
    assert.throws(() => stageRelease({ root, output, slug }), /slug/i);
    assert.throws(() => validateRelease({ root, slug }), /slug/i);
  }
  writeFileSync(path.join(root, 'includes/.env'), 'private secret');
  assert.throws(() => stageRelease({ root, output }), /private|secret|forbidden|development/i);
  assert.equal(existsSync(output), false, 'Preflight failure left a misleading package directory');
}));

test('source and destination symlinks cannot admit files outside the checkout', (context) => fixture(root => {
  const linked = path.join(root, 'includes/linked');
  const target = path.join(root, 'tests');
  try { symlinkSync(target, linked, process.platform === 'win32' ? 'junction' : 'dir'); }
  catch (error) { if (error.code === 'EPERM') return context.skip('Host cannot create symlinks'); throw error; }
  assert.throws(() => stageRelease({ root, output: path.join(root, '.test-runtime/release-stage') }), /symlink|symbolic/i);
  rmSync(linked);
  mkdirSync(path.join(root, 'scratch'));
  symlinkSync(path.join(root, 'scratch'), path.join(root, 'linked-output'), process.platform === 'win32' ? 'junction' : 'dir');
  assert.throws(() => stageRelease({ root, output: path.join(root, 'linked-output/stage') }), /symlink|symbolic/i);
  rmSync(path.join(root, 'linked-output'));
  rmSync(path.join(root, 'docs'), { recursive: true });
  symlinkSync(path.join(root, 'scratch'), path.join(root, 'docs'), process.platform === 'win32' ? 'junction' : 'dir');
  assert.throws(() => stageRelease({ root, output: path.join(root, '.test-runtime/release-stage') }), /symlink|symbolic/i);
}));

test('staged verification rejects added files, missing files and changed bytes', () => fixture(root => {
  const output = path.join(root, '.test-runtime/release-stage');
  stageRelease({ root, output });
  assert.throws(() => verifyStage({ root, output, slug: 'another-valid-slug' }), /slug/i);
  writeFileSync(path.join(output, 'surprise.php'), '<?php // changed');
  assert.throws(() => verifyStage({ root, output }), /unexpected|differ|manifest|files/i);
  rmSync(path.join(output, 'surprise.php'));
  writeFileSync(path.join(output, 'assets/login.js'), '// changed');
  assert.throws(() => verifyStage({ root, output }), /changed|differ|hash/i);
  rmSync(path.join(output, 'assets/login.js'));
  assert.throws(() => verifyStage({ root, output }), /missing|differ|files/i);
}));

test('CLI validates the real checkout and returns failure for an invalid tag', () => {
  const script = new URL('../scripts/release.mjs', import.meta.url);
  const good = spawnSync(process.execPath, [fileURLToPath(script), 'validate'], { encoding: 'utf8' });
  assert.equal(good.status, 0, good.stderr);
  assert.match(JSON.parse(good.stdout).version, /^\d+\.\d+\.\d+$/);
  assert.equal(JSON.parse(good.stdout).slug, 'portalweave-sso');
  const bad = spawnSync(process.execPath, [fileURLToPath(script), 'validate', '--tag', 'v9999.0.0'], { encoding: 'utf8' });
  assert.equal(bad.status, 1);
  assert.match(bad.stderr, /tag/i);
});
