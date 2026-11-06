<?php
/**
 * Plugin Name: PortalWeave SSO
 * Plugin URI: https://github.com/RobotHanzo/WordPressSSO
 * Description: Multi-provider OAuth/OIDC sign-in, provisioning, access control and audit tools.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: RobotHanzo
 * Author URI: https://github.com/RobotHanzo
 * License: GPL-2.0-or-later
 * Text Domain: portalweave-sso
 * Domain Path: /languages
 * Network: true
 */

defined('ABSPATH') || exit;

define('PORTALWEAVE_SSO_VERSION', '1.0.0');
define('PORTALWEAVE_SSO_FILE', __FILE__);
define('PORTALWEAVE_SSO_DIR', plugin_dir_path(__FILE__));
define('PORTALWEAVE_SSO_URL', plugin_dir_url(__FILE__));

if (!function_exists('openssl_encrypt') || !function_exists('openssl_verify')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>' . esc_html__('PortalWeave SSO requires the PHP OpenSSL extension. Enable it on the server to activate authentication features.', 'portalweave-sso') . '</p></div>';
    });
    return;
}

foreach (['config', 'audit', 'http', 'jwt', 'accounts', 'auth', 'access', 'privacy', 'cleanup', 'display', 'admin', 'extensions', 'abilities', 'cli'] as $wpsso_class) {
    require_once PORTALWEAVE_SSO_DIR . 'includes/class-' . $wpsso_class . '.php';
}
unset($wpsso_class);

register_activation_hook(__FILE__, function ($network_wide) {
    if (is_multisite() && $network_wide) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id()]) as $site_id) {
            switch_to_blog($site_id);
            \WPSSO\Audit::install();
            restore_current_blog();
        }
    } else {
        \WPSSO\Audit::install();
    }
});
register_deactivation_hook(__FILE__, function ($network_wide) {
    $sites = is_multisite() && $network_wide ? get_sites(['fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id()]) : [get_current_blog_id()];
    foreach ($sites as $site_id) {
        if (is_multisite()) {
            switch_to_blog($site_id);
        }
        wp_clear_scheduled_hook('wpsso_cleanup');
        wp_clear_scheduled_hook('wpsso_state_cleanup');
        wp_clear_scheduled_hook('wpsso_extension_cleanup');
        wp_unschedule_hook('wpsso_expire_claim');
        if (is_multisite()) {
            restore_current_blog();
        }
    }
});
add_action('wp_initialize_site', function ($site) {
    if (!array_key_exists(plugin_basename(PORTALWEAVE_SSO_FILE), (array) get_network_option((int) $site->site_id, 'active_sitewide_plugins', []))) {
        return;
    }
    switch_to_blog($site->blog_id);
    \WPSSO\Audit::install();
    restore_current_blog();
}, 200);
add_action('plugins_loaded', function () {
    if (get_option('wpsso_db_version') !== PORTALWEAVE_SSO_VERSION) {
        \WPSSO\Audit::install();
    }
    foreach (['Auth', 'Access', 'Privacy', 'Display', 'Admin', 'Extensions', 'Abilities', 'Cli'] as $class) {
        call_user_func(['WPSSO\\' . $class, 'register']);
    }
    add_action('wpsso_cleanup', ['WPSSO\\Audit', 'cleanup']);
});
