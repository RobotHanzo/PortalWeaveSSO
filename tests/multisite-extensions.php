<?php
/** Real-network add-on isolation; run only from the guarded multisite runner. */

portalweave_sso_test('multisite: SCIM credentials and resource ownership are isolated between blogs', function () {
    global $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $original_blog = get_current_blog_id();
    $original_depth = count($GLOBALS['_wp_switched_stack'] ?? []);
    $settings = [];
    $id = 0;
    $https = $_SERVER['HTTPS'] ?? null;
    $secret_a = bin2hex(random_bytes(24));
    $secret_b = bin2hex(random_bytes(24));
    $request = static function ($method, $path, $secret, $body = null) {
        $request = new WP_REST_Request($method, '/portalweave-sso/v1/scim/v2/' . $path);
        $request->set_header('Authorization', 'Bearer ' . $secret);
        if ($body !== null) { $request->set_body(wp_json_encode($body)); }
        return rest_do_request($request);
    };
    try {
        $_SERVER['HTTPS'] = 'on';
        foreach ([$portalweave_sso_blog_a => $secret_a, $portalweave_sso_blog_b => $secret_b] as $blog => $secret) {
            switch_to_blog($blog);
            $settings[$blog] = get_option('portalweave_sso_settings', []);
            update_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults(), false);
            $saved = \PortalWeaveSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]);
            portalweave_sso_assert($saved === true, 'Unable to configure SCIM blog fixture');
            restore_current_blog();
        }
        switch_to_blog($portalweave_sso_blog_a);
        rest_get_server();
        \PortalWeaveSSO\Extensions::routes();
        $username = 'scim_network_' . bin2hex(random_bytes(5));
        $created = $request('POST', 'Users', $secret_a, ['userName' => $username, 'externalId' => $username,
            'emails' => [['value' => $username . '@example.org']], 'active' => true]);
        portalweave_sso_assert($created->get_status() === 201, 'SCIM multisite creation failed: ' . wp_json_encode($created->get_data()));
        $id = (int) $created->get_data()['id'];
        portalweave_sso_assert(is_user_member_of_blog($id, $portalweave_sso_blog_a), 'Provisioned account is not a member of its owner blog');
        $bad_credential = $request('GET', 'Users/' . $id, $secret_b);
        portalweave_sso_assert($bad_credential->get_status() === 401, 'Site B bearer was accepted by site A');
        restore_current_blog();
        switch_to_blog($portalweave_sso_blog_b);
        $cross_get = $request('GET', 'Users/' . $id, $secret_b);
        $cross_patch = $request('PATCH', 'Users/' . $id, $secret_b, ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
        portalweave_sso_assert($cross_get->get_status() === 404 && $cross_patch->get_status() === 404, 'Site B accessed or changed site A resource');
        $listed = $request('GET', 'Users', $secret_b);
        portalweave_sso_assert($listed->get_data()['totalResults'] === 0, 'Site B directory included another site account');
        add_user_to_blog($portalweave_sso_blog_b, $id, 'subscriber');
        restore_current_blog();
        switch_to_blog($portalweave_sso_blog_a);
        $shared_delete = $request('DELETE', 'Users/' . $id, $secret_a);
        portalweave_sso_assert($shared_delete->get_status() === 403, 'Site-local SCIM deactivated a global account shared by two sites');
        portalweave_sso_assert(get_user_meta($id, '_portalweave_sso_scim_active_' . $portalweave_sso_blog_a, true) === '1', 'Rejected mutation changed account activity');
        update_user_meta($id, '_portalweave_sso_scim_group_names_' . $portalweave_sso_blog_a, ['engineering']);
        portalweave_sso_assert(in_array('engineering', \PortalWeaveSSO\Extensions::scim_groups($id), true), 'Owner site lost scoped SCIM group');
        restore_current_blog();
        switch_to_blog($portalweave_sso_blog_b);
        portalweave_sso_assert(\PortalWeaveSSO\Extensions::scim_groups($id) === [], 'SCIM groups leaked to another site');
    } finally {
        while (count($GLOBALS['_wp_switched_stack'] ?? []) > $original_depth) { restore_current_blog(); }
        foreach ($settings as $blog => $value) { switch_to_blog($blog); update_option('portalweave_sso_settings', $value, false); restore_current_blog(); }
        if ($id) { require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_user($id); }
        if ($https === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $https; }
    }
});

portalweave_sso_test('multisite: moderation and avatar updates remain local to the configured blog', function () {
    global $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $original_blog = get_current_blog_id();
    $original_depth = count($GLOBALS['_wp_switched_stack'] ?? []);
    $settings = [];
    $id = wp_insert_user(['user_login' => 'module_network_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    portalweave_sso_assert(!is_wp_error($id), 'Unable to create network extension fixture');
    try {
        foreach ([$portalweave_sso_blog_a, $portalweave_sso_blog_b] as $blog) {
            add_user_to_blog($blog, $id, 'subscriber');
            switch_to_blog($blog);
            $settings[$blog] = get_option('portalweave_sso_settings', []);
            update_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults(), false);
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['modules' => ['moderation' => true, 'avatar' => true]]) === true, 'Unable to enable blog module fixture');
            restore_current_blog();
        }
        switch_to_blog($portalweave_sso_blog_a);
        $user = get_user_by('id', $id);
        \PortalWeaveSSO\Extensions::resolved($user, ['id' => 'moderated', 'moderation' => true], ['picture' => 'https://example.org/avatar-site-a.png']);
        portalweave_sso_error(\PortalWeaveSSO\Extensions::account_check($user), 'portalweave_sso_approval_pending');
        $avatar_a = \PortalWeaveSSO\Extensions::avatar(['url' => 'default'], $user);
        portalweave_sso_assert($avatar_a['url'] === 'https://example.org/avatar-site-a.png', 'Site A avatar was not stored');
        restore_current_blog();
        switch_to_blog($portalweave_sso_blog_b);
        portalweave_sso_assert(\PortalWeaveSSO\Extensions::account_check($user) instanceof WP_User, 'Site A moderation blocked site B login');
        portalweave_sso_assert(\PortalWeaveSSO\Extensions::avatar(['url' => 'default'], $user)['url'] === 'default', 'Site A avatar leaked to site B');
        \PortalWeaveSSO\Extensions::resolved($user, ['id' => 'unmoderated', 'moderation' => false], ['picture' => 'https://example.org/avatar-site-b.png']);
        portalweave_sso_assert(\PortalWeaveSSO\Extensions::avatar(['url' => 'default'], $user)['url'] === 'https://example.org/avatar-site-b.png', 'Site B avatar missing');
        restore_current_blog();
        switch_to_blog($portalweave_sso_blog_a);
        portalweave_sso_assert(\PortalWeaveSSO\Extensions::avatar(['url' => 'default'], $user)['url'] === 'https://example.org/avatar-site-a.png', 'Site B changed site A avatar');
    } finally {
        while (count($GLOBALS['_wp_switched_stack'] ?? []) > $original_depth) { restore_current_blog(); }
        foreach ($settings as $blog => $value) { switch_to_blog($blog); update_option('portalweave_sso_settings', $value, false); restore_current_blog(); }
        require_once ABSPATH . 'wp-admin/includes/ms.php';
        wpmu_delete_user($id);
    }
});
