<?php
namespace WPSSO;

defined('ABSPATH') || exit;

/** Bounded, SSRF-resistant JSON transport for configured identity endpoints. */
final class Http {
    public static function json($url, array $args = []) {
        $parts = wp_parse_url((string) $url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return new \WP_Error('wpsso_endpoint', __('Identity endpoints must be absolute HTTPS URLs without credentials or fragments.', 'wpsso'));
        }
        $args = array_merge(['method' => 'GET', 'timeout' => 15, 'headers' => [], 'body' => null], $args);
        $args['redirection'] = 0;
        $args['sslverify'] = true;
        $args['reject_unsafe_urls'] = true;
        $args['limit_response_size'] = 1048576;
        $args['headers'] = array_merge(['Accept' => 'application/json'], $args['headers']);
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new \WP_Error('wpsso_http', __('The identity endpoint could not be reached. Check its URL and connectivity.', 'wpsso'));
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('wpsso_http_status', sprintf(__('The identity endpoint returned HTTP %d.', 'wpsso'), $status));
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) >= 1048576) {
            return new \WP_Error('wpsso_response_size', __('The identity response is too large.', 'wpsso'));
        }
        $decoded = json_decode($body, true, 64);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('wpsso_json', __('The identity endpoint did not return valid JSON.', 'wpsso'));
        }
        if (isset($decoded['error'])) {
            return new \WP_Error('wpsso_provider_error', __('The provider rejected the request. Check the client settings and grant permissions.', 'wpsso'));
        }
        return $decoded;
    }
}
