<?php
/** Test-only MU fixture. Never package this in the distributable plugin. */
defined('ABSPATH') || exit;
if (!defined('WPSSO_TEST_IDP') || WPSSO_TEST_IDP !== true || get_option('blogname') !== 'WPSSO Integration') {
    return;
}

function wpsso_test_idp_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function wpsso_test_idp_response(array $body, int $status = 200): array {
    return ['headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($body),
        'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Bad Request'], 'cookies' => [], 'filename' => null];
}

add_filter('pre_http_request', static function ($response, $args, $url) {
    if ($url !== 'https://example.org/wpsso-test-token' && $url !== 'https://example.org/wpsso-test-userinfo') {
        return $response;
    }
    if ($url === 'https://example.org/wpsso-test-userinfo') {
        $header = (string) ($args['headers']['Authorization'] ?? '');
        if (!preg_match('/^Bearer wpsso-test-access-([a-f0-9]{24})$/D', $header, $match)) {
            return wpsso_test_idp_response(['error' => 'invalid_token'], 401);
        }
        $record = get_transient('wpsso_test_access_' . $match[1]);
        return is_array($record) ? wpsso_test_idp_response($record) : wpsso_test_idp_response(['error' => 'invalid_token'], 401);
    }
    parse_str((string) ($args['body'] ?? ''), $body);
    if (($args['method'] ?? '') !== 'POST' || ($body['grant_type'] ?? '') !== 'authorization_code' || ($body['client_id'] ?? '') !== 'wpsso-e2e-client') {
        return wpsso_test_idp_response(['error' => 'invalid_request'], 400);
    }
    $code = (string) ($body['code'] ?? '');
    $json = base64_decode(strtr($code, '-_', '+/'), true);
    $fixture = is_string($json) ? json_decode($json, true) : null;
    $expected_callback = home_url('/?wpsso_action=callback');
    if (!is_array($fixture) || !is_string($fixture['nonce'] ?? null) || !is_string($fixture['challenge'] ?? null) ||
        !hash_equals($fixture['challenge'], wpsso_test_idp_encode(hash('sha256', (string) ($body['code_verifier'] ?? ''), true))) ||
        !hash_equals($expected_callback, (string) ($body['redirect_uri'] ?? ''))) {
        return wpsso_test_idp_response(['error' => 'invalid_grant'], 400);
    }
    $code_key = 'wpsso_test_code_' . hash('sha256', $code);
    if (!add_option($code_key, time() + 600, '', false)) {
        return wpsso_test_idp_response(['error' => 'invalid_grant'], 400);
    }
    $subject = is_string($fixture['sub'] ?? null) && preg_match('/^[a-z0-9_-]{1,80}$/D', $fixture['sub']) ? $fixture['sub'] : 'e2e-subject';
    $claims = ['iss' => 'https://example.org/wpsso-test', 'aud' => 'wpsso-e2e-client', 'sub' => $subject,
        'nonce' => $fixture['nonce'], 'iat' => time(), 'exp' => time() + 300, 'email' => $subject . '@example.org',
        'email_verified' => true, 'preferred_username' => $subject, 'given_name' => 'Test', 'family_name' => 'Identity'];
    $header = wpsso_test_idp_encode('{"alg":"HS256","typ":"JWT"}');
    $payload = $header . '.' . wpsso_test_idp_encode(wp_json_encode($claims));
    $token = $payload . '.' . wpsso_test_idp_encode(hash_hmac('sha256', $payload, 'wpsso-local-integration-signing-key-only-64-characters-long-fixture', true));
    $access = bin2hex(random_bytes(12));
    set_transient('wpsso_test_access_' . $access, $claims, 600);
    return wpsso_test_idp_response(['access_token' => 'wpsso-test-access-' . $access, 'token_type' => 'Bearer', 'expires_in' => 300, 'id_token' => $token]);
}, 10, 3);

add_action('init', static function () {
    if (!isset($_GET['wpsso_test_inspect'])) {
        return;
    }
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    header('Content-Type: application/json');
    $user = wp_get_current_user();
    echo wp_json_encode(['id' => $user->ID, 'login' => $user->user_login, 'roles' => $user->roles,
        'test_url' => current_user_can('manage_options') ? \WPSSO\Auth::login_url('e2e', home_url('/origin-login-initiator/'), true) : null]);
    exit;
}, 0);
