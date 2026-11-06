<?php
/** Regression checks for protocol input and mapped personal-data ownership. */
portalweave_sso_test('Configured verification values reject structured provider claims', function () {
    $app = portalweave_sso_app(['verified_value' => 'approved']);
    foreach ([['approved'], ['status' => 'approved'], new stdClass(), null] as $value) {
        portalweave_sso_assert(\PortalWeaveSSO\Accounts::verified($app, ['email_verified' => $value]) === false, 'Structured claim was accepted as verified');
    }
    portalweave_sso_assert(\PortalWeaveSSO\Accounts::verified($app, ['email_verified' => 'approved']) === true, 'Configured scalar verification was rejected');
});

portalweave_sso_test('JWT rejects structured key and binding inputs without PHP warnings', function () {
    $app = portalweave_sso_jwt_app();
    $token = portalweave_sso_signed_hmac(portalweave_sso_jwt_claims(), $app['jwt_secret']);
    portalweave_sso_error(\PortalWeaveSSO\Jwt::validate($token, array_replace($app, ['jwt_secret' => ['unexpected']])), 'portalweave_sso_jwt_key');
    portalweave_sso_error(\PortalWeaveSSO\Jwt::validate($token, $app, ['nonce' => ['browser-nonce']]), 'portalweave_sso_jwt_nonce');
    $token = portalweave_sso_signed_hmac(portalweave_sso_jwt_claims(['at_hash' => 'bound']), $app['jwt_secret']);
    portalweave_sso_error(\PortalWeaveSSO\Jwt::validate($token, $app, ['access_token' => ['unexpected']]), 'portalweave_sso_jwt_at_hash');
    $rsa = portalweave_sso_signed_hmac(portalweave_sso_jwt_claims(), $app['jwt_secret'], ['alg' => 'RS256']);
    portalweave_sso_error(\PortalWeaveSSO\Jwt::validate($rsa, array_replace($app, ['algorithms' => ['RS256'], 'jwks_url' => ['unexpected']])), 'portalweave_sso_jwt_key');
});

portalweave_sso_test('HTTP rejects structured URLs and bounds the caller timeout', function () {
    portalweave_sso_error(\PortalWeaveSSO\Http::json(['https://example.org/token']), 'portalweave_sso_endpoint');
    $timeout = null;
    $http = static function ($pre, $args, $url) use (&$timeout) {
        if ($url !== 'https://example.org/bounded-timeout') { return $pre; }
        $timeout = $args['timeout'];
        return ['response' => ['code' => 200], 'body' => '{}', 'headers' => []];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        portalweave_sso_assert(is_array(\PortalWeaveSSO\Http::json('https://example.org/bounded-timeout', ['timeout' => 86400])), 'Safe endpoint did not respond');
        portalweave_sso_assert($timeout === 30, 'Identity HTTP timeout is not bounded');
    } finally { remove_filter('pre_http_request', $http, 10); }
});

portalweave_sso_test('Every provider profile endpoint requires a bounded bearer credential', function () {
    $app = portalweave_sso_app(['userinfo_url' => '', 'groupinfo_url' => 'https://example.org/group-boundary', 'jwt_secret' => str_repeat('K', 64), 'algorithms' => ['HS256']]);
    $claims = portalweave_sso_jwt_claims(['aud' => $app['client_id']]);
    $tokens = ['id_token' => portalweave_sso_signed_hmac($claims, $app['jwt_secret']), 'access_token' => 'valid-access', 'token_type' => 'Bearer'];
    // This parser normally terminates in a redirecting HTTP controller. Exercise
    // its endpoint boundary directly without emitting login cookies or exits.
    $attributes = new ReflectionMethod(\PortalWeaveSSO\Auth::class, 'attributes');
    $requests = 0;
    $http = static function ($pre, $args, $url) use (&$requests) {
        if ($url !== 'https://example.org/group-boundary') { return $pre; }
        $requests++;
        portalweave_sso_assert(($args['headers']['Authorization'] ?? '') === 'Bearer valid-access', 'Bearer credential was altered');
        return ['response' => ['code' => 200], 'body' => '{"groups":["readers"]}', 'headers' => []];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        foreach ([['access_token' => ''], ['access_token' => "bad\r\nInjected: header"], ['access_token' => str_repeat('A', 65537)], ['token_type' => ['Bearer']]] as $override) {
            portalweave_sso_error($attributes->invoke(null, $app, array_replace($tokens, $override), ['nonce' => 'browser-nonce']), 'portalweave_sso_token');
        }
        portalweave_sso_assert($requests === 0, 'Invalid credential reached the group endpoint');
        $result = $attributes->invoke(null, $app, $tokens, ['nonce' => 'browser-nonce']);
        portalweave_sso_assert(is_array($result) && ($result['groups'] ?? []) === ['readers'] && $requests === 1, 'Valid group response was rejected');
    } finally { remove_filter('pre_http_request', $http, 10); }
});

portalweave_sso_test('Malformed token response fields fail without replacing encrypted refresh credentials', function () {
    $id = 0;
    $app = portalweave_sso_app(['id' => 'malformed-response', 'enabled' => true]);
    $response = ['access_token' => 'valid-access', 'token_type' => ['Bearer']];
    $http = static function ($pre, $args, $url) use ($app, &$response) {
        if ($url !== $app['token_url']) { return $pre; }
        return ['response' => ['code' => 200], 'body' => json_encode($response), 'headers' => []];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [$app['id'] => $app], 'default_app' => $app['id']]) === true, 'Token fixture configuration failed');
        $id = wp_insert_user(['user_login' => 'token_boundary_' . wp_generate_password(10, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(16, false) . '@example.org', 'role' => 'subscriber']);
        portalweave_sso_assert(is_int($id) && $id > 0, 'Token fixture user failed');
        wp_set_current_user($id);
        $meta = \PortalWeaveSSO\Auth::token_meta_key($app['id']);
        $encrypted = \PortalWeaveSSO\Secrets::encrypt('{"refresh_token":"previous-secret","_subject":"subject","_nonce":"nonce"}');
        update_user_meta($id, $meta, $encrypted);
        foreach ([['token_type' => ['Bearer']], ['refresh_token' => ['invalid']], ['id_token' => ['invalid']], ['access_token' => "unsafe\0header"]] as $override) {
            $response = array_replace(['access_token' => 'valid-access', 'token_type' => 'Bearer'], $override);
            portalweave_sso_error(\PortalWeaveSSO\Auth::refresh($id, $app['id']), 'portalweave_sso_token');
            portalweave_sso_assert(get_user_meta($id, $meta, true) === $encrypted, 'Malformed response replaced stored credentials');
        }
    } finally {
        remove_filter('pre_http_request', $http, 10);
        if (is_int($id) && $id > 0) { wp_delete_user($id); }
    }
});

portalweave_sso_test('An expired account worker cannot release a newer lock owner', function () {
    $id = 0;
    $app = portalweave_sso_app();
    $subject = 'lock-owner-' . wp_generate_password(20, false);
    $scope = get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
    $identity_key = '_portalweave_sso_identity_' . hash('sha256', $scope);
    $lock_key = '_portalweave_sso_lock_' . hash('sha256', $identity_key . hash('sha256', $subject));
    $new_owner = (time() + 120) . ':newer-worker';
    $replace_owner = static function ($row) use ($lock_key, $new_owner) {
        update_option($lock_key, $new_owner, false);
        return $row;
    };
    add_filter('wp_pre_insert_user_data', $replace_owner);
    try {
        $user = \PortalWeaveSSO\Accounts::resolve($app, ['sub' => $subject, 'email' => wp_generate_password(16, false) . '@example.org', 'email_verified' => true]);
        portalweave_sso_assert($user instanceof \WP_User, 'Account fixture could not be created');
        $id = $user->ID;
        portalweave_sso_assert(get_option($lock_key) === $new_owner, 'Older worker removed the newer lock owner');
    } finally {
        remove_filter('wp_pre_insert_user_data', $replace_owner);
        delete_option($lock_key);
        if ($id) { wp_delete_user($id); }
    }
});

portalweave_sso_test('Custom profile mappings track the union of plugin-written keys for privacy tools', function () {
    $id = 0;
    $subject = 'privacy-' . wp_generate_password(20, false);
    $attributes = ['sub' => $subject, 'email' => wp_generate_password(16, false) . '@example.org', 'email_verified' => true, 'department' => 'Engineering', 'location' => 'Singapore'];
    try {
        $app = portalweave_sso_app(['custom_mapping' => ['department' => 'department']]);
        $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($user instanceof \WP_User, 'Mapped account could not be created');
        $id = $user->ID;
        $key = \PortalWeaveSSO\Accounts::mapped_keys_meta_key();
        portalweave_sso_assert(get_user_meta($id, $key, true) === ['department'], 'Mapped key was not recorded');
        $scope = get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
        portalweave_sso_assert(get_user_meta($id, \PortalWeaveSSO\Accounts::identity_keys_meta_key(), true) === ['_portalweave_sso_identity_' . hash('sha256', $scope), '_portalweave_sso_sid_' . hash('sha256', $scope)], 'Identity key ownership was not recorded');
        $app['custom_mapping'] = ['location' => 'location'];
        $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($user instanceof \WP_User, 'Mapped account could not be updated');
        portalweave_sso_assert(get_user_meta($id, $key, true) === ['department', 'location'], 'Past mapping ownership was lost');
        portalweave_sso_assert(get_user_meta($id, 'department', true) === 'Engineering' && get_user_meta($id, 'location', true) === 'Singapore', 'Mapped values were not saved');
    } finally {
        if ($id) { wp_delete_user($id); }
    }
});
