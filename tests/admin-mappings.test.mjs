import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { readRows, writeRows } = createRequire(import.meta.url)('../assets/admin-mappings.js');
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

test('Visual content rules retain post/category scope and explicit access choices', () => {
    const value = { posts: { 42: { login: true, roles: ['subscriber'], groups: ['faculty', 'editors'] } }, categories: { 8: { login: false, roles: [], groups: [] } } };
    assert.deepEqual(convert('content_rules', value), value);
    const rows = readRows('content_rules', JSON.stringify(value));
    assert.throws(() => writeRows('content_rules', [rows[0], rows[0]]), /duplicate/);
    assert.throws(() => writeRows('content_rules', [{ ...rows[0], id: '-1' }]), /required/);
    assert.throws(() => writeRows('content_rules', [{ ...rows[0], id: '1.5' }]), /required/);
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
