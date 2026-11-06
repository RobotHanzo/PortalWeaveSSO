#!/usr/bin/env node
/** Fail closed on the native `wp plugin check --format=json --no-color` output. */
import { appendFileSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { commandEscape } from './report-results.mjs';

const MAX_BYTES = 8 * 1024 * 1024;
const MAX_FINDINGS = 20000;
const CLEAN_OUTPUT = 'Success: Checks complete. No errors found.';
const FIELDS = ['line', 'column', 'type', 'code', 'message', 'docs'];

function xml(value) {
    return String(value).toWellFormed().replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFE\uFFFF]/g, '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}

function markdown(value) {
    return String(value).slice(0, 1500).replace(/[\r\n]+/g, ' ').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/([\\`*_{}\[\]()#+.!|])/g, '\\$1');
}

function validateFinding(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value) ||
        Object.keys(value).length !== FIELDS.length || FIELDS.some(field => !Object.hasOwn(value, field))) {
        throw new Error('Each finding must contain exactly line, column, type, code, message and docs.');
    }
    if (!Number.isSafeInteger(value.line) || value.line < 0 || !Number.isSafeInteger(value.column) || value.column < 0 ||
        !['ERROR', 'WARNING'].includes(value.type) || typeof value.code !== 'string' ||
        !/^[A-Za-z0-9_.-]{1,512}$/.test(value.code) || typeof value.message !== 'string' ||
        !value.message.trim() || value.message.length > 65536 || typeof value.docs !== 'string' || value.docs.length > 8192) {
        throw new Error('A finding has an invalid position, severity, code, message or documentation field.');
    }
}

/** Parse every complete FILE/JSON block; unfamiliar or incomplete output never counts as success. */
export function parsePluginCheck(raw, { strict = false } = {}) {
    if (typeof raw !== 'string' || !raw.trim() || Buffer.byteLength(raw, 'utf8') > MAX_BYTES) {
        throw new Error('Plugin Check output is empty or exceeds 8 MiB.');
    }
    if (typeof strict !== 'boolean') throw new Error('The strict policy must be a boolean.');
    const source = raw.replace(/^\uFEFF/, '').replace(/\r\n/g, '\n').trim();
    const findings = [];
    const files = new Set();
    if (source !== CLEAN_OUTPUT) {
        let current;
        let block = [];
        const finish = () => {
            if (current === undefined) return;
            let values;
            try { values = JSON.parse(block.join('\n')); }
            catch { throw new Error(`Missing, truncated or invalid JSON for FILE: ${current || '(plugin)'}.`); }
            if (!Array.isArray(values) || !values.length) throw new Error('Each FILE block must contain a nonempty array of findings.');
            if (findings.length + values.length > MAX_FINDINGS) throw new Error('Plugin Check output exceeds 20000 findings.');
            for (const value of values) {
                validateFinding(value);
                findings.push({ file: current, ...value });
            }
            files.add(current);
        };
        for (const line of source.split('\n')) {
            if (line.startsWith('FILE: ')) {
                finish();
                current = line.slice(6);
                if (current.length > 4096 || /[\u0000-\u001F\u007F]/.test(current) || current.trim() !== current) {
                    throw new Error('A FILE header contains an invalid or overlong path.');
                }
                block = [];
            } else if (current !== undefined) block.push(line);
            else if (line.trim()) throw new Error('Unexpected output before a FILE header or the explicit clean-result message.');
        }
        finish();
        if (!findings.length) throw new Error('The explicit Plugin Check clean-result message is missing.');
    }
    const errors = findings.filter(finding => finding.type === 'ERROR').length;
    const warnings = findings.length - errors;
    const tests = findings.map((finding, index) => ({
        name: `${index + 1}. ${finding.type} ${finding.code}: ${finding.file || '(plugin)'}:${finding.line}:${finding.column}`,
        pass: finding.type !== 'ERROR' && !strict,
        duration: 0,
        error: `${finding.file || '(plugin)'}:${finding.line}:${finding.column} ${finding.code}\n${finding.message}${finding.docs ? '\n' + finding.docs : ''}`,
    }));
    if (!tests.length) tests.push({ name: 'Plugin Check completed without findings', pass: true, duration: 0 });
    return { suite: 'plugin-check', valid: true, strict, findings, tests,
        summary: { total: findings.length, errors, warnings, files: files.size }, success: errors === 0 && (!strict || warnings === 0) };
}

export function invalidPluginCheck(message, strict = false) {
    return { suite: 'plugin-check', valid: false, strict, findings: [],
        tests: [{ name: 'Plugin Check output is complete and valid', pass: false, duration: 0, error: message }],
        summary: { total: null, errors: null, warnings: null, files: null }, success: false, error: message };
}

export function pluginCheckSummary(result) {
    if (!result.valid) return '### WordPress Plugin Check\n\n**Missing or invalid check output. Publication readiness could not be verified.**\n\n' + markdown(result.error) + '\n';
    const lines = ['### WordPress Plugin Check', '', '| Errors | Warnings | Findings | Files |', '| ---: | ---: | ---: | ---: |',
        `| ${result.summary.errors} | ${result.summary.warnings} | ${result.summary.total} | ${result.summary.files} |`, '',
        `**${result.success ? 'Passed' : 'Failed'}.** Policy: ${result.strict ? 'errors and warnings fail' : 'errors fail; warnings are reported'}.`];
    for (const finding of result.findings.slice(0, 50)) {
        lines.push('', `- **${finding.type} ${markdown(finding.code)}** — ${markdown(finding.file || '(plugin)')}:${finding.line}:${finding.column}: ${markdown(finding.message)}`);
    }
    if (result.findings.length > 50) lines.push('', `${result.findings.length - 50} additional findings are retained in the JSON and JUnit artifacts.`);
    return lines.join('\n') + '\n';
}

export function writePluginCheckReports(result, directory) {
    mkdirSync(directory, { recursive: true });
    const failures = result.tests.filter(test => !test.pass).length;
    const cases = result.tests.map(test => `<testcase classname="wordpress.plugin-check" name="${xml(test.name)}" time="0">${test.pass
        ? test.error ? `<system-out>${xml(test.error)}</system-out>` : ''
        : `<failure type="PluginCheckFinding" message="${xml(test.error)}">${xml(test.error)}</failure>`}</testcase>`).join('');
    writeFileSync(join(directory, 'plugin-check.json'), JSON.stringify(result, null, 2) + '\n', 'utf8');
    writeFileSync(join(directory, 'plugin-check.xml'), `<?xml version="1.0" encoding="UTF-8"?>\n<testsuites tests="${result.tests.length}" failures="${failures}" errors="0"><testsuite name="plugin-check" tests="${result.tests.length}" failures="${failures}" errors="0" skipped="0">${cases}</testsuite></testsuites>\n`, 'utf8');
}

function annotations(result) {
    if (!result.valid) return [{ type: 'error', title: 'Plugin Check output invalid', message: result.error }];
    return [...result.findings.filter(finding => finding.type === 'ERROR'), ...result.findings.filter(finding => finding.type === 'WARNING')]
        .slice(0, 50).map(finding => ({ type: finding.type.toLowerCase(), title: finding.code, message: finding.message,
            file: finding.file, line: finding.line, column: finding.column }));
}

function inputCollision(input, directory) {
    if (!input) return false;
    const sameName = (left, right) => process.platform === 'win32' ? left.toLowerCase() === right.toLowerCase() : left === right;
    return ['plugin-check.json', 'plugin-check.xml'].some(name => {
        const target = resolve(directory, name);
        if (sameName(input, target)) return true;
        try {
            const source = statSync(input);
            const destination = statSync(target);
            return source.dev === destination.dev && source.ino === destination.ino;
        } catch (error) {
            if (['ENOENT', 'ENOTDIR'].includes(error.code)) return false;
            throw error;
        }
    });
}

function main(args) {
    let input;
    let output = process.env.WPSSO_REPORT_DIR || 'reports/plugin-check';
    let outputGiven = false;
    let strict = false;
    let result;
    try {
        for (let index = 0; index < args.length; index++) {
            if (args[index] === '--output') {
                if (outputGiven || !args[index + 1] || args[index + 1].startsWith('--')) throw new Error('--output requires one report directory.');
                output = args[++index];
                outputGiven = true;
            } else if (args[index] === '--strict' && !strict) strict = true;
            else if (args[index].startsWith('-') || input) throw new Error('Usage: node scripts/plugin-check.mjs <rawfile> [--output DIRECTORY] [--strict]');
            else input = args[index];
        }
        if (!input) throw new Error('A native Plugin Check output file is required.');
        input = resolve(input);
        output = resolve(output);
        if (inputCollision(input, output)) throw new Error('The report destination must not overwrite the input file.');
        if (!statSync(input).isFile() || statSync(input).size > MAX_BYTES) throw new Error('Plugin Check input must be a regular file no larger than 8 MiB.');
        const raw = new TextDecoder('utf-8', { fatal: true }).decode(readFileSync(input));
        result = parsePluginCheck(raw, { strict });
    } catch (error) {
        result = invalidPluginCheck(error.message, strict);
    }
    try {
        // Input/report collisions are always rejected before writing any output artifact.
        if (inputCollision(input, output)) throw new Error('The report destination must not overwrite the input file.');
        writePluginCheckReports(result, output);
    } catch (error) {
        result = invalidPluginCheck(`Plugin Check reporting failed: ${error.message}`, strict);
    }
    let summary = pluginCheckSummary(result);
    if (process.env.GITHUB_STEP_SUMMARY) {
        try { appendFileSync(process.env.GITHUB_STEP_SUMMARY, summary, 'utf8'); }
        catch (error) {
            result = invalidPluginCheck(`Plugin Check summary reporting failed: ${error.message}`, strict);
            summary = pluginCheckSummary(result);
        }
    }
    process.stdout.write(summary);
    for (const annotation of annotations(result)) {
        const properties = [`title=${commandEscape(annotation.title.slice(0, 300), true)}`];
        if (annotation.file) properties.push(`file=${commandEscape(annotation.file, true)}`);
        if (annotation.line > 0) properties.push(`line=${annotation.line}`);
        if (annotation.column > 0) properties.push(`col=${annotation.column}`);
        console.log(`::${annotation.type} ${properties.join(',')}::${commandEscape(annotation.message.slice(0, 8000))}`);
    }
    process.exitCode = result.success ? 0 : 1;
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) main(process.argv.slice(2));
