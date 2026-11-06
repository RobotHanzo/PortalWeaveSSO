<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Administrative configuration, reporting, and content policy controls. */
final class Admin {
    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('network_admin_menu', [self::class, 'network_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_init', [self::class, 'redirect_legacy_page']);
        foreach (['save', 'export', 'import', 'support'] as $action) {
            add_action('admin_post_portalweave_sso_' . $action, [self::class, $action]);
        }
        add_action('add_meta_boxes', [self::class, 'meta_boxes']);
        add_action('save_post', [self::class, 'save_post'], 10, 2);
        add_action('category_add_form_fields', [self::class, 'category_add']);
        add_action('category_edit_form_fields', [self::class, 'category_edit']);
        add_action('created_category', [self::class, 'save_category']);
        add_action('edited_category', [self::class, 'save_category']);
        add_filter('attachment_fields_to_edit', [self::class, 'attachment_fields'], 10, 2);
        add_filter('attachment_fields_to_save', [self::class, 'attachment_save'], 10, 2);
    }

    public static function menu(): void {
        add_menu_page(__('PortalWeave SSO', 'portalweave-sso'), __('PortalWeave SSO', 'portalweave-sso'), 'manage_options', 'portalweave-sso', [self::class, 'page'], 'dashicons-shield');
    }

    public static function network_menu(): void {
        add_menu_page(__('PortalWeave SSO for networks', 'portalweave-sso'), __('PortalWeave SSO', 'portalweave-sso'), 'manage_network_options', 'portalweave-sso', [self::class, 'page'], 'dashicons-shield');
    }

    /** Preserve old bookmarks without asking WordPress to render a removed submenu. */
    public static function redirect_legacy_page(): void {
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Capability-gated, read-only bookmark navigation; no settings are read from the request or mutated.
        if ($pagenow !== 'options-general.php' || is_network_admin() || !current_user_can('manage_options') || self::request_key($_SERVER, 'REQUEST_METHOD') !== 'get' || self::request_key($_GET, 'page') !== 'portalweave-sso') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only an allowlisted local task selects the canonical destination.
        $screen = self::request_key($_GET, 'tab');
        $args = [];
        foreach (['provider', 'provider_section', 'new_provider', 'setup_provider', 'activity_view', 'activity_days', 'appearance_section', 'integration_section', 'audit_page', 'audit_snapshot', 'portalweave_sso_notice'] as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Scalar local navigation keys only; each screen validates its own presentation values.
            $value = self::request_key($_GET, $key);
            if ($value !== '') $args[$key] = $value;
        }
        wp_safe_redirect(self::screen_url($screen, false, $args));
        exit;
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'portalweave-sso') === false) {
            return;
        }
        $version = defined('PORTALWEAVE_SSO_VERSION') ? PORTALWEAVE_SSO_VERSION : '1.1.0';
        $style = dirname(__DIR__) . '/assets/admin.css';
        $script = dirname(__DIR__) . '/assets/admin.js';
        // File revisions also refresh assets when administrators test an unpublished build at the same plugin version.
        $style_version = $version . '-' . (is_file($style) ? (string) filemtime($style) : '0');
        $script_version = $version . '-' . (is_file($script) ? (string) filemtime($script) : '0');
        wp_enqueue_style('portalweave-sso-admin', plugins_url('../assets/admin.css', __FILE__), [], $style_version);
        wp_enqueue_script('portalweave-sso-admin', plugins_url('../assets/admin.js', __FILE__), [], $script_version, ['in_footer' => true, 'strategy' => 'defer']);
        wp_enqueue_style('portalweave-sso-unsaved', plugins_url('../assets/admin-unsaved.css', __FILE__), ['portalweave-sso-admin'], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/admin-unsaved.css'));
        wp_enqueue_script('portalweave-sso-unsaved', plugins_url('../assets/admin-unsaved.js', __FILE__), ['portalweave-sso-admin'], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/admin-unsaved.js'), ['in_footer' => true, 'strategy' => 'defer']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An allowlisted screen loads local presentation assets; all writes retain their own permission and nonce checks.
        if (self::request_key($_GET, 'tab') === 'modules') {
            wp_enqueue_style('portalweave-sso-integrations', plugins_url('../assets/integrations.css', __FILE__), ['portalweave-sso-admin'], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/integrations.css'));
            wp_enqueue_script('portalweave-sso-integrations', plugins_url('../assets/integrations.js', __FILE__), [], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/integrations.js'), ['in_footer' => true, 'strategy' => 'defer']);
        }
        wp_enqueue_style('portalweave-sso-mappings', plugins_url('../assets/admin-mappings.css', __FILE__), ['portalweave-sso-admin'], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/admin-mappings.css'));
        wp_enqueue_script('portalweave-sso-mappings', plugins_url('../assets/admin-mappings.js', __FILE__), ['portalweave-sso-admin'], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/admin-mappings.js'), ['in_footer' => true, 'strategy' => 'defer']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider navigation loads local image controls; settings saves and WordPress uploads retain their own authorization.
        if (self::request_key($_GET, 'tab') === 'providers' && self::request_key($_GET, 'provider') !== '' && self::request_key($_GET, 'new_provider') !== '1' && current_user_can(is_network_admin() ? 'manage_network_options' : 'manage_options')) {
            $dependencies = [];
            if (current_user_can('upload_files')) {
                wp_enqueue_media();
                $dependencies[] = 'media-editor';
            }
            wp_enqueue_style('portalweave-sso-provider-logo', plugins_url('../assets/provider-logo.css', __FILE__), ['portalweave-sso-admin'], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/provider-logo.css'));
            wp_enqueue_script('portalweave-sso-provider-logo', plugins_url('../assets/provider-logo.js', __FILE__), $dependencies, $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/provider-logo.js'), ['in_footer' => true, 'strategy' => 'defer']);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation decides whether to load the local setup interface; saves retain their own nonce and capability checks.
        if (self::request_key($_GET, 'tab') === 'providers' && self::request_key($_GET, 'new_provider') === '1') {
            $setup_style = dirname(__DIR__) . '/assets/provider-setup.css';
            $setup_script = dirname(__DIR__) . '/assets/provider-setup.js';
            wp_enqueue_style('portalweave-sso-setup', plugins_url('../assets/provider-setup.css', __FILE__), ['portalweave-sso-admin'], $version . '-' . (string) filemtime($setup_style));
            wp_enqueue_script('portalweave-sso-setup', plugins_url('../assets/provider-setup.js', __FILE__), [], $version . '-' . (string) filemtime($setup_script), ['in_footer' => true, 'strategy' => 'defer']);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Appearance navigation loads local preview controls; form saves retain capability and nonce checks.
        if (self::request_key($_GET, 'tab') === 'appearance' && current_user_can(is_network_admin() ? 'manage_network_options' : 'manage_options')) {
            $settings = self::settings(is_network_admin());
            $button = $settings['button'];
            wp_enqueue_style('portalweave-sso-buttons', plugins_url('../assets/login.css', __FILE__), [], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/login.css'));
            $css = sprintf('#portalweave-sso-appearance-preview .portalweave-sso-login{--portalweave-sso-size:%dpx;--portalweave-sso-radius:%dpx;--portalweave-sso-spacing:%dpx}', $button['size'], $button['radius'], $button['spacing']);
            wp_add_inline_style('portalweave-sso-buttons', $css . "\n" . Display::wp_login_css($settings['wp_login_button']));
            $dependencies = [];
            if (current_user_can('upload_files')) {
                wp_enqueue_media();
                $dependencies[] = 'media-editor';
            }
            wp_enqueue_style('portalweave-sso-appearance', plugins_url('../assets/appearance.css', __FILE__), ['portalweave-sso-admin', 'portalweave-sso-buttons'], $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/appearance.css'));
            wp_enqueue_script('portalweave-sso-appearance', plugins_url('../assets/appearance.js', __FILE__), $dependencies, $version . '-' . (string) filemtime(dirname(__DIR__) . '/assets/appearance.js'), ['in_footer' => true, 'strategy' => 'defer']);
        }
    }

    private static function is_network_request(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects a capability and settings scope; every mutation separately verifies its nonce.
        return is_multisite() && isset($_REQUEST['network']) && is_string($_REQUEST['network']) && $_REQUEST['network'] === '1';
    }

    private static function require_access(): bool {
        $network = self::is_network_request();
        if (!current_user_can($network ? 'manage_network_options' : 'manage_options')) {
            wp_die(esc_html__('You cannot manage SSO settings.', 'portalweave-sso'), '', ['response' => 403]);
        }
        return $network;
    }

    /** Core checks the nonce after this type guard, without casting arrays to strings. */
    private static function require_nonce_type(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Each caller immediately verifies this nonce with check_admin_referer().
        if (!isset($_REQUEST['_wpnonce']) || !is_string($_REQUEST['_wpnonce'])) {
            wp_die(esc_html__('The form security token is invalid. Reload the settings page and try again.', 'portalweave-sso'), '', ['response' => 403]);
        }
    }

    private static function settings(bool $network): array {
        if (!$network) {
            return Config::all();
        }
        $stored = get_site_option('portalweave_sso_settings', []);
        $stored = is_array($stored) ? $stored : [];
        $settings = array_replace_recursive(Config::defaults(), $stored);
        // Do not inherit providers from a site into network administration.
        $settings['apps'] = $stored['apps'] ?? [];
        return $settings;
    }

    private static function return_url(bool $network, string $notice = ''): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Navigation context is allowlisted and all POST action callers verify their own nonce before mutating data.
        $screen = self::request_key($_POST, 'portalweave_sso_screen');
        $args = [];
        if ($screen === 'providers') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- These validated keys select only a local editor destination.
            $provider = self::request_key($_POST, 'portalweave_sso_provider');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This flag changes only local navigation.
            $creating = self::request_key($_POST, 'portalweave_sso_creating') === '1';
            $args = $creating ? ['new_provider' => '1'] : ($provider !== '' ? ['provider' => $provider] : []);
            if (!$creating && $provider !== '') {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only an allowlisted local navigation section is retained; callers verify the form nonce.
                $args['provider_section'] = self::provider_section($_POST, 'portalweave_sso_provider_section');
            }
            if ($creating) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve only a catalog-validated provider choice in a local error redirect; no credentials or form state enter the URL.
                $selection = isset($_POST['settings']) && is_array($_POST['settings']) && isset($_POST['settings']['provider_setup']) && is_array($_POST['settings']['provider_setup']) && isset($_POST['settings']['provider_setup']['provider']) && is_string($_POST['settings']['provider_setup']['provider']) ? sanitize_key(wp_unslash($_POST['settings']['provider_setup']['provider'])) : '';
                if (isset(ProviderSetup::catalog()[$selection])) {
                    $args['setup_provider'] = $selection;
                }
            }
        }
        if ($screen === 'reports') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only allowlisted presentation context is retained; callers verify the action nonce.
            $args['activity_view'] = self::activity_view($_POST, 'portalweave_sso_activity_view', 'audit');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The period is a bounded navigation value, never a settings mutation.
            $args['activity_days'] = self::activity_days($_POST, 'portalweave_sso_activity_days');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Bounded audit navigation is retained only after the action's nonce checks and is never stored.
            $args['audit_page'] = self::audit_number($_POST, 'portalweave_sso_audit_page', 1);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This integer ceiling chooses only a local retained-history view.
            $args['audit_snapshot'] = self::audit_number($_POST, 'portalweave_sso_audit_snapshot');
        }
        if ($screen === 'appearance') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- An allowlisted presentation tab is retained only after the action's nonce checks.
            $args['appearance_section'] = self::appearance_section($_POST, 'portalweave_sso_appearance_section');
        }
        if ($screen === 'modules') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only a known integration panel is retained after the action's nonce checks.
            $args['integration_section'] = self::integration_section($_POST, 'portalweave_sso_integration_section');
        }
        $url = self::screen_url($screen ?: 'overview', $network, $args);
        return $notice === '' ? $url : add_query_arg('portalweave_sso_notice', $notice, $url);
    }

    private static function request_key(array $values, string $key): string {
        return isset($values[$key]) && is_string($values[$key]) ? sanitize_key(wp_unslash($values[$key])) : '';
    }

    private static function screens(): array {
        return [
            'overview' => [__('Overview', 'portalweave-sso'), 'dashboard'],
            'providers' => [__('Identity providers', 'portalweave-sso'), 'shield'],
            'login' => [__('Login & logout', 'portalweave-sso'), 'lock'],
            'access' => [__('Access policies', 'portalweave-sso'), 'admin-network'],
            'appearance' => [__('Appearance', 'portalweave-sso'), 'art'],
            'modules' => [__('Integrations', 'portalweave-sso'), 'admin-plugins'],
            'reports' => [__('Activity', 'portalweave-sso'), 'chart-bar'],
            'tools' => [__('Tools & support', 'portalweave-sso'), 'admin-tools'],
        ];
    }

    private static function provider_sections(): array {
        return [
            'connection' => __('Connection', 'portalweave-sso'),
            'endpoints' => __('Endpoints', 'portalweave-sso'),
            'security' => __('Security', 'portalweave-sso'),
            'mapping' => __('Mapping', 'portalweave-sso'),
            'accounts' => __('Accounts', 'portalweave-sso'),
            'integrations' => __('Integrations', 'portalweave-sso'),
            'button' => __('Login button', 'portalweave-sso'),
        ];
    }

    private static function provider_section(array $values, string $key): string {
        $section = self::request_key($values, $key);
        return isset(self::provider_sections()[$section]) ? $section : 'connection';
    }

    private static function appearance_section(array $values, string $key): string {
        $section = self::request_key($values, $key);
        return in_array($section, ['shared', 'wordpress'], true) ? $section : 'shared';
    }

    private static function integration_section(array $values, string $key): string {
        $section = self::request_key($values, $key);
        return in_array($section, ['scim', 'jwt_sso', 'shared_sso', 'guest', 'moderation', 'avatar', 'attribute_redirect', 'abilities'], true) ? $section : 'scim';
    }

    private static function activity_view(array $values, string $key, string $default = 'overview'): string {
        $view = self::request_key($values, $key);
        return in_array($view, ['overview', 'audit'], true) ? $view : $default;
    }

    private static function activity_days(array $values, string $key): int {
        $days = self::request_key($values, $key);
        return in_array($days, ['7', '30', '90'], true) ? (int) $days : 30;
    }

    private static function audit_number(array $values, string $key, int $fallback = 0): int {
        $value = $values[$key] ?? null;
        return is_string($value) && strlen($value) <= 20 && ctype_digit($value) && (int) $value > 0 ? (int) $value : $fallback;
    }

    private static function screen_url(string $screen, bool $network, array $args = []): string {
        $screen = isset(self::screens()[$screen]) ? $screen : 'overview';
        $base = $network ? network_admin_url('admin.php') : admin_url('admin.php');
        return add_query_arg(array_merge(['page' => 'portalweave-sso', 'tab' => $screen], $args), $base);
    }

    private static function fail($error, bool $network): void {
        $message = is_wp_error($error) ? $error->get_error_message() : (string) $error;
        set_transient('portalweave_sso_admin_error_' . get_current_user_id(), $message, 60);
        wp_safe_redirect(self::return_url($network, 'error'));
        exit;
    }

    public static function save(): void {
        $network = self::require_access();
        self::require_nonce_type();
        check_admin_referer('portalweave_sso_save');
        if (!isset($_POST['portalweave_sso_complete']) || !is_string($_POST['portalweave_sso_complete']) || sanitize_text_field(wp_unslash($_POST['portalweave_sso_complete'])) !== '1') {
            self::fail(__('The settings form was truncated. Increase PHP max_input_vars for this number of providers, then retry.', 'portalweave-sso'), $network);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Form types and permitted keys are validated below; Config::save() sanitizes each setting without corrupting credentials or JSON.
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? self::form_settings(wp_unslash($_POST['settings'])) : [];
        if (is_wp_error($input)) {
            self::fail($input, $network);
        }
        if (!$input) {
            self::fail(__('No settings were submitted. Check the server POST size limit.', 'portalweave-sso'), $network);
        }
        foreach (['portalweave_sso_screen', 'portalweave_sso_provider', 'portalweave_sso_creating', 'portalweave_sso_provider_section', 'portalweave_sso_activity_view', 'portalweave_sso_activity_days', 'portalweave_sso_appearance_section', 'portalweave_sso_integration_section', 'portalweave_sso_audit_page', 'portalweave_sso_audit_snapshot'] as $key) {
            if (isset($_POST[$key]) && !is_string($_POST[$key])) {
                self::fail(__('The settings screen is invalid. Reload the page and try again.', 'portalweave-sso'), $network);
            }
        }
        $screen = self::request_key($_POST, 'portalweave_sso_screen');
        $provider = self::request_key($_POST, 'portalweave_sso_provider');
        $creating = self::request_key($_POST, 'portalweave_sso_creating') === '1';
        $providers = $input['apps'] ?? [];
        if (isset($input['new_app'])) {
            $providers[''] = $input['new_app'];
        }
        foreach ($providers as $id => &$app) {
            if (in_array($app['_delete'] ?? false, [true, 1, '1'], true)) {
                continue;
            }
            foreach (['mapping', 'custom_mapping', 'role_mapping', 'domain_roles', 'domain_mapping', 'redirect_rules'] as $key) {
                if (isset($app[$key]) && is_string($app[$key])) {
                    $decoded = self::decode_json($app[$key], $key);
                    if (is_wp_error($decoded)) {
                        self::fail($decoded, $network);
                    }
                    $app[$key] = $decoded;
                }
            }
            if (isset($app['algorithms'])) {
                $app['algorithms'] = self::lines($app['algorithms']);
            }
            if (isset($app['verified_value'])) {
                $value = json_decode($app['verified_value'], true);
                if (json_last_error() !== JSON_ERROR_NONE || is_array($value)) {
                    self::fail(__('Verified claim value must be a JSON scalar, for example true or "verified".', 'portalweave-sso'), $network);
                }
                $app['verified_value'] = $value;
            }
        }
        unset($app);
        if (isset($input['apps'])) {
            $input['apps'] = array_intersect_key($providers, $input['apps']);
        }
        if (isset($input['new_app'])) {
            $input['new_app'] = $providers[''];
        }
        foreach (['redirect_allowlist', 'allowed_domains', 'blocked_domains', 'shared_sites'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = self::lines($input[$key]);
            }
        }
        if (isset($input['content_rules']) && is_string($input['content_rules'])) {
            $input['content_rules'] = self::decode_json($input['content_rules'], 'content_rules');
            if (is_wp_error($input['content_rules'])) {
                self::fail($input['content_rules'], $network);
            }
        }
        if (isset($input['protected_media']) && is_string($input['protected_media'])) {
            $input['protected_media'] = array_values(array_filter(array_map('absint', preg_split('/[\s,]+/', $input['protected_media']))));
        }
        $prepared = AdminSettings::prepare($input, $screen, $provider, $creating, $network);
        if (is_wp_error($prepared)) {
            self::fail($prepared, $network);
        }
        $result = Config::save($prepared, $network);
        if (is_wp_error($result)) {
            self::fail($result, $network);
        }
        if ($screen === 'providers') {
            $id = $creating ? sanitize_key($input['provider_setup']['id'] ?? $input['new_app']['id'] ?? '') : $provider;
            $deleted = in_array($input['apps'][$provider]['_delete'] ?? false, [true, 1, '1'], true);
            $args = $deleted ? [] : ['provider' => $id];
            if (!$deleted && !$creating) {
                $args['provider_section'] = self::provider_section($_POST, 'portalweave_sso_provider_section');
            }
            wp_safe_redirect(self::screen_url('providers', $network, ['portalweave_sso_notice' => 'saved'] + $args));
        } else {
            wp_safe_redirect(self::return_url($network, 'saved'));
        }
        exit;
    }

    /** Accept only the shape generated by the HTML form, before decoding JSON. */
    private static function form_settings(array $input) {
        $allowed = Config::defaults() + ['new_app' => [], 'provider_setup' => [], 'clear_scim_token' => false, 'clear_shared_secret' => false];
        $input = array_intersect_key($input, $allowed);
        foreach ($input as $key => &$value) {
            if ($key === 'provider_setup') {
                if (!is_array($value)) {
                    return new \WP_Error('portalweave_sso_form_type', __('Invalid guided setup fields.', 'portalweave-sso'));
                }
                foreach ($value as $field) {
                    if (!is_string($field)) {
                        return new \WP_Error('portalweave_sso_form_type', __('Guided setup fields must be strings.', 'portalweave-sso'));
                    }
                }
            } elseif ($key === 'apps' || $key === 'new_app') {
                if (!is_array($value) || ($key === 'apps' && count($value) > 100)) {
                    return new \WP_Error('portalweave_sso_form_type', __('Invalid provider form fields.', 'portalweave-sso'));
                }
                $providers = $key === 'new_app' ? ['new' => $value] : $value;
                foreach ($providers as &$provider) {
                    if (!is_array($provider)) {
                        return new \WP_Error('portalweave_sso_form_type', __('Invalid provider form fields.', 'portalweave-sso'));
                    }
                    $provider = array_intersect_key($provider, Config::app_defaults() + ['_delete' => false, 'clear_client_secret' => false, 'clear_jwt_secret' => false]);
                    foreach ($provider as $field) {
                        if (!is_string($field)) {
                            return new \WP_Error('portalweave_sso_form_type', __('Provider form values must be strings.', 'portalweave-sso'));
                        }
                    }
                }
                unset($provider);
                $value = $key === 'new_app' ? $providers['new'] : $providers;
            } elseif (in_array($key, ['button', 'wp_login_button', 'support', 'modules'], true)) {
                if (!is_array($value)) {
                    return new \WP_Error('portalweave_sso_form_type', __('Invalid settings form fields.', 'portalweave-sso'));
                }
                $value = array_intersect_key($value, $allowed[$key]);
                foreach ($value as $field) {
                    if (!is_string($field)) {
                        return new \WP_Error('portalweave_sso_form_type', __('Settings form values must be strings.', 'portalweave-sso'));
                    }
                }
            } elseif (!is_string($value)) {
                return new \WP_Error('portalweave_sso_form_type', __('Settings form values must be strings.', 'portalweave-sso'));
            }
        }
        unset($value);
        return $input;
    }

    private static function decode_json(string $value, string $label) {
        if (trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            /* translators: %s: configuration field name. */
            return new \WP_Error('portalweave_sso_config_json', sprintf(__('Invalid JSON object or array for %s.', 'portalweave-sso'), $label));
        }
        return $decoded;
    }

    private static function lines($value): array {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn($item) => is_scalar($item) ? trim((string) $item) : '', $value)));
        }
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $value))));
    }

    public static function export(): void {
        $network = self::require_access();
        self::require_nonce_type();
        check_admin_referer('portalweave_sso_export');
        $kind = isset($_REQUEST['kind']) && is_string($_REQUEST['kind']) ? sanitize_key(wp_unslash($_REQUEST['kind'])) : 'configuration';
        nocache_headers();
        if ($kind === 'audit') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="portalweave-sso-audit.csv"');
            $columns = ['username', 'status', 'app', 'created_at', 'email', 'client_ip', 'navigation', 'error_code'];
            self::csv_row($columns);
            foreach (Audit::recent(['limit' => 5000]) as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = (string) ($row[$column] ?? '');
                    // Spreadsheet formulas can execute even after leading whitespace.
                    $values[] = preg_match('/^[\s]*[=+@\-]/u', $value) ? "'" . $value : $value;
                }
                self::csv_row($values);
            }
        } else {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="portalweave-sso-configuration.json"');
            echo wp_json_encode(self::redact(self::settings($network)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    private static function csv_row(array $values): void {
        $row = implode(',', array_map(static fn($value) => '"' . str_replace('"', '""', (string) $value) . '"', $values)) . "\r\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV response, not HTML; fields use RFC 4180 quoting and formula-prefixed data is neutralized by export().
        echo $row;
    }

    private static function redact(array $settings): array {
        unset($settings['scim_token'], $settings['shared_secret']);
        foreach ($settings['apps'] ?? [] as $id => $app) {
            unset($app['client_id'], $app['client_secret'], $app['jwt_secret'], $app['state_parameter']);
            foreach (['authorize_url', 'token_url', 'userinfo_url', 'groupinfo_url', 'jwks_url', 'logout_url', 'issuer', 'callback_url', 'icon_url'] as $key) {
                // Endpoints can contain tenant-supplied credentials in query parameters.
                $app[$key] = preg_replace('/[?#].*$/', '', (string) ($app[$key] ?? ''));
            }
            $settings['apps'][$id] = $app;
        }
        if (isset($settings['button']['custom_css'])) {
            $settings['button']['custom_css'] = '';
        }
        if (isset($settings['wp_login_button']['icon_url'])) {
            $settings['wp_login_button']['icon_url'] = preg_replace('/[?#].*$/', '', (string) $settings['wp_login_button']['icon_url']);
        }
        return $settings;
    }

    public static function import(): void {
        $network = self::require_access();
        self::require_nonce_type();
        check_admin_referer('portalweave_sso_import');
        if (empty($_FILES['configuration']) || !is_array($_FILES['configuration'])) {
            self::fail(__('Choose a configuration JSON file.', 'portalweave-sso'), $network);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the upload error, byte count, exact temporary-path type and PHP upload provenance before reading it.
        $file = $_FILES['configuration'];
        if (!is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_int($file['size'] ?? null) || $file['size'] < 0 || $file['size'] > 1048576 || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            self::fail(__('The upload failed or exceeds the 1 MB limit.', 'portalweave-sso'), $network);
        }
        // Read only PHP's verified upload, with a hard read limit independent of client metadata.
        $raw = file_get_contents($file['tmp_name'], false, null, 0, 1048577);
        if (!is_string($raw) || strlen($raw) > 1048576) {
            self::fail(__('The upload failed or exceeds the 1 MB limit.', 'portalweave-sso'), $network);
        }
        $settings = self::decode_json((string) $raw, 'configuration');
        if (is_wp_error($settings)) {
            self::fail($settings, $network);
        }
        $result = Config::import($settings, $network);
        if (is_wp_error($result)) {
            self::fail($result, $network);
        }
        wp_safe_redirect(self::return_url($network, 'imported'));
        exit;
    }

    public static function support(): void {
        $network = self::require_access();
        self::require_nonce_type();
        check_admin_referer('portalweave_sso_support');
        $support = self::settings($network)['support'] ?? [];
        $recipient = sanitize_email($support['email'] ?? '');
        $message = isset($_POST['message']) && is_string($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        if (!$recipient || !$message || strlen($message) > 10000) {
            self::fail(__('Configure a support recipient and enter a message of up to 10,000 characters.', 'portalweave-sso'), $network);
        }
        $body = $message;
        if (isset($_POST['include_configuration']) && is_string($_POST['include_configuration']) && sanitize_text_field(wp_unslash($_POST['include_configuration'])) === '1') {
            $body .= "\n\n" . __('Configuration (client IDs, secrets and tokens excluded):', 'portalweave-sso') . "\n";
            $body .= wp_json_encode(self::redact(self::settings($network)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        if (!wp_mail($recipient, __('[PortalWeave SSO] Support request', 'portalweave-sso'), $body)) {
            self::fail(__('WordPress could not hand the support message to its mail service.', 'portalweave-sso'), $network);
        }
        wp_safe_redirect(self::return_url($network, 'sent'));
        exit;
    }

    private static function hidden_scope(bool $network, string $screen = ''): void {
        if ($network) {
            echo '<input type="hidden" name="network" value="1">';
        }
        if ($screen !== '') {
            echo '<input type="hidden" name="portalweave_sso_screen" value="' . esc_attr($screen) . '">';
        }
    }

    public static function page(): void {
        $network = is_network_admin();
        if (!current_user_can($network ? 'manage_network_options' : 'manage_options')) {
            return;
        }
        $settings = self::settings($network);
        $enforced = !$network && is_multisite() && !empty(get_site_option('portalweave_sso_settings', [])['network_enforced']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation is constrained to known local screens and provider identifiers.
        $screen = self::request_key($_GET, 'tab');
        $screen = isset(self::screens()[$screen]) ? $screen : 'overview';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses an existing provider to display; saving requires capability, nonce and scoped validation.
        $provider = self::request_key($_GET, 'provider');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opens an empty editor without changing any settings.
        $creating = self::request_key($_GET, 'new_provider') === '1';
        $editing = $screen === 'providers' && ($creating || $provider !== '');
        $missing = $editing && !$creating && !isset($settings['apps'][$provider]);
        $editing = $editing && !$missing;
        $notices = ['saved' => __('Changes saved.', 'portalweave-sso'), 'imported' => __('Configuration imported. Existing credentials were retained by app ID. Incomplete restored providers and login enforcement are disabled until you configure credentials and test SSO.', 'portalweave-sso'), 'sent' => __('Support message submitted to the WordPress mail service.', 'portalweave-sso')];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects a display-only notice; error details come from the current administrator's server transient.
        $notice = self::request_key($_GET, 'portalweave_sso_notice');
        echo '<div class="wrap portalweave-sso-admin" data-unsaved-title="' . esc_attr__('Unsaved settings', 'portalweave-sso') . '" data-unsaved-message="' . esc_attr__('You have unsaved changes.', 'portalweave-sso') . '" data-unsaved-save="' . esc_attr__('Save changes', 'portalweave-sso') . '" data-unsaved-revert="' . esc_attr__('Revert all changes', 'portalweave-sso') . '"><header class="portalweave-sso-header"><div class="portalweave-sso-brand"><img class="portalweave-sso-brand-logo" src="' . esc_url(plugins_url('assets/branding/logo.png', PORTALWEAVE_SSO_FILE)) . '" srcset="' . esc_url(plugins_url('assets/branding/logo-2x.png', PORTALWEAVE_SSO_FILE)) . ' 2x" width="48" height="48" alt="" decoding="async"><div class="portalweave-sso-brand-copy"><h1>' . esc_html__('PortalWeave SSO', 'portalweave-sso') . '</h1><p>' . esc_html($network ? __('Single sign-on for your network', 'portalweave-sso') : __('Identity and access for your WordPress site', 'portalweave-sso')) . '</p></div></div><span class="portalweave-sso-version">' . esc_html(defined('PORTALWEAVE_SSO_VERSION') ? PORTALWEAVE_SSO_VERSION : '1.1.0') . '</span></header>';
        if ($notice === 'error') {
            $error = get_transient('portalweave_sso_admin_error_' . get_current_user_id());
            delete_transient('portalweave_sso_admin_error_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . esc_html(is_string($error) && $error !== '' ? $error : __('The operation could not be completed.', 'portalweave-sso')) . '</p></div>';
        } elseif (isset($notices[$notice])) {
            echo '<div class="notice notice-success"><p>' . esc_html($notices[$notice]) . '</p></div>';
        }
        if ($missing) {
            echo '<div class="notice notice-error"><p>' . esc_html__('That provider no longer exists. Choose a provider below or add a new one.', 'portalweave-sso') . '</p></div>';
        }
        if ($enforced) {
            echo '<div class="notice notice-info"><p>' . esc_html__('These settings are managed by your network administrator. You can review them here.', 'portalweave-sso') . '</p></div>';
        }
        echo '<a class="screen-reader-text" href="#portalweave-sso-content">' . esc_html__('Skip to SSO settings', 'portalweave-sso') . '</a><div class="portalweave-sso-workspace"><aside class="portalweave-sso-sidebar"><nav aria-label="' . esc_attr__('SSO settings', 'portalweave-sso') . '">';
        foreach (self::screens() as $key => [$label, $icon]) {
            echo '<a class="portalweave-sso-nav-link' . ($key === $screen ? ' is-active' : '') . '"' . ($key === $screen ? ' aria-current="page"' : '') . ' href="' . esc_url(self::screen_url($key, $network)) . '"><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span>' . esc_html($label) . '</span></a>';
        }
        echo '</nav></aside><div id="portalweave-sso-content" class="portalweave-sso-content" tabindex="-1">';
        $titles = [
            'overview' => [__('Your sign-in workspace', 'portalweave-sso'), __('Set up a provider, test sign-in, then choose how people access your site.', 'portalweave-sso')],
            'providers' => [__('Identity providers', 'portalweave-sso'), __('Manage the services people use to sign in. Configure and test each provider separately.', 'portalweave-sso')],
            'login' => [__('Login & logout', 'portalweave-sso'), __('Choose the default provider and what happens when people sign in or out.', 'portalweave-sso')],
            'access' => [__('Access policies', 'portalweave-sso'), __('Decide who can visit your site and protect specific content or media.', 'portalweave-sso')],
            'appearance' => [__('Login appearance', 'portalweave-sso'), __('Style your login buttons and the messages people see before and after sign-in.', 'portalweave-sso')],
            'modules' => [__('Integrations', 'portalweave-sso'), __('Enable the optional features your site needs. Keep unused integrations off.', 'portalweave-sso')],
            'reports' => [__('SSO activity', 'portalweave-sso'), __('View activity trends or inspect recorded audit events.', 'portalweave-sso')],
            'tools' => [__('Tools & support', 'portalweave-sso'), __('Back up configuration, troubleshoot sign-in, and contact your support team.', 'portalweave-sso')],
        ];
        [$title, $description] = $titles[$screen];
        if ($editing) {
            $title = $creating ? __('Add identity provider', 'portalweave-sso') : ($settings['apps'][$provider]['name'] ?: $provider);
            $description = $creating ? __('Connect your service with a guide tailored to its setup process.', 'portalweave-sso') : __('Configure this provider. Other providers and site settings are saved separately.', 'portalweave-sso');
            echo '<p class="portalweave-sso-back-link"><a href="' . esc_url(self::screen_url('providers', $network)) . '">' . esc_html__('Back to identity providers', 'portalweave-sso') . '</a></p>';
        }
        echo '<div class="portalweave-sso-page-heading"><div><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p></div>';
        if ($screen === 'providers' && !$editing && !$enforced) {
            echo '<div class="portalweave-sso-page-actions"><a class="button button-primary" href="' . esc_url(self::screen_url('providers', $network, ['new_provider' => '1'])) . '">' . esc_html__('Add provider', 'portalweave-sso') . '</a></div>';
        }
        echo '</div>';
        if ($screen === 'overview') {
            self::overview($settings, $network, $enforced);
        } elseif ($screen === 'providers' && !$editing) {
            self::provider_list($settings, $network, $enforced);
        } elseif ($editing && $creating) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only a known catalog key selects the displayed recipe; the final POST validates its own scope, capability and nonce.
            $setup_provider = self::request_key($_GET, 'setup_provider');
            ProviderWizard::render($setup_provider, $network, $enforced, self::screen_url('providers', $network, ['new_provider' => '1']));
        } else {
            $activity_view = '';
            $activity_days = 30;
            $audit_context = ['page' => 1, 'snapshot' => 0];
            if ($screen === 'reports') {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation is restricted to the two Activity views.
                $activity_view = self::activity_view($_GET, 'activity_view');
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only chart period is restricted to 7, 30 or 90 UTC days.
                $activity_days = self::activity_days($_GET, 'activity_days');
                self::activity_navigation($activity_view, $activity_days, $network);
                if ($activity_view === 'overview') {
                    self::activity_overview($activity_days, $network);
                } else {
                    $audit_context = self::reports($settings, $network, $activity_days);
                }
            }
            if ($screen !== 'reports' || $activity_view === 'audit') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="portalweave-sso-settings-form" data-pending-label="' . esc_attr__('Saving…', 'portalweave-sso') . '" data-pending-message="' . esc_attr__('Saving your changes…', 'portalweave-sso') . '"><input type="hidden" name="action" value="portalweave_sso_save"><input type="hidden" name="portalweave_sso_screen" value="' . esc_attr($screen) . '">';
            if ($screen === 'reports') {
                echo '<input type="hidden" name="portalweave_sso_activity_view" value="audit"><input type="hidden" name="portalweave_sso_activity_days" value="' . esc_attr((string) $activity_days) . '">';
                echo '<input type="hidden" name="portalweave_sso_audit_page" value="' . esc_attr((string) $audit_context['page']) . '"><input type="hidden" name="portalweave_sso_audit_snapshot" value="' . esc_attr((string) $audit_context['snapshot']) . '">';
            }
            wp_nonce_field('portalweave_sso_save');
            self::hidden_scope($network);
            if ($editing) {
                echo '<input type="hidden" name="portalweave_sso_provider" value="' . esc_attr($provider) . '"><input type="hidden" name="portalweave_sso_creating" value="' . ($creating ? '1' : '0') . '">';
            }
            echo '<fieldset' . ($enforced ? ' disabled' : '') . '>';
            if ($editing) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An allowlisted section chooses local presentation only; saving retains capability, nonce and provider-scope checks.
                $provider_section = self::provider_section($_GET, 'provider_section');
                self::provider($provider, $creating ? Config::app_defaults() : array_replace_recursive(Config::app_defaults(), $settings['apps'][$provider]), $creating, !$network || !empty($settings['network_enforced']), $provider_section);
            } else {
                self::global_fields($settings, $network, $screen);
            }
            if (!$enforced) {
                echo '<div class="portalweave-sso-save"><button class="button button-primary" type="submit">' . esc_html($editing ? ($creating ? __('Add provider', 'portalweave-sso') : __('Save provider', 'portalweave-sso')) : __('Save changes', 'portalweave-sso')) . '</button><span>' . esc_html($editing ? __('Saves all sections for this provider.', 'portalweave-sso') : __('Saves only the settings on this screen.', 'portalweave-sso')) . '</span></div>';
            }
            echo '</fieldset><input type="hidden" name="portalweave_sso_complete" value="1"></form>';
            if ($screen === 'tools') {
                self::tools($settings, $network, $enforced);
            }
            }
        }
        echo '</div></div></div>';
    }

    private static function overview(array $settings, bool $network, bool $enforced): void {
        $default = $settings['apps'][$settings['default_app']] ?? [];
        echo '<dl class="portalweave-sso-summary-grid">';
        foreach ([
            [__('Configured providers', 'portalweave-sso'), (string) count($settings['apps'])],
            [__('Default provider', 'portalweave-sso'), $default['name'] ?? ($default['id'] ?? __('Not selected', 'portalweave-sso'))],
            [__('Site-wide sign-in', 'portalweave-sso'), $settings['force_auth'] || $settings['restrict_site'] ? __('Required', 'portalweave-sso') : __('Optional', 'portalweave-sso')],
        ] as [$label, $value]) {
            echo '<div class="portalweave-sso-stat"><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
        }
        echo '</dl><section class="portalweave-sso-section"><h3>' . esc_html__('Set up SSO in three steps', 'portalweave-sso') . '</h3><ol class="portalweave-sso-setup">';
        $steps = [
            [__('Connect an identity provider', 'portalweave-sso'), __('Add your provider credentials and register the displayed callback URL.', 'portalweave-sso'), 'providers'],
            [__('Test before you enforce sign-in', 'portalweave-sso'), __('Use Test SSO Config, review the returned attributes, and sign in with an ordinary account.', 'portalweave-sso'), 'providers'],
            [__('Choose your access policy', 'portalweave-sso'), __('Set a default provider and review recovery options before requiring SSO.', 'portalweave-sso'), 'login'],
        ];
        foreach ($steps as [$label, $help, $target]) {
            echo '<li><h4><a href="' . esc_url(self::screen_url($target, $network)) . '">' . esc_html($label) . '</a></h4><p>' . esc_html($help) . '</p></li>';
        }
        echo '</ol></section>';
        if (!$settings['apps'] && !$enforced) {
            echo '<div class="portalweave-sso-callout"><strong>' . esc_html__('Start with your first provider', 'portalweave-sso') . '</strong><p>' . esc_html__('Choose a provider template or configure your own OAuth / OpenID Connect service.', 'portalweave-sso') . '</p><p><a class="button button-primary" href="' . esc_url(self::screen_url('providers', $network, ['new_provider' => '1'])) . '">' . esc_html__('Add provider', 'portalweave-sso') . '</a></p></div>';
        }
    }

    private static function provider_list(array $settings, bool $network, bool $enforced): void {
        if (!$settings['apps']) {
            echo '<section class="portalweave-sso-section portalweave-sso-empty-state"><span class="dashicons dashicons-shield" aria-hidden="true"></span><h3>' . esc_html__('No identity providers yet', 'portalweave-sso') . '</h3><p>' . esc_html__('Add a provider to connect your site to your identity service. Login policies stay separate from provider setup.', 'portalweave-sso') . '</p></section>';
            return;
        }
        echo '<section class="portalweave-sso-section"><table class="widefat portalweave-sso-provider-list"><thead><tr>';
        foreach ([__('Provider', 'portalweave-sso'), __('Protocol', 'portalweave-sso'), __('Status', 'portalweave-sso'), __('Actions', 'portalweave-sso')] as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($settings['apps'] as $id => $app) {
            $url = self::screen_url('providers', $network, ['provider' => $id]);
            echo '<tr><td><strong><a href="' . esc_url($url) . '">' . esc_html($app['name'] ?: $id) . '</a></strong><span class="portalweave-sso-provider-issuer">' . esc_html($app['issuer'] ?: $id) . '</span></td><td>' . esc_html(strtoupper($app['protocol'])) . '</td><td><span class="portalweave-sso-status ' . (!empty($app['enabled']) ? 'is-enabled' : 'is-disabled') . '">' . esc_html(!empty($app['enabled']) ? __('Enabled', 'portalweave-sso') : __('Disabled', 'portalweave-sso')) . '</span></td><td><div class="portalweave-sso-provider-actions"><a class="button" href="' . esc_url($url) . '">' . esc_html($enforced ? __('View', 'portalweave-sso') : __('Configure', 'portalweave-sso')) . '</a>';
            if (!empty($app['enabled']) && (!$network || !empty($settings['network_enforced']))) {
                echo '<a class="button-link" target="_blank" rel="noopener" href="' . esc_url(Auth::login_url($id, '', true)) . '">' . esc_html__('Test SSO Config', 'portalweave-sso') . '</a>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html__('Enabled is a configuration setting. Test each provider to verify the connection.', 'portalweave-sso') . '</p></section>';
    }

    private static function provider_panel(string $key, string $title): void {
        echo '<div class="portalweave-sso-provider-panel portalweave-sso-provider-body" data-provider-panel="' . esc_attr($key) . '" id="portalweave-sso-provider-panel-' . esc_attr($key) . '"><h3>' . esc_html($title) . '</h3>';
    }

    private static function provider(string $id, array $app, bool $new, bool $can_test = true, string $section = 'connection'): void {
        $prefix = $new ? 'settings[new_app]' : 'settings[apps][' . $id . ']';
        echo '<section class="portalweave-sso-section portalweave-sso-provider" data-provider-editor data-provider-section="' . esc_attr($section) . '"><nav class="portalweave-sso-provider-tabs" data-provider-tabs aria-label="' . esc_attr__('Provider configuration sections', 'portalweave-sso') . '">';
        foreach (self::provider_sections() as $key => $label) {
            echo '<a href="#portalweave-sso-provider-panel-' . esc_attr($key) . '" id="portalweave-sso-provider-tab-' . esc_attr($key) . '" data-provider-tab="' . esc_attr($key) . '"' . ($key === $section ? ' class="is-active"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav><input type="hidden" name="portalweave_sso_provider_section" value="' . esc_attr($section) . '">';
        self::provider_panel('connection', __('Connection', 'portalweave-sso'));
        if ($new) {
            self::field($prefix . '[id]', '', __('App ID', 'portalweave-sso'), 'text', __('A unique slug, for example staff-entra.', 'portalweave-sso'));
        } else {
            echo '<input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr($id) . '"><p><strong>' . esc_html__('App ID:', 'portalweave-sso') . '</strong> <code>' . esc_html($id) . '</code></p>';
        }
        $templates = [
            'custom' => __('Custom OAuth / OpenID', 'portalweave-sso'), 'entra' => __('Microsoft Entra ID / Azure AD', 'portalweave-sso'), 'b2c' => __('Azure AD B2C', 'portalweave-sso'), 'office365' => __('Office 365', 'portalweave-sso'), 'cognito' => __('AWS Cognito', 'portalweave-sso'), 'keycloak' => __('Keycloak', 'portalweave-sso'), 'okta' => __('Okta', 'portalweave-sso'), 'salesforce' => __('Salesforce', 'portalweave-sso'), 'google' => __('Google', 'portalweave-sso'), 'auth0' => __('Auth0', 'portalweave-sso'), 'onelogin' => __('OneLogin', 'portalweave-sso'), 'discord' => __('Discord', 'portalweave-sso'), 'classlink' => __('ClassLink', 'portalweave-sso'), 'authentik' => 'authentik',
        ];
        asort($templates, SORT_STRING | SORT_FLAG_CASE);
        self::select($prefix . '[provider]', $app['provider'] ?? 'custom', __('Provider template', 'portalweave-sso'), $templates, __('Templates fill empty endpoint fields. Replace tenant and domain placeholders with values from your provider.', 'portalweave-sso'), 'portalweave-sso-preset');
        self::field($prefix . '[name]', $app['name'], __('Display name', 'portalweave-sso'));
        self::check($prefix . '[enabled]', $app['enabled'], __('Enable this provider', 'portalweave-sso'));
        self::field($prefix . '[client_id]', $app['client_id'], __('Client ID', 'portalweave-sso'), 'text', __('Stored on the server. Excluded from support exports.', 'portalweave-sso'));
        self::secret($prefix, 'client_secret', !empty($app['client_secret']), __('Client secret', 'portalweave-sso'));
        self::select($prefix . '[credential_method]', $app['credential_method'], __('Send client credentials', 'portalweave-sso'), ['header' => __('HTTP Basic header', 'portalweave-sso'), 'body' => __('Request body', 'portalweave-sso'), 'none' => __('No client authentication (public client)', 'portalweave-sso')]);
        self::field($prefix . '[scope]', $app['scope'], __('Scopes', 'portalweave-sso'), 'text', __('Space separated. OIDC requires openid; profile and email are common.', 'portalweave-sso'));
        echo '</div>';
        self::provider_panel('endpoints', __('Provider endpoints', 'portalweave-sso'));
        foreach (['authorize_url' => __('Authorization endpoint', 'portalweave-sso'), 'token_url' => __('Token endpoint', 'portalweave-sso'), 'userinfo_url' => __('User-info endpoint', 'portalweave-sso'), 'groupinfo_url' => __('Group-info endpoint', 'portalweave-sso'), 'jwks_url' => __('JWKS endpoint', 'portalweave-sso'), 'issuer' => __('Issuer', 'portalweave-sso'), 'logout_url' => __('OIDC end-session endpoint', 'portalweave-sso'), 'callback_url' => __('Callback URL override', 'portalweave-sso')] as $key => $label) {
            self::field($prefix . '[' . $key . ']', $app[$key], $label, 'url', $key === 'callback_url' ? __('Optional. Use an allowed origin that routes to this WordPress installation; register this exact URL with the provider.', 'portalweave-sso') : '');
        }
        self::check($prefix . '[backchannel_logout]', $app['backchannel_logout'] ?? false, __('Enable signed OIDC back-channel logout', 'portalweave-sso'), __('Configure the back-channel URL at the provider. Logout tokens must pass signature, issuer, audience, event, expiry, and replay checks.', 'portalweave-sso'));
        if (!$new) {
            echo '<div class="portalweave-sso-callback"><strong>' . esc_html__('OIDC back-channel logout URL', 'portalweave-sso') . '</strong><code>' . esc_html(add_query_arg(['portalweave_sso_action' => 'backchannel_logout', 'app' => $id], home_url('/'))) . '</code></div>';
        }
        echo '<div class="portalweave-sso-callback"><strong>' . esc_html__('Registered callback URL', 'portalweave-sso') . '</strong><code>' . esc_html(Config::callback($app)) . '</code></div>';
        echo '</div>';
        self::provider_panel('security', __('Token validation and advanced options', 'portalweave-sso'));
        echo '<div class="portalweave-sso-grid">';
        self::select($prefix . '[protocol]', $app['protocol'], __('Protocol', 'portalweave-sso'), ['oidc' => __('OpenID Connect 1.0', 'portalweave-sso'), 'oauth2' => __('OAuth 2.0', 'portalweave-sso')]);
        self::select($prefix . '[grant]', $app['grant'], __('Login grant', 'portalweave-sso'), ['authorization_code' => __('Authorization Code', 'portalweave-sso'), 'implicit' => __('Implicit (legacy)', 'portalweave-sso'), 'password' => __('Password (legacy)', 'portalweave-sso')]);
        echo '</div>';
        self::check($prefix . '[pkce]', $app['pkce'], __('Use PKCE (S256)', 'portalweave-sso'), __('Public clients can select “No client authentication” and omit a client secret.', 'portalweave-sso'));
        self::check($prefix . '[legacy_grants]', $app['legacy_grants'], __('Explicitly allow legacy Implicit / Password grants', 'portalweave-sso'), __('These flows are deprecated by current OAuth security guidance. Authorization Code with PKCE is the recommended flow.', 'portalweave-sso'));
        self::field($prefix . '[state_parameter]', $app['state_parameter'], __('Additional state value', 'portalweave-sso'), 'text', __('Stored inside a random, browser-bound, one-use state transaction.', 'portalweave-sso'));
        self::field($prefix . '[algorithms]', implode("\n", $app['algorithms']), __('Allowed JWT algorithms', 'portalweave-sso'), 'textarea', __('One per line: RS256, RS384, RS512, HS256, HS384, HS512. Pin only the algorithms used by this provider.', 'portalweave-sso'));
        self::secret($prefix, 'jwt_secret', !empty($app['jwt_secret']), __('HMAC JWT signing secret', 'portalweave-sso'));
        self::field($prefix . '[subject_attribute]', $app['subject_attribute'], __('Immutable subject attribute', 'portalweave-sso'), 'text', __('OIDC uses the signed sub claim. OAuth-only providers need a stable unique ID.', 'portalweave-sso'));
        echo '</div>';
        self::provider_panel('mapping', __('Attributes and role mapping', 'portalweave-sso'));
        self::json($prefix . '[mapping]', $app['mapping'], __('Standard attribute paths', 'portalweave-sso'), __('Map WordPress profile fields to provider attributes. Use dot paths for nested attributes, such as realm_access.roles.', 'portalweave-sso'));
        self::json($prefix . '[custom_mapping]', $app['custom_mapping'], __('Custom profile attributes', 'portalweave-sso'), __('Map safe public profile keys to provider attribute paths. Privilege and internal WordPress metadata are blocked.', 'portalweave-sso'));
        self::json($prefix . '[role_mapping]', $app['role_mapping'], __('Group → WordPress roles', 'portalweave-sso'), __('Choose the WordPress roles assigned for each matching provider group. Use Ctrl or Command to select multiple roles.', 'portalweave-sso'));
        self::json($prefix . '[domain_roles]', $app['domain_roles'], __('Email domain → WordPress roles', 'portalweave-sso'), __('Choose roles for exact email domains, such as school.example. Use Ctrl or Command to select multiple roles.', 'portalweave-sso'));
        self::field($prefix . '[default_role]', $app['default_role'], __('Default WordPress role', 'portalweave-sso'));
        echo '</div>';
        self::provider_panel('accounts', __('Account access and verification', 'portalweave-sso'));
        self::json($prefix . '[domain_mapping]', $app['domain_mapping'], __('Email domain mapping', 'portalweave-sso'), __('Replace an exact email domain with another domain. Identity verification still uses the original provider claim.', 'portalweave-sso'));
        foreach ([
            'auto_register' => [__('Automatically register users at first SSO login', 'portalweave-sso'), __('Turn off to allow existing linked or eligible local accounts only.', 'portalweave-sso')],
            'link_existing' => [__('Link existing users by verified email', 'portalweave-sso'), __('Only a verified provider email may link an existing account.', 'portalweave-sso')],
            'keep_roles' => [__('Keep existing WordPress roles', 'portalweave-sso'), __('Provider roles are not used to replace an existing account’s roles.', 'portalweave-sso')],
            'block_unmapped' => [__('Block login when no explicit role mapping matches', 'portalweave-sso'), __('The default role does not count as an explicit match.', 'portalweave-sso')],
            'keep_attributes' => [__('Keep existing profile attributes', 'portalweave-sso'), __('Applies to existing users.', 'portalweave-sso')],
            'keep_email' => [__('Keep existing account email', 'portalweave-sso'), __('Applies to existing users.', 'portalweave-sso')],
            'allow_duplicate_emails' => [__('Allow duplicate WordPress email addresses', 'portalweave-sso'), __('Provider subject stays the primary identity. Automatic email linking requires one unambiguous account.', 'portalweave-sso')],
            'require_verified_email' => [__('Require a verified provider email', 'portalweave-sso'), __('The verified claim and value are configured below.', 'portalweave-sso')],
            'allow_admin' => [__('Allow SSO for administrators', 'portalweave-sso'), __('Off by default. The configured verified claim must match for every administrator SSO login.', 'portalweave-sso')],
        ] as $key => [$label, $help]) {
            self::check($prefix . '[' . $key . ']', $app[$key], $label, $help);
        }
        self::field($prefix . '[verified_attribute]', $app['verified_attribute'], __('Verified provider claim path', 'portalweave-sso'));
        self::field($prefix . '[verified_value]', wp_json_encode($app['verified_value']), __('Required verified claim value', 'portalweave-sso'), 'text', __('JSON scalar. A true/1 expectation accepts true, "true", 1, or "1". Other scalar values use an exact string comparison.', 'portalweave-sso'));
        echo '</div>';
        self::provider_panel('integrations', __('Optional integrations', 'portalweave-sso'));
        self::check($prefix . '[guest]', $app['guest'] ?? false, __('Allow ephemeral guest login for this app', 'portalweave-sso'), __('Also enable the Guest User Login module.', 'portalweave-sso'));
        self::check($prefix . '[moderation]', $app['moderation'] ?? false, __('Hold new users for administrator approval', 'portalweave-sso'), __('Also enable the User Moderation module.', 'portalweave-sso'));
        self::check($prefix . '[shared_sso]', $app['shared_sso'] ?? false, __('Issue a shared subdomain SSO ticket', 'portalweave-sso'), __('Also enable and configure the Shared SSO module.', 'portalweave-sso'));
        self::check($prefix . '[jwt_sso]', $app['jwt_sso'] ?? false, __('Allow signed JWT SSO for this app', 'portalweave-sso'), __('Also enable the JWT SSO module.', 'portalweave-sso'));
        self::field($prefix . '[avatar_attribute]', $app['avatar_attribute'] ?? 'picture', __('Profile picture attribute', 'portalweave-sso'), 'text', __('Used when the Profile Picture module is enabled.', 'portalweave-sso'));
        self::json($prefix . '[redirect_rules]', $app['redirect_rules'], __('Attribute based redirect rules', 'portalweave-sso'), __('Match a provider attribute to a value and choose its redirect URL. Rules run in order and URLs must pass the redirect allowlist.', 'portalweave-sso'));
        echo '</div>';
        self::provider_panel('button', __('Login button', 'portalweave-sso'));
        self::field($prefix . '[button_text]', $app['button_text'], __('Button text', 'portalweave-sso'), 'text', __('Leave blank to use the global text and provider name.', 'portalweave-sso'));
        self::provider_logo($prefix, $app['icon_url']);
        echo '</div><div class="portalweave-sso-provider-body portalweave-sso-provider-footer">';
        if (!$new) {
            if ($can_test) {
            echo '<div class="portalweave-sso-provider-actions"><a class="button button-secondary" target="_blank" rel="noopener" href="' . esc_url(Auth::login_url($id, '', true)) . '">' . esc_html__('Test SSO Config', 'portalweave-sso') . '</a><span>' . esc_html__('Opens a protected raw-attribute test without changing your account.', 'portalweave-sso') . '</span></div>';
            } else {
                echo '<p class="description">' . esc_html__('This network configuration is not active for sign-in. Test it on a staging site before enforcing it; a site-level test could otherwise use a different provider with the same ID.', 'portalweave-sso') . '</p>';
            }
            self::check($prefix . '[_delete]', false, __('Delete this provider when saving', 'portalweave-sso'), __('Linked user identities remain stored. Removing a provider does not delete users.', 'portalweave-sso'));
        }
        echo '</div></section>';
    }

    private static function global_fields(array $settings, bool $network, string $screen): void {
        if ($screen === 'login') {
            echo '<section id="portalweave-sso-login" class="portalweave-sso-section"><h3>' . esc_html__('Sign-in behavior', 'portalweave-sso') . '</h3>';
        $apps = ['' => __('Select a default provider', 'portalweave-sso')];
        foreach ($settings['apps'] as $id => $app) {
            $apps[$id] = $app['name'] ?: $id;
        }
        self::select('settings[default_app]', $settings['default_app'], __('Default identity provider', 'portalweave-sso'), $apps);
        foreach ([
            'force_auth' => [__('Force authentication for the whole site', 'portalweave-sso'), __('Logged-out visitors start SSO with the default provider.', 'portalweave-sso')],
            'disable_wp_login' => [__('Hide the standard WordPress password login', 'portalweave-sso'), __('Keep a tested SSO route and a documented recovery procedure before enabling.', 'portalweave-sso')],
            'single_flow' => [__('Use a single sign-in flow', 'portalweave-sso'), __('Send WordPress login requests directly to the default provider.', 'portalweave-sso')],
            'popup' => [__('Open SSO login in a popup window', 'portalweave-sso'), __('Browsers may use the normal redirect if popups are blocked.', 'portalweave-sso')],
            'logout_confirmation' => [__('Ask for confirmation before SSO logout', 'portalweave-sso'), __('Confirmation also verifies the current WordPress session.', 'portalweave-sso')],
        ] as $key => [$label, $help]) {
            self::check('settings[' . $key . ']', $settings[$key], $label, $help);
        }
        self::field('settings[login_redirect]', $settings['login_redirect'], __('Default redirect after login', 'portalweave-sso'), 'url');
        self::field('settings[logout_redirect]', $settings['logout_redirect'], __('Default redirect after logout', 'portalweave-sso'), 'url');
        self::field('settings[redirect_allowlist]', implode("\n", $settings['redirect_allowlist']), __('Allowed redirect URLs', 'portalweave-sso'), 'textarea', __('One HTTPS origin or path prefix per line. Paths use a boundary check; example https://portal.example.com/members/.', 'portalweave-sso'));
        } elseif ($screen === 'access') {
            echo '<section id="portalweave-sso-access" class="portalweave-sso-section"><h3>' . esc_html__('Who can access your site', 'portalweave-sso') . '</h3>';
            if ($network) {
                self::check('settings[network_enforced]', $settings['network_enforced'], __('Enforce these settings across this network', 'portalweave-sso'), __('Applies to this network ID. Other networks keep their own policy.', 'portalweave-sso'));
            }
            self::check('settings[restrict_site]', $settings['restrict_site'], __('Restrict the site to logged-in users', 'portalweave-sso'));
        self::field('settings[allowed_domains]', implode("\n", $settings['allowed_domains']), __('Allowed email domains', 'portalweave-sso'), 'textarea', __('One exact domain per line. Leave empty to allow all domains except blocked ones.', 'portalweave-sso'));
        self::field('settings[blocked_domains]', implode("\n", $settings['blocked_domains']), __('Blocked email domains', 'portalweave-sso'), 'textarea', __('One exact domain per line. A blocked domain always takes precedence.', 'portalweave-sso'));
        self::json('settings[content_rules]', $settings['content_rules'], __('Content access rules', 'portalweave-sso'), __('Add bulk rules by post, page or category ID. Individual editors also expose access restrictions. When roles or groups are selected, a user must match either one.', 'portalweave-sso'));
        self::field('settings[protected_media]', implode(', ', $settings['protected_media']), __('Protected attachment IDs', 'portalweave-sso'), 'text', __('Comma separated. Original files and derivatives are relocated outside the document root and served through authenticated delivery. Purge old copies from web-server and CDN caches.', 'portalweave-sso'));
        /* translators: %s: private media directory configuration constant, displayed as code. */
        $media_help = sprintf(__('Protecting an attachment relocates its original and derivatives outside the web document root. WordPress URLs then use the authenticated delivery endpoint. Configure a writable %s in wp-config.php; the default is a private sibling directory of ABSPATH. Purge previously cached files from web-server and CDN caches. Restoring public access moves files back to their original upload paths.', 'portalweave-sso'), '<code>PORTALWEAVE_SSO_PRIVATE_MEDIA_DIR</code>');
        echo '<div class="portalweave-sso-callout"><strong>' . esc_html__('Protected media delivery', 'portalweave-sso') . '</strong><p>' . wp_kses($media_help, ['code' => []]) . '</p></div>';
        } elseif ($screen === 'appearance') {
            self::appearance($settings);
            return;
        } elseif ($screen === 'modules') {
            self::integrations($settings, $network);
            return;
        } elseif ($screen === 'reports') {
            echo '<section class="portalweave-sso-section"><h3>' . esc_html__('Audit collection and retention', 'portalweave-sso') . '</h3>';
        self::check('settings[debug]', $settings['debug'], __('Enable diagnostic debug events', 'portalweave-sso'), __('No bearer tokens, client secrets, or raw provider attributes are logged. Debug events are removed weekly.', 'portalweave-sso'));
        self::check('settings[audit_enabled]', $settings['audit_enabled'], __('Record SSO transactions and login audit events', 'portalweave-sso'), __('Disable to stop collecting new audit events. Existing events remain until their retention period expires.', 'portalweave-sso'));
        self::check('settings[audit_ip]', $settings['audit_ip'], __('Include client IP addresses in audit events', 'portalweave-sso'), __('Off by default. Enable only when needed and disclose this collection in your site privacy policy.', 'portalweave-sso'));
        self::field('settings[audit_retention_days]', $settings['audit_retention_days'], __('Audit retention (days)', 'portalweave-sso'), 'number', __('Audit and transaction reports contain personal data. Set retention appropriate to your site.', 'portalweave-sso'));
        } elseif ($screen === 'tools') {
        echo '<section class="portalweave-sso-section"><h3>' . esc_html__('Support destinations', 'portalweave-sso') . '</h3>';
        self::field('settings[support][email]', $settings['support']['email'], __('Support recipient email', 'portalweave-sso'), 'email', __('Choose the team that supports this installation. No vendor destination is configured automatically.', 'portalweave-sso'));
        foreach (['booking_url' => __('Call / screen-share booking URL', 'portalweave-sso'), 'forum_url' => __('Support forum URL', 'portalweave-sso'), 'demo_url' => __('Demo / trial request URL', 'portalweave-sso')] as $key => $label) {
            self::field('settings[support][' . $key . ']', $settings['support'][$key], $label, 'url');
        }
            echo '<details class="portalweave-sso-subsection"><summary>' . esc_html__('Data removal', 'portalweave-sso') . '</summary>';
        self::check('settings[delete_data_on_uninstall]', $settings['delete_data_on_uninstall'], __('Delete plugin data when uninstalling', 'portalweave-sso'), __('Off by default. Removes plugin settings, audit events, and linked user metadata. WordPress users, posts, and private media vault files are retained. Back up and restore protected media before uninstalling.', 'portalweave-sso'));
            echo '</details>';
        }
        echo '</section>';
    }

    private static function integrations(array $settings, bool $network): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A bounded section selects local presentation only; all integrations remain in the protected scoped form.
        $section = self::integration_section($_GET, 'integration_section');
        $groups = [
            [__('Provisioning', 'portalweave-sso'), ['scim']],
            [__('Sign-in and sessions', 'portalweave-sso'), ['jwt_sso', 'shared_sso', 'guest']],
            [__('Account experience', 'portalweave-sso'), ['moderation', 'avatar', 'attribute_redirect']],
            [__('Automation', 'portalweave-sso'), ['abilities']],
        ];
        $items = [
            'scim' => [__('SCIM provisioning', 'portalweave-sso'), __('Create and manage site-owned directory users and groups through an authenticated SCIM client.', 'portalweave-sso'), 'networking'],
            'jwt_sso' => [__('JWT single sign-on', 'portalweave-sso'), __('Accept signed ID tokens through the browser-bound JWT sign-in flow.', 'portalweave-sso'), 'shield'],
            'shared_sso' => [__('Shared subdomain SSO', 'portalweave-sso'), __('Hand off sign-in between trusted HTTPS sites on the same parent domain.', 'portalweave-sso'), 'admin-multisite'],
            'guest' => [__('Guest accounts', 'portalweave-sso'), __('Create temporary subscriber accounts through selected identity providers.', 'portalweave-sso'), 'clock'],
            'moderation' => [__('User moderation', 'portalweave-sso'), __('Hold new nonprivileged identities for administrator approval before sign-in.', 'portalweave-sso'), 'admin-users'],
            'avatar' => [__('Profile pictures', 'portalweave-sso'), __('Use a provider picture attribute for WordPress avatars.', 'portalweave-sso'), 'format-image'],
            'attribute_redirect' => [__('Attribute redirects', 'portalweave-sso'), __('Choose a destination after sign-in using ordered provider claim rules.', 'portalweave-sso'), 'randomize'],
            'abilities' => [__('AI / MCP abilities', 'portalweave-sso'), __('Expose eight administrator abilities to an authenticated automation client.', 'portalweave-sso'), 'admin-tools'],
        ];
        echo '<section id="portalweave-sso-modules" class="portalweave-sso-section portalweave-sso-integrations" data-integrations-editor data-integration-section="' . esc_attr($section) . '" data-enabled-label="' . esc_attr__('Enabled', 'portalweave-sso') . '" data-disabled-label="' . esc_attr__('Off', 'portalweave-sso') . '"><p class="portalweave-sso-integrations-intro">' . esc_html__('Choose an integration to configure it. Each integration starts off; enabling it keeps your existing account and access policies in place.', 'portalweave-sso') . '</p><input type="hidden" name="portalweave_sso_integration_section" value="' . esc_attr($section) . '"><div class="portalweave-sso-integrations-layout"><nav class="portalweave-sso-integrations-directory" data-integration-tabs aria-label="' . esc_attr__('Integration settings', 'portalweave-sso') . '">';
        foreach ($groups as [$label, $keys]) {
            echo '<div class="portalweave-sso-integration-group"><h3>' . esc_html($label) . '</h3>';
            foreach ($keys as $key) {
                [$title, , $icon] = $items[$key];
                $enabled = $key === 'abilities' ? !empty($settings['abilities_enabled']) : !empty($settings['modules'][$key]);
                echo '<a id="portalweave-sso-integration-tab-' . esc_attr($key) . '" href="#portalweave-sso-integration-panel-' . esc_attr($key) . '" data-integration-tab="' . esc_attr($key) . '"' . ($section === $key ? ' class="is-active"' : '') . '><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="portalweave-sso-integration-name">' . esc_html($title) . '<span class="portalweave-sso-integration-status' . ($enabled ? ' is-enabled' : '') . '" data-integration-status>' . esc_html($enabled ? __('Enabled', 'portalweave-sso') : __('Off', 'portalweave-sso')) . '</span></span></a>';
            }
            echo '</div>';
        }
        echo '</nav><div class="portalweave-sso-integration-panels">';
        foreach ($items as $key => [$title, $description]) {
            echo '<section id="portalweave-sso-integration-panel-' . esc_attr($key) . '" class="portalweave-sso-integration-panel" data-integration-panel="' . esc_attr($key) . '"><h3>' . esc_html($title) . '</h3><p class="portalweave-sso-integration-description">' . esc_html($description) . '</p>';
            if ($key === 'abilities') {
                self::check('settings[abilities_enabled]', $settings['abilities_enabled'], __('Enable the eight AI / MCP abilities', 'portalweave-sso'), __('Requires WordPress 6.9 or later and manage_options. Install the WordPress MCP Adapter separately for MCP transport.', 'portalweave-sso'));
                echo '<p>' . esc_html__('The adapter owns authentication and connection setup. PortalWeave SSO does not call an AI model itself. Review the client’s data handling before exposing personal audit output.', 'portalweave-sso') . '</p>';
            } else {
                self::check('settings[modules][' . $key . ']', $settings['modules'][$key] ?? false, __('Enable this integration', 'portalweave-sso'));
                if ($key === 'scim') {
                    self::secret('settings', 'scim_token', !empty($settings['scim_token']), __('SCIM bearer token', 'portalweave-sso'));
                    echo '<p class="description">' . esc_html__('Use a dedicated cryptographically random token of at least 32 characters. Configure it in the provisioning client; it is never displayed again.', 'portalweave-sso') . '</p><p><strong>' . esc_html__('SCIM base URL', 'portalweave-sso') . '</strong><br><code class="portalweave-sso-integration-endpoint">' . esc_html(rest_url('portalweave-sso/v1/scim/v2')) . '</code></p>';
                } elseif ($key === 'shared_sso') {
                    self::field('settings[shared_cookie_domain]', $settings['shared_cookie_domain'], __('Shared cookie domain', 'portalweave-sso'), 'text', __('Example: .example.com. Every subdomain that can set cookies must be trusted.', 'portalweave-sso'));
                    self::secret('settings', 'shared_secret', !empty($settings['shared_secret']), __('Shared SSO signing secret', 'portalweave-sso'));
                    self::field('settings[shared_sites]', implode("\n", $settings['shared_sites']), __('Trusted shared SSO sites', 'portalweave-sso'), 'textarea', __('One exact HTTPS origin per line. Configure matching signing secrets and provider identities in each app.', 'portalweave-sso'));
                } elseif ($key === 'guest') {
                    self::field('settings[guest_ttl]', $settings['guest_ttl'] ?? 3600, __('Guest session lifetime (seconds)', 'portalweave-sso'), 'number', __('Between 300 and 86,400 seconds. Expired guests cannot sign in; scheduled cleanup retains accounts that authored content.', 'portalweave-sso'), ['min' => 300, 'max' => 86400]);
                } elseif ($key === 'jwt_sso') {
                    echo '<p>' . esc_html__('Use an OIDC provider that supports signed ID tokens with form_post. Configure its issuer, algorithms and trusted keys in the provider’s Security section.', 'portalweave-sso') . '</p>';
                } elseif ($key === 'moderation') {
                    echo '<p>' . esc_html__('Approve or block accounts under Users → Edit User → SSO account moderation. Approval does not grant roles; privileged accounts keep their recovery protections.', 'portalweave-sso') . '</p>';
                } elseif ($key === 'avatar') {
                    echo '<p>' . esc_html__('Set the picture attribute on each provider. Visitors’ browsers fetch the public HTTPS image from the picture host; disclose the host in your site privacy policy.', 'portalweave-sso') . '</p>';
                } elseif ($key === 'attribute_redirect') {
                    echo '<p>' . esc_html__('Set ordered claim, value and destination rules on each provider. Every destination must also pass the allowlist under Login & logout.', 'portalweave-sso') . '</p><p><a href="' . esc_url(self::screen_url('login', $network)) . '">' . esc_html__('Manage the redirect allowlist', 'portalweave-sso') . '</a></p>';
                }
                if ($key !== 'scim') {
                    self::integration_providers($settings, $network, $key);
                }
            }
            echo '</section>';
        }
        echo '</div></div></section>';
    }

    private static function integration_providers(array $settings, bool $network, string $integration): void {
        echo '<div class="portalweave-sso-integration-providers"><h4>' . esc_html__('Provider settings', 'portalweave-sso') . '</h4><p>' . esc_html__('Provider options are saved separately. Save your integration changes before opening a provider.', 'portalweave-sso') . '</p>';
        if (!$settings['apps']) {
            echo '<p><a href="' . esc_url(self::screen_url('providers', $network)) . '">' . esc_html__('Add an identity provider', 'portalweave-sso') . '</a></p>';
        } else {
            echo '<ul>';
            foreach ($settings['apps'] as $id => $app) {
                $enabled = in_array($integration, ['avatar', 'attribute_redirect'], true) ? null : !empty($app[$integration]);
                echo '<li><a href="' . esc_url(self::screen_url('providers', $network, ['provider' => (string) $id, 'provider_section' => 'integrations'])) . '">' . esc_html($app['name'] ?: (string) $id) . '</a>';
                if ($enabled !== null) {
                    echo '<span class="portalweave-sso-badge">' . esc_html($enabled ? __('Provider option on', 'portalweave-sso') : __('Provider option off', 'portalweave-sso')) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private static function appearance(array $settings): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An allowlisted Appearance tab selects presentation only; both panels use the same protected scoped save.
        $section = self::appearance_section($_GET, 'appearance_section');
        $button = $settings['button'];
        $style = array_replace(Config::wp_login_button_defaults(), $settings['wp_login_button']);
        echo '<input type="hidden" name="portalweave_sso_appearance_section" value="' . esc_attr($section) . '"><section id="portalweave-sso-appearance" class="portalweave-sso-section portalweave-sso-appearance-editor" data-appearance-editor data-appearance-section="' . esc_attr($section) . '" data-icon-invalid="' . esc_attr__('Use an HTTPS image URL without embedded credentials or a fragment.', 'portalweave-sso') . '" data-icon-error="' . esc_attr__('The preview image could not be loaded. Check the image URL.', 'portalweave-sso') . '"><nav class="portalweave-sso-appearance-tabs" data-appearance-tabs aria-label="' . esc_attr__('Appearance sections', 'portalweave-sso') . '">';
        foreach (['shared' => __('Shared buttons', 'portalweave-sso'), 'wordpress' => __('WordPress sign-in', 'portalweave-sso')] as $key => $label) {
            echo '<a href="#portalweave-sso-appearance-panel-' . esc_attr($key) . '" id="portalweave-sso-appearance-tab-' . esc_attr($key) . '" data-appearance-tab="' . esc_attr($key) . '"' . ($section === $key ? ' class="is-active"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav><div class="portalweave-sso-appearance-layout"><div class="portalweave-sso-appearance-controls"><section id="portalweave-sso-appearance-panel-shared" data-appearance-panel="shared"><h3>' . esc_html__('Shared buttons', 'portalweave-sso') . '</h3>';
        /* translators: %s: login button shortcode, displayed as code. */
        $placement = sprintf(__('Use %s, the PortalWeave SSO widget, or the WooCommerce login form.', 'portalweave-sso'), '<code>[sso_oauth_login]</code>');
        echo '<p>' . wp_kses($placement, ['code' => []]) . '</p>';
        self::select('settings[button][theme]', $button['theme'], __('Button theme', 'portalweave-sso'), ['default' => __('Default', 'portalweave-sso'), 'white' => __('White', 'portalweave-sso'), 'hover' => __('Hover', 'portalweave-sso'), 'custom' => __('Custom', 'portalweave-sso'), 'smart' => __('Smart', 'portalweave-sso')]);
        self::select('settings[button][shape]', $button['shape'], __('Button shape', 'portalweave-sso'), ['round' => __('Round', 'portalweave-sso'), 'round-edge' => __('Round edge', 'portalweave-sso'), 'square' => __('Square', 'portalweave-sso'), 'long' => __('Long', 'portalweave-sso')]);
        self::field('settings[button][text]', $button['text'], __('Default button text', 'portalweave-sso'), 'text', __('Use {app} for the provider name. Each app can override this text.', 'portalweave-sso'));
        self::field('settings[button][above_text]', $button['above_text'], __('Text above the widget', 'portalweave-sso'));
        foreach (['size' => __('Font size (px)', 'portalweave-sso'), 'radius' => __('Corner curve (px)', 'portalweave-sso'), 'spacing' => __('Button spacing (px)', 'portalweave-sso')] as $key => $label) {
            self::field('settings[button][' . $key . ']', $button[$key], $label, 'number');
        }
        self::check('settings[button][shadow]', $button['shadow'], __('Show button shadows', 'portalweave-sso'));
        self::check('settings[button][transform]', $button['transform'], __('Animate buttons on hover', 'portalweave-sso'));
        self::check('settings[button][admin_style]', $button['admin_style'], __('Apply shared styling on the WordPress login screen', 'portalweave-sso'), __('This choice is retained when custom WordPress sign-in styling is off.', 'portalweave-sso'));
        self::field('settings[button][custom_css]', $button['custom_css'], __('Custom button CSS', 'portalweave-sso'), 'textarea', __('Scope rules to .portalweave-sso-login to avoid affecting unrelated site elements. Only trusted administrators can configure CSS.', 'portalweave-sso'));
        self::field('settings[button][logout_message]', $button['logout_message'], __('Logged-in message', 'portalweave-sso'), 'textarea', __('Placeholders: ##user## for the display name and ##Sign Out## for the logout link.', 'portalweave-sso'));
        self::check('settings[button][logout_link]', $button['logout_link'], __('Show a logout link', 'portalweave-sso'));
        echo '</section><section id="portalweave-sso-appearance-panel-wordpress" data-appearance-panel="wordpress"><h3>' . esc_html__('WordPress sign-in', 'portalweave-sso') . '</h3><p>' . esc_html__('Customize provider buttons on the WordPress sign-in page. Shortcode, widget and WooCommerce buttons keep the shared appearance.', 'portalweave-sso') . '</p>';
        self::check('settings[wp_login_button][enabled]', $style['enabled'], __('Customize WordPress sign-in buttons', 'portalweave-sso'), __('Turn this off to inherit the shared settings, including the choice to leave WordPress login styling off. Your custom values are retained. Shared custom CSS is not applied while this customization is on.', 'portalweave-sso'));
        echo '<fieldset class="portalweave-sso-appearance-group"><legend>' . esc_html__('Button text', 'portalweave-sso') . '</legend>';
        self::field('settings[wp_login_button][text]', $style['text'], __('WordPress button text', 'portalweave-sso'), 'text', __('Use {app} for the provider name. Leave blank to inherit the provider label or shared template. Maximum 200 characters.', 'portalweave-sso'), ['maxlength' => 200]);
        echo '</fieldset><fieldset class="portalweave-sso-appearance-group"><legend>' . esc_html__('Colors', 'portalweave-sso') . '</legend><div class="portalweave-sso-appearance-presets" data-appearance-presets>';
        foreach (['light' => [__('Light', 'portalweave-sso'), __('Light preset applied. Custom WordPress styling is on. Save changes to keep it.', 'portalweave-sso')], 'blue' => [__('Blue', 'portalweave-sso'), __('Blue preset applied. Custom WordPress styling is on. Save changes to keep it.', 'portalweave-sso')], 'dark' => [__('Dark', 'portalweave-sso'), __('Dark preset applied. Custom WordPress styling is on. Save changes to keep it.', 'portalweave-sso')]] as $key => [$label, $message]) {
            echo '<button type="button" class="button button-secondary" data-appearance-preset="' . esc_attr($key) . '" data-preset-message="' . esc_attr($message) . '" hidden>' . esc_html($label) . '</button>';
        }
        echo '</div><div class="portalweave-sso-color-fields">';
        foreach (['background' => __('Background', 'portalweave-sso'), 'text_color' => __('Text color', 'portalweave-sso'), 'border_color' => __('Border color', 'portalweave-sso'), 'hover_background' => __('Hover background', 'portalweave-sso'), 'hover_text_color' => __('Hover text color', 'portalweave-sso')] as $key => $label) {
            self::appearance_color($key, $style[$key], $label);
        }
        echo '</div></fieldset><fieldset class="portalweave-sso-appearance-group"><legend>' . esc_html__('Size and spacing', 'portalweave-sso') . '</legend><div class="portalweave-sso-dimension-fields">';
        foreach (['radius' => [__('Corner radius (px)', 'portalweave-sso'), 0, 50], 'font_size' => [__('Font size (px)', 'portalweave-sso'), 10, 32], 'padding_y' => [__('Vertical padding (px)', 'portalweave-sso'), 8, 24], 'padding_x' => [__('Horizontal padding (px)', 'portalweave-sso'), 8, 40]] as $key => [$label, $min, $max]) {
            self::field('settings[wp_login_button][' . $key . ']', $style[$key], $label, 'number', '', ['min' => $min, 'max' => $max]);
        }
        echo '</div>';
        self::check('settings[wp_login_button][full_width]', $style['full_width'], __('Use the full sign-in form width', 'portalweave-sso'));
        self::check('settings[wp_login_button][shadow]', $style['shadow'], __('Show a button shadow', 'portalweave-sso'));
        echo '</fieldset><fieldset class="portalweave-sso-appearance-group"><legend>' . esc_html__('Icon', 'portalweave-sso') . '</legend>';
        self::select('settings[wp_login_button][icon_source]', $style['icon_source'], __('Icon source', 'portalweave-sso'), ['provider' => __('Provider icon', 'portalweave-sso'), 'custom' => __('Custom image', 'portalweave-sso'), 'none' => __('No icon', 'portalweave-sso')], __('Provider icons use the provider image when available and a generic icon otherwise.', 'portalweave-sso'));
        echo '<div data-appearance-custom-icon>';
        self::field('settings[wp_login_button][icon_url]', $style['icon_url'], __('Custom icon URL', 'portalweave-sso'), 'url', __('Use an HTTPS image URL without embedded credentials or a fragment. You can paste a URL without using the media library.', 'portalweave-sso'), ['maxlength' => 2048, 'pattern' => 'https://.+']);
        echo '<div class="portalweave-sso-appearance-media-actions">';
        if (current_user_can('upload_files')) {
            echo '<button type="button" class="button button-secondary" data-appearance-media data-media-title="' . esc_attr__('Choose a sign-in button icon', 'portalweave-sso') . '" data-media-button="' . esc_attr__('Use this image', 'portalweave-sso') . '" data-media-message="' . esc_attr__('Custom image selected. Save changes to keep it.', 'portalweave-sso') . '" hidden>' . esc_html__('Choose image', 'portalweave-sso') . '</button>';
        }
        echo '<button type="button" class="button button-secondary" data-appearance-remove data-remove-message="' . esc_attr__('Custom image cleared. Choose another image, provider icons or no icon before saving.', 'portalweave-sso') . '" hidden>' . esc_html__('Remove image', 'portalweave-sso') . '</button></div></div><div class="portalweave-sso-dimension-fields">';
        self::field('settings[wp_login_button][icon_size]', $style['icon_size'], __('Icon size (px)', 'portalweave-sso'), 'number', '', ['min' => 12, 'max' => 48]);
        self::select('settings[wp_login_button][icon_position]', $style['icon_position'], __('Icon position', 'portalweave-sso'), ['left' => __('Left of text', 'portalweave-sso'), 'right' => __('Right of text', 'portalweave-sso')]);
        echo '</div></fieldset></section></div>';
        self::appearance_preview($settings, $style, $section);
        echo '</div></section>';
    }

    private static function appearance_color(string $key, string $value, string $label): void {
        $id = 'portalweave-sso-wp-color-' . $key;
        $color = sanitize_hex_color($value) ?: '#ffffff';
        if (strlen($color) === 4) {
            $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }
        echo '<div class="portalweave-sso-appearance-color"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input type="text" id="' . esc_attr($id) . '" name="settings[wp_login_button][' . esc_attr($key) . ']" value="' . esc_attr($value) . '" pattern="#[a-fA-F0-9]{3}([a-fA-F0-9]{3})?" maxlength="7" required spellcheck="false" data-appearance-color="' . esc_attr($key) . '" aria-describedby="' . esc_attr($id . '-help') . '"><input type="color" value="' . esc_attr($color) . '" data-appearance-swatch="' . esc_attr($key) . '" aria-label="' . esc_attr($label) . '" hidden><p class="description" id="' . esc_attr($id . '-help') . '">' . esc_html__('Hex color, for example #ffffff.', 'portalweave-sso') . '</p></div>';
    }

    private static function appearance_preview(array $settings, array $style, string $section): void {
        $apps = [];
        foreach ($settings['apps'] as $id => $app) {
            $apps[$id] = ['name' => $app['name'] ?: (string) $id, 'text' => $app['button_text'] ?? '', 'icon' => Display::provider_icon($app), 'shared_icon' => !empty($app['icon_url']) && Config::https_url($app['icon_url']) ? $app['icon_url'] : ''];
        }
        uksort($apps, static fn($first, $second) => strcasecmp($apps[$first]['name'], $apps[$second]['name']) ?: strcmp((string) $first, (string) $second));
        $sample = !$apps;
        if ($sample) {
            $apps['example'] = ['name' => __('Example provider', 'portalweave-sso'), 'text' => '', 'icon' => Display::provider_icon(['provider' => 'custom']), 'shared_icon' => ''];
        }
        $selected = isset($apps[$settings['default_app']]) ? $settings['default_app'] : array_key_first($apps);
        $app = $apps[$selected];
        $custom = $section === 'wordpress' && !empty($style['enabled']);
        $shared = $settings['button'];
        $styled = $section === 'shared' || $custom || !empty($shared['admin_style']);
        $label = $app['text'] ?: str_replace('{app}', $app['name'], $shared['text']);
        if ($custom) {
            $label = $style['text'] !== '' ? str_replace('{app}', $app['name'], $style['text']) : $label;
            if ($label === '') {
            /* translators: %s: provider name. */
                $label = sprintf(__('Sign in with %s', 'portalweave-sso'), $app['name']);
            }
        }
        $theme = $custom ? 'custom' : (in_array($shared['theme'], ['default', 'white', 'hover', 'custom', 'smart'], true) ? $shared['theme'] : 'default');
        $shape = $custom ? 'round-edge' : str_replace('_', '-', $shared['shape']);
        $classes = $styled ? 'portalweave-sso-button portalweave-sso-theme-' . $theme . ' portalweave-sso-shape-' . $shape : 'portalweave-sso-preview-unstyled';
        $classes .= ($custom ? $style['shadow'] : $shared['shadow']) && $styled ? ' portalweave-sso-shadow' : '';
        $classes .= !$custom && $shared['transform'] && $styled ? ' portalweave-sso-transform' : '';
        $classes .= $custom && $style['icon_position'] === 'right' ? ' portalweave-sso-icon-right' : '';
        $icon = $custom ? ($style['icon_source'] === 'custom' ? $style['icon_url'] : ($style['icon_source'] === 'provider' ? $app['icon'] : '')) : $app['shared_icon'];
        $icon = $icon !== '' && Config::https_url($icon) ? $icon : '';
        $custom_note = __('Custom WordPress sign-in styling is on. Save changes to apply it.', 'portalweave-sso');
        $shared_note = __('Shared button preview. WordPress sign-in can use its own appearance.', 'portalweave-sso');
        $inherit_note = __('WordPress sign-in inherits the shared button settings. Custom CSS and theme styles can affect the final appearance.', 'portalweave-sso');
        $unstyled_note = __('Shared WordPress login styling is off. Your theme controls the provider links until you enable styling.', 'portalweave-sso');
        echo '<aside class="portalweave-sso-appearance-preview" id="portalweave-sso-appearance-preview" aria-label="' . esc_attr__('Button appearance preview', 'portalweave-sso') . '"><h3>' . esc_html__('Preview', 'portalweave-sso') . '</h3><label for="portalweave-sso-preview-provider">' . esc_html__('Preview provider', 'portalweave-sso') . '</label><select id="portalweave-sso-preview-provider" data-appearance-provider>';
        foreach ($apps as $id => $provider) {
            echo '<option value="' . esc_attr((string) $id) . '" data-provider-name="' . esc_attr($provider['name']) . '" data-provider-text="' . esc_attr($provider['text']) . '" data-provider-icon="' . esc_url($provider['icon']) . '" data-shared-icon="' . esc_url($provider['shared_icon']) . '"' . selected($selected, $id, false) . '>' . esc_html($provider['name']) . '</option>';
        }
        echo '</select><div class="portalweave-sso-preview-canvas"><div class="portalweave-sso-login' . ($custom ? ' portalweave-sso-login-screen' : '') . '" data-appearance-preview-root><p class="portalweave-sso-label" data-appearance-preview-above' . ($shared['above_text'] === '' ? ' hidden' : '') . '>' . esc_html($shared['above_text']) . '</p><button type="button" class="' . esc_attr($classes) . '" data-appearance-preview-button><img class="portalweave-sso-icon" data-appearance-preview-icon alt="" referrerpolicy="no-referrer"' . ($icon !== '' ? ' src="' . esc_url($icon) . '"' : ' hidden') . '><span class="portalweave-sso-mark" data-appearance-preview-mark aria-hidden="true"' . ($custom || $icon !== '' ? ' hidden' : '') . '>' . esc_html(strtoupper(mb_substr($app['name'], 0, 1))) . '</span><span data-appearance-preview-label>' . esc_html($label) . '</span></button></div></div><p class="portalweave-sso-preview-note" data-appearance-note data-note-shared="' . esc_attr($shared_note) . '" data-note-custom="' . esc_attr($custom_note) . '" data-note-inherit="' . esc_attr($inherit_note) . '" data-note-unstyled="' . esc_attr($unstyled_note) . '" data-default-template="' . esc_attr__('Sign in with {app}', 'portalweave-sso') . '">' . esc_html($section === 'shared' ? $shared_note : ($custom ? $custom_note : ($styled ? $inherit_note : $unstyled_note))) . '</p><p class="portalweave-sso-preview-note">' . esc_html__('Preview only. This button does not start sign-in.', 'portalweave-sso') . '</p>';
        if ($sample) {
            echo '<p class="portalweave-sso-preview-note">' . esc_html__('No providers are configured. This preview uses a fictional provider.', 'portalweave-sso') . '</p>';
        }
        echo '<p class="portalweave-sso-appearance-status" data-appearance-status role="status" aria-live="polite" aria-atomic="true"></p></aside>';
    }

    private static function provider_logo(string $prefix, string $url): void {
        echo '<div class="portalweave-sso-provider-logo" data-provider-logo data-logo-invalid="' . esc_attr__('Choose an HTTPS image URL without embedded credentials or a fragment. Media library images must also be served over HTTPS.', 'portalweave-sso') . '" data-logo-selected="' . esc_attr__('Custom logo selected. Save provider to apply it.', 'portalweave-sso') . '" data-logo-removed="' . esc_attr__('Custom logo removed. Save provider to apply it.', 'portalweave-sso') . '" data-logo-error="' . esc_attr__('The image could not be loaded. Check its URL or choose another image.', 'portalweave-sso') . '">';
        self::field($prefix . '[icon_url]', $url, __('Custom logo URL', 'portalweave-sso'), 'url', __('Choose or upload an image, or paste an HTTPS image URL you control. This provider logo is used by shared buttons and the WordPress provider-icon option. Leave blank for the default appearance.', 'portalweave-sso'), ['maxlength' => 2048, 'pattern' => 'https://.+']);
        echo '<div class="portalweave-sso-provider-logo-actions">';
        if (current_user_can('upload_files')) {
            echo '<button type="button" class="button button-secondary" data-logo-media data-media-title="' . esc_attr__('Choose a provider button logo', 'portalweave-sso') . '" data-media-button="' . esc_attr__('Use this image', 'portalweave-sso') . '" hidden>' . esc_html__('Choose or upload image', 'portalweave-sso') . '</button>';
        }
        echo '<button type="button" class="button button-secondary" data-logo-remove hidden>' . esc_html__('Remove custom logo', 'portalweave-sso') . '</button></div><div class="portalweave-sso-provider-logo-preview" data-logo-preview hidden><img data-logo-image alt="" referrerpolicy="no-referrer" width="64" height="64"><span>' . esc_html__('Custom logo preview', 'portalweave-sso') . '</span></div><p data-logo-status role="status" aria-live="polite" aria-atomic="true"></p></div>';
    }

    private static function field(string $name, $value, string $label, string $type = 'text', string $help = '', array $attributes = []): void {
        $id = 'portalweave-sso-' . substr(md5($name), 0, 12);
        echo '<div class="portalweave-sso-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="4" spellcheck="false"' . ($help !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '') . '>' . esc_textarea((string) $value) . '</textarea>';
        } else {
            echo '<input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr((string) $value) . '"' . ($help !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '') . ' autocomplete="off"';
            $attributes = $type === 'number' ? array_replace(['min' => 0, 'step' => 1], $attributes) : $attributes;
            foreach (array_intersect_key($attributes, array_fill_keys(['min', 'max', 'step', 'maxlength', 'pattern'], true)) as $attribute => $setting) {
                echo ' ' . esc_attr($attribute) . '="' . esc_attr((string) $setting) . '"';
            }
            echo '>';
        }
        if ($help !== '') {
            echo '<p class="description" id="' . esc_attr($id . '-help') . '">' . esc_html($help) . '</p>';
        }
        echo '</div>';
    }

    private static function check(string $name, $value, string $label, string $help = ''): void {
        $id = 'portalweave-sso-' . substr(md5($name), 0, 12);
        echo '<div class="portalweave-sso-check"><input type="hidden" name="' . esc_attr($name) . '" value="0"><label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"' . checked((bool) $value, true, false) . ($help !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '') . '> ' . esc_html($label) . '</label>';
        if ($help !== '') {
            echo '<p class="description" id="' . esc_attr($id . '-help') . '">' . esc_html($help) . '</p>';
        }
        echo '</div>';
    }

    private static function select(string $name, $value, string $label, array $choices, string $help = '', string $class = ''): void {
        $id = 'portalweave-sso-' . substr(md5($name), 0, 12);
        echo '<div class="portalweave-sso-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="' . esc_attr($class) . '"' . ($help !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '') . '>';
        foreach ($choices as $key => $text) {
            echo '<option value="' . esc_attr($key) . '"' . selected((string) $value, (string) $key, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
        if ($help !== '') {
            echo '<p class="description" id="' . esc_attr($id . '-help') . '">' . esc_html($help) . '</p>';
        }
        echo '</div>';
    }

    private static function secret(string $prefix, string $key, bool $configured, string $label): void {
        $name = $prefix . '[' . $key . ']';
        $id = 'portalweave-sso-' . substr(md5($name), 0, 12);
        echo '<div class="portalweave-sso-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="password" value="" autocomplete="new-password" aria-describedby="' . esc_attr($id . '-help') . '" placeholder="' . esc_attr($configured ? __('Configured — leave blank to keep', 'portalweave-sso') : __('Not configured', 'portalweave-sso')) . '"><p class="description" id="' . esc_attr($id . '-help') . '">' . esc_html__('Enter a new value to replace it. Stored values are never printed in this page.', 'portalweave-sso') . '</p></div>';
        /* translators: %s: credential field label. */
        self::check($prefix . '[clear_' . $key . ']', false, sprintf(__('Clear stored %s', 'portalweave-sso'), $label));
    }

    private static function json(string $name, array $value, string $label, string $help): void {
        preg_match('/\[([a-z_]+)\]$/', $name, $match);
        $type = $match[1] ?? '';
        $roles = [];
        foreach (wp_roles()->roles as $key => $role) {
            $roles[$key] = translate_user_role($role['name']);
        }
        $labels = [
            'visual' => __('Visual editor', 'portalweave-sso'), 'advanced' => __('Advanced JSON', 'portalweave-sso'),
            'add' => __('Add rule', 'portalweave-sso'), 'remove' => __('Remove rule', 'portalweave-sso'),
            'path' => __('Provider attribute path', 'portalweave-sso'), 'meta' => __('Profile field key', 'portalweave-sso'),
            'group' => __('Provider group', 'portalweave-sso'), 'domain' => __('Email domain', 'portalweave-sso'),
            'target_domain' => __('Replacement email domain', 'portalweave-sso'), 'roles' => __('WordPress roles', 'portalweave-sso'),
            'claim' => __('Provider attribute path', 'portalweave-sso'), 'equals' => __('Matches value', 'portalweave-sso'),
            'value_type' => __('Value type', 'portalweave-sso'), 'string' => __('Text', 'portalweave-sso'),
            'number' => __('Number', 'portalweave-sso'), 'boolean' => __('Boolean', 'portalweave-sso'), 'null' => __('Null', 'portalweave-sso'),
            'url' => __('Redirect URL', 'portalweave-sso'), 'target' => __('Content type', 'portalweave-sso'),
            'posts' => __('Post or page', 'portalweave-sso'), 'categories' => __('Category', 'portalweave-sso'),
            'id' => __('Content ID', 'portalweave-sso'), 'login' => __('Require sign-in', 'portalweave-sso'),
            'groups' => __('Provider groups (one per line)', 'portalweave-sso'),
            'empty' => __('No rules configured. Add a rule to get started.', 'portalweave-sso'),
            'unsupported' => __('This JSON uses a structure the visual editor cannot represent. Continue editing JSON; your values have been kept.', 'portalweave-sso'),
            'invalid' => __('Enter a valid JSON object or array before switching to the visual editor.', 'portalweave-sso'),
            'duplicate' => __('Each source must be unique. Change or remove the duplicate rule.', 'portalweave-sso'),
            'required' => __('Complete this rule or remove it.', 'portalweave-sso'),
            'invalid_value' => __('Enter a valid value for the selected type.', 'portalweave-sso'),
            'roles_help' => __('Select one or more roles. Existing administrator verification requirements still apply.', 'portalweave-sso'),
            'row' => __('Rule', 'portalweave-sso'),
        ];
        $standard = [
            'username' => __('Username', 'portalweave-sso'), 'email' => __('Email', 'portalweave-sso'),
            'first_name' => __('First name', 'portalweave-sso'), 'last_name' => __('Last name', 'portalweave-sso'),
            'display_name' => __('Display name', 'portalweave-sso'), 'groups' => __('Groups', 'portalweave-sso'),
        ];
        echo '<div class="portalweave-sso-mapping" data-mapping-editor="' . esc_attr($type) . '" data-mapping-config="' . esc_attr(wp_json_encode(['labels' => $labels, 'roles' => $roles, 'standard' => $standard, 'defaults' => Config::app_defaults()['mapping']])) . '">';
        self::field($name, wp_json_encode($value ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $label, 'textarea', $help);
        echo '</div>';
    }

    private static function activity_navigation(string $view, int $days, bool $network): void {
        echo '<nav class="portalweave-sso-activity-tabs" aria-label="' . esc_attr__('Activity views', 'portalweave-sso') . '">';
        foreach (['overview' => __('Overview', 'portalweave-sso'), 'audit' => __('Audit logs', 'portalweave-sso')] as $key => $label) {
            echo '<a href="' . esc_url(self::screen_url('reports', $network, ['activity_view' => $key, 'activity_days' => $days])) . '" data-activity-view="' . esc_attr($key) . '"' . ($key === $view ? ' class="is-active" aria-current="true"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function activity_overview(int $days, bool $network): void {
        $period_urls = [];
        foreach ([7, 30, 90] as $period) {
            $period_urls[$period] = self::screen_url('reports', $network, ['activity_view' => 'overview', 'activity_days' => $period]);
        }
        // Audit history always belongs to the current site, including in Network Admin.
        $provider_names = array_map(static fn($app) => (string) ($app['name'] ?? ''), Config::apps());
        ActivityView::render(Audit::summary($days), $provider_names, $period_urls, $days, (bool) Config::get('audit_enabled', true));
    }

    private static function reports(array $settings, bool $network, int $days = 30): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- These bounded integers select read-only history; the page capability check gates all audit output.
        $page = self::audit_number($_GET, 'audit_page', 1);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An integer row ceiling only stabilizes navigation through this site's retained audit events.
        $snapshot = self::audit_number($_GET, 'audit_snapshot');
        $history = Audit::page($page, 50, $snapshot);
        $url = wp_nonce_url(add_query_arg(['action' => 'portalweave_sso_export', 'kind' => 'audit', 'network' => $network ? 1 : 0], admin_url('admin-post.php')), 'portalweave_sso_export');
        echo '<section id="portalweave-sso-reports" class="portalweave-sso-section"><div class="portalweave-sso-section-title"><h2>' . esc_html__('SSO transaction and login audit', 'portalweave-sso') . '</h2><a class="button" href="' . esc_url($url) . '">' . esc_html__('Export audit CSV', 'portalweave-sso') . '</a></div><p>' . esc_html__('Retained events for this site, newest first, 50 per page. Timestamps are UTC. CSV exports the latest 5,000 rows independently of this page.', 'portalweave-sso') . '</p>';
        if (is_wp_error($history)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($history->get_error_message()) . '</p></div></section>';
            return ['page' => $page, 'snapshot' => $snapshot];
        }
        self::audit_pagination($history, $network, $days, 'top');
        echo '<div class="portalweave-sso-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('Audit events', 'portalweave-sso') . '"><table class="widefat striped"><thead><tr>';
        foreach ([__('User', 'portalweave-sso'), __('Status', 'portalweave-sso'), __('App', 'portalweave-sso'), __('Date (UTC)', 'portalweave-sso'), __('Email', 'portalweave-sso'), __('Client IP', 'portalweave-sso'), __('Navigation URL', 'portalweave-sso'), __('Error code', 'portalweave-sso')] as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $rows = $history['rows'];
        foreach ($rows as $row) {
            echo '<tr data-audit-id="' . esc_attr((string) $row['id']) . '">';
            foreach (['username', 'status', 'app', 'created_at', 'email', 'client_ip', 'navigation', 'error_code'] as $key) {
                echo '<td>' . esc_html((string) ($row[$key] ?? '')) . '</td>';
            }
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="8">' . esc_html__('No retained events in this view. Refresh latest to check for new events.', 'portalweave-sso') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::audit_pagination($history, $network, $days, 'bottom');
        echo '</section>';
        return ['page' => $history['page'], 'snapshot' => $history['snapshot']];
    }

    private static function audit_pagination(array $history, bool $network, int $days, string $position): void {
        $page = $history['page'];
        $pages = $history['pages'];
        $total = $history['total'];
        $first = $history['rows'] ? ($page - 1) * $history['per_page'] + 1 : 0;
        $last = $first ? $first + count($history['rows']) - 1 : 0;
        $args = ['activity_view' => 'audit', 'activity_days' => $days, 'audit_snapshot' => $history['snapshot']];
        echo '<nav class="portalweave-sso-audit-pagination" data-audit-pagination="' . esc_attr($position) . '" data-audit-page="' . esc_attr((string) $page) . '" data-audit-pages="' . esc_attr((string) $pages) . '" aria-label="' . esc_attr($position === 'top' ? __('Audit pagination above events', 'portalweave-sso') : __('Audit pagination below events', 'portalweave-sso')) . '"><span class="portalweave-sso-audit-count">';
        /* translators: 1: first event number, 2: last event number, 3: total retained events. */
        echo esc_html(sprintf(__('%1$s–%2$s of %3$s events', 'portalweave-sso'), number_format_i18n($first), number_format_i18n($last), number_format_i18n($total)));
        echo '</span><div class="portalweave-sso-audit-page-actions">';
        foreach ([
            ['first', 1, __('First', 'portalweave-sso'), $page > 1],
            ['previous', $page - 1, __('Previous', 'portalweave-sso'), $page > 1],
        ] as [$key, $target, $label, $enabled]) {
            self::audit_page_link($key, $target, $label, $enabled, $network, $args);
        }
        /* translators: 1: current page, 2: total pages. */
        echo '<span class="portalweave-sso-audit-page-number">' . esc_html(sprintf(__('Page %1$s of %2$s', 'portalweave-sso'), number_format_i18n($page), number_format_i18n($pages))) . '</span>';
        foreach ([
            ['next', $page + 1, __('Next', 'portalweave-sso'), $page < $pages],
            ['last', $pages, __('Last', 'portalweave-sso'), $page < $pages],
        ] as [$key, $target, $label, $enabled]) {
            self::audit_page_link($key, $target, $label, $enabled, $network, $args);
        }
        echo '</div><a class="portalweave-sso-audit-refresh" href="' . esc_url(self::screen_url('reports', $network, ['activity_view' => 'audit', 'activity_days' => $days])) . '">' . esc_html__('Refresh latest', 'portalweave-sso') . '</a></nav>';
    }

    private static function audit_page_link(string $key, int $target, string $label, bool $enabled, bool $network, array $args): void {
        if ($enabled) {
            echo '<a class="button" data-audit-link="' . esc_attr($key) . '" href="' . esc_url(self::screen_url('reports', $network, $args + ['audit_page' => $target])) . '">' . esc_html($label) . '</a>';
        } else {
            echo '<span class="button disabled" aria-disabled="true">' . esc_html($label) . '</span>';
        }
    }

    private static function tools(array $settings, bool $network, bool $enforced): void {
        echo '<section id="portalweave-sso-tools" class="portalweave-sso-section"><h2>' . esc_html__('Troubleshooting and admin tools', 'portalweave-sso') . '</h2><div class="portalweave-sso-callout"><strong>' . esc_html__('Recovery before enforcement', 'portalweave-sso') . '</strong><p>' . esc_html__('Test a non-admin account first, then verify an approved administrator account. Keep filesystem or WP-CLI access available so forced login can be disabled if your provider is unavailable.', 'portalweave-sso') . '</p></div>';
        echo '<details class="portalweave-sso-subsection"><summary>' . esc_html__('Troubleshooting, FAQs and AI setup', 'portalweave-sso') . '</summary><h3>' . esc_html__('Common errors', 'portalweave-sso') . '</h3><dl class="portalweave-sso-errors">';
        foreach ([
            __('State / browser binding', 'portalweave-sso') => __('Restart sign-in from the original browser. Ensure cookies work and the callback hostname and HTTPS scheme match the initiating login origin. Do not cache login or callback routes.', 'portalweave-sso'),
            __('Invalid issuer or audience', 'portalweave-sso') => __('Copy the exact issuer and client ID from the provider registration. Tenant-specific issuers must match exactly; the token audience must include this app’s client ID.', 'portalweave-sso'),
            __('JWT signature / key unavailable', 'portalweave-sso') => __('Check the pinned algorithm and JWKS URL. Confirm outbound HTTPS and current provider key rotation. Never solve a signature error by disabling validation.', 'portalweave-sso'),
            __('Email not verified / admin blocked', 'portalweave-sso') => __('Check the configured verified claim and expected JSON value. Administrator SSO is disabled by default and always requires the verified claim.', 'portalweave-sso'),
            __('No role match / restricted domain', 'portalweave-sso') => __('Inspect raw attributes using Test SSO Config. Map the returned groups and check exact allowed and blocked email domains.', 'portalweave-sso'),
            __('Redirect rejected', 'portalweave-sso') => __('Use a same-origin URL or add the exact trusted HTTPS origin/path to the redirect allowlist. The callback must route back to this WordPress instance.', 'portalweave-sso'),
        ] as $title => $answer) {
            echo '<dt>' . esc_html($title) . '</dt><dd>' . esc_html($answer) . '</dd>';
        }
        echo '</dl><h3>' . esc_html__('FAQs', 'portalweave-sso') . '</h3><details class="portalweave-sso-subsection"><summary>' . esc_html__('Which provider endpoint URLs should I use?', 'portalweave-sso') . '</summary><p>' . esc_html__('Use your provider’s tenant-specific OpenID discovery document to copy issuer, authorization_endpoint, token_endpoint, userinfo_endpoint, jwks_uri, and end_session_endpoint. The templates are starting points; the provider registration is authoritative.', 'portalweave-sso') . '</p></details><details class="portalweave-sso-subsection"><summary>' . esc_html__('How are direct media URLs protected?', 'portalweave-sso') . '</summary><p>' . esc_html__('Protected originals and derivatives are moved into a writable private directory outside the web document root, and WordPress generates authenticated delivery URLs. Purge old web-server and CDN cached copies when protecting a file. Attachment-page rules alone do not relocate files; use the protected attachment control or the protected-ID list.', 'portalweave-sso') . '</p></details><details class="portalweave-sso-subsection"><summary>' . esc_html__('Will this create user accounts automatically?', 'portalweave-sso') . '</summary><p>' . esc_html__('Only when the provider’s automatic registration setting is enabled. Existing-account linking is a separate opt-in and requires a verified, unique email.', 'portalweave-sso') . '</p></details>';
        /* translators: %s: required WordPress capability, displayed as code. */
        $mcp_auth = sprintf(__('Enable AI / MCP abilities on the Integrations screen. Authenticate your MCP client with an administrator who has %s.', 'portalweave-sso'), '<code>manage_options</code>');
        /* translators: %s: ability namespace, displayed as code. */
        $mcp_discovery = sprintf(__('Discover the eight %s abilities for status, provider configuration, validation, diagnosis, repair, audit, support preparation, and support submission. Review configuration changes before asking a client to execute them.', 'portalweave-sso'), '<code>portalweave-sso/*</code>');
        echo '<h3>' . esc_html__('AI / MCP setup', 'portalweave-sso') . '</h3><ol><li>' . esc_html__('Use WordPress 6.9 or later and install the official WordPress MCP Adapter for MCP transport.', 'portalweave-sso') . '</li><li>' . wp_kses($mcp_auth, ['code' => []]) . '</li><li>' . esc_html__('Connect Claude Desktop, Cursor, ChatGPT, or another MCP client to your adapter’s authenticated endpoint. Follow the adapter’s current transport and authentication setup.', 'portalweave-sso') . '</li><li>' . wp_kses($mcp_discovery, ['code' => []]) . '</li></ol><p>' . esc_html__('Disabling the abilities setting removes execution access. Abilities never return client IDs, secrets, access tokens, or refresh tokens.', 'portalweave-sso') . '</p>';
        $export = wp_nonce_url(add_query_arg(['action' => 'portalweave_sso_export', 'kind' => 'configuration', 'network' => $network ? 1 : 0], admin_url('admin-post.php')), 'portalweave_sso_export');
        echo '</details><h3>' . esc_html__('Configuration backup', 'portalweave-sso') . '</h3><p><a class="button" href="' . esc_url($export) . '">' . esc_html__('Download configuration backup', 'portalweave-sso') . '</a></p><p class="description">' . esc_html__('Credential-free JSON includes mappings and policy. Client IDs, all secrets, and tokens are excluded. Restore retains existing credentials by app ID; on a clean installation, incomplete providers and login enforcement remain disabled until credentials are entered. Retain an independent WordPress database backup for complete recovery.', 'portalweave-sso') . '</p>';
        if (!$enforced) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" data-pending-label="' . esc_attr__('Importing…', 'portalweave-sso') . '" data-pending-message="' . esc_attr__('Importing your configuration…', 'portalweave-sso') . '"><input type="hidden" name="action" value="portalweave_sso_import">';
            wp_nonce_field('portalweave_sso_import');
            self::hidden_scope($network, 'tools');
            echo '<label for="portalweave-sso-import">' . esc_html__('Restore configuration JSON (maximum 1 MB)', 'portalweave-sso') . '</label><p><input type="file" id="portalweave-sso-import" name="configuration" accept="application/json,.json" required> <button type="submit" class="button">' . esc_html__('Import configuration', 'portalweave-sso') . '</button></p></form>';
        }
        echo '<h3>' . esc_html__('Support', 'portalweave-sso') . '</h3><p>' . esc_html__('Support destinations are configured by your site administrator.', 'portalweave-sso') . '</p><p class="portalweave-sso-support-links">';
        foreach (['booking_url' => __('Book a call / screen-share', 'portalweave-sso'), 'forum_url' => __('Visit support forum', 'portalweave-sso'), 'demo_url' => __('Request a demo / trial', 'portalweave-sso')] as $key => $label) {
            if (!empty($settings['support'][$key])) {
                echo '<a class="button" href="' . esc_url($settings['support'][$key]) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a> ';
            }
        }
        echo '</p>';
        if (!empty($settings['support']['email'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-pending-label="' . esc_attr__('Sending…', 'portalweave-sso') . '" data-pending-message="' . esc_attr__('Submitting your support message…', 'portalweave-sso') . '"><input type="hidden" name="action" value="portalweave_sso_support">';
            wp_nonce_field('portalweave_sso_support');
            self::hidden_scope($network, 'tools');
            /* translators: %s: support recipient email address. */
            self::field('message', '', __('Support message', 'portalweave-sso'), 'textarea', sprintf(__('Sent to %s when you submit this form.', 'portalweave-sso'), $settings['support']['email']));
            self::check('include_configuration', false, __('Send Plugin Configuration with this message', 'portalweave-sso'), __('Excludes client IDs, secrets, passwords, bearer tokens, and refresh tokens. Other policy and endpoint data will be included.', 'portalweave-sso'));
            echo '<p><button class="button" type="submit">' . esc_html__('Submit support query', 'portalweave-sso') . '</button></p></form>';
        }
        echo '</section>';
    }

    public static function meta_boxes(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ($type !== 'attachment') {
                add_meta_box('portalweave-sso-policy', __('SSO access restriction', 'portalweave-sso'), [self::class, 'post_box'], $type, 'side');
            }
        }
    }

    public static function post_box(\WP_Post $post): void {
        wp_nonce_field('portalweave_sso_policy', 'portalweave_sso_policy_nonce');
        $rule = get_post_meta($post->ID, '_portalweave_sso_rule', true);
        self::policy_fields(is_array($rule) ? $rule : []);
    }

    private static function policy_fields(array $rule): void {
        self::check('portalweave_sso_rule[login]', $rule['login'] ?? false, __('Require a logged-in user', 'portalweave-sso'));
        self::field('portalweave_sso_rule[roles]', implode(', ', $rule['roles'] ?? []), __('Allowed role slugs', 'portalweave-sso'), 'text', __('Comma separated. Leave empty for any role.', 'portalweave-sso'));
        self::field('portalweave_sso_rule[groups]', implode(', ', $rule['groups'] ?? []), __('Allowed provider groups', 'portalweave-sso'), 'text', __('Comma separated. A matching role or group grants access; logged-out users are sent to SSO.', 'portalweave-sso'));
    }

    private static function submitted_rule(): ?array {
        if (empty($_POST['portalweave_sso_policy_nonce']) || !is_string($_POST['portalweave_sso_policy_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['portalweave_sso_policy_nonce'])), 'portalweave_sso_policy')) {
            return null;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only three whitelisted scalar fields are consumed and individually sanitized below.
        $input = isset($_POST['portalweave_sso_rule']) && is_array($_POST['portalweave_sso_rule']) ? array_intersect_key(wp_unslash($_POST['portalweave_sso_rule']), ['login' => true, 'roles' => true, 'groups' => true]) : [];
        foreach ($input as $value) {
            if (!is_string($value)) {
                return null;
            }
        }
        return ['login' => !empty($input['login']), 'roles' => array_values(array_filter(array_map('sanitize_key', self::lines($input['roles'] ?? '')))), 'groups' => array_values(array_filter(array_map('sanitize_text_field', self::lines($input['groups'] ?? ''))))];
    }

    public static function save_post(int $post_id, \WP_Post $post): void {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('manage_options') || !current_user_can('edit_post', $post_id)) {
            return;
        }
        $rule = self::submitted_rule();
        if ($rule !== null) {
            update_post_meta($post_id, '_portalweave_sso_rule', $rule);
        }
    }

    public static function category_add(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="form-field"><h3>' . esc_html__('SSO access restriction', 'portalweave-sso') . '</h3>';
        wp_nonce_field('portalweave_sso_policy', 'portalweave_sso_policy_nonce');
        self::policy_fields([]);
        echo '</div>';
    }

    public static function category_edit(\WP_Term $term): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<tr class="form-field"><th scope="row">' . esc_html__('SSO access restriction', 'portalweave-sso') . '</th><td>';
        wp_nonce_field('portalweave_sso_policy', 'portalweave_sso_policy_nonce');
        $rule = get_term_meta($term->term_id, '_portalweave_sso_rule', true);
        self::policy_fields(is_array($rule) ? $rule : []);
        echo '</td></tr>';
    }

    public static function save_category(int $term_id): void {
        if (!current_user_can('manage_options') || !current_user_can('manage_categories')) {
            return;
        }
        $rule = self::submitted_rule();
        if ($rule !== null) {
            update_term_meta($term_id, '_portalweave_sso_rule', $rule);
        }
    }

    public static function attachment_fields(array $fields, \WP_Post $post): array {
        if (current_user_can('manage_options') && current_user_can('edit_post', $post->ID)) {
            $fields['portalweave_sso_protected'] = ['label' => __('SSO protected', 'portalweave-sso'), 'input' => 'html', 'html' => '<label><input type="checkbox" name="attachments[' . absint($post->ID) . '][portalweave_sso_protected]" value="1"' . checked(Access::is_protected($post->ID), true, false) . '>' . esc_html__(' Require SSO', 'portalweave-sso') . '</label><p>' . esc_html__('Moves originals and derivatives outside the document root. Purge cached copies from your web server and CDN.', 'portalweave-sso') . '</p>'];
        }
        return $fields;
    }

    public static function attachment_save(array $post, array $attachment): array {
        // The core media save handler verifies its nonce before applying this filter.
        if (current_user_can('manage_options') && current_user_can('edit_post', $post['ID'])) {
            update_post_meta((int) $post['ID'], '_portalweave_sso_protected', !empty($attachment['portalweave_sso_protected']) ? 1 : 0);
        }
        return $post;
    }
}
