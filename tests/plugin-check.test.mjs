import test from 'node:test';
import assert from 'node:assert/strict';
import { linkSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { parsePluginCheck, pluginCheckSummary, writePluginCheckReports } from '../scripts/plugin-check.mjs';
import { summarizeReports } from '../scripts/report-results.mjs';
import { summarizeTooling } from '../scripts/report-tooling.mjs';

const script = fileURLToPath(new URL('../scripts/plugin-check.mjs', import.meta.url));
const clean = 'Success: Checks complete. No errors found.\n';
const finding = (override = {}) => ({ line: 12, column: 7, type: 'ERROR', code: 'WordPress.Security.Example', message: 'A complete finding.', docs: '', ...override });
const block = (file, values) => `FILE: ${file}\n${JSON.stringify(values)}\n\n`;

function fixture(work) {
    const root = mkdtempSync(path.join(os.tmpdir(), 'wpsso-plugin-check-'));
    try { return work(root); }
    finally { rmSync(root, { recursive: true, force: true }); }
}

function invoke(root, raw, args = []) {
    const input = path.join(root, 'native.txt');
    const output = path.join(root, 'reports');
    const summary = path.join(root, 'summary.md');
    if (raw !== undefined) writeFileSync(input, raw);
    const result = spawnSync(process.execPath, [script, input, '--output', output, ...args], {
        encoding: 'utf8', env: { ...process.env, GITHUB_STEP_SUMMARY: summary },
    });
    assert.equal(result.error, undefined);
    return { ...result, output, summary };
}

test('Plugin Check parses all native blocks, multiline JSON, CRLF and Windows path colons', () => {
    const first = finding();
    const second = finding({ type: 'WARNING', line: 0, column: 0, code: 'trademarked_term' });
    const raw = block('includes/example.php', [first]) + 'FILE: C:\\plugin\\readme.txt\r\n' + JSON.stringify([second], null, 2).replace(/\n/g, '\r\n');
    const result = parsePluginCheck('\uFEFF' + raw);
    assert.deepEqual(result.summary, { total: 2, errors: 1, warnings: 1, files: 2 });
    assert.deepEqual(result.findings, [{ file: 'includes/example.php', ...first }, { file: 'C:\\plugin\\readme.txt', ...second }]);
    assert.equal(result.success, false);
    assert.deepEqual(result.tests.map(value => value.pass), [false, true]);
});

test('Plugin Check only accepts the official explicit clean-result message', () => {
    const result = parsePluginCheck(clean);
    assert.equal(result.success, true);
    assert.deepEqual(result.summary, { total: 0, errors: 0, warnings: 0, files: 0 });
    assert.equal(result.tests.length, 1);
    for (const raw of ['', '\n', '[]', 'Success: Everything is fine.', 'Success: Checks complete. No problems found.', clean + block('a.php', [finding()])]) {
        assert.throws(() => parsePluginCheck(raw), /empty|unexpected|missing/i);
    }
});

test('Plugin Check rejects truncated blocks, unknown text and incomplete or invalid schemas', () => {
    const bad = [
        'FILE: a.php', 'FILE: a.php\n[', block('a.php', []), block('a.php', [finding()]) + 'unexpected output',
        block('a.php', [finding()]) + 'FILE: missing.php\n', '[{}]',
        block('a.php', [{ ...finding(), type: undefined }]), block('a.php', [{ ...finding(), extra: 'hidden' }]),
        ...[{ line: -1 }, { line: '12' }, { column: 0.5 }, { type: 'NOTICE' }, { message: '' }, { docs: [] }, { code: '' }].map(value => block('a.php', [finding(value)])),
    ];
    for (const raw of bad) assert.throws(() => parsePluginCheck(raw));
    assert.throws(() => parsePluginCheck('x'.repeat(8 * 1024 * 1024 + 1)), /8 MiB/);
    assert.throws(() => parsePluginCheck(block('many.php', Array.from({ length: 20001 }, () => finding()))), /20000/);
});

test('Plugin Check warning-only results pass by default and fail only with explicit strict policy', () => {
    const raw = block('includes/example.php', [finding({ type: 'WARNING' })]);
    const result = parsePluginCheck(raw);
    assert.equal(result.success, true);
    assert.equal(result.summary.warnings, 1);
    assert.match(pluginCheckSummary(result), /warnings are reported/);
    assert.equal(parsePluginCheck(raw, { strict: true }).success, false);
    assert.throws(() => parsePluginCheck(raw, { strict: 'true' }), /boolean/);
});

test('Plugin Check JSON and JUnit retain every finding and integrate with existing report summaries', () => fixture(root => {
    const message = '<script>alert("x")</script> & \"quotes\"\n::error title=forged::payload';
    const values = Array.from({ length: 60 }, (_, index) => finding({ type: index ? 'WARNING' : 'ERROR', message, code: 'check_' + index }));
    const result = parsePluginCheck(block('includes/example.php', values));
    writePluginCheckReports(result, root);
    const stored = JSON.parse(readFileSync(path.join(root, 'plugin-check.json'), 'utf8'));
    assert.equal(stored.findings.length, 60);
    assert.equal(stored.findings[0].message, message);
    const junit = readFileSync(path.join(root, 'plugin-check.xml'), 'utf8');
    assert.doesNotMatch(junit, /<script>/);
    assert.equal((junit.match(/<testcase /g) || []).length, 60);
    assert.equal(summarizeTooling(junit).failed, 1);
    const aggregate = summarizeReports(root, ['plugin-check']);
    assert.equal(aggregate.issues.length, 0);
    assert.equal(aggregate.failed, 1);
    assert.match(pluginCheckSummary(result), /10 additional findings/);
}));

test('Plugin Check CLI fails on native errors and escapes bounded GitHub annotations and summaries', () => fixture(root => {
    const message = '<img src=x onerror=alert(1)>\n::error title=forged::payload\r100%';
    const raw = block('C:\\plugin\\with,comma.php', Array.from({ length: 60 }, () => finding({ message })));
    const run = invoke(root, raw);
    assert.equal(run.status, 1);
    assert.equal((run.stdout.match(/^::error /gm) || []).length, 50);
    assert.match(run.stdout, /file=C%3A\\plugin\\with%2Ccomma\.php/);
    assert.match(run.stdout, /%0A::error title=forged::payload%0D100%25/);
    assert.doesNotMatch(run.stdout, /^::error title=forged/gm);
    assert.doesNotMatch(readFileSync(run.summary, 'utf8'), /<img/);
    assert.equal(JSON.parse(readFileSync(path.join(run.output, 'plugin-check.json'), 'utf8')).summary.errors, 60);
}));

test('Plugin Check CLI reports warning-only and clean output with the expected exit status', () => fixture(root => {
    assert.equal(invoke(root, clean).status, 0);
    const warnings = block('readme.txt', [finding({ type: 'WARNING' })]);
    assert.equal(invoke(root, warnings).status, 0);
    const strict = invoke(root, warnings, ['--strict']);
    assert.equal(strict.status, 1);
    assert.equal(summarizeTooling(readFileSync(path.join(strict.output, 'plugin-check.xml'), 'utf8')).failed, 1);
}));

test('Plugin Check CLI writes failing JSON, JUnit and summary for missing, malformed and invalid UTF-8 input', () => fixture(root => {
    for (const raw of [undefined, '', block('a.php', [finding()]) + 'FILE: incomplete.php\n', Buffer.from([0xff, 0xfe])]) {
        const run = invoke(root, raw);
        assert.equal(run.status, 1);
        const report = JSON.parse(readFileSync(path.join(run.output, 'plugin-check.json'), 'utf8'));
        assert.equal(report.valid, false);
        assert.equal(report.summary.errors, null);
        assert.equal(summarizeTooling(readFileSync(path.join(run.output, 'plugin-check.xml'), 'utf8')).failed, 1);
        assert.match(readFileSync(run.summary, 'utf8'), /Missing or invalid/);
    }
}));

test('Plugin Check never overwrites its input when report and input paths collide', () => fixture(root => {
    mkdirSync(path.join(root, 'reports'));
    const input = path.join(root, 'reports/plugin-check.json');
    writeFileSync(input, clean);
    const run = spawnSync(process.execPath, [script, input, '--output', path.dirname(input)], { encoding: 'utf8', env: { ...process.env, GITHUB_STEP_SUMMARY: '' } });
    assert.equal(run.status, 1);
    assert.equal(readFileSync(input, 'utf8'), clean);
    assert.match(run.stdout, /must not overwrite/);
}));

test('Plugin Check reporting errors cannot print or exit with a passing result', () => fixture(root => {
    writeFileSync(path.join(root, 'reports'), 'A file blocks the output directory.');
    const run = invoke(root, clean);
    assert.equal(run.status, 1);
    assert.match(run.stdout, /Missing or invalid/);
    assert.doesNotMatch(run.stdout, /\*\*Passed/);
    assert.match(readFileSync(run.summary, 'utf8'), /Missing or invalid/);
}));

test('Plugin Check rejects unknown, duplicate and incomplete options with failed reports', () => fixture(root => {
    for (const args of [['--unknown'], ['--strict', '--strict'], ['--output'], ['--output', 'another-directory']]) {
        const run = invoke(root, clean, args);
        assert.equal(run.status, 1);
        assert.equal(JSON.parse(readFileSync(path.join(run.output, 'plugin-check.json'), 'utf8')).valid, false);
    }
}));

test('Plugin Check refuses hard-link aliases of its raw input as report destinations', () => fixture(root => {
    const input = path.join(root, 'native.txt');
    const output = path.join(root, 'reports');
    mkdirSync(output);
    writeFileSync(input, clean);
    linkSync(input, path.join(output, 'plugin-check.json'));
    const run = spawnSync(process.execPath, [script, input, '--output', output], { encoding: 'utf8', env: { ...process.env, GITHUB_STEP_SUMMARY: '' } });
    assert.equal(run.status, 1);
    assert.equal(readFileSync(input, 'utf8'), clean);
    assert.match(run.stdout, /must not overwrite/);
}));
