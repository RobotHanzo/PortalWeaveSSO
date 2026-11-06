import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scripts = fileURLToPath(new URL('../scripts/', import.meta.url));
const engines = readdirSync(scripts).filter(name => /\.(?:mjs|php)$/.test(name)).sort();
const posix = { skip: process.platform === 'win32' && 'Bash helper execution runs on Linux' };

function fixture(work) {
  const root = mkdtempSync(path.join(tmpdir(), 'portalweave-sso-bash-helpers-'));
  try { return work(root); }
  finally {
    assert.equal(path.dirname(root), path.resolve(tmpdir()));
    assert.ok(path.basename(root).startsWith('portalweave-sso-bash-helpers-'));
    rmSync(root, { recursive: true, force: true });
  }
}

test('Every Node and PHP helper has a same-name Bash entrypoint', () => {
  assert.ok(engines.length, 'The helper inventory is empty');
  for (const engine of engines) {
    const wrapper = engine.replace(/\.(?:mjs|php)$/, '.sh');
    assert.ok(existsSync(path.join(scripts, wrapper)), `${engine} has no Bash entrypoint`);
  }
});

// Exercise each real wrapper from a checkout path containing spaces. Probe only
// the interpreter boundary so these tests need no PHP, network, Docker or reports.
for (const engine of engines) {
  const wrapper = engine.replace(/\.(?:mjs|php)$/, '.sh');
  test(`${wrapper} preserves arguments, caller directory, output and backend exit status`, posix, () => fixture(root => {
    const source = path.join(root, 'source checkout $literal', 'scripts');
    const caller = path.join(root, 'caller directory');
    const bin = path.join(root, 'bin');
    for (const directory of [source, caller, bin]) mkdirSync(directory, { recursive: true });
    copyFileSync(path.join(scripts, wrapper), path.join(source, wrapper));
    copyFileSync(path.join(scripts, engine), path.join(source, engine));
    writeFileSync(path.join(caller, 'glob-would-expand.txt'), 'fixture');
    const probe = path.join(root, 'probe.mjs');
    writeFileSync(probe, `process.stdout.write(JSON.stringify({ cwd: process.cwd(), engine: process.argv[2], args: process.argv.slice(3) }));
process.stderr.write('backend stderr\\n');
process.exitCode = Number(process.env.PORTALWEAVE_SSO_HELPER_TEST_EXIT);
`);
    for (const interpreter of ['node', 'php']) {
      writeFileSync(path.join(bin, interpreter), `#!/usr/bin/env bash
exec "$PORTALWEAVE_SSO_HELPER_TEST_NODE" "$PORTALWEAVE_SSO_HELPER_TEST_PROBE" "$(basename -- "$0")" "$@"
`, { mode: 0o755 });
    }
    const args = ['two words', '', '"double quotes"', "'single quotes'", '$HOME', '$(touch substitution-ran)',
      '`touch backticks-ran`', 'semicolon;literal', '*', '[abc]?', '--output', 'relative output/file', 'line one\nline two'];
    for (const status of [0, 47]) {
      const run = spawnSync('bash', [path.join(source, wrapper), ...args], {
        cwd: caller, encoding: 'utf8', timeout: 10_000,
        env: { ...process.env, PATH: bin + path.delimiter + process.env.PATH,
          PORTALWEAVE_SSO_HELPER_TEST_NODE: process.execPath, PORTALWEAVE_SSO_HELPER_TEST_PROBE: probe,
          PORTALWEAVE_SSO_HELPER_TEST_EXIT: String(status) },
      });
      assert.ifError(run.error);
      assert.equal(run.status, status, run.stderr);
      assert.equal(run.stderr, 'backend stderr\n');
      assert.deepEqual(JSON.parse(run.stdout), {
        cwd: caller, engine: engine.endsWith('.php') ? 'php' : 'node', args: [path.join(source, engine), ...args],
      });
      for (const name of ['substitution-ran', 'backticks-ran']) assert.equal(existsSync(path.join(caller, name)), false);
    }
  }));
}

test('release.sh validates the checkout from another directory and preserves metadata failure exits', posix, () => fixture(root => {
  const script = path.join(scripts, 'release.sh');
  const run = spawnSync('bash', [script, 'validate', '--github-output', 'metadata output.txt'], {
    cwd: root, encoding: 'utf8', timeout: 10_000,
  });
  assert.ifError(run.error);
  assert.equal(run.status, 0, run.stderr);
  const metadata = JSON.parse(run.stdout);
  assert.match(metadata.version, /^\d+\.\d+\.\d+$/);
  assert.equal(metadata.slug, 'portalweave-sso');
  assert.match(readFileSync(path.join(root, 'metadata output.txt'), 'utf8'), new RegExp(`^version=${metadata.version.replaceAll('.', '\\.')}\n`));
  const bad = spawnSync('bash', [script, 'validate', '--tag', 'v9999.0.0'], {
    cwd: root, encoding: 'utf8', timeout: 10_000,
  });
  assert.ifError(bad.error);
  assert.equal(bad.status, 1);
  assert.match(bad.stderr, /Git tag must be/);
}));

test('plugin-check.sh retains strict warning failures and complete reports using caller-relative paths', posix, () => fixture(root => {
  const raw = 'FILE: includes/example.php\n' + JSON.stringify([
    { line: 12, column: 3, type: 'WARNING', code: 'example.advisory', message: 'Review the reported operation.', docs: '' },
  ]) + '\n';
  const input = 'native output [strict].txt';
  writeFileSync(path.join(root, input), raw);
  const run = spawnSync('bash', [path.join(scripts, 'plugin-check.sh'), input, '--output', 'reports with spaces', '--strict'], {
    cwd: root, encoding: 'utf8', timeout: 10_000, env: { ...process.env, GITHUB_STEP_SUMMARY: '' },
  });
  assert.ifError(run.error);
  assert.equal(run.status, 1, run.stderr);
  const result = JSON.parse(readFileSync(path.join(root, 'reports with spaces', 'plugin-check.json'), 'utf8'));
  assert.equal(result.valid, true);
  assert.equal(result.strict, true);
  assert.equal(result.success, false);
  assert.equal(result.summary.warnings, 1);
  assert.equal(result.findings[0].code, 'example.advisory');
  assert.equal(result.tests[0].pass, false);
  assert.match(readFileSync(path.join(root, 'reports with spaces', 'plugin-check.xml'), 'utf8'), /failures="1"/);
  assert.equal(readFileSync(path.join(root, input), 'utf8'), raw, 'Reporting overwrote native findings');
}));
