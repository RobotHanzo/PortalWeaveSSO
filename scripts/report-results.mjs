#!/usr/bin/env node
import { appendFileSync, existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const textLimit = 8000;
export function commandEscape(value, property = false) {
    let text = String(value).replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
    if (property) text = text.replace(/:/g, '%3A').replace(/,/g, '%2C');
    return text;
}
function markdown(value) {
    return String(value).slice(0, textLimit).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/([\\`*_{}\[\]()#+.!|])/g, '\\$1').replace(/[\r\n]+/g, ' ');
}
function reportFiles(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        if (entry.isSymbolicLink()) return [];
        const path = join(directory, entry.name);
        return entry.isDirectory() ? reportFiles(path) : entry.isFile() && entry.name.endsWith('.json') ? [path] : [];
    });
}

export function summarizeReports(directory, expected = []) {
    const issues = [];
    const reports = [];
    const names = new Set();
    let files = [];
    try { files = reportFiles(directory); }
    catch (error) { issues.push(`Cannot read report directory: ${error.message}`); }
    if (!files.length) issues.push('No JSON test reports were found.');
    for (const path of files) {
        try {
            if (statSync(path).size > 8 * 1024 * 1024) throw new Error('Report exceeds the 8 MiB limit.');
            const report = JSON.parse(readFileSync(path, 'utf8'));
            if (!report || typeof report.suite !== 'string' || !/^[a-z][a-z0-9-]*$/.test(report.suite) || basename(path) !== `${report.suite}.json`) throw new Error('Invalid suite name or filename.');
            if (names.has(report.suite)) throw new Error(`Duplicate suite: ${report.suite}`);
            if (!Array.isArray(report.tests) || !report.tests.length) throw new Error('Suite has no test cases.');
            for (const result of report.tests) {
                if (!result || typeof result.name !== 'string' || !result.name || typeof result.pass !== 'boolean' ||
                    (result.error !== undefined && typeof result.error !== 'string') ||
                    (result.duration !== undefined && (typeof result.duration !== 'number' || !Number.isFinite(result.duration) || result.duration < 0))) throw new Error('Invalid test-case schema.');
            }
            if (!existsSync(path.replace(/\.json$/, '.xml'))) throw new Error('JUnit XML report is missing.');
            names.add(report.suite);
            reports.push(report);
        } catch (error) { issues.push(`${basename(path)}: ${error.message}`); }
    }
    for (const suite of expected) {
        if (!names.has(suite)) issues.push(`Required test suite is missing: ${suite}`);
    }
    const total = reports.reduce((count, report) => count + report.tests.length, 0);
    const failed = reports.reduce((count, report) => count + report.tests.filter(test => !test.pass).length, 0);
    const lines = ['### Test results', '', '| Suite | Passed | Failed | Total |', '| --- | ---: | ---: | ---: |'];
    for (const report of reports) {
        const failures = report.tests.filter(result => !result.pass).length;
        lines.push(`| ${markdown(report.suite)} | ${report.tests.length - failures} | ${failures} | ${report.tests.length} |`);
    }
    lines.push('', `**${total - failed}/${total} tests passed.** ${issues.length ? `${issues.length} reporting error(s).` : ''}`);
    const annotations = [];
    for (const issue of issues) {
        lines.push('', `- Reporting error: ${markdown(issue)}`);
        annotations.push({ title: 'Test reporting error', message: issue });
    }
    const failures = reports.flatMap(report => report.tests.filter(result => !result.pass).map(result => ({ suite: report.suite, ...result })));
    for (const failure of failures.slice(0, 50)) {
        lines.push('', `- **${markdown(failure.suite)}: ${markdown(failure.name)}** — ${markdown(failure.error ?? 'Test failed.')}`);
        annotations.push({ title: `${failure.suite}: ${failure.name}`, message: failure.error ?? 'Test failed.' });
    }
    if (failures.length > 50) lines.push('', `${failures.length - 50} additional failures are available in the report artifacts.`);
    return { total, failed, issues, reports, annotations, markdown: lines.join('\n') + '\n', success: total > 0 && failed === 0 && issues.length === 0 };
}

function main(args) {
    let directory;
    const expected = [];
    for (let i = 0; i < args.length; i++) {
        if (args[i] === '--expect') {
            if (!args[i + 1] || args[i + 1].startsWith('--')) throw new Error('--expect requires a comma-separated suite list.');
            expected.push(...args[++i].split(',').filter(Boolean));
        } else if (args[i].startsWith('-') || directory) throw new Error('Usage: node scripts/report-results.mjs <directory> [--expect integration,https]');
        else directory = args[i];
    }
    directory ??= process.env.WPSSO_REPORT_DIR;
    if (!directory) throw new Error('A report directory is required.');
    if (expected.some(suite => !/^[a-z][a-z0-9-]*$/.test(suite))) throw new Error('Expected suite names are invalid.');
    const result = summarizeReports(resolve(directory), [...new Set(expected)]);
    if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, result.markdown, 'utf8');
    process.stdout.write(result.markdown);
    for (const annotation of result.annotations) {
        console.log(`::error title=${commandEscape(annotation.title.slice(0, 300), true)}::${commandEscape(annotation.message.slice(0, textLimit))}`);
    }
    process.exitCode = result.success ? 0 : 1;
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
    try { main(process.argv.slice(2)); }
    catch (error) {
        console.error(`::error title=Test reporting failed::${commandEscape(error.message)}`);
        process.exitCode = 1;
    }
}
