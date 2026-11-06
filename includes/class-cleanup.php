<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Deliberate uninstall cleanup. Never removes accounts, posts, public profile fields or private vault files. */
final class Cleanup
{
    public static function site(array $local, array $network = []): void
    {
        foreach (['portalweave_sso_cleanup', 'portalweave_sso_state_cleanup', 'portalweave_sso_extension_cleanup', 'portalweave_sso_expire_claim'] as $hook) {
            wp_unschedule_hook($hook);
        }
        if (empty($local['delete_data_on_uninstall']) && empty($network['delete_data_on_uninstall'])) {
            return;
        }
        global $wpdb;
        $suffix = is_multisite() ? '_' . get_current_blog_id() : '';
        $index_key = '_portalweave_sso_identity_keys' . $suffix;
        $keys = [$index_key, '_portalweave_sso_mapped_keys' . $suffix, '_portalweave_sso_groups' . $suffix, '_portalweave_sso_app' . $suffix, '_portalweave_sso_avatar' . $suffix, '_portalweave_sso_moderation' . $suffix];
        foreach (['managed', 'active', 'external', 'groups', 'group_names', 'modified'] as $field) {
            $keys[] = '_portalweave_sso_scim_' . $field . $suffix;
        }
        if (!is_multisite()) {
            $keys[] = '_portalweave_sso_guest_expires';
        }
        foreach ([$local['apps'] ?? [], $network['apps'] ?? []] as $apps) {
            foreach (is_array($apps) ? $apps : [] as $id => $app) {
                if (!is_array($app) || !is_string($app['issuer'] ?? null) || !is_string($app['client_id'] ?? null)) {
                    continue;
                }
                $scope = get_current_blog_id() . '|' . $id . '|' . $app['issuer'] . '|' . $app['client_id'];
                $keys[] = '_portalweave_sso_identity_' . hash('sha256', $scope);
                $keys[] = '_portalweave_sso_sid_' . hash('sha256', $scope);
            }
        }
        // Read the provenance index so links from deleted provider configurations can be removed as well.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must discover the current site-specific metadata provenance without caching personal data.
        $indexes = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT meta_value FROM %i WHERE meta_key = %s', $wpdb->usermeta, $index_key));
        foreach ($indexes as $index) {
            foreach ((array) maybe_unserialize($index) as $key) {
                if (is_string($key) && preg_match('/^_portalweave_sso_(?:identity|sid)_[a-f0-9]{64}$/D', $key)) {
                    $keys[] = $key;
                }
            }
        }
        $token_prefix = '_portalweave_sso_tokens_' . (is_multisite() ? get_current_blog_id() . '_' : '');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove encrypted tokens even when their provider was deleted from configuration.
        $tokens = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like($token_prefix) . '%'));
        foreach (array_unique(array_merge($keys, $tokens)) as $key) {
            delete_metadata('user', 0, $key, '', true);
        }
        delete_metadata('post', 0, '_portalweave_sso_rule', '', true);
        delete_metadata('term', 0, '_portalweave_sso_rule', '', true);
        foreach (['portalweave_sso_settings', 'portalweave_sso_db_version', 'portalweave_sso_debug_log', 'portalweave_sso_scim_groups', 'portalweave_sso_scim_write_lock'] as $option) {
            delete_option($option);
        }
        foreach (['portalweave_sso_state_', 'portalweave_sso_refresh_', 'portalweave_sso_logout_', 'portalweave_sso_claim_', '_portalweave_sso_lock_', '_transient_portalweave_sso_', '_transient_timeout_portalweave_sso_'] as $prefix) {
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded uninstall discovery; delete_option performs the corresponding cache invalidation.
                $options = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT 200', $wpdb->options, $wpdb->esc_like($prefix) . '%'));
                $progress = false;
                foreach ($options as $option) {
                    $progress = delete_option($option) || $progress;
                }
            } while (count($options) === 200 && $progress);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The table identifier is prepared; DDL has no object-cache representation.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'portalweave_sso_audit'));
        // _portalweave_sso_private_files/_portalweave_sso_public_files/_portalweave_sso_protected remain available for safe vault recovery.
    }

    public static function run(): void
    {
        $networks = [];
        $offset = 0;
        do {
            $sites = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]) : [get_current_blog_id()];
            foreach ($sites as $site_id) {
                if (is_multisite()) {
                    switch_to_blog($site_id);
                }
                $local = get_option('portalweave_sso_settings', []);
                $network_id = is_multisite() ? (int) get_site($site_id)->site_id : 0;
                $network = $network_id ? get_network_option($network_id, 'portalweave_sso_settings', []) : [];
                self::site(is_array($local) ? $local : [], is_array($network) ? $network : []);
                if (!empty($network['delete_data_on_uninstall'])) {
                    $networks[$network_id] = true;
                }
                if (is_multisite()) {
                    restore_current_blog();
                }
            }
            $offset += 100;
        } while (is_multisite() && count($sites) === 100);
        foreach (array_keys($networks) as $network_id) {
            delete_network_option($network_id, 'portalweave_sso_settings');
        }
    }
}
