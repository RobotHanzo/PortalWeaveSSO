<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Build isolated settings updates without treating hidden fields as deletions. */
final class AdminSettings
{
    private const FIELDS = [
        'providers' => ['apps', 'new_app', 'provider_setup'],
        'login' => ['default_app', 'force_auth', 'disable_wp_login', 'single_flow', 'popup', 'logout_confirmation', 'login_redirect', 'logout_redirect', 'redirect_allowlist'],
        'access' => ['restrict_site', 'allowed_domains', 'blocked_domains', 'content_rules', 'protected_media', 'network_enforced'],
        'appearance' => ['button'],
        'modules' => ['modules', 'scim_token', 'clear_scim_token', 'shared_secret', 'clear_shared_secret', 'shared_cookie_domain', 'shared_sites', 'guest_ttl', 'abilities_enabled'],
        'reports' => ['audit_enabled', 'audit_ip', 'audit_retention_days', 'debug'],
        'tools' => ['support', 'delete_data_on_uninstall'],
    ];

    /** Input has already passed the administrator form's type/JSON parsers. */
    public static function prepare(array $input, string $screen, string $provider = '', bool $creating = false, bool $network = false)
    {
        if (!isset(self::FIELDS[$screen]) || array_diff(array_keys($input), self::FIELDS[$screen])) {
            return new \WP_Error('portalweave_sso_settings_scope', __('The submitted fields do not belong to this settings screen. Reload the page and try again.', 'portalweave-sso'));
        }
        if (!$input) {
            return new \WP_Error('portalweave_sso_settings_empty', __('No settings were submitted for this screen.', 'portalweave-sso'));
        }
        if (!$network && array_key_exists('network_enforced', $input)) {
            return new \WP_Error('portalweave_sso_settings_scope', __('Network enforcement can only be changed in Network Admin.', 'portalweave-sso'));
        }
        $stored = $network ? get_site_option('portalweave_sso_settings', []) : get_option('portalweave_sso_settings', []);
        $stored = is_array($stored) ? $stored : [];
        if ($screen === 'providers') {
            if (array_key_exists('provider_setup', $input)) {
                if (!$creating || $provider !== '' || array_keys($input) !== ['provider_setup'] || !is_array($input['provider_setup'])) {
                    return new \WP_Error('portalweave_sso_provider_target', __('Guided setup can only create one new identity provider.', 'portalweave-sso'));
                }
                $app = ProviderSetup::prepare($input['provider_setup']);
                if (is_wp_error($app)) {
                    return $app;
                }
                $input = ['new_app' => $app];
            }
            return self::provider($input, $provider, $creating, $stored);
        }
        if ($provider !== '' || $creating) {
            return new \WP_Error('portalweave_sso_provider_target', __('This settings screen does not edit an identity provider.', 'portalweave-sso'));
        }
        foreach (['button', 'modules', 'support'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $defaults = Config::defaults()[$key];
            if (!is_array($input[$key]) || array_diff(array_keys($input[$key]), array_keys($defaults))) {
                return new \WP_Error('portalweave_sso_settings_scope', __('The submitted settings contain unexpected fields.', 'portalweave-sso'));
            }
            foreach ($input[$key] as $value) {
                if (!is_scalar($value)) {
                    return new \WP_Error('portalweave_sso_form_type', __('Settings form values must be scalar fields.', 'portalweave-sso'));
                }
            }
            $prior = isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : [];
            $input[$key] = array_replace($defaults, array_intersect_key($prior, $defaults), $input[$key]);
        }
        // No stored secrets are copied into submitted input. Config owns their preservation/encryption.
        return $input;
    }

    private static function provider(array $input, string $provider, bool $creating, array $stored)
    {
        $prior = isset($stored['apps']) && is_array($stored['apps']) ? $stored['apps'] : [];
        if ($creating) {
            if ($provider !== '' || array_keys($input) !== ['new_app'] || !is_array($input['new_app'])) {
                return new \WP_Error('portalweave_sso_provider_target', __('Submit one new identity provider from the add-provider screen.', 'portalweave-sso'));
            }
            $app = $input['new_app'];
            $provider = $app['id'] ?? '';
            if (!is_string($provider) || $provider === '' || sanitize_key($provider) !== $provider) {
                return new \WP_Error('portalweave_sso_app_id', __('Enter a valid unique app ID using lowercase letters, digits, underscores or dashes.', 'portalweave-sso'));
            }
            if (array_key_exists($provider, $prior)) {
                return new \WP_Error('portalweave_sso_app_exists', __('A provider with this app ID already exists.', 'portalweave-sso'));
            }
            if (in_array($app['_delete'] ?? false, [true, 1, '1'], true)) {
                return new \WP_Error('portalweave_sso_provider_target', __('A new provider cannot be deleted before it is created.', 'portalweave-sso'));
            }
        } else {
            if ($provider === '' || sanitize_key($provider) !== $provider || !array_key_exists($provider, $prior)
                || array_keys($input) !== ['apps'] || !is_array($input['apps']) || array_map('strval', array_keys($input['apps'])) !== [$provider]
                || !is_array($input['apps'][$provider])) {
                return new \WP_Error('portalweave_sso_provider_target', __('Submit only the identity provider selected on this screen.', 'portalweave-sso'));
            }
            $app = $input['apps'][$provider];
            if (isset($app['id']) && $app['id'] !== $provider) {
                return new \WP_Error('portalweave_sso_provider_target', __('The provider ID does not match the selected provider.', 'portalweave-sso'));
            }
        }
        $allowed = Config::app_defaults() + ['_delete' => false, 'clear_client_secret' => false, 'clear_jwt_secret' => false];
        if (array_diff(array_keys($app), array_keys($allowed))) {
            return new \WP_Error('portalweave_sso_settings_scope', __('The submitted provider contains unexpected fields.', 'portalweave-sso'));
        }
        // Empty placeholders ask Config to retain each provider's raw prior, without resubmitting ciphertext.
        $apps = array_fill_keys(array_keys($prior), []);
        if (in_array($app['_delete'] ?? false, [true, 1, '1'], true)) {
            unset($apps[$provider]);
            $result = ['apps' => $apps];
            if (($stored['default_app'] ?? '') === $provider) {
                $result['default_app'] = '';
            }
            return $result;
        }
        unset($app['_delete']);
        $app['id'] = $provider;
        $apps[$provider] = $app;
        return ['apps' => $apps];
    }
}
