<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

final class Audit
{
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'portalweave_sso_audit';
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
        update_option('portalweave_sso_db_version', PORTALWEAVE_SSO_VERSION, false);
        if (!wp_next_scheduled('portalweave_sso_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'portalweave_sso_cleanup');
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core has no higher-level API for this plugin-owned audit table; wpdb formats the inserted values and audit rows are not cached.
        $wpdb->insert($wpdb->prefix . 'portalweave_sso_audit', [
            'user_id' => $user ? $user->ID : 0, 'username' => $user ? $user->user_login : '',
            'email' => $user ? $user->user_email : '', 'status' => substr(sanitize_key($status), 0, 32),
            'app' => substr(sanitize_key($app), 0, 100), 'error_code' => substr(sanitize_key($code), 0, 100),
            'client_ip' => $ip, 'navigation' => substr(esc_url_raw($navigation), 0, 2048),
            'created_at' => current_time('mysql', true),
        ]);
        do_action('portalweave_sso_audit_recorded', $status, $app, $user ? $user->ID : 0, $code);
        if (Config::get('debug')) {
            $logs = (array) get_option('portalweave_sso_debug_log', []);
            $logs[] = ['at' => time(), 'status' => sanitize_key($status), 'app' => sanitize_key($app), 'code' => sanitize_key($code)];
            update_option('portalweave_sso_debug_log', array_slice($logs, -500), false);
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no custom audit-report API; read current rows without creating an extra cache of personal data.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR app = %s) AND (%d < 0 OR user_id = %d) ORDER BY id DESC LIMIT %d OFFSET %d",
            $wpdb->prefix . 'portalweave_sso_audit', $status, $status, $app, $app, $user_id, $user_id, $limit, $offset
        ), ARRAY_A) ?: [];
        return $rows;
    }

    /** A bounded page of this blog's retained history, stable against newly appended events. */
    public static function page(int $page = 1, int $per_page = 50, int $snapshot = 0): array|\WP_Error
    {
        global $wpdb;
        $per_page = max(1, min(100, $per_page));
        $snapshot = max(0, $snapshot);
        $suppressed = $wpdb->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no pagination API for this blog-owned audit table; count current retained rows without caching personal data. The ceiling keeps appended events from shifting later pages.
            $totals = $wpdb->get_row($wpdb->prepare(
                'SELECT COUNT(*) AS total, MAX(id) AS latest FROM %i WHERE (%d = 0 OR id <= %d)',
                $wpdb->prefix . 'portalweave_sso_audit', $snapshot, $snapshot
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($totals)) {
                return new \WP_Error('portalweave_sso_audit_page', __('Audit events could not be loaded. Please try again.', 'portalweave-sso'));
            }
            $total = (int) $totals['total'];
            $snapshot = (int) ($totals['latest'] ?? 0);
            $pages = max(1, (int) ceil($total / $per_page));
            $page = max(1, min($pages, $page));
            $offset = ($page - 1) * $per_page;
            $rows = [];
            if ($total > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The protected admin viewer needs this blog's current audit fields; prepared ceiling/offset/limit bound the read and avoid an extra personal-data cache.
                $rows = $wpdb->get_results($wpdb->prepare(
                    'SELECT id, username, status, app, created_at, email, client_ip, navigation, error_code FROM %i WHERE id <= %d ORDER BY id DESC LIMIT %d OFFSET %d',
                    $wpdb->prefix . 'portalweave_sso_audit', $snapshot, $per_page, $offset
                ), ARRAY_A);
                if ($wpdb->last_error !== '' || !is_array($rows)) {
                    return new \WP_Error('portalweave_sso_audit_page', __('Audit events could not be loaded. Please try again.', 'portalweave-sso'));
                }
            }
            return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per_page, 'snapshot' => $snapshot];
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    /** Aggregate retained audit events for this blog, including in Network Admin. */
    public static function summary(int $days = 30, ?int $now = null): array|\WP_Error
    {
        global $wpdb;
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $now ??= time();
        $start = gmdate('Y-m-d 00:00:00', $now - ($days - 1) * DAY_IN_SECONDS);
        $end = gmdate('Y-m-d H:i:s', $now + 1);
        $suppressed = $wpdb->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no audit aggregate API; one prepared UTC statement returns bounded daily/provider counts from this blog in one read view, without personal fields or an extra cache.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT 'daily' AS row_type, DATE(created_at) AS bucket, COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('success', 'local_login') THEN 1 ELSE 0 END) AS sign_ins,
                    SUM(CASE WHEN status IN ('failure', 'local_failure') THEN 1 ELSE 0 END) AS failures,
                    SUM(CASE WHEN status IN ('logout', 'local_logout') THEN 1 ELSE 0 END) AS sign_outs,
                    SUM(CASE WHEN status = 'test_success' THEN 1 ELSE 0 END) AS tests,
                    SUM(CASE WHEN status NOT IN ('success', 'local_login', 'failure', 'local_failure', 'logout', 'local_logout', 'test_success') THEN 1 ELSE 0 END) AS other
                FROM %i WHERE created_at >= %s AND created_at < %s
                GROUP BY DATE(created_at)
                UNION ALL
                SELECT 'provider', app, total, 0, 0, 0, 0, 0 FROM (
                    SELECT app, COUNT(*) AS total FROM %i WHERE created_at >= %s AND created_at < %s
                    GROUP BY app ORDER BY total DESC, app ASC LIMIT 8
                ) AS provider_counts
                ORDER BY row_type ASC, total DESC, bucket ASC",
                $wpdb->prefix . 'portalweave_sso_audit', $start, $end,
                $wpdb->prefix . 'portalweave_sso_audit', $start, $end
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($rows)) {
                return new \WP_Error('portalweave_sso_audit_summary', __('Activity statistics could not be loaded. Please try again.', 'portalweave-sso'));
            }
        } finally {
            $wpdb->suppress_errors($suppressed);
        }

        $daily = [];
        for ($index = $days - 1; $index >= 0; $index--) {
            $date = gmdate('Y-m-d', $now - $index * DAY_IN_SECONDS);
            $daily[$date] = ['date' => $date, 'count' => 0];
        }
        $total = 0;
        $types = ['sign_ins' => 0, 'failures' => 0, 'sign_outs' => 0, 'tests' => 0, 'other' => 0];
        $providers = [];
        $provider_total = 0;
        foreach ($rows as $row) {
            $count = (int) $row['total'];
            if ($row['row_type'] === 'provider') {
                $providers[] = ['app' => (string) $row['bucket'], 'count' => $count];
                $provider_total += $count;
                continue;
            }
            $daily[$row['bucket']]['count'] = $count;
            $total += $count;
            foreach (array_keys($types) as $type) {
                $types[$type] += (int) $row[$type];
            }
        }
        return [
            'days' => $days, 'start' => substr($start, 0, 10), 'end' => gmdate('Y-m-d', $now),
            'total' => $total, 'daily' => array_values($daily), 'types' => $types,
            'providers' => $providers, 'other_providers' => max(0, $total - $provider_total),
        ];
    }

    public static function for_email(string $email, int $page = 1): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export reads this blog's authoritative custom audit table without caching personal data.
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 100 OFFSET %d',
            $wpdb->prefix . 'portalweave_sso_audit', sanitize_email($email), (max(1, $page) - 1) * 100
        ), ARRAY_A) ?: [];
    }

    public static function anonymize(string $email): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no audit-table erasure API; wpdb formats the exact-email update and no audit-row cache exists.
        $count = $wpdb->update(
            $wpdb->prefix . 'portalweave_sso_audit',
            ['email' => '', 'username' => '', 'user_id' => 0, 'client_ip' => '', 'navigation' => ''],
            ['email' => sanitize_email($email)],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%s']
        );
        return (int) $count;
    }

    public static function cleanup(): void
    {
        global $wpdb;
        $days = max(1, (int) Config::get('audit_retention_days', 90));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom audit table requires a prepared range DELETE for retention; no audit-row cache exists.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s', $wpdb->prefix . 'portalweave_sso_audit', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
        $logs = array_values(array_filter((array) get_option('portalweave_sso_debug_log', []), static fn($row) => ($row['at'] ?? 0) >= time() - WEEK_IN_SECONDS));
        update_option('portalweave_sso_debug_log', $logs, false);
        // Locks are non-autoloaded options. Discover expired owners in batches and
        // compare each owner again when deleting so a renewed lock survives.
        $like = $wpdb->esc_like('_portalweave_sso_lock_') . '%';
        $cutoff = time();
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no option-prefix/expiry query; fetch current owners rather than cached lock data before atomic removal.
            $locks = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id LIMIT 200', $wpdb->options, $like, $cutoff), ARRAY_A) ?: [];
            $progress = false;
            foreach ($locks as $lock) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- delete_option cannot compare owners atomically; evict the non-autoloaded option cache after an exact-owner deletion.
                $deleted = $wpdb->delete($wpdb->options, ['option_name' => $lock['option_name'], 'option_value' => $lock['option_value']], ['%s', '%s']);
                if ($deleted) {
                    wp_cache_delete($lock['option_name'], 'options');
                    $progress = true;
                }
            }
        } while (count($locks) === 200 && $progress);
        do_action('portalweave_sso_cleanup_complete');
    }
}
