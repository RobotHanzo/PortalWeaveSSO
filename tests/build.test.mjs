import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const posix = { skip: process.platform === 'win32' && 'Native Bash packaging is verified on Linux' };
const expected = ['LICENSE', 'assets/file with spaces.txt', 'assets/login.js', 'docs/external-services.md', 'docs/integrations.md', 'docs/privacy.md', 'docs/provider-setup.md', 'includes/class-fixture.php', 'languages/portalweave-sso.pot', 'portalweave-sso.php', 'readme.txt', 'uninstall.php'];
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');

function fixture(t) {
  const base = mkdtempSync(path.join(tmpdir(), 'portalweave-sso-build-'));
  const root = path.join(base, 'checkout with spaces');
  mkdirSync(root);
  t.after(() => {
    assert.equal(path.dirname(base), path.resolve(tmpdir()));
    assert.ok(path.basename(base).startsWith('portalweave-sso-build-'));
    rmSync(base, { recursive: true, force: true });
  });
  for (const directory of ['scripts', 'includes', 'assets', 'docs', 'languages']) mkdirSync(path.join(root, directory));
  for (const file of ['build.sh', 'release.sh', 'release.mjs']) copyFileSync(new URL(`../scripts/${file}`, import.meta.url), path.join(root, 'scripts', file));
  const source = {
    'portalweave-sso.php': `<?php
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
`,
    'readme.txt': '=== PortalWeave SSO ===\nRequires at least: 6.5\nRequires PHP: 8.1\nStable tag: 1.2.3\nLicense: GPLv2 or later\n\n== Changelog ==\n= 1.2.3 =\nFixture.\n',
    LICENSE: 'GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\n',
    'uninstall.php': '<?php // fixture',
    'assets/login.js': '// production\n',
    'assets/file with spaces.txt': 'binary-safe bytes: \u0000\u0001\u00ff\n',
    'includes/class-fixture.php': '<?php // production',
    'languages/portalweave-sso.pot': 'msgid ""\nmsgstr ""\n"X-Domain: portalweave-sso\\n"\n',
    'docs/provider-setup.md': '# Setup',
    'docs/integrations.md': '# Integrations',
    'docs/external-services.md': '# Services',
    'docs/privacy.md': '# Privacy',
    '.env': 'excluded fixture credential',
    'README.md': '# Excluded development file',
  };
  for (const [file, bytes] of Object.entries(source)) writeFileSync(path.join(root, file), bytes);
  const run = (args = [], env = {}) => {
    const result = spawnSync('bash', [path.join(root, 'scripts/build.sh'), ...args], {
      cwd: base, encoding: 'utf8', timeout: 20_000, env: { ...process.env, ...env },
    });
    assert.ifError(result.error);
    return result;
  };
  const zip = path.join(root, 'dist/portalweave-sso-1.2.3.zip');
  return { base, root, run, zip };
}

function assertClean(root) {
  const scratch = path.join(root, '.test-runtime');
  assert.deepEqual(existsSync(scratch) ? readdirSync(scratch) : [], []);
}

test('Bash build from another working directory preserves exact archive bytes, manifest and checksum', posix, t => {
  const { root, run, zip } = fixture(t);
  const result = run(['--slug', 'portalweave-sso', '--version', '1.2.3'], { ZIPOPT: '-r', UNZIP: '-j' });
  assert.equal(result.status, 0, result.stderr);
  const listing = spawnSync('unzip', ['-Z1', zip], { encoding: 'utf8' });
  assert.equal(listing.status, 0, listing.stderr);
  assert.deepEqual(listing.stdout.trim().split('\n').sort(), expected.map(file => `portalweave-sso/${file}`).sort());
  for (const file of expected) {
    const entry = spawnSync('unzip', ['-p', zip, `portalweave-sso/${file}`]);
    assert.equal(entry.status, 0, String(entry.stderr));
    assert.equal(sha256(entry.stdout), sha256(readFileSync(path.join(root, file))), file);
  }
  const hash = sha256(readFileSync(zip));
  assert.equal(readFileSync(`${zip}.sha256`, 'utf8'), `${hash}  portalweave-sso-1.2.3.zip\n`);
  assert.ok(result.stdout.includes(zip));
  assert.ok(result.stdout.includes(`SHA256 ${hash}`));
  assertClean(root);
});

test('Bash build rejects unknown, duplicate, missing and mismatched arguments without artifacts', posix, t => {
  const { root, run, zip } = fixture(t);
  for (const args of [['--unknown'], ['--slug'], ['--version', ''], ['--slug', '--version'], ['--slug', 'portalweave-sso', '--slug', 'portalweave-sso'], ['--version', '1.2.3', '--version', '1.2.3'], ['--slug', 'other'], ['--version', '9.9.9']]) {
    const result = run(args);
    assert.notEqual(result.status, 0, JSON.stringify(args));
    assert.equal(existsSync(zip), false);
    assertClean(root);
  }
});

test('Bash build fails invalid metadata before creating packaging directories', posix, t => {
  const { root, run, zip } = fixture(t);
  const readme = path.join(root, 'readme.txt');
  writeFileSync(readme, readFileSync(readme, 'utf8').replace('Stable tag: 1.2.3', 'Stable tag: 9.9.9'));
  const result = run();
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /Stable tag|version/i);
  assert.equal(existsSync(zip), false);
  assert.equal(existsSync(path.join(root, '.test-runtime')), false);
});

test('Bash build refuses linked package directories and output files without touching their targets', posix, t => {
  for (const kind of ['dist', '.test-runtime', 'archive', 'checksum']) {
    const { base, root, run, zip } = fixture(t);
    const target = path.join(base, `outside-${kind.replace('.', '')}`);
    if (kind === 'dist' || kind === '.test-runtime') {
      mkdirSync(target);
      writeFileSync(path.join(target, 'keep.txt'), 'keep');
      symlinkSync(target, path.join(root, kind), 'dir');
    } else {
      mkdirSync(path.join(root, 'dist'));
      writeFileSync(target, 'keep');
      symlinkSync(target, kind === 'archive' ? zip : `${zip}.sha256`);
    }
    const result = run();
    assert.notEqual(result.status, 0, kind);
    assert.match(result.stderr, /symbolic link|Unsafe package/i);
    assert.equal(readFileSync(kind === 'dist' || kind === '.test-runtime' ? path.join(target, 'keep.txt') : target, 'utf8'), 'keep');
    if (kind !== '.test-runtime') assertClean(root);
  }
});

test('Bash build rejects unsafe or case-colliding production names before archiving', posix, t => {
  for (const names of [['bad:name.js'], ['line\nbreak.js'], ['duplicate.js', 'DUPLICATE.js']]) {
    const { root, run, zip } = fixture(t);
    for (const name of names) writeFileSync(path.join(root, 'assets', name), '// fixture');
    const result = run();
    assert.notEqual(result.status, 0, JSON.stringify(names));
    assert.match(result.stderr, /Unsafe ZIP entry|Duplicate ZIP entry/);
    assert.equal(existsSync(zip), false);
    assertClean(root);
  }
});

test('Bash build preserves archiver failure and cleans only its own scratch directory', posix, t => {
  const { root, run, zip } = fixture(t);
  const bin = path.join(root, 'fake-bin');
  const scratch = path.join(root, '.test-runtime');
  mkdirSync(bin);
  mkdirSync(scratch);
  mkdirSync(path.dirname(zip));
  writeFileSync(zip, 'previous archive');
  writeFileSync(`${zip}.sha256`, 'previous checksum');
  writeFileSync(path.join(scratch, 'keep.txt'), 'unrelated scratch data');
  writeFileSync(path.join(bin, 'zip'), '#!/usr/bin/env bash\nprintf partial > "$3"\nexit 37\n', { mode: 0o755 });
  const result = run([], { PATH: bin + path.delimiter + process.env.PATH });
  assert.equal(result.status, 37, result.stderr);
  assert.equal(readFileSync(zip, 'utf8'), 'previous archive');
  assert.equal(readFileSync(`${zip}.sha256`, 'utf8'), 'previous checksum');
  assert.deepEqual(readdirSync(scratch), ['keep.txt']);
  assert.equal(readFileSync(path.join(scratch, 'keep.txt'), 'utf8'), 'unrelated scratch data');
});

test('Bash build rejects unexpected ZIP members before extraction or publication', posix, t => {
  const { root, run, zip } = fixture(t);
  const realZip = spawnSync('bash', ['-c', 'command -v zip'], { encoding: 'utf8' });
  assert.equal(realZip.status, 0, realZip.stderr);
  const bin = path.join(root, 'fake-bin');
  mkdirSync(bin);
  writeFileSync(path.join(bin, 'zip'), `#!/usr/bin/env bash
set -eu
"$BUILD_TEST_REAL_ZIP" "$@"
printf unexpected > unexpected.txt
"$BUILD_TEST_REAL_ZIP" -q "$3" unexpected.txt
`, { mode: 0o755 });
  const result = run([], { PATH: bin + path.delimiter + process.env.PATH, BUILD_TEST_REAL_ZIP: realZip.stdout.trim() });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /unexpected, noncanonical, duplicate or missing entries/);
  assert.equal(existsSync(zip), false);
  assertClean(root);
});

test('Bash build fails a ZIP with matching member names but a corrupt CRC', posix, t => {
  const { root, run, zip } = fixture(t);
  const realZip = spawnSync('bash', ['-c', 'command -v zip'], { encoding: 'utf8' });
  assert.equal(realZip.status, 0, realZip.stderr);
  const bin = path.join(root, 'fake-bin');
  mkdirSync(bin);
  writeFileSync(path.join(bin, 'zip'), `#!/usr/bin/env bash
set -eu
"$BUILD_TEST_REAL_ZIP" "$@"
node - "$3" <<'NODE'
const fs = require('node:fs');
const bytes = fs.readFileSync(process.argv[2]);
const directory = bytes.indexOf(Buffer.from([0x50, 0x4b, 0x01, 0x02]));
if (directory < 0) throw new Error('Test archive has no central directory');
bytes[14] ^= 0xff;
bytes[directory + 16] ^= 0xff;
fs.writeFileSync(process.argv[2], bytes);
NODE
`, { mode: 0o755 });
  const result = run([], { PATH: bin + path.delimiter + process.env.PATH, BUILD_TEST_REAL_ZIP: realZip.stdout.trim() });
  assert.notEqual(result.status, 0);
  assert.match(result.stdout + result.stderr, /bad CRC|bad crc/);
  assert.equal(existsSync(zip), false);
  assertClean(root);
});
