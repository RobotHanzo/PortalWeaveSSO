<?php
/** Real-network add-on isolation; run only from the guarded multisite runner. */

wpsso_test('multisite: SCIM credentials and resource ownership are isolated between blogs', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $original_blog = get_current_blog_id();
    $original_depth = count($GLOBALS['_wp_switched_stack'] ?? []);
    $settings = [];
    $id = 0;
    $https = $_SERVER['HTTPS'] ?? null;
    $secret_a = bin2hex(random_bytes(24));
    $secret_b = bin2hex(random_bytes(24));
    $request = static function ($method, $path, $secret, $body = null) {
        $request = new WP_REST_Request($method, '/wpsso/v1/scim/v2/' . $path);
        $request->set_header('Authorization', 'Bearer ' . $secret);
        if ($body !== null) { $request->set_body(wp_json_encode($body)); }
        return rest_do_request($request);
    };
    try {
        $_SERVER['HTTPS'] = 'on';
        foreach ([$wpsso_blog_a => $secret_a, $wpsso_blog_b => $secret_b] as $blog => $secret) {
            switch_to_blog($blog);
            $settings[$blog] = get_option('wpsso_settings', []);
            update_option('wpsso_settings', \WPSSO\Config::defaults(), false);
            $saved = \WPSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]);
            wpsso_assert($saved === true, 'Unable to configure SCIM blog fixture');
            restore_current_blog();
        }
        switch_to_blog($wpsso_blog_a);
        rest_get_server();
        \WPSSO\Extensions::routes();
        $username = 'scim_network_' . bin2hex(random_bytes(5));
        $created = $request('POST', 'Users', $secret_a, ['userName' => $username, 'externalId' => $username,
            'emails' => [['value' => $username . '@example.org']], 'active' => true]);
        wpsso_assert($created->get_status() === 201, 'SCIM multisite creation failed: ' . wp_json_encode($created->get_data()));
        $id = (int) $created->get_data()['id'];
        wpsso_assert(is_user_member_of_blog($id, $wpsso_blog_a), 'Provisioned account is not a member of its owner blog');
        $bad_credential = $request('GET', 'Users/' . $id, $secret_b);
        wpsso_assert($bad_credential->get_status() === 401, 'Site B bearer was accepted by site A');
        restore_current_blog();
        switch_to_blog($wpsso_blog_b);
        $cross_get = $request('GET', 'Users/' . $id, $secret_b);
        $cross_patch = $request('PATCH', 'Users/' . $id, $secret_b, ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
        wpsso_assert($cross_get->get_status() === 404 && $cross_patch->get_status() === 404, 'Site B accessed or changed site A resource');
        $listed = $request('GET', 'Users', $secret_b);
        wpsso_assert($listed->get_data()['totalResults'] === 0, 'Site B directory included another site account');
        add_user_to_blog($wpsso_blog_b, $id, 'subscriber');
        restore_current_blog();
        switch_to_blog($wpsso_blog_a);
        $shared_delete = $request('DELETE', 'Users/' . $id, $secret_a);
        wpsso_assert($shared_delete->get_status() === 403, 'Site-local SCIM deactivated a global account shared by two sites');
        wpsso_assert(get_user_meta($id, '_wpsso_scim_active_' . $wpsso_blog_a, true) === '1', 'Rejected mutation changed account activity');
        update_user_meta($id, '_wpsso_scim_group_names_' . $wpsso_blog_a, ['engineering']);
        wpsso_assert(in_array('engineering', \WPSSO\Extensions::scim_groups($id), true), 'Owner site lost scoped SCIM group');
        restore_current_blog();
        switch_to_blog($wpsso_blog_b);
        wpsso_assert(\WPSSO\Extensions::scim_groups($id) === [], 'SCIM groups leaked to another site');
    } finally {
        while (count($GLOBALS['_wp_switched_stack'] ?? []) > $original_depth) { restore_current_blog(); }
        foreach ($settings as $blog => $value) { switch_to_blog($blog); update_option('wpsso_settings', $value, false); restore_current_blog(); }
        if ($id) { require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_user($id); }
        if ($https === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $https; }
    }
});

wpsso_test('multisite: moderation and avatar updates remain local to the configured blog', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $original_blog = get_current_blog_id();
    $original_depth = count($GLOBALS['_wp_switched_stack'] ?? []);
    $settings = [];
    $id = wp_insert_user(['user_login' => 'module_network_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    wpsso_assert(!is_wp_error($id), 'Unable to create network extension fixture');
    try {
        foreach ([$wpsso_blog_a, $wpsso_blog_b] as $blog) {
            add_user_to_blog($blog, $id, 'subscriber');
            switch_to_blog($blog);
            $settings[$blog] = get_option('wpsso_settings', []);
            update_option('wpsso_settings', \WPSSO\Config::defaults(), false);
            wpsso_assert(\WPSSO\Config::save(['modules' => ['moderation' => true, 'avatar' => true]]) === true, 'Unable to enable blog module fixture');
            restore_current_blog();
        }
        switch_to_blog($wpsso_blog_a);
        $user = get_user_by('id', $id);
        \WPSSO\Extensions::resolved($user, ['id' => 'moderated', 'moderation' => true], ['picture' => 'https://example.org/avatar-site-a.png']);
        wpsso_error(\WPSSO\Extensions::account_check($user), 'wpsso_approval_pending');
        $avatar_a = \WPSSO\Extensions::avatar(['url' => 'default'], $user);
        wpsso_assert($avatar_a['url'] === 'https://example.org/avatar-site-a.png', 'Site A avatar was not stored');
        restore_current_blog();
        switch_to_blog($wpsso_blog_b);
        wpsso_assert(\WPSSO\Extensions::account_check($user) instanceof WP_User, 'Site A moderation blocked site B login');
        wpsso_assert(\WPSSO\Extensions::avatar(['url' => 'default'], $user)['url'] === 'default', 'Site A avatar leaked to site B');
        \WPSSO\Extensions::resolved($user, ['id' => 'unmoderated', 'moderation' => false], ['picture' => 'https://example.org/avatar-site-b.png']);
        wpsso_assert(\WPSSO\Extensions::avatar(['url' => 'default'], $user)['url'] === 'https://example.org/avatar-site-b.png', 'Site B avatar missing');
        restore_current_blog();
        switch_to_blog($wpsso_blog_a);
        wpsso_assert(\WPSSO\Extensions::avatar(['url' => 'default'], $user)['url'] === 'https://example.org/avatar-site-a.png', 'Site B changed site A avatar');
    } finally {
        while (count($GLOBALS['_wp_switched_stack'] ?? []) > $original_depth) { restore_current_blog(); }
        foreach ($settings as $blog => $value) { switch_to_blog($blog); update_option('wpsso_settings', $value, false); restore_current_blog(); }
        require_once ABSPATH . 'wp-admin/includes/ms.php';
        wpmu_delete_user($id);
    }
});
