<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/report.php';
wpsso_report_bootstrap('multisite');
ob_start();
$_SERVER['HTTP_HOST'] = 'network.example.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
if (!is_multisite() || !in_array(get_network()->site_name, ['WPSSO Multisite Integration', 'WPSSO Multisite Integration Sites'], true)) {
    fwrite(STDERR, "Refusing multisite fixtures outside the disposable WPSSO network.\n"); exit(2);
}
add_filter('pre_wp_mail', static fn() => true);
$wpsso_blog_a = get_main_site_id();
$wpsso_blog_b = domain_exists('second.network.example.test', '/', get_current_network_id());
if (!$wpsso_blog_b) {
    $wpsso_blog_b = wpmu_create_blog('second.network.example.test', '/', 'WPSSO Second Site', 1, [], get_current_network_id());
}
if (is_wp_error($wpsso_blog_b)) { throw new RuntimeException($wpsso_blog_b->get_error_message()); }
$wpsso_original_network = get_site_option('wpsso_settings', []);
$wpsso_original_sites = [];
foreach ([$wpsso_blog_a, $wpsso_blog_b] as $site) {
    switch_to_blog($site);
    $wpsso_original_sites[$site] = get_option('wpsso_settings', []);
    update_option('wpsso_settings', \WPSSO\Config::defaults(), false);
    restore_current_blog();
}
set_error_handler(static function ($severity, $message, $file, $line) {
    if ((error_reporting() & $severity) && str_contains(str_replace('\\', '/', $file), '/plugins/wordpress-sso/')) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
function wpsso_assert($condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function wpsso_error($result, string $code): void {
    wpsso_assert(is_wp_error($result), 'Expected WP_Error ' . $code);
    wpsso_assert($result->get_error_code() === $code, 'Expected ' . $code . ', got ' . $result->get_error_code());
}
function wpsso_app(array $overrides = []): array {
    return array_replace(\WPSSO\Config::app_defaults(), ['id' => 'multi', 'name' => 'Multisite test',
        'client_id' => 'multisite-client', 'issuer' => 'https://example.org',
        'authorize_url' => 'https://example.org/authorize', 'token_url' => 'https://example.org/token',
        'jwks_url' => 'https://example.org/jwks'], $overrides);
}
function wpsso_test(string $name, callable $callback): void {
    global $wpsso_results, $wpsso_blog_a, $wpsso_blog_b;
    $started = microtime(true);
    $user = get_current_user_id();
    $network = get_site_option('wpsso_settings', []);
    try {
        $callback();
        $wpsso_results[] = ['pass' => true, 'name' => $name, 'duration' => microtime(true) - $started];
    } catch (Throwable $error) {
        $wpsso_results[] = ['pass' => false, 'name' => $name, 'duration' => microtime(true) - $started, 'error' => $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')'];
    } finally {
        while (ms_is_switched()) { restore_current_blog(); }
        update_site_option('wpsso_settings', $network);
        foreach ([$wpsso_blog_a, $wpsso_blog_b] as $site) {
            switch_to_blog($site);
            update_option('wpsso_settings', \WPSSO\Config::defaults(), false);
            restore_current_blog();
        }
        wp_set_current_user($user);
    }
}

wpsso_test('Multisite activation initializes audit tables for new sites', function () {
    global $wpdb, $wpsso_blog_b;
    switch_to_blog($wpsso_blog_b);
    wpsso_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'wpsso_audit'))) === $wpdb->prefix . 'wpsso_audit', 'New-site audit schema is missing');
    restore_current_blog();
});
wpsso_test('Network-enforced settings apply to every site and reject site overrides', function () {
    global $wpsso_blog_b;
    wpsso_error(\WPSSO\Config::save(['protected_media' => [123]], true), 'wpsso_network_content');
    wpsso_assert(\WPSSO\Config::save(['network_enforced' => true, 'apps' => ['multi' => wpsso_app()], 'default_app' => 'multi'], true) === true, 'Network settings save failed');
    switch_to_blog($wpsso_blog_b);
    wpsso_assert(\WPSSO\Config::app('multi')['client_id'] === 'multisite-client', 'Enforced provider missing on another site');
    wpsso_error(\WPSSO\Config::save(['debug' => true]), 'wpsso_network_enforced');
    restore_current_blog();
});
wpsso_test('Independent networks retain separate enforced provider configuration', function () {
    global $wpdb, $current_site;
    $original_network = $current_site;
    $other_network = 0;
    try {
        wpsso_assert(\WPSSO\Config::save(['network_enforced' => true, 'apps' => ['multi' => wpsso_app(['name' => 'First network'])], 'default_app' => 'multi'], true) === true, 'First network configuration failed');
        $wpdb->insert($wpdb->site, ['domain' => 'other-' . bin2hex(random_bytes(4)) . '.example.test', 'path' => '/']);
        $other_network = (int) $wpdb->insert_id;
        wpsso_assert($other_network > 0, 'Other network fixture failed');
        $current_site = get_network($other_network);
        wpsso_assert(\WPSSO\Config::app('multi') === null, 'An unrelated network inherited the first network provider');
        wpsso_assert(\WPSSO\Config::save(['network_enforced' => true, 'apps' => ['multi' => wpsso_app(['name' => 'Other network'])], 'default_app' => 'multi'], true) === true, 'Other network configuration failed');
        wpsso_assert(\WPSSO\Config::app('multi')['name'] === 'Other network', 'Other network configuration did not resolve');
        $current_site = $original_network;
        wpsso_assert(\WPSSO\Config::app('multi')['name'] === 'First network', 'Saving another network replaced the first network provider');
    } finally {
        $current_site = $original_network;
        if ($other_network) {
            delete_network_option($other_network, 'wpsso_settings');
            $wpdb->delete($wpdb->site, ['id' => $other_network]);
            wp_cache_delete($other_network, 'networks');
        }
    }
});
wpsso_test('Provider groups remain local while privileged network identities require verified admin policy', function () {
    global $wpsso_blog_a, $wpsso_blog_b;
    $id = wp_insert_user(['user_login' => 'multi_scope_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(40), 'user_email' => 'multi_' . bin2hex(random_bytes(5)) . '@example.org', 'role' => 'subscriber']);
    wpsso_assert(!is_wp_error($id), 'Fixture user failed');
    try {
        add_user_to_blog($wpsso_blog_b, $id, 'subscriber');
        update_user_meta($id, \WPSSO\Accounts::groups_meta_key(), ['staff']);
        $user = new WP_User($id, '', $wpsso_blog_a);
        wpsso_assert(\WPSSO\Access::allows(['groups' => ['staff']], $user), 'Local group access failed');
        switch_to_blog($wpsso_blog_b);
        wpsso_assert(!\WPSSO\Access::allows(['groups' => ['staff']], new WP_User($id, '', $wpsso_blog_b)), 'A provider group escaped its site');
        restore_current_blog();
        add_user_to_blog($wpsso_blog_b, $id, 'administrator');
        $user = new WP_User($id, '', $wpsso_blog_a);
        wpsso_assert(\WPSSO\Accounts::is_privileged($user), 'An administrator on another site was treated as an unprivileged global identity');
        wpsso_assert(!\WPSSO\Access::allows(['groups' => ['private']], $user), 'Other-site administrator bypassed local content policy');
        $attrs = ['sub' => 'subject-' . $id, 'email' => $user->user_email, 'email_verified' => true, 'preferred_username' => $user->user_login];
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_app(['link_existing' => true]), $attrs), 'wpsso_admin_disabled');
    } finally {
        while (ms_is_switched()) { restore_current_blog(); }
        wpmu_delete_user($id);
    }
});
foreach (['multisite-auth', 'multisite-extensions', 'compliance-multisite'] as $suite) {
    if (is_file(__DIR__ . '/' . $suite . '.php')) { require __DIR__ . '/' . $suite . '.php'; }
    else { $wpsso_results[] = ['pass' => false, 'name' => $suite, 'error' => 'Missing test suite']; }
}
update_site_option('wpsso_settings', $wpsso_original_network);
foreach ($wpsso_original_sites as $site => $settings) {
    switch_to_blog($site); update_option('wpsso_settings', $settings, false); restore_current_blog();
}
restore_error_handler();
$noise = ob_get_clean();
exit(wpsso_report_finish('multisite', $wpsso_results, $noise, 'multisite tests'));
