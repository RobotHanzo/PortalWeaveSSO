<?php
/** Exercise real WordPress REST, user metadata, capabilities and atomic options. */

wpsso_test('SCIM rejects disabled modules, HTTP and invalid bearer tokens', function () {
    $saved = get_option('wpsso_settings');
    $https = $_SERVER['HTTPS'] ?? null;
    try {
        $secret = str_repeat('scim-secret-', 4);
        wpsso_assert(\WPSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]) === true, 'SCIM configuration failed');
        $request = new WP_REST_Request('GET', '/wpsso/v1/scim/v2/Users');
        $request->set_header('Authorization', 'Bearer ' . $secret);
        $_SERVER['HTTPS'] = 'off';
        wpsso_error(\WPSSO\Extensions::scim_permission($request), 'wpsso_scim_auth');
        $_SERVER['HTTPS'] = 'on';
        $request->set_header('Authorization', 'Bearer wrong-secret');
        wpsso_error(\WPSSO\Extensions::scim_permission($request), 'wpsso_scim_auth');
        $request->set_header('Authorization', 'Bearer ' . $secret);
        wpsso_assert(\WPSSO\Extensions::scim_permission($request) === true, 'Correct SCIM credential rejected');
        \WPSSO\Config::save(['modules' => ['scim' => false]]);
        wpsso_error(\WPSSO\Extensions::scim_permission($request), 'wpsso_scim_auth');
    } finally {
        update_option('wpsso_settings', $saved, false);
        if ($https === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $https; }
    }
});

wpsso_test('SCIM provisions subscriber, prevents takeover, manages groups and revokes deactivated login', function () {
    $saved = get_option('wpsso_settings');
    $old_groups = get_option('wpsso_scim_groups', []);
    $https = $_SERVER['HTTPS'] ?? null;
    $ids = [];
    try {
        $_SERVER['HTTPS'] = 'on';
        $secret = bin2hex(random_bytes(24));
        wpsso_assert(\WPSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]) === true, 'SCIM setup failed');
        rest_get_server();
        \WPSSO\Extensions::routes();
        $call = static function ($method, $path, $body = null, $query = []) use ($secret) {
            $request = new WP_REST_Request($method, '/wpsso/v1/scim/v2/' . $path);
            $request->set_header('Authorization', 'Bearer ' . $secret);
            $request->set_header('Content-Type', 'application/scim+json');
            if ($body !== null) { $request->set_body(wp_json_encode($body)); }
            $request->set_query_params($query);
            return rest_do_request($request);
        };
        $username = 'scim_' . bin2hex(random_bytes(6));
        $body = ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => $username,
            'externalId' => 'external-' . $username, 'emails' => [['value' => $username . '@example.org', 'primary' => true]],
            'active' => true, 'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace']];
        $response = $call('POST', 'Users', $body);
        wpsso_assert($response->get_status() === 201, 'User create failed: ' . wp_json_encode($response->get_data()));
        $id = (int) $response->get_data()['id'];
        $ids[] = $id;
        wpsso_assert(get_user_by('id', $id)->roles === ['subscriber'], 'SCIM assigned an elevated role');
        wpsso_assert($call('POST', 'Users', $body)->get_status() === 409, 'Duplicate identity was accepted');
        $second = $body;
        $second['userName'] .= 'other';
        $second['emails'][0]['value'] = $second['userName'] . '@example.org';
        wpsso_assert($call('POST', 'Users', $second)->get_status() === 409, 'Duplicate externalId was accepted');
        $result = $call('GET', 'Users', null, ['filter' => 'userName eq "' . $username . '"', 'count' => 1]);
        wpsso_assert($result->get_data()['totalResults'] === 1 && count($result->get_data()['Resources']) === 1, 'SCIM equality filter failed');
        $group = $call('POST', 'Groups', ['displayName' => 'Engineering', 'members' => [['value' => (string) $id]]]);
        wpsso_assert($group->get_status() === 201, 'Group create failed');
        $group_id = $group->get_data()['id'];
        wpsso_assert(in_array('Engineering', (array) get_user_meta($id, '_wpsso_scim_group_names', true), true), 'Group membership not stored');
        $remove = $call('PATCH', 'Groups/' . $group_id, ['Operations' => [['op' => 'remove', 'path' => 'members[value eq "' . $id . '"]']]]);
        wpsso_assert($remove->get_status() === 200 && get_user_meta($id, '_wpsso_scim_groups', true) === [], 'Group remove failed');
        $active = $call('PATCH', 'Users/' . $id, ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
        wpsso_assert($active->get_status() === 200 && $active->get_data()['active'] === false, 'Active=false patch failed: ' . wp_json_encode($active->get_data()));
        wpsso_error(\WPSSO\Extensions::account_check(get_user_by('id', $id)), 'wpsso_account_inactive');
        $admin_id = wp_insert_user(['user_login' => 'scim_admin_' . bin2hex(random_bytes(4)), 'user_pass' => wp_generate_password(32), 'role' => 'administrator']);
        $ids[] = $admin_id;
        update_user_meta($admin_id, '_wpsso_scim_managed', '1');
        $denied = $call('DELETE', 'Users/' . $admin_id);
        wpsso_assert($denied->get_status() === 403 && get_user_meta($admin_id, '_wpsso_scim_active', true) !== '0', 'SCIM deactivated an administrator');
        $elevation = $body;
        $elevation['roles'] = ['administrator'];
        wpsso_assert($call('PUT', 'Users/' . $id, $elevation)->get_status() === 403, 'SCIM accepted roles');
    } finally {
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        foreach ($ids as $id) { if (is_int($id)) { wp_delete_user($id); } }
        update_option('wpsso_settings', $saved, false);
        update_option('wpsso_scim_groups', $old_groups, false);
        if ($https === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $https; }
    }
});

wpsso_test('SCIM rejects unsupported filters and read-only PATCH paths', function () {
    wpsso_error(\WPSSO\Extensions::parse_filter('userName eq "ada" or id pr'), 'wpsso_scim_filter');
    wpsso_error(\WPSSO\Extensions::parse_filter('userName eq "x"; DROP TABLE users'), 'wpsso_scim_filter');
    $result = \WPSSO\Extensions::parse_filter('externalId eq "abc\\"def"');
    wpsso_assert($result === ['externalid', 'abc"def'], 'Escaped SCIM filter was not parsed');
    $result = \WPSSO\Extensions::patch(['userName' => 'ada'], ['Operations' => [['op' => 'replace', 'path' => 'roles', 'value' => ['administrator']]]]);
    wpsso_error($result, 'wpsso_scim_patch');
});

wpsso_test('JWT/shared assertion replay claims are atomic and purpose-bound', function () {
    $id = bin2hex(random_bytes(12));
    wpsso_assert(\WPSSO\Extensions::consume('test-jwt', $id, 1), 'First claim failed');
    wpsso_assert(!\WPSSO\Extensions::consume('test-jwt', $id, 1), 'Replay was accepted');
    wpsso_assert(\WPSSO\Extensions::consume('test-other-site', $id, 1), 'Independent audience claim failed');
    foreach (['test-jwt', 'test-other-site'] as $purpose) {
        $name = 'wpsso_claim_' . hash('sha256', $purpose . '|' . $id);
        delete_option($name);
        wp_clear_scheduled_hook('wpsso_expire_claim', [$name]);
    }
});

wpsso_test('Eight abilities stay disabled by default and require manage_options on direct execution', function () {
    $saved = get_option('wpsso_settings');
    $current = get_current_user_id();
    $subscriber = wp_insert_user(['user_login' => 'ability_' . bin2hex(random_bytes(4)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    try {
        wpsso_assert(count(\WPSSO\Abilities::definitions()) === 8, 'Exactly eight abilities are required');
        \WPSSO\Config::save(['abilities_enabled' => false]);
        wpsso_error(\WPSSO\Abilities::execute('export-config'), 'wpsso_ability_forbidden');
        \WPSSO\Config::save(['abilities_enabled' => true]);
        wp_set_current_user($subscriber);
        wpsso_error(\WPSSO\Abilities::execute('configure-provider', ['id' => 'evil', 'settings' => []]), 'wpsso_ability_forbidden');
        wpsso_error(\WPSSO\Abilities::execute('login-audit'), 'wpsso_ability_forbidden');
    } finally {
        wp_set_current_user($current);
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        wp_delete_user($subscriber);
        update_option('wpsso_settings', $saved, false);
    }
});

wpsso_test('Moderation and guest expiry block authentication without changing roles', function () {
    $id = wp_insert_user(['user_login' => 'moderation_' . bin2hex(random_bytes(4)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    try {
        $user = get_user_by('id', $id);
        update_user_meta($id, '_wpsso_moderation', 'pending');
        wpsso_error(\WPSSO\Extensions::account_check($user), 'wpsso_approval_pending');
        update_user_meta($id, '_wpsso_moderation', 'approved');
        wpsso_assert(\WPSSO\Extensions::account_check($user) instanceof WP_User, 'Approved account rejected');
        update_user_meta($id, '_wpsso_guest_expires', time() - 1);
        wpsso_error(\WPSSO\Extensions::account_check($user), 'wpsso_guest_expired');
        wpsso_assert(get_user_by('id', $id)->roles === ['subscriber'], 'Moderation changed roles');
    } finally {
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        wp_delete_user($id);
    }
});

wpsso_test('Native WordPress abilities register eight tools and enforce permission again at execution', function () {
    if (!function_exists('wp_get_abilities')) {
        wpsso_assert(!function_exists('wp_register_ability'), 'Native ability discovery is unexpectedly missing');
        return;
    }
    $saved = get_option('wpsso_settings');
    $current = get_current_user_id();
    $admin = get_user_by('login', 'wpsso_test_admin');
    wpsso_assert($admin instanceof WP_User, 'Integration administrator is missing');
    try {
        wp_set_current_user($admin->ID);
        wpsso_assert(\WPSSO\Config::save(['abilities_enabled' => true]) === true, 'Could not enable abilities');
        // The public discovery API lazily initializes the native registries and
        // fires the category/ability registration actions exactly once.
        $abilities = array_filter(wp_get_abilities(), static fn($ability) => str_starts_with($ability->get_name(), 'wpsso/'));
        wpsso_assert(did_action('wp_abilities_api_categories_init') > 0 && did_action('wp_abilities_api_init') > 0, 'Native registration actions did not run');
        wpsso_assert(count($abilities) === 8, 'Eight native tools were not registered');
        $ability = wp_get_ability('wpsso/export-config');
        $result = $ability->execute([]);
        wpsso_assert(is_array($result) && array_key_exists('apps', $result), 'Native ability execute failed: ' . (is_wp_error($result) ? $result->get_error_message() : get_debug_type($result)));
        \WPSSO\Config::save(['abilities_enabled' => false]);
        $denied = $ability->execute([]);
        wpsso_assert(is_wp_error($denied), 'A previously registered tool executed after being disabled');
        wpsso_error(\WPSSO\Abilities::execute('export-config'), 'wpsso_ability_forbidden');
    } finally {
        wp_set_current_user($current);
        update_option('wpsso_settings', $saved, false);
    }
});
