<?php
portalweave_sso_test('Privacy policy and controls disclose provider data without enabling telemetry or IP collection', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::get('audit_ip') === false, 'Client IP collection must require opt-in');
    portalweave_sso_assert(\PortalWeaveSSO\Config::get('delete_data_on_uninstall') === false, 'Private data cleanup must be explicit');
    portalweave_sso_assert(has_action('admin_init', [\PortalWeaveSSO\Privacy::class, 'policy']) !== false, 'Privacy policy is not registered on the admin lifecycle');
    portalweave_sso_assert(has_filter('wp_privacy_personal_data_exporters', [\PortalWeaveSSO\Privacy::class, 'exporters']) !== false, 'Profile privacy exporter is not registered');
    \PortalWeaveSSO\Audit::record('privacy_fixture');
    $rows = \PortalWeaveSSO\Audit::recent(['status' => 'privacy_fixture']);
    portalweave_sso_assert($rows && $rows[0]['client_ip'] === '', 'Default audit collected an IP');
    \PortalWeaveSSO\Config::save(['audit_ip' => true]);
    \PortalWeaveSSO\Audit::record('privacy_fixture_ip');
    $rows = \PortalWeaveSSO\Audit::recent(['status' => 'privacy_fixture_ip']);
    portalweave_sso_assert($rows && $rows[0]['client_ip'] === '127.0.0.1', 'Explicit IP opt-in did not work');
    \PortalWeaveSSO\Config::save(['audit_enabled' => false]);
    \PortalWeaveSSO\Audit::record('privacy_disabled_fixture');
    portalweave_sso_assert(\PortalWeaveSSO\Audit::recent(['status' => 'privacy_disabled_fixture']) === [], 'Disabled auditing persisted personal data');
});

portalweave_sso_test('Profile privacy export excludes credentials and erasure revokes links without unblocking accounts', function () {
    $unique = bin2hex(random_bytes(5));
    $app = portalweave_sso_app(['custom_mapping' => ['department' => 'department']]);
    $attributes = ['sub' => 'privacy-' . $unique, 'email' => 'privacy-' . $unique . '@example.org', 'email_verified' => true, 'preferred_username' => 'privacy-' . $unique, 'groups' => ['staff'], 'department' => 'Research'];
    $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
    portalweave_sso_assert($user instanceof WP_User, 'Privacy fixture account failed');
    try {
        update_user_meta($user->ID, \PortalWeaveSSO\Auth::token_meta_key('fixture'), \PortalWeaveSSO\Secrets::encrypt('must-not-export-token'));
        update_user_meta($user->ID, '_portalweave_sso_moderation', 'blocked');
        $session = WP_Session_Tokens::get_instance($user->ID)->create(time() + 3600);
        $export = \PortalWeaveSSO\Privacy::export($user->user_email);
        $json = wp_json_encode($export);
        portalweave_sso_assert(str_contains($json, 'Research') && str_contains($json, 'staff'), 'Mapped profile data missing from export');
        portalweave_sso_assert(!str_contains($json, 'must-not-export-token') && !str_contains($json, 'enc:v1:'), 'Privacy export included credentials');
        $erased = \PortalWeaveSSO\Privacy::erase($user->user_email);
        portalweave_sso_assert($erased['items_removed'] && $erased['items_retained'] && $erased['done'], 'Privacy erase result did not disclose retained security policy');
        portalweave_sso_assert(get_user_meta($user->ID, 'department', true) === '', 'Mapped custom field retained after erase');
        portalweave_sso_assert(get_user_meta($user->ID, \PortalWeaveSSO\Auth::token_meta_key('fixture'), true) === '', 'Encrypted tokens retained after erase');
        portalweave_sso_assert(!WP_Session_Tokens::get_instance($user->ID)->verify($session), 'Erasure did not revoke sessions');
        portalweave_sso_assert(get_userdata($user->ID)->roles === ['subscriber'] && get_user_meta($user->ID, '_portalweave_sso_moderation', true) === 'blocked', 'Erasure deleted the account or unblocked it');
        $identity_keys = (array) get_user_meta($user->ID, \PortalWeaveSSO\Accounts::identity_keys_meta_key(), true);
        portalweave_sso_assert(array_filter($identity_keys) === [], 'Provider identity index retained');
    } finally { wp_delete_user($user->ID); }
});

portalweave_sso_test('Privacy email matching treats wildcard characters literally', function () {
    $unique = bin2hex(random_bytes(4));
    $ids = [];
    try {
        foreach (['literal*' . $unique, 'literal-other-' . $unique] as $index => $local) {
            $id = wp_insert_user(['user_login' => 'privacy_match_' . $index . $unique, 'user_pass' => wp_generate_password(32), 'user_email' => $local . '@example.org', 'role' => 'subscriber']);
            portalweave_sso_assert(!is_wp_error($id), 'Email fixture failed');
            $ids[] = $id;
            update_user_meta($id, \PortalWeaveSSO\Accounts::groups_meta_key(), ['private-' . $index]);
        }
        $data = \PortalWeaveSSO\Privacy::export('literal*' . $unique . '@example.org');
        portalweave_sso_assert(count($data['data']) === 1 && !str_contains(wp_json_encode($data), 'private-1'), 'Privacy export exposed another email account');
        \PortalWeaveSSO\Privacy::erase('literal*' . $unique . '@example.org');
        portalweave_sso_assert(get_user_meta($ids[1], \PortalWeaveSSO\Accounts::groups_meta_key(), true) === ['private-1'], 'Privacy erasure changed a nonmatching account');
    } finally { foreach ($ids as $id) { wp_delete_user($id); } }
});

portalweave_sso_test('Uninstall cleanup requires consent and preserves accounts and private-media recovery', function () {
    $id = wp_insert_user(['user_login' => 'cleanup_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    $attachment = wp_insert_post(['post_type' => 'attachment', 'post_title' => 'Private cleanup fixture', 'post_status' => 'inherit']);
    $file = wp_tempnam('portalweave-private-fixture');
    portalweave_sso_assert($id && !is_wp_error($id) && $attachment > 0 && $file, 'Cleanup fixtures failed');
    try {
        update_post_meta($attachment, '_portalweave_sso_private_files', ['fixture.txt' => $file]);
        update_post_meta($attachment, '_portalweave_sso_protected', 1);
        update_user_meta($id, \PortalWeaveSSO\Auth::token_meta_key('deleted-app'), 'encrypted-fixture');
        add_option('portalweave_sso_state_cleanup_fixture', ['nonce' => 'fixture'], '', false);
        update_option('portalweave_sso_settings', ['delete_data_on_uninstall' => false], false);
        \PortalWeaveSSO\Cleanup::site(get_option('portalweave_sso_settings'));
        portalweave_sso_assert(get_option('portalweave_sso_settings') !== false && get_user_meta($id, \PortalWeaveSSO\Auth::token_meta_key('deleted-app'), true), 'Cleanup ignored the retention setting');
        \PortalWeaveSSO\Cleanup::site(['delete_data_on_uninstall' => true]);
        portalweave_sso_assert(get_option('portalweave_sso_settings') === false && get_option('portalweave_sso_state_cleanup_fixture') === false, 'Consented options were retained');
        portalweave_sso_assert(get_user_meta($id, \PortalWeaveSSO\Auth::token_meta_key('deleted-app'), true) === '', 'Uninstall retained a deleted provider token');
        portalweave_sso_assert(get_userdata($id) instanceof WP_User && is_file($file) && get_post_meta($attachment, '_portalweave_sso_private_files', true), 'Uninstall destroyed an account or private recovery data');
    } finally {
        \PortalWeaveSSO\Audit::install();
        \PortalWeaveSSO\Auth::register();
        \PortalWeaveSSO\Extensions::register();
        wp_delete_user($id);
        wp_delete_attachment($attachment, true);
        wp_delete_file($file);
    }
});
