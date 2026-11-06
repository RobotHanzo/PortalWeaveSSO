import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export function xmlEscape(value) {
    return String(value).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}

export function writeReports(suite, results, directory = process.env.WPSSO_REPORT_DIR) {
    if (!directory) return;
    if (!/^[a-z][a-z0-9-]*$/.test(suite)) throw new Error('Invalid report suite name');
    mkdirSync(directory, { recursive: true });
    const failed = results.filter(result => !result.pass).length;
    const duration = results.reduce((total, result) => total + (result.duration ?? 0), 0);
    const cases = results.map(result => `<testcase classname="wpsso.${suite}" name="${xmlEscape(result.name)}" time="${(result.duration ?? 0).toFixed(6)}">${result.pass ? '' : `<failure type="AssertionFailure" message="${xmlEscape(result.error ?? 'Test failed.')}">${xmlEscape(result.error ?? 'Test failed.')}</failure>`}</testcase>`).join('');
    const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<testsuites tests="${results.length}" failures="${failed}" errors="0" time="${duration.toFixed(6)}"><testsuite name="${suite}" tests="${results.length}" failures="${failed}" errors="0" skipped="0" time="${duration.toFixed(6)}">${cases}</testsuite></testsuites>\n`;
    writeFileSync(join(directory, `${suite}.json`), JSON.stringify({ suite, tests: results, summary: {
        total: results.length, passed: results.length - failed, failed, duration,
    } }, null, 2) + '\n', 'utf8');
    writeFileSync(join(directory, `${suite}.xml`), xml, 'utf8');
}

export function reportGuard(suite, results) {
    let complete = false;
    process.once('exit', () => {
        if (complete) return;
        results.push({ name: 'Test runner did not complete', pass: false, duration: 0, error: 'The process exited before the suite completed.' });
        try { writeReports(suite, results); }
        catch (error) { console.error(`Test reporting failed: ${error.message}`); }
        process.exitCode = 1;
    });
    return () => {
        complete = true;
        try { writeReports(suite, results); }
        catch (error) { console.error(`Test reporting failed: ${error.message}`); process.exitCode = 1; }
    };
}
