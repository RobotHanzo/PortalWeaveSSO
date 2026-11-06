<?php
wpsso_test('OAuth state is browser-bound, purpose-bound and consumed once', function () {
    $state = \WPSSO\Auth::create_flow(['kind' => 'login', 'app' => 'fixture', 'redirect' => home_url('/members/')], 'configured-extra-state');
    wpsso_assert(is_string($state), 'Flow could not be created');
    $key = 'wpsso_state_' . hash('sha256', $state);
    $name = '__Host-wpsso_' . substr(hash('sha256', $state), 0, 24);
    $flow = get_option($key);
    // Deliberately substitute a known browser secret into persisted flow. The
    // external cookie cannot be read from PHP CLI response headers.
    $flow['browser'] = hash('sha256', 'known-browser-secret');
    update_option($key, $flow, false);
    $_COOKIE[$name] = 'another-browser';
    wpsso_error(\WPSSO\Auth::consume_flow($state, 'login'), 'wpsso_state');
    $_COOKIE[$name] = 'known-browser-secret';
    wpsso_error(\WPSSO\Auth::consume_flow($state, 'logout'), 'wpsso_state');
    $result = \WPSSO\Auth::consume_flow($state, 'login');
    wpsso_assert(is_array($result) && $result['redirect'] === home_url('/members/'), 'Original redirect not retained');
    wpsso_assert(get_option($key) === false, 'State not deleted atomically');
    wpsso_error(\WPSSO\Auth::consume_flow($state, 'login'), 'wpsso_state');
    unset($_COOKIE[$name]);
});

wpsso_test('Expired state and mismatched multisite state cannot authenticate', function () {
    foreach (['expired', 'site'] as $case) {
        $state = \WPSSO\Auth::create_flow(['kind' => 'login', 'app' => 'fixture']);
        wpsso_assert(is_string($state), 'Flow creation failed');
        $key = 'wpsso_state_' . hash('sha256', $state);
        $name = '__Host-wpsso_' . substr(hash('sha256', $state), 0, 24);
        $flow = get_option($key);
        $flow['browser'] = hash('sha256', 'browser');
        if ($case === 'expired') { $flow['expires'] = time() - 1; }
        else { $flow['blog_id'] = get_current_blog_id() + 1; }
        update_option($key, $flow, false);
        $_COOKIE[$name] = 'browser';
        wpsso_error(\WPSSO\Auth::consume_flow($state, 'login'), 'wpsso_state');
        delete_option($key);
        unset($_COOKIE[$name]);
    }
    wpsso_error(\WPSSO\Auth::consume_flow('attacker-selected-state', 'login'), 'wpsso_state');
});

wpsso_test('Test configuration URLs carry a fresh provider-specific admin nonce', function () {
    $url = \WPSSO\Auth::login_url('test-provider', home_url('/origin/'), true);
    parse_str(wp_parse_url($url, PHP_URL_QUERY), $params);
    wpsso_assert(($params['test'] ?? '') === '1', 'Test flag missing');
    wpsso_assert(wp_verify_nonce($params['_wpnonce'] ?? '', 'wpsso_test_test-provider') !== false, 'Test nonce invalid');
    wpsso_assert(($params['redirect_to'] ?? '') === home_url('/origin/'), 'Origin navigation lost');
});

wpsso_test('Identity HTTP rejects insecure URLs and never follows redirects', function () {
    wpsso_error(\WPSSO\Http::json('http://example.org/token'), 'wpsso_endpoint');
    wpsso_error(\WPSSO\Http::json('https://user:secret@example.org/token'), 'wpsso_endpoint');
    wpsso_error(\WPSSO\Http::json('https://example.org/token#fragment'), 'wpsso_endpoint');
    $seen = [];
    $http = static function ($pre, $args, $url) use (&$seen) {
        if ($url !== 'https://example.org/wpsso-transport-test') { return $pre; }
        $seen = $args;
        return ['response' => ['code' => 302], 'body' => '{"access_token":"never-expose-me"}', 'headers' => ['location' => 'https://evil.example/']];
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        $result = \WPSSO\Http::json('https://example.org/wpsso-transport-test', ['redirection' => 5, 'sslverify' => false]);
        wpsso_error($result, 'wpsso_http_status');
        wpsso_assert($seen['redirection'] === 0 && $seen['sslverify'] === true && $seen['reject_unsafe_urls'] === true, 'Safe transport flags were overridden');
        wpsso_assert(strpos($result->get_error_message(), 'never-expose-me') === false, 'Token leaked in error');
    } finally { remove_filter('pre_http_request', $http, 10); }
});

wpsso_test('Signed back-channel logout revokes only matching app identities and rejects replay', function () {
    $old = get_option('wpsso_settings', []);
    $first = 0;
    $second = 0;
    $jti = 'logout-' . wp_generate_password(20, false);
    $subject = 'backchannel-subject';
    $app = array_replace(\WPSSO\Config::app_defaults(), [
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
        $saved = \WPSSO\Config::save(['apps' => [$app['id'] => $app]]);
        wpsso_assert($saved === true, 'Unable to save back-channel fixture');
        $first = wp_insert_user(['user_login' => 'bc_' . wp_generate_password(12, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12, false) . '@example.org', 'role' => 'subscriber']);
        $second = wp_insert_user(['user_login' => 'bc_' . wp_generate_password(12, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12, false) . '@example.org', 'role' => 'subscriber']);
        wpsso_assert(is_int($first) && is_int($second), 'Unable to create back-channel users');
        $scope = get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
        $scope_hash = hash('sha256', $scope);
        update_user_meta($first, '_wpsso_identity_' . $scope_hash, hash('sha256', $subject));
        // Same immutable subject on a separate app must not receive this logout.
        update_user_meta($second, '_wpsso_identity_' . hash('sha256', get_current_blog_id() . '|different-app|' . $app['issuer'] . '|' . $app['client_id']), hash('sha256', $subject));
        \WPSSO\Auth::remember_sid($first, $app, ['sid' => 'sid-for-first']);
        \WPSSO\Auth::remember_sid($second, array_merge($app, ['id' => 'different-app']), ['sid' => 'sid-for-first']);
        update_user_meta($first, '_wpsso_tokens_' . $app['id'], \WPSSO\Secrets::encrypt('{"refresh_token":"sensitive"}'));
        $one = \WP_Session_Tokens::get_instance($first);
        $two = \WP_Session_Tokens::get_instance($second);
        $first_token = $one->create(time() + 3600);
        $second_token = $two->create(time() + 3600);
        $claims = ['iss' => $app['issuer'], 'aud' => $app['client_id'], 'iat' => time(), 'jti' => $jti, 'sid' => 'sid-for-first', 'events' => ['http://schemas.openid.net/event/backchannel-logout' => new stdClass()]];
        $jwt = $sign($claims);
        wpsso_assert(\WPSSO\Auth::backchannel_logout($app['id'], $jwt) === true, 'Valid signed logout failed');
        wpsso_assert($one->get($first_token) === null, 'Matching WordPress session survived logout');
        wpsso_assert(is_array($two->get($second_token)), 'Other app session was incorrectly revoked');
        wpsso_assert(get_user_meta($first, '_wpsso_tokens_' . $app['id'], true) === '', 'Provider tokens survived logout');
        wpsso_error(\WPSSO\Auth::backchannel_logout($app['id'], $jwt), 'wpsso_logout_replay');
        // Subject-only events do not require a sid index or an exp claim.
        $fresh_session = $one->create(time() + 3600);
        unset($claims['sid']);
        $claims['sub'] = $subject;
        $claims['jti'] .= '-subject';
        wpsso_assert(\WPSSO\Auth::backchannel_logout($app['id'], $sign($claims)) === true, 'Subject-only logout failed');
        wpsso_assert($one->get($fresh_session) === null, 'Subject-only logout did not revoke session');
    } finally {
        update_option('wpsso_settings', $old, false);
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        if (is_int($first) && $first > 0) { wp_delete_user($first); }
        if (is_int($second) && $second > 0) { wp_delete_user($second); }
        foreach ([$jti, $jti . '-subject'] as $id) {
            delete_option('wpsso_logout_' . hash('sha256', get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'] . '|' . $id));
        }
    }
});
