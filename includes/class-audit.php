<?php
namespace WPSSO;

defined('ABSPATH') || exit;

final class Audit
{
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'wpsso_audit';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            username varchar(60) NOT NULL DEFAULT '',
            email varchar(100) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL,
            app varchar(100) NOT NULL DEFAULT '',
            error_code varchar(100) NOT NULL DEFAULT '',
            client_ip varchar(45) NOT NULL DEFAULT '',
            navigation text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY user_id (user_id),
            KEY status (status)
        ) {$collate};");
        update_option('wpsso_db_version', WPSSO_VERSION, false);
        if (!wp_next_scheduled('wpsso_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpsso_cleanup');
        }
    }

    public static function record($status, $app = '', $user = null, $code = '', $navigation = ''): void
    {
        global $wpdb;
        if (!Config::get('audit_enabled', true)) {
            return;
        }
        $user = $user instanceof \WP_User ? $user : ($user ? get_userdata((int) $user) : null);
        $ip = Config::get('audit_ip') && isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        // Proxy headers are untrusted. Configure the web server's real IP module instead.
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        $navigation = $navigation ?: Redirect::current();
        $navigation = preg_replace('/[?#].*$/', '', (string) $navigation);
        $wpdb->insert($wpdb->prefix . 'wpsso_audit', [
            'user_id' => $user ? $user->ID : 0, 'username' => $user ? $user->user_login : '',
            'email' => $user ? $user->user_email : '', 'status' => substr(sanitize_key($status), 0, 32),
            'app' => substr(sanitize_key($app), 0, 100), 'error_code' => substr(sanitize_key($code), 0, 100),
            'client_ip' => $ip, 'navigation' => substr(esc_url_raw($navigation), 0, 2048),
            'created_at' => current_time('mysql', true),
        ]);
        self::invalidate();
        do_action('wpsso_audit_recorded', $status, $app, $user ? $user->ID : 0, $code);
        if (Config::get('debug')) {
            $logs = (array) get_option('wpsso_debug_log', []);
            $logs[] = ['at' => time(), 'status' => sanitize_key($status), 'app' => sanitize_key($app), 'code' => sanitize_key($code)];
            update_option('wpsso_debug_log', array_slice($logs, -500), false);
        }
    }

    public static function recent(array $args = []): array
    {
        global $wpdb;
        $status = sanitize_key((string) ($args['status'] ?? ''));
        $app = sanitize_key((string) ($args['app'] ?? ''));
        $user_id = isset($args['user_id']) && $args['user_id'] !== '' ? absint($args['user_id']) : -1;
        $limit = max(1, min(10000, (int) ($args['limit'] ?? 100)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $generation = wp_cache_get('generation', 'stsa_identity_sso_audit') ?: 'initial';
        $key = hash('sha256', wp_json_encode([$generation, $status, $app, $user_id, $limit, $offset]));
        $rows = wp_cache_get($key, 'stsa_identity_sso_audit');
        if ($rows !== false) {
            return $rows;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR app = %s) AND (%d < 0 OR user_id = %d) ORDER BY id DESC LIMIT %d OFFSET %d",
            $wpdb->prefix . 'wpsso_audit', $status, $status, $app, $app, $user_id, $user_id, $limit, $offset
        ), ARRAY_A) ?: [];
        wp_cache_set($key, $rows, 'stsa_identity_sso_audit', MINUTE_IN_SECONDS);
        return $rows;
    }

    public static function for_email(string $email, int $page = 1): array
    {
        global $wpdb;
        // Privacy exports must read the current authoritative records, without retaining PII in a secondary cache.
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 100 OFFSET %d',
            $wpdb->prefix . 'wpsso_audit', sanitize_email($email), (max(1, $page) - 1) * 100
        ), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export intentionally bypasses caches containing personal data.
    }

    public static function anonymize(string $email): int
    {
        global $wpdb;
        $count = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET email = '', username = '', user_id = 0, client_ip = '', navigation = '' WHERE email = %s",
            $wpdb->prefix . 'wpsso_audit', sanitize_email($email)
        ));
        self::invalidate();
        return (int) $count;
    }

    private static function invalidate(): void
    {
        wp_cache_set('generation', wp_generate_uuid4(), 'stsa_identity_sso_audit');
    }

    public static function cleanup(): void
    {
        global $wpdb;
        $days = max(1, (int) Config::get('audit_retention_days', 90));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s', $wpdb->prefix . 'wpsso_audit', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
        self::invalidate();
        $logs = array_values(array_filter((array) get_option('wpsso_debug_log', []), static fn($row) => ($row['at'] ?? 0) >= time() - WEEK_IN_SECONDS));
        update_option('wpsso_debug_log', $logs, false);
        // Expired locks and used flow records are not autoloaded; bound their lifetime.
        $like = $wpdb->esc_like('_wpsso_lock_') . '%';
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $like, time()));
        do_action('wpsso_cleanup_complete');
    }
}
