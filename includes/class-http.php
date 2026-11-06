<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Bounded, SSRF-resistant JSON transport for configured identity endpoints. */
final class Http {
    public static function json($url, array $args = []) {
        $parts = is_string($url) && strlen($url) <= 8192 ? wp_parse_url($url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return new \WP_Error('portalweave_sso_endpoint', __('Identity endpoints must be absolute HTTPS URLs without credentials or fragments.', 'portalweave-sso'));
        }
        $args = array_merge(['method' => 'GET', 'timeout' => 15, 'headers' => [], 'body' => null], $args);
        $args['redirection'] = 0;
        $args['timeout'] = min(30, max(1, (int) $args['timeout']));
        $args['sslverify'] = true;
        $args['reject_unsafe_urls'] = true;
        $args['limit_response_size'] = 1048576;
        $args['headers'] = array_merge(['Accept' => 'application/json'], $args['headers']);
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new \WP_Error('portalweave_sso_http', __('The identity endpoint could not be reached. Check its URL and connectivity.', 'portalweave-sso'));
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            /* translators: %d: HTTP status code returned by the identity provider. */
            return new \WP_Error('portalweave_sso_http_status', sprintf(__('The identity endpoint returned HTTP %d.', 'portalweave-sso'), $status));
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) >= 1048576) {
            return new \WP_Error('portalweave_sso_response_size', __('The identity response is too large.', 'portalweave-sso'));
        }
        $decoded = json_decode($body, true, 64);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('portalweave_sso_json', __('The identity endpoint did not return valid JSON.', 'portalweave-sso'));
        }
        if (isset($decoded['error'])) {
            return new \WP_Error('portalweave_sso_provider_error', __('The provider rejected the request. Check the client settings and grant permissions.', 'portalweave-sso'));
        }
        return $decoded;
    }
}
