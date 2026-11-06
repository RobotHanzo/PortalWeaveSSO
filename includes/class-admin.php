<?php
namespace WPSSO;

defined('ABSPATH') || exit;

/** Administrative configuration, reporting, and content policy controls. */
final class Admin {
    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('network_admin_menu', [self::class, 'network_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        foreach (['save', 'export', 'import', 'support'] as $action) {
            add_action('admin_post_wpsso_' . $action, [self::class, $action]);
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
        add_options_page(__('STSA Identity SSO', 'stsa-identity-sso'), __('STSA Identity SSO', 'stsa-identity-sso'), 'manage_options', 'wpsso', [self::class, 'page']);
    }

    public static function network_menu(): void {
        add_menu_page(__('STSA Identity SSO for networks', 'stsa-identity-sso'), __('STSA Identity SSO', 'stsa-identity-sso'), 'manage_network_options', 'wpsso', [self::class, 'page'], 'dashicons-shield');
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'wpsso') === false) {
            return;
        }
        $version = defined('WPSSO_VERSION') ? WPSSO_VERSION : '1.0.0';
        wp_enqueue_style('wpsso-admin', plugins_url('../assets/admin.css', __FILE__), [], $version);
        wp_enqueue_script('wpsso-admin', plugins_url('../assets/admin.js', __FILE__), [], $version, ['in_footer' => true, 'strategy' => 'defer']);
    }

    private static function is_network_request(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects a capability and settings scope; every mutation separately verifies its nonce.
        return is_multisite() && isset($_REQUEST['network']) && is_string($_REQUEST['network']) && $_REQUEST['network'] === '1';
    }

    private static function require_access(): bool {
        $network = self::is_network_request();
        if (!current_user_can($network ? 'manage_network_options' : 'manage_options')) {
            wp_die(esc_html__('You cannot manage SSO settings.', 'stsa-identity-sso'), '', ['response' => 403]);
        }
        return $network;
    }

    private static function settings(bool $network): array {
        if (!$network) {
            return Config::all();
        }
        $stored = get_site_option('wpsso_settings', []);
        $stored = is_array($stored) ? $stored : [];
        $settings = array_replace_recursive(Config::defaults(), $stored);
        // Do not inherit providers from a site into network administration.
        $settings['apps'] = $stored['apps'] ?? [];
        return $settings;
    }

    private static function return_url(bool $network, string $notice = ''): string {
        $url = $network ? network_admin_url('admin.php?page=wpsso') : admin_url('options-general.php?page=wpsso');
        return $notice === '' ? $url : add_query_arg('wpsso_notice', $notice, $url);
    }

    private static function fail($error, bool $network): void {
        $message = is_wp_error($error) ? $error->get_error_message() : (string) $error;
        set_transient('wpsso_admin_error_' . get_current_user_id(), $message, 60);
        wp_safe_redirect(self::return_url($network, 'error'));
        exit;
    }

    public static function save(): void {
        $network = self::require_access();
        check_admin_referer('wpsso_save');
        if (!isset($_POST['wpsso_complete']) || !is_string($_POST['wpsso_complete']) || sanitize_text_field(wp_unslash($_POST['wpsso_complete'])) !== '1') {
            self::fail(__('The settings form was truncated. Increase PHP max_input_vars for this number of providers, then retry.', 'stsa-identity-sso'), $network);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Form types and permitted keys are validated below; Config::save() sanitizes each setting without corrupting credentials or JSON.
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? self::form_settings(wp_unslash($_POST['settings'])) : [];
        if (is_wp_error($input)) {
            self::fail($input, $network);
        }
        if (!$input) {
            self::fail(__('No settings were submitted. Check the server POST size limit.', 'stsa-identity-sso'), $network);
        }
        $input['apps'] = isset($input['apps']) && is_array($input['apps']) ? $input['apps'] : [];
        $new = $input['new_app'] ?? [];
        unset($input['new_app']);
        if (is_array($new) && !empty($new['id'])) {
            $id = sanitize_key($new['id']);
            if (isset($input['apps'][$id])) {
                self::fail(__('A provider with this app ID already exists.', 'stsa-identity-sso'), $network);
            }
            $input['apps'][$id] = $new;
        }
        foreach ($input['apps'] as $id => &$app) {
            if (!is_array($app)) {
                self::fail(__('Invalid provider settings.', 'stsa-identity-sso'), $network);
            }
            if (!empty($app['_delete'])) {
                unset($input['apps'][$id]);
                continue;
            }
            $app['id'] = sanitize_key($id);
            unset($app['_delete']);
            foreach (['mapping', 'custom_mapping', 'role_mapping', 'domain_roles', 'domain_mapping', 'redirect_rules'] as $key) {
                if (isset($app[$key]) && is_string($app[$key])) {
                    $decoded = self::decode_json($app[$key], $key);
                    if (is_wp_error($decoded)) {
                        self::fail($decoded, $network);
                    }
                    $app[$key] = $decoded;
                }
            }
            $app['algorithms'] = self::lines($app['algorithms'] ?? []);
            if (isset($app['verified_value'])) {
                $value = json_decode($app['verified_value'], true);
                if (json_last_error() !== JSON_ERROR_NONE || is_array($value)) {
                    self::fail(__('Verified claim value must be a JSON scalar, for example true or "verified".', 'stsa-identity-sso'), $network);
                }
                $app['verified_value'] = $value;
            }
        }
        unset($app);
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
        $result = Config::save($input, $network);
        if (is_wp_error($result)) {
            self::fail($result, $network);
        }
        wp_safe_redirect(self::return_url($network, 'saved'));
        exit;
    }

    /** Accept only the shape generated by the HTML form, before decoding JSON. */
    private static function form_settings(array $input) {
        $allowed = Config::defaults() + ['new_app' => [], 'clear_scim_token' => false, 'clear_shared_secret' => false];
        $input = array_intersect_key($input, $allowed);
        foreach ($input as $key => &$value) {
            if ($key === 'apps' || $key === 'new_app') {
                if (!is_array($value) || ($key === 'apps' && count($value) > 100)) {
                    return new \WP_Error('wpsso_form_type', __('Invalid provider form fields.', 'stsa-identity-sso'));
                }
                $providers = $key === 'new_app' ? ['new' => $value] : $value;
                foreach ($providers as &$provider) {
                    if (!is_array($provider)) {
                        return new \WP_Error('wpsso_form_type', __('Invalid provider form fields.', 'stsa-identity-sso'));
                    }
                    $provider = array_intersect_key($provider, Config::app_defaults() + ['_delete' => false, 'clear_client_secret' => false, 'clear_jwt_secret' => false]);
                    foreach ($provider as $field) {
                        if (!is_string($field)) {
                            return new \WP_Error('wpsso_form_type', __('Provider form values must be strings.', 'stsa-identity-sso'));
                        }
                    }
                }
                unset($provider);
                $value = $key === 'new_app' ? $providers['new'] : $providers;
            } elseif (in_array($key, ['button', 'support', 'modules'], true)) {
                if (!is_array($value)) {
                    return new \WP_Error('wpsso_form_type', __('Invalid settings form fields.', 'stsa-identity-sso'));
                }
                $value = array_intersect_key($value, $allowed[$key]);
                foreach ($value as $field) {
                    if (!is_string($field)) {
                        return new \WP_Error('wpsso_form_type', __('Settings form values must be strings.', 'stsa-identity-sso'));
                    }
                }
            } elseif (!is_string($value)) {
                return new \WP_Error('wpsso_form_type', __('Settings form values must be strings.', 'stsa-identity-sso'));
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
            return new \WP_Error('wpsso_config_json', sprintf(__('Invalid JSON object or array for %s.', 'stsa-identity-sso'), $label));
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
        check_admin_referer('wpsso_export');
        $kind = isset($_REQUEST['kind']) && is_string($_REQUEST['kind']) ? sanitize_key(wp_unslash($_REQUEST['kind'])) : 'configuration';
        nocache_headers();
        if ($kind === 'audit') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="wpsso-audit.csv"');
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
            header('Content-Disposition: attachment; filename="wpsso-configuration.json"');
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
        return $settings;
    }

    public static function import(): void {
        $network = self::require_access();
        check_admin_referer('wpsso_import');
        if (empty($_FILES['configuration']) || !is_array($_FILES['configuration'])) {
            self::fail(__('Choose a configuration JSON file.', 'stsa-identity-sso'), $network);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the upload error, byte count, exact temporary-path type and PHP upload provenance before reading it.
        $file = $_FILES['configuration'];
        if (!is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_int($file['size'] ?? null) || $file['size'] < 0 || $file['size'] > 1048576 || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            self::fail(__('The upload failed or exceeds the 1 MB limit.', 'stsa-identity-sso'), $network);
        }
        // Read only PHP's verified upload, with a hard read limit independent of client metadata.
        $raw = file_get_contents($file['tmp_name'], false, null, 0, 1048577);
        if (!is_string($raw) || strlen($raw) > 1048576) {
            self::fail(__('The upload failed or exceeds the 1 MB limit.', 'stsa-identity-sso'), $network);
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
        check_admin_referer('wpsso_support');
        $support = self::settings($network)['support'] ?? [];
        $recipient = sanitize_email($support['email'] ?? '');
        $message = isset($_POST['message']) && is_string($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        if (!$recipient || !$message || strlen($message) > 10000) {
            self::fail(__('Configure a support recipient and enter a message of up to 10,000 characters.', 'stsa-identity-sso'), $network);
        }
        $body = $message;
        if (isset($_POST['include_configuration']) && is_string($_POST['include_configuration']) && sanitize_text_field(wp_unslash($_POST['include_configuration'])) === '1') {
            $body .= "\n\n" . __('Configuration (client IDs, secrets and tokens excluded):', 'stsa-identity-sso') . "\n";
            $body .= wp_json_encode(self::redact(self::settings($network)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        if (!wp_mail($recipient, __('[STSA Identity SSO] Support request', 'stsa-identity-sso'), $body)) {
            self::fail(__('WordPress could not hand the support message to its mail service.', 'stsa-identity-sso'), $network);
        }
        wp_safe_redirect(self::return_url($network, 'sent'));
        exit;
    }

    private static function hidden_scope(bool $network): void {
        if ($network) {
            echo '<input type="hidden" name="network" value="1">';
        }
    }

    public static function page(): void {
        $network = is_network_admin();
        if (!current_user_can($network ? 'manage_network_options' : 'manage_options')) {
            return;
        }
        $settings = self::settings($network);
        $enforced = !$network && is_multisite() && !empty(get_site_option('wpsso_settings', [])['network_enforced']);
        $notices = ['saved' => __('SSO settings saved.', 'stsa-identity-sso'), 'imported' => __('Configuration imported. Existing credentials were retained by app ID. Incomplete restored providers and login enforcement are disabled until you configure credentials and test SSO.', 'stsa-identity-sso'), 'sent' => __('Support message submitted to the WordPress mail service.', 'stsa-identity-sso')];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selection; the error text comes from a user-scoped server transient.
        $notice = isset($_GET['wpsso_notice']) && is_string($_GET['wpsso_notice']) ? sanitize_key(wp_unslash($_GET['wpsso_notice'])) : '';
        echo '<div class="wrap wpsso-admin"><header class="wpsso-header"><div><span class="wpsso-eyebrow">' . esc_html__('IDENTITY & ACCESS', 'stsa-identity-sso') . '</span><h1>' . esc_html($network ? __('STSA Identity SSO for networks', 'stsa-identity-sso') : __('STSA Identity SSO', 'stsa-identity-sso')) . '</h1><p>' . esc_html__('Connect providers, map accounts, and control access from one place.', 'stsa-identity-sso') . '</p></div><span class="wpsso-version">' . esc_html(defined('WPSSO_VERSION') ? WPSSO_VERSION : '1.0.0') . '</span></header>';
        if ($notice === 'error') {
            $error = get_transient('wpsso_admin_error_' . get_current_user_id());
            delete_transient('wpsso_admin_error_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . esc_html($error ?: __('The operation could not be completed.', 'stsa-identity-sso')) . '</p></div>';
        } elseif (isset($notices[$notice])) {
            echo '<div class="notice notice-success"><p>' . esc_html($notices[$notice]) . '</p></div>';
        }
        if ($enforced) {
            echo '<div class="notice notice-info"><p>' . esc_html__('Network policy controls SSO for this site. Update these settings in Network Admin.', 'stsa-identity-sso') . '</p></div>';
        }
        echo '<nav class="wpsso-tabs" aria-label="' . esc_attr__('SSO settings', 'stsa-identity-sso') . '">';
        foreach (['providers' => __('Providers', 'stsa-identity-sso'), 'accounts' => __('Login & access', 'stsa-identity-sso'), 'appearance' => __('Appearance', 'stsa-identity-sso'), 'modules' => __('Modules', 'stsa-identity-sso'), 'reports' => __('Reports', 'stsa-identity-sso'), 'tools' => __('Tools & support', 'stsa-identity-sso')] as $id => $label) {
            echo '<a href="#wpsso-' . esc_attr($id) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpsso-settings-form"><input type="hidden" name="action" value="wpsso_save">';
        wp_nonce_field('wpsso_save');
        self::hidden_scope($network);
        echo '<fieldset' . ($enforced ? ' disabled' : '') . '>';
        self::providers($settings);
        self::global_fields($settings, $network);
        if (!$enforced) {
            echo '<div class="wpsso-save"><button class="button button-primary button-hero" type="submit">' . esc_html__('Save all settings', 'stsa-identity-sso') . '</button><span>' . esc_html__('Test each provider before enabling forced authentication.', 'stsa-identity-sso') . '</span></div>';
        }
        echo '</fieldset><input type="hidden" name="wpsso_complete" value="1"></form>';
        self::reports($settings, $network);
        self::tools($settings, $network, $enforced);
        echo '</div>';
    }

    private static function providers(array $settings): void {
        echo '<section id="wpsso-providers" class="wpsso-section"><div class="wpsso-section-title"><h2>' . esc_html__('Identity providers', 'stsa-identity-sso') . '</h2><p>' . esc_html__('Register the callback shown below in each provider. Use a separate app ID for each tenant.', 'stsa-identity-sso') . '</p></div>';
        foreach (($settings['apps'] ?? []) as $id => $app) {
            self::provider((string) $id, array_replace_recursive(Config::app_defaults(), $app), false);
        }
        self::provider('', Config::app_defaults(), true);
        echo '</section>';
    }

    private static function provider(string $id, array $app, bool $new): void {
        $prefix = $new ? 'settings[new_app]' : 'settings[apps][' . $id . ']';
        echo '<details class="wpsso-provider"' . ($new ? '' : ' open') . '><summary><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><strong>' . esc_html($new ? __('Add an identity provider', 'stsa-identity-sso') : ($app['name'] ?: $id)) . '</strong><span class="wpsso-badge">' . esc_html($new ? __('NEW APP', 'stsa-identity-sso') : strtoupper($app['protocol'] ?? 'oidc')) . '</span></summary><div class="wpsso-provider-body">';
        if ($new) {
            self::field($prefix . '[id]', '', __('App ID', 'stsa-identity-sso'), 'text', __('A unique slug, for example staff-entra. Leave blank to skip adding a provider.', 'stsa-identity-sso'));
        } else {
            echo '<input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr($id) . '"><p><strong>' . esc_html__('App ID:', 'stsa-identity-sso') . '</strong> <code>' . esc_html($id) . '</code></p>';
        }
        self::select($prefix . '[provider]', $app['provider'] ?? 'custom', __('Provider template', 'stsa-identity-sso'), [
            'custom' => __('Custom OAuth / OpenID', 'stsa-identity-sso'), 'entra' => __('Microsoft Entra ID / Azure AD', 'stsa-identity-sso'), 'b2c' => __('Azure AD B2C', 'stsa-identity-sso'), 'office365' => __('Office 365', 'stsa-identity-sso'), 'cognito' => __('AWS Cognito', 'stsa-identity-sso'), 'keycloak' => __('Keycloak', 'stsa-identity-sso'), 'okta' => __('Okta', 'stsa-identity-sso'), 'salesforce' => __('Salesforce', 'stsa-identity-sso'), 'google' => __('Google', 'stsa-identity-sso'), 'auth0' => __('Auth0', 'stsa-identity-sso'), 'onelogin' => __('OneLogin', 'stsa-identity-sso'), 'discord' => __('Discord', 'stsa-identity-sso'), 'classlink' => __('ClassLink', 'stsa-identity-sso'), 'authentik' => 'authentik',
        ], __('Templates fill empty endpoint fields. Replace tenant and domain placeholders with values from your provider.', 'stsa-identity-sso'), 'wpsso-preset');
        self::field($prefix . '[name]', $app['name'], __('Display name', 'stsa-identity-sso'));
        self::check($prefix . '[enabled]', $app['enabled'], __('Enable this provider', 'stsa-identity-sso'));
        echo '<div class="wpsso-grid">';
        self::select($prefix . '[protocol]', $app['protocol'], __('Protocol', 'stsa-identity-sso'), ['oidc' => __('OpenID Connect 1.0', 'stsa-identity-sso'), 'oauth2' => __('OAuth 2.0', 'stsa-identity-sso')]);
        self::select($prefix . '[grant]', $app['grant'], __('Login grant', 'stsa-identity-sso'), ['authorization_code' => __('Authorization Code', 'stsa-identity-sso'), 'implicit' => __('Implicit (legacy)', 'stsa-identity-sso'), 'password' => __('Password (legacy)', 'stsa-identity-sso')]);
        echo '</div>';
        self::check($prefix . '[pkce]', $app['pkce'], __('Use PKCE (S256)', 'stsa-identity-sso'), __('Public clients can select “No client authentication” and omit a client secret.', 'stsa-identity-sso'));
        self::check($prefix . '[legacy_grants]', $app['legacy_grants'], __('Explicitly allow legacy Implicit / Password grants', 'stsa-identity-sso'), __('These flows are deprecated by current OAuth security guidance. Authorization Code with PKCE is the recommended flow.', 'stsa-identity-sso'));
        self::field($prefix . '[client_id]', $app['client_id'], __('Client ID', 'stsa-identity-sso'), 'text', __('Stored on the server. Excluded from support exports.', 'stsa-identity-sso'));
        self::secret($prefix, 'client_secret', !empty($app['client_secret']), __('Client secret', 'stsa-identity-sso'));
        self::select($prefix . '[credential_method]', $app['credential_method'], __('Send client credentials', 'stsa-identity-sso'), ['header' => __('HTTP Basic header', 'stsa-identity-sso'), 'body' => __('Request body', 'stsa-identity-sso'), 'none' => __('No client authentication (public client)', 'stsa-identity-sso')]);
        self::field($prefix . '[scope]', $app['scope'], __('Scopes', 'stsa-identity-sso'), 'text', __('Space separated. OIDC requires openid; profile and email are common.', 'stsa-identity-sso'));
        foreach (['authorize_url' => __('Authorization endpoint', 'stsa-identity-sso'), 'token_url' => __('Token endpoint', 'stsa-identity-sso'), 'userinfo_url' => __('User-info endpoint', 'stsa-identity-sso'), 'groupinfo_url' => __('Group-info endpoint', 'stsa-identity-sso'), 'jwks_url' => __('JWKS endpoint', 'stsa-identity-sso'), 'issuer' => __('Issuer', 'stsa-identity-sso'), 'logout_url' => __('OIDC end-session endpoint', 'stsa-identity-sso'), 'callback_url' => __('Callback URL override', 'stsa-identity-sso')] as $key => $label) {
            self::field($prefix . '[' . $key . ']', $app[$key], $label, 'url', $key === 'callback_url' ? __('Optional. Use an allowed origin that routes to this WordPress installation; register this exact URL with the provider.', 'stsa-identity-sso') : '');
        }
        self::check($prefix . '[backchannel_logout]', $app['backchannel_logout'] ?? false, __('Enable signed OIDC back-channel logout', 'stsa-identity-sso'), __('Configure the back-channel URL at the provider. Logout tokens must pass signature, issuer, audience, event, expiry, and replay checks.', 'stsa-identity-sso'));
        if (!$new) {
            echo '<div class="wpsso-callback"><strong>' . esc_html__('OIDC back-channel logout URL', 'stsa-identity-sso') . '</strong><code>' . esc_html(add_query_arg(['wpsso_action' => 'backchannel_logout', 'app' => $id], home_url('/'))) . '</code></div>';
        }
        echo '<div class="wpsso-callback"><strong>' . esc_html__('Registered callback URL', 'stsa-identity-sso') . '</strong><code>' . esc_html(Config::callback($app)) . '</code></div>';
        self::field($prefix . '[state_parameter]', $app['state_parameter'], __('Additional state value', 'stsa-identity-sso'), 'text', __('Stored inside a random, browser-bound, one-use state transaction.', 'stsa-identity-sso'));
        self::field($prefix . '[algorithms]', implode("\n", $app['algorithms']), __('Allowed JWT algorithms', 'stsa-identity-sso'), 'textarea', __('One per line: RS256, RS384, RS512, HS256, HS384, HS512. Pin only the algorithms used by this provider.', 'stsa-identity-sso'));
        self::secret($prefix, 'jwt_secret', !empty($app['jwt_secret']), __('HMAC JWT signing secret', 'stsa-identity-sso'));
        self::field($prefix . '[subject_attribute]', $app['subject_attribute'], __('Immutable subject attribute', 'stsa-identity-sso'), 'text', __('OIDC uses the signed sub claim. OAuth-only providers need a stable unique ID.', 'stsa-identity-sso'));
        echo '<details class="wpsso-subsection"><summary>' . esc_html__('Account and attribute mapping', 'stsa-identity-sso') . '</summary>';
        self::json($prefix . '[mapping]', $app['mapping'], __('Standard attribute paths', 'stsa-identity-sso'), __('Dot paths for username, email, first_name, last_name, display_name, groups. Example: {"email":"email","groups":"realm_access.roles"}.', 'stsa-identity-sso'));
        self::json($prefix . '[custom_mapping]', $app['custom_mapping'], __('Custom profile attributes', 'stsa-identity-sso'), __('Object of safe user-meta key → provider attribute path. Privilege and internal WordPress meta keys are blocked.', 'stsa-identity-sso'));
        self::json($prefix . '[role_mapping]', $app['role_mapping'], __('Group → WordPress roles', 'stsa-identity-sso'), __('Example: {"members":["subscriber"],"editors":["editor"]}.', 'stsa-identity-sso'));
        self::json($prefix . '[domain_roles]', $app['domain_roles'], __('Email domain → WordPress roles', 'stsa-identity-sso'), __('Example: {"school.example":["author"]}.', 'stsa-identity-sso'));
        self::field($prefix . '[default_role]', $app['default_role'], __('Default WordPress role', 'stsa-identity-sso'));
        self::json($prefix . '[domain_mapping]', $app['domain_mapping'], __('Email domain mapping', 'stsa-identity-sso'), __('Example: {"old.example":"new.example"}. Identity verification uses the original provider claim.', 'stsa-identity-sso'));
        foreach ([
            'auto_register' => [__('Automatically register users at first SSO login', 'stsa-identity-sso'), __('Turn off to allow existing linked or eligible local accounts only.', 'stsa-identity-sso')],
            'link_existing' => [__('Link existing users by verified email', 'stsa-identity-sso'), __('Only a verified provider email may link an existing account.', 'stsa-identity-sso')],
            'keep_roles' => [__('Keep existing WordPress roles', 'stsa-identity-sso'), __('Provider roles are not used to replace an existing account’s roles.', 'stsa-identity-sso')],
            'block_unmapped' => [__('Block login when no explicit role mapping matches', 'stsa-identity-sso'), __('The default role does not count as an explicit match.', 'stsa-identity-sso')],
            'keep_attributes' => [__('Keep existing profile attributes', 'stsa-identity-sso'), __('Applies to existing users.', 'stsa-identity-sso')],
            'keep_email' => [__('Keep existing account email', 'stsa-identity-sso'), __('Applies to existing users.', 'stsa-identity-sso')],
            'allow_duplicate_emails' => [__('Allow duplicate WordPress email addresses', 'stsa-identity-sso'), __('Provider subject stays the primary identity. Automatic email linking requires one unambiguous account.', 'stsa-identity-sso')],
            'require_verified_email' => [__('Require a verified provider email', 'stsa-identity-sso'), __('The verified claim and value are configured below.', 'stsa-identity-sso')],
            'allow_admin' => [__('Allow SSO for administrators', 'stsa-identity-sso'), __('Off by default. The configured verified claim must match for every administrator SSO login.', 'stsa-identity-sso')],
        ] as $key => [$label, $help]) {
            self::check($prefix . '[' . $key . ']', $app[$key], $label, $help);
        }
        self::field($prefix . '[verified_attribute]', $app['verified_attribute'], __('Verified provider claim path', 'stsa-identity-sso'));
        self::field($prefix . '[verified_value]', wp_json_encode($app['verified_value']), __('Required verified claim value', 'stsa-identity-sso'), 'text', __('JSON scalar. A true/1 expectation accepts true, "true", 1, or "1". Other scalar values use an exact string comparison.', 'stsa-identity-sso'));
        self::check($prefix . '[guest]', $app['guest'] ?? false, __('Allow ephemeral guest login for this app', 'stsa-identity-sso'), __('Also enable the Guest User Login module.', 'stsa-identity-sso'));
        self::check($prefix . '[moderation]', $app['moderation'] ?? false, __('Hold new users for administrator approval', 'stsa-identity-sso'), __('Also enable the User Moderation module.', 'stsa-identity-sso'));
        self::check($prefix . '[shared_sso]', $app['shared_sso'] ?? false, __('Issue a shared subdomain SSO ticket', 'stsa-identity-sso'), __('Also enable and configure the Shared SSO module.', 'stsa-identity-sso'));
        self::check($prefix . '[jwt_sso]', $app['jwt_sso'] ?? false, __('Allow signed JWT SSO for this app', 'stsa-identity-sso'), __('Also enable the JWT SSO module.', 'stsa-identity-sso'));
        self::field($prefix . '[avatar_attribute]', $app['avatar_attribute'] ?? 'picture', __('Profile picture attribute', 'stsa-identity-sso'), 'text', __('Used when the Profile Picture module is enabled.', 'stsa-identity-sso'));
        self::json($prefix . '[redirect_rules]', $app['redirect_rules'], __('Attribute based redirect rules', 'stsa-identity-sso'), __('Array of {"claim":"department","equals":"sales","url":"https://example.com/sales/"}. URLs must pass the redirect allowlist.', 'stsa-identity-sso'));
        echo '</details><details class="wpsso-subsection"><summary>' . esc_html__('Login button', 'stsa-identity-sso') . '</summary>';
        self::field($prefix . '[button_text]', $app['button_text'], __('Button text', 'stsa-identity-sso'), 'text', __('Leave blank to use the global text and provider name.', 'stsa-identity-sso'));
        self::field($prefix . '[icon_url]', $app['icon_url'], __('Custom icon URL', 'stsa-identity-sso'), 'url', __('Use an HTTPS image you control.', 'stsa-identity-sso'));
        echo '</details>';
        if (!$new) {
            echo '<div class="wpsso-provider-actions"><a class="button button-secondary" target="_blank" rel="noopener" href="' . esc_url(Auth::login_url($id, '', true)) . '">' . esc_html__('Test SSO Config', 'stsa-identity-sso') . '</a><span>' . esc_html__('Opens a protected raw-attribute test without changing your account.', 'stsa-identity-sso') . '</span></div>';
            self::check($prefix . '[_delete]', false, __('Delete this provider when saving', 'stsa-identity-sso'), __('Linked user identities remain stored. Removing a provider does not delete users.', 'stsa-identity-sso'));
        }
        echo '</div></details>';
    }

    private static function global_fields(array $settings, bool $network): void {
        echo '<section id="wpsso-accounts" class="wpsso-section"><h2>' . esc_html__('Login, logout, and access', 'stsa-identity-sso') . '</h2>';
        if ($network) {
            self::check('settings[network_enforced]', $settings['network_enforced'], __('Enforce these settings across this network', 'stsa-identity-sso'), __('Applies to this network ID. Other networks can keep separate policies.', 'stsa-identity-sso'));
        }
        $apps = ['' => __('Select a default provider', 'stsa-identity-sso')];
        foreach ($settings['apps'] as $id => $app) {
            $apps[$id] = $app['name'] ?: $id;
        }
        self::select('settings[default_app]', $settings['default_app'], __('Default identity provider', 'stsa-identity-sso'), $apps);
        foreach ([
            'force_auth' => [__('Force authentication for the whole site', 'stsa-identity-sso'), __('Logged-out visitors start SSO with the default provider.', 'stsa-identity-sso')],
            'restrict_site' => [__('Restrict the site to logged-in users', 'stsa-identity-sso'), __('Unauthenticated visitors are redirected to the configured SSO login.', 'stsa-identity-sso')],
            'disable_wp_login' => [__('Hide the standard WordPress password login', 'stsa-identity-sso'), __('Keep a tested SSO route and a documented recovery procedure before enabling.', 'stsa-identity-sso')],
            'single_flow' => [__('Use a single sign-in flow', 'stsa-identity-sso'), __('Send WordPress login requests directly to the default provider.', 'stsa-identity-sso')],
            'popup' => [__('Open SSO login in a popup window', 'stsa-identity-sso'), __('Browsers may use the normal redirect if popups are blocked.', 'stsa-identity-sso')],
            'logout_confirmation' => [__('Ask for confirmation before SSO logout', 'stsa-identity-sso'), __('Confirmation also verifies the current WordPress session.', 'stsa-identity-sso')],
        ] as $key => [$label, $help]) {
            self::check('settings[' . $key . ']', $settings[$key], $label, $help);
        }
        self::field('settings[login_redirect]', $settings['login_redirect'], __('Default redirect after login', 'stsa-identity-sso'), 'url');
        self::field('settings[logout_redirect]', $settings['logout_redirect'], __('Default redirect after logout', 'stsa-identity-sso'), 'url');
        self::field('settings[redirect_allowlist]', implode("\n", $settings['redirect_allowlist']), __('Allowed redirect URLs', 'stsa-identity-sso'), 'textarea', __('One HTTPS origin or path prefix per line. Paths use a boundary check; example https://portal.example.com/members/.', 'stsa-identity-sso'));
        self::field('settings[allowed_domains]', implode("\n", $settings['allowed_domains']), __('Allowed email domains', 'stsa-identity-sso'), 'textarea', __('One exact domain per line. Leave empty to allow all domains except blocked ones.', 'stsa-identity-sso'));
        self::field('settings[blocked_domains]', implode("\n", $settings['blocked_domains']), __('Blocked email domains', 'stsa-identity-sso'), 'textarea', __('One exact domain per line. A blocked domain always takes precedence.', 'stsa-identity-sso'));
        self::json('settings[content_rules]', $settings['content_rules'], __('Advanced content rules', 'stsa-identity-sso'), __('Optional bulk rules; individual post and category editors also expose role/group restrictions. See the documentation for the schema.', 'stsa-identity-sso'));
        self::field('settings[protected_media]', implode(', ', $settings['protected_media']), __('Protected attachment IDs', 'stsa-identity-sso'), 'text', __('Comma separated. Original files and derivatives are relocated outside the document root and served through authenticated delivery. Purge old copies from web-server and CDN caches.', 'stsa-identity-sso'));
        /* translators: %s: login button shortcode, displayed as code. */
        $placement = sprintf(__('Use %s, the STSA Identity SSO widget, or the WooCommerce login form.', 'stsa-identity-sso'), '<code>[wpsso_oauth_login]</code>');
        echo '</section><section id="wpsso-appearance" class="wpsso-section"><h2>' . esc_html__('Login button and widget', 'stsa-identity-sso') . '</h2><p>' . wp_kses($placement, ['code' => []]) . '</p>';
        $button = $settings['button'];
        self::select('settings[button][theme]', $button['theme'], __('Button theme', 'stsa-identity-sso'), ['default' => __('Default', 'stsa-identity-sso'), 'white' => __('White', 'stsa-identity-sso'), 'hover' => __('Hover', 'stsa-identity-sso'), 'custom' => __('Custom', 'stsa-identity-sso'), 'smart' => __('Smart', 'stsa-identity-sso')]);
        self::select('settings[button][shape]', $button['shape'], __('Button shape', 'stsa-identity-sso'), ['round' => __('Round', 'stsa-identity-sso'), 'round-edge' => __('Round edge', 'stsa-identity-sso'), 'square' => __('Square', 'stsa-identity-sso'), 'long' => __('Long', 'stsa-identity-sso')]);
        self::field('settings[button][text]', $button['text'], __('Default button text', 'stsa-identity-sso'), 'text', __('Use {app} for the provider name. Each app can override this text.', 'stsa-identity-sso'));
        self::field('settings[button][above_text]', $button['above_text'], __('Text above the widget', 'stsa-identity-sso'));
        foreach (['size' => __('Font size (px)', 'stsa-identity-sso'), 'radius' => __('Corner curve (px)', 'stsa-identity-sso'), 'spacing' => __('Button spacing (px)', 'stsa-identity-sso')] as $key => $label) {
            self::field('settings[button][' . $key . ']', $button[$key], $label, 'number');
        }
        self::check('settings[button][shadow]', $button['shadow'], __('Show button shadows', 'stsa-identity-sso'));
        self::check('settings[button][transform]', $button['transform'], __('Animate buttons on hover', 'stsa-identity-sso'));
        self::check('settings[button][admin_style]', $button['admin_style'], __('Apply styling on the WordPress login screen', 'stsa-identity-sso'));
        self::field('settings[button][custom_css]', $button['custom_css'], __('Custom button CSS', 'stsa-identity-sso'), 'textarea', __('Scope rules to .wpsso-login to avoid affecting unrelated site elements. Only trusted administrators can configure CSS.', 'stsa-identity-sso'));
        self::field('settings[button][logout_message]', $button['logout_message'], __('Logged-in message', 'stsa-identity-sso'), 'textarea', __('Placeholders: ##user## for the display name and ##Sign Out## for the logout link.', 'stsa-identity-sso'));
        self::check('settings[button][logout_link]', $button['logout_link'], __('Show a logout link', 'stsa-identity-sso'));
        echo '</section><section id="wpsso-modules" class="wpsso-section"><h2>' . esc_html__('Provisioning and add-on modules', 'stsa-identity-sso') . '</h2><p>' . esc_html__('Modules are bundled and opt in. Each endpoint enforces the corresponding toggle and its own authentication.', 'stsa-identity-sso') . '</p>';
        foreach (['scim' => __('SCIM user provisioning', 'stsa-identity-sso'), 'jwt_sso' => __('JWT Single Sign-On', 'stsa-identity-sso'), 'shared_sso' => __('SSO into multiple subdomain apps', 'stsa-identity-sso'), 'guest' => __('Guest User Login', 'stsa-identity-sso'), 'moderation' => __('User Moderation', 'stsa-identity-sso'), 'avatar' => __('Profile Picture / Gravatar mapping', 'stsa-identity-sso'), 'attribute_redirect' => __('Attribute Based Redirection', 'stsa-identity-sso')] as $key => $label) {
            self::check('settings[modules][' . $key . ']', $settings['modules'][$key] ?? false, $label);
        }
        self::secret('settings', 'scim_token', !empty($settings['scim_token']), __('SCIM bearer token', 'stsa-identity-sso'));
        echo '<p class="description">' . esc_html__('Use a cryptographically random token of at least 32 characters. Configure it in the provisioning client; it is never displayed again.', 'stsa-identity-sso') . '</p>';
        self::field('settings[guest_ttl]', $settings['guest_ttl'] ?? 3600, __('Guest session lifetime (seconds)', 'stsa-identity-sso'), 'number');
        self::field('settings[shared_cookie_domain]', $settings['shared_cookie_domain'], __('Shared cookie domain', 'stsa-identity-sso'), 'text', __('Example: .example.com. Every subdomain that can set cookies must be trusted.', 'stsa-identity-sso'));
        self::secret('settings', 'shared_secret', !empty($settings['shared_secret']), __('Shared SSO signing secret', 'stsa-identity-sso'));
        self::field('settings[shared_sites]', implode("\n", $settings['shared_sites']), __('Trusted shared SSO sites', 'stsa-identity-sso'), 'textarea', __('One exact HTTPS origin per line. Configure matching signing secrets and provider identities in each app.', 'stsa-identity-sso'));
        echo '<div class="wpsso-callout"><strong>' . esc_html__('Protected media delivery', 'stsa-identity-sso') . '</strong><p>' . esc_html__('Protecting an attachment relocates its original and derivatives outside the web document root. WordPress URLs then use the authenticated delivery endpoint. Configure a writable ', 'stsa-identity-sso') . '<code>WPSSO_PRIVATE_MEDIA_DIR</code>' . esc_html__(' in wp-config.php; the default is a private sibling directory of ABSPATH. Purge previously cached files from web-server and CDN caches. Restoring public access moves files back to their original upload paths.', 'stsa-identity-sso') . '</p></div>';
        echo '</section><section class="wpsso-section"><h2>' . esc_html__('Automation and reporting settings', 'stsa-identity-sso') . '</h2>';
        self::check('settings[abilities_enabled]', $settings['abilities_enabled'], __('Enable the eight AI / MCP abilities', 'stsa-identity-sso'), __('Off by default. Execution requires manage_options; MCP transport requires the WordPress MCP Adapter.', 'stsa-identity-sso'));
        self::check('settings[debug]', $settings['debug'], __('Enable diagnostic debug events', 'stsa-identity-sso'), __('No bearer tokens, client secrets, or raw provider attributes are logged. Debug events are removed weekly.', 'stsa-identity-sso'));
        self::check('settings[audit_enabled]', $settings['audit_enabled'], __('Record SSO transactions and login audit events', 'stsa-identity-sso'), __('Disable to stop collecting new audit events. Existing events remain until their retention period expires.', 'stsa-identity-sso'));
        self::check('settings[audit_ip]', $settings['audit_ip'], __('Include client IP addresses in audit events', 'stsa-identity-sso'), __('Off by default. Enable only when needed and disclose this collection in your site privacy policy.', 'stsa-identity-sso'));
        self::field('settings[audit_retention_days]', $settings['audit_retention_days'], __('Audit retention (days)', 'stsa-identity-sso'), 'number', __('Audit and transaction reports contain personal data. Set retention appropriate to your site.', 'stsa-identity-sso'));
        self::check('settings[delete_data_on_uninstall]', $settings['delete_data_on_uninstall'], __('Delete plugin data when uninstalling', 'stsa-identity-sso'), __('Off by default. Removes plugin settings, audit events, and linked user metadata. WordPress users, posts, and private media vault files are retained. Back up and restore protected media before uninstalling.', 'stsa-identity-sso'));
        echo '</section><section class="wpsso-section"><h2>' . esc_html__('Support destinations', 'stsa-identity-sso') . '</h2>';
        self::field('settings[support][email]', $settings['support']['email'], __('Support recipient email', 'stsa-identity-sso'), 'email', __('Choose the team that supports this installation. No vendor destination is configured automatically.', 'stsa-identity-sso'));
        foreach (['booking_url' => __('Call / screen-share booking URL', 'stsa-identity-sso'), 'forum_url' => __('Support forum URL', 'stsa-identity-sso'), 'demo_url' => __('Demo / trial request URL', 'stsa-identity-sso')] as $key => $label) {
            self::field('settings[support][' . $key . ']', $settings['support'][$key], $label, 'url');
        }
        echo '</section>';
    }

    private static function field(string $name, $value, string $label, string $type = 'text', string $help = ''): void {
        $id = 'wpsso-' . substr(md5($name), 0, 12);
        echo '<div class="wpsso-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="4" spellcheck="false">' . esc_textarea((string) $value) . '</textarea>';
        } else {
            echo '<input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr((string) $value) . '"' . ($type === 'number' ? ' min="0" step="1"' : '') . ' autocomplete="off">';
        }
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</div>';
    }

    private static function check(string $name, $value, string $label, string $help = ''): void {
        echo '<div class="wpsso-check"><input type="hidden" name="' . esc_attr($name) . '" value="0"><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked((bool) $value, true, false) . '> ' . esc_html($label) . '</label>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</div>';
    }

    private static function select(string $name, $value, string $label, array $choices, string $help = '', string $class = ''): void {
        $id = 'wpsso-' . substr(md5($name), 0, 12);
        echo '<div class="wpsso-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="' . esc_attr($class) . '">';
        foreach ($choices as $key => $text) {
            echo '<option value="' . esc_attr($key) . '"' . selected((string) $value, (string) $key, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</div>';
    }

    private static function secret(string $prefix, string $key, bool $configured, string $label): void {
        $name = $prefix . '[' . $key . ']';
        $id = 'wpsso-' . substr(md5($name), 0, 12);
        echo '<div class="wpsso-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="password" value="" autocomplete="new-password" placeholder="' . esc_attr($configured ? __('Configured — leave blank to keep', 'stsa-identity-sso') : __('Not configured', 'stsa-identity-sso')) . '"><p class="description">' . esc_html__('Enter a new value to replace it. Stored values are never printed in this page.', 'stsa-identity-sso') . '</p></div>';
        /* translators: %s: credential field label. */
        self::check($prefix . '[clear_' . $key . ']', false, sprintf(__('Clear stored %s', 'stsa-identity-sso'), $label));
    }

    private static function json(string $name, array $value, string $label, string $help): void {
        self::field($name, wp_json_encode($value ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $label, 'textarea', $help);
    }

    private static function reports(array $settings, bool $network): void {
        $url = wp_nonce_url(add_query_arg(['action' => 'wpsso_export', 'kind' => 'audit', 'network' => $network ? 1 : 0], admin_url('admin-post.php')), 'wpsso_export');
        echo '<section id="wpsso-reports" class="wpsso-section"><div class="wpsso-section-title"><h2>' . esc_html__('SSO transaction and login audit', 'stsa-identity-sso') . '</h2><a class="button" href="' . esc_url($url) . '">' . esc_html__('Export audit CSV', 'stsa-identity-sso') . '</a></div><p>' . esc_html__('Most recent 100 transactions for this site. Timestamps are UTC. Export contains up to 5,000 rows.', 'stsa-identity-sso') . '</p><div class="wpsso-table-scroll"><table class="widefat striped"><thead><tr>';
        foreach ([__('User', 'stsa-identity-sso'), __('Status', 'stsa-identity-sso'), __('App', 'stsa-identity-sso'), __('Date (UTC)', 'stsa-identity-sso'), __('Email', 'stsa-identity-sso'), __('Client IP', 'stsa-identity-sso'), __('Navigation URL', 'stsa-identity-sso'), __('Error code', 'stsa-identity-sso')] as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $rows = Audit::recent(['limit' => 100]);
        foreach ($rows as $row) {
            echo '<tr>';
            foreach (['username', 'status', 'app', 'created_at', 'email', 'client_ip', 'navigation', 'error_code'] as $key) {
                echo '<td>' . esc_html((string) ($row[$key] ?? '')) . '</td>';
            }
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="8">' . esc_html__('No SSO transactions recorded yet.', 'stsa-identity-sso') . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function tools(array $settings, bool $network, bool $enforced): void {
        echo '<section id="wpsso-tools" class="wpsso-section"><h2>' . esc_html__('Troubleshooting and admin tools', 'stsa-identity-sso') . '</h2><div class="wpsso-callout"><strong>' . esc_html__('Recovery before enforcement', 'stsa-identity-sso') . '</strong><p>' . esc_html__('Test a non-admin account first, then verify an approved administrator account. Keep filesystem or WP-CLI access available so forced login can be disabled if your provider is unavailable.', 'stsa-identity-sso') . '</p></div>';
        echo '<h3>' . esc_html__('Common errors', 'stsa-identity-sso') . '</h3><dl class="wpsso-errors">';
        foreach ([
            __('State / browser binding', 'stsa-identity-sso') => __('Restart sign-in from the original browser. Ensure cookies work and the callback hostname and HTTPS scheme match the initiating login origin. Do not cache login or callback routes.', 'stsa-identity-sso'),
            __('Invalid issuer or audience', 'stsa-identity-sso') => __('Copy the exact issuer and client ID from the provider registration. Tenant-specific issuers must match exactly; the token audience must include this app’s client ID.', 'stsa-identity-sso'),
            __('JWT signature / key unavailable', 'stsa-identity-sso') => __('Check the pinned algorithm and JWKS URL. Confirm outbound HTTPS and current provider key rotation. Never solve a signature error by disabling validation.', 'stsa-identity-sso'),
            __('Email not verified / admin blocked', 'stsa-identity-sso') => __('Check the configured verified claim and expected JSON value. Administrator SSO is disabled by default and always requires the verified claim.', 'stsa-identity-sso'),
            __('No role match / restricted domain', 'stsa-identity-sso') => __('Inspect raw attributes using Test SSO Config. Map the returned groups and check exact allowed and blocked email domains.', 'stsa-identity-sso'),
            __('Redirect rejected', 'stsa-identity-sso') => __('Use a same-origin URL or add the exact trusted HTTPS origin/path to the redirect allowlist. The callback must route back to this WordPress instance.', 'stsa-identity-sso'),
        ] as $title => $answer) {
            echo '<dt>' . esc_html($title) . '</dt><dd>' . esc_html($answer) . '</dd>';
        }
        echo '</dl><h3>' . esc_html__('FAQs', 'stsa-identity-sso') . '</h3><details class="wpsso-subsection"><summary>' . esc_html__('Which provider endpoint URLs should I use?', 'stsa-identity-sso') . '</summary><p>' . esc_html__('Use your provider’s tenant-specific OpenID discovery document to copy issuer, authorization_endpoint, token_endpoint, userinfo_endpoint, jwks_uri, and end_session_endpoint. The templates are starting points; the provider registration is authoritative.', 'stsa-identity-sso') . '</p></details><details class="wpsso-subsection"><summary>' . esc_html__('How are direct media URLs protected?', 'stsa-identity-sso') . '</summary><p>' . esc_html__('Protected originals and derivatives are moved into a writable private directory outside the web document root, and WordPress generates authenticated delivery URLs. Purge old web-server and CDN cached copies when protecting a file. Attachment-page rules alone do not relocate files; use the protected attachment control or the protected-ID list.', 'stsa-identity-sso') . '</p></details><details class="wpsso-subsection"><summary>' . esc_html__('Will this create user accounts automatically?', 'stsa-identity-sso') . '</summary><p>' . esc_html__('Only when the provider’s automatic registration setting is enabled. Existing-account linking is a separate opt-in and requires a verified, unique email.', 'stsa-identity-sso') . '</p></details>';
        echo '<h3>' . esc_html__('AI / MCP setup', 'stsa-identity-sso') . '</h3><ol><li>' . esc_html__('Use WordPress 6.9 or later and install the official WordPress MCP Adapter for MCP transport.', 'stsa-identity-sso') . '</li><li>' . esc_html__('Enable AI / MCP abilities above. Authenticate your MCP client with an administrator who has ', 'stsa-identity-sso') . '<code>manage_options</code>.</li><li>' . esc_html__('Connect Claude Desktop, Cursor, ChatGPT, or another MCP client to your adapter’s authenticated endpoint. Follow the adapter’s current transport and authentication setup.', 'stsa-identity-sso') . '</li><li>' . esc_html__('Discover the eight ', 'stsa-identity-sso') . '<code>wpsso/*</code>' . esc_html__(' abilities for status, provider configuration, validation, diagnosis, repair, audit, support preparation, and support submission. Review configuration changes before asking a client to execute them.', 'stsa-identity-sso') . '</li></ol><p>' . esc_html__('Disabling the abilities setting removes execution access. Abilities never return client IDs, secrets, access tokens, or refresh tokens.', 'stsa-identity-sso') . '</p>';
        $export = wp_nonce_url(add_query_arg(['action' => 'wpsso_export', 'kind' => 'configuration', 'network' => $network ? 1 : 0], admin_url('admin-post.php')), 'wpsso_export');
        echo '<h3>' . esc_html__('Configuration backup', 'stsa-identity-sso') . '</h3><p><a class="button" href="' . esc_url($export) . '">' . esc_html__('Download configuration backup', 'stsa-identity-sso') . '</a></p><p class="description">' . esc_html__('Credential-free JSON includes mappings and policy. Client IDs, all secrets, and tokens are excluded. Restore retains existing credentials by app ID; on a clean installation, incomplete providers and login enforcement remain disabled until credentials are entered. Retain an independent WordPress database backup for complete recovery.', 'stsa-identity-sso') . '</p>';
        if (!$enforced) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data"><input type="hidden" name="action" value="wpsso_import">';
            wp_nonce_field('wpsso_import');
            self::hidden_scope($network);
            echo '<label for="wpsso-import">' . esc_html__('Restore configuration JSON (maximum 1 MB)', 'stsa-identity-sso') . '</label><p><input type="file" id="wpsso-import" name="configuration" accept="application/json,.json" required> <button type="submit" class="button">' . esc_html__('Import configuration', 'stsa-identity-sso') . '</button></p></form>';
        }
        echo '<h3>' . esc_html__('Support', 'stsa-identity-sso') . '</h3><p>' . esc_html__('Support destinations are configured by your site administrator.', 'stsa-identity-sso') . '</p><p class="wpsso-support-links">';
        foreach (['booking_url' => __('Book a call / screen-share', 'stsa-identity-sso'), 'forum_url' => __('Visit support forum', 'stsa-identity-sso'), 'demo_url' => __('Request a demo / trial', 'stsa-identity-sso')] as $key => $label) {
            if (!empty($settings['support'][$key])) {
                echo '<a class="button" href="' . esc_url($settings['support'][$key]) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a> ';
            }
        }
        echo '</p>';
        if (!empty($settings['support']['email'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wpsso_support">';
            wp_nonce_field('wpsso_support');
            self::hidden_scope($network);
            /* translators: %s: support recipient email address. */
            self::field('message', '', __('Support message', 'stsa-identity-sso'), 'textarea', sprintf(__('Sent to %s when you submit this form.', 'stsa-identity-sso'), $settings['support']['email']));
            self::check('include_configuration', false, __('Send Plugin Configuration with this message', 'stsa-identity-sso'), __('Excludes client IDs, secrets, passwords, bearer tokens, and refresh tokens. Other policy and endpoint data will be included.', 'stsa-identity-sso'));
            echo '<p><button class="button" type="submit">' . esc_html__('Submit support query', 'stsa-identity-sso') . '</button></p></form>';
        }
        echo '</section>';
    }

    public static function meta_boxes(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ($type !== 'attachment') {
                add_meta_box('wpsso-policy', __('SSO access restriction', 'stsa-identity-sso'), [self::class, 'post_box'], $type, 'side');
            }
        }
    }

    public static function post_box(\WP_Post $post): void {
        wp_nonce_field('wpsso_policy', 'wpsso_policy_nonce');
        $rule = get_post_meta($post->ID, '_wpsso_rule', true);
        self::policy_fields(is_array($rule) ? $rule : []);
    }

    private static function policy_fields(array $rule): void {
        self::check('wpsso_rule[login]', $rule['login'] ?? false, __('Require a logged-in user', 'stsa-identity-sso'));
        self::field('wpsso_rule[roles]', implode(', ', $rule['roles'] ?? []), __('Allowed role slugs', 'stsa-identity-sso'), 'text', __('Comma separated. Leave empty for any role.', 'stsa-identity-sso'));
        self::field('wpsso_rule[groups]', implode(', ', $rule['groups'] ?? []), __('Allowed provider groups', 'stsa-identity-sso'), 'text', __('Comma separated. A matching role or group grants access; logged-out users are sent to SSO.', 'stsa-identity-sso'));
    }

    private static function submitted_rule(): ?array {
        if (empty($_POST['wpsso_policy_nonce']) || !is_string($_POST['wpsso_policy_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpsso_policy_nonce'])), 'wpsso_policy')) {
            return null;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only three whitelisted scalar fields are consumed and individually sanitized below.
        $input = isset($_POST['wpsso_rule']) && is_array($_POST['wpsso_rule']) ? array_intersect_key(wp_unslash($_POST['wpsso_rule']), ['login' => true, 'roles' => true, 'groups' => true]) : [];
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
            update_post_meta($post_id, '_wpsso_rule', $rule);
        }
    }

    public static function category_add(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="form-field"><h3>' . esc_html__('SSO access restriction', 'stsa-identity-sso') . '</h3>';
        wp_nonce_field('wpsso_policy', 'wpsso_policy_nonce');
        self::policy_fields([]);
        echo '</div>';
    }

    public static function category_edit(\WP_Term $term): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<tr class="form-field"><th scope="row">' . esc_html__('SSO access restriction', 'stsa-identity-sso') . '</th><td>';
        wp_nonce_field('wpsso_policy', 'wpsso_policy_nonce');
        $rule = get_term_meta($term->term_id, '_wpsso_rule', true);
        self::policy_fields(is_array($rule) ? $rule : []);
        echo '</td></tr>';
    }

    public static function save_category(int $term_id): void {
        if (!current_user_can('manage_options') || !current_user_can('manage_categories')) {
            return;
        }
        $rule = self::submitted_rule();
        if ($rule !== null) {
            update_term_meta($term_id, '_wpsso_rule', $rule);
        }
    }

    public static function attachment_fields(array $fields, \WP_Post $post): array {
        if (current_user_can('manage_options') && current_user_can('edit_post', $post->ID)) {
            $fields['wpsso_protected'] = ['label' => __('SSO protected', 'stsa-identity-sso'), 'input' => 'html', 'html' => '<label><input type="checkbox" name="attachments[' . absint($post->ID) . '][wpsso_protected]" value="1"' . checked(Access::is_protected($post->ID), true, false) . '>' . esc_html__(' Require SSO', 'stsa-identity-sso') . '</label><p>' . esc_html__('Moves originals and derivatives outside the document root. Purge cached copies from your web server and CDN.', 'stsa-identity-sso') . '</p>'];
        }
        return $fields;
    }

    public static function attachment_save(array $post, array $attachment): array {
        // The core media save handler verifies its nonce before applying this filter.
        if (current_user_can('manage_options') && current_user_can('edit_post', $post['ID'])) {
            update_post_meta((int) $post['ID'], '_wpsso_protected', !empty($attachment['wpsso_protected']) ? 1 : 0);
        }
        return $post;
    }
}
