<?php
/** Regression checks for protocol input and mapped personal-data ownership. */
wpsso_test('Configured verification values reject structured provider claims', function () {
    $app = wpsso_app(['verified_value' => 'approved']);
    foreach ([['approved'], ['status' => 'approved'], new stdClass(), null] as $value) {
        wpsso_assert(\WPSSO\Accounts::verified($app, ['email_verified' => $value]) === false, 'Structured claim was accepted as verified');
    }
    wpsso_assert(\WPSSO\Accounts::verified($app, ['email_verified' => 'approved']) === true, 'Configured scalar verification was rejected');
});

wpsso_test('JWT rejects structured key and binding inputs without PHP warnings', function () {
    $app = wpsso_jwt_app();
    $token = wpsso_signed_hmac(wpsso_jwt_claims(), $app['jwt_secret']);
    wpsso_error(\WPSSO\Jwt::validate($token, array_replace($app, ['jwt_secret' => ['unexpected']])), 'wpsso_jwt_key');
    wpsso_error(\WPSSO\Jwt::validate($token, $app, ['nonce' => ['browser-nonce']]), 'wpsso_jwt_nonce');
    $token = wpsso_signed_hmac(wpsso_jwt_claims(['at_hash' => 'bound']), $app['jwt_secret']);
    wpsso_error(\WPSSO\Jwt::validate($token, $app, ['access_token' => ['unexpected']]), 'wpsso_jwt_at_hash');
    $rsa = wpsso_signed_hmac(wpsso_jwt_claims(), $app['jwt_secret'], ['alg' => 'RS256']);
    wpsso_error(\WPSSO\Jwt::validate($rsa, array_replace($app, ['algorithms' => ['RS256'], 'jwks_url' => ['unexpected']])), 'wpsso_jwt_key');
});

wpsso_test('HTTP rejects structured URLs and bounds the caller timeout', function () {
    wpsso_error(\WPSSO\Http::json(['https://example.org/token']), 'wpsso_endpoint');
    $timeout = null;
    $http = static function ($pre, $args, $url) use (&$timeout) {
        if ($url !== 'https://example.org/bounded-timeout') { return $pre; }
        $timeout = $args['timeout'];
        return ['response' => ['code' => 200], 'body' => '{}', 'headers' => []];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        wpsso_assert(is_array(\WPSSO\Http::json('https://example.org/bounded-timeout', ['timeout' => 86400])), 'Safe endpoint did not respond');
        wpsso_assert($timeout === 30, 'Identity HTTP timeout is not bounded');
    } finally { remove_filter('pre_http_request', $http, 10); }
});

wpsso_test('Every provider profile endpoint requires a bounded bearer credential', function () {
    $app = wpsso_app(['userinfo_url' => '', 'groupinfo_url' => 'https://example.org/group-boundary', 'jwt_secret' => str_repeat('K', 64), 'algorithms' => ['HS256']]);
    $claims = wpsso_jwt_claims(['aud' => $app['client_id']]);
    $tokens = ['id_token' => wpsso_signed_hmac($claims, $app['jwt_secret']), 'access_token' => 'valid-access', 'token_type' => 'Bearer'];
    // This parser normally terminates in a redirecting HTTP controller. Exercise
    // its endpoint boundary directly without emitting login cookies or exits.
    $attributes = new ReflectionMethod(\WPSSO\Auth::class, 'attributes');
    $requests = 0;
    $http = static function ($pre, $args, $url) use (&$requests) {
        if ($url !== 'https://example.org/group-boundary') { return $pre; }
        $requests++;
        wpsso_assert(($args['headers']['Authorization'] ?? '') === 'Bearer valid-access', 'Bearer credential was altered');
        return ['response' => ['code' => 200], 'body' => '{"groups":["readers"]}', 'headers' => []];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        foreach ([['access_token' => ''], ['access_token' => "bad\r\nInjected: header"], ['access_token' => str_repeat('A', 65537)], ['token_type' => ['Bearer']]] as $override) {
            wpsso_error($attributes->invoke(null, $app, array_replace($tokens, $override), ['nonce' => 'browser-nonce']), 'wpsso_token');
        }
        wpsso_assert($requests === 0, 'Invalid credential reached the group endpoint');
        $result = $attributes->invoke(null, $app, $tokens, ['nonce' => 'browser-nonce']);
        wpsso_assert(is_array($result) && ($result['groups'] ?? []) === ['readers'] && $requests === 1, 'Valid group response was rejected');
    } finally { remove_filter('pre_http_request', $http, 10); }
});

wpsso_test('An expired account worker cannot release a newer lock owner', function () {
    $id = 0;
    $app = wpsso_app();
    $subject = 'lock-owner-' . wp_generate_password(20, false);
    $scope = get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
    $identity_key = '_wpsso_identity_' . hash('sha256', $scope);
    $lock_key = '_wpsso_lock_' . hash('sha256', $identity_key . hash('sha256', $subject));
    $new_owner = (time() + 120) . ':newer-worker';
    $replace_owner = static function ($row) use ($lock_key, $new_owner) {
        update_option($lock_key, $new_owner, false);
        return $row;
    };
    add_filter('wp_pre_insert_user_data', $replace_owner);
    try {
        $user = \WPSSO\Accounts::resolve($app, ['sub' => $subject, 'email' => wp_generate_password(16, false) . '@example.org', 'email_verified' => true]);
        wpsso_assert($user instanceof \WP_User, 'Account fixture could not be created');
        $id = $user->ID;
        wpsso_assert(get_option($lock_key) === $new_owner, 'Older worker removed the newer lock owner');
    } finally {
        remove_filter('wp_pre_insert_user_data', $replace_owner);
        delete_option($lock_key);
        if ($id) { wp_delete_user($id); }
    }
});

wpsso_test('Custom profile mappings track the union of plugin-written keys for privacy tools', function () {
    $id = 0;
    $subject = 'privacy-' . wp_generate_password(20, false);
    $attributes = ['sub' => $subject, 'email' => wp_generate_password(16, false) . '@example.org', 'email_verified' => true, 'department' => 'Engineering', 'location' => 'Singapore'];
    try {
        $app = wpsso_app(['custom_mapping' => ['department' => 'department']]);
        $user = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($user instanceof \WP_User, 'Mapped account could not be created');
        $id = $user->ID;
        $key = \WPSSO\Accounts::mapped_keys_meta_key();
        wpsso_assert(get_user_meta($id, $key, true) === ['department'], 'Mapped key was not recorded');
        $scope = get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
        wpsso_assert(get_user_meta($id, \WPSSO\Accounts::identity_keys_meta_key(), true) === ['_wpsso_identity_' . hash('sha256', $scope), '_wpsso_sid_' . hash('sha256', $scope)], 'Identity key ownership was not recorded');
        $app['custom_mapping'] = ['location' => 'location'];
        $user = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($user instanceof \WP_User, 'Mapped account could not be updated');
        wpsso_assert(get_user_meta($id, $key, true) === ['department', 'location'], 'Past mapping ownership was lost');
        wpsso_assert(get_user_meta($id, 'department', true) === 'Engineering' && get_user_meta($id, 'location', true) === 'Singapore', 'Mapped values were not saved');
    } finally {
        if ($id) { wp_delete_user($id); }
    }
});
