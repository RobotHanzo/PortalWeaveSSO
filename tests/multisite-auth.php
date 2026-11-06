<?php
/** Included by the disposable real-multisite integration runner. */
function wpsso_multisite_auth_at($blog_id, callable $callback) {
    switch_to_blog((int) $blog_id);
    try { return $callback(); } finally { restore_current_blog(); }
}

function wpsso_multisite_auth_fixture(array $overrides = []) {
    return array_replace(\WPSSO\Config::app_defaults(), [
        'id' => 'same-provider', 'name' => 'Multisite isolation fixture', 'enabled' => true,
        'client_id' => 'shared-client', 'issuer' => 'https://example.org',
        'authorize_url' => 'https://example.org/authorize', 'token_url' => 'https://example.org/token',
        'userinfo_url' => 'https://example.org/userinfo', 'jwt_secret' => str_repeat('M', 64),
        'algorithms' => ['HS256'], 'backchannel_logout' => true,
    ], $overrides);
}

function wpsso_multisite_auth_delete_user($id) {
    if (!is_int($id) || $id < 1) { return; }
    if (!function_exists('wpmu_delete_user')) { require_once ABSPATH . 'wp-admin/includes/ms.php'; }
    wpmu_delete_user($id);
}

wpsso_test('multisite: OAuth credentials and last-provider metadata have distinct blog keys', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    wpsso_assert(is_multisite(), 'This suite requires real multisite');
    $a = wpsso_multisite_auth_at($wpsso_blog_a, static function () {
        return [\WPSSO\Auth::token_meta_key('same-provider'), \WPSSO\Auth::app_meta_key()];
    });
    $b = wpsso_multisite_auth_at($wpsso_blog_b, static function () {
        return [\WPSSO\Auth::token_meta_key('same-provider'), \WPSSO\Auth::app_meta_key()];
    });
    wpsso_assert($a === ['_wpsso_tokens_' . $wpsso_blog_a . '_same-provider', '_wpsso_app_' . $wpsso_blog_a], 'Site A keys are not scoped');
    wpsso_assert($b === ['_wpsso_tokens_' . $wpsso_blog_b . '_same-provider', '_wpsso_app_' . $wpsso_blog_b], 'Site B keys are not scoped');
    wpsso_assert($a[0] !== $b[0] && $a[1] !== $b[1], 'Provider keys collide between blogs');
});

wpsso_test('multisite: refresh never sends another blog or legacy global OAuth credential', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $user_id = 0;
    $settings = [];
    $requests = [];
    $original_user = get_current_user_id();
    $blogs = [(int) $wpsso_blog_a, (int) $wpsso_blog_b];
    $http = static function ($pre, $args, $url) use (&$requests, $blogs) {
        foreach ($blogs as $blog_id) {
            if ($url === 'https://example.org/multisite-token-' . $blog_id) {
                parse_str($args['body'], $body);
                $requests[] = ['blog' => get_current_blog_id(), 'endpoint' => $blog_id, 'refresh' => $body['refresh_token'] ?? ''];
                return ['response' => ['code' => 200], 'headers' => [], 'body' => json_encode(['access_token' => 'access-' . $blog_id, 'refresh_token' => 'rotated-' . $blog_id, 'token_type' => 'Bearer', 'expires_in' => 300])];
            }
        }
        return $pre;
    };
    add_filter('pre_http_request', $http, 10, 3);
    try {
        foreach ($blogs as $blog_id) {
            wpsso_multisite_auth_at($blog_id, static function () use ($blog_id, &$settings) {
                $settings[$blog_id] = get_option('wpsso_settings', []);
                $app = wpsso_multisite_auth_fixture(['protocol' => 'oauth2', 'token_url' => 'https://example.org/multisite-token-' . $blog_id, 'credential_method' => 'none']);
                $result = \WPSSO\Config::save(['apps' => [$app['id'] => $app], 'default_app' => $app['id']]);
                wpsso_assert($result === true, 'Unable to configure multisite refresh fixture: ' . (is_wp_error($result) ? $result->get_error_message() : 'unknown'));
            });
        }
        $user_id = wpsso_multisite_auth_at($wpsso_blog_a, static function () {
            return wp_insert_user(['user_login' => 'msauth_' . wp_generate_password(12, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12, false) . '@example.org', 'role' => 'subscriber']);
        });
        wpsso_assert(is_int($user_id), 'Unable to create multisite refresh user');
        add_user_to_blog((int) $wpsso_blog_b, $user_id, 'subscriber');
        wp_set_current_user($user_id);
        update_user_meta($user_id, '_wpsso_tokens_same-provider', \WPSSO\Secrets::encrypt(json_encode(['refresh_token' => 'legacy-global-secret'])));
        update_user_meta($user_id, '_wpsso_app', 'legacy-global-provider');
        wpsso_multisite_auth_at($wpsso_blog_a, static function () use ($user_id, $wpsso_blog_a) {
            update_user_meta($user_id, \WPSSO\Auth::token_meta_key('same-provider'), \WPSSO\Secrets::encrypt(json_encode(['refresh_token' => 'original-' . $wpsso_blog_a, '_subject' => 'immutable-a', '_nonce' => 'nonce-a'])));
            update_user_meta($user_id, \WPSSO\Auth::app_meta_key(), 'same-provider');
        });
        wpsso_multisite_auth_at($wpsso_blog_b, static function () use ($user_id, $wpsso_blog_b, &$requests) {
            wpsso_assert(get_user_meta($user_id, \WPSSO\Auth::app_meta_key(), true) === '', 'Last-provider metadata leaked across blogs');
            wpsso_error(\WPSSO\Auth::refresh($user_id, 'same-provider'), 'wpsso_refresh_missing');
            wpsso_assert(count($requests) === 0, 'Site B transmitted another blog credential to its endpoint');
            update_user_meta($user_id, \WPSSO\Auth::token_meta_key('same-provider'), \WPSSO\Secrets::encrypt(json_encode(['refresh_token' => 'original-' . $wpsso_blog_b, '_subject' => 'immutable-b', '_nonce' => 'nonce-b'])));
            $result = \WPSSO\Auth::refresh($user_id, 'same-provider');
            wpsso_assert(is_array($result) && $result['refresh_token'] === 'rotated-' . $wpsso_blog_b, 'Site B refresh failed');
        });
        wpsso_multisite_auth_at($wpsso_blog_a, static function () use ($user_id, $wpsso_blog_a) {
            $result = \WPSSO\Auth::refresh($user_id, 'same-provider');
            wpsso_assert(is_array($result) && $result['refresh_token'] === 'rotated-' . $wpsso_blog_a, 'Site A credential was overwritten by Site B');
        });
        wpsso_assert($requests === [
            ['blog' => (int) $wpsso_blog_b, 'endpoint' => (int) $wpsso_blog_b, 'refresh' => 'original-' . $wpsso_blog_b],
            ['blog' => (int) $wpsso_blog_a, 'endpoint' => (int) $wpsso_blog_a, 'refresh' => 'original-' . $wpsso_blog_a],
        ], 'Refresh endpoint received a credential from a different blog');
    } finally {
        remove_filter('pre_http_request', $http, 10);
        foreach ($settings as $blog_id => $value) {
            wpsso_multisite_auth_at($blog_id, static function () use ($value) { update_option('wpsso_settings', $value, false); });
        }
        wp_set_current_user($original_user);
        wpsso_multisite_auth_delete_user($user_id);
    }
});

wpsso_test('multisite: signed back-channel subject logout is restricted to its blog identity', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $settings = [];
    $users = [];
    $sessions = [];
    $app = wpsso_multisite_auth_fixture();
    $jti = 'multisite-logout-' . wp_generate_password(20, false);
    try {
        foreach ([(int) $wpsso_blog_a, (int) $wpsso_blog_b] as $blog_id) {
            wpsso_multisite_auth_at($blog_id, static function () use ($blog_id, $app, &$settings, &$users, &$sessions) {
                $settings[$blog_id] = get_option('wpsso_settings', []);
                $result = \WPSSO\Config::save(['apps' => [$app['id'] => $app], 'default_app' => $app['id']]);
                wpsso_assert($result === true, 'Unable to configure multisite logout fixture');
                $user = wp_insert_user(['user_login' => 'mslogout_' . wp_generate_password(12, false), 'user_pass' => wp_generate_password(32), 'user_email' => wp_generate_password(12, false) . '@example.org', 'role' => 'subscriber']);
                wpsso_assert(is_int($user), 'Unable to create multisite logout user');
                $users[$blog_id] = $user;
                $scope = $blog_id . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
                update_user_meta($user, '_wpsso_identity_' . hash('sha256', $scope), hash('sha256', 'same-subject'));
                update_user_meta($user, \WPSSO\Auth::token_meta_key($app['id']), \WPSSO\Secrets::encrypt('{"refresh_token":"site-secret"}'));
                $sessions[$blog_id] = \WP_Session_Tokens::get_instance($user)->create(time() + 3600);
            });
        }
        $encode = static function ($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
        $claims = ['iss' => $app['issuer'], 'aud' => $app['client_id'], 'iat' => time(), 'jti' => $jti, 'sub' => 'same-subject', 'events' => ['http://schemas.openid.net/event/backchannel-logout' => new stdClass()]];
        $input = $encode(json_encode(['alg' => 'HS256'])) . '.' . $encode(json_encode($claims));
        $token = $input . '.' . $encode(hash_hmac('sha256', $input, $app['jwt_secret'], true));
        wpsso_multisite_auth_at($wpsso_blog_a, static function () use ($app, $token, $users, $sessions, $wpsso_blog_a) {
            wpsso_assert(\WPSSO\Auth::backchannel_logout($app['id'], $token) === true, 'Site A logout failed');
            wpsso_assert(\WP_Session_Tokens::get_instance($users[$wpsso_blog_a])->get($sessions[$wpsso_blog_a]) === null, 'Site A session survived');
            wpsso_assert(get_user_meta($users[$wpsso_blog_a], \WPSSO\Auth::token_meta_key($app['id']), true) === '', 'Site A token survived');
        });
        wpsso_multisite_auth_at($wpsso_blog_b, static function () use ($app, $users, $sessions, $wpsso_blog_b) {
            wpsso_assert(is_array(\WP_Session_Tokens::get_instance($users[$wpsso_blog_b])->get($sessions[$wpsso_blog_b])), 'Site B identity was logged out by Site A');
            wpsso_assert(get_user_meta($users[$wpsso_blog_b], \WPSSO\Auth::token_meta_key($app['id']), true) !== '', 'Site B credentials were removed by Site A');
        });
    } finally {
        foreach ($settings as $blog_id => $value) {
            wpsso_multisite_auth_at($blog_id, static function () use ($value, $blog_id, $app, $jti) {
                update_option('wpsso_settings', $value, false);
                $scope = $blog_id . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
                delete_option('wpsso_logout_' . hash('sha256', $scope . '|' . $jti));
            });
        }
        foreach ($users as $user_id) { wpsso_multisite_auth_delete_user($user_id); }
    }
});
