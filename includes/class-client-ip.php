<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Resolve audit attribution only through explicitly trusted, server-configured proxies. */
final class ClientIp
{
    public static function current(): string
    {
        $proxies = defined('PORTALWEAVE_SSO_TRUSTED_PROXIES') ? constant('PORTALWEAVE_SSO_TRUSTED_PROXIES') : [];
        $header = defined('PORTALWEAVE_SSO_CLIENT_IP_HEADER') ? constant('PORTALWEAVE_SSO_CLIENT_IP_HEADER') : 'X-Forwarded-For';
        return self::resolve($_SERVER, $proxies, $header);
    }

    /** Invalid configuration or a malformed consumed header falls back to the validated socket peer. */
    public static function resolve(array $server, $trusted_proxies = [], $header = 'X-Forwarded-For'): string
    {
        $peer = self::address($server['REMOTE_ADDR'] ?? null);
        if ($peer === '') {
            return '';
        }
        $ranges = self::ranges($trusted_proxies);
        if (!$ranges || !self::trusted($peer, $ranges) || !is_string($header)) {
            return $peer;
        }
        $headers = ['x-forwarded-for' => 'HTTP_X_FORWARDED_FOR', 'x-real-ip' => 'HTTP_X_REAL_IP', 'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP'];
        $header = strlen($header) <= 64 ? strtolower($header) : '';
        if (!isset($headers[$header])) {
            return $peer;
        }
        $raw = $server[$headers[$header]] ?? null;
        if (!is_string($raw) || strlen($raw) > 4096) {
            return $peer;
        }
        if ($header === 'x-forwarded-for') {
            $hops = explode(',', $raw);
            if (count($hops) > 32) {
                return $peer;
            }
            $address = $peer;
            for ($index = count($hops) - 1; $index >= 0; $index--) {
                // A caller-controlled prefix cannot override the first untrusted hop.
                if (!self::trusted($address, $ranges)) {
                    return $address;
                }
                $address = self::address(trim($hops[$index], " \t"));
                if ($address === '') {
                    return $peer;
                }
            }
            return $address;
        }
        $address = self::address(trim($raw, " \t"));
        if ($address === '') {
            return $peer;
        }
        if ($header === 'cf-connecting-ip') {
            $packed = inet_pton($address);
            if (strlen($packed) === 4 && (ord($packed[0]) & 0xf0) === 0xf0) {
                // Cloudflare's Pseudo IPv4 overwrite mode preserves the real visitor in this IPv6 header.
                $ipv6 = self::address($server['HTTP_CF_CONNECTING_IPV6'] ?? null);
                return $ipv6 !== '' && strlen(inet_pton($ipv6)) === 16 ? $ipv6 : $peer;
            }
        }
        return $address;
    }

    private static function address($value): string
    {
        if (!is_string($value) || strlen($value) > 45 || !filter_var($value, FILTER_VALIDATE_IP)) {
            return '';
        }
        $packed = inet_pton($value);
        return $packed === false ? '' : (inet_ntop($packed) ?: '');
    }

    private static function ranges($values): array
    {
        if (!is_array($values) || count($values) > 128) {
            return [];
        }
        $ranges = [];
        foreach ($values as $value) {
            if (!is_string($value) || strlen($value) > 49) {
                return [];
            }
            $parts = explode('/', $value);
            $address = self::address($parts[0]);
            if ($address === '' || count($parts) > 2) {
                return [];
            }
            $packed = inet_pton($address);
            $bits = strlen($packed) * 8;
            if (isset($parts[1]) && (!preg_match('/^[1-9][0-9]{0,2}$/D', $parts[1]) || (int) $parts[1] > $bits)) {
                return [];
            }
            $ranges[] = [$packed, isset($parts[1]) ? (int) $parts[1] : $bits];
        }
        return $ranges;
    }

    private static function trusted(string $address, array $ranges): bool
    {
        $packed = inet_pton($address);
        foreach ($ranges as [$network, $prefix]) {
            if (strlen($packed) !== strlen($network)) {
                continue;
            }
            $bytes = intdiv($prefix, 8);
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $remaining = $prefix % 8;
            if (!$remaining || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (0xff << (8 - $remaining))) === 0) {
                return true;
            }
        }
        return false;
    }
}
