import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
export const locales = {
  de_DE: 'nplurals=2; plural=(n != 1);',
  es_ES: 'nplurals=2; plural=(n != 1);',
  fr_FR: 'nplurals=2; plural=(n > 1);',
  pt_BR: 'nplurals=2; plural=(n > 1);',
  ja: 'nplurals=1; plural=0;',
  zh_CN: 'nplurals=1; plural=0;',
  zh_TW: 'nplurals=1; plural=0;',
};

/** Read gettext PO strings, contexts, plurals and flags without runtime dependencies. */
export function parsePo(source) {
  const entries = [];
  let entry;
  let field;
  const finish = () => {
    if (!entry) return;
    if (entry.id === undefined || !entry.strings.length) throw new Error('Incomplete PO entry');
    entries.push(entry);
    entry = undefined;
    field = undefined;
  };
  for (const line of source.replaceAll('\r\n', '\n').split('\n')) {
    if (!line.trim()) { finish(); continue; }
    entry ??= { strings: [], flags: [] };
    if (line.startsWith('#')) {
      if (line.startsWith('#,')) entry.flags.push(...line.slice(2).split(',').map(flag => flag.trim()));
      continue;
    }
    const match = line.match(/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?) (".*")$/);
    if (match) {
      field = match[1].startsWith('msgstr') ? Number(match[2] ?? 0) : { msgctxt: 'context', msgid: 'id', msgid_plural: 'plural' }[match[1]];
      if (typeof field === 'number') {
        if (entry.strings[field] !== undefined) throw new Error('Duplicate msgstr');
        entry.strings[field] = JSON.parse(match[3]);
      } else {
        if (entry[field] !== undefined) throw new Error('Duplicate PO field');
        entry[field] = JSON.parse(match[3]);
      }
    } else if (line.startsWith('"') && field !== undefined) {
      if (typeof field === 'number') entry.strings[field] += JSON.parse(line);
      else entry[field] += JSON.parse(line);
    } else throw new Error(`Invalid PO line: ${line}`);
  }
  finish();
  const seen = new Set();
  for (const item of entries) {
    const key = messageKey(item);
    if (seen.has(key)) throw new Error(`Duplicate message: ${item.id}`);
    seen.add(key);
  }
  return entries;
}

const messageKey = entry => (entry.context === undefined ? '' : `${entry.context}\x04`) + entry.id;
const tokens = text => [...text.matchAll(/%(?:\d+\$)?[-+0 #]*(?:\d+)?(?:\.\d+)?[bcdeEfFgGosuxX]|\{app\}|##(?:user|Sign Out)##/g)].map(match => match[0]).sort();

export function validateCatalog(entries, template, locale) {
  if (!Object.hasOwn(locales, locale)) throw new Error(`Unsupported locale: ${locale}`);
  const header = entries.find(entry => entry.id === '' && entry.context === undefined)?.strings[0] ?? '';
  for (const expected of [`Language: ${locale}\n`, 'Content-Type: text/plain; charset=UTF-8\n', `Plural-Forms: ${locales[locale]}\n`, 'X-Domain: portalweave-sso\n']) {
    if (!header.includes(expected)) throw new Error(`Missing catalog header: ${expected.trim()}`);
  }
  const messages = new Map(template.filter(entry => entry.id).map(entry => [messageKey(entry), entry]));
  let count = 0;
  for (const entry of entries) {
    if (!entry.id) continue;
    const original = messages.get(messageKey(entry));
    if (!original || original.plural !== entry.plural) throw new Error(`Message absent from template: ${entry.id}`);
    if (entry.flags.includes('fuzzy')) throw new Error(`Review fuzzy translation: ${entry.id}`);
    if (entry.strings.every(text => text === '')) continue;
    const forms = entry.plural ? Number(locales[locale].match(/nplurals=(\d+)/)[1]) : 1;
    if (entry.strings.length !== forms || Array.from({ length: forms }, (_, index) => entry.strings[index]).some(text => !text)) throw new Error(`Incomplete plural translation: ${entry.id}`);
    for (const text of entry.strings) {
      if (text.includes('\0') || JSON.stringify(tokens(text)) !== JSON.stringify(tokens(entry.id))) throw new Error(`Translation placeholders differ: ${entry.id}`);
      if (/<\/?[a-z][^>]*>/i.test(text) && !/<\/?[a-z][^>]*>/i.test(entry.id)) throw new Error(`Unexpected translation markup: ${entry.id}`);
    }
    count++;
  }
  return count;
}

/** GNU MO revision 0, UTF-8, sorted by original byte sequence for gettext lookup. */
export function compileMo(entries) {
  const messages = entries.filter(entry => !entry.flags.includes('fuzzy') && entry.strings.every(text => text !== ''))
    .map(entry => ({ id: Buffer.from(messageKey(entry) + (entry.plural ? `\0${entry.plural}` : '')), text: Buffer.from(entry.strings.join('\0')) }))
    .sort((a, b) => Buffer.compare(a.id, b.id));
  const count = messages.length;
  const originals = 28;
  const translations = originals + count * 8;
  let offset = translations + count * 8;
  const output = Buffer.alloc(offset + messages.reduce((size, message) => size + message.id.length + message.text.length + 2, 0));
  [0x950412de, 0, count, originals, translations, 0, 0].forEach((value, index) => output.writeUInt32LE(value, index * 4));
  for (const [index, message] of messages.entries()) {
    output.writeUInt32LE(message.id.length, originals + index * 8);
    output.writeUInt32LE(offset, originals + index * 8 + 4);
    message.id.copy(output, offset);
    offset += message.id.length + 1;
  }
  for (const [index, message] of messages.entries()) {
    output.writeUInt32LE(message.text.length, translations + index * 8);
    output.writeUInt32LE(offset, translations + index * 8 + 4);
    message.text.copy(output, offset);
    offset += message.text.length + 1;
  }
  return output;
}

export function buildCatalogs({ directory = path.join(root, 'languages'), check = false } = {}) {
  const template = parsePo(readFileSync(path.join(directory, 'portalweave-sso.pot'), 'utf8'));
  const files = readdirSync(directory).filter(file => file.endsWith('.po')).sort();
  if (files.length !== Object.keys(locales).length) throw new Error('Expected all seven bundled locales');
  return files.map(file => {
    const locale = file.replace(/^portalweave-sso-/, '').replace(/\.po$/, '');
    if (file !== `portalweave-sso-${locale}.po`) throw new Error(`Unexpected catalog filename: ${file}`);
    const entries = parsePo(readFileSync(path.join(directory, file), 'utf8'));
    const translated = validateCatalog(entries, template, locale);
    const binary = compileMo(entries);
    const output = path.join(directory, file.replace(/\.po$/, '.mo'));
    if (check) {
      if (!readFileSync(output).equals(binary)) throw new Error(`Recompile stale catalog: ${file}`);
    } else writeFileSync(output, binary);
    return { locale, translated, total: template.length - 1 };
  });
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    if (process.argv.slice(2).some(arg => arg !== '--check')) throw new Error('Usage: bash scripts/l10n.sh [--check]');
    for (const result of buildCatalogs({ check: process.argv.includes('--check') })) console.log(`${result.locale}: ${result.translated}/${result.total} messages translated`);
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}
