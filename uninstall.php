<?php
/** Preserve user identities, policy and private files for deliberate server-side recovery. */
defined('WP_UNINSTALL_PLUGIN') || exit;
$wpsso_sites = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
foreach ($wpsso_sites as $wpsso_site) {
    if (is_multisite()) {
        switch_to_blog($wpsso_site);
    }
    foreach (['wpsso_cleanup', 'wpsso_state_cleanup', 'wpsso_extension_cleanup', 'wpsso_expire_claim'] as $wpsso_hook) {
        wp_unschedule_hook($wpsso_hook);
    }
    if (is_multisite()) {
        restore_current_blog();
    }
}
// No recursive file deletion: vault files may be the only surviving media copy.
// See README.md for complete database/files backup and intentional public restoration.
