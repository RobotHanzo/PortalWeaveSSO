<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/report.php';
portalweave_sso_report_bootstrap('multisite');
ob_start();
$_SERVER['HTTP_HOST'] = 'network.example.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
if (!is_multisite() || !in_array(get_network()->site_name, ['PortalWeave SSO Multisite Integration', 'PortalWeave SSO Multisite Integration Sites'], true)) {
    fwrite(STDERR, "Refusing multisite fixtures outside the disposable PortalWeave SSO network.\n"); exit(2);
}
add_filter('pre_wp_mail', static fn() => true);
$portalweave_sso_blog_a = get_main_site_id();
$portalweave_sso_blog_b = domain_exists('second.network.example.test', '/', get_current_network_id());
if (!$portalweave_sso_blog_b) {
    $portalweave_sso_blog_b = wpmu_create_blog('second.network.example.test', '/', 'PortalWeave SSO Second Site', 1, [], get_current_network_id());
}
if (is_wp_error($portalweave_sso_blog_b)) { throw new RuntimeException($portalweave_sso_blog_b->get_error_message()); }
$portalweave_sso_original_network = get_site_option('portalweave_sso_settings', []);
$portalweave_sso_original_sites = [];
foreach ([$portalweave_sso_blog_a, $portalweave_sso_blog_b] as $site) {
    switch_to_blog($site);
    $portalweave_sso_original_sites[$site] = get_option('portalweave_sso_settings', []);
    update_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults(), false);
    restore_current_blog();
}
set_error_handler(static function ($severity, $message, $file, $line) {
    if ((error_reporting() & $severity) && str_contains(str_replace('\\', '/', $file), '/plugins/portalweave-sso/')) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
function portalweave_sso_assert($condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function portalweave_sso_error($result, string $code): void {
    portalweave_sso_assert(is_wp_error($result), 'Expected WP_Error ' . $code);
    portalweave_sso_assert($result->get_error_code() === $code, 'Expected ' . $code . ', got ' . $result->get_error_code());
}
function portalweave_sso_app(array $overrides = []): array {
    return array_replace(\PortalWeaveSSO\Config::app_defaults(), ['id' => 'multi', 'name' => 'Multisite test',
        'client_id' => 'multisite-client', 'issuer' => 'https://example.org',
        'authorize_url' => 'https://example.org/authorize', 'token_url' => 'https://example.org/token',
        'jwks_url' => 'https://example.org/jwks'], $overrides);
}
function portalweave_sso_test(string $name, callable $callback): void {
    global $portalweave_sso_results, $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $started = microtime(true);
    $user = get_current_user_id();
    $network = get_site_option('portalweave_sso_settings', []);
    try {
        $callback();
        $portalweave_sso_results[] = ['pass' => true, 'name' => $name, 'duration' => microtime(true) - $started];
    } catch (Throwable $error) {
        $portalweave_sso_results[] = ['pass' => false, 'name' => $name, 'duration' => microtime(true) - $started, 'error' => $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')'];
    } finally {
        while (ms_is_switched()) { restore_current_blog(); }
        update_site_option('portalweave_sso_settings', $network);
        foreach ([$portalweave_sso_blog_a, $portalweave_sso_blog_b] as $site) {
            switch_to_blog($site);
            update_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults(), false);
            restore_current_blog();
        }
        wp_set_current_user($user);
    }
}

portalweave_sso_test('Multisite activation initializes audit tables for new sites', function () {
    global $wpdb, $portalweave_sso_blog_b;
    switch_to_blog($portalweave_sso_blog_b);
    portalweave_sso_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'portalweave_sso_audit'))) === $wpdb->prefix . 'portalweave_sso_audit', 'New-site audit schema is missing');
    restore_current_blog();
});
portalweave_sso_test('Network-enforced settings apply to every site and reject site overrides', function () {
    global $portalweave_sso_blog_b;
    portalweave_sso_error(\PortalWeaveSSO\Config::save(['protected_media' => [123]], true), 'portalweave_sso_network_content');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['network_enforced' => true, 'apps' => ['multi' => portalweave_sso_app()], 'default_app' => 'multi'], true) === true, 'Network settings save failed');
    switch_to_blog($portalweave_sso_blog_b);
    portalweave_sso_assert(\PortalWeaveSSO\Config::app('multi')['client_id'] === 'multisite-client', 'Enforced provider missing on another site');
    portalweave_sso_error(\PortalWeaveSSO\Config::save(['debug' => true]), 'portalweave_sso_network_enforced');
    restore_current_blog();
});
portalweave_sso_test('Independent networks retain separate enforced provider configuration', function () {
    global $wpdb, $current_site;
    $original_network = $current_site;
    $other_network = 0;
    try {
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['network_enforced' => true, 'apps' => ['multi' => portalweave_sso_app(['name' => 'First network'])], 'default_app' => 'multi'], true) === true, 'First network configuration failed');
        $wpdb->insert($wpdb->site, ['domain' => 'other-' . bin2hex(random_bytes(4)) . '.example.test', 'path' => '/']);
        $other_network = (int) $wpdb->insert_id;
        portalweave_sso_assert($other_network > 0, 'Other network fixture failed');
        $current_site = get_network($other_network);
        portalweave_sso_assert(\PortalWeaveSSO\Config::app('multi') === null, 'An unrelated network inherited the first network provider');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['network_enforced' => true, 'apps' => ['multi' => portalweave_sso_app(['name' => 'Other network'])], 'default_app' => 'multi'], true) === true, 'Other network configuration failed');
        portalweave_sso_assert(\PortalWeaveSSO\Config::app('multi')['name'] === 'Other network', 'Other network configuration did not resolve');
        $current_site = $original_network;
        portalweave_sso_assert(\PortalWeaveSSO\Config::app('multi')['name'] === 'First network', 'Saving another network replaced the first network provider');
    } finally {
        $current_site = $original_network;
        if ($other_network) {
            delete_network_option($other_network, 'portalweave_sso_settings');
            $wpdb->delete($wpdb->site, ['id' => $other_network]);
            wp_cache_delete($other_network, 'networks');
        }
    }
});
portalweave_sso_test('Provider groups remain local while privileged network identities require verified admin policy', function () {
    global $portalweave_sso_blog_a, $portalweave_sso_blog_b;
    $id = wp_insert_user(['user_login' => 'multi_scope_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(40), 'user_email' => 'multi_' . bin2hex(random_bytes(5)) . '@example.org', 'role' => 'subscriber']);
    portalweave_sso_assert(!is_wp_error($id), 'Fixture user failed');
    try {
        add_user_to_blog($portalweave_sso_blog_b, $id, 'subscriber');
        update_user_meta($id, \PortalWeaveSSO\Accounts::groups_meta_key(), ['staff']);
        $user = new WP_User($id, '', $portalweave_sso_blog_a);
        portalweave_sso_assert(\PortalWeaveSSO\Access::allows(['groups' => ['staff']], $user), 'Local group access failed');
        switch_to_blog($portalweave_sso_blog_b);
        portalweave_sso_assert(!\PortalWeaveSSO\Access::allows(['groups' => ['staff']], new WP_User($id, '', $portalweave_sso_blog_b)), 'A provider group escaped its site');
        restore_current_blog();
        add_user_to_blog($portalweave_sso_blog_b, $id, 'administrator');
        $user = new WP_User($id, '', $portalweave_sso_blog_a);
        portalweave_sso_assert(\PortalWeaveSSO\Accounts::is_privileged($user), 'An administrator on another site was treated as an unprivileged global identity');
        portalweave_sso_assert(!\PortalWeaveSSO\Access::allows(['groups' => ['private']], $user), 'Other-site administrator bypassed local content policy');
        $attrs = ['sub' => 'subject-' . $id, 'email' => $user->user_email, 'email_verified' => true, 'preferred_username' => $user->user_login];
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_app(['link_existing' => true]), $attrs), 'portalweave_sso_admin_disabled');
    } finally {
        while (ms_is_switched()) { restore_current_blog(); }
        wpmu_delete_user($id);
    }
});
foreach (['multisite-auth', 'multisite-extensions', 'compliance-multisite', 'admin-settings', 'provider-setup'] as $suite) {
    if (is_file(__DIR__ . '/' . $suite . '.php')) { require __DIR__ . '/' . $suite . '.php'; }
    else { $portalweave_sso_results[] = ['pass' => false, 'name' => $suite, 'error' => 'Missing test suite']; }
}
update_site_option('portalweave_sso_settings', $portalweave_sso_original_network);
foreach ($portalweave_sso_original_sites as $site => $settings) {
    switch_to_blog($site); update_option('portalweave_sso_settings', $settings, false); restore_current_blog();
}
restore_error_handler();
$noise = ob_get_clean();
exit(portalweave_sso_report_finish('multisite', $portalweave_sso_results, $noise, 'multisite tests'));
