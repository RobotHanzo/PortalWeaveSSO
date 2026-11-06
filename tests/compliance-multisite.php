<?php
/** Privacy, audit and consent boundaries on the disposable real multisite network. */
function wpsso_compliance_multisite_at(int $blog, callable $callback) {
    switch_to_blog($blog);
    try { return $callback(); }
    finally { restore_current_blog(); }
}

function wpsso_compliance_multisite_user(): int {
    global $wpsso_blog_a, $wpsso_blog_b;
    $unique = bin2hex(random_bytes(6));
    $id = wp_insert_user(['user_login' => 'privacy_network_' . $unique, 'user_pass' => wp_generate_password(40), 'user_email' => 'privacy_network_' . $unique . '@example.org', 'role' => 'subscriber']);
    wpsso_assert(is_int($id) && $id > 0, 'Unable to create shared privacy fixture');
    add_user_to_blog($wpsso_blog_a, $id, 'subscriber');
    add_user_to_blog($wpsso_blog_b, $id, 'subscriber');
    return $id;
}

wpsso_test('multisite: privacy export and erase isolate provider data while mapped public fields remain global', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $id = wpsso_compliance_multisite_user();
    $email = get_userdata($id)->user_email;
    $keys = [];
    $values = [];
    try {
        foreach ([$wpsso_blog_a => 'site-a', $wpsso_blog_b => 'site-b'] as $blog => $label) {
            wpsso_compliance_multisite_at($blog, static function () use ($id, $blog, $label, &$keys, &$values) {
                $hash = hash('sha256', $blog . '|deleted-provider|https://example.org|privacy-client');
                $keys[$blog] = [
                    'token' => \WPSSO\Auth::token_meta_key('deleted-provider'),
                    'group' => \WPSSO\Accounts::groups_meta_key(),
                    'avatar' => '_wpsso_avatar_' . $blog,
                    'scim' => '_wpsso_scim_external_' . $blog,
                    'scim-groups' => '_wpsso_scim_groups_' . $blog,
                    'scim-group-names' => '_wpsso_scim_group_names_' . $blog,
                    'scim-modified' => '_wpsso_scim_modified_' . $blog,
                    'identity' => '_wpsso_identity_' . $hash,
                    'sid' => '_wpsso_sid_' . $hash,
                    'mapped-index' => \WPSSO\Accounts::mapped_keys_meta_key(),
                    'identity-index' => \WPSSO\Accounts::identity_keys_meta_key(),
                ];
                $values[$blog] = [
                    'token' => \WPSSO\Secrets::encrypt('credential-' . $label),
                    'group' => ['group-' . $label],
                    'avatar' => 'https://example.org/avatar-' . $label . '.png',
                    'scim' => 'external-' . $label,
                    'scim-groups' => ['scim-group-' . $label],
                    'scim-group-names' => ['scim-group-name-' . $label],
                    'scim-modified' => 'modified-' . $label,
                    'identity' => hash('sha256', 'subject-' . $label),
                    'sid' => hash('sha256', 'session-' . $label),
                    'mapped-index' => ['department'],
                    'identity-index' => [$keys[$blog]['identity'], $keys[$blog]['sid']],
                ];
                foreach ($keys[$blog] as $type => $key) { update_user_meta($id, $key, $values[$blog][$type]); }
                update_user_meta($id, '_wpsso_scim_active_' . $blog, '0');
            });
        }
        update_user_meta($id, 'department', 'Global mapped profile field');
        $session = WP_Session_Tokens::get_instance($id)->create(time() + 3600);
        wpsso_compliance_multisite_at($wpsso_blog_a, static function () use ($id, $email, $keys, $values, $wpsso_blog_a, $wpsso_blog_b, $session) {
            $export = \WPSSO\Privacy::export($email);
            $json = wp_json_encode($export);
            wpsso_assert(str_contains($json, 'group-site-a') && str_contains($json, 'external-site-a') && str_contains($json, 'avatar-site-a'), 'Current-site profile was missing');
            wpsso_assert(str_contains($json, 'Global mapped profile field'), 'Mapped WordPress-global profile field was missing');
            foreach (['group-site-b', 'external-site-b', 'avatar-site-b', 'modified-site-b', $keys[$wpsso_blog_b]['identity'], $keys[$wpsso_blog_b]['sid'], $values[$wpsso_blog_b]['identity'], $values[$wpsso_blog_b]['sid']] as $private) {
                wpsso_assert(!str_contains($json, $private), 'Privacy export exposed another blog provider data');
            }
            wpsso_assert(!str_contains($json, 'credential-') && !str_contains($json, 'enc:v1:') && !str_contains($json, '_wpsso_tokens_'), 'Privacy export exposed credentials');
            $erased = \WPSSO\Privacy::erase($email);
            wpsso_assert($erased['items_removed'] && $erased['items_retained'], 'Erasure did not report retained provisioning block');
            foreach ($keys[$wpsso_blog_a] as $key) { wpsso_assert(get_user_meta($id, $key, true) === '', 'Current-site provider data survived erasure'); }
            foreach ($keys[$wpsso_blog_b] as $type => $key) { wpsso_assert(get_user_meta($id, $key, true) === $values[$wpsso_blog_b][$type], 'Erasure changed another site provider data'); }
            wpsso_assert(get_user_meta($id, '_wpsso_scim_active_' . $wpsso_blog_a, true) === '0' && get_user_meta($id, '_wpsso_scim_active_' . $wpsso_blog_b, true) === '0', 'Erasure enabled a blocked account');
            wpsso_assert(get_user_meta($id, 'department', true) === '', 'Mapped global profile field was not erased');
            wpsso_assert(!WP_Session_Tokens::get_instance($id)->verify($session), 'Privacy erasure did not revoke the shared WordPress session');
        });
    } finally { wpmu_delete_user($id); }
});

wpsso_test('multisite: local uninstall consent preserves other-site metadata, settings and private vault files', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $id = wpsso_compliance_multisite_user();
    $keys = [];
    $settings = [];
    $attachments = [];
    $files = [];
    $states = [];
    $network_settings = get_site_option('wpsso_settings', []);
    try {
        foreach ([$wpsso_blog_a => true, $wpsso_blog_b => false] as $blog => $consent) {
            wpsso_compliance_multisite_at($blog, static function () use ($id, $blog, $consent, &$keys, &$settings, &$attachments, &$files, &$states) {
                $settings[$blog] = array_replace(\WPSSO\Config::defaults(), ['delete_data_on_uninstall' => $consent]);
                update_option('wpsso_settings', $settings[$blog], false);
                \WPSSO\Audit::install();
                $hash = hash('sha256', $blog . '|removed-provider|https://example.org|cleanup-client');
                $keys[$blog] = [\WPSSO\Auth::token_meta_key('removed-provider'), \WPSSO\Accounts::groups_meta_key(), '_wpsso_avatar_' . $blog, '_wpsso_scim_external_' . $blog, '_wpsso_identity_' . $hash, '_wpsso_sid_' . $hash];
                foreach (['managed', 'active', 'groups', 'group_names', 'modified'] as $field) { $keys[$blog][] = '_wpsso_scim_' . $field . '_' . $blog; }
                foreach ($keys[$blog] as $key) { update_user_meta($id, $key, 'retained-value-' . $blog); }
                update_user_meta($id, \WPSSO\Accounts::identity_keys_meta_key(), ['_wpsso_identity_' . $hash, '_wpsso_sid_' . $hash]);
                update_user_meta($id, \WPSSO\Accounts::mapped_keys_meta_key(), ['department']);
                $files[$blog] = wp_tempnam('stsa-multisite-vault-' . $blog);
                $attachments[$blog] = wp_insert_post(['post_type' => 'attachment', 'post_title' => 'Private multisite cleanup fixture', 'post_status' => 'inherit']);
                wpsso_assert(is_string($files[$blog]) && $attachments[$blog] > 0, 'Private vault fixture failed');
                update_post_meta($attachments[$blog], '_wpsso_private_files', ['fixture.txt' => $files[$blog]]);
                update_post_meta($attachments[$blog], '_wpsso_protected', 1);
                $states[$blog] = ['expires' => time() + 600, 'marker' => 'state-' . $blog];
                add_option('wpsso_state_site_cleanup_fixture', $states[$blog], '', false);
            });
        }
        update_user_meta($id, 'department', 'Global field survives uninstall');
        wpsso_compliance_multisite_at($wpsso_blog_a, static function () use ($settings, $wpsso_blog_a) { \WPSSO\Cleanup::site($settings[$wpsso_blog_a]); });
        foreach ([$wpsso_blog_a, $wpsso_blog_b] as $blog) {
            wpsso_compliance_multisite_at($blog, static function () use ($id, $blog, $keys, $settings, $attachments, $files, $states, $wpsso_blog_a) {
                foreach ($keys[$blog] as $key) {
                    wpsso_assert(get_user_meta($id, $key, true) === ($blog === $wpsso_blog_a ? '' : 'retained-value-' . $blog), 'Site cleanup crossed the provider metadata boundary');
                }
                wpsso_assert(get_option('wpsso_settings') === ($blog === $wpsso_blog_a ? false : $settings[$blog]), 'Site cleanup crossed the settings boundary');
                wpsso_assert(get_option('wpsso_state_site_cleanup_fixture') === ($blog === $wpsso_blog_a ? false : $states[$blog]), 'Site cleanup crossed the state boundary');
                wpsso_assert(is_file($files[$blog]) && get_post_meta($attachments[$blog], '_wpsso_private_files', true) === ['fixture.txt' => $files[$blog]] && get_post_meta($attachments[$blog], '_wpsso_protected', true) === '1', 'Cleanup destroyed private content recovery data');
            });
        }
        wpsso_assert(get_site_option('wpsso_settings', []) === $network_settings, 'Site consent changed network settings');
        wpsso_assert(get_userdata($id) instanceof WP_User && get_user_meta($id, 'department', true) === 'Global field survives uninstall', 'Uninstall destroyed a WordPress account or public field');
    } finally {
        foreach ([$wpsso_blog_a, $wpsso_blog_b] as $blog) {
            wpsso_compliance_multisite_at($blog, static function () use ($blog, $attachments, $files) {
                \WPSSO\Audit::install();
                \WPSSO\Auth::register();
                \WPSSO\Extensions::register();
                delete_option('wpsso_state_site_cleanup_fixture');
                if (!empty($attachments[$blog])) { wp_delete_post($attachments[$blog], true); }
                if (!empty($files[$blog])) { wp_delete_file($files[$blog]); }
            });
        }
        wpmu_delete_user($id);
    }
});

wpsso_test('multisite: audit reads and privacy anonymization remain local to each blog', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $id = wpsso_compliance_multisite_user();
    $email = get_userdata($id)->user_email;
    $status = 'privacy_scope_' . bin2hex(random_bytes(4));
    try {
        foreach ([$wpsso_blog_a, $wpsso_blog_b] as $blog) {
            wpsso_compliance_multisite_at($blog, static function () use ($blog, $id, $status) {
                \WPSSO\Audit::install();
                \WPSSO\Audit::record($status, 'site-' . $blog, get_userdata($id));
                $rows = \WPSSO\Audit::recent(['status' => $status]);
                wpsso_assert(count($rows) === 1 && $rows[0]['app'] === 'site-' . $blog, 'Audit read leaked another blog event');
            });
        }
        wpsso_compliance_multisite_at($wpsso_blog_a, static function () use ($email, $status) {
            wpsso_assert(\WPSSO\Audit::anonymize($email) === 1, 'Current-site audit was not anonymized');
            $rows = \WPSSO\Audit::recent(['status' => $status]);
            wpsso_assert(count($rows) === 1 && $rows[0]['email'] === '' && $rows[0]['username'] === '' && (int) $rows[0]['user_id'] === 0, 'Audit report returned data predating anonymization');
        });
        wpsso_compliance_multisite_at($wpsso_blog_b, static function () use ($id, $email, $status) {
            $rows = \WPSSO\Audit::recent(['status' => $status]);
            wpsso_assert(count($rows) === 1 && $rows[0]['email'] === $email && (int) $rows[0]['user_id'] === $id, 'Another-site audit was anonymized');
            wpsso_assert(count(\WPSSO\Audit::for_email($email)) === 1, 'Another-site privacy audit export was changed');
        });
    } finally {
        foreach ([$wpsso_blog_a, $wpsso_blog_b] as $blog) {
            wpsso_compliance_multisite_at($blog, static function () use ($email) { \WPSSO\Audit::anonymize($email); });
        }
        wpmu_delete_user($id);
    }
});
