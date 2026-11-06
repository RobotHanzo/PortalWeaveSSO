#!/usr/bin/env node
import { appendFileSync, readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { commandEscape } from './report-results.mjs';

function decode(value) {
    if (/&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[a-fA-F0-9]+);)/.test(value)) throw new Error('Invalid XML entity.');
    return value.replace(/&([^;]+);/g, (_, entity) => {
        const named = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
        if (Object.hasOwn(named, entity)) return named[entity];
        const code = entity.startsWith('#x') ? Number.parseInt(entity.slice(2), 16) : Number.parseInt(entity.slice(1), 10);
        if (!Number.isInteger(code) || (code < 0x20 && ![9, 10, 13].includes(code)) || code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff) || [0xfffe, 0xffff].includes(code)) throw new Error('Invalid XML character reference.');
        return String.fromCodePoint(code);
    });
}

/** Parse the bounded XML subset emitted by Node's built-in JUnit reporter. No entities or DTDs are loaded. */
export function summarizeTooling(xml) {
    if (typeof xml !== 'string' || !xml.trim() || xml.length > 8 * 1024 * 1024) throw new Error('JUnit report is empty or exceeds 8 MiB.');
    if (/<!DOCTYPE|<!ENTITY/i.test(xml) || /[\u0000-\u0008\u000B\u000C\u000E-\u001F]/.test(xml)) throw new Error('XML declarations or characters are unsupported.');
    const tags = /<!--[^]*?-->|<\?xml[^?]*\?>|<!\[CDATA\[[^]*?\]\]>|<\/?[A-Za-z](?:[^"'<>]|"[^"]*"|'[^']*')*>/g;
    const stack = [];
    const cases = [];
    let cursor = 0;
    let root = false;
    let closed = false;
    let current;
    function text(value, literal = false) {
        if (value.includes('<') && !literal) throw new Error('Truncated or malformed XML markup.');
        const decoded = literal ? value : decode(value);
        if (current && ['failure', 'error'].includes(stack.at(-1))) current.error += decoded.slice(0, 8000);
        else if (decoded.trim() && !['skipped', 'system-out', 'system-err'].includes(stack.at(-1))) throw new Error('Unexpected text outside a JUnit result.');
    }
    for (const match of xml.matchAll(tags)) {
        text(xml.slice(cursor, match.index));
        cursor = match.index + match[0].length;
        const tag = match[0];
        if (tag.startsWith('<!--')) {
            if (tag.slice(4, -3).includes('--')) throw new Error('Malformed XML comment.');
            continue;
        }
        if (tag.startsWith('<?xml')) {
            if (root || xml.slice(0, match.index).trim()) throw new Error('Unexpected XML declaration.');
            continue;
        }
        if (tag.startsWith('<![CDATA[')) { text(tag.slice(9, -3), true); continue; }
        const end = tag.match(/^<\/([a-z-]+)\s*>$/);
        if (end) {
            if (stack.pop() !== end[1]) throw new Error('Mismatched XML closing tag.');
            if (end[1] === 'testcase') current = undefined;
            if (end[1] === 'testsuites') closed = true;
            continue;
        }
        const start = tag.match(/^<([a-z-]+)([^]*?)(\/?)>$/);
        if (!start || closed) throw new Error('Malformed XML element or multiple roots.');
        const [, name, raw, selfClosing] = start;
        if (!['testsuites', 'testsuite', 'testcase', 'failure', 'error', 'skipped', 'system-out', 'system-err'].includes(name)) throw new Error('Unsupported JUnit element.');
        const attributes = {};
        const attribute = /\s+([A-Za-z_][\w.:-]*)\s*=\s*(?:"([^"<]*)"|'([^'<]*)')/gy;
        let endIndex = 0;
        while (true) {
            attribute.lastIndex = endIndex;
            const item = attribute.exec(raw);
            if (!item) break;
            if (Object.hasOwn(attributes, item[1])) throw new Error('Duplicate XML attribute.');
            attributes[item[1]] = decode(item[2] ?? item[3]);
            endIndex = attribute.lastIndex;
        }
        if (raw.slice(endIndex).trim()) throw new Error('Malformed XML attributes.');
        const parent = stack.at(-1);
        if (name === 'testsuites') {
            if (root || stack.length) throw new Error('Multiple JUnit roots.');
            root = true;
            if (selfClosing) closed = true;
        } else if (['testsuite', 'testcase'].includes(name)) {
            if (!root || !['testsuites', 'testsuite'].includes(parent)) throw new Error('Unexpected JUnit test hierarchy.');
            if (name === 'testcase') {
                if (!attributes.name) throw new Error('JUnit testcase has no name.');
                current = { name: attributes.name, status: Object.hasOwn(attributes, 'failure') ? 'failed' : 'passed', error: attributes.failure ?? '' };
                cases.push(current);
                if (selfClosing) current = undefined;
            }
        } else {
            if (!root || !stack.length || (!['system-out', 'system-err'].includes(name) && parent !== 'testcase')) throw new Error('JUnit result is outside a testcase.');
            if (['failure', 'error'].includes(name)) {
                current.status = 'failed';
                current.error = attributes.message ? `${attributes.message}\n` : '';
            } else if (name === 'skipped' && current.status !== 'failed') current.status = 'skipped';
        }
        if (!selfClosing) stack.push(name);
    }
    text(xml.slice(cursor));
    if (!root || !closed || stack.length || !cases.length) throw new Error('JUnit report is incomplete or contains no testcases.');
    const failed = cases.filter(result => result.status === 'failed');
    const skipped = cases.filter(result => result.status === 'skipped').length;
    const passed = cases.length - failed.length - skipped;
    const markdown = `### Tooling test results\n\n| Suite | Passed | Failed | Skipped | Total |\n| --- | ---: | ---: | ---: | ---: |\n| Node.js tooling | ${passed} | ${failed.length} | ${skipped} | ${cases.length} |\n\n**${passed}/${cases.length} tooling tests passed.**\n`;
    return { total: cases.length, passed, failed: failed.length, skipped, failures: failed, markdown, success: failed.length === 0 };
}

function main(args) {
    try {
        if (args.length !== 1) throw new Error('Usage: node scripts/report-tooling.mjs <tooling.xml>');
        const file = resolve(args[0]);
        if (statSync(file).size > 8 * 1024 * 1024) throw new Error('JUnit report exceeds 8 MiB.');
        const result = summarizeTooling(readFileSync(file, 'utf8'));
        if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, result.markdown, 'utf8');
        process.stdout.write(result.markdown);
        for (const failure of result.failures.slice(0, 50)) console.log(`::error title=${commandEscape(failure.name.slice(0, 300), true)}::${commandEscape((failure.error || 'Tooling test failed.').slice(0, 8000))}`);
        process.exitCode = result.success ? 0 : 1;
    } catch (error) {
        const message = `Tooling test reporting failed: ${error.message}`;
        if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, '### Tooling test results\n\n**Report missing or invalid. See the job annotations.**\n', 'utf8');
        console.error(`::error title=Tooling test reporting failed::${commandEscape(message)}`);
        process.exitCode = 1;
    }
}
if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) main(process.argv.slice(2));
