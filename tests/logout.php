<?php
/** Exercise sign-out choices against real WordPress sessions and encrypted provider storage. */
function portalweave_sso_logout_states(): array {
    global $wpdb;
    return $wpdb->get_col($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('portalweave_sso_state_') . '%'));
}

function portalweave_sso_logout_fixture(callable $test, array $overrides = [], bool $linked = true, bool $confirmation = true): void {
    $prior_user = get_current_user_id();
    $prior_cookies = $_COOKIE;
    $prior_states = portalweave_sso_logout_states();
    $app = portalweave_sso_app(array_replace(['name' => 'Northstar Identity', 'logout_url' => 'https://example.org/end-session'], $overrides));
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture', 'logout_confirmation' => $confirmation]) === true, 'Unable to configure the sign-out fixture');
    $user = wp_insert_user(['user_login' => 'logout_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(32), 'user_email' => bin2hex(random_bytes(5)) . '@example.org', 'role' => 'subscriber']);
    portalweave_sso_assert(is_int($user) && $user > 0, 'Unable to create sign-out user');
    try {
        wp_set_current_user($user);
        $sessions = WP_Session_Tokens::get_instance($user);
        $expires = time() + HOUR_IN_SECONDS;
        $session = $sessions->create($expires);
        $other = $sessions->create($expires);
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user, $expires, 'logged_in', $session);
        portalweave_sso_assert(wp_get_session_token() === $session, 'A real current session is required');
        if ($linked) { update_user_meta($user, \PortalWeaveSSO\Auth::app_meta_key(), 'fixture'); }
        $stored = \PortalWeaveSSO\Secrets::encrypt(wp_json_encode(['access_token' => 'local-only-access', 'refresh_token' => 'local-only-refresh', 'id_token' => 'local-only-id-token']));
        update_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('fixture'), $stored);
        update_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('other-provider'), $stored);
        $test($user, $session, $other, $stored, $app);
    } finally {
        foreach (array_diff(portalweave_sso_logout_states(), $prior_states) as $key) { delete_option($key); }
        $_COOKIE = $prior_cookies;
        wp_set_current_user($prior_user);
        wp_delete_user($user);
    }
}

/** Capture only terminal platform boundaries; execute the actual private route handler. */
function portalweave_sso_logout_request(array $fields, string $method = 'POST'): array {
    $previous = [$_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null];
    $result = [];
    $redirect = static function ($url) use (&$result) {
        $result = ['redirect' => $url];
        throw new RuntimeException('portalweave_sso_expected_logout_result');
    };
    $die = static function () use (&$result) {
        return static function ($message) use (&$result) {
            $result = ['error' => $message];
            throw new RuntimeException('portalweave_sso_expected_logout_result');
        };
    };
    add_filter('wp_redirect', $redirect);
    add_filter('wp_die_handler', $die);
    try {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $method === 'GET' ? $fields : [];
        $_POST = $method === 'POST' ? $fields : [];
        $_REQUEST = $fields;
        try {
            (new ReflectionMethod(\PortalWeaveSSO\Auth::class, 'logout'))->invoke(null);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'portalweave_sso_expected_logout_result') { throw $error; }
        }
    } finally {
        remove_filter('wp_redirect', $redirect);
        remove_filter('wp_die_handler', $die);
        [$_GET, $_POST, $_REQUEST, $old_method] = $previous;
        if ($old_method === null) { unset($_SERVER['REQUEST_METHOD']); }
        else { $_SERVER['REQUEST_METHOD'] = $old_method; }
    }
    portalweave_sso_assert($result !== [], 'Sign-out action did not reach a terminal boundary');
    return $result;
}

function portalweave_sso_logout_fields(array $fields = []): array {
    return array_replace(['confirm' => '1', '_wpnonce' => wp_create_nonce('portalweave_sso_logout'), 'redirect_to' => home_url('/after-signout/')], $fields);
}

portalweave_sso_test('Site-only sign out revokes the current session without contacting the provider', function () {
    portalweave_sso_logout_fixture(function ($user, $session, $other, $stored) {
        $states = portalweave_sso_logout_states();
        $result = portalweave_sso_logout_request(portalweave_sso_logout_fields(['logout_scope' => 'site']));
        portalweave_sso_assert(($result['redirect'] ?? '') === home_url('/after-signout/'), 'Site-only sign out redirected to the identity provider');
        portalweave_sso_assert(!is_user_logged_in() && WP_Session_Tokens::get_instance($user)->get($session) === null, 'Current WordPress session survived sign out');
        portalweave_sso_assert(is_array(WP_Session_Tokens::get_instance($user)->get($other)), 'An unrelated WordPress session was revoked');
        portalweave_sso_assert(get_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('fixture'), true) === '', 'Current provider tokens survived sign out');
        portalweave_sso_assert(get_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('other-provider'), true) === $stored, 'Other provider token storage was cleared');
        portalweave_sso_assert(portalweave_sso_logout_states() === $states, 'Site-only sign out created provider logout state');
    });
});

portalweave_sso_test('Combined sign out retains the provider hint and a one-use browser-bound return', function () {
    portalweave_sso_logout_fixture(function ($user, $session) {
        $result = portalweave_sso_logout_request(portalweave_sso_logout_fields(['logout_scope' => 'all']));
        $url = $result['redirect'] ?? '';
        portalweave_sso_assert(wp_parse_url($url, PHP_URL_HOST) === 'example.org' && wp_parse_url($url, PHP_URL_PATH) === '/end-session', 'Combined sign out did not reach the configured endpoint');
        parse_str(wp_parse_url($url, PHP_URL_QUERY), $params);
        portalweave_sso_assert(($params['id_token_hint'] ?? '') === 'local-only-id-token' && ($params['client_id'] ?? '') === 'fixture-client', 'Required provider protocol values were lost');
        portalweave_sso_assert(($params['post_logout_redirect_uri'] ?? '') === add_query_arg('portalweave_sso_action', 'logout_callback', home_url('/')), 'Provider return does not use the canonical callback');
        portalweave_sso_assert(!is_user_logged_in() && WP_Session_Tokens::get_instance($user)->get($session) === null, 'Local session survived combined sign out');
        portalweave_sso_assert(get_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('fixture'), true) === '', 'Provider tokens remained stored after combined sign out');
        $state = $params['state'] ?? '';
        portalweave_sso_assert(is_string($state) && strlen($state) >= 43, 'Provider state is missing');
        $key = 'portalweave_sso_state_' . hash('sha256', $state);
        $cookie = '__Host-portalweave_sso_' . substr(hash('sha256', $state), 0, 24);
        $flow = get_option($key);
        portalweave_sso_assert(is_array($flow) && $flow['kind'] === 'logout' && $flow['app'] === 'fixture', 'Provider state lost its purpose or provider');
        $flow['browser'] = hash('sha256', 'known-logout-browser');
        update_option($key, $flow, false);
        $_COOKIE[$cookie] = 'different-browser';
        portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow($state, 'logout'), 'portalweave_sso_state');
        $_COOKIE[$cookie] = 'known-logout-browser';
        portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow($state, 'login'), 'portalweave_sso_state');
        $returned = \PortalWeaveSSO\Auth::consume_flow($state, 'logout');
        portalweave_sso_assert(is_array($returned) && $returned['redirect'] === home_url('/after-signout/'), 'Validated sign-out return destination was lost');
        portalweave_sso_error(\PortalWeaveSSO\Auth::consume_flow($state, 'logout'), 'portalweave_sso_state');
    });
});

portalweave_sso_test('Malformed choices and invalid nonces preserve sessions and provider credentials', function () {
    portalweave_sso_logout_fixture(function ($user, $session, $other, $stored) {
        $cases = [
            ['logout_scope' => 'unknown'], ['logout_scope' => ['site']], ['logout_scope' => ''], [],
            ['logout_scope' => 'site', '_wpnonce' => 'forged'], ['logout_scope' => 'site', '_wpnonce' => ['nested']],
        ];
        foreach ($cases as $case) {
            $states = portalweave_sso_logout_states();
            $result = portalweave_sso_logout_request(portalweave_sso_logout_fields($case));
            portalweave_sso_assert(isset($result['error']), 'Malformed sign-out request was accepted');
            portalweave_sso_assert(get_current_user_id() === $user && is_array(WP_Session_Tokens::get_instance($user)->get($session)), 'Rejected request revoked the current session');
            portalweave_sso_assert(get_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('fixture'), true) === $stored, 'Rejected request cleared provider credentials');
            portalweave_sso_assert(portalweave_sso_logout_states() === $states, 'Rejected request created provider logout state');
        }
    });
});

portalweave_sso_test('Unsupported and unlinked providers cannot be forced into combined sign out', function () {
    foreach ([['logout_url' => ''], ['logout_url' => '', 'backchannel_logout' => true]] as $override) {
        portalweave_sso_logout_fixture(function ($user, $session, $other, $stored) {
            $states = portalweave_sso_logout_states();
            $result = portalweave_sso_logout_request(portalweave_sso_logout_fields(['logout_scope' => 'all']));
            portalweave_sso_assert(isset($result['error']), 'Missing front-channel support accepted combined sign out');
            portalweave_sso_assert(get_current_user_id() === $user && is_array(WP_Session_Tokens::get_instance($user)->get($session)), 'Unsupported combined request revoked the session');
            portalweave_sso_assert(get_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('fixture'), true) === $stored && portalweave_sso_logout_states() === $states, 'Unsupported request changed provider storage');
        }, $override);
    }
    portalweave_sso_logout_fixture(function ($user, $session) {
        $result = portalweave_sso_logout_request(portalweave_sso_logout_fields(['logout_scope' => 'all']));
        portalweave_sso_assert(isset($result['error']) && get_current_user_id() === $user && is_array(WP_Session_Tokens::get_instance($user)->get($session)), 'Default provider was used for an unlinked WordPress session');
    }, [], false);
});

portalweave_sso_test('Provider state storage failure preserves the current session and encrypted credentials', function () {
    portalweave_sso_logout_fixture(function ($user, $session, $other, $stored) {
        $states = portalweave_sso_logout_states();
        $unavailable = static function ($pre, $option) {
            return str_starts_with($option, 'portalweave_sso_state_') ? ['fixture_storage_collision' => true] : $pre;
        };
        add_filter('pre_option', $unavailable, 10, 2);
        try {
            $result = portalweave_sso_logout_request(portalweave_sso_logout_fields(['logout_scope' => 'all']));
        } finally { remove_filter('pre_option', $unavailable, 10); }
        portalweave_sso_assert(isset($result['error']) && str_contains($result['error'], 'portalweave_sso_state_store'), 'Provider state failure was not reported');
        portalweave_sso_assert(get_current_user_id() === $user && is_array(WP_Session_Tokens::get_instance($user)->get($session)), 'State storage failure revoked the current session');
        portalweave_sso_assert(get_user_meta($user, \PortalWeaveSSO\Auth::token_meta_key('fixture'), true) === $stored && portalweave_sso_logout_states() === $states, 'State storage failure changed provider credentials or left a flow behind');
    });
});

portalweave_sso_test('Disabled confirmation preserves nonce-bearing logout links and supported default scope', function () {
    foreach ([true, false] as $supported) {
        portalweave_sso_logout_fixture(function ($user, $session) use ($supported) {
            $result = portalweave_sso_logout_request(portalweave_sso_logout_fields(), 'GET');
            portalweave_sso_assert(isset($result['redirect']) && !is_user_logged_in() && WP_Session_Tokens::get_instance($user)->get($session) === null, 'Existing no-confirmation link no longer signs out');
            portalweave_sso_assert($supported ? wp_parse_url($result['redirect'], PHP_URL_HOST) === 'example.org' : $result['redirect'] === home_url('/after-signout/'), 'Default no-confirmation scope changed');
        }, ['logout_url' => $supported ? 'https://example.org/end-session' : ''], true, false);
    }
});

portalweave_sso_test('Sign-out view names the provider, hides unsupported actions and escapes public labels', function () {
    $context = ['site_name' => 'Northstar <script>alert(1)</script>', 'provider_name' => 'Identity <img src=x onerror=alert(1)> & Co.',
        'action_url' => add_query_arg('portalweave_sso_action', 'logout', home_url('/')), 'nonce' => 'fixture-form-nonce',
        'redirect' => home_url('/after-signout/'), 'back_url' => home_url('/members/'), 'can_provider' => true];
    $html = \PortalWeaveSSO\LogoutView::render($context);
    portalweave_sso_assert(str_contains($html, esc_html($context['site_name'])) && str_contains($html, esc_html($context['provider_name'])), 'Names were omitted or not escaped');
    portalweave_sso_assert(!str_contains($html, '<script') && !str_contains($html, '<img src=x'), 'A public label injected executable markup');
    portalweave_sso_assert(preg_match('/<button\b(?=[^>]*\bname="logout_scope")(?=[^>]*\bvalue="all")[^>]*>/i', $html) === 1, 'Supported provider has no combined submit action');
    portalweave_sso_assert(preg_match('/<button\b(?=[^>]*\bname="logout_scope")(?=[^>]*\bvalue="site")[^>]*>/i', $html) === 1, 'Site-only submit action is missing');
    portalweave_sso_assert(str_contains($html, 'name="_wpnonce"') && str_contains($html, 'fixture-form-nonce') && str_contains($html, 'method="post"') && str_contains($html, esc_url($context['back_url'])), 'The form lost its nonce, POST method or back navigation');
    $context['can_provider'] = false;
    $local = \PortalWeaveSSO\LogoutView::render($context);
    portalweave_sso_assert(preg_match('/<button\b(?=[^>]*\bname="logout_scope")(?=[^>]*\bvalue="all")[^>]*>/i', $local) === 0, 'Unsupported provider still offers combined sign out');
    portalweave_sso_assert(preg_match('/<button\b(?=[^>]*\bname="logout_scope")(?=[^>]*\bvalue="site")[^>]*>/i', $local) === 1 && str_contains($local, esc_url($context['back_url'])), 'Local sign out or Go back disappeared without provider support');
});
