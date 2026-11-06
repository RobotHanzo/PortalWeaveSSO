<?php
namespace WPSSO;

defined('ABSPATH') || exit;

/** Pinned-algorithm JWS verification. Key selection never trusts token URLs. */
final class Jwt {
    private const ALGORITHMS = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512', 'RS256' => 'sha256', 'RS384' => 'sha384', 'RS512' => 'sha512'];

    public static function validate($jwt, array $app, array $context = []) {
        $logout = ($context['profile'] ?? '') === 'logout';
        if (!is_string($jwt) || strlen($jwt) > 65536 || count($segments = explode('.', $jwt)) !== 3) {
            return self::error('format', __('The token is not a compact signed JWT.', 'portalweave-sso'));
        }
        [$head, $body, $signature] = array_map([self::class, 'decode'], $segments);
        if ($head === false || $body === false || $signature === false) {
            return self::error('format', __('The JWT encoding is invalid.', 'portalweave-sso'));
        }
        $header = json_decode($head, true, 16);
        $claims = json_decode($body, true, 64);
        if (!is_array($header) || !is_array($claims) || substr(ltrim($head), 0, 1) !== '{' || substr(ltrim($body), 0, 1) !== '{') {
            return self::error('format', __('The JWT header or payload is invalid.', 'portalweave-sso'));
        }
        $algorithm = $header['alg'] ?? '';
        $allowed = $app['algorithms'] ?? ['RS256'];
        if (!is_string($algorithm) || !isset(self::ALGORITHMS[$algorithm]) || !is_array($allowed) || !in_array($algorithm, $allowed, true)) {
            return self::error('algorithm', __('The JWT signing algorithm is not allowed for this provider.', 'portalweave-sso'));
        }
        if (isset($header['crit']) || isset($header['b64']) || isset($header['jku']) || isset($header['x5u']) || isset($header['jwk'])) {
            return self::error('header', __('Unsupported JWT key references or critical headers.', 'portalweave-sso'));
        }
        $input = $segments[0] . '.' . $segments[1];
        if (strncmp($algorithm, 'HS', 2) === 0) {
            $secret = $app['jwt_secret'] ?? '';
            if ($secret === '') {
                $secret = $app['client_secret'] ?? '';
            }
            // Explicit symmetric material only; an RSA public key must never be a MAC key.
            if (!is_string($secret) || strlen($secret) < (int) substr($algorithm, 2) / 8 || strpos($secret, '-----BEGIN') !== false) {
                return self::error('key', __('HMAC JWT verification requires a strong configured symmetric secret.', 'portalweave-sso'));
            }
            if (!hash_equals(hash_hmac(self::ALGORITHMS[$algorithm], $input, $secret, true), $signature)) {
                return self::error('signature', __('The JWT signature is invalid.', 'portalweave-sso'));
            }
        } else {
            if (!function_exists('openssl_verify')) {
                return self::error('openssl', __('The PHP OpenSSL extension is required for RSA verification.', 'portalweave-sso'));
            }
            $key = self::rsa_key($app, $header, $algorithm);
            if (is_wp_error($key)) {
                return $key;
            }
            if (openssl_verify($input, $signature, $key, self::ALGORITHMS[$algorithm]) !== 1) {
                return self::error('signature', __('The JWT signature is invalid.', 'portalweave-sso'));
            }
        }
        $now = time();
        $issuer = $context['issuer'] ?? ($app['issuer'] ?? '');
        $audience = $context['audience'] ?? ($app['client_id'] ?? '');
        if (!is_string($issuer) || $issuer === '' || !isset($claims['iss']) || !is_string($claims['iss']) || !hash_equals($issuer, $claims['iss'])) {
            return self::error('issuer', __('The token issuer does not match the configured issuer.', 'portalweave-sso'));
        }
        $audiences = $claims['aud'] ?? [];
        $audiences = is_string($audiences) ? [$audiences] : $audiences;
        if (!is_string($audience) || $audience === '' || !is_array($audiences) || !array_is_list($audiences) || !$audiences || count(array_filter($audiences, 'is_string')) !== count($audiences) || !in_array($audience, $audiences, true)) {
            return self::error('audience', __('The token audience does not include this client.', 'portalweave-sso'));
        }
        if ((!$logout && count($audiences) > 1 && !isset($claims['azp'])) || (isset($claims['azp']) && (!is_string($claims['azp']) || !hash_equals($audience, $claims['azp'])))) {
            return self::error('audience', __('The token authorized party does not match this client.', 'portalweave-sso'));
        }
        if (!isset($claims['iat']) || !is_int($claims['iat']) || $claims['iat'] > $now + 60 || (!$logout && !isset($claims['exp'])) || (array_key_exists('exp', $claims) && (!is_int($claims['exp']) || $claims['exp'] <= $now - 60 || $claims['exp'] <= $claims['iat']))) {
            return self::error('time', __('The token is expired or its timestamps are invalid.', 'portalweave-sso'));
        }
        if (array_key_exists('nbf', $claims) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 60)) {
            return self::error('time', __('The token is not yet valid.', 'portalweave-sso'));
        }
        if ($logout) {
            if ($claims['iat'] < $now - 600 || array_key_exists('nonce', $claims)) {
                return self::error('logout', __('The logout token is too old or contains a forbidden nonce.', 'portalweave-sso'));
            }
            if (empty($claims['jti']) || !is_string($claims['jti']) || strlen($claims['jti']) > 256 || !is_array($claims['events'] ?? null) || !array_key_exists('http://schemas.openid.net/event/backchannel-logout', $claims['events']) || !is_array($claims['events']['http://schemas.openid.net/event/backchannel-logout'])) {
                return self::error('logout', __('The token is not a valid OIDC back-channel logout event.', 'portalweave-sso'));
            }
            $has_subject = isset($claims['sub']) && is_string($claims['sub']) && $claims['sub'] !== '' && strlen($claims['sub']) <= 1024;
            $has_session = isset($claims['sid']) && is_string($claims['sid']) && $claims['sid'] !== '' && strlen($claims['sid']) <= 1024;
            if ((!$has_subject && !$has_session) || (array_key_exists('sub', $claims) && !$has_subject) || (array_key_exists('sid', $claims) && !$has_session)) {
                return self::error('logout', __('The logout event requires a valid subject or session identifier.', 'portalweave-sso'));
            }
            return $claims;
        }
        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '' || strlen($claims['sub']) > 1024) {
            return self::error('subject', __('The token has no valid subject.', 'portalweave-sso'));
        }
        if (isset($context['nonce']) && (!is_string($context['nonce']) || !isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($context['nonce'], $claims['nonce']))) {
            return self::error('nonce', __('The token nonce does not match this browser login.', 'portalweave-sso'));
        }
        if (!empty($context['require_at_hash']) && !isset($claims['at_hash'])) {
            return self::error('at_hash', __('The implicit-flow ID token is missing its access-token hash.', 'portalweave-sso'));
        }
        if (isset($claims['at_hash']) && isset($context['access_token'])) {
            if (!is_string($context['access_token'])) {
                return self::error('at_hash', __('The access token is not bound to this ID token.', 'portalweave-sso'));
            }
            $hash = hash(self::ALGORITHMS[$algorithm], $context['access_token'], true);
            $expected = self::encode(substr($hash, 0, strlen($hash) / 2));
            if (!is_string($claims['at_hash']) || !hash_equals($expected, $claims['at_hash'])) {
                return self::error('at_hash', __('The access token is not bound to this ID token.', 'portalweave-sso'));
            }
        }
        return $claims;
    }

    private static function rsa_key(array $app, array $header, $algorithm) {
        $url = $app['jwks_url'] ?? '';
        if (!is_string($url) || $url === '' || strlen($url) > 8192 || (isset($header['kid']) && (!is_string($header['kid']) || strlen($header['kid']) > 256))) {
            return self::error('key', __('Configure a JWKS URL and a valid key identifier.', 'portalweave-sso'));
        }
        $cache = 'wpsso_jwks_' . hash('sha256', $url);
        $keys = get_transient($cache);
        $fresh = false;
        if (!is_array($keys)) {
            $keys = self::fetch_keys($url, $cache);
            $fresh = true;
        }
        if (is_wp_error($keys)) {
            return $keys;
        }
        $matches = self::select_keys($keys, $header, $algorithm);
        if (!$matches && !$fresh) {
            // Allow rotation on an unknown kid, bounded to once per minute per endpoint.
            $rotation = $cache . '_retry';
            if (!get_transient($rotation)) {
                set_transient($rotation, 1, 60);
                $keys = self::fetch_keys($url, $cache);
                if (is_wp_error($keys)) {
                    return $keys;
                }
                $matches = self::select_keys($keys, $header, $algorithm);
            }
        }
        if (count($matches) !== 1) {
            return self::error('key', __('A unique compatible signing key was not found in the provider JWKS.', 'portalweave-sso'));
        }
        $jwk = reset($matches);
        $n = self::decode($jwk['n'] ?? '');
        $e = self::decode($jwk['e'] ?? '');
        if ($n === false || $e === false || strlen(ltrim($n, "\0")) < 256 || strlen($n) > 1024 || strlen($e) < 1 || strlen($e) > 8) {
            return self::error('key', __('The provider RSA key is invalid or smaller than 2048 bits.', 'portalweave-sso'));
        }
        $rsa = self::der(0x30, self::der(0x02, self::unsigned($n)) . self::der(0x02, self::unsigned($e)));
        $oid = hex2bin('300d06092a864886f70d0101010500');
        $spki = self::der(0x30, $oid . self::der(0x03, "\0" . $rsa));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function fetch_keys($url, $cache) {
        $data = Http::json($url);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!isset($data['keys']) || !is_array($data['keys']) || count($data['keys']) > 100) {
            return self::error('jwks', __('The JWKS document does not contain a valid key set.', 'portalweave-sso'));
        }
        set_transient($cache, $data['keys'], 3600);
        return $data['keys'];
    }

    private static function select_keys(array $keys, array $header, $algorithm) {
        return array_values(array_filter($keys, static function ($key) use ($header, $algorithm) {
            return is_array($key) && ($key['kty'] ?? '') === 'RSA'
                && (!isset($header['kid']) || (isset($key['kid']) && $key['kid'] === $header['kid']))
                && (!isset($key['alg']) || $key['alg'] === $algorithm)
                && (!isset($key['use']) || $key['use'] === 'sig')
                && (!isset($key['key_ops']) || (is_array($key['key_ops']) && in_array('verify', $key['key_ops'], true)));
        }));
    }

    public static function encode($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode($value) {
        if (!is_string($value) || $value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded !== false && self::encode($decoded) === $value ? $decoded : false;
    }

    private static function unsigned($bytes) {
        $bytes = ltrim($bytes, "\0");
        return ($bytes !== '' && (ord($bytes[0]) & 0x80)) ? "\0" . $bytes : $bytes;
    }

    private static function der($tag, $bytes) {
        $length = strlen($bytes);
        if ($length < 128) {
            return chr($tag) . chr($length) . $bytes;
        }
        $encoded = ltrim(pack('N', $length), "\0");
        return chr($tag) . chr(0x80 | strlen($encoded)) . $encoded . $bytes;
    }

    private static function error($code, $message) {
        return new \WP_Error('wpsso_jwt_' . $code, $message);
    }
}
