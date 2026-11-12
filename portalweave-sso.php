<?php
/**
 * Plugin Name: PortalWeave SSO
 * Plugin URI: https://github.com/RobotHanzo/PortalWeaveSSO
 * Description: Multi-provider OAuth/OIDC sign-in, provisioning, access control and audit tools.
 * Version: 1.3.2
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

define('PORTALWEAVE_SSO_VERSION', '1.3.2');
define('PORTALWEAVE_SSO_FILE', __FILE__);
define('PORTALWEAVE_SSO_DIR', plugin_dir_path(__FILE__));
define('PORTALWEAVE_SSO_URL', plugin_dir_url(__FILE__));

add_action('init', static function () {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- GitHub/manual ZIP installs need the bundled catalogs before WordPress.org language packs are available.
    load_plugin_textdomain('portalweave-sso', false, dirname(plugin_basename(PORTALWEAVE_SSO_FILE)) . '/languages');
}, 0);

if (!function_exists('openssl_encrypt') || !function_exists('openssl_verify')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>' . esc_html__('PortalWeave SSO requires the PHP OpenSSL extension. Enable it on the server to activate authentication features.', 'portalweave-sso') . '</p></div>';
    });
    return;
}

foreach (['config', 'client-ip', 'audit', 'http', 'jwt', 'accounts', 'logout-view', 'auth', 'access', 'privacy', 'cleanup', 'display', 'provider-setup', 'provider-wizard', 'admin-settings', 'activity-view', 'admin', 'extensions', 'abilities', 'cli'] as $portalweave_sso_class) {
    require_once PORTALWEAVE_SSO_DIR . 'includes/class-' . $portalweave_sso_class . '.php';
}
unset($portalweave_sso_class);

register_activation_hook(__FILE__, function ($network_wide) {
    if (is_multisite() && $network_wide) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id()]) as $site_id) {
            switch_to_blog($site_id);
            \PortalWeaveSSO\Audit::install();
            restore_current_blog();
        }
    } else {
        \PortalWeaveSSO\Audit::install();
    }
});
register_deactivation_hook(__FILE__, function ($network_wide) {
    $sites = is_multisite() && $network_wide ? get_sites(['fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id()]) : [get_current_blog_id()];
    foreach ($sites as $site_id) {
        if (is_multisite()) {
            switch_to_blog($site_id);
        }
        wp_clear_scheduled_hook('portalweave_sso_cleanup');
        wp_clear_scheduled_hook('portalweave_sso_state_cleanup');
        wp_clear_scheduled_hook('portalweave_sso_extension_cleanup');
        wp_unschedule_hook('portalweave_sso_expire_claim');
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
    \PortalWeaveSSO\Audit::install();
    restore_current_blog();
}, 200);
add_action('plugins_loaded', function () {
    if (get_option('portalweave_sso_db_version') !== PORTALWEAVE_SSO_VERSION) {
        \PortalWeaveSSO\Audit::install();
    }
    foreach (['Auth', 'Access', 'Privacy', 'Display', 'Admin', 'Extensions', 'Abilities', 'Cli'] as $class) {
        call_user_func(['PortalWeaveSSO\\' . $class, 'register']);
    }
    add_action('portalweave_sso_cleanup', ['PortalWeaveSSO\\Audit', 'cleanup']);
});
