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
        add_options_page('WordPress SSO', 'WordPress SSO', 'manage_options', 'wpsso', [self::class, 'page']);
    }

    public static function network_menu(): void {
        add_menu_page('Network SSO', 'Network SSO', 'manage_network_options', 'wpsso', [self::class, 'page'], 'dashicons-shield');
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'wpsso') === false) {
            return;
        }
        $version = defined('WPSSO_VERSION') ? WPSSO_VERSION : '1.0.0';
        wp_enqueue_style('wpsso-admin', plugins_url('../assets/admin.css', __FILE__), [], $version);
        wp_enqueue_script('wpsso-admin', plugins_url('../assets/admin.js', __FILE__), [], $version, true);
    }

    private static function is_network_request(): bool {
        return is_multisite() && !empty($_REQUEST['network']); // Used only to select a capability; never bypasses it.
    }

    private static function require_access(string $action): bool {
        $network = self::is_network_request();
        if (!current_user_can($network ? 'manage_network_options' : 'manage_options')) {
            wp_die(esc_html__('You cannot manage SSO settings.', 'wpsso'), '', ['response' => 403]);
        }
        check_admin_referer('wpsso_' . $action);
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
        $network = self::require_access('save');
        if (empty($_POST['wpsso_complete'])) {
            self::fail('The settings form was truncated. Increase PHP max_input_vars for this number of providers, then retry.', $network);
        }
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        if (!$input) {
            self::fail('No settings were submitted. Check the server POST size limit.', $network);
        }
        $input['apps'] = isset($input['apps']) && is_array($input['apps']) ? $input['apps'] : [];
        $new = $input['new_app'] ?? [];
        unset($input['new_app']);
        if (is_array($new) && !empty($new['id'])) {
            $id = sanitize_key($new['id']);
            if (isset($input['apps'][$id])) {
                self::fail('A provider with this app ID already exists.', $network);
            }
            $input['apps'][$id] = $new;
        }
        foreach ($input['apps'] as $id => &$app) {
            if (!is_array($app)) {
                self::fail('Invalid provider settings.', $network);
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
                    self::fail('Verified claim value must be a JSON scalar, for example true or "verified".', $network);
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

    private static function decode_json(string $value, string $label) {
        if (trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new \WP_Error('wpsso_config_json', 'Invalid JSON object or array for ' . $label . '.');
        }
        return $decoded;
    }

    private static function lines($value): array {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', array_map('strval', $value))));
        }
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $value))));
    }

    public static function export(): void {
        $network = self::require_access('export');
        $kind = sanitize_key($_REQUEST['kind'] ?? 'configuration');
        nocache_headers();
        if ($kind === 'audit') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="wpsso-audit.csv"');
            $stream = fopen('php://output', 'w');
            $columns = ['username', 'status', 'app', 'created_at', 'email', 'client_ip', 'navigation', 'error_code'];
            fputcsv($stream, $columns, ',', '"', '');
            foreach (Audit::recent(['limit' => 5000]) as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = (string) ($row[$column] ?? '');
                    // Spreadsheet formulas can execute even after leading whitespace.
                    $values[] = preg_match('/^[\s]*[=+@\-]/u', $value) ? "'" . $value : $value;
                }
                fputcsv($stream, $values, ',', '"', '');
            }
            fclose($stream);
        } else {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="wpsso-configuration.json"');
            echo wp_json_encode(self::redact(self::settings($network)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    private static function redact(array $settings): array {
        foreach ($settings as $key => &$value) {
            if (preg_match('/(?:client_id|secret|token|password|credential)/i', (string) $key)) {
                unset($settings[$key]);
            } elseif (is_array($value)) {
                $value = self::redact($value);
            } elseif ($key === 'state_parameter' || $key === 'custom_css') {
                $value = '';
            } elseif (is_string($value) && preg_match('/^https?:\/\//i', $value)) {
                // Endpoints can contain tenant-supplied credentials in query parameters.
                $value = preg_replace('/[?#].*$/', '', $value);
            }
        }
        unset($value);
        return $settings;
    }

    public static function import(): void {
        $network = self::require_access('import');
        if (empty($_FILES['configuration']) || !is_array($_FILES['configuration'])) {
            self::fail('Choose a configuration JSON file.', $network);
        }
        $file = $_FILES['configuration'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 1048576 || !is_uploaded_file($file['tmp_name'])) {
            self::fail('The upload failed or exceeds the 1 MB limit.', $network);
        }
        $raw = file_get_contents($file['tmp_name']);
        $settings = self::decode_json((string) $raw, 'configuration');
        if (is_wp_error($settings)) {
            self::fail($settings, $network);
        }
        $result = Config::save($settings, $network);
        if (is_wp_error($result)) {
            self::fail($result, $network);
        }
        wp_safe_redirect(self::return_url($network, 'imported'));
        exit;
    }

    public static function support(): void {
        $network = self::require_access('support');
        $support = self::settings($network)['support'] ?? [];
        $recipient = sanitize_email($support['email'] ?? '');
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        if (!$recipient || !$message || strlen($message) > 10000) {
            self::fail('Configure a support recipient and enter a message of up to 10,000 characters.', $network);
        }
        $body = $message;
        if (!empty($_POST['include_configuration'])) {
            $body .= "\n\nConfiguration (client IDs, secrets and tokens excluded):\n";
            $body .= wp_json_encode(self::redact(self::settings($network)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        if (!wp_mail($recipient, '[WPSSO] Support request', $body)) {
            self::fail('WordPress could not hand the support message to its mail service.', $network);
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
        $notices = ['saved' => 'SSO settings saved.', 'imported' => 'Configuration imported. Re-enter omitted credentials before testing.', 'sent' => 'Support message submitted to the WordPress mail service.'];
        $notice = sanitize_key($_GET['wpsso_notice'] ?? '');
        echo '<div class="wrap wpsso-admin"><header class="wpsso-header"><div><span class="wpsso-eyebrow">IDENTITY &amp; ACCESS</span><h1>' . esc_html($network ? 'Network single sign-on' : 'WordPress single sign-on') . '</h1><p>Connect providers, map accounts, and control access from one place.</p></div><span class="wpsso-version">WPSSO ' . esc_html(defined('WPSSO_VERSION') ? WPSSO_VERSION : '1.0.0') . '</span></header>';
        if ($notice === 'error') {
            $error = get_transient('wpsso_admin_error_' . get_current_user_id());
            delete_transient('wpsso_admin_error_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . esc_html($error ?: 'The operation could not be completed.') . '</p></div>';
        } elseif (isset($notices[$notice])) {
            echo '<div class="notice notice-success"><p>' . esc_html($notices[$notice]) . '</p></div>';
        }
        if ($enforced) {
            echo '<div class="notice notice-info"><p>Network policy controls SSO for this site. Update these settings in Network Admin.</p></div>';
        }
        echo '<nav class="wpsso-tabs" aria-label="SSO settings">';
        foreach (['providers' => 'Providers', 'accounts' => 'Login & access', 'appearance' => 'Appearance', 'modules' => 'Modules', 'reports' => 'Reports', 'tools' => 'Tools & support'] as $id => $label) {
            echo '<a href="#wpsso-' . esc_attr($id) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpsso-settings-form"><input type="hidden" name="action" value="wpsso_save">';
        wp_nonce_field('wpsso_save');
        self::hidden_scope($network);
        echo '<fieldset' . ($enforced ? ' disabled' : '') . '>';
        self::providers($settings);
        self::global_fields($settings, $network);
        if (!$enforced) {
            echo '<div class="wpsso-save"><button class="button button-primary button-hero" type="submit">Save all settings</button><span>Test each provider before enabling forced authentication.</span></div>';
        }
        echo '</fieldset><input type="hidden" name="wpsso_complete" value="1"></form>';
        self::reports($settings, $network);
        self::tools($settings, $network, $enforced);
        echo '</div>';
    }

    private static function providers(array $settings): void {
        echo '<section id="wpsso-providers" class="wpsso-section"><div class="wpsso-section-title"><h2>Identity providers</h2><p>Register the callback shown below in each provider. Use a separate app ID for each tenant.</p></div>';
        foreach (($settings['apps'] ?? []) as $id => $app) {
            self::provider((string) $id, array_replace_recursive(Config::app_defaults(), $app), false);
        }
        self::provider('', Config::app_defaults(), true);
        echo '</section>';
    }

    private static function provider(string $id, array $app, bool $new): void {
        $prefix = $new ? 'settings[new_app]' : 'settings[apps][' . $id . ']';
        echo '<details class="wpsso-provider"' . ($new ? '' : ' open') . '><summary><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><strong>' . esc_html($new ? 'Add an identity provider' : ($app['name'] ?: $id)) . '</strong><span class="wpsso-badge">' . esc_html($new ? 'NEW APP' : strtoupper($app['protocol'] ?? 'oidc')) . '</span></summary><div class="wpsso-provider-body">';
        if ($new) {
            self::field($prefix . '[id]', '', 'App ID', 'text', 'A unique slug, for example staff-entra. Leave blank to skip adding a provider.');
        } else {
            echo '<input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr($id) . '"><p><strong>App ID:</strong> <code>' . esc_html($id) . '</code></p>';
        }
        self::select($prefix . '[provider]', $app['provider'] ?? 'custom', 'Provider template', [
            'custom' => 'Custom OAuth / OpenID', 'entra' => 'Microsoft Entra ID / Azure AD', 'b2c' => 'Azure AD B2C', 'office365' => 'Office 365', 'cognito' => 'AWS Cognito', 'keycloak' => 'Keycloak', 'okta' => 'Okta', 'salesforce' => 'Salesforce', 'google' => 'Google', 'auth0' => 'Auth0', 'onelogin' => 'OneLogin', 'discord' => 'Discord', 'classlink' => 'ClassLink', 'authentik' => 'authentik',
        ], 'Templates fill empty endpoint fields. Replace tenant and domain placeholders with values from your provider.', 'wpsso-preset');
        self::field($prefix . '[name]', $app['name'], 'Display name');
        self::check($prefix . '[enabled]', $app['enabled'], 'Enable this provider');
        echo '<div class="wpsso-grid">';
        self::select($prefix . '[protocol]', $app['protocol'], 'Protocol', ['oidc' => 'OpenID Connect 1.0', 'oauth2' => 'OAuth 2.0']);
        self::select($prefix . '[grant]', $app['grant'], 'Login grant', ['authorization_code' => 'Authorization Code', 'implicit' => 'Implicit (legacy)', 'password' => 'Password (legacy)']);
        echo '</div>';
        self::check($prefix . '[pkce]', $app['pkce'], 'Use PKCE (S256)', 'Public clients can select “No client authentication” and omit a client secret.');
        self::check($prefix . '[legacy_grants]', $app['legacy_grants'], 'Explicitly allow legacy Implicit / Password grants', 'These flows are deprecated by current OAuth security guidance. Authorization Code with PKCE is the recommended flow.');
        self::field($prefix . '[client_id]', $app['client_id'], 'Client ID', 'text', 'Stored on the server. Excluded from support exports.');
        self::secret($prefix, 'client_secret', !empty($app['client_secret']), 'Client secret');
        self::select($prefix . '[credential_method]', $app['credential_method'], 'Send client credentials', ['header' => 'HTTP Basic header', 'body' => 'Request body', 'none' => 'No client authentication (public client)']);
        self::field($prefix . '[scope]', $app['scope'], 'Scopes', 'text', 'Space separated. OIDC requires openid; profile and email are common.');
        foreach (['authorize_url' => 'Authorization endpoint', 'token_url' => 'Token endpoint', 'userinfo_url' => 'User-info endpoint', 'groupinfo_url' => 'Group-info endpoint', 'jwks_url' => 'JWKS endpoint', 'issuer' => 'Issuer', 'logout_url' => 'OIDC end-session endpoint', 'callback_url' => 'Callback URL override'] as $key => $label) {
            self::field($prefix . '[' . $key . ']', $app[$key], $label, 'url', $key === 'callback_url' ? 'Optional. Use an allowed origin that routes to this WordPress installation; register this exact URL with the provider.' : '');
        }
        echo '<div class="wpsso-callback"><strong>Registered callback URL</strong><code>' . esc_html(Config::callback($app)) . '</code></div>';
        self::field($prefix . '[state_parameter]', $app['state_parameter'], 'Additional state value', 'text', 'Stored inside a random, browser-bound, one-use state transaction.');
        self::field($prefix . '[algorithms]', implode("\n", $app['algorithms']), 'Allowed JWT algorithms', 'textarea', 'One per line: RS256, RS384, RS512, HS256, HS384, HS512. Pin only the algorithms used by this provider.');
        self::secret($prefix, 'jwt_secret', !empty($app['jwt_secret']), 'HMAC JWT signing secret');
        self::field($prefix . '[subject_attribute]', $app['subject_attribute'], 'Immutable subject attribute', 'text', 'OIDC uses the signed sub claim. OAuth-only providers need a stable unique ID.');
        echo '<details class="wpsso-subsection"><summary>Account and attribute mapping</summary>';
        self::json($prefix . '[mapping]', $app['mapping'], 'Standard attribute paths', 'Dot paths for username, email, first_name, last_name, display_name, groups. Example: {"email":"email","groups":"realm_access.roles"}.');
        self::json($prefix . '[custom_mapping]', $app['custom_mapping'], 'Custom profile attributes', 'Object of safe user-meta key → provider attribute path. Privilege and internal WordPress meta keys are blocked.');
        self::json($prefix . '[role_mapping]', $app['role_mapping'], 'Group → WordPress roles', 'Example: {"members":["subscriber"],"editors":["editor"]}.');
        self::json($prefix . '[domain_roles]', $app['domain_roles'], 'Email domain → WordPress roles', 'Example: {"school.example":["author"]}.');
        self::field($prefix . '[default_role]', $app['default_role'], 'Default WordPress role');
        self::json($prefix . '[domain_mapping]', $app['domain_mapping'], 'Email domain mapping', 'Example: {"old.example":"new.example"}. Identity verification uses the original provider claim.');
        foreach ([
            'auto_register' => ['Automatically register users at first SSO login', 'Turn off to allow existing linked or eligible local accounts only.'],
            'link_existing' => ['Link existing users by verified email', 'Only a verified provider email may link an existing account.'],
            'keep_roles' => ['Keep existing WordPress roles', 'Provider roles are not used to replace an existing account’s roles.'],
            'block_unmapped' => ['Block login when no explicit role mapping matches', 'The default role does not count as an explicit match.'],
            'keep_attributes' => ['Keep existing profile attributes', 'Applies to existing users.'],
            'keep_email' => ['Keep existing account email', 'Applies to existing users.'],
            'allow_duplicate_emails' => ['Allow duplicate WordPress email addresses', 'Provider subject stays the primary identity. Automatic email linking requires one unambiguous account.'],
            'require_verified_email' => ['Require a verified provider email', 'The verified claim and value are configured below.'],
            'allow_admin' => ['Allow SSO for administrators', 'Off by default. The configured verified claim must match for every administrator SSO login.'],
        ] as $key => [$label, $help]) {
            self::check($prefix . '[' . $key . ']', $app[$key], $label, $help);
        }
        self::field($prefix . '[verified_attribute]', $app['verified_attribute'], 'Verified provider claim path');
        self::field($prefix . '[verified_value]', wp_json_encode($app['verified_value']), 'Required verified claim value', 'text', 'JSON scalar, for example true, "verified", or 1. Value types must match.');
        self::check($prefix . '[guest]', $app['guest'] ?? false, 'Allow ephemeral guest login for this app', 'Also enable the Guest User Login module.');
        self::check($prefix . '[moderation]', $app['moderation'] ?? false, 'Hold new users for administrator approval', 'Also enable the User Moderation module.');
        self::check($prefix . '[shared_sso]', $app['shared_sso'] ?? false, 'Issue a shared subdomain SSO ticket', 'Also enable and configure the Shared SSO module.');
        self::check($prefix . '[jwt_sso]', $app['jwt_sso'] ?? false, 'Allow signed JWT SSO for this app', 'Also enable the JWT SSO module.');
        self::field($prefix . '[avatar_attribute]', $app['avatar_attribute'] ?? 'picture', 'Profile picture attribute', 'text', 'Used when the Profile Picture module is enabled.');
        self::json($prefix . '[redirect_rules]', $app['redirect_rules'], 'Attribute based redirect rules', 'Array of {"claim":"department","equals":"sales","url":"https://example.com/sales/"}. URLs must pass the redirect allowlist.');
        echo '</details><details class="wpsso-subsection"><summary>Login button</summary>';
        self::field($prefix . '[button_text]', $app['button_text'], 'Button text', 'text', 'Leave blank to use the global text and provider name.');
        self::field($prefix . '[icon_url]', $app['icon_url'], 'Custom icon URL', 'url', 'Use an HTTPS image you control.');
        echo '</details>';
        if (!$new) {
            echo '<div class="wpsso-provider-actions"><a class="button button-secondary" target="_blank" rel="noopener" href="' . esc_url(Auth::login_url($id, '', true)) . '">Test SSO Config</a><span>Opens a protected raw-attribute test without changing your account.</span></div>';
            self::check($prefix . '[_delete]', false, 'Delete this provider when saving', 'Linked user identities remain stored. Removing a provider does not delete users.');
        }
        echo '</div></details>';
    }

    private static function global_fields(array $settings, bool $network): void {
        echo '<section id="wpsso-accounts" class="wpsso-section"><h2>Login, logout, and access</h2>';
        if ($network) {
            self::check('settings[network_enforced]', $settings['network_enforced'], 'Enforce these settings across this network', 'Applies to this network ID. Other networks can keep separate policies.');
        }
        $apps = ['' => 'Select a default provider'];
        foreach ($settings['apps'] as $id => $app) {
            $apps[$id] = $app['name'] ?: $id;
        }
        self::select('settings[default_app]', $settings['default_app'], 'Default identity provider', $apps);
        foreach ([
            'force_auth' => ['Force authentication for the whole site', 'Logged-out visitors start SSO with the default provider.'],
            'restrict_site' => ['Restrict the site to logged-in users', 'Unauthenticated visitors are redirected to the configured SSO login.'],
            'disable_wp_login' => ['Hide the standard WordPress password login', 'Keep a tested SSO route and a documented recovery procedure before enabling.'],
            'single_flow' => ['Use a single sign-in flow', 'Send WordPress login requests directly to the default provider.'],
            'popup' => ['Open SSO login in a popup window', 'Browsers may use the normal redirect if popups are blocked.'],
            'logout_confirmation' => ['Ask for confirmation before SSO logout', 'Confirmation also verifies the current WordPress session.'],
        ] as $key => [$label, $help]) {
            self::check('settings[' . $key . ']', $settings[$key], $label, $help);
        }
        self::field('settings[login_redirect]', $settings['login_redirect'], 'Default redirect after login', 'url');
        self::field('settings[logout_redirect]', $settings['logout_redirect'], 'Default redirect after logout', 'url');
        self::field('settings[redirect_allowlist]', implode("\n", $settings['redirect_allowlist']), 'Allowed redirect URLs', 'textarea', 'One HTTPS origin or path prefix per line. Paths use a boundary check; example https://portal.example.com/members/.');
        self::field('settings[allowed_domains]', implode("\n", $settings['allowed_domains']), 'Allowed email domains', 'textarea', 'One exact domain per line. Leave empty to allow all domains except blocked ones.');
        self::field('settings[blocked_domains]', implode("\n", $settings['blocked_domains']), 'Blocked email domains', 'textarea', 'One exact domain per line. A blocked domain always takes precedence.');
        self::json('settings[content_rules]', $settings['content_rules'], 'Advanced content rules', 'Optional bulk rules; individual post and category editors also expose role/group restrictions. See the documentation for the schema.');
        self::field('settings[protected_media]', implode(', ', $settings['protected_media']), 'Protected attachment IDs', 'text', 'Comma separated. Restrict direct files at the web server too; PHP cannot intercept static media requests.');
        echo '</section><section id="wpsso-appearance" class="wpsso-section"><h2>Login button and widget</h2><p>Use <code>[wpsso_oauth_login]</code>, the WordPress SSO widget, or the WooCommerce login form.</p>';
        $button = $settings['button'];
        self::select('settings[button][theme]', $button['theme'], 'Button theme', ['default' => 'Default', 'white' => 'White', 'hover' => 'Hover', 'custom' => 'Custom', 'smart' => 'Smart']);
        self::select('settings[button][shape]', $button['shape'], 'Button shape', ['round' => 'Round', 'round-edge' => 'Round edge', 'square' => 'Square', 'long' => 'Long']);
        self::field('settings[button][text]', $button['text'], 'Default button text', 'text', 'The provider name is appended by the default renderer. Each app can override it.');
        self::field('settings[button][above_text]', $button['above_text'], 'Text above the widget');
        foreach (['size' => 'Font size (px)', 'radius' => 'Corner curve (px)', 'spacing' => 'Button spacing (px)'] as $key => $label) {
            self::field('settings[button][' . $key . ']', $button[$key], $label, 'number');
        }
        self::check('settings[button][shadow]', $button['shadow'], 'Show button shadows');
        self::check('settings[button][transform]', $button['transform'], 'Animate buttons on hover');
        self::check('settings[button][admin_style]', $button['admin_style'], 'Apply styling on the WordPress login screen');
        self::field('settings[button][custom_css]', $button['custom_css'], 'Custom button CSS', 'textarea', 'Scope rules to .wpsso-login to avoid affecting unrelated site elements. Only trusted administrators can configure CSS.');
        self::field('settings[button][logout_message]', $button['logout_message'], 'Logged-in message', 'textarea', 'Placeholders: ##user## for the display name and ##Sign Out## for the logout link.');
        self::check('settings[button][logout_link]', $button['logout_link'], 'Show a logout link');
        echo '</section><section id="wpsso-modules" class="wpsso-section"><h2>Provisioning and add-on modules</h2><p>Modules are bundled and opt in. Each endpoint enforces the corresponding toggle and its own authentication.</p>';
        foreach (['scim' => 'SCIM user provisioning', 'jwt_sso' => 'JWT Single Sign-On', 'shared_sso' => 'SSO into multiple subdomain apps', 'guest' => 'Guest User Login', 'moderation' => 'User Moderation', 'avatar' => 'Profile Picture / Gravatar mapping', 'attribute_redirect' => 'Attribute Based Redirection'] as $key => $label) {
            self::check('settings[modules][' . $key . ']', $settings['modules'][$key] ?? false, $label);
        }
        self::secret('settings', 'scim_token', !empty($settings['scim_token']), 'SCIM bearer token');
        echo '<p class="description">Use a cryptographically random token of at least 32 characters. Configure it in the provisioning client; it is never displayed again.</p>';
        self::field('settings[guest_ttl]', $settings['guest_ttl'] ?? 3600, 'Guest session lifetime (seconds)', 'number');
        self::field('settings[shared_cookie_domain]', $settings['shared_cookie_domain'], 'Shared cookie domain', 'text', 'Example: .example.com. Every subdomain that can set cookies must be trusted.');
        self::secret('settings', 'shared_secret', !empty($settings['shared_secret']), 'Shared SSO signing secret');
        self::field('settings[shared_sites]', implode("\n", $settings['shared_sites']), 'Trusted shared SSO sites', 'textarea', 'One exact HTTPS origin per line. Configure matching signing secrets and provider identities in each app.');
        echo '<div class="wpsso-callout"><strong>Protected media delivery</strong><p>Attachment restrictions protect WordPress routes. Configure an Apache deny rule or an Nginx internal location and route files through the authenticated delivery endpoint before treating static files as private. Existing public URLs remain public until your server rules take effect.</p></div>';
        echo '</section><section class="wpsso-section"><h2>Automation and reporting settings</h2>';
        self::check('settings[abilities_enabled]', $settings['abilities_enabled'], 'Enable the eight AI / MCP abilities', 'Off by default. Execution requires manage_options; MCP transport requires the WordPress MCP Adapter.');
        self::check('settings[debug]', $settings['debug'], 'Enable diagnostic debug events', 'No bearer tokens, client secrets, or raw provider attributes are logged. Debug events are removed weekly.');
        self::field('settings[audit_retention_days]', $settings['audit_retention_days'], 'Audit retention (days)', 'number', 'Audit and transaction reports contain personal data. Set retention appropriate to your site.');
        echo '</section><section class="wpsso-section"><h2>Support destinations</h2>';
        self::field('settings[support][email]', $settings['support']['email'], 'Support recipient email', 'email', 'Choose the team that supports this installation. No vendor destination is configured automatically.');
        foreach (['booking_url' => 'Call / screen-share booking URL', 'forum_url' => 'Support forum URL', 'demo_url' => 'Demo / trial request URL'] as $key => $label) {
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
        echo '<div class="wpsso-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="password" value="" autocomplete="new-password" placeholder="' . esc_attr($configured ? 'Configured — leave blank to keep' : 'Not configured') . '"><p class="description">Enter a new value to replace it. Stored values are never printed in this page.</p></div>';
        self::check($prefix . '[clear_' . $key . ']', false, 'Clear stored ' . strtolower($label));
    }

    private static function json(string $name, array $value, string $label, string $help): void {
        self::field($name, wp_json_encode($value ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $label, 'textarea', $help);
    }

    private static function reports(array $settings, bool $network): void {
        $url = wp_nonce_url(add_query_arg(['action' => 'wpsso_export', 'kind' => 'audit', 'network' => $network ? 1 : 0], admin_url('admin-post.php')), 'wpsso_export');
        echo '<section id="wpsso-reports" class="wpsso-section"><div class="wpsso-section-title"><h2>SSO transaction and login audit</h2><a class="button" href="' . esc_url($url) . '">Export audit CSV</a></div><p>Most recent 100 transactions for this site. Timestamps are UTC. Export contains up to 5,000 rows.</p><div class="wpsso-table-scroll"><table class="widefat striped"><thead><tr>';
        foreach (['User', 'Status', 'App', 'Date (UTC)', 'Email', 'Client IP', 'Navigation URL', 'Error code'] as $label) {
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
            echo '<tr><td colspan="8">No SSO transactions recorded yet.</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function tools(array $settings, bool $network, bool $enforced): void {
        echo '<section id="wpsso-tools" class="wpsso-section"><h2>Troubleshooting and admin tools</h2><div class="wpsso-callout"><strong>Recovery before enforcement</strong><p>Test a non-admin account first, then verify an approved administrator account. Keep filesystem or WP-CLI access available so forced login can be disabled if your provider is unavailable.</p></div>';
        echo '<h3>Common errors</h3><dl class="wpsso-errors">';
        foreach ([
            'State / browser binding' => 'Restart sign-in from the original browser. Ensure cookies work and the callback hostname and HTTPS scheme match the initiating login origin. Do not cache login or callback routes.',
            'Invalid issuer or audience' => 'Copy the exact issuer and client ID from the provider registration. Tenant-specific issuers must match exactly; the token audience must include this app’s client ID.',
            'JWT signature / key unavailable' => 'Check the pinned algorithm and JWKS URL. Confirm outbound HTTPS and current provider key rotation. Never solve a signature error by disabling validation.',
            'Email not verified / admin blocked' => 'Check the configured verified claim and expected JSON value. Administrator SSO is disabled by default and always requires the verified claim.',
            'No role match / restricted domain' => 'Inspect raw attributes using Test SSO Config. Map the returned groups and check exact allowed and blocked email domains.',
            'Redirect rejected' => 'Use a same-origin URL or add the exact trusted HTTPS origin/path to the redirect allowlist. The callback must route back to this WordPress instance.',
        ] as $title => $answer) {
            echo '<dt>' . esc_html($title) . '</dt><dd>' . esc_html($answer) . '</dd>';
        }
        echo '</dl><h3>FAQs</h3><details class="wpsso-subsection"><summary>Which provider endpoint URLs should I use?</summary><p>Use your provider’s tenant-specific OpenID discovery document to copy issuer, authorization_endpoint, token_endpoint, userinfo_endpoint, jwks_uri, and end_session_endpoint. The templates are starting points; the provider registration is authoritative.</p></details><details class="wpsso-subsection"><summary>Can WordPress protect a direct media URL?</summary><p>Only when your web server prevents direct static access and files are delivered through the authenticated media route. Attachment-page rules alone cannot protect a public upload URL.</p></details><details class="wpsso-subsection"><summary>Will this create user accounts automatically?</summary><p>Only when the provider’s automatic registration setting is enabled. Existing-account linking is a separate opt-in and requires a verified, unique email.</p></details>';
        echo '<h3>AI / MCP setup</h3><ol><li>Use WordPress 6.9 or later and install the official WordPress MCP Adapter for MCP transport.</li><li>Enable AI / MCP abilities above. Authenticate your MCP client with an administrator who has <code>manage_options</code>.</li><li>Connect Claude Desktop, Cursor, ChatGPT, or another MCP client to your adapter’s authenticated endpoint. Follow the adapter’s current transport and authentication setup.</li><li>Discover the eight <code>wpsso/*</code> abilities for status, provider configuration, validation, diagnosis, repair, audit, support preparation, and support submission. Review configuration changes before asking a client to execute them.</li></ol><p>Disabling the abilities setting removes execution access. Abilities never return client IDs, secrets, access tokens, or refresh tokens.</p>';
        $export = wp_nonce_url(add_query_arg(['action' => 'wpsso_export', 'kind' => 'configuration', 'network' => $network ? 1 : 0], admin_url('admin-post.php')), 'wpsso_export');
        echo '<h3>Configuration backup</h3><p><a class="button" href="' . esc_url($export) . '">Download configuration backup</a></p><p class="description">Credential-free JSON includes mappings and policy. Client IDs, all secrets, and tokens are excluded; enter credentials again after restoring on another installation. Retain an independent WordPress database backup for a complete recovery.</p>';
        if (!$enforced) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data"><input type="hidden" name="action" value="wpsso_import">';
            wp_nonce_field('wpsso_import');
            self::hidden_scope($network);
            echo '<label for="wpsso-import">Restore configuration JSON (maximum 1 MB)</label><p><input type="file" id="wpsso-import" name="configuration" accept="application/json,.json" required> <button type="submit" class="button">Import configuration</button></p></form>';
        }
        echo '<h3>Support</h3><p>Support destinations are configured by your site administrator.</p><p class="wpsso-support-links">';
        foreach (['booking_url' => 'Book a call / screen-share', 'forum_url' => 'Visit support forum', 'demo_url' => 'Request a demo / trial'] as $key => $label) {
            if (!empty($settings['support'][$key])) {
                echo '<a class="button" href="' . esc_url($settings['support'][$key]) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a> ';
            }
        }
        echo '</p>';
        if (!empty($settings['support']['email'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wpsso_support">';
            wp_nonce_field('wpsso_support');
            self::hidden_scope($network);
            self::field('message', '', 'Support message', 'textarea', 'Sent to ' . $settings['support']['email'] . ' when you submit this form.');
            self::check('include_configuration', false, 'Send Plugin Configuration with this message', 'Excludes client IDs, secrets, passwords, bearer tokens, and refresh tokens. Other policy and endpoint data will be included.');
            echo '<p><button class="button" type="submit">Submit support query</button></p></form>';
        }
        echo '</section>';
    }

    public static function meta_boxes(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ($type !== 'attachment') {
                add_meta_box('wpsso-policy', 'SSO access restriction', [self::class, 'post_box'], $type, 'side');
            }
        }
    }

    public static function post_box(\WP_Post $post): void {
        wp_nonce_field('wpsso_policy', 'wpsso_policy_nonce');
        $rule = get_post_meta($post->ID, '_wpsso_rule', true);
        self::policy_fields(is_array($rule) ? $rule : []);
    }

    private static function policy_fields(array $rule): void {
        self::check('wpsso_rule[login]', $rule['login'] ?? false, 'Require a logged-in user');
        self::field('wpsso_rule[roles]', implode(', ', $rule['roles'] ?? []), 'Allowed role slugs', 'text', 'Comma separated. Leave empty for any role.');
        self::field('wpsso_rule[groups]', implode(', ', $rule['groups'] ?? []), 'Allowed provider groups', 'text', 'Comma separated. A matching role or group grants access; logged-out users are sent to SSO.');
    }

    private static function submitted_rule(): array {
        $input = isset($_POST['wpsso_rule']) && is_array($_POST['wpsso_rule']) ? wp_unslash($_POST['wpsso_rule']) : [];
        return ['login' => !empty($input['login']), 'roles' => array_values(array_filter(array_map('sanitize_key', self::lines($input['roles'] ?? '')))), 'groups' => array_values(array_filter(array_map('sanitize_text_field', self::lines($input['groups'] ?? ''))))];
    }

    public static function save_post(int $post_id, \WP_Post $post): void {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('manage_options') || !current_user_can('edit_post', $post_id) || empty($_POST['wpsso_policy_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpsso_policy_nonce'])), 'wpsso_policy')) {
            return;
        }
        update_post_meta($post_id, '_wpsso_rule', self::submitted_rule());
    }

    public static function category_add(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="form-field"><h3>SSO access restriction</h3>';
        wp_nonce_field('wpsso_policy', 'wpsso_policy_nonce');
        self::policy_fields([]);
        echo '</div>';
    }

    public static function category_edit(\WP_Term $term): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<tr class="form-field"><th scope="row">SSO access restriction</th><td>';
        wp_nonce_field('wpsso_policy', 'wpsso_policy_nonce');
        $rule = get_term_meta($term->term_id, '_wpsso_rule', true);
        self::policy_fields(is_array($rule) ? $rule : []);
        echo '</td></tr>';
    }

    public static function save_category(int $term_id): void {
        if (!current_user_can('manage_options') || !current_user_can('manage_categories') || empty($_POST['wpsso_policy_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpsso_policy_nonce'])), 'wpsso_policy')) {
            return;
        }
        update_term_meta($term_id, '_wpsso_rule', self::submitted_rule());
    }

    public static function attachment_fields(array $fields, \WP_Post $post): array {
        if (current_user_can('manage_options') && current_user_can('edit_post', $post->ID)) {
            $fields['wpsso_protected'] = ['label' => 'SSO protected', 'input' => 'html', 'html' => '<label><input type="checkbox" name="attachments[' . absint($post->ID) . '][wpsso_protected]" value="1"' . checked((bool) get_post_meta($post->ID, '_wpsso_protected', true), true, false) . '> Require SSO</label><p>Also deny direct static requests at the web server.</p>'];
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
