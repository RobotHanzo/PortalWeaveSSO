import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildCatalogs, compileMo, parsePo, validateCatalog } from '../scripts/l10n.mjs';

test('Bundled catalogs validate and compiled MO files match their editable PO sources', () => {
  const results = buildCatalogs({ check: true });
  assert.deepEqual(results.map(result => result.locale), ['de_DE', 'es_ES', 'fr_FR', 'ja', 'pt_BR', 'zh_CN', 'zh_TW']);
  for (const result of results) assert.ok(result.translated >= 333, `Translation coverage regressed: ${result.locale}`);
});

test('PO parsing preserves multiline strings, contexts, plurals and UTF-8', () => {
  const entries = parsePo('#. Translator note\nmsgctxt "menu"\nmsgid "First "\n"line"\nmsgstr "Première ligne"\n\nmsgid "one"\nmsgid_plural "many"\nmsgstr[0] "un"\nmsgstr[1] "plusieurs"\n');
  assert.equal(entries[0].id, 'First line');
  assert.equal(entries[0].context, 'menu');
  assert.equal(entries[0].strings[0], 'Première ligne');
  assert.deepEqual(entries[1].strings, ['un', 'plusieurs']);
  for (const invalid of ['msgid "incomplete"', 'msgid "a"\nmsgstr "a"\n\nmsgid "a"\nmsgstr "b"', 'msgid "a"\nmsgstr "a"\nmsgstr "b"', 'unrecognized syntax']) {
    assert.throws(() => parsePo(invalid));
  }
});

test('Catalog validation rejects broken placeholders, markup, fuzzy entries and incomplete plurals', () => {
  const source = readFileSync(new URL('../languages/portalweave-sso-es_ES.po', import.meta.url), 'utf8');
  const template = parsePo(readFileSync(new URL('../languages/portalweave-sso.pot', import.meta.url), 'utf8'));
  for (const mutate of [
    entry => { entry.strings = ['Iniciar sesión']; },
    entry => { entry.strings = ['Iniciar sesión con %d']; },
    entry => { entry.strings = ['<script>alert(1)</script> %s']; },
    entry => { entry.flags.push('fuzzy'); },
  ]) {
    const entries = parsePo(source);
    mutate(entries.find(entry => entry.id === 'Sign in with %s'));
    assert.throws(() => validateCatalog(entries, template, 'es_ES'));
  }
  const entries = parsePo(source);
  entries.find(entry => entry.plural).strings = ['%1$s: %2$s evento'];
  assert.throws(() => validateCatalog(entries, template, 'es_ES'), /plural/);
  const unknown = parsePo(source);
  unknown.push({ id: 'Deleted source string', strings: ['Traducción'], flags: [] });
  assert.throws(() => validateCatalog(unknown, template, 'es_ES'), /template/);
});

test('MO encodes context, plural separators and UTF-8 byte lengths for gettext readers', () => {
  const mo = compileMo([
    { id: '', strings: ['Content-Type: text/plain; charset=UTF-8\n'], flags: [] },
    { id: 'Login', context: 'menu', strings: ['登录'], flags: [] },
    { id: 'event', plural: 'events', strings: ['événement', 'événements'], flags: [] },
    { id: 'untranslated', strings: [''], flags: [] },
  ]);
  assert.equal(mo.readUInt32LE(0), 0x950412de);
  const count = mo.readUInt32LE(8);
  assert.equal(count, 3);
  const pairs = new Map();
  for (let index = 0; index < count; index++) {
    const values = [12, 16].map(header => {
      const cursor = mo.readUInt32LE(header) + index * 8;
      const length = mo.readUInt32LE(cursor);
      const offset = mo.readUInt32LE(cursor + 4);
      assert.equal(mo[offset + length], 0);
      return mo.subarray(offset, offset + length).toString('utf8');
    });
    pairs.set(...values);
  }
  assert.equal(pairs.get('menu\x04Login'), '登录');
  assert.equal(pairs.get('event\0events'), 'événement\0événements');
  assert.ok(!pairs.has('untranslated'));
});
