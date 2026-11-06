<?php
/** Privacy, audit and consent boundaries on the disposable real multisite network. */
function portalweave_sso_compliance_multisite_at(int $blog, callable $callback) {
    switch_to_blog($blog);
    try { return $callback(); }
    finally { restore_current_blog(); }
}

function portalweave_sso_compliance_multisite_user(): int {
    global $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $unique = bin2hex(random_bytes(6));
    $id = wp_insert_user(['user_login' => 'privacy_network_' . $unique, 'user_pass' => wp_generate_password(40), 'user_email' => 'privacy_network_' . $unique . '@example.org', 'role' => 'subscriber']);
    portalweave_sso_assert(is_int($id) && $id > 0, 'Unable to create shared privacy fixture');
    add_user_to_blog($portalweave_sso_blog_a, $id, 'subscriber');
    add_user_to_blog($portalweave_sso_blog_b, $id, 'subscriber');
    return $id;
}

portalweave_sso_test('multisite: privacy export and erase isolate provider data while mapped public fields remain global', function () {
    global $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $id = portalweave_sso_compliance_multisite_user();
    $email = get_userdata($id)->user_email;
    $keys = [];
    $values = [];
    try {
        foreach ([$portalweave_sso_blog_a => 'site-a', $portalweave_sso_blog_b => 'site-b'] as $blog => $label) {
            portalweave_sso_compliance_multisite_at($blog, static function () use ($id, $blog, $label, &$keys, &$values) {
                $hash = hash('sha256', $blog . '|deleted-provider|https://example.org|privacy-client');
                $keys[$blog] = [
                    'token' => \PortalWeaveSSO\Auth::token_meta_key('deleted-provider'),
                    'group' => \PortalWeaveSSO\Accounts::groups_meta_key(),
                    'avatar' => '_portalweave_sso_avatar_' . $blog,
                    'scim' => '_portalweave_sso_scim_external_' . $blog,
                    'scim-groups' => '_portalweave_sso_scim_groups_' . $blog,
                    'scim-group-names' => '_portalweave_sso_scim_group_names_' . $blog,
                    'scim-modified' => '_portalweave_sso_scim_modified_' . $blog,
                    'identity' => '_portalweave_sso_identity_' . $hash,
                    'sid' => '_portalweave_sso_sid_' . $hash,
                    'mapped-index' => \PortalWeaveSSO\Accounts::mapped_keys_meta_key(),
                    'identity-index' => \PortalWeaveSSO\Accounts::identity_keys_meta_key(),
                ];
                $values[$blog] = [
                    'token' => \PortalWeaveSSO\Secrets::encrypt('credential-' . $label),
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
                update_user_meta($id, '_portalweave_sso_scim_active_' . $blog, '0');
            });
        }
        update_user_meta($id, 'department', 'Global mapped profile field');
        $session = WP_Session_Tokens::get_instance($id)->create(time() + 3600);
        portalweave_sso_compliance_multisite_at($portalweave_sso_blog_a, static function () use ($id, $email, $keys, $values, $portalweave_sso_blog_a, $portalweave_sso_blog_b, $session) {
            $export = \PortalWeaveSSO\Privacy::export($email);
            $json = wp_json_encode($export);
            portalweave_sso_assert(str_contains($json, 'group-site-a') && str_contains($json, 'external-site-a') && str_contains($json, 'avatar-site-a'), 'Current-site profile was missing');
            portalweave_sso_assert(str_contains($json, 'Global mapped profile field'), 'Mapped WordPress-global profile field was missing');
            foreach (['group-site-b', 'external-site-b', 'avatar-site-b', 'modified-site-b', $keys[$portalweave_sso_blog_b]['identity'], $keys[$portalweave_sso_blog_b]['sid'], $values[$portalweave_sso_blog_b]['identity'], $values[$portalweave_sso_blog_b]['sid']] as $private) {
                portalweave_sso_assert(!str_contains($json, $private), 'Privacy export exposed another blog provider data');
            }
            portalweave_sso_assert(!str_contains($json, 'credential-') && !str_contains($json, 'enc:v1:') && !str_contains($json, '_portalweave_sso_tokens_'), 'Privacy export exposed credentials');
            $erased = \PortalWeaveSSO\Privacy::erase($email);
            portalweave_sso_assert($erased['items_removed'] && $erased['items_retained'], 'Erasure did not report retained provisioning block');
            foreach ($keys[$portalweave_sso_blog_a] as $key) { portalweave_sso_assert(get_user_meta($id, $key, true) === '', 'Current-site provider data survived erasure'); }
            foreach ($keys[$portalweave_sso_blog_b] as $type => $key) { portalweave_sso_assert(get_user_meta($id, $key, true) === $values[$portalweave_sso_blog_b][$type], 'Erasure changed another site provider data'); }
            portalweave_sso_assert(get_user_meta($id, '_portalweave_sso_scim_active_' . $portalweave_sso_blog_a, true) === '0' && get_user_meta($id, '_portalweave_sso_scim_active_' . $portalweave_sso_blog_b, true) === '0', 'Erasure enabled a blocked account');
            portalweave_sso_assert(get_user_meta($id, 'department', true) === '', 'Mapped global profile field was not erased');
            portalweave_sso_assert(!WP_Session_Tokens::get_instance($id)->verify($session), 'Privacy erasure did not revoke the shared WordPress session');
        });
    } finally { wpmu_delete_user($id); }
});

portalweave_sso_test('multisite: local uninstall consent preserves other-site metadata, settings and private vault files', function () {
    global $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $id = portalweave_sso_compliance_multisite_user();
    $keys = [];
    $settings = [];
    $attachments = [];
    $files = [];
    $states = [];
    $network_settings = get_site_option('portalweave_sso_settings', []);
    try {
        foreach ([$portalweave_sso_blog_a => true, $portalweave_sso_blog_b => false] as $blog => $consent) {
            portalweave_sso_compliance_multisite_at($blog, static function () use ($id, $blog, $consent, &$keys, &$settings, &$attachments, &$files, &$states) {
                $settings[$blog] = array_replace(\PortalWeaveSSO\Config::defaults(), ['delete_data_on_uninstall' => $consent]);
                update_option('portalweave_sso_settings', $settings[$blog], false);
                \PortalWeaveSSO\Audit::install();
                $hash = hash('sha256', $blog . '|removed-provider|https://example.org|cleanup-client');
                $keys[$blog] = [\PortalWeaveSSO\Auth::token_meta_key('removed-provider'), \PortalWeaveSSO\Accounts::groups_meta_key(), '_portalweave_sso_avatar_' . $blog, '_portalweave_sso_scim_external_' . $blog, '_portalweave_sso_identity_' . $hash, '_portalweave_sso_sid_' . $hash];
                foreach (['managed', 'active', 'groups', 'group_names', 'modified'] as $field) { $keys[$blog][] = '_portalweave_sso_scim_' . $field . '_' . $blog; }
                foreach ($keys[$blog] as $key) { update_user_meta($id, $key, 'retained-value-' . $blog); }
                update_user_meta($id, \PortalWeaveSSO\Accounts::identity_keys_meta_key(), ['_portalweave_sso_identity_' . $hash, '_portalweave_sso_sid_' . $hash]);
                update_user_meta($id, \PortalWeaveSSO\Accounts::mapped_keys_meta_key(), ['department']);
                $files[$blog] = wp_tempnam('stsa-multisite-vault-' . $blog);
                $attachments[$blog] = wp_insert_post(['post_type' => 'attachment', 'post_title' => 'Private multisite cleanup fixture', 'post_status' => 'inherit']);
                portalweave_sso_assert(is_string($files[$blog]) && $attachments[$blog] > 0, 'Private vault fixture failed');
                update_post_meta($attachments[$blog], '_portalweave_sso_private_files', ['fixture.txt' => $files[$blog]]);
                update_post_meta($attachments[$blog], '_portalweave_sso_protected', 1);
                $states[$blog] = ['expires' => time() + 600, 'marker' => 'state-' . $blog];
                add_option('portalweave_sso_state_site_cleanup_fixture', $states[$blog], '', false);
            });
        }
        update_user_meta($id, 'department', 'Global field survives uninstall');
        portalweave_sso_compliance_multisite_at($portalweave_sso_blog_a, static function () use ($settings, $portalweave_sso_blog_a) { \PortalWeaveSSO\Cleanup::site($settings[$portalweave_sso_blog_a]); });
        foreach ([$portalweave_sso_blog_a, $portalweave_sso_blog_b] as $blog) {
            portalweave_sso_compliance_multisite_at($blog, static function () use ($id, $blog, $keys, $settings, $attachments, $files, $states, $portalweave_sso_blog_a) {
                foreach ($keys[$blog] as $key) {
                    portalweave_sso_assert(get_user_meta($id, $key, true) === ($blog === $portalweave_sso_blog_a ? '' : 'retained-value-' . $blog), 'Site cleanup crossed the provider metadata boundary');
                }
                portalweave_sso_assert(get_option('portalweave_sso_settings') === ($blog === $portalweave_sso_blog_a ? false : $settings[$blog]), 'Site cleanup crossed the settings boundary');
                portalweave_sso_assert(get_option('portalweave_sso_state_site_cleanup_fixture') === ($blog === $portalweave_sso_blog_a ? false : $states[$blog]), 'Site cleanup crossed the state boundary');
                portalweave_sso_assert(is_file($files[$blog]) && get_post_meta($attachments[$blog], '_portalweave_sso_private_files', true) === ['fixture.txt' => $files[$blog]] && get_post_meta($attachments[$blog], '_portalweave_sso_protected', true) === '1', 'Cleanup destroyed private content recovery data');
            });
        }
        portalweave_sso_assert(get_site_option('portalweave_sso_settings', []) === $network_settings, 'Site consent changed network settings');
        portalweave_sso_assert(get_userdata($id) instanceof WP_User && get_user_meta($id, 'department', true) === 'Global field survives uninstall', 'Uninstall destroyed a WordPress account or public field');
    } finally {
        foreach ([$portalweave_sso_blog_a, $portalweave_sso_blog_b] as $blog) {
            portalweave_sso_compliance_multisite_at($blog, static function () use ($blog, $attachments, $files) {
                \PortalWeaveSSO\Audit::install();
                \PortalWeaveSSO\Auth::register();
                \PortalWeaveSSO\Extensions::register();
                delete_option('portalweave_sso_state_site_cleanup_fixture');
                if (!empty($attachments[$blog])) { wp_delete_post($attachments[$blog], true); }
                if (!empty($files[$blog])) { wp_delete_file($files[$blog]); }
            });
        }
        wpmu_delete_user($id);
    }
});

portalweave_sso_test('multisite: audit reads and privacy anonymization remain local to each blog', function () {
    global $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $id = portalweave_sso_compliance_multisite_user();
    $email = get_userdata($id)->user_email;
    $status = 'privacy_scope_' . bin2hex(random_bytes(4));
    try {
        foreach ([$portalweave_sso_blog_a, $portalweave_sso_blog_b] as $blog) {
            portalweave_sso_compliance_multisite_at($blog, static function () use ($blog, $id, $status) {
                \PortalWeaveSSO\Audit::install();
                \PortalWeaveSSO\Audit::record($status, 'site-' . $blog, get_userdata($id));
                $rows = \PortalWeaveSSO\Audit::recent(['status' => $status]);
                portalweave_sso_assert(count($rows) === 1 && $rows[0]['app'] === 'site-' . $blog, 'Audit read leaked another blog event');
            });
        }
        portalweave_sso_compliance_multisite_at($portalweave_sso_blog_a, static function () use ($email, $status) {
            portalweave_sso_assert(\PortalWeaveSSO\Audit::anonymize($email) === 1, 'Current-site audit was not anonymized');
            $rows = \PortalWeaveSSO\Audit::recent(['status' => $status]);
            portalweave_sso_assert(count($rows) === 1 && $rows[0]['email'] === '' && $rows[0]['username'] === '' && (int) $rows[0]['user_id'] === 0, 'Audit report returned data predating anonymization');
        });
        portalweave_sso_compliance_multisite_at($portalweave_sso_blog_b, static function () use ($id, $email, $status) {
            $rows = \PortalWeaveSSO\Audit::recent(['status' => $status]);
            portalweave_sso_assert(count($rows) === 1 && $rows[0]['email'] === $email && (int) $rows[0]['user_id'] === $id, 'Another-site audit was anonymized');
            portalweave_sso_assert(count(\PortalWeaveSSO\Audit::for_email($email)) === 1, 'Another-site privacy audit export was changed');
        });
    } finally {
        foreach ([$portalweave_sso_blog_a, $portalweave_sso_blog_b] as $blog) {
            portalweave_sso_compliance_multisite_at($blog, static function () use ($email) { \PortalWeaveSSO\Audit::anonymize($email); });
        }
        wpmu_delete_user($id);
    }
});

portalweave_sso_test('multisite: Activity summaries keep identical provider IDs and private audit data scoped to each blog', function () {
    global $wpdb, $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $now = gmmktime(12, 0, 0, 7, 19, 2004);
    $app = 'shared-activity-provider';
    $email = 'private-summary-' . bin2hex(random_bytes(5)) . '@example.org';
    $inserted = [];
    $queries = [];
    $capture = static function ($query) use (&$queries) {
        if (preg_match('/^\s*SELECT\b/i', $query) && str_contains($query, 'portalweave_sso_audit')) { $queries[] = $query; }
        return $query;
    };
    try {
        foreach ([
            $portalweave_sso_blog_a => [['success', '2004-07-19 08:00:00']],
            $portalweave_sso_blog_b => [['failure', '2004-07-17 09:00:00'], ['local_logout', '2004-07-18 10:00:00'], ['test_success', '2004-07-19 11:00:00']],
        ] as $blog => $events) {
            portalweave_sso_compliance_multisite_at($blog, static function () use ($app, $email, $events, &$inserted) {
                global $wpdb;
                \PortalWeaveSSO\Audit::install();
                $table = $wpdb->prefix . 'portalweave_sso_audit';
                foreach ($events as [$status, $created]) {
                    $result = $wpdb->insert($table, [
                        'user_id' => 424242, 'username' => 'private-summary-user', 'email' => $email,
                        'status' => $status, 'app' => $app, 'error_code' => 'private-summary-error',
                        'client_ip' => '198.51.100.123', 'navigation' => 'https://private.example.org/summary-fixture', 'created_at' => $created,
                    ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
                    portalweave_sso_assert($result === 1, 'Unable to insert scoped Activity fixture');
                    $inserted[] = ['table' => $table, 'id' => (int) $wpdb->insert_id];
                }
            });
        }
        add_filter('query', $capture);
        try {
            [$first, $second] = portalweave_sso_compliance_multisite_at($portalweave_sso_blog_a, static function () use ($now, $portalweave_sso_blog_a, $portalweave_sso_blog_b) {
                $first = \PortalWeaveSSO\Audit::summary(7, $now);
                $second = portalweave_sso_compliance_multisite_at($portalweave_sso_blog_b, static fn() => \PortalWeaveSSO\Audit::summary(7, $now));
                portalweave_sso_assert(get_current_blog_id() === $portalweave_sso_blog_a && \PortalWeaveSSO\Audit::summary(7, $now) === $first, 'Restoring the blog reused another site\'s Activity summary');
                return [$first, $second];
            });
        } finally { remove_filter('query', $capture); }
        foreach ([
            [$first, 1, ['sign_ins' => 1, 'failures' => 0, 'sign_outs' => 0, 'tests' => 0, 'other' => 0]],
            [$second, 3, ['sign_ins' => 0, 'failures' => 1, 'sign_outs' => 1, 'tests' => 1, 'other' => 0]],
        ] as [$summary, $total, $types]) {
            portalweave_sso_assert(is_array($summary) && $summary['total'] === $total && $summary['types'] === $types, 'Activity totals or event types crossed a blog boundary');
            portalweave_sso_assert($summary['days'] === 7 && $summary['start'] === '2004-07-13' && $summary['end'] === '2004-07-19'
                && count($summary['daily']) === 7 && array_sum(array_column($summary['daily'], 'count')) === $total, 'Scoped Activity daily counts lost the fixed UTC window');
            portalweave_sso_assert($summary['providers'] === [['app' => $app, 'count' => $total]] && $summary['other_providers'] === 0, 'Identical app IDs combined events from different blogs');
            foreach ($summary['daily'] as $day) {
                portalweave_sso_assert(array_keys($day) === ['date', 'count'] && is_string($day['date']) && is_int($day['count']), 'Daily Activity data contains unexpected fields or untyped counts');
            }
            $json = wp_json_encode($summary);
            foreach ([$email, 'private-summary-user', 'private-summary-error', '198.51.100.123', 'private.example.org', '424242'] as $private) {
                portalweave_sso_assert(!str_contains($json, $private), 'Activity summary exposed a personal audit value');
            }
        }
        portalweave_sso_assert(count($queries) === 3, 'Scoped Activity summary reads were not exercised across the restored blog');
        foreach ($queries as $query) {
            portalweave_sso_assert(!preg_match('/\b(?:user_id|username|email|client_ip|navigation|error_code)\b|SELECT\s+\*/i', $query), 'Scoped Activity aggregate fetched personal audit columns');
        }
    } finally {
        foreach ($inserted as $row) { $wpdb->delete($row['table'], ['id' => $row['id']], ['%d']); }
    }
});
