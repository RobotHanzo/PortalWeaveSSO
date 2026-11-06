<?php
/** Proxy attribution is tested at the public resolver and real audit persistence boundaries. */
defined('ABSPATH') || exit;

portalweave_sso_test('Client IP defaults preserve the validated peer and ignore untrusted forwarded headers', function (): void {
    foreach ([
        ['missing peer', [], ''],
        ['invalid peer', ['REMOTE_ADDR' => 'not-an-address'], ''],
        ['array peer', ['REMOTE_ADDR' => ['198.51.100.17']], ''],
        ['integer peer', ['REMOTE_ADDR' => 123], ''],
        ['peer with port', ['REMOTE_ADDR' => '198.51.100.17:443'], ''],
        ['peer with zone', ['REMOTE_ADDR' => 'fe80::1%eth0'], ''],
        ['IPv4 peer', ['REMOTE_ADDR' => '198.51.100.17'], '198.51.100.17'],
        ['canonical IPv6 peer', ['REMOTE_ADDR' => '2001:0DB8:0000:0000:0000:0000:0000:0017'], '2001:db8::17'],
        ['public peer spoof', ['REMOTE_ADDR' => '198.51.100.17', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.10'], '198.51.100.17'],
        ['no implicit local trust', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], '127.0.0.1'],
        ['no implicit private trust', ['REMOTE_ADDR' => '10.23.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], '10.23.0.5'],
    ] as [$label, $server, $expected]) {
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server) === $expected, $label . ' did not preserve the validated peer');
    }
});

portalweave_sso_test('Client IP X-Forwarded-For stops at the first untrusted hop and never skips malformed consumed hops', function (): void {
    $trusted = ['10.23.0.5/32', '10.23.0.6', '2001:db8:abcd::/48'];
    foreach ([
        ['one proxy', '10.23.0.5', '198.51.100.17', '198.51.100.17'],
        ['trusted proxy chain', '10.23.0.5', '198.51.100.17, 10.23.0.6', '198.51.100.17'],
        ['mixed-family proxy chain', '10.23.0.5', '2001:db8::17, 2001:db8:abcd::5, 10.23.0.6', '2001:db8::17'],
        ['nearest untrusted intermediary', '10.23.0.5', '203.0.113.9, 198.51.100.17, 10.23.0.6', '198.51.100.17'],
        ['malformed prefix outside trust boundary', '10.23.0.5', 'not-an-address, 198.51.100.17', '198.51.100.17'],
        ['empty prefix outside trust boundary', '10.23.0.5', ', 198.51.100.17', '198.51.100.17'],
        ['malformed consumed hop', '10.23.0.5', '198.51.100.17, not-an-address', '10.23.0.5'],
        ['malformed consumed hop after trusted proxy', '10.23.0.5', '198.51.100.17, not-an-address, 10.23.0.6', '10.23.0.5'],
        ['empty consumed hop', '10.23.0.5', '198.51.100.17,', '10.23.0.5'],
        ['all trusted hops', '10.23.0.5', '10.23.0.6', '10.23.0.6'],
        ['untrusted immediate peer', '192.0.2.5', '198.51.100.17, 10.23.0.6', '192.0.2.5'],
        ['IPv6 immediate peer', '2001:0DB8:ABCD:0001::5', '198.51.100.17', '198.51.100.17'],
        ['header optional whitespace', '10.23.0.5', "\t 198.51.100.17 \t", '198.51.100.17'],
    ] as [$label, $peer, $forwarded, $expected]) {
        $actual = \PortalWeaveSSO\ClientIp::resolve(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $forwarded], $trusted);
        portalweave_sso_assert($actual === $expected, $label . ' returned ' . $actual . ' instead of ' . $expected);
    }
});

portalweave_sso_test('Client IP proxy trust validates the complete allowlist and bounds its size', function (): void {
    $server = ['REMOTE_ADDR' => '10.23.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.17'];
    foreach ([
        [], null, false, '10.23.0.5', 5, new stdClass(),
        ['10.23.0.5', 'not-an-address'], ['10.23.0.5', 'proxy.example.org'],
        ['10.23.0.5', ''], ['10.23.0.5', null], ['10.23.0.5', ['10.23.0.6']],
        ['10.23.0.5/0'], ['10.23.0.5/33'], ['10.23.0.5/-1'], ['10.23.0.5/24/32'],
        ['10.23.0.5/'], ['10.23.0.5/abc'], ['::/0', '10.23.0.5'], ['::/129', '10.23.0.5'],
        array_fill(0, 129, '10.23.0.5'),
    ] as $index => $trusted) {
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, $trusted) === '10.23.0.5', 'Invalid proxy configuration ' . $index . ' enabled forwarded trust');
    }
    portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, array_fill(0, 128, '10.23.0.5')) === '198.51.100.17', 'The maximum valid proxy allowlist was rejected');
});

portalweave_sso_test('Client IP CIDR matching handles boundary bits and keeps IPv4 and IPv6 trust separate', function (): void {
    foreach ([
        ['IPv4 exact match', '10.23.0.5', ['10.23.0.5'], true],
        ['IPv4 exact nonmatch', '10.23.0.6', ['10.23.0.5'], false],
        ['IPv4 first address', '10.23.0.0', ['10.23.0.9/25'], true],
        ['IPv4 last address', '10.23.0.127', ['10.23.0.9/25'], true],
        ['IPv4 next subnet', '10.23.0.128', ['10.23.0.9/25'], false],
        ['IPv4 widest accepted prefix', '10.23.0.5', ['0.0.0.0/1'], true],
        ['IPv4 other half', '200.23.0.5', ['0.0.0.0/1'], false],
        ['IPv6 exact alternate notation', '2001:db8:abcd::5', ['2001:0db8:abcd:0000:0000:0000:0000:0005/128'], true],
        ['IPv6 exact nonmatch', '2001:db8:abcd::6', ['2001:db8:abcd::5/128'], false],
        ['IPv6 first address', '2001:db8:abcd:8000::', ['2001:db8:abcd:8000::5/49'], true],
        ['IPv6 last address', '2001:db8:abcd:ffff:ffff:ffff:ffff:ffff', ['2001:db8:abcd:8000::5/49'], true],
        ['IPv6 preceding subnet', '2001:db8:abcd:7fff:ffff:ffff:ffff:ffff', ['2001:db8:abcd:8000::5/49'], false],
        ['IPv6 widest accepted prefix', '2001:db8::5', ['::/1'], true],
        ['IPv6 other half', 'a001:db8::5', ['::/1'], false],
        ['IPv6 cannot trust IPv4', '10.23.0.5', ['::/1'], false],
        ['mapped IPv6 cannot inherit IPv4 trust', '::ffff:10.23.0.5', ['10.23.0.0/24'], false],
        ['explicit mapped IPv6 trust', '::ffff:10.23.0.5', ['::ffff:10.23.0.5/128'], true],
    ] as [$label, $peer, $trusted, $matches]) {
        $actual = \PortalWeaveSSO\ClientIp::resolve(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '198.51.100.17'], $trusted);
        $expected = $matches ? '198.51.100.17' : inet_ntop(inet_pton($peer));
        portalweave_sso_assert($actual === $expected, $label . ' crossed the configured proxy boundary');
    }
});

portalweave_sso_test('Client IP forwarded headers reject malformed values and enforce byte and hop limits', function (): void {
    $peer = '10.23.0.5';
    foreach ([null, false, 123, ['198.51.100.17'], '', ' ', '198.51.100.17:443', '[2001:db8::17]', 'fe80::1%eth0', "198.51.100.17\r\n", 'unknown', '198.51.100.017'] as $index => $forwarded) {
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $forwarded], [$peer]) === $peer, 'Malformed forwarded value ' . $index . ' was accepted');
    }
    portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve(['REMOTE_ADDR' => $peer], [$peer]) === $peer, 'A missing selected header changed the peer');
    $address = '198.51.100.17';
    $maximum_bytes = str_repeat(' ', 4096 - strlen($address)) . $address;
    $maximum_hops = $address . ',' . implode(',', array_fill(0, 31, $peer));
    foreach ([
        ['maximum header bytes', $maximum_bytes, $address],
        ['excess header bytes', ' ' . $maximum_bytes, $peer],
        ['maximum header hops', $maximum_hops, $address],
        ['excess header hops', $maximum_hops . ',' . $peer, $peer],
    ] as [$label, $forwarded, $expected]) {
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $forwarded], [$peer]) === $expected, $label . ' was not enforced');
    }
});

portalweave_sso_test('Client IP header selection is explicit and single-address modes reject chains', function (): void {
    $peer = '10.23.0.5';
    $server = ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '198.51.100.17', 'HTTP_X_REAL_IP' => '198.51.100.18', 'HTTP_CF_CONNECTING_IP' => '198.51.100.19'];
    foreach (['X-Forwarded-For' => '198.51.100.17', 'x-FoRwArDeD-fOr' => '198.51.100.17', 'X-Real-IP' => '198.51.100.18', 'x-real-ip' => '198.51.100.18', 'CF-Connecting-IP' => '198.51.100.19', 'cf-connecting-ip' => '198.51.100.19'] as $header => $expected) {
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, [$peer], $header) === $expected, $header . ' selected another header');
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, ['192.0.2.5'], $header) === $peer, $header . ' accepted an untrusted immediate peer');
    }
    foreach (['', 'Forwarded', 'HTTP_X_FORWARDED_FOR', ['X-Forwarded-For'], null, false, 123] as $index => $header) {
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, [$peer], $header) === $peer, 'Invalid header selection ' . $index . ' enabled forwarded trust');
    }
    foreach (['X-Real-IP' => 'HTTP_X_REAL_IP', 'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP'] as $header => $key) {
        foreach (['198.51.100.17, 10.23.0.6', ['198.51.100.17'], '198.51.100.17:443', 'fe80::1%eth0', '', null] as $index => $value) {
            portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve(array_replace($server, [$key => $value]), [$peer], $header) === $peer, $header . ' accepted invalid single address ' . $index);
        }
        $missing = $server;
        unset($missing[$key]);
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($missing, [$peer], $header) === $peer, $header . ' silently fell back to another header');
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve(array_replace($server, [$key => '2001:0DB8::0017']), [$peer], $header) === '2001:db8::17', $header . ' did not canonicalize IPv6');
    }
});

portalweave_sso_test('Client IP Cloudflare Pseudo IPv4 uses validated connecting IPv6 only in the selected trusted mode', function (): void {
    $peer = '10.23.0.5';
    $base = ['REMOTE_ADDR' => $peer, 'HTTP_CF_CONNECTING_IP' => '240.0.0.17'];
    foreach ([
        ['valid connecting IPv6', ['HTTP_CF_CONNECTING_IPV6' => '2001:0DB8:0000::0017'], '2001:db8::17'],
        ['missing connecting IPv6', [], $peer],
        ['malformed connecting IPv6', ['HTTP_CF_CONNECTING_IPV6' => 'not-an-address'], $peer],
        ['IPv4 secondary', ['HTTP_CF_CONNECTING_IPV6' => '198.51.100.17'], $peer],
        ['array secondary', ['HTTP_CF_CONNECTING_IPV6' => ['2001:db8::17']], $peer],
        ['secondary with zone', ['HTTP_CF_CONNECTING_IPV6' => 'fe80::1%eth0'], $peer],
        ['ordinary IPv4 ignores secondary', ['HTTP_CF_CONNECTING_IP' => '198.51.100.17', 'HTTP_CF_CONNECTING_IPV6' => 'not-an-address'], '198.51.100.17'],
        ['ordinary IPv6 ignores secondary', ['HTTP_CF_CONNECTING_IP' => '2001:db8::17', 'HTTP_CF_CONNECTING_IPV6' => 'not-an-address'], '2001:db8::17'],
        ['lower Pseudo IPv4 boundary', ['HTTP_CF_CONNECTING_IP' => '239.255.255.255', 'HTTP_CF_CONNECTING_IPV6' => '2001:db8::17'], '239.255.255.255'],
        ['upper Pseudo IPv4 boundary', ['HTTP_CF_CONNECTING_IP' => '255.255.255.255', 'HTTP_CF_CONNECTING_IPV6' => '2001:db8::17'], '2001:db8::17'],
        ['unrelated Pseudo IPv4 header', ['HTTP_CF_CONNECTING_IP' => '198.51.100.17', 'HTTP_CF_PSEUDO_IPV4' => '240.0.0.18'], '198.51.100.17'],
    ] as [$label, $override, $expected]) {
        $actual = \PortalWeaveSSO\ClientIp::resolve(array_replace($base, $override), [$peer], 'CF-Connecting-IP');
        portalweave_sso_assert($actual === $expected, $label . ' returned ' . $actual . ' instead of ' . $expected);
    }
    $server = array_replace($base, ['HTTP_CF_CONNECTING_IPV6' => '2001:db8::17', 'HTTP_X_REAL_IP' => '240.0.0.17', 'HTTP_X_FORWARDED_FOR' => '198.51.100.18']);
    portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, [], 'CF-Connecting-IP') === $peer, 'Untrusted Cloudflare headers changed the peer');
    portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, [$peer]) === '198.51.100.18', 'Default mode used Cloudflare headers');
    portalweave_sso_assert(\PortalWeaveSSO\ClientIp::resolve($server, [$peer], 'X-Real-IP') === '240.0.0.17', 'X-Real-IP mode used Cloudflare connecting IPv6');
});

/** Restore request state and delete only uniquely identified rows created by this fixture. */
function portalweave_sso_client_ip_audit_fixture(callable $test): void {
    global $wpdb;
    $server = $_SERVER;
    $settings = get_option('portalweave_sso_settings', []);
    $statuses = [];
    $record = static function () use (&$statuses): array {
        $status = 'client_ip_' . bin2hex(random_bytes(8));
        $statuses[] = $status;
        \PortalWeaveSSO\Audit::record($status);
        return \PortalWeaveSSO\Audit::recent(['status' => $status]);
    };
    try {
        $test($record);
    } finally {
        $_SERVER = $server;
        update_option('portalweave_sso_settings', $settings, false);
        foreach ($statuses as $status) {
            $wpdb->delete($wpdb->prefix . 'portalweave_sso_audit', ['status' => $status], ['%s']);
        }
    }
}

portalweave_sso_test('Audit IP logging keeps forwarded headers disabled by default and accepts an Nginx-restored peer', function (): void {
    portalweave_sso_assert(!defined('PORTALWEAVE_SSO_TRUSTED_PROXIES') && !defined('PORTALWEAVE_SSO_CLIENT_IP_HEADER'), 'Disposable fixture unexpectedly has production proxy constants');
    portalweave_sso_client_ip_audit_fixture(function ($record): void {
        \PortalWeaveSSO\Config::save(['audit_ip' => true]);
        $_SERVER = ['REMOTE_ADDR' => '198.51.100.17', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.10'];
        portalweave_sso_assert(\PortalWeaveSSO\ClientIp::current() === '198.51.100.17', 'Default current resolver changed the Nginx-restored peer');
        $rows = $record();
        portalweave_sso_assert(count($rows) === 1 && $rows[0]['client_ip'] === '198.51.100.17', 'Audit logging trusted forwarded headers without server configuration');
    });
});

// This suite runs last: immutable test-only constants must not change earlier privacy or runtime suites.
define('PORTALWEAVE_SSO_TRUSTED_PROXIES', ['10.23.0.5/32', '2001:db8:abcd::/48']);

portalweave_sso_test('Audit IP logging persists the trusted proxy client and retains privacy opt-in and disabled-audit controls', function (): void {
    portalweave_sso_client_ip_audit_fixture(function ($record): void {
        foreach ([
            ['trusted IPv4 proxy', ['REMOTE_ADDR' => '10.23.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.17'], '198.51.100.17'],
            ['trusted IPv6 proxy', ['REMOTE_ADDR' => '2001:db8:abcd::5', 'HTTP_X_FORWARDED_FOR' => '2001:0DB8::0017'], '2001:db8::17'],
            ['untrusted direct peer', ['REMOTE_ADDR' => '192.0.2.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.17'], '192.0.2.5'],
            ['malformed trusted chain', ['REMOTE_ADDR' => '10.23.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.17, malformed'], '10.23.0.5'],
            ['invalid immediate peer', ['REMOTE_ADDR' => 'not-an-address', 'HTTP_X_FORWARDED_FOR' => '198.51.100.17'], ''],
        ] as [$label, $server, $expected]) {
            $_SERVER = $server;
            \PortalWeaveSSO\Config::save(['audit_ip' => true]);
            $rows = $record();
            portalweave_sso_assert(count($rows) === 1 && $rows[0]['client_ip'] === $expected, $label . ' did not persist the resolved client IP');
        }
        $_SERVER = ['REMOTE_ADDR' => '10.23.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.17'];
        \PortalWeaveSSO\Config::save(['audit_ip' => false]);
        $rows = $record();
        portalweave_sso_assert(count($rows) === 1 && $rows[0]['client_ip'] === '', 'Trusted proxy configuration bypassed IP collection opt-in');
        \PortalWeaveSSO\Config::save(['audit_ip' => true, 'audit_enabled' => false]);
        portalweave_sso_assert($record() === [], 'Disabled audit logging persisted a resolved client IP');
    });
});
