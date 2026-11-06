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
