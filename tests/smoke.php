<?php
portalweave_sso_test('Plugin activates its public hooks and renders provider buttons safely', function () {
    $app = portalweave_sso_app(['name' => '<img src=x onerror=alert(1)>']);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture']) === true, 'Fixture save failed');
    wp_set_current_user(0);
    $html = do_shortcode('[portalweave_sso_login app="fixture" redirect="/origin/"]');
    portalweave_sso_assert(str_contains($html, 'portalweave-sso-button') && str_contains($html, 'redirect_to='), 'Shortcode missing login link');
    portalweave_sso_assert(!str_contains($html, 'onerror=') && !str_contains($html, 'fixture-client-secret'), 'Untrusted markup or credentials leaked');
    portalweave_sso_assert(has_action('woocommerce_login_form_end', [\PortalWeaveSSO\Display::class, 'login_buttons']) !== false, 'WooCommerce hook missing');
    portalweave_sso_assert(has_action('widgets_init') !== false, 'Widget hook missing');
});

portalweave_sso_test('Administrator task screens and provider editor never reveal stored secrets', function () {
    $app = portalweave_sso_app();
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture']) === true, 'Fixture save failed');
    $admin = get_user_by('login', 'portalweave_sso_test_admin');
    wp_set_current_user($admin->ID);
    $original_get = $_GET;
    try {
        foreach (['overview', 'providers', 'login', 'access', 'appearance', 'modules', 'reports', 'tools', 'provider-editor'] as $screen) {
            $_GET = ['page' => 'portalweave-sso', 'tab' => $screen === 'provider-editor' ? 'providers' : $screen];
            if ($screen === 'provider-editor') {
                $_GET['provider'] = 'fixture';
            }
            ob_start();
            \PortalWeaveSSO\Admin::page();
            $html = ob_get_clean();
            portalweave_sso_assert(str_contains($html, 'PortalWeave SSO'), 'Admin page missing title');
            portalweave_sso_assert(!str_contains($html, $app['client_secret']), 'Stored secret rendered');
        }
    } finally { $_GET = $original_get; }
});

portalweave_sso_test('Audit retains useful metadata without query tokens and supports privacy erasure', function () {
    $id = wp_insert_user(['user_login' => 'portalweave_sso_audit_' . wp_generate_password(8, false), 'user_pass' => 'test', 'user_email' => 'audit-fixture@example.org']);
    portalweave_sso_assert(!is_wp_error($id), 'Fixture account failed');
    try {
        \PortalWeaveSSO\Audit::record('success', 'fixture', get_userdata($id), '', 'https://example.org/path?access_token=secret#fragment');
        $rows = \PortalWeaveSSO\Audit::recent(['user_id' => $id]);
        portalweave_sso_assert(count($rows) >= 1 && $rows[0]['navigation'] === 'https://example.org/path', 'Audit leaked a query token');
        $export = \PortalWeaveSSO\Display::exporter([])['portalweave-sso']['callback'];
        portalweave_sso_assert(count($export('audit-fixture@example.org')['data']) >= 1, 'Privacy exporter missed rows');
        $erase = \PortalWeaveSSO\Display::eraser([])['portalweave-sso']['callback'];
        portalweave_sso_assert($erase('audit-fixture@example.org')['items_removed'] === true, 'Audit personal data not erased');
        portalweave_sso_assert(\PortalWeaveSSO\Audit::recent(['user_id' => $id]) === [], 'Identifiable audit row remains');
    } finally { wp_delete_user($id); }
});

portalweave_sso_test('Protected core posts cannot leak through REST collections, revisions or oEmbed', function () {
    $post = wp_insert_post(['post_title' => 'SSO private embed', 'post_content' => 'Protected content', 'post_status' => 'publish']);
    portalweave_sso_assert($post > 0, 'Fixture post failed');
    try {
        update_post_meta($post, '_portalweave_sso_rule', ['login' => true]);
        wp_set_current_user(0);
        portalweave_sso_assert(apply_filters('oembed_response_data', ['title' => 'Private'], get_post($post), 600, 400) === false, 'oEmbed exposed a protected post');
        $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/' . $post));
        portalweave_sso_assert($response->get_status() === 401, 'REST individual read did not reject anonymous access');
        $request = new WP_REST_Request('GET', '/wp/v2/posts/' . $post . '/revisions');
        portalweave_sso_error(\PortalWeaveSSO\Access::rest_content(null, rest_get_server(), $request), 'portalweave_sso_content_denied');
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_param('include', [$post]);
        $response = rest_do_request($request);
        portalweave_sso_assert($response->get_status() === 200 && $response->get_data() === [], 'REST collection exposed restricted content');
        $category = new WP_REST_Request('GET', '/wp/v2/categories/' . $post);
        $category->set_param('id', $post);
        portalweave_sso_assert(\PortalWeaveSSO\Access::rest_content(null, rest_get_server(), $category) === null, 'Post rules were incorrectly applied to a category ID');
    } finally { wp_delete_post($post, true); }
});

foreach (['post' => 'wp/v2/posts', 'page' => 'wp/v2/pages', 'pw_sso_case_fixture' => 'portalweave-fixture/v1/case-records'] as $post_type => $rest_path) {
    portalweave_sso_test('REST content rules follow case-insensitive WordPress routes for ' . $post_type, function () use ($post_type, $rest_path) {
        $posts = [];
        $users = [];
        $custom = $post_type === 'pw_sso_case_fixture';
        $original_rest_server = rest_get_server();
        try {
            if ($custom) {
                $GLOBALS['wp_rest_server'] = clone $original_rest_server;
                register_post_type($post_type, ['public' => true, 'show_in_rest' => true, 'rest_namespace' => 'portalweave-fixture/v1', 'rest_base' => 'case-records', 'supports' => ['title', 'editor']]);
                get_post_type_object($post_type)->get_rest_controller()->register_routes();
            }
            foreach (['Protected route fixture', 'Public route fixture'] as $title) {
                $posts[] = wp_insert_post(['post_type' => $post_type, 'post_title' => $title, 'post_content' => $title . ' body', 'post_status' => 'publish']);
                portalweave_sso_assert(end($posts) > 0, 'REST route fixture post failed');
            }
            foreach (['subscriber', 'editor'] as $role) {
                $id = wp_insert_user(['user_login' => 'portalweave_sso_route_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(40), 'role' => $role]);
                portalweave_sso_assert(!is_wp_error($id), 'REST route fixture user failed');
                $users[$role] = $id;
            }
            update_user_meta($users['editor'], \PortalWeaveSSO\Accounts::groups_meta_key(), ['route-members']);
            [$namespace, $base] = [substr($rest_path, 0, strrpos($rest_path, '/')), substr($rest_path, strrpos($rest_path, '/') + 1)];
            $routes = ['/' . $rest_path, '/' . strtoupper($namespace) . '/' . $base, '/' . $namespace . '/' . strtoupper($base), '/' . strtoupper($rest_path)];
            $policies = [
                [['login' => true], [0 => 401, $users['subscriber'] => 200]],
                [['roles' => ['editor']], [0 => 401, $users['subscriber'] => 403, $users['editor'] => 200]],
                [['groups' => ['route-members']], [0 => 401, $users['subscriber'] => 403, $users['editor'] => 200]],
            ];
            foreach ($policies as [$rule, $statuses]) {
                update_post_meta($posts[0], '_portalweave_sso_rule', $rule);
                foreach ($statuses as $user_id => $status) {
                    wp_set_current_user($user_id);
                    foreach ($routes as $route) {
                        foreach (['GET', 'HEAD'] as $method) {
                            $response = rest_do_request(new WP_REST_Request($method, $route . '/' . $posts[0]));
                            portalweave_sso_assert($response->get_status() === $status, $method . ' ' . $route . ' expected ' . $status . ', got ' . $response->get_status());
                            $data = $response->get_data();
                            portalweave_sso_assert($status === 200 ? ($method === 'HEAD' || (int) $data['id'] === $posts[0]) : $data['code'] === 'portalweave_sso_content_denied', 'REST route returned an unexpected post or denial');
                        }
                        $public = rest_do_request(new WP_REST_Request('GET', $route . '/' . $posts[1]));
                        portalweave_sso_assert($public->get_status() === 200 && (int) $public->get_data()['id'] === $posts[1], 'REST route blocked public content');
                        $request = new WP_REST_Request('GET', $route);
                        $request->set_param('include', $posts);
                        $response = rest_do_request($request);
                        $ids = array_map('intval', array_column($response->get_data(), 'id'));
                        sort($ids);
                        $expected = $status === 200 ? $posts : [$posts[1]];
                        sort($expected);
                        portalweave_sso_assert($response->get_status() === 200 && $ids === $expected, 'REST collection did not preserve the content access policy');
                    }
                }
            }
            update_post_meta($posts[0], '_portalweave_sso_rule', ['login' => true]);
            wp_set_current_user(0);
            foreach ($routes as $route) {
                foreach (['query', 'json'] as $source) {
                    $request = new WP_REST_Request('GET', $route . '/' . $posts[1]);
                    if ($source === 'query') {
                        $request->set_query_params(['id' => $posts[0]]);
                    } else {
                        $request->set_header('Content-Type', 'application/json');
                        $request->set_body(wp_json_encode(['id' => $posts[0]]));
                    }
                    $response = rest_do_request($request);
                    portalweave_sso_assert($response->get_status() === 401, 'REST ' . $source . ' id override exposed restricted content');
                    $request = new WP_REST_Request('GET', $route . '/' . $posts[0]);
                    $request->set_query_params(['id' => $posts[1]]);
                    $response = rest_do_request($request);
                    portalweave_sso_assert($response->get_status() === 200 && (int) $response->get_data()['id'] === $posts[1], 'REST rejected an effective public object');
                }
            }
        } finally {
            wp_set_current_user(0);
            foreach ($posts as $id) { wp_delete_post($id, true); }
            foreach ($users as $id) { wp_delete_user($id); }
            if ($custom) {
                unregister_post_type($post_type);
                $GLOBALS['wp_rest_server'] = $original_rest_server;
            }
        }
    });
}

portalweave_sso_test('REST revision and autosave policies follow validated parent and item overrides', function () {
    $posts = [];
    $id = wp_insert_user(['user_login' => 'portalweave_sso_nested_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(40), 'role' => 'editor']);
    portalweave_sso_assert(!is_wp_error($id), 'Nested REST user fixture failed');
    try {
        foreach (['Protected nested fixture', 'Public nested fixture'] as $title) {
            $posts[] = wp_insert_post(['post_title' => $title, 'post_content' => $title . ' body', 'post_status' => 'publish']);
        }
        $revisions = [];
        foreach ($posts as $post) {
            $revisions[$post] = wp_insert_post(['post_type' => 'revision', 'post_parent' => $post, 'post_status' => 'inherit', 'post_name' => $post . '-autosave-v1', 'post_title' => 'Nested revision', 'post_content' => 'Revision for ' . $post]);
            portalweave_sso_assert($revisions[$post] > 0, 'Revision fixture failed');
        }
        update_post_meta($posts[0], '_portalweave_sso_rule', ['groups' => ['nested-members']]);
        wp_set_current_user($id);
        foreach (['revisions' => 'parent', 'autosaves' => 'id'] as $resource => $parameter) {
            foreach (['/wp/v2/posts', '/WP/V2/POSTS'] as $route) {
                $request = new WP_REST_Request('GET', $route . '/' . $posts[1] . '/' . $resource);
                $request->set_query_params([$parameter => $posts[0]]);
                $response = rest_do_request($request);
                portalweave_sso_assert($response->get_status() === 403 && $response->get_data()['code'] === 'portalweave_sso_content_denied', 'REST exposed a restricted effective revision/autosave parent');
                update_user_meta($id, \PortalWeaveSSO\Accounts::groups_meta_key(), ['nested-members']);
                $response = rest_do_request($request);
                portalweave_sso_assert($response->get_status() === 200 && in_array($revisions[$posts[0]], array_column($response->get_data(), 'id'), true), 'Permitted revision/autosave collection failed');
                delete_user_meta($id, \PortalWeaveSSO\Accounts::groups_meta_key());
                $request = new WP_REST_Request('GET', $route . '/' . $posts[0] . '/' . $resource);
                $request->set_query_params([$parameter => $posts[1]]);
                portalweave_sso_assert(rest_do_request($request)->get_status() === 200, 'REST rejected an effective public revision/autosave parent');
                $request = new WP_REST_Request('GET', $route . '/' . $posts[1] . '/' . $resource . '/' . $revisions[$posts[1]]);
                $request->set_query_params(['parent' => $posts[0], 'id' => $revisions[$posts[0]]]);
                portalweave_sso_assert(rest_do_request($request)->get_status() === 403, 'REST exposed a restricted individual revision/autosave');
            }
        }
    } finally {
        foreach ($posts as $post) { wp_delete_post($post, true); }
        wp_delete_user($id);
    }
});

portalweave_sso_test('Custom plugin-management roles receive administrator SSO protection', function () {
    add_role('portalweave_sso_installer_fixture', 'Installer fixture', ['read' => true, 'install_plugins' => true]);
    $id = wp_insert_user(['user_login' => 'portalweave_sso_installer_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(40), 'user_email' => 'installer_' . bin2hex(random_bytes(5)) . '@example.org', 'role' => 'portalweave_sso_installer_fixture']);
    portalweave_sso_assert(!is_wp_error($id), 'Fixture user failed');
    try {
        $user = get_userdata($id);
        portalweave_sso_assert(\PortalWeaveSSO\Accounts::is_privileged($user), 'Custom installation capability escaped privilege detection');
        $attrs = ['sub' => 'custom-admin-' . $id, 'email' => $user->user_email, 'email_verified' => true];
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_app(['link_existing' => true]), $attrs), 'portalweave_sso_admin_disabled');
    } finally { wp_delete_user($id); remove_role('portalweave_sso_installer_fixture'); }
});

portalweave_sso_test('Global media protection cannot be removed through an individual checkbox', function () {
    $upload = wp_upload_bits('portalweave-sso-global-' . bin2hex(random_bytes(4)) . '.txt', null, 'Private global media');
    portalweave_sso_assert(empty($upload['error']), 'Upload failed');
    $id = wp_insert_attachment(['post_title' => 'Global protection fixture', 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
    try {
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['protected_media' => [$id]]) === true, 'Global media protection failed');
        update_post_meta($id, '_portalweave_sso_protected', 1);
        update_post_meta($id, '_portalweave_sso_protected', 0);
        portalweave_sso_assert(!file_exists($upload['file']) && get_post_meta($id, '_portalweave_sso_protected', true), 'Checkbox exposed a globally protected file');
        delete_transient('portalweave_sso_media_error_' . get_current_user_id());
        \PortalWeaveSSO\Config::save(['protected_media' => []]);
        update_post_meta($id, '_portalweave_sso_protected', 0);
        portalweave_sso_assert(file_exists($upload['file']), 'Explicitly removed protection failed to restore media');
    } finally { wp_delete_attachment($id, true); }
});

portalweave_sso_test('Plugin lifecycle cleanup runs on single-site WordPress', function () {
    do_action('deactivate_' . plugin_basename(PORTALWEAVE_SSO_FILE), false);
    portalweave_sso_assert(wp_next_scheduled('portalweave_sso_cleanup') === false, 'Audit cleanup remained scheduled');
    portalweave_sso_assert(wp_next_scheduled('portalweave_sso_state_cleanup') === false, 'State cleanup remained scheduled');
    portalweave_sso_assert(wp_next_scheduled('portalweave_sso_extension_cleanup') === false, 'Extension cleanup remained scheduled');
    \PortalWeaveSSO\Audit::install();
    \PortalWeaveSSO\Auth::register();
    \PortalWeaveSSO\Extensions::register();
});
