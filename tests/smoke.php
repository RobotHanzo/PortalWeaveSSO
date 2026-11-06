<?php
wpsso_test('Plugin activates its public hooks and renders provider buttons safely', function () {
    $app = wpsso_app(['name' => '<img src=x onerror=alert(1)>']);
    wpsso_assert(\WPSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture']) === true, 'Fixture save failed');
    wp_set_current_user(0);
    $html = do_shortcode('[wpsso_oauth_login app="fixture" redirect="/origin/"]');
    wpsso_assert(str_contains($html, 'wpsso-button') && str_contains($html, 'redirect_to='), 'Shortcode missing login link');
    wpsso_assert(!str_contains($html, 'onerror=') && !str_contains($html, 'fixture-client-secret'), 'Untrusted markup or credentials leaked');
    wpsso_assert(has_action('woocommerce_login_form_end', [\WPSSO\Display::class, 'login_buttons']) !== false, 'WooCommerce hook missing');
    wpsso_assert(has_action('widgets_init') !== false, 'Widget hook missing');
});

wpsso_test('Administrator task screens and provider editor never reveal stored secrets', function () {
    $app = wpsso_app();
    wpsso_assert(\WPSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture']) === true, 'Fixture save failed');
    $admin = get_user_by('login', 'wpsso_test_admin');
    wp_set_current_user($admin->ID);
    $original_get = $_GET;
    try {
        foreach (['overview', 'providers', 'login', 'access', 'appearance', 'modules', 'reports', 'tools', 'provider-editor'] as $screen) {
            $_GET = ['page' => 'wpsso', 'tab' => $screen === 'provider-editor' ? 'providers' : $screen];
            if ($screen === 'provider-editor') {
                $_GET['provider'] = 'fixture';
            }
            ob_start();
            \WPSSO\Admin::page();
            $html = ob_get_clean();
            wpsso_assert(str_contains($html, 'PortalWeave SSO'), 'Admin page missing title');
            wpsso_assert(!str_contains($html, $app['client_secret']), 'Stored secret rendered');
        }
    } finally { $_GET = $original_get; }
});

wpsso_test('Audit retains useful metadata without query tokens and supports privacy erasure', function () {
    $id = wp_insert_user(['user_login' => 'wpsso_audit_' . wp_generate_password(8, false), 'user_pass' => 'test', 'user_email' => 'audit-fixture@example.org']);
    wpsso_assert(!is_wp_error($id), 'Fixture account failed');
    try {
        \WPSSO\Audit::record('success', 'fixture', get_userdata($id), '', 'https://example.org/path?access_token=secret#fragment');
        $rows = \WPSSO\Audit::recent(['user_id' => $id]);
        wpsso_assert(count($rows) >= 1 && $rows[0]['navigation'] === 'https://example.org/path', 'Audit leaked a query token');
        $export = \WPSSO\Display::exporter([])['wpsso']['callback'];
        wpsso_assert(count($export('audit-fixture@example.org')['data']) >= 1, 'Privacy exporter missed rows');
        $erase = \WPSSO\Display::eraser([])['wpsso']['callback'];
        wpsso_assert($erase('audit-fixture@example.org')['items_removed'] === true, 'Audit personal data not erased');
        wpsso_assert(\WPSSO\Audit::recent(['user_id' => $id]) === [], 'Identifiable audit row remains');
    } finally { wp_delete_user($id); }
});

wpsso_test('Protected core posts cannot leak through REST collections, revisions or oEmbed', function () {
    $post = wp_insert_post(['post_title' => 'SSO private embed', 'post_content' => 'Protected content', 'post_status' => 'publish']);
    wpsso_assert($post > 0, 'Fixture post failed');
    try {
        update_post_meta($post, '_wpsso_rule', ['login' => true]);
        wp_set_current_user(0);
        wpsso_assert(apply_filters('oembed_response_data', ['title' => 'Private'], get_post($post), 600, 400) === false, 'oEmbed exposed a protected post');
        $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/' . $post));
        wpsso_assert($response->get_status() === 401, 'REST individual read did not reject anonymous access');
        $request = new WP_REST_Request('GET', '/wp/v2/posts/' . $post . '/revisions');
        wpsso_error(\WPSSO\Access::rest_content(null, rest_get_server(), $request), 'wpsso_content_denied');
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_param('include', [$post]);
        $response = rest_do_request($request);
        wpsso_assert($response->get_status() === 200 && $response->get_data() === [], 'REST collection exposed restricted content');
        $category = new WP_REST_Request('GET', '/wp/v2/categories/' . $post);
        $category->set_param('id', $post);
        wpsso_assert(\WPSSO\Access::rest_content(null, rest_get_server(), $category) === null, 'Post rules were incorrectly applied to a category ID');
    } finally { wp_delete_post($post, true); }
});

wpsso_test('Custom plugin-management roles receive administrator SSO protection', function () {
    add_role('wpsso_installer_fixture', 'Installer fixture', ['read' => true, 'install_plugins' => true]);
    $id = wp_insert_user(['user_login' => 'wpsso_installer_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(40), 'user_email' => 'installer_' . bin2hex(random_bytes(5)) . '@example.org', 'role' => 'wpsso_installer_fixture']);
    wpsso_assert(!is_wp_error($id), 'Fixture user failed');
    try {
        $user = get_userdata($id);
        wpsso_assert(\WPSSO\Accounts::is_privileged($user), 'Custom installation capability escaped privilege detection');
        $attrs = ['sub' => 'custom-admin-' . $id, 'email' => $user->user_email, 'email_verified' => true];
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_app(['link_existing' => true]), $attrs), 'wpsso_admin_disabled');
    } finally { wp_delete_user($id); remove_role('wpsso_installer_fixture'); }
});

wpsso_test('Global media protection cannot be removed through an individual checkbox', function () {
    $upload = wp_upload_bits('wpsso-global-' . bin2hex(random_bytes(4)) . '.txt', null, 'Private global media');
    wpsso_assert(empty($upload['error']), 'Upload failed');
    $id = wp_insert_attachment(['post_title' => 'Global protection fixture', 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
    try {
        wpsso_assert(\WPSSO\Config::save(['protected_media' => [$id]]) === true, 'Global media protection failed');
        update_post_meta($id, '_wpsso_protected', 1);
        update_post_meta($id, '_wpsso_protected', 0);
        wpsso_assert(!file_exists($upload['file']) && get_post_meta($id, '_wpsso_protected', true), 'Checkbox exposed a globally protected file');
        delete_transient('wpsso_media_error_' . get_current_user_id());
        \WPSSO\Config::save(['protected_media' => []]);
        update_post_meta($id, '_wpsso_protected', 0);
        wpsso_assert(file_exists($upload['file']), 'Explicitly removed protection failed to restore media');
    } finally { wp_delete_attachment($id, true); }
});

wpsso_test('Plugin lifecycle cleanup runs on single-site WordPress', function () {
    do_action('deactivate_' . plugin_basename(PORTALWEAVE_SSO_FILE), false);
    wpsso_assert(wp_next_scheduled('wpsso_cleanup') === false, 'Audit cleanup remained scheduled');
    wpsso_assert(wp_next_scheduled('wpsso_state_cleanup') === false, 'State cleanup remained scheduled');
    wpsso_assert(wp_next_scheduled('wpsso_extension_cleanup') === false, 'Extension cleanup remained scheduled');
    \WPSSO\Audit::install();
    \WPSSO\Auth::register();
    \WPSSO\Extensions::register();
});
