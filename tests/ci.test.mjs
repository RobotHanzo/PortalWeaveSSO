import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const posix = { skip: process.platform === 'win32' && 'Bash CI harness regressions run on Linux' };

// Run the real harness in a disposable directory. Only the external Docker and
// delay boundaries are faked; no daemon, registry, ports or databases are used.
function runHarness(overrides = {}, task = 'multisite') {
  const root = mkdtempSync(path.join(tmpdir(), 'portalweave-sso-ci-retry-'));
  const log = path.join(root, 'commands.log');
  try {
    mkdirSync(path.join(root, 'scripts'));
    mkdirSync(path.join(root, 'bin'));
    copyFileSync(new URL('../scripts/ci.sh', import.meta.url), path.join(root, 'scripts/ci.sh'));
    writeFileSync(path.join(root, 'bin/docker'), `#!/usr/bin/env bash
set -eu
printf 'docker %s\\n' "$*" >> "$CI_TEST_LOG"
case "\${4:-}" in
  pull)
    count=0
    if [[ -f "$CI_TEST_COUNT" ]]; then read -r count < "$CI_TEST_COUNT"; fi
    count=$((count + 1))
    printf '%s\\n' "$count" > "$CI_TEST_COUNT"
    if ((count <= CI_TEST_PULL_FAILURES)); then
      printf 'connection reset by peer\\n' >&2
      exit 23
    fi
    ;;
  up) exit "$CI_TEST_UP_EXIT" ;;
  run)
    if [[ "$*" == *'tests/multisite.php'* ]]; then exit "$CI_TEST_SUITE_EXIT"; fi
    ;;
esac
`, { mode: 0o755 });
    writeFileSync(path.join(root, 'bin/sleep'), '#!/usr/bin/env bash\nprintf "sleep %s\\n" "$*" >> "$CI_TEST_LOG"\n', { mode: 0o755 });
    const result = spawnSync('bash', ['scripts/ci.sh', task], {
      cwd: root,
      encoding: 'utf8',
      timeout: 10_000,
      env: {
        ...process.env,
        PATH: path.join(root, 'bin') + path.delimiter + process.env.PATH,
        CI_TEST_LOG: log,
        CI_TEST_COUNT: path.join(root, 'pull-count'),
        CI_TEST_PULL_FAILURES: '0',
        CI_TEST_UP_EXIT: '0',
        CI_TEST_SUITE_EXIT: '0',
        ...overrides,
      },
    });
    assert.ifError(result.error);
    return { ...result, commands: existsSync(log) ? readFileSync(log, 'utf8').trim().split('\n') : [] };
  } finally {
    assert.equal(path.dirname(root), path.resolve(tmpdir()));
    assert.ok(path.basename(root).startsWith('portalweave-sso-ci-retry-'));
    rmSync(root, { recursive: true, force: true });
  }
}

const pulls = run => run.commands.filter(command => command.startsWith('docker compose -f compose.test.yml pull '));
const starts = run => run.commands.filter(command => command.startsWith('docker compose -f compose.test.yml up '));
const suites = run => run.commands.filter(command => command.includes('tests/multisite.php'));

test('CI downloads every service image before starting the disposable fixture', posix, () => {
  const run = runHarness();
  assert.equal(run.status, 0, run.stderr);
  assert.equal(pulls(run).length, 1);
  for (const service of ['db', 'wordpress', 'cli']) assert.ok(pulls(run)[0].split(' ').includes(service));
  assert.equal(run.commands[0], pulls(run)[0]);
  assert.equal(starts(run).length, 1);
  assert.equal(suites(run).length, 1);
  for (const command of run.commands.filter(command => / (up|run) /.test(command))) {
    assert.ok(command.includes('--pull never'), `Registry access can escape preflight: ${command}`);
  }
  assert.ok(!run.commands.some(command => command.startsWith('sleep ')));
});

test('CI recovers from transient registry failures before running setup or tests', posix, () => {
  const run = runHarness({ CI_TEST_PULL_FAILURES: '2' });
  assert.equal(run.status, 0, run.stderr);
  assert.equal(pulls(run).length, 3);
  assert.deepEqual(run.commands.filter(command => command.startsWith('sleep ')), ['sleep 5', 'sleep 10']);
  assert.ok(run.commands.indexOf(starts(run)[0]) > run.commands.lastIndexOf(pulls(run)[2]));
  assert.equal(starts(run).length, 1);
  assert.equal(suites(run).length, 1);
  assert.match(run.stderr, /connection reset by peer/);
});

test('CI fails closed after persistent registry failures without starting a fixture', posix, () => {
  const run = runHarness({ CI_TEST_PULL_FAILURES: '99' });
  assert.equal(run.status, 23, run.stderr);
  assert.equal(pulls(run).length, 3);
  assert.deepEqual(run.commands.filter(command => command.startsWith('sleep ')), ['sleep 5', 'sleep 10']);
  assert.equal(starts(run).length, 0);
  assert.ok(!run.commands.some(command => command.includes(' run ')));
});

test('CI does not retry a container startup failure', posix, () => {
  const run = runHarness({ CI_TEST_UP_EXIT: '42' });
  assert.equal(run.status, 42, run.stderr);
  assert.equal(pulls(run).length, 1);
  assert.equal(starts(run).length, 1);
  assert.equal(suites(run).length, 0);
  assert.ok(!run.commands.some(command => command.startsWith('sleep ')));
});

test('CI preserves test failures without retrying the suite', posix, () => {
  const run = runHarness({ CI_TEST_SUITE_EXIT: '47' });
  assert.equal(run.status, 47, run.stderr);
  assert.equal(pulls(run).length, 1);
  assert.equal(starts(run).length, 1);
  assert.equal(suites(run).length, 1);
  assert.ok(!run.commands.some(command => command.startsWith('sleep ')));
});

test('CI rejects invalid tasks before accessing Docker', posix, () => {
  const run = runHarness({}, 'unknown');
  assert.equal(run.status, 2, run.stderr);
  assert.deepEqual(run.commands, []);
  assert.match(run.stderr, /Usage:/);
});
