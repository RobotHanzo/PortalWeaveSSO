import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync, mkdirSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';
import { writeReports } from './report.mjs';
import { commandEscape, summarizeReports } from '../scripts/report-results.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
function fixture(t) {
    const directory = mkdtempSync(join(tmpdir(), 'wpsso-report-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    return directory;
}
function runReport(directory, expected = 'integration') {
    return spawnSync(process.execPath, [join(root, 'scripts/report-results.mjs'), directory, '--expect', expected], {
        encoding: 'utf8', env: { ...process.env, GITHUB_STEP_SUMMARY: join(directory, 'summary.md') },
    });
}

test('JUnit escapes provider payloads and records real failed cases with durations', t => {
    const directory = fixture(t);
    writeReports('integration', [
        { name: 'Valid "claim" & <subject>', pass: true, duration: 0.125 },
        { name: 'Signature rejection', pass: false, duration: 0.25, error: 'Bad <signature> & "key"\u0001\nnext line' },
    ], directory);
    const xml = readFileSync(join(directory, 'integration.xml'), 'utf8');
    assert.match(xml, /tests="2" failures="1" errors="0" time="0\.375000"/);
    assert.match(xml, /name="Valid &quot;claim&quot; &amp; &lt;subject&gt;" time="0\.125000"/);
    assert.match(xml, /<failure type="AssertionFailure" message="Bad &lt;signature&gt; &amp; &quot;key&quot;\nnext line">/);
    assert.equal(xml.includes('\u0001'), false);
    const report = JSON.parse(readFileSync(join(directory, 'integration.json'), 'utf8'));
    assert.deepEqual(report.summary, { total: 2, passed: 1, failed: 1, duration: 0.375 });
    assert.equal(report.tests[1].pass, false);
    const result = runReport(directory);
    assert.equal(result.status, 1, 'A failing test report must fail the reporting step');
    assert.match(readFileSync(join(directory, 'summary.md'), 'utf8'), /1\/2 tests passed/);
});

test('Passing expected reports produce a successful summary without write-token privileges', t => {
    const directory = fixture(t);
    writeReports('integration', [{ name: 'WordPress mapping', pass: true, duration: 1 }], directory);
    writeReports('https', [{ name: 'PKCE login', pass: true, duration: 0.5 }], directory);
    const result = runReport(directory, 'integration,https');
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /2\/2 tests passed/);
    assert.doesNotMatch(result.stdout, /::error/);
});

test('Absent expected suites and zero tests cannot look like successful CI', t => {
    const directory = fixture(t);
    assert.equal(runReport(directory).status, 1);
    writeReports('integration', [{ name: 'A real test', pass: true }], directory);
    const missing = runReport(directory, 'integration,https');
    assert.equal(missing.status, 1);
    assert.match(missing.stdout, /Required test suite is missing: https/);
    writeReports('integration', [], directory);
    const empty = runReport(directory);
    assert.equal(empty.status, 1);
    assert.match(empty.stdout, /Suite has no test cases/);
});

test('Malformed reports, invalid cases and missing JUnit files fail closed', t => {
    const directory = fixture(t);
    writeFileSync(join(directory, 'integration.json'), '{broken');
    assert.equal(runReport(directory).status, 1);
    writeFileSync(join(directory, 'integration.json'), JSON.stringify({ suite: 'integration', tests: [{ name: 'Fake pass', pass: 'true' }] }));
    assert.equal(runReport(directory).status, 1);
    writeReports('integration', [{ name: 'Real case', pass: true }], directory);
    unlinkSync(join(directory, 'integration.xml'));
    const missingXml = runReport(directory);
    assert.equal(missingXml.status, 1);
    assert.match(missingXml.stdout, /JUnit XML report is missing/);
});

test('Duplicate suite reports are rejected rather than hiding a failed matrix result', t => {
    const directory = fixture(t);
    mkdirSync(join(directory, 'another-job'));
    writeReports('integration', [{ name: 'Failed job', pass: false, error: 'Fail' }], directory);
    writeReports('integration', [{ name: 'Passing job', pass: true }], join(directory, 'another-job'));
    const result = summarizeReports(directory, ['integration']);
    assert.equal(result.success, false);
    assert.ok(result.issues.some(issue => issue.includes('Duplicate suite')));
});

test('Annotations encode untrusted newlines, percent signs and property separators', t => {
    assert.equal(commandEscape('one%\r\ntwo'), 'one%25%0D%0Atwo');
    assert.equal(commandEscape('one,two:three', true), 'one%2Ctwo%3Athree');
    const directory = fixture(t);
    writeReports('integration', [{ name: 'unsafe,name:title', pass: false, error: 'first line\n::notice title=injected::payload%' }], directory);
    const result = runReport(directory);
    assert.equal(result.status, 1);
    assert.match(result.stdout, /::error title=integration%3A unsafe%2Cname%3Atitle::first line%0A::notice title=injected::payload%25/);
    assert.equal(result.stdout.split('\n').some(line => line.startsWith('::notice')), false);
});

test('An unfinished Node test runner writes an explicit failing report and exits nonzero', t => {
    const directory = fixture(t);
    const guardModule = pathToFileURL(join(root, 'tests/report.mjs')).href;
    const script = join(directory, 'unfinished.mjs');
    writeFileSync(script, `import { reportGuard } from ${JSON.stringify(guardModule)};\nreportGuard('https', []);\nprocess.exit(0);\n`);
    const result = spawnSync(process.execPath, [script], { encoding: 'utf8', env: { ...process.env, WPSSO_REPORT_DIR: directory } });
    assert.equal(result.status, 1);
    const report = JSON.parse(readFileSync(join(directory, 'https.json'), 'utf8'));
    assert.equal(report.tests[0].pass, false);
    assert.equal(report.tests[0].name, 'Test runner did not complete');
});
