<?php
/** Independent signature fixtures exercise the public validator. */
function wpsso_signed_hmac(array $claims, $secret, array $header = ['alg' => 'HS256']) {
    $encode = static function ($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
    $input = $encode(json_encode($header)) . '.' . $encode(json_encode($claims));
    $hashes = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];
    return $input . '.' . $encode(hash_hmac($hashes[$header['alg']] ?? 'sha256', $input, $secret, true));
}

function wpsso_jwt_claims(array $override = []) {
    return array_merge(['iss' => 'https://example.org', 'aud' => 'test-client', 'sub' => 'immutable-123', 'iat' => time(), 'exp' => time() + 300, 'nonce' => 'browser-nonce'], $override);
}

function wpsso_jwt_app(array $override = []) {
    return array_merge(['issuer' => 'https://example.org', 'client_id' => 'test-client', 'jwt_secret' => str_repeat('K', 64), 'algorithms' => ['HS256'], 'jwks_url' => 'https://example.org/jwks'], $override);
}

wpsso_test('JWT validates HS256, HS384, HS512 with a pinned symmetric key', function () {
    foreach (['HS256', 'HS384', 'HS512'] as $alg) {
        $app = wpsso_jwt_app(['algorithms' => [$alg]]);
        $token = wpsso_signed_hmac(wpsso_jwt_claims(), $app['jwt_secret'], ['alg' => $alg]);
        $result = \WPSSO\Jwt::validate($token, $app, ['nonce' => 'browser-nonce']);
        wpsso_assert(is_array($result) && $result['sub'] === 'immutable-123', $alg . ' rejected a valid signature');
    }
});

wpsso_test('JWT rejects tampering, unpinned algorithms, key references and weak keys', function () {
    $app = wpsso_jwt_app();
    $claims = wpsso_jwt_claims();
    $token = wpsso_signed_hmac($claims, 'different-key-with-at-least-thirty-two-bytes');
    wpsso_error(\WPSSO\Jwt::validate($token, $app), 'wpsso_jwt_signature');
    $token = wpsso_signed_hmac($claims, $app['jwt_secret'], ['alg' => 'HS512']);
    wpsso_error(\WPSSO\Jwt::validate($token, $app), 'wpsso_jwt_algorithm');
    $token = wpsso_signed_hmac($claims, $app['jwt_secret'], ['alg' => 'HS256', 'jku' => 'https://evil.example/jwks']);
    wpsso_error(\WPSSO\Jwt::validate($token, $app), 'wpsso_jwt_header');
    $token = wpsso_signed_hmac($claims, 'short');
    wpsso_error(\WPSSO\Jwt::validate($token, wpsso_jwt_app(['jwt_secret' => 'short'])), 'wpsso_jwt_key');
    wpsso_error(\WPSSO\Jwt::validate('eyJhbGciOiJub25lIn0.eyJzdWIiOiJ4In0.', $app), 'wpsso_jwt_format');
});

wpsso_test('JWT binds issuer, audience, authorized party, time, subject and nonce', function () {
    $app = wpsso_jwt_app();
    $cases = [
        [['iss' => 'https://evil.example'], 'issuer'],
        [['aud' => 'different-client'], 'audience'],
        [['aud' => ['test-client', 'other']], 'audience'],
        [['azp' => 'other'], 'audience'],
        [['exp' => time() - 120], 'time'],
        [['iat' => time() + 120], 'time'],
        [['nbf' => time() + 120], 'time'],
        [['exp' => (string) (time() + 300)], 'time'],
        [['sub' => ''], 'subject'],
        [['nonce' => 'another-browser'], 'nonce'],
    ];
    foreach ($cases as [$override, $code]) {
        $token = wpsso_signed_hmac(wpsso_jwt_claims($override), $app['jwt_secret']);
        wpsso_error(\WPSSO\Jwt::validate($token, $app, ['nonce' => 'browser-nonce']), 'wpsso_jwt_' . $code);
    }
});

wpsso_test('Implicit JWT requires the correct access-token hash', function () {
    $app = wpsso_jwt_app();
    $access = 'opaque-access-token';
    $expected = rtrim(strtr(base64_encode(substr(hash('sha256', $access, true), 0, 16)), '+/', '-_'), '=');
    $context = ['access_token' => $access, 'require_at_hash' => true];
    $token = wpsso_signed_hmac(wpsso_jwt_claims(), $app['jwt_secret']);
    wpsso_error(\WPSSO\Jwt::validate($token, $app, $context), 'wpsso_jwt_at_hash');
    $token = wpsso_signed_hmac(wpsso_jwt_claims(['at_hash' => $expected]), $app['jwt_secret']);
    wpsso_assert(is_array(\WPSSO\Jwt::validate($token, $app, $context)), 'Correct token hash rejected');
    $context['access_token'] = 'injected-token';
    wpsso_error(\WPSSO\Jwt::validate($token, $app, $context), 'wpsso_jwt_at_hash');
});

wpsso_test('Back-channel logout uses a distinct signed event claim profile', function () {
    $app = wpsso_jwt_app();
    $claims = ['iss' => $app['issuer'], 'aud' => $app['client_id'], 'iat' => time(), 'sid' => 'session-id', 'jti' => 'logout-unique', 'events' => ['http://schemas.openid.net/event/backchannel-logout' => new stdClass()]];
    $token = wpsso_signed_hmac($claims, $app['jwt_secret']);
    wpsso_assert(is_array(\WPSSO\Jwt::validate($token, $app, ['profile' => 'logout'])), 'Valid sid-only logout without exp rejected');
    wpsso_error(\WPSSO\Jwt::validate($token, $app), 'wpsso_jwt_time');
    foreach ([['nonce' => 'forbidden'], ['iat' => time() - 601], ['jti' => ''], ['events' => []], ['sid' => ''], ['exp' => time() - 120], ['exp' => null], ['sub' => null]] as $override) {
        $result = \WPSSO\Jwt::validate(wpsso_signed_hmac(array_merge($claims, $override), $app['jwt_secret']), $app, ['profile' => 'logout']);
        wpsso_assert(is_wp_error($result), 'Invalid logout claim profile accepted');
    }
    // An ordinary ID token must never be accepted as a logout event.
    wpsso_error(\WPSSO\Jwt::validate(wpsso_signed_hmac(wpsso_jwt_claims(), $app['jwt_secret']), $app, ['profile' => 'logout']), 'wpsso_jwt_logout');
});

wpsso_test('RSA JWKS validates RS256, RS384, RS512 and rejects wrong signatures', function () {
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    wpsso_assert($private !== false, 'OpenSSL key generation failed');
    $details = openssl_pkey_get_details($private);
    $encode = static function ($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
    $url = 'https://example.org/jwks?test=' . wp_generate_password(12, false);
    $jwk = ['kty' => 'RSA', 'kid' => 'rsa-test', 'use' => 'sig', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])];
    $http = static function ($pre, $args, $request) use ($url, $jwk) {
        if ($request !== $url) { return $pre; }
        return ['response' => ['code' => 200], 'body' => json_encode(['keys' => [$jwk]]), 'headers' => []];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        foreach (['RS256' => 'sha256', 'RS384' => 'sha384', 'RS512' => 'sha512'] as $alg => $digest) {
            $input = $encode(json_encode(['alg' => $alg, 'kid' => 'rsa-test'])) . '.' . $encode(json_encode(wpsso_jwt_claims()));
            openssl_sign($input, $signature, $private, $digest);
            $token = $input . '.' . $encode($signature);
            $app = wpsso_jwt_app(['algorithms' => [$alg], 'jwks_url' => $url]);
            wpsso_assert(is_array(\WPSSO\Jwt::validate($token, $app)), $alg . ' JWKS verification failed');
            $signature[0] = chr(ord($signature[0]) ^ 1);
            wpsso_error(\WPSSO\Jwt::validate($input . '.' . $encode($signature), $app), 'wpsso_jwt_signature');
        }
        $input = $encode(json_encode(['alg' => 'RS256', 'kid' => 'unknown'])) . '.' . $encode(json_encode(wpsso_jwt_claims()));
        openssl_sign($input, $signature, $private, 'sha256');
        wpsso_error(\WPSSO\Jwt::validate($input . '.' . $encode($signature), wpsso_jwt_app(['algorithms' => ['RS256'], 'jwks_url' => $url])), 'wpsso_jwt_key');
    } finally {
        remove_filter('pre_http_request', $http, 10);
        delete_transient('wpsso_jwks_' . hash('sha256', $url));
        delete_transient('wpsso_jwks_' . hash('sha256', $url) . '_retry');
    }
});
