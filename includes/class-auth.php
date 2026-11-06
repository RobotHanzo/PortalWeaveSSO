<?php
namespace WPSSO;

defined('ABSPATH') || exit;

/** Browser authentication and OIDC RP-initiated logout. */
final class Auth {
    private const TTL = 600;

    public static function token_meta_key($app_id) {
        return '_wpsso_tokens_' . (is_multisite() ? get_current_blog_id() . '_' : '') . sanitize_key($app_id);
    }

    public static function app_meta_key() {
        return '_wpsso_app' . (is_multisite() ? '_' . get_current_blog_id() : '');
    }

    public static function register() {
        add_action('init', [self::class, 'dispatch'], 1);
        add_filter('logout_url', [self::class, 'filter_logout_url'], 10, 2);
        add_action('wpsso_state_cleanup', [self::class, 'cleanup']);
        if (!wp_next_scheduled('wpsso_state_cleanup')) {
            wp_schedule_event(time() + 3600, 'hourly', 'wpsso_state_cleanup');
        }
    }

    public static function login_url($app_id, $redirect = '', $test = false, $popup = false) {
        $args = ['wpsso_action' => 'login', 'app' => sanitize_key($app_id)];
        if ($redirect !== '') {
            $args['redirect_to'] = Redirect::validate($redirect);
        }
        if ($popup) {
            $args['popup'] = '1';
        }
        if ($test) {
            $args['test'] = '1';
            $args['_wpnonce'] = wp_create_nonce('wpsso_test_' . sanitize_key($app_id));
        }
        return add_query_arg($args, home_url('/'));
    }

    public static function logout_url($redirect = '') {
        $args = ['wpsso_action' => 'logout', '_wpnonce' => wp_create_nonce('wpsso_logout')];
        if ($redirect !== '') {
            $args['redirect_to'] = Redirect::validate($redirect);
        }
        return add_query_arg($args, home_url('/'));
    }

    public static function filter_logout_url($url, $redirect) {
        if (is_user_logged_in() && get_user_meta(get_current_user_id(), self::app_meta_key(), true)) {
            return self::logout_url(is_string($redirect) ? $redirect : '');
        }
        return $url;
    }

    public static function dispatch() {
        $action = sanitize_key(self::input($_GET, 'wpsso_action')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routes only; each handler applies its browser-bound state, signed-token or WordPress-nonce authorization.
        if (!in_array($action, ['login', 'callback', 'logout', 'logout_callback', 'password', 'backchannel_logout'], true)) {
            return;
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        if (!is_ssl()) {
            self::fail(new \WP_Error('wpsso_https', __('SSO requires HTTPS on the WordPress site.', 'stsa-identity-sso')));
        }
        try {
            switch ($action) {
                case 'login': self::start(); break;
                case 'callback': self::callback(); break;
                case 'password': self::password(); break;
                case 'logout': self::logout(); break;
                case 'logout_callback': self::logout_callback(); break;
                case 'backchannel_logout': self::backchannel_endpoint(); break;
            }
        } catch (\Throwable $error) {
            // Never expose exception messages: they may contain provider payloads.
            self::fail(new \WP_Error('wpsso_internal', __('The SSO request could not be completed. Contact the site administrator.', 'stsa-identity-sso')));
        }
        exit;
    }

    private static function start() {
        $id = sanitize_key(self::input($_GET, 'app')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public login initiator creates browser-bound OAuth state; no user is authenticated here.
        $app = Config::app($id);
        if (!$app || empty($app['enabled'])) {
            self::fail(new \WP_Error('wpsso_app', __('The selected SSO provider is unavailable.', 'stsa-identity-sso')), $id);
        }
        $test = self::input($_GET, 'test') === '1';
        if ($test && (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(self::input($_GET, '_wpnonce')), 'wpsso_test_' . $id))) {
            self::fail(new \WP_Error('wpsso_test_permission', __('A fresh administrator test link is required.', 'stsa-identity-sso')), $id);
        }
        $callback = Config::callback($app);
        if (!self::https_endpoint($callback)) {
            self::fail(new \WP_Error('wpsso_callback_https', __('The canonical callback must use HTTPS. Check the WordPress site URL.', 'stsa-identity-sso')), $id);
        }
        // Browser binding must be created on the callback origin. login_url always
        // targets the canonical home origin, retaining the initiating redirect.
        if (self::origin($callback) !== self::origin(home_url('/'))) {
            self::fail(new \WP_Error('wpsso_callback_origin', __('The callback must use the canonical WordPress origin.', 'stsa-identity-sso')), $id);
        }
        $grant = $app['grant'] ?? 'authorization_code';
        if (in_array($grant, ['implicit', 'password'], true) && empty($app['legacy_grants'])) {
            self::fail(new \WP_Error('wpsso_legacy_disabled', __('This legacy grant is disabled. Use Authorization Code with PKCE.', 'stsa-identity-sso')), $id);
        }
        $redirect = self::input($_GET, 'redirect_to');
        if ($redirect === '') {
            $redirect = (string) Config::get('login_redirect', home_url('/'));
        }
        $flow = [
            'app' => $id, 'redirect' => Redirect::validate($redirect), 'callback' => $callback,
            'nonce' => Jwt::encode(random_bytes(32)), 'verifier' => Jwt::encode(random_bytes(48)),
            'test_user' => $test ? get_current_user_id() : 0, 'popup' => self::input($_GET, 'popup') === '1',
            'kind' => 'login', 'grant' => $grant,
            'config_hash' => self::config_hash($app),
        ];
        $state = self::create_flow($flow, (string) ($app['state_parameter'] ?? ''));
        if (is_wp_error($state)) {
            self::fail($state, $id);
        }
        if ($grant === 'password') {
            self::password_form($state, $app);
        }
        $params = [
            'client_id' => (string) ($app['client_id'] ?? ''), 'redirect_uri' => $callback,
            'response_type' => $grant === 'implicit' ? 'id_token token' : 'code',
            'scope' => (string) ($app['scope'] ?? 'openid profile email'), 'state' => $state,
        ];
        if (($app['protocol'] ?? 'oidc') === 'oidc' || $grant === 'implicit') {
            $params['nonce'] = $flow['nonce'];
        }
        if ($grant === 'implicit' && ($app['protocol'] ?? 'oidc') !== 'oidc') {
            self::fail(new \WP_Error('wpsso_implicit_oidc', __('Implicit login requires OpenID Connect and a signed ID token.', 'stsa-identity-sso')), $id);
        }
        if ($grant === 'authorization_code' && !empty($app['pkce'])) {
            $params['code_challenge'] = Jwt::encode(hash('sha256', $flow['verifier'], true));
            $params['code_challenge_method'] = 'S256';
        }
        if (empty($app['authorize_url'])) {
            self::fail(new \WP_Error('wpsso_endpoint', __('Configure an authorization endpoint.', 'stsa-identity-sso')), $id);
        }
        $url = add_query_arg($params, $app['authorize_url']);
        // Endpoint is administrator-configured, HTTPS and validated at save. A
        // provider hop is intentionally external; user redirects are separately checked.
        if (!self::https_endpoint($app['authorize_url'])) {
            self::fail(new \WP_Error('wpsso_endpoint', __('The authorization endpoint must use HTTPS.', 'stsa-identity-sso')), $id);
        }
        self::send_redirect($url);
    }

    private static function callback() {
        $request = self::request_method() === 'POST' ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Provider callbacks cannot supply WordPress nonces; consume_flow enforces one-use browser-bound OAuth state before processing.
        $state = self::input($request, 'state');
        if ($state === '' && self::request_method() === 'GET' && self::input($request, 'code') === '' && self::input($request, 'error') === '') {
            self::fragment_relay();
        }
        $flow = self::consume_flow($state, 'login');
        if (is_wp_error($flow)) {
            self::fail($flow);
        }
        $app = Config::app($flow['app']);
        if (!$app || empty($app['enabled'])) {
            self::fail(new \WP_Error('wpsso_app', __('The selected provider is unavailable.', 'stsa-identity-sso')));
        }
        if (!hash_equals((string) ($flow['config_hash'] ?? ''), self::config_hash($app))) {
            self::fail(new \WP_Error('wpsso_config_changed', __('The provider configuration changed. Start sign in again.', 'stsa-identity-sso')), $app['id']);
        }
        if (!empty($flow['test_user']) && (get_current_user_id() !== (int) $flow['test_user'] || !current_user_can('manage_options'))) {
            self::fail(new \WP_Error('wpsso_test_permission', __('The original administrator session is required to view test results.', 'stsa-identity-sso')), $app['id']);
        }
        if (self::input($request, 'error') !== '') {
            self::fail(new \WP_Error('wpsso_provider_denied', __('The identity provider declined authentication.', 'stsa-identity-sso')), $app['id']);
        }
        // OIDC authorization response issuer is optional, but if supplied is exact.
        if (self::input($request, 'iss') !== '' && !hash_equals((string) ($app['issuer'] ?? ''), self::input($request, 'iss'))) {
            self::fail(new \WP_Error('wpsso_issuer', __('The authorization response issuer is incorrect.', 'stsa-identity-sso')), $app['id']);
        }
        if (($app['grant'] ?? 'authorization_code') === 'implicit') {
            if (empty($app['legacy_grants']) || self::request_method() !== 'POST') {
                self::fail(new \WP_Error('wpsso_implicit', __('Invalid implicit callback.', 'stsa-identity-sso')), $app['id']);
            }
            $tokens = ['id_token' => self::input($request, 'id_token'), 'access_token' => self::input($request, 'access_token'), 'token_type' => self::input($request, 'token_type')];
        } else {
            $code = self::input($request, 'code');
            if ($code === '' || strlen($code) > 8192) {
                self::fail(new \WP_Error('wpsso_code', __('The callback did not contain an authorization code.', 'stsa-identity-sso')), $app['id']);
            }
            $body = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $flow['callback']];
            if (!empty($app['pkce'])) {
                $body['code_verifier'] = $flow['verifier'];
            }
            $tokens = self::token_request($app, $body);
        }
        if (is_wp_error($tokens)) {
            self::fail($tokens, $app['id']);
        }
        self::complete($app, $flow, $tokens);
    }

    /** Access-token exchange is shared by authorization-code, password and refresh. */
    private static function token_request(array $app, array $body) {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $method = $app['credential_method'] ?? 'header';
        if ($method === 'header' && !empty($app['client_secret'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode((string) $app['client_id']) . ':' . urlencode((string) $app['client_secret']));
        } else {
            $body['client_id'] = (string) ($app['client_id'] ?? '');
            if ($method === 'body' && !empty($app['client_secret'])) {
                $body['client_secret'] = $app['client_secret'];
            }
        }
        $tokens = Http::json($app['token_url'] ?? '', ['method' => 'POST', 'headers' => $headers, 'body' => http_build_query($body, '', '&', PHP_QUERY_RFC1738)]);
        if (is_wp_error($tokens)) {
            return $tokens;
        }
        if (empty($tokens['access_token']) || !is_string($tokens['access_token']) || strlen($tokens['access_token']) > 65536 || preg_match('/[\r\n]/', $tokens['access_token']) || strcasecmp((string) ($tokens['token_type'] ?? 'Bearer'), 'Bearer') !== 0) {
            return new \WP_Error('wpsso_token', __('The token endpoint returned an invalid bearer token.', 'stsa-identity-sso'));
        }
        return $tokens;
    }

    private static function attributes(array $app, array $tokens, array $flow) {
        $attributes = [];
        $oidc = ($app['protocol'] ?? 'oidc') === 'oidc';
        if ($oidc && empty($tokens['id_token'])) {
            return new \WP_Error('wpsso_id_token', __('OpenID Connect requires an ID token. Include openid in the scope.', 'stsa-identity-sso'));
        }
        if (!empty($tokens['id_token'])) {
            $context = [
                'access_token' => $tokens['access_token'] ?? '',
                'require_at_hash' => ($app['grant'] ?? '') === 'implicit',
            ];
            // The password grant has no browser authorization response and is
            // not an OIDC authentication flow. Its token response is TLS bound.
            if (($app['grant'] ?? '') !== 'password') {
                $context['nonce'] = $flow['nonce'];
            }
            $attributes = Jwt::validate($tokens['id_token'], $app, $context);
            if (is_wp_error($attributes)) {
                return $attributes;
            }
        }
        if (!empty($app['userinfo_url']) || !empty($app['groupinfo_url'])) {
            if (empty($tokens['access_token']) || !is_string($tokens['access_token']) || strlen($tokens['access_token']) > 65536 || preg_match('/[\x00-\x20\x7f]/', $tokens['access_token']) || !is_string($tokens['token_type'] ?? 'Bearer') || strcasecmp($tokens['token_type'] ?? 'Bearer', 'Bearer') !== 0) {
                return new \WP_Error('wpsso_token', __('A valid bearer token is required for user information.', 'stsa-identity-sso'));
            }
        }
        if (!empty($app['userinfo_url'])) {
            $userinfo = Http::json($app['userinfo_url'], ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']]]);
            if (is_wp_error($userinfo)) {
                return $userinfo;
            }
            if ($oidc && (!isset($userinfo['sub']) || !is_string($userinfo['sub']) || !hash_equals($attributes['sub'], $userinfo['sub']))) {
                return new \WP_Error('wpsso_subject', __('The UserInfo subject does not match the verified ID token.', 'stsa-identity-sso'));
            }
            $attributes = array_merge($attributes, $userinfo);
        }
        if (!$oidc) {
            $subject = self::claim($attributes, $app['subject_attribute'] ?? 'sub');
            if (!is_string($subject) && !is_int($subject)) {
                return new \WP_Error('wpsso_subject', __('Configure an immutable provider subject attribute.', 'stsa-identity-sso'));
            }
            if ((string) $subject === '') {
                return new \WP_Error('wpsso_subject', __('The provider did not supply an immutable subject.', 'stsa-identity-sso'));
            }
            $attributes['sub'] = (string) $subject;
        }
        if (!empty($app['groupinfo_url'])) {
            $groups = Http::json($app['groupinfo_url'], ['headers' => ['Authorization' => 'Bearer ' . ($tokens['access_token'] ?? '')]]);
            if (is_wp_error($groups)) {
                return $groups;
            }
            $attributes['_wpsso_groupinfo'] = $groups;
            if (isset($groups['groups']) && is_array($groups['groups'])) {
                $attributes['groups'] = $groups['groups'];
            } elseif (array_is_list($groups)) {
                $attributes['groups'] = $groups;
            }
        }
        return $attributes;
    }

    private static function complete(array $app, array $flow, array $tokens) {
        $attributes = self::attributes($app, $tokens, $flow);
        if (is_wp_error($attributes)) {
            self::fail($attributes, $app['id']);
        }
        if (!empty($flow['test_user'])) {
            Audit::record('test_success', $app['id'], wp_get_current_user(), '', $flow['redirect']);
            self::page(__('SSO configuration test', 'stsa-identity-sso'), '<p>' . esc_html__('Authentication succeeded. These are the attributes returned by the configured provider. No user account was created or signed in.', 'stsa-identity-sso') . '</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html(wp_json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre><p><a href="' . esc_url(admin_url('options-general.php?page=wpsso')) . '">' . esc_html__('Return to settings', 'stsa-identity-sso') . '</a></p>');
        }
        $user = Accounts::resolve($app, $attributes, $tokens);
        if (!is_wp_error($user)) {
            $user = apply_filters('wpsso_pre_authenticate', $user, $app, $attributes);
        }
        if (is_wp_error($user)) {
            self::fail($user, $app['id']);
        }
        if (!$user instanceof \WP_User || !$user->ID) {
            self::fail(new \WP_Error('wpsso_account', __('The identity could not be mapped to a user account.', 'stsa-identity-sso')), $app['id']);
        }
        $saved = self::store_tokens($user->ID, $app['id'], $tokens, $attributes['sub'] ?? '', $flow['nonce']);
        if (is_wp_error($saved)) {
            self::fail($saved, $app['id']);
        }
        update_user_meta($user->ID, self::app_meta_key(), $app['id']);
        self::remember_sid($user->ID, $app, $attributes);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, true);
        do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Required WordPress core login event for session integrations.
        do_action('wpsso_authenticated', $user, $app, $attributes);
        $redirect = apply_filters('wpsso_login_redirect', $flow['redirect'], $app, $attributes, $user);
        $redirect = Redirect::validate(is_string($redirect) ? $redirect : '');
        Audit::record('success', $app['id'], $user, '', $redirect);
        if (!empty($flow['popup'])) {
            self::popup_complete($redirect);
        }
        self::redirect($redirect);
    }

    private static function store_tokens($user_id, $app_id, array $tokens, $subject, $nonce) {
        $stored = ['_subject' => (string) $subject, '_nonce' => (string) $nonce];
        foreach (['access_token', 'refresh_token', 'id_token', 'token_type'] as $key) {
            if (isset($tokens[$key]) && is_string($tokens[$key])) {
                $stored[$key] = $tokens[$key];
            }
        }
        if (isset($tokens['expires_in']) && is_numeric($tokens['expires_in'])) {
            $stored['expires_at'] = time() + min(31536000, max(0, (int) $tokens['expires_in']));
        } elseif (isset($tokens['expires_at']) && is_int($tokens['expires_at'])) {
            $stored['expires_at'] = $tokens['expires_at'];
        }
        $encrypted = Secrets::encrypt(wp_json_encode($stored));
        if (is_wp_error($encrypted)) {
            return $encrypted;
        }
        if (!is_string($encrypted) || $encrypted === '') {
            return new \WP_Error('wpsso_token_storage', __('Encrypted token storage is unavailable.', 'stsa-identity-sso'));
        }
        if (update_user_meta($user_id, self::token_meta_key($app_id), $encrypted) === false) {
            return new \WP_Error('wpsso_token_storage', __('The encrypted session tokens could not be saved.', 'stsa-identity-sso'));
        }
        return true;
    }

    public static function refresh($user_id, $app_id) {
        global $wpdb;
        if ((int) $user_id !== get_current_user_id() && !current_user_can('manage_options')) {
            return new \WP_Error('wpsso_permission', __('You cannot refresh this user session.', 'stsa-identity-sso'));
        }
        $app = Config::app(sanitize_key($app_id));
        if (!$app || empty($app['enabled'])) {
            return new \WP_Error('wpsso_app', __('The provider is unavailable.', 'stsa-identity-sso'));
        }
        $lock = 'wpsso_refresh_' . (int) $user_id . '_' . sanitize_key($app_id);
        // Compare and delete in SQL: a cached get/delete pair can remove a new owner's lock.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $lock, time())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic lock acquisition; option cache is invalidated below.
        wp_cache_delete($lock, 'options');
        $expires = time() + 120;
        if (!add_option($lock, $expires, '', false)) {
            return new \WP_Error('wpsso_refresh_busy', __('A token refresh is already in progress.', 'stsa-identity-sso'));
        }
        try {
            $old = self::read_tokens($user_id, $app_id);
            if (empty($old['refresh_token'])) {
                return new \WP_Error('wpsso_refresh_missing', __('No refresh token is stored for this account.', 'stsa-identity-sso'));
            }
            $tokens = self::token_request($app, ['grant_type' => 'refresh_token', 'refresh_token' => $old['refresh_token']]);
            if (is_wp_error($tokens)) {
                return $tokens;
            }
            if (!empty($tokens['id_token'])) {
                $context = ['access_token' => $tokens['access_token']];
                $claims = Jwt::validate($tokens['id_token'], $app, $context);
                if (is_wp_error($claims)) {
                    return $claims;
                }
                if (!hash_equals((string) ($old['_subject'] ?? ''), $claims['sub']) || (isset($claims['nonce']) && (!is_string($claims['nonce']) || !hash_equals((string) ($old['_nonce'] ?? ''), $claims['nonce'])))) {
                    return new \WP_Error('wpsso_refresh_subject', __('The refreshed identity differs from the stored account.', 'stsa-identity-sso'));
                }
            }
            $merged = array_merge($old, $tokens);
            $saved = self::store_tokens($user_id, $app_id, $merged, $old['_subject'] ?? '', $old['_nonce'] ?? '');
            if (is_wp_error($saved)) {
                return $saved;
            }
            Audit::record('refresh', $app_id, get_user_by('id', $user_id));
            return $merged;
        } finally {
            $wpdb->delete($wpdb->options, ['option_name' => $lock, 'option_value' => (string) $expires], ['%s', '%s']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Release only this refresh owner's lock, then invalidate the option cache.
            wp_cache_delete($lock, 'options');
        }
    }

    private static function read_tokens($user_id, $app_id) {
        $encrypted = get_user_meta($user_id, self::token_meta_key($app_id), true);
        if (!is_string($encrypted) || $encrypted === '') {
            return [];
        }
        $plain = Secrets::decrypt($encrypted);
        if (!is_string($plain)) {
            return [];
        }
        $tokens = json_decode($plain, true);
        return is_array($tokens) ? $tokens : [];
    }

    private static function password_form($state, array $app) {
        $action = add_query_arg('wpsso_action', 'password', home_url('/'));
        $html = '<p>' . esc_html__('This provider uses the legacy password grant. Your credentials are sent to the configured identity provider over HTTPS.', 'stsa-identity-sso') . '</p><form method="post" action="' . esc_url($action) . '"><input type="hidden" name="state" value="' . esc_attr($state) . '"><p><label>' . esc_html__('Provider username', 'stsa-identity-sso') . '<br><input name="username" autocomplete="username" required maxlength="512"></label></p><p><label>' . esc_html__('Provider password', 'stsa-identity-sso') . '<br><input type="password" name="password" autocomplete="current-password" required maxlength="4096"></label></p><button type="submit">' . esc_html__('Sign in', 'stsa-identity-sso') . '</button></form>';
        self::page($app['name'] ?? __('Sign in', 'stsa-identity-sso'), $html);
    }

    private static function password() {
        if (self::request_method() !== 'POST') {
            self::fail(new \WP_Error('wpsso_method', __('A POST request is required.', 'stsa-identity-sso')));
        }
        $request = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The password form is protected by single-use state bound to its HttpOnly browser cookie, verified next.
        $flow = self::consume_flow(self::input($request, 'state'), 'login');
        if (is_wp_error($flow)) {
            self::fail($flow);
        }
        $app = Config::app($flow['app']);
        if (!$app || empty($app['enabled']) || ($app['grant'] ?? '') !== 'password' || empty($app['legacy_grants'])) {
            self::fail(new \WP_Error('wpsso_legacy_disabled', __('The password grant is disabled.', 'stsa-identity-sso')));
        }
        if (!hash_equals((string) ($flow['config_hash'] ?? ''), self::config_hash($app))) {
            self::fail(new \WP_Error('wpsso_config_changed', __('The provider configuration changed. Start sign in again.', 'stsa-identity-sso')), $app['id']);
        }
        if (!empty($flow['test_user']) && (get_current_user_id() !== (int) $flow['test_user'] || !current_user_can('manage_options'))) {
            self::fail(new \WP_Error('wpsso_test_permission', __('The original administrator session is required.', 'stsa-identity-sso')));
        }
        $username = self::input($request, 'username');
        $password = self::input($request, 'password');
        if ($username === '' || $password === '' || strlen($username) > 512 || strlen($password) > 4096) {
            self::fail(new \WP_Error('wpsso_credentials', __('Enter a valid provider username and password.', 'stsa-identity-sso')), $app['id']);
        }
        // Endpoint credentials never enter user metadata, audit logs or error text.
        $tokens = self::token_request($app, ['grant_type' => 'password', 'username' => $username, 'password' => $password, 'scope' => $app['scope'] ?? '', 'nonce' => $flow['nonce']]);
        unset($password, $request['password'], $_POST['password']);
        if (is_wp_error($tokens)) {
            self::fail($tokens, $app['id']);
        }
        self::complete($app, $flow, $tokens);
    }

    private static function logout() {
        if (!is_user_logged_in()) {
            self::redirect(Redirect::validate((string) Config::get('logout_redirect', home_url('/'))));
        }
        $request = self::request_method() === 'POST' ? $_POST : $_GET;
        if (!wp_verify_nonce(sanitize_text_field(self::input($request, '_wpnonce')), 'wpsso_logout')) {
            self::fail(new \WP_Error('wpsso_logout_nonce', __('The logout link has expired. Please try again.', 'stsa-identity-sso')));
        }
        $redirect = self::input($request, 'redirect_to');
        $redirect = Redirect::validate($redirect !== '' ? $redirect : (string) Config::get('logout_redirect', home_url('/')));
        if (Config::get('logout_confirmation', true) && self::input($request, 'confirm') !== '1') {
            $html = '<form method="post" action="' . esc_url(add_query_arg('wpsso_action', 'logout', home_url('/'))) . '"><input type="hidden" name="confirm" value="1"><input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('wpsso_logout')) . '"><input type="hidden" name="redirect_to" value="' . esc_attr($redirect) . '"><p>' . esc_html__('Sign out of this site and your identity provider?', 'stsa-identity-sso') . '</p><button type="submit">' . esc_html__('Sign out', 'stsa-identity-sso') . '</button></form>';
            self::page(__('Confirm sign out', 'stsa-identity-sso'), $html);
        }
        $user = wp_get_current_user();
        $app_id = get_user_meta($user->ID, self::app_meta_key(), true);
        $app = Config::app(is_string($app_id) ? $app_id : '');
        $tokens = $app ? self::read_tokens($user->ID, $app['id']) : [];
        $state = '';
        $callback = add_query_arg('wpsso_action', 'logout_callback', home_url('/'));
        if ($app && !empty($app['logout_url']) && self::https_endpoint($app['logout_url'])) {
            $state = self::create_flow(['kind' => 'logout', 'app' => $app['id'], 'redirect' => $redirect, 'callback' => $callback]);
        }
        if ($app) {
            delete_user_meta($user->ID, self::token_meta_key($app['id']));
        }
        Audit::record('logout', $app['id'] ?? '', $user, '', $redirect);
        wp_logout();
        if (is_string($state) && $state !== '') {
            $params = ['post_logout_redirect_uri' => $callback, 'state' => $state, 'client_id' => $app['client_id'] ?? ''];
            if (!empty($tokens['id_token'])) {
                $params['id_token_hint'] = $tokens['id_token'];
            }
            self::send_redirect(add_query_arg($params, $app['logout_url']));
        }
        self::redirect($redirect);
    }

    private static function logout_callback() {
        $flow = self::consume_flow(self::input($_GET, 'state'), 'logout'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Provider logout return verifies its one-use browser-bound state instead of a WordPress nonce.
        if (is_wp_error($flow)) {
            self::fail($flow);
        }
        self::redirect(Redirect::validate($flow['redirect']));
    }

    private static function identity_scope(array $app) {
        return get_current_blog_id() . '|' . $app['id'] . '|' . ($app['issuer'] ?? '') . '|' . ($app['client_id'] ?? '');
    }

    /** Store only a sid digest, scoped exactly like the account identity. */
    public static function remember_sid($user_id, array $app, array $attributes) {
        if (empty($app['backchannel_logout']) || !isset($attributes['sid']) || !is_string($attributes['sid']) || $attributes['sid'] === '' || strlen($attributes['sid']) > 1024) {
            return;
        }
        $key = '_wpsso_sid_' . hash('sha256', self::identity_scope($app));
        $digest = hash('sha256', $attributes['sid']);
        $existing = get_user_meta($user_id, $key, false);
        if (!in_array($digest, $existing, true)) {
            // Keep the bounded set of active/recent provider sessions. No bearer
            // token, ID token or raw provider session identifier is indexed.
            if (count($existing) >= 100) {
                // Never forget a sid while leaving its WordPress session live.
                // At the cap, revoke the old sessions before recording the new one.
                \WP_Session_Tokens::get_instance($user_id)->destroy_all();
                delete_user_meta($user_id, $key);
            }
            add_user_meta($user_id, $key, $digest, false);
        }
    }

    private static function backchannel_endpoint() {
        if (self::request_method() !== 'POST') {
            wp_send_json(['error' => 'invalid_request'], 400);
        }
        $result = self::backchannel_logout(sanitize_key(self::input($_GET, 'app')), self::input($_POST, 'logout_token')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- This server-to-server endpoint requires a validated signed OIDC logout JWT and replay-protected jti; it has no browser nonce.
        if (is_wp_error($result)) {
            wp_send_json(['error' => 'invalid_request', 'error_description' => $result->get_error_code()], 400);
        }
        status_header(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo '{}';
        exit;
    }

    /** A signed logout token is the authorization; no browser session is needed. */
    public static function backchannel_logout($app_id, $logout_token) {
        $app = Config::app(sanitize_key($app_id));
        if (!$app || empty($app['enabled']) || empty($app['backchannel_logout']) || ($app['protocol'] ?? '') !== 'oidc') {
            return new \WP_Error('wpsso_backchannel_disabled', __('Back-channel logout is disabled for this provider.', 'stsa-identity-sso'));
        }
        $claims = Jwt::validate($logout_token, $app, ['profile' => 'logout']);
        if (is_wp_error($claims)) {
            Audit::record('failure', $app['id'], null, $claims->get_error_code());
            return $claims;
        }
        $scope = self::identity_scope($app);
        $replay = 'wpsso_logout_' . hash('sha256', $scope . '|' . $claims['jti']);
        if (!add_option($replay, time() + 660, '', false)) {
            return new \WP_Error('wpsso_logout_replay', __('The logout event has already been processed.', 'stsa-identity-sso'));
        }
        $scope_hash = hash('sha256', $scope);
        $identity_key = '_wpsso_identity_' . $scope_hash;
        $sid_key = '_wpsso_sid_' . $scope_hash;
        $query = ['blog_id' => 0, 'number' => 1000];
        if (isset($claims['sid'])) {
            $query['meta_key'] = $sid_key;
            $query['meta_value'] = hash('sha256', $claims['sid']);
        } else {
            $query['meta_key'] = $identity_key;
            $query['meta_value'] = hash('sha256', $claims['sub']);
        }
        foreach (get_users($query) as $user) {
            // When the provider supplies both values, both must match.
            if (isset($claims['sub']) && !hash_equals((string) get_user_meta($user->ID, $identity_key, true), hash('sha256', $claims['sub']))) {
                continue;
            }
            \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            delete_user_meta($user->ID, self::token_meta_key($app['id']));
            delete_user_meta($user->ID, $sid_key);
            Audit::record('logout', $app['id'], $user, 'wpsso_backchannel_logout');
            do_action('wpsso_backchannel_logout', $user, $app, $claims);
        }
        return true;
    }

    /** State is random even when an administrator configures extra state data. */
    public static function create_flow(array $flow, $extra_state = '') {
        $state = Jwt::encode(random_bytes(32));
        if ($extra_state !== '') {
            $state .= '.' . Jwt::encode(substr($extra_state, 0, 256));
        }
        $browser = Jwt::encode(random_bytes(32));
        $flow['expires'] = time() + self::TTL;
        $flow['browser'] = hash('sha256', $browser);
        $flow['blog_id'] = get_current_blog_id();
        $key = 'wpsso_state_' . hash('sha256', $state);
        if (!add_option($key, $flow, '', false)) {
            return new \WP_Error('wpsso_state_store', __('The login state could not be stored.', 'stsa-identity-sso'));
        }
        if (headers_sent() || !setcookie(self::cookie_name($state), $browser, ['expires' => time() + self::TTL, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'])) {
            delete_option($key);
            return new \WP_Error('wpsso_cookie', __('The browser login cookie could not be created.', 'stsa-identity-sso'));
        }
        return $state;
    }

    /** Validate before consuming; atomic DB deletion prevents parallel replay. */
    public static function consume_flow($state, $kind) {
        global $wpdb;
        if (!is_string($state) || strlen($state) > 512 || !preg_match('/^[A-Za-z0-9_-]{43}(?:\.[A-Za-z0-9_-]+)?$/D', $state)) {
            return new \WP_Error('wpsso_state', __('The login state is missing or invalid. Start sign in again.', 'stsa-identity-sso'));
        }
        $key = 'wpsso_state_' . hash('sha256', $state);
        $flow = get_option($key);
        $browser = self::input($_COOKIE, self::cookie_name($state));
        if (!is_array($flow) || ($flow['kind'] ?? '') !== $kind || ($flow['expires'] ?? 0) < time() || ($flow['blog_id'] ?? 0) !== get_current_blog_id() || $browser === '' || !hash_equals((string) ($flow['browser'] ?? ''), hash('sha256', $browser))) {
            return new \WP_Error('wpsso_state', __('The login state expired or belongs to a different browser. Start sign in again.', 'stsa-identity-sso'));
        }
        $deleted = $wpdb->delete($wpdb->options, ['option_name' => $key], ['%s']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic deletion makes OAuth state single use across concurrent requests; invalidate cache below.
        wp_cache_delete($key, 'options');
        if ($deleted !== 1) {
            return new \WP_Error('wpsso_state_replay', __('This login state has already been used.', 'stsa-identity-sso'));
        }
        setcookie(self::cookie_name($state), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        return $flow;
    }

    public static function cleanup() {
        global $wpdb;
        $like = $wpdb->esc_like('wpsso_state_') . '%';
        $logout_like = $wpdb->esc_like('wpsso_logout_') . '%';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 2000', $wpdb->options, $like, $logout_like)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron must inspect current expiring security records; deletion uses the option API below.
        foreach ($rows as $row) {
            $value = maybe_unserialize($row->option_value);
            $expires = is_array($value) ? ($value['expires'] ?? 0) : (int) $value;
            if ($expires < time()) {
                delete_option($row->option_name);
            }
        }
    }

    private static function fragment_relay() {
        $html = '<p id="wpsso-relay-status">' . esc_html__('Completing sign in…', 'stsa-identity-sso') . '</p><form id="wpsso-relay" method="post" action="' . esc_url(add_query_arg('wpsso_action', 'callback', home_url('/'))) . '"></form><noscript>' . esc_html__('JavaScript is required to complete legacy implicit sign in.', 'stsa-identity-sso') . '</noscript>';
        self::page(__('Completing sign in', 'stsa-identity-sso'), $html, 'relay', '', __('No login response was received. Start sign in again.', 'stsa-identity-sso'));
    }

    private static function popup_complete($redirect) {
        $html = '<p>' . esc_html__('Sign in completed. You can close this window.', 'stsa-identity-sso') . '</p><p><a href="' . esc_url($redirect) . '">' . esc_html__('Continue', 'stsa-identity-sso') . '</a></p>';
        self::page(__('Signed in', 'stsa-identity-sso'), $html, 'popup', Redirect::validate($redirect));
    }

    private static function input(array $values, $key) {
        // OAuth codes, tokens and passwords are opaque. Do not alter their bytes
        // with text sanitizers; validate type/size here and the protocol at use.
        return isset($values[$key]) && is_string($values[$key]) && strlen($values[$key]) <= 131072 ? wp_unslash($values[$key]) : '';
    }

    private static function request_method() {
        return isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
    }

    private static function claim(array $attributes, $path) {
        $value = $attributes;
        foreach (explode('.', (string) $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private static function cookie_name($state) {
        return '__Host-wpsso_' . substr(hash('sha256', $state), 0, 24);
    }

    private static function origin($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    private static function https_endpoint($url) {
        $parts = wp_parse_url((string) $url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    private static function config_hash(array $app) {
        return hash('sha256', wp_json_encode($app));
    }

    private static function redirect($url) {
        // Redirect::validate applies the explicit allowlist, including external origins.
        self::send_redirect(Redirect::validate($url));
    }

    /** Call only after provider-endpoint or user-redirect allowlist validation. */
    private static function send_redirect($url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $allow = static function ($hosts) use ($host) {
            return is_string($host) && $host !== '' ? array_unique(array_merge($hosts, [$host])) : $hosts;
        };
        add_filter('allowed_redirect_hosts', $allow);
        try {
            wp_safe_redirect($url, 302, 'STSA Identity SSO');
        } finally {
            remove_filter('allowed_redirect_hosts', $allow);
        }
        exit;
    }

    private static function fail($error, $app = '') {
        Audit::record('failure', $app, is_user_logged_in() ? wp_get_current_user() : null, $error->get_error_code());
        wp_die(esc_html($error->get_error_message()) . '<p><code>' . esc_html($error->get_error_code()) . '</code></p>', esc_html__('SSO sign in failed', 'stsa-identity-sso'), ['response' => 403]);
        exit;
    }

    /** Minimal standalone protocol page; only local executable code is allowed. */
    private static function page($title, $html, $mode = '', $redirect = '', $no_response = '') {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private, max-age=0');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html($title) . '</title></head><body data-wpsso-mode="' . esc_attr($mode) . '" data-wpsso-redirect="' . esc_attr($redirect) . '" data-wpsso-origin="' . esc_attr(self::origin(home_url('/'))) . '" data-wpsso-no-response="' . esc_attr($no_response) . '" style="font:16px/1.6 system-ui,sans-serif;max-width:850px;margin:3rem auto;padding:1rem"><h1>' . esc_html($title) . '</h1>' . wp_kses($html, self::page_allowed_html());
        if (in_array($mode, ['relay', 'popup'], true)) {
            wp_enqueue_script('wpsso-auth', WPSSO_URL . 'assets/auth.js', [], WPSSO_VERSION, true);
            wp_print_scripts('wpsso-auth');
        }
        echo '</body></html>';
        exit;
    }

    private static function page_allowed_html() {
        return [
            'p' => ['id' => true], 'br' => [], 'pre' => ['style' => true],
            'a' => ['href' => true], 'noscript' => [],
            'form' => ['id' => true, 'method' => true, 'action' => true],
            'label' => [], 'button' => ['type' => true],
            'input' => ['type' => true, 'name' => true, 'value' => true, 'autocomplete' => true, 'required' => true, 'maxlength' => true],
        ];
    }
}
