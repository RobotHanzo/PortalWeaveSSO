<?php
wpsso_test('Privacy policy and controls disclose provider data without enabling telemetry or IP collection', function () {
    wpsso_assert(\WPSSO\Config::get('audit_ip') === false, 'Client IP collection must require opt-in');
    wpsso_assert(\WPSSO\Config::get('delete_data_on_uninstall') === false, 'Private data cleanup must be explicit');
    wpsso_assert(has_action('admin_init', [\WPSSO\Privacy::class, 'policy']) !== false, 'Privacy policy is not registered on the admin lifecycle');
    wpsso_assert(has_filter('wp_privacy_personal_data_exporters', [\WPSSO\Privacy::class, 'exporters']) !== false, 'Profile privacy exporter is not registered');
    \WPSSO\Audit::record('privacy_fixture');
    $rows = \WPSSO\Audit::recent(['status' => 'privacy_fixture']);
    wpsso_assert($rows && $rows[0]['client_ip'] === '', 'Default audit collected an IP');
    \WPSSO\Config::save(['audit_ip' => true]);
    \WPSSO\Audit::record('privacy_fixture_ip');
    $rows = \WPSSO\Audit::recent(['status' => 'privacy_fixture_ip']);
    wpsso_assert($rows && $rows[0]['client_ip'] === '127.0.0.1', 'Explicit IP opt-in did not work');
    \WPSSO\Config::save(['audit_enabled' => false]);
    \WPSSO\Audit::record('privacy_disabled_fixture');
    wpsso_assert(\WPSSO\Audit::recent(['status' => 'privacy_disabled_fixture']) === [], 'Disabled auditing persisted personal data');
});

wpsso_test('Profile privacy export excludes credentials and erasure revokes links without unblocking accounts', function () {
    $unique = bin2hex(random_bytes(5));
    $app = wpsso_app(['custom_mapping' => ['department' => 'department']]);
    $attributes = ['sub' => 'privacy-' . $unique, 'email' => 'privacy-' . $unique . '@example.org', 'email_verified' => true, 'preferred_username' => 'privacy-' . $unique, 'groups' => ['staff'], 'department' => 'Research'];
    $user = \WPSSO\Accounts::resolve($app, $attributes);
    wpsso_assert($user instanceof WP_User, 'Privacy fixture account failed');
    try {
        update_user_meta($user->ID, \WPSSO\Auth::token_meta_key('fixture'), \WPSSO\Secrets::encrypt('must-not-export-token'));
        update_user_meta($user->ID, '_wpsso_moderation', 'blocked');
        $session = WP_Session_Tokens::get_instance($user->ID)->create(time() + 3600);
        $export = \WPSSO\Privacy::export($user->user_email);
        $json = wp_json_encode($export);
        wpsso_assert(str_contains($json, 'Research') && str_contains($json, 'staff'), 'Mapped profile data missing from export');
        wpsso_assert(!str_contains($json, 'must-not-export-token') && !str_contains($json, 'enc:v1:'), 'Privacy export included credentials');
        $erased = \WPSSO\Privacy::erase($user->user_email);
        wpsso_assert($erased['items_removed'] && $erased['items_retained'] && $erased['done'], 'Privacy erase result did not disclose retained security policy');
        wpsso_assert(get_user_meta($user->ID, 'department', true) === '', 'Mapped custom field retained after erase');
        wpsso_assert(get_user_meta($user->ID, \WPSSO\Auth::token_meta_key('fixture'), true) === '', 'Encrypted tokens retained after erase');
        wpsso_assert(!WP_Session_Tokens::get_instance($user->ID)->verify($session), 'Erasure did not revoke sessions');
        wpsso_assert(get_userdata($user->ID)->roles === ['subscriber'] && get_user_meta($user->ID, '_wpsso_moderation', true) === 'blocked', 'Erasure deleted the account or unblocked it');
        $identity_keys = (array) get_user_meta($user->ID, \WPSSO\Accounts::identity_keys_meta_key(), true);
        wpsso_assert(array_filter($identity_keys) === [], 'Provider identity index retained');
    } finally { wp_delete_user($user->ID); }
});
