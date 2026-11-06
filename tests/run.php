<?php
/** Run only against the disposable compose.test.yml WordPress database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/report.php';
portalweave_sso_report_bootstrap('integration');
ob_start();
define('PORTALWEAVE_SSO_PRIVATE_MEDIA_DIR', '/tmp/portalweave-sso-private-tests');
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
if (get_option('blogname') !== 'PortalWeave SSO Integration') {
    fwrite(STDERR, "Refusing to run destructive integration fixtures outside the PortalWeave SSO Integration test site.\n");
    exit(2);
}
$portalweave_sso_original_settings = get_option('portalweave_sso_settings', []);
$portalweave_sso_runtime_path = wp_normalize_path(dirname(PORTALWEAVE_SSO_FILE)) . '/';
set_error_handler(static function ($severity, $message, $file, $line) use ($portalweave_sso_runtime_path) {
    $file = wp_normalize_path($file);
    if ((error_reporting() & $severity) && (str_starts_with($file, $portalweave_sso_runtime_path) || str_starts_with($file, wp_normalize_path(__DIR__) . '/'))) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

function portalweave_sso_assert($condition, string $message = 'Assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function portalweave_sso_error($result, string $code): void {
    portalweave_sso_assert(is_wp_error($result), 'Expected WP_Error ' . $code . ', got ' . get_debug_type($result));
    portalweave_sso_assert($result->get_error_code() === $code, 'Expected ' . $code . ', got ' . $result->get_error_code() . ': ' . $result->get_error_message());
}
function portalweave_sso_app(array $override = []): array {
    return array_replace_recursive(\PortalWeaveSSO\Config::app_defaults(), [
        'id' => 'fixture', 'name' => 'Fixture', 'client_id' => 'fixture-client',
        'issuer' => 'https://example.org', 'authorize_url' => 'https://example.org/authorize',
        'token_url' => 'https://example.org/token', 'userinfo_url' => 'https://example.org/userinfo',
        'jwks_url' => 'https://example.org/jwks', 'client_secret' => 'fixture-client-secret-with-thirty-two-bytes',
    ], $override);
}
function portalweave_sso_test(string $name, callable $test): void {
    global $portalweave_sso_results;
    $started = microtime(true);
    $settings = get_option('portalweave_sso_settings', []);
    $user_id = get_current_user_id();
    try {
        update_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults(), false);
        $test();
        $portalweave_sso_results[] = ['name' => $name, 'pass' => true, 'duration' => microtime(true) - $started];
    } catch (Throwable $error) {
        $portalweave_sso_results[] = ['name' => $name, 'pass' => false, 'duration' => microtime(true) - $started, 'error' => $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')'];
    } finally {
        update_option('portalweave_sso_settings', $settings, false);
        wp_set_current_user($user_id);
    }
}

try {
    \PortalWeaveSSO\Audit::install();
    foreach (['jwt', 'auth', 'core', 'extensions', 'smoke', 'compliance-auth', 'compliance-ui', 'compliance-modules', 'compliance-privacy', 'admin-settings', 'admin-navigation', 'provider-setup', 'activity', 'button-appearance'] as $suite) {
        if (!is_file(__DIR__ . '/' . $suite . '.php')) {
            throw new RuntimeException('Required test suite missing: ' . $suite);
        }
        require __DIR__ . '/' . $suite . '.php';
    }
} catch (Throwable $error) {
    $portalweave_sso_results[] = ['name' => 'Test runner', 'pass' => false, 'error' => $error->getMessage()];
} finally {
    update_option('portalweave_sso_settings', $portalweave_sso_original_settings, false);
}
restore_error_handler();
$noise = ob_get_clean();
exit(portalweave_sso_report_finish('integration', $portalweave_sso_results, $noise));
