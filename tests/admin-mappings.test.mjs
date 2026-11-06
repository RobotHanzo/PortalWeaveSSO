import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { readRows, writeRows, serializeRows } = createRequire(import.meta.url)('../assets/admin-mappings.js');
const convert = (type, value) => JSON.parse(writeRows(type, readRows(type, JSON.stringify(value))));

test('Visual mapping conversions preserve provider paths, domains, Unicode and literal markup', () => {
    for (const type of ['mapping', 'custom_mapping', 'domain_mapping']) {
        const value = type === 'mapping' ? { email: 'claims.mail', groups: 'realm_access.roles', first_name: '' }
            : type === 'domain_mapping' ? { 'old.example': 'new.example' }
                : { department: '组织.<img src=x onerror=alert(1)>', '__proto__': 'ignored-by-object-literal' };
        assert.deepEqual(convert(type, value), value);
    }
});

test('Visual role maps preserve multiple exact roles and support existing list syntax', () => {
    assert.deepEqual(convert('role_mapping', { members: ['subscriber', 'author'], admins: ['administrator'] }), { members: ['subscriber', 'author'], admins: ['administrator'] });
    assert.deepEqual(convert('domain_roles', { 'school.example': 'subscriber, author' }), { 'school.example': ['subscriber', 'author'] });
});

test('Visual redirect rules retain order, scalar match types and exact URLs', () => {
    const value = [
        { claim: 'department', equals: 'sales', url: 'https://site.example/sales/?a=1&b=2' },
        { claim: 'active', equals: false, url: 'https://site.example/inactive/' },
        { claim: 'level', equals: 1.5, url: 'https://site.example/level/' },
        { claim: 'unset', equals: null, url: 'https://site.example/unset/' },
    ];
    assert.deepEqual(convert('redirect_rules', value), value);
    for (const equals of ['', '0x10', 'Infinity', '01']) {
        assert.throws(() => writeRows('redirect_rules', [{ claim: 'level', equals, value_type: 'number', url: 'https://site.example/' }]), /invalid_value/);
    }
    assert.throws(() => writeRows('redirect_rules', [{ claim: 'active', equals: 'yes', value_type: 'boolean', url: 'https://site.example/' }]), /invalid_value/);
});

test('Existing redirect integers outside the safe range remain in advanced JSON without rounding', () => {
    for (const literal of ['9007199254740993', '-9007199254740993', '9007199254740992', '-9007199254740992', '9223372036854775807']) {
        const source = '[{"claim":"account","equals":' + literal + ',"url":"https://site.example/"}]';
        assert.throws(() => readRows('redirect_rules', source), /unsafe_number/, literal);
        assert.ok(source.includes('"equals":' + literal), 'refusing the visual editor must leave exact source text available');
    }
    const source = '[{"claim":"account","equals":9007199254740993,"url":"https://site.example/"}]';
    assert.equal(JSON.parse(source)[0].equals, 9007199254740992, 'fixture must reproduce JavaScript integer rounding');
});

test('Visual redirect numeric entry rejects unsafe integers but accepts safe boundaries and finite decimals', () => {
    const row = equals => ({ claim: 'account', equals, value_type: 'number', url: 'https://site.example/' });
    for (const literal of ['9007199254740992', '9007199254740993', '-9007199254740992', '-9007199254740993', '9.007199254740992e15', '-9.007199254740992e15']) {
        assert.throws(() => writeRows('redirect_rules', [row(literal)]), /unsafe_number/, literal);
    }
    for (const literal of ['9007199254740991', '-9007199254740991', '0', '-42', '1.5', '-0.125', '1.25e-3']) {
        const encoded = writeRows('redirect_rules', [row(literal)]);
        assert.equal(JSON.parse(encoded)[0].equals, Number(literal), literal);
        assert.equal(readRows('redirect_rules', encoded)[0].value_type, 'number');
    }
    for (const literal of ['1e400', '-1e400']) {
        assert.throws(() => writeRows('redirect_rules', [row(literal)]), /invalid_value/, literal);
        assert.throws(() => readRows('redirect_rules', '[{"claim":"account","equals":' + literal + ',"url":"https://site.example/"}]'), /unsupported/, literal);
    }
});

test('Visual content rules retain post/category scope and explicit access choices', () => {
    const value = { posts: { 42: { login: true, roles: ['subscriber'], groups: ['faculty', 'editors'] } }, categories: { 8: { login: false, roles: [], groups: [] } } };
    assert.deepEqual(convert('content_rules', value), value);
    const rows = readRows('content_rules', JSON.stringify(value));
    assert.throws(() => writeRows('content_rules', [rows[0], rows[0]]), /duplicate/);
    assert.throws(() => writeRows('content_rules', [{ ...rows[0], id: '-1' }]), /required/);
    assert.throws(() => writeRows('content_rules', [{ ...rows[0], id: '1.5' }]), /required/);
});

test('Content group names keep significant spaces and cannot split a single authorization group', () => {
    const value = { posts: { 42: { groups: 'Faculty Team, Support Staff\nGuest Team' } } };
    const result = convert('content_rules', value);
    assert.deepEqual(result.posts[42].groups, ['Faculty Team', 'Support Staff', 'Guest Team']);
    assert.deepEqual(convert('content_rules', { posts: { 42: { groups: ['Faculty, Team', 'Support Staff'] } } }).posts[42].groups, ['Faculty, Team', 'Support Staff']);
    for (const group of ['Faculty\nTeam', 'Faculty\rTeam', 'Faculty\r\nTeam']) {
        assert.throws(() => readRows('content_rules', JSON.stringify({ posts: { 42: { groups: [group] } } })), /unsupported/);
    }
});

test('Returning visual values to the original restores exact source text without ignoring rule order', () => {
    const original = '{\n    "editors": ["editor"],\n    "members": ["subscriber"]\n}';
    const rows = readRows('role_mapping', original);
    assert.equal(serializeRows('role_mapping', rows, rows, original), original);
    assert.equal(serializeRows('role_mapping', [...rows].reverse(), rows, original), original, 'map-key order must not create a false draft');
    assert.notEqual(serializeRows('role_mapping', [{ key: 'editors', roles: ['author'] }, rows[1]], rows, original), original);
    const rules = '[{"claim":"group","equals":"staff","url":"https://site.example/staff/"},{"claim":"group","equals":"members","url":"https://site.example/members/"}]';
    const redirects = readRows('redirect_rules', rules);
    assert.equal(serializeRows('redirect_rules', redirects, redirects, rules), rules);
    assert.notEqual(serializeRows('redirect_rules', [...redirects].reverse(), redirects, rules), rules, 'redirect rule order changes policy');
});

test('Empty maps and lists are usable while unsupported shapes remain in advanced JSON', () => {
    for (const type of ['mapping', 'custom_mapping', 'role_mapping', 'domain_roles', 'domain_mapping', 'redirect_rules', 'content_rules']) {
        assert.deepEqual(readRows(type, '{}'), []);
        assert.deepEqual(readRows(type, '[]'), []);
        assert.throws(() => readRows(type, 'null'), /invalid/);
        assert.throws(() => readRows(type, '"string"'), /invalid/);
        assert.throws(() => readRows(type, '{'), SyntaxError);
    }
    assert.throws(() => readRows('mapping', '{"unknown":"path"}'), /unsupported/);
    assert.throws(() => readRows('custom_mapping', '{"department":{"nested":"value"}}'), /unsupported/);
    assert.throws(() => readRows('role_mapping', '{"editors":["editor",null]}'), /unsupported/);
    assert.throws(() => readRows('redirect_rules', '[{"claim":"department","equals":"sales","url":"https://site.example/","extra":"keep"}]'), /unsupported/);
    assert.throws(() => readRows('content_rules', '{"posts":{"42":{"login":true,"extra":"keep"}}}'), /unsupported/);
});

test('Visual editors reject duplicate and unfinished rules without collapsing sources', () => {
    assert.throws(() => writeRows('custom_mapping', [{ key: 'team', value: 'team' }, { key: 'team', value: 'department' }]), /duplicate/);
    assert.throws(() => writeRows('custom_mapping', [{ key: '', value: 'department' }]), /required/);
    const encoded = writeRows('custom_mapping', [{ key: '__proto__', value: 'opaque' }]);
    assert.equal(JSON.parse(encoded).__proto__, 'opaque');
    assert.equal({}.opaque, undefined);
});
