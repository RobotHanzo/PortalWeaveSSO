<?php
/**
 * Plugin Name: WPSSO OAuth & OpenID Connect
 * Description: Multi-provider OAuth/OIDC sign-in, provisioning, access control and audit tools.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: STSA
 * License: GPL-2.0-or-later
 * Text Domain: wpsso
 * Network: true
 */

defined('ABSPATH') || exit;

define('WPSSO_VERSION', '1.0.0');
define('WPSSO_FILE', __FILE__);
define('WPSSO_DIR', plugin_dir_path(__FILE__));
define('WPSSO_URL', plugin_dir_url(__FILE__));

foreach (['config', 'audit', 'http', 'jwt', 'accounts', 'auth', 'access', 'display', 'admin', 'extensions', 'abilities', 'cli'] as $wpsso_class) {
    require_once WPSSO_DIR . 'includes/class-' . $wpsso_class . '.php';
}
unset($wpsso_class);

register_activation_hook(__FILE__, function ($network_wide) {
    if (is_multisite() && $network_wide) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
            switch_to_blog($site_id);
            \WPSSO\Audit::install();
            restore_current_blog();
        }
    } else {
        \WPSSO\Audit::install();
    }
});
register_deactivation_hook(__FILE__, function ($network_wide) {
    $sites = is_multisite() && $network_wide ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
    foreach ($sites as $site_id) {
        switch_to_blog($site_id);
        wp_clear_scheduled_hook('wpsso_cleanup');
        wp_clear_scheduled_hook('wpsso_state_cleanup');
        restore_current_blog();
    }
});
add_action('wp_initialize_site', function ($site) {
    if (!is_plugin_active_for_network(plugin_basename(WPSSO_FILE))) {
        return;
    }
    switch_to_blog($site->blog_id);
    \WPSSO\Audit::install();
    restore_current_blog();
}, 200);
add_action('plugins_loaded', function () {
    load_plugin_textdomain('wpsso', false, dirname(plugin_basename(WPSSO_FILE)) . '/languages');
    if (get_option('wpsso_db_version') !== WPSSO_VERSION) {
        \WPSSO\Audit::install();
    }
    foreach (['Auth', 'Access', 'Display', 'Admin', 'Extensions', 'Abilities', 'Cli'] as $class) {
        call_user_func(['WPSSO\\' . $class, 'register']);
    }
    add_action('wpsso_cleanup', ['WPSSO\\Audit', 'cleanup']);
});
