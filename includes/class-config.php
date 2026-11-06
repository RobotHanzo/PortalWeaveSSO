<?php
namespace WPSSO;

defined('ABSPATH') || exit;

final class Secrets
{
    private static function key(): string
    {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }

    public static function encrypt(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($text, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Authenticated encryption is unavailable.');
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (!str_starts_with($text, 'enc:v1:')) {
            return ''; // Refuse plaintext or unknown formats at rest.
        }
        $raw = base64_decode(substr($text, 7), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return is_string($plain) ? $plain : '';
    }
}

final class Config
{
    public static function defaults(): array
    {
        return [
            'apps' => [], 'default_app' => '', 'network_enforced' => false,
            'force_auth' => false, 'restrict_site' => false, 'disable_wp_login' => false,
            'single_flow' => false, 'popup' => false, 'login_redirect' => '', 'logout_redirect' => '',
            'redirect_allowlist' => [], 'logout_confirmation' => true, 'allowed_domains' => [], 'blocked_domains' => [],
            'debug' => false, 'audit_enabled' => true, 'audit_ip' => false,
            'audit_retention_days' => 90, 'abilities_enabled' => false, 'delete_data_on_uninstall' => false,
            'button' => ['theme' => 'default', 'shape' => 'round-edge', 'text' => 'Sign in with {app}',
                'above_text' => '', 'logout_message' => 'Signed in as ##user##. ##Sign Out##', 'logout_link' => true,
                'size' => 16, 'radius' => 8, 'spacing' => 12, 'shadow' => true, 'transform' => true,
                'custom_css' => '', 'admin_style' => true],
            'support' => ['email' => '', 'booking_url' => '', 'forum_url' => '', 'demo_url' => ''],
            'modules' => ['scim' => false, 'jwt_sso' => false, 'shared_sso' => false, 'guest' => false,
                'moderation' => false, 'avatar' => false, 'attribute_redirect' => false],
            'scim_token' => '', 'shared_cookie_domain' => '', 'shared_secret' => '', 'shared_sites' => [],
            'guest_ttl' => 3600, 'content_rules' => [], 'protected_media' => [],
        ];
    }

    public static function app_defaults(): array
    {
        return [
            'id' => '', 'name' => '', 'provider' => 'custom', 'enabled' => true, 'protocol' => 'oidc',
            'client_id' => '', 'client_secret' => '', 'scope' => 'openid profile email',
            'authorize_url' => '', 'token_url' => '', 'userinfo_url' => '', 'groupinfo_url' => '',
            'jwks_url' => '', 'issuer' => '', 'logout_url' => '', 'callback_url' => '',
            'credential_method' => 'header', 'grant' => 'authorization_code', 'legacy_grants' => false,
            'pkce' => true, 'state_parameter' => '', 'algorithms' => ['RS256'], 'jwt_secret' => '',
            'subject_attribute' => 'sub',
            'mapping' => ['username' => 'preferred_username', 'email' => 'email', 'first_name' => 'given_name',
                'last_name' => 'family_name', 'display_name' => 'name', 'groups' => 'groups'],
            'custom_mapping' => [], 'role_mapping' => [], 'domain_roles' => [], 'default_role' => 'subscriber',
            'keep_roles' => true, 'block_unmapped' => false, 'auto_register' => true, 'link_existing' => false,
            'keep_attributes' => false, 'keep_email' => false, 'allow_duplicate_emails' => false,
            'domain_mapping' => [], 'allow_admin' => false, 'verified_attribute' => 'email_verified',
            'verified_value' => true, 'require_verified_email' => true, 'icon_url' => '', 'button_text' => '',
            'redirect_rules' => [], 'guest' => false, 'shared_sso' => false, 'moderation' => false,
            'jwt_sso' => false, 'avatar_attribute' => 'picture', 'backchannel_logout' => false,
        ];
    }

    public static function all(): array
    {
        $local = get_option('wpsso_settings', []);
        $network = is_multisite() ? get_site_option('wpsso_settings', []) : [];
        $stored = !empty($network['network_enforced']) ? $network : $local;
        $settings = array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
        // Recursive merging must never resurrect removed providers.
        $settings['apps'] = is_array($stored['apps'] ?? null) ? $stored['apps'] : [];
        foreach ($settings['apps'] as $id => &$app) {
            $app = array_replace(self::app_defaults(), $app, ['id' => (string) $id]);
            foreach (['client_secret', 'jwt_secret'] as $key) {
                $app[$key] = Secrets::decrypt((string) $app[$key]);
            }
        }
        unset($app);
        foreach (['scim_token', 'shared_secret'] as $key) {
            $settings[$key] = Secrets::decrypt((string) $settings[$key]);
        }
        return $settings;
    }

    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }

    public static function apps(): array { return self::get('apps', []); }

    public static function app(string $id): ?array { return self::apps()[$id] ?? null; }

    public static function callback(array $app = []): string
    {
        return add_query_arg('wpsso_action', 'callback', ($app['callback_url'] ?? '') ?: home_url('/'));
    }

    public static function list($value): array
    {
        return array_values(array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string) $v) : '', is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value)), static fn($v) => $v !== ''));
    }

    private static function bools(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            $data[$key] = filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        return $data;
    }

    public static function safe_meta_key(string $key): bool
    {
        // User-controlled meta cannot touch internal state, capabilities, sessions or core fields.
        return (bool) preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)
            && !preg_match('/(?:capabilit|user_level|session|password|activation|application_password|wpsso|^wp_)/i', $key)
            && !in_array($key, ['role', 'roles', 'user_email', 'user_login', 'user_pass', 'user_status', 'spam', 'deleted', 'primary_blog', 'source_domain'], true);
    }

    public static function save(array $input, bool $network = false)
    {
        if ($network && !is_multisite()) {
            return new \WP_Error('wpsso_network', 'Network settings require WordPress multisite.');
        }
        if (!$network && is_multisite() && !empty(get_site_option('wpsso_settings', [])['network_enforced'])) {
            return new \WP_Error('wpsso_network_enforced', 'This network enforces its SSO configuration.');
        }
        $raw = $network ? get_site_option('wpsso_settings', []) : get_option('wpsso_settings', []);
        $old = array_replace(self::defaults(), is_array($raw) ? $raw : []);
        $settings = array_replace($old, array_intersect_key($input, self::defaults()));
        foreach (['default_app', 'login_redirect', 'logout_redirect', 'shared_cookie_domain', 'scim_token', 'shared_secret'] as $key) {
            if (!is_string($settings[$key])) {
                return new \WP_Error('wpsso_setting_type', 'URL, identifier and credential settings must be strings.');
            }
        }
        $settings = self::bools($settings, ['network_enforced', 'force_auth', 'restrict_site', 'disable_wp_login', 'single_flow', 'popup', 'logout_confirmation', 'debug', 'audit_enabled', 'audit_ip', 'abilities_enabled', 'delete_data_on_uninstall']);
        foreach (['redirect_allowlist', 'shared_sites'] as $key) {
            $settings[$key] = self::list($settings[$key]);
            foreach ($settings[$key] as $url) {
                if (!self::https_url($url) || wp_parse_url($url, PHP_URL_FRAGMENT) || wp_parse_url($url, PHP_URL_QUERY)) {
                    return new \WP_Error('wpsso_url', 'Redirect and shared-site lists require HTTPS URLs without query strings or fragments.');
                }
                if ($key === 'shared_sites' && !in_array(wp_parse_url($url, PHP_URL_PATH), ['', '/', null], true)) {
                    return new \WP_Error('wpsso_shared_origin', 'Shared sites must be exact HTTPS origins.');
                }
            }
            if ($key === 'shared_sites') {
                $settings[$key] = array_map(static fn($url) => rtrim($url, '/'), $settings[$key]);
            }
        }
        foreach (['allowed_domains', 'blocked_domains'] as $key) {
            $settings[$key] = array_map('strtolower', self::list($settings[$key]));
            foreach ($settings[$key] as $domain) {
                if (!self::domain($domain)) {
                    return new \WP_Error('wpsso_domain', 'Enter exact email domains, without wildcards or URLs.');
                }
            }
        }
        $apps = array_key_exists('apps', $input) ? $input['apps'] : $old['apps'];
        if (!is_array($apps) || count($apps) > 100) {
            return new \WP_Error('wpsso_apps', 'Providers must be a map of at most 100 apps.');
        }
        $settings['apps'] = [];
        foreach ($apps as $id => $app) {
            if (!is_array($app) || sanitize_key((string) $id) !== (string) $id || !$id) {
                return new \WP_Error('wpsso_app_id', 'Provider IDs must use lowercase letters, digits, underscores or dashes.');
            }
            $prior = $old['apps'][$id] ?? [];
            $app = array_replace(self::app_defaults(), $prior, array_intersect_key($app, self::app_defaults()), ['id' => $id]);
            $app = self::bools($app, ['enabled', 'legacy_grants', 'pkce', 'keep_roles', 'block_unmapped', 'auto_register', 'link_existing', 'keep_attributes', 'keep_email', 'allow_duplicate_emails', 'allow_admin', 'require_verified_email', 'guest', 'shared_sso', 'moderation', 'jwt_sso', 'backchannel_logout']);
            foreach (['name', 'provider', 'scope', 'client_id', 'state_parameter', 'subject_attribute', 'verified_attribute', 'avatar_attribute', 'button_text'] as $key) {
                if (!is_scalar($app[$key])) {
                    return new \WP_Error('wpsso_setting_type', 'Provider text settings must be strings.');
                }
                $app[$key] = sanitize_text_field((string) $app[$key]);
            }
            foreach (['protocol' => ['oidc', 'oauth2'], 'credential_method' => ['header', 'body', 'none'], 'grant' => ['authorization_code', 'implicit', 'password']] as $key => $values) {
                if (!in_array($app[$key], $values, true)) {
                    return new \WP_Error('wpsso_app_setting', 'Invalid protocol, grant or credential placement.');
                }
            }
            if ($app['grant'] !== 'authorization_code' && !$app['legacy_grants']) {
                return new \WP_Error('wpsso_legacy_grant', 'Enable legacy grants explicitly before selecting Implicit or Password.');
            }
            if ($app['grant'] === 'authorization_code' && !$app['pkce']) {
                // Confidential providers can explicitly disable PKCE, but never public clients.
                if ($app['credential_method'] === 'none') {
                    return new \WP_Error('wpsso_pkce', 'Public clients must use PKCE.');
                }
            }
            foreach (['authorize_url', 'token_url', 'userinfo_url', 'groupinfo_url', 'jwks_url', 'logout_url', 'issuer', 'callback_url', 'icon_url'] as $key) {
                if (!is_string($app[$key])) {
                    return new \WP_Error('wpsso_endpoint', 'Provider endpoints must be URL strings.');
                }
                $app[$key] = trim((string) $app[$key]);
                if ($app[$key] !== '' && (!self::https_url($app[$key]) || wp_parse_url($app[$key], PHP_URL_FRAGMENT))) {
                    return new \WP_Error('wpsso_endpoint', 'Provider endpoints, icons and callbacks must use HTTPS without embedded credentials.');
                }
            }
            if ($app['callback_url'] && (!Redirect::same_origin($app['callback_url'], home_url('/')) || wp_parse_url($app['callback_url'], PHP_URL_FRAGMENT))) {
                return new \WP_Error('wpsso_callback_origin', 'The callback must use this site origin and have no fragment.');
            }
            $app['algorithms'] = self::list($app['algorithms']);
            if (!$app['algorithms'] || array_diff($app['algorithms'], ['RS256', 'RS384', 'RS512', 'HS256', 'HS384', 'HS512'])) {
                return new \WP_Error('wpsso_algorithm', 'Only explicitly selected RSA or HMAC SHA-2 JWT algorithms are supported.');
            }
            if (array_filter($app['algorithms'], static fn($v) => str_starts_with($v, 'HS')) && array_filter($app['algorithms'], static fn($v) => str_starts_with($v, 'RS'))) {
                return new \WP_Error('wpsso_algorithm_family', 'Select either HMAC or RSA algorithms for one provider.');
            }
            foreach (['mapping', 'custom_mapping', 'role_mapping', 'domain_roles', 'domain_mapping', 'redirect_rules'] as $key) {
                if (!is_array($app[$key])) {
                    return new \WP_Error('wpsso_mapping', 'Mappings must be JSON objects or lists.');
                }
            }
            $app['mapping'] = array_replace(self::app_defaults()['mapping'], array_intersect_key($app['mapping'], self::app_defaults()['mapping']));
            foreach ($app['mapping'] as $path) {
                if (!is_string($path) || strlen($path) > 256) {
                    return new \WP_Error('wpsso_mapping', 'Claim paths must be strings of at most 256 characters.');
                }
            }
            foreach ($app['custom_mapping'] as $key => $path) {
                if (!self::safe_meta_key((string) $key) || !is_string($path)) {
                    return new \WP_Error('wpsso_meta', 'Custom mappings must use safe public profile keys and string claim paths.');
                }
            }
            foreach (['role_mapping', 'domain_roles'] as $key) {
                foreach ($app[$key] as $source => &$roles) {
                    $roles = self::list($roles);
                    foreach ($roles as $role) {
                        if (!get_role($role)) {
                            return new \WP_Error('wpsso_role', 'Role mappings must refer to existing WordPress roles.');
                        }
                    }
                    if ($key === 'domain_roles' && !self::domain((string) $source)) {
                        return new \WP_Error('wpsso_domain', 'Domain role mappings require exact email domains.');
                    }
                }
                unset($roles);
            }
            if (!get_role($app['default_role'])) {
                return new \WP_Error('wpsso_role', 'Choose an existing default role.');
            }
            foreach ($app['domain_mapping'] as $from => $to) {
                if (!self::domain((string) $from) || !self::domain((string) $to)) {
                    return new \WP_Error('wpsso_domain', 'Domain mappings require exact source and target domains.');
                }
            }
            foreach (['client_secret', 'jwt_secret'] as $key) {
                $submitted = $input['apps'][$id][$key] ?? '';
                if (!is_string($submitted)) {
                    return new \WP_Error('wpsso_setting_type', 'Provider secrets must be strings.');
                }
                if (!empty($input['apps'][$id]['clear_' . $key])) {
                    $app[$key] = '';
                } elseif ($submitted !== '' && $submitted !== '********') {
                    $app[$key] = Secrets::encrypt((string) $submitted);
                } else {
                    $app[$key] = $prior[$key] ?? '';
                }
            }
            if ($app['enabled'] && (!$app['client_id'] || !$app['authorize_url'] || ($app['grant'] !== 'implicit' && !$app['token_url']))) {
                return new \WP_Error('wpsso_incomplete', 'Enabled providers require a client ID and appropriate authorization/token endpoints.');
            }
            if ($app['enabled'] && $app['protocol'] === 'oidc' && (!$app['issuer'] || (!$app['jwks_url'] && !Secrets::decrypt($app['jwt_secret']) && !Secrets::decrypt($app['client_secret'])))) {
                return new \WP_Error('wpsso_oidc_keys', 'OIDC requires an issuer and a JWKS URL or HMAC secret.');
            }
            $settings['apps'][$id] = $app;
        }
        $settings['default_app'] = sanitize_key((string) $settings['default_app']);
        if ($settings['default_app'] && empty($settings['apps'][$settings['default_app']]['enabled'])) {
            return new \WP_Error('wpsso_default_app', 'The default provider must be enabled.');
        }
        if (($settings['force_auth'] || $settings['restrict_site'] || $settings['disable_wp_login'] || $settings['single_flow']) && empty($settings['apps'][$settings['default_app']]['enabled'])) {
            return new \WP_Error('wpsso_lockout', 'Choose an enabled default provider before restricting access or enabling a single flow.');
        }
        foreach (['scim_token', 'shared_secret'] as $key) {
            if (!empty($input['clear_' . $key])) {
                $settings[$key] = '';
            } elseif (!empty($input[$key]) && $input[$key] !== '********') {
                if (strlen((string) $input[$key]) < 32) {
                    return new \WP_Error('wpsso_secret_length', 'Provisioning and shared-session secrets require at least 32 characters.');
                }
                $settings[$key] = Secrets::encrypt((string) $input[$key]);
            } else {
                $settings[$key] = $old[$key];
            }
        }
        $settings['modules'] = self::bools(array_replace(self::defaults()['modules'], (array) $settings['modules']), array_keys(self::defaults()['modules']));
        $settings['audit_retention_days'] = max(1, min(3650, absint($settings['audit_retention_days'])));
        $settings['guest_ttl'] = max(300, min(86400, absint($settings['guest_ttl'])));
        $settings['button'] = array_replace(self::defaults()['button'], (array) $settings['button']);
        foreach (['theme', 'shape', 'text', 'above_text', 'logout_message', 'custom_css'] as $key) {
            if (!is_string($settings['button'][$key])) {
                return new \WP_Error('wpsso_button_type', 'Button text and appearance settings must be strings.');
            }
        }
        foreach (['size' => [10, 48], 'radius' => [0, 100], 'spacing' => [0, 100]] as $key => $bounds) {
            $settings['button'][$key] = max($bounds[0], min($bounds[1], (int) $settings['button'][$key]));
        }
        $settings['button'] = self::bools($settings['button'], ['shadow', 'transform', 'logout_link', 'admin_style']);
        $settings['button']['custom_css'] = wp_strip_all_tags((string) $settings['button']['custom_css']);
        $settings['support'] = array_replace(self::defaults()['support'], (array) $settings['support']);
        $settings['support']['email'] = sanitize_email($settings['support']['email']);
        foreach (['booking_url', 'forum_url', 'demo_url'] as $key) {
            if ($settings['support'][$key] && !self::https_url($settings['support'][$key])) {
                return new \WP_Error('wpsso_support_url', 'Support links must use HTTPS.');
            }
        }
        if (!is_array($settings['content_rules']) || !is_array($settings['protected_media'])) {
            return new \WP_Error('wpsso_rules', 'Content and media rules must be arrays.');
        }
        if ($network && ($settings['content_rules'] || $settings['protected_media'])) {
            return new \WP_Error('wpsso_network_content', 'Network settings cannot contain site-local post, category or attachment IDs. Use the content and media editors on each site.');
        }
        $settings['shared_cookie_domain'] = strtolower(ltrim(trim((string) $settings['shared_cookie_domain']), '.'));
        if ($settings['shared_cookie_domain'] && (!self::domain($settings['shared_cookie_domain']) || substr_count($settings['shared_cookie_domain'], '.') < 1)) {
            return new \WP_Error('wpsso_shared_domain', 'Use a registrable parent domain for shared sessions.');
        }
        $settings['protected_media'] = array_values(array_unique(array_filter(array_map('absint', $settings['protected_media']))));
        // Complete every validation before moving any media bytes.
        foreach ($settings['protected_media'] as $attachment_id) {
            if (get_post_type($attachment_id) !== 'attachment') {
                return new \WP_Error('wpsso_media_id', 'Protected media IDs must identify existing attachments.');
            }
        }
        $moved = [];
        foreach ($settings['protected_media'] as $attachment_id) {
            $had_private = (bool) get_post_meta($attachment_id, '_wpsso_private_files', true);
            $protected = Access::protect_media($attachment_id);
            if (is_wp_error($protected)) {
                // Keep already-relocated attachments discoverable and protected
                // even when a later filesystem operation prevents settings save.
                foreach (array_merge($moved, [$attachment_id]) as $moved_id) {
                    if (get_post_meta($moved_id, '_wpsso_private_files', true)) {
                        update_post_meta($moved_id, '_wpsso_protected', 1);
                    }
                }
                return $protected;
            }
            if (!$had_private) {
                $moved[] = $attachment_id;
            }
        }
        $restored_ids = [];
        foreach (array_diff((array) $old['protected_media'], $settings['protected_media']) as $attachment_id) {
            if (!get_post_meta($attachment_id, '_wpsso_protected', true)) {
                $restored = Access::unprotect_media((int) $attachment_id);
                if (is_wp_error($restored)) {
                    // Reflect completed intentional restores rather than leave
                    // a saved policy claiming that public files are private.
                    $old['protected_media'] = array_values(array_diff($old['protected_media'], $restored_ids));
                    $network ? update_site_option('wpsso_settings', $old) : update_option('wpsso_settings', $old, false);
                    foreach ($moved as $moved_id) {
                        update_post_meta($moved_id, '_wpsso_protected', 1);
                    }
                    return $restored;
                }
                $restored_ids[] = (int) $attachment_id;
            }
        }
        if ($network) {
            update_site_option('wpsso_settings', $settings);
        } else {
            update_option('wpsso_settings', $settings, false);
        }
        do_action('wpsso_configuration_saved', $network);
        return true;
    }

    /** Restore portable configuration without discarding locally stored credentials. */
    public static function import(array $input, bool $network = false)
    {
        $old = $network ? get_site_option('wpsso_settings', []) : get_option('wpsso_settings', []);
        if (isset($input['apps']) && is_array($input['apps'])) {
            foreach ($input['apps'] as $id => &$app) {
                if (!is_array($app)) {
                    return new \WP_Error('wpsso_apps', 'Imported providers must be objects.');
                }
                if (empty($app['client_id'])) {
                    $app['client_id'] = $old['apps'][$id]['client_id'] ?? '';
                }
                if (!$app['client_id']) {
                    $app['enabled'] = false;
                }
            }
            unset($app);
            $default = $input['default_app'] ?? $old['default_app'] ?? '';
            if (!$default || empty($input['apps'][$default]['enabled'])) {
                $input['default_app'] = '';
                foreach (['force_auth', 'restrict_site', 'disable_wp_login', 'single_flow'] as $key) {
                    $input[$key] = false;
                }
            }
        }
        return self::save($input, $network);
    }

    public static function https_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && empty($parts['user']) && empty($parts['pass']) && !preg_match('/[\x00-\x20\\\\|]/', $url);
    }

    public static function domain(string $domain): bool
    {
        return strlen($domain) <= 253 && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $domain);
    }

    public static function redacted(): array
    {
        $settings = self::all();
        foreach ($settings['apps'] as &$app) {
            foreach (['client_id', 'client_secret', 'jwt_secret', 'state_parameter'] as $key) {
                $app[$key] = '';
            }
            foreach (['authorize_url', 'token_url', 'userinfo_url', 'groupinfo_url', 'jwks_url', 'logout_url', 'issuer', 'callback_url', 'icon_url'] as $key) {
                $app[$key] = preg_replace('/[?#].*$/', '', $app[$key]);
            }
        }
        unset($app);
        $settings['scim_token'] = '';
        $settings['shared_secret'] = '';
        // Arbitrary CSS and custom claim values may contain personal information.
        $settings['button']['custom_css'] = '';
        return apply_filters('wpsso_redacted_configuration', $settings);
    }
}

final class Redirect
{
    private static function origin(string $url): string
    {
        $p = wp_parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return '';
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($p['host']) . ':' . $port;
    }

    public static function same_origin(string $a, string $b): bool
    {
        return self::origin($a) !== '' && self::origin($a) === self::origin($b);
    }

    public static function validate($url, string $fallback = ''): string
    {
        $fallback = $fallback ?: home_url('/');
        if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\\\\]/', $url) || preg_match('/%(?:0[ad]|5c)/i', $url)) {
            return $fallback;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = rtrim(home_url('/'), '/') . $url;
        }
        if (!self::origin($url)) {
            return $fallback;
        }
        if (self::same_origin($url, home_url('/'))) {
            return esc_url_raw($url);
        }
        foreach (Config::get('redirect_allowlist', []) as $allowed) {
            if (!self::same_origin($url, $allowed)) {
                continue;
            }
            $path = rtrim(wp_parse_url($allowed, PHP_URL_PATH) ?: '', '/');
            $candidate = wp_parse_url($url, PHP_URL_PATH) ?: '';
            // Reject encoded separators and dot-segments that change a prefix after browser normalization.
            $decoded = rawurldecode($candidate);
            if (preg_match('~(?:^|/)\.{1,2}(?:/|$)|\\\\|[\x00-\x20]~', $decoded) || str_contains($decoded, '%')) {
                continue;
            }
            if ($path === '' || $decoded === $path || str_starts_with($decoded, $path . '/')) {
                return esc_url_raw($url);
            }
        }
        return $fallback;
    }

    public static function current(): string
    {
        // The URI is never used directly: validate() enforces origin, control-character and URL rules below.
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict URL validation follows; text sanitizers alter valid URL encoding.
        $url = self::validate($uri);
        return remove_query_arg(['code', 'state', 'id_token', 'access_token', 'refresh_token', 'wpsso_action', '_wpnonce'], $url);
    }
}
