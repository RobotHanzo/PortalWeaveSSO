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

wpsso_test('Administrator settings render all panels without revealing stored credentials', function () {
    $app = wpsso_app();
    wpsso_assert(\WPSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture']) === true, 'Fixture save failed');
    $admin = get_user_by('login', 'wpsso_test_admin');
    wp_set_current_user($admin->ID);
    $original_get = $_GET;
    try {
        foreach (['wpsso'] as $page) {
            $_GET = ['page' => $page];
            ob_start();
            \WPSSO\Admin::page();
            $html = ob_get_clean();
            wpsso_assert(str_contains($html, 'WordPress SSO'), 'Admin page missing title');
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
