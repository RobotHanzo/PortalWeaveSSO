<?php
/** Configure only the disposable HTTPS integration environment. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__, 4) . '/wp-load.php';
if (get_option('blogname') !== 'PortalWeave SSO Integration' || !defined('PORTALWEAVE_SSO_TEST_IDP') || PORTALWEAVE_SSO_TEST_IDP !== true) {
    fwrite(STDERR, "Refusing E2E fixture outside the enabled integration test site.\n");
    exit(2);
}
if (in_array('--restore', $argv, true)) {
    $original = get_option('portalweave_sso_e2e_original');
    if (!is_array($original)) { fwrite(STDERR, "No saved E2E baseline exists.\n"); exit(1); }
    update_option('portalweave_sso_settings', $original['settings'], false);
    update_option('home', $original['home']);
    update_option('siteurl', $original['siteurl']);
    delete_option('portalweave_sso_e2e_original');
    echo "Original integration settings and URLs restored.\n";
    exit;
}
if (!get_option('portalweave_sso_e2e_original')) {
    add_option('portalweave_sso_e2e_original', ['settings' => get_option('portalweave_sso_settings', []), 'home' => get_option('home'), 'siteurl' => get_option('siteurl')], '', false);
}
$origin = 'https://localhost:8448';
update_option('home', $origin);
update_option('siteurl', $origin);
$app = array_replace(\PortalWeaveSSO\Config::app_defaults(), [
    'id' => 'e2e', 'name' => 'Local test IdP', 'provider' => 'custom', 'enabled' => true,
    'protocol' => 'oidc', 'client_id' => 'portalweave-sso-e2e-client', 'client_secret' => '',
    'credential_method' => 'none', 'grant' => 'authorization_code', 'pkce' => true,
    'authorize_url' => 'https://example.org/portalweave-sso-test-authorize', 'token_url' => 'https://example.org/portalweave-sso-test-token',
    'userinfo_url' => 'https://example.org/portalweave-sso-test-userinfo', 'issuer' => 'https://example.org/portalweave-sso-test',
    'jwks_url' => '', 'algorithms' => ['HS256'], 'jwt_secret' => 'portalweave-sso-local-integration-signing-key-only-64-characters-long-fixture',
    'auto_register' => true, 'link_existing' => false, 'require_verified_email' => true,
]);
$logout_app = array_replace($app, ['id' => 'e2e-logout', 'name' => 'Northstar Identity', 'logout_url' => 'https://example.org/portalweave-sso-test-logout']);
$result = \PortalWeaveSSO\Config::save(array_replace(\PortalWeaveSSO\Config::defaults(), ['apps' => ['e2e' => $app, 'e2e-logout' => $logout_app], 'default_app' => 'e2e']));
if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message() . "\n"); exit(1); }
$username = 'portalweave_sso_e2e_admin';
$id = username_exists($username);
$data = ['user_login' => $username, 'user_pass' => 'Local-E2E-Fixture-Password-Only-2026!', 'user_email' => 'portalweave_sso_e2e_admin@example.org', 'role' => 'administrator'];
if ($id) { $data['ID'] = $id; $id = wp_update_user($data); } else { $id = wp_insert_user($data); }
if (is_wp_error($id)) { fwrite(STDERR, $id->get_error_message() . "\n"); exit(1); }
echo "Disposable HTTPS IdP fixture ready at https://localhost:8448.\n";
