<?php
portalweave_sso_test('OAuth state is browser-bound, purpose-bound and consumed once', function () {
    $state = \PortalWeaveSSO\Auth::create_flow(['kind' => 'login', 'app' => 'fixture', 'redirect' => home_url('/members/')], 'configured-extra-state');
    portalweave_sso_assert(is_string($state), 'Flow could not be created');
    $key = 'portalweave_sso_state_' . hash('sha256', $state);
    $name = '__Host-portalweave_sso_' . substr(hash('sha256', $state), 0, 24);
    $flow = get_option($key);
    // Deliberately substitute a known browser secret into persisted flow. The
    // external cookie cannot be read from PHP CLI response headers.
    $flow['browser'] = hash('sha256', 'known-browser-secret');
    update_option($key, $flow, false);
    $_COOKIE[$name] = 'another-browser';
    portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow($state, 'login'), 'portalweave_sso_state');
    $_COOKIE[$name] = 'known-browser-secret';
    portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow($state, 'logout'), 'portalweave_sso_state');
    $result = \PortalWeaveSSO\Auth::consume_flow($state, 'login');
    portalweave_sso_assert(is_array($result) && $result['redirect'] === home_url('/members/'), 'Original redirect not retained');
    portalweave_sso_assert(get_option($key) === false, 'State not deleted atomically');
    portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow($state, 'login'), 'portalweave_sso_state');
    unset($_COOKIE[$name]);
});

portalweave_sso_test('Expired state and mismatched multisite state cannot authenticate', function () {
    foreach (['expired', 'site'] as $case) {
        $state = \PortalWeaveSSO\Auth::create_flow(['kind' => 'login', 'app' => 'fixture']);
        portalweave_sso_assert(is_string($state), 'Flow creation failed');
        $key = 'portalweave_sso_state_' . hash('sha256', $state);
        $name = '__Host-portalweave_sso_' . substr(hash('sha256', $state), 0, 24);
        $flow = get_option($key);
        $flow['browser'] = hash('sha256', 'browser');
        if ($case === 'expired') { $flow['expires'] = time() - 1; }
        else { $flow['blog_id'] = get_current_blog_id() + 1; }
        update_option($key, $flow, false);
        $_COOKIE[$name] = 'browser';
        portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow($state, 'login'), 'portalweave_sso_state');
        delete_option($key);
        unset($_COOKIE[$name]);
    }
    portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow('attacker-selected-state', 'login'), 'portalweave_sso_state');
});

portalweave_sso_test('Test configuration URLs carry a fresh provider-specific admin nonce', function () {
    $url = \PortalWeaveSSO\Auth::login_url('test-provider', home_url('/origin/'), true);
    parse_str(wp_parse_url($url, PHP_URL_QUERY), $params);
    portalweave_sso_assert(($params['test'] ?? '') === '1', 'Test flag missing');
    portalweave_sso_assert(wp_verify_nonce($params['_wpnonce'] ?? '', 'portalweave_sso_test_test-provider') !== false, 'Test nonce invalid');
    portalweave_sso_assert(($params['redirect_to'] ?? '') === home_url('/origin/'), 'Origin navigation lost');
});

portalweave_sso_test('Identity HTTP rejects insecure URLs and never follows redirects', function () {
    portalweave_sso_error(\PortalWeaveSSO\Http::json('http://example.org/token'), 'portalweave_sso_endpoint');
    portalweave_sso_error(\PortalWeaveSSO\Http::json('https://user:secret@example.org/token'), 'portalweave_sso_endpoint');
    portalweave_sso_error(\PortalWeaveSSO\Http::json('https://example.org/token#fragment'), 'portalweave_sso_endpoint');
    $seen = [];
    $http = static function ($pre, $args, $url) use (&$seen) {
        if ($url !== 'https://example.org/portalweave-sso-transport-test') { return $pre; }
        $seen = $args;
        return ['response' => ['code' => 302], 'body' => '{"access_token":"never-expose-me"}', 'headers' => ['location' => 'https://evil.example/']];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        $result = \PortalWeaveSSO\Http::json('https://example.org/portalweave-sso-transport-test', ['redirection' => 5, 'sslverify' => false]);
        portalweave_sso_error($result, 'portalweave_sso_http_status');
        portalweave_sso_assert($seen['redirection'] === 0 && $seen['sslverify'] === true && $seen['reject_unsafe_urls'] === true, 'Safe transport flags were overridden');
        portalweave_sso_assert(strpos($result->get_error_message(), 'never-expose-me') === false, 'Token leaked in error');
    } finally { remove_filter('pre_http_request', $http, 10); }
});

portalweave_sso_test('Signed back-channel logout revokes only matching app identities and rejects replay', function () {
    $old = get_option('portalweave_sso_settings', []);
    $first = 0;
    $second = 0;
    $jti = 'logout-' . wp_generate_password(20, false);
    $subject = 'backchannel-subject';
    $app = array_replace(\PortalWeaveSSO\Config::app_defaults(), [
        'id' => 'backchannel-test', 'name' => 'Back-channel test', 'enabled' => true,
        'client_id' => 'test-client', 'issuer' => 'https://example.org',
        'authorize_url' => 'https://example.org/authorize', 'token_url' => 'https://example.org/token',
        'jwt_secret' => str_repeat('K', 64), 'algorithms' => ['HS256'], 'backchannel_logout' => true,
    ]);
    $encode = static function ($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
    $sign = static function ($claims) use ($app, $encode) {
        $input = $encode(json_encode(['alg' => 'HS256'])) . '.' . $encode(json_encode($claims));
        return $input . '.' . $encode(hash_hmac('sha256', $input, $app['jwt_secret'], true));
    };
    try {
        $saved = \PortalWeaveSSO\Config::save(['apps' => [$app['id'] => $app], 'default_app' => $app['id']]);
        portalweave_sso_assert($saved === true, 'Unable to save back-channel fixture: ' . (is_wp_error($saved) ? $saved->get_error_message() : 'unknown error'));
        $first = wp_insert_user(['user_login' => 'bc_' . wp_generate_password(12, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12, false) . '@example.org', 'role' => 'subscriber']);
        $second = wp_insert_user(['user_login' => 'bc_' . wp_generate_password(12, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12, false) . '@example.org', 'role' => 'subscriber']);
        portalweave_sso_assert(is_int($first) && is_int($second), 'Unable to create back-channel users');
        $scope = get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
        $scope_hash = hash('sha256', $scope);
        update_user_meta($first, '_portalweave_sso_identity_' . $scope_hash, hash('sha256', $subject));
        // Same immutable subject on a separate app must not receive this logout.
        update_user_meta($second, '_portalweave_sso_identity_' . hash('sha256', get_current_blog_id() . '|different-app|' . $app['issuer'] . '|' . $app['client_id']), hash('sha256', $subject));
        \PortalWeaveSSO\Auth::remember_sid($first, $app, ['sid' => 'sid-for-first']);
        \PortalWeaveSSO\Auth::remember_sid($second, array_merge($app, ['id' => 'different-app']), ['sid' => 'sid-for-first']);
        update_user_meta($first, \PortalWeaveSSO\Auth::token_meta_key($app['id']), \PortalWeaveSSO\Secrets::encrypt('{"refresh_token":"sensitive"}'));
        $one = \WP_Session_Tokens::get_instance($first);
        $two = \WP_Session_Tokens::get_instance($second);
        $first_token = $one->create(time() + 3600);
        $second_token = $two->create(time() + 3600);
        $claims = ['iss' => $app['issuer'], 'aud' => $app['client_id'], 'iat' => time(), 'jti' => $jti, 'sid' => 'sid-for-first', 'events' => ['http://schemas.openid.net/event/backchannel-logout' => new stdClass()]];
        $jwt = $sign($claims);
        portalweave_sso_assert(\PortalWeaveSSO\Auth::backchannel_logout($app['id'], $jwt) === true, 'Valid signed logout failed');
        portalweave_sso_assert($one->get($first_token) === null, 'Matching WordPress session survived logout');
        portalweave_sso_assert(is_array($two->get($second_token)), 'Other app session was incorrectly revoked');
        portalweave_sso_assert(get_user_meta($first, \PortalWeaveSSO\Auth::token_meta_key($app['id']), true) === '', 'Provider tokens survived logout');
        portalweave_sso_error(\PortalWeaveSSO\Auth::backchannel_logout($app['id'], $jwt), 'portalweave_sso_logout_replay');
        // Subject-only events do not require a sid index or an exp claim.
        $fresh_session = $one->create(time() + 3600);
        unset($claims['sid']);
        $claims['sub'] = $subject;
        $claims['jti'] .= '-subject';
        portalweave_sso_assert(\PortalWeaveSSO\Auth::backchannel_logout($app['id'], $sign($claims)) === true, 'Subject-only logout failed');
        portalweave_sso_assert($one->get($fresh_session) === null, 'Subject-only logout did not revoke session');
    } finally {
        update_option('portalweave_sso_settings', $old, false);
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        if (is_int($first) && $first > 0) { wp_delete_user($first); }
        if (is_int($second) && $second > 0) { wp_delete_user($second); }
        foreach ([$jti, $jti . '-subject'] as $id) {
            delete_option('portalweave_sso_logout_' . hash('sha256', get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'] . '|' . $id));
        }
    }
});

portalweave_sso_test('Refresh rotates encrypted credentials, binds identity and requires account permission', function () {
    $old = get_option('portalweave_sso_settings', []);
    $user_id = 0;
    $mode = 'valid';
    $seen = [];
    $app = array_replace(\PortalWeaveSSO\Config::app_defaults(), [
        'id' => 'refresh-test', 'name' => 'Refresh test', 'enabled' => true,
        'client_id' => 'refresh-client', 'client_secret' => 'secret:with-colon-and-32-plus-bytes',
        'issuer' => 'https://example.org', 'authorize_url' => 'https://example.org/authorize',
        'token_url' => 'https://example.org/refresh-fixture', 'jwt_secret' => str_repeat('R', 64), 'algorithms' => ['HS256'],
    ]);
    $encode = static function ($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
    $http = static function ($pre, $args, $url) use (&$mode, &$seen, $app, $encode) {
        if ($url !== $app['token_url']) { return $pre; }
        $seen = $args;
        $claims = ['iss' => $app['issuer'], 'aud' => $app['client_id'], 'sub' => $mode === 'subject' ? 'attacker-subject' : 'original-subject', 'nonce' => $mode === 'nonce' ? 'wrong-nonce' : 'original-nonce', 'iat' => time(), 'exp' => time() + 300];
        $input = $encode(json_encode(['alg' => 'HS256'])) . '.' . $encode(json_encode($claims));
        $token = $input . '.' . $encode(hash_hmac('sha256', $input, $app['jwt_secret'], true));
        return ['response' => ['code' => 200], 'headers' => [], 'body' => json_encode(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'id_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 300])];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        $saved = \PortalWeaveSSO\Config::save(['apps' => [$app['id'] => $app], 'default_app' => $app['id']]);
        portalweave_sso_assert($saved === true, 'Unable to save refresh fixture: ' . (is_wp_error($saved) ? $saved->get_error_message() : 'unknown error'));
        $user_id = wp_insert_user(['user_login' => 'refresh_' . wp_generate_password(10, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12, false) . '@example.org', 'role' => 'subscriber']);
        portalweave_sso_assert(is_int($user_id), 'Unable to create refresh user');
        $meta = \PortalWeaveSSO\Auth::token_meta_key($app['id']);
        update_user_meta($user_id, $meta, \PortalWeaveSSO\Secrets::encrypt(json_encode(['refresh_token' => 'original-refresh', '_subject' => 'original-subject', '_nonce' => 'original-nonce'])));
        wp_set_current_user(0);
        portalweave_sso_error(\PortalWeaveSSO\Auth::refresh($user_id, $app['id']), 'portalweave_sso_permission');
        wp_set_current_user($user_id);
        $result = \PortalWeaveSSO\Auth::refresh($user_id, $app['id']);
        portalweave_sso_assert(is_array($result) && $result['refresh_token'] === 'rotated-refresh', 'Refresh token not rotated');
        parse_str($seen['body'], $body);
        portalweave_sso_assert(($body['grant_type'] ?? '') === 'refresh_token' && ($body['refresh_token'] ?? '') === 'original-refresh', 'Refresh request body incorrect');
        portalweave_sso_assert(!isset($body['client_secret']) && !isset($body['client_id']), 'Header credentials leaked into body');
        portalweave_sso_assert(($seen['headers']['Authorization'] ?? '') === 'Basic ' . base64_encode(urlencode($app['client_id']) . ':' . urlencode($app['client_secret'])), 'Basic credentials were not form-encoded');
        $encrypted = get_user_meta($user_id, $meta, true);
        portalweave_sso_assert(strpos($encrypted, 'rotated-refresh') === false, 'Rotated credentials stored in plaintext');
        $stored = json_decode(\PortalWeaveSSO\Secrets::decrypt($encrypted), true);
        portalweave_sso_assert($stored['refresh_token'] === 'rotated-refresh' && $stored['_subject'] === 'original-subject', 'Encrypted rotation lost identity');
        foreach (['subject', 'nonce'] as $invalid) {
            $mode = $invalid;
            portalweave_sso_error(\PortalWeaveSSO\Auth::refresh($user_id, $app['id']), 'portalweave_sso_refresh_subject');
            portalweave_sso_assert(get_user_meta($user_id, $meta, true) === $encrypted, 'Rejected refresh changed stored credentials');
        }
    } finally {
        remove_filter('pre_http_request', $http, 10);
        update_option('portalweave_sso_settings', $old, false);
        if (is_int($user_id) && $user_id > 0) { wp_delete_user($user_id); }
    }
});
