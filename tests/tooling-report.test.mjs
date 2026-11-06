import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { summarizeTooling } from '../scripts/report-tooling.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
function fixture(t) {
    const directory = mkdtempSync(join(tmpdir(), 'portalweave-sso-tooling-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    return directory;
}
function cli(directory, xml) {
    const file = join(directory, 'tooling.xml');
    if (xml !== undefined) writeFileSync(file, xml);
    return spawnSync(process.execPath, [join(root, 'scripts/report-tooling.mjs'), file], {
        encoding: 'utf8', env: { ...process.env, GITHUB_STEP_SUMMARY: join(directory, 'summary.md') },
    });
}
test('Tooling JUnit summary counts flat passing, failed and skipped testcases', () => {
    const report = summarizeTooling('<?xml version="1.0"?><testsuites><testcase name="pass"/><testcase name="failure"><failure message="bad &amp; invalid">details</failure></testcase><testcase name="skip"><skipped/></testcase><!-- tests 3 --></testsuites>');
    assert.deepEqual([report.total, report.passed, report.failed, report.skipped], [3, 1, 1, 1]);
    assert.equal(report.success, false);
    assert.equal(report.failures[0].error, 'bad & invalid\ndetails');
});
test('Node built-in JUnit output produces a native job summary and preserves failure exits', t => {
    const directory = fixture(t);
    const file = join(directory, 'fixture.mjs');
    writeFileSync(file, `import test from 'node:test'; import assert from 'node:assert/strict';
test('passes', () => {});
test('skipped', {skip:true}, () => {});
test('fails & <tag>', () => assert.fail('broken\\n::notice title=unsafe::value'));`);
    const childEnvironment = { ...process.env };
    delete childEnvironment.NODE_TEST_CONTEXT;
    const run = spawnSync(process.execPath, ['--test', '--test-reporter=junit', file], { encoding: 'utf8', env: childEnvironment });
    assert.equal(run.status, 1);
    const result = cli(directory, run.stdout);
    assert.equal(result.status, 1, result.stderr);
    assert.doesNotMatch(result.stderr, /Tooling test reporting failed/, run.stdout + '\n' + result.stderr);
    assert.match(readFileSync(join(directory, 'summary.md'), 'utf8'), /\| Node.js tooling \| 1 \| 1 \| 1 \| 3 \|/);
    assert.match(result.stdout, /::error title=fails & <tag>::/);
    assert.equal(result.stdout.split('\n').some(line => line.startsWith('::notice')), false);
});
test('Tooling reporter accepts passing Node cases and escaped literal tag payloads', t => {
    const directory = fixture(t);
    const result = cli(directory, '<testsuites><testcase name="pass &lt;testcase&gt;"/></testsuites>');
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /1\/1 tooling tests passed/);
});
test('Missing, empty, truncated and mismatched tooling reports fail closed', t => {
    const directory = fixture(t);
    assert.equal(cli(directory).status, 1);
    for (const xml of ['', '<testsuites/>', '<testsuites><testcase name="pass"/>', '<testsuites><testcase name="pass"></testsuites>', '<testsuites><testcase name="pass"/></testsuites><testsuites/>']) {
        assert.equal(cli(directory, xml).status, 1, xml);
    }
});
test('Tooling parser refuses DTDs, external entities and undeclared entities', () => {
    for (const xml of [
        '<!DOCTYPE testsuites SYSTEM "file:///etc/passwd"><testsuites><testcase name="pass"/></testsuites>',
        '<!DOCTYPE testsuites [<!ENTITY secret SYSTEM "https://example.org/">]><testsuites><testcase name="&secret;"/></testsuites>',
        '<testsuites><testcase name="&unknown;"/></testsuites>',
    ]) assert.throws(() => summarizeTooling(xml));
});
