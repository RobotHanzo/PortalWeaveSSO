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
        $user = $user instanceof \WP_User ? $user : ($user ? get_userdata((int) $user) : null);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
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
        $where = '1=1';
        $params = [];
        foreach (['status', 'app', 'user_id'] as $key) {
            if (isset($args[$key]) && $args[$key] !== '') {
                $where .= " AND {$key} = %s";
                $params[] = sanitize_text_field((string) $args[$key]);
            }
        }
        $params[] = max(1, min(10000, (int) ($args['limit'] ?? 100)));
        $params[] = max(0, (int) ($args['offset'] ?? 0));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wpsso_audit WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params), ARRAY_A) ?: [];
    }

    public static function cleanup(): void
    {
        global $wpdb;
        $days = max(1, (int) Config::get('audit_retention_days', 90));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpsso_audit WHERE created_at < %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
        $logs = array_values(array_filter((array) get_option('wpsso_debug_log', []), static fn($row) => ($row['at'] ?? 0) >= time() - WEEK_IN_SECONDS));
        update_option('wpsso_debug_log', $logs, false);
        // Expired locks and used flow records are not autoloaded; bound their lifetime.
        $like = $wpdb->esc_like('_wpsso_lock_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $like, time()));
        do_action('wpsso_cleanup_complete');
    }
}
