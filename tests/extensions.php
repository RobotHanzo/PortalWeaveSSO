<?php
/** Exercise real WordPress REST, user metadata, capabilities and atomic options. */

portalweave_sso_test('SCIM rejects disabled modules, HTTP and invalid bearer tokens', function () {
    $saved = get_option('portalweave_sso_settings');
    $https = $_SERVER['HTTPS'] ?? null;
    try {
        $secret = str_repeat('scim-secret-', 4);
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]) === true, 'SCIM configuration failed');
        $request = new WP_REST_Request('GET', '/portalweave-sso/v1/scim/v2/Users');
        $request->set_header('Authorization', 'Bearer ' . $secret);
        $_SERVER['HTTPS'] = 'off';
        portalweave_sso_error(\PortalWeaveSSO\Extensions::scim_permission($request), 'portalweave_sso_scim_auth');
        $_SERVER['HTTPS'] = 'on';
        $request->set_header('Authorization', 'Bearer wrong-secret');
        portalweave_sso_error(\PortalWeaveSSO\Extensions::scim_permission($request), 'portalweave_sso_scim_auth');
        $request->set_header('Authorization', 'Bearer ' . $secret);
        portalweave_sso_assert(\PortalWeaveSSO\Extensions::scim_permission($request) === true, 'Correct SCIM credential rejected');
        \PortalWeaveSSO\Config::save(['modules' => ['scim' => false]]);
        portalweave_sso_error(\PortalWeaveSSO\Extensions::scim_permission($request), 'portalweave_sso_scim_auth');
    } finally {
        update_option('portalweave_sso_settings', $saved, false);
        if ($https === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $https; }
    }
});

portalweave_sso_test('SCIM provisions subscriber, prevents takeover, manages groups and revokes deactivated login', function () {
    $saved = get_option('portalweave_sso_settings');
    $old_groups = get_option('portalweave_sso_scim_groups', []);
    $https = $_SERVER['HTTPS'] ?? null;
    $ids = [];
    try {
        $_SERVER['HTTPS'] = 'on';
        $secret = bin2hex(random_bytes(24));
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]) === true, 'SCIM setup failed');
        rest_get_server();
        \PortalWeaveSSO\Extensions::routes();
        $call = static function ($method, $path, $body = null, $query = []) use ($secret) {
            $request = new WP_REST_Request($method, '/portalweave-sso/v1/scim/v2/' . $path);
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
        portalweave_sso_assert($response->get_status() === 201, 'User create failed: ' . wp_json_encode($response->get_data()));
        $id = (int) $response->get_data()['id'];
        $ids[] = $id;
        portalweave_sso_assert(get_user_by('id', $id)->roles === ['subscriber'], 'SCIM assigned an elevated role');
        portalweave_sso_assert($call('POST', 'Users', $body)->get_status() === 409, 'Duplicate identity was accepted');
        $second = $body;
        $second['userName'] .= 'other';
        $second['emails'][0]['value'] = $second['userName'] . '@example.org';
        portalweave_sso_assert($call('POST', 'Users', $second)->get_status() === 409, 'Duplicate externalId was accepted');
        $result = $call('GET', 'Users', null, ['filter' => 'userName eq "' . $username . '"', 'count' => 1]);
        portalweave_sso_assert($result->get_data()['totalResults'] === 1 && count($result->get_data()['Resources']) === 1, 'SCIM equality filter failed');
        $group = $call('POST', 'Groups', ['displayName' => 'Engineering', 'members' => [['value' => (string) $id]]]);
        portalweave_sso_assert($group->get_status() === 201, 'Group create failed');
        $group_id = $group->get_data()['id'];
        portalweave_sso_assert(in_array('Engineering', (array) get_user_meta($id, '_portalweave_sso_scim_group_names', true), true), 'Group membership not stored');
        $remove = $call('PATCH', 'Groups/' . $group_id, ['Operations' => [['op' => 'remove', 'path' => 'members[value eq "' . $id . '"]']]]);
        portalweave_sso_assert($remove->get_status() === 200 && get_user_meta($id, '_portalweave_sso_scim_groups', true) === [], 'Group remove failed');
        $active = $call('PATCH', 'Users/' . $id, ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
        portalweave_sso_assert($active->get_status() === 200 && $active->get_data()['active'] === false, 'Active=false patch failed: ' . wp_json_encode($active->get_data()));
        portalweave_sso_error(\PortalWeaveSSO\Extensions::account_check(get_user_by('id', $id)), 'portalweave_sso_account_inactive');
        $admin_id = wp_insert_user(['user_login' => 'scim_admin_' . bin2hex(random_bytes(4)), 'user_pass' => wp_generate_password(32), 'role' => 'administrator']);
        $ids[] = $admin_id;
        update_user_meta($admin_id, '_portalweave_sso_scim_managed', '1');
        $denied = $call('DELETE', 'Users/' . $admin_id);
        portalweave_sso_assert($denied->get_status() === 403 && get_user_meta($admin_id, '_portalweave_sso_scim_active', true) !== '0', 'SCIM deactivated an administrator');
        $elevation = $body;
        $elevation['roles'] = ['administrator'];
        portalweave_sso_assert($call('PUT', 'Users/' . $id, $elevation)->get_status() === 403, 'SCIM accepted roles');
    } finally {
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        foreach ($ids as $id) { if (is_int($id)) { wp_delete_user($id); } }
        update_option('portalweave_sso_settings', $saved, false);
        update_option('portalweave_sso_scim_groups', $old_groups, false);
        if ($https === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $https; }
    }
});

portalweave_sso_test('SCIM rejects unsupported filters and read-only PATCH paths', function () {
    portalweave_sso_error(\PortalWeaveSSO\Extensions::parse_filter('userName eq "ada" or id pr'), 'portalweave_sso_scim_filter');
    portalweave_sso_error(\PortalWeaveSSO\Extensions::parse_filter('userName eq "x"; DROP TABLE users'), 'portalweave_sso_scim_filter');
    $result = \PortalWeaveSSO\Extensions::parse_filter('externalId eq "abc\\"def"');
    portalweave_sso_assert($result === ['externalid', 'abc"def'], 'Escaped SCIM filter was not parsed');
    $result = \PortalWeaveSSO\Extensions::patch(['userName' => 'ada'], ['Operations' => [['op' => 'replace', 'path' => 'roles', 'value' => ['administrator']]]]);
    portalweave_sso_error($result, 'portalweave_sso_scim_patch');
});

portalweave_sso_test('SCIM deactivation denies existing Application Passwords without deleting credentials', function () {
    $id = 0;
    $api_request = static fn() => true;
    add_filter('application_password_is_api_request', $api_request);
    try {
        $secret = bin2hex(random_bytes(24));
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]) === true, 'SCIM setup failed');
        rest_get_server();
        \PortalWeaveSSO\Extensions::routes();
        $call = static function ($method, $path, $body = null) use ($secret) {
            $request = new WP_REST_Request($method, '/portalweave-sso/v1/scim/v2/' . $path);
            $request->set_header('Authorization', 'Bearer ' . $secret);
            $request->set_header('Content-Type', 'application/scim+json');
            if ($body !== null) { $request->set_body(wp_json_encode($body)); }
            return rest_do_request($request);
        };
        $name = 'scim_api_' . bin2hex(random_bytes(6));
        $body = ['userName' => $name, 'emails' => [['value' => $name . '@example.org']], 'active' => true];
        $created = $call('POST', 'Users', $body);
        portalweave_sso_assert($created->get_status() === 201, 'SCIM user creation failed');
        $id = (int) $created->get_data()['id'];
        $credential = WP_Application_Passwords::create_new_application_password($id, ['name' => 'Disposable API regression']);
        portalweave_sso_assert(is_array($credential), 'Application Password creation failed');
        $authenticate = static fn() => wp_authenticate_application_password(null, $name, $credential[0]);
        portalweave_sso_assert($authenticate() instanceof WP_User, 'Active account could not authenticate');
        foreach (['PATCH', 'PUT', 'DELETE'] as $method) {
            if ($method === 'PATCH') {
                $disabled = $call($method, 'Users/' . $id, ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
            } else {
                $disabled = $call($method, 'Users/' . $id, $method === 'PUT' ? array_replace($body, ['active' => false]) : null);
            }
            portalweave_sso_assert($disabled->get_status() === ($method === 'DELETE' ? 204 : 200), $method . ' deactivation failed');
            portalweave_sso_error($authenticate(), 'portalweave_sso_account_inactive');
            portalweave_sso_assert(count(WP_Application_Passwords::get_user_application_passwords($id)) === 1, 'Deactivation deleted global API credentials');
            $active = $call('PATCH', 'Users/' . $id, ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => true]]]);
            portalweave_sso_assert($active->get_status() === 200 && $authenticate() instanceof WP_User, 'Reactivation did not restore legitimate API authentication');
        }
    } finally {
        remove_filter('application_password_is_api_request', $api_request);
        if ($id) { wp_delete_user($id); }
    }
});

portalweave_sso_test('Native API authentication enforces moderation and expiry while retaining earlier errors', function () {
    $id = wp_insert_user(['user_login' => 'moderation_api_' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(32), 'role' => 'editor']);
    portalweave_sso_assert(!is_wp_error($id), 'API user creation failed');
    $server = $_SERVER;
    $post = $_POST;
    $api_status = $GLOBALS['wp_rest_application_password_status'] ?? null;
    $api_request = static fn() => true;
    add_filter('application_password_is_api_request', $api_request);
    try {
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['modules' => ['moderation' => true]]) === true, 'Moderation setup failed');
        $credential = WP_Application_Passwords::create_new_application_password($id, ['name' => 'Disposable moderation API regression']);
        portalweave_sso_assert(is_array($credential), 'Application Password creation failed');
        $_SERVER['PHP_AUTH_USER'] = get_userdata($id)->user_login;
        $_SERVER['PHP_AUTH_PW'] = $credential[0];
        portalweave_sso_assert(wp_validate_application_password(false) === $id, 'Native API authentication rejected an active editor');
        $admin = get_user_by('login', 'portalweave_sso_test_admin');
        wp_set_current_user($admin->ID);
        foreach (['pending' => 'portalweave_sso_approval_pending', 'blocked' => 'portalweave_sso_account_blocked', 'approved' => ''] as $status => $code) {
            $_POST = ['portalweave_sso_moderation' => $status, 'portalweave_sso_moderation_nonce' => wp_create_nonce('portalweave_sso_moderate_' . $id)];
            \PortalWeaveSSO\Extensions::moderation_save($id);
            $authenticated = wp_validate_application_password(false);
            if ($code !== '') {
                portalweave_sso_assert($authenticated === false, 'Blocked account retained native API access');
                portalweave_sso_error(rest_application_password_check_errors(null), $code);
            } else {
                portalweave_sso_assert($authenticated === $id, 'Approved account lost API access');
            }
        }
        update_user_meta($id, '_portalweave_sso_guest_expires', time() - 1);
        portalweave_sso_assert(wp_validate_application_password(false) === false, 'Expired guest retained native API access');
        portalweave_sso_error(rest_application_password_check_errors(null), 'portalweave_sso_guest_expired');
        $prior = new WP_Error('other_policy', 'Existing authentication rejection');
        do_action('wp_authenticate_application_password_errors', $prior, get_userdata($id), [], $credential[0]);
        portalweave_sso_assert($prior->get_error_code() === 'other_policy' && in_array('portalweave_sso_guest_expired', $prior->get_error_codes(), true), 'Account policy replaced an earlier authentication error');
        portalweave_sso_assert(get_userdata($id)->roles === ['editor'] && count(WP_Application_Passwords::get_user_application_passwords($id)) === 1, 'API admission changed account roles or credentials');
    } finally {
        remove_filter('application_password_is_api_request', $api_request);
        $_SERVER = $server;
        $_POST = $post;
        $GLOBALS['wp_rest_application_password_status'] = $api_status;
        wp_delete_user($id);
    }
});

portalweave_sso_test('JWT/shared assertion replay claims are atomic and purpose-bound', function () {
    $id = bin2hex(random_bytes(12));
    portalweave_sso_assert(\PortalWeaveSSO\Extensions::consume('test-jwt', $id, 1), 'First claim failed');
    portalweave_sso_assert(!\PortalWeaveSSO\Extensions::consume('test-jwt', $id, 1), 'Replay was accepted');
    portalweave_sso_assert(\PortalWeaveSSO\Extensions::consume('test-other-site', $id, 1), 'Independent audience claim failed');
    foreach (['test-jwt', 'test-other-site'] as $purpose) {
        $name = 'portalweave_sso_claim_' . hash('sha256', $purpose . '|' . $id);
        delete_option($name);
        wp_clear_scheduled_hook('portalweave_sso_expire_claim', [$name]);
    }
});

portalweave_sso_test('Eight abilities stay disabled by default and require manage_options on direct execution', function () {
    $saved = get_option('portalweave_sso_settings');
    $current = get_current_user_id();
    $subscriber = wp_insert_user(['user_login' => 'ability_' . bin2hex(random_bytes(4)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    try {
        portalweave_sso_assert(count(\PortalWeaveSSO\Abilities::definitions()) === 8, 'Exactly eight abilities are required');
        \PortalWeaveSSO\Config::save(['abilities_enabled' => false]);
        portalweave_sso_error(\PortalWeaveSSO\Abilities::execute('export-config'), 'portalweave_sso_ability_forbidden');
        \PortalWeaveSSO\Config::save(['abilities_enabled' => true]);
        wp_set_current_user($subscriber);
        portalweave_sso_error(\PortalWeaveSSO\Abilities::execute('configure-provider', ['id' => 'evil', 'settings' => []]), 'portalweave_sso_ability_forbidden');
        portalweave_sso_error(\PortalWeaveSSO\Abilities::execute('login-audit'), 'portalweave_sso_ability_forbidden');
    } finally {
        wp_set_current_user($current);
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        wp_delete_user($subscriber);
        update_option('portalweave_sso_settings', $saved, false);
    }
});

portalweave_sso_test('Moderation and guest expiry block authentication without changing roles', function () {
    $id = wp_insert_user(['user_login' => 'moderation_' . bin2hex(random_bytes(4)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    try {
        $user = get_user_by('id', $id);
        update_user_meta($id, '_portalweave_sso_moderation', 'pending');
        portalweave_sso_error(\PortalWeaveSSO\Extensions::account_check($user), 'portalweave_sso_approval_pending');
        update_user_meta($id, '_portalweave_sso_moderation', 'approved');
        portalweave_sso_assert(\PortalWeaveSSO\Extensions::account_check($user) instanceof WP_User, 'Approved account rejected');
        update_user_meta($id, '_portalweave_sso_guest_expires', time() - 1);
        portalweave_sso_error(\PortalWeaveSSO\Extensions::account_check($user), 'portalweave_sso_guest_expired');
        portalweave_sso_assert(get_user_by('id', $id)->roles === ['subscriber'], 'Moderation changed roles');
    } finally {
        if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        wp_delete_user($id);
    }
});

portalweave_sso_test('Native WordPress abilities register eight tools and enforce permission again at execution', function () {
    if (!function_exists('wp_get_abilities')) {
        portalweave_sso_assert(!function_exists('wp_register_ability'), 'Native ability discovery is unexpectedly missing');
        return;
    }
    $saved = get_option('portalweave_sso_settings');
    $current = get_current_user_id();
    $admin = get_user_by('login', 'portalweave_sso_test_admin');
    portalweave_sso_assert($admin instanceof WP_User, 'Integration administrator is missing');
    try {
        wp_set_current_user($admin->ID);
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['abilities_enabled' => true]) === true, 'Could not enable abilities');
        // The public discovery API lazily initializes the native registries and
        // fires the category/ability registration actions exactly once.
        $abilities = array_filter(wp_get_abilities(), static fn($ability) => str_starts_with($ability->get_name(), 'portalweave-sso/'));
        portalweave_sso_assert(did_action('wp_abilities_api_categories_init') > 0 && did_action('wp_abilities_api_init') > 0, 'Native registration actions did not run');
        portalweave_sso_assert(count($abilities) === 8, 'Eight native tools were not registered');
        $ability = wp_get_ability('portalweave-sso/export-config');
        $result = $ability->execute([]);
        portalweave_sso_assert(is_array($result) && array_key_exists('apps', $result), 'Native ability execute failed: ' . (is_wp_error($result) ? $result->get_error_message() : get_debug_type($result)));
        \PortalWeaveSSO\Config::save(['abilities_enabled' => false]);
        $denied = $ability->execute([]);
        portalweave_sso_assert(is_wp_error($denied), 'A previously registered tool executed after being disabled');
        portalweave_sso_error(\PortalWeaveSSO\Abilities::execute('export-config'), 'portalweave_sso_ability_forbidden');
    } finally {
        wp_set_current_user($current);
        update_option('portalweave_sso_settings', $saved, false);
    }
});
