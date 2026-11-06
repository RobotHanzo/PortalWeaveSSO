<?php
/** Run only against the disposable compose.test.yml WordPress database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/report.php';
wpsso_report_bootstrap('integration');
ob_start();
define('WPSSO_PRIVATE_MEDIA_DIR', '/tmp/wpsso-private-tests');
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
if (get_option('blogname') !== 'WPSSO Integration') {
    fwrite(STDERR, "Refusing to run destructive integration fixtures outside the WPSSO Integration test site.\n");
    exit(2);
}
$wpsso_original_settings = get_option('wpsso_settings', []);
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
    wpsso_assert(is_wp_error($result), 'Expected WP_Error ' . $code . ', got ' . get_debug_type($result));
    wpsso_assert($result->get_error_code() === $code, 'Expected ' . $code . ', got ' . $result->get_error_code() . ': ' . $result->get_error_message());
}
function wpsso_app(array $override = []): array {
    return array_replace_recursive(\WPSSO\Config::app_defaults(), [
        'id' => 'fixture', 'name' => 'Fixture', 'client_id' => 'fixture-client',
        'issuer' => 'https://example.org', 'authorize_url' => 'https://example.org/authorize',
        'token_url' => 'https://example.org/token', 'userinfo_url' => 'https://example.org/userinfo',
        'jwks_url' => 'https://example.org/jwks', 'client_secret' => 'fixture-client-secret-with-thirty-two-bytes',
    ], $override);
}
function wpsso_test(string $name, callable $test): void {
    global $wpsso_results;
    $started = microtime(true);
    $settings = get_option('wpsso_settings', []);
    $user_id = get_current_user_id();
    try {
        update_option('wpsso_settings', \WPSSO\Config::defaults(), false);
        $test();
        $wpsso_results[] = ['name' => $name, 'pass' => true, 'duration' => microtime(true) - $started];
    } catch (Throwable $error) {
        $wpsso_results[] = ['name' => $name, 'pass' => false, 'duration' => microtime(true) - $started, 'error' => $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')'];
    } finally {
        update_option('wpsso_settings', $settings, false);
        wp_set_current_user($user_id);
    }
}

try {
    \WPSSO\Audit::install();
    foreach (['jwt', 'auth', 'core', 'extensions', 'smoke'] as $suite) {
        if (!is_file(__DIR__ . '/' . $suite . '.php')) {
            throw new RuntimeException('Required test suite missing: ' . $suite);
        }
        require __DIR__ . '/' . $suite . '.php';
    }
} catch (Throwable $error) {
    $wpsso_results[] = ['name' => 'Test runner', 'pass' => false, 'error' => $error->getMessage()];
} finally {
    update_option('wpsso_settings', $wpsso_original_settings, false);
}
restore_error_handler();
$noise = ob_get_clean();
exit(wpsso_report_finish('integration', $wpsso_results, $noise));
