<?php
/** Audit summaries use real UTC history and clean up only their own inserted rows. */
function portalweave_sso_activity_fixture(callable $test): void {
    global $wpdb;
    $table = $wpdb->prefix . 'portalweave_sso_audit';
    $ids = [];
    $email = 'activity-' . bin2hex(random_bytes(6)) . '@example.org';
    $insert = static function (array $row = []) use ($wpdb, $table, $email, &$ids): int {
        $result = $wpdb->insert($table, array_replace([
            'user_id' => 0, 'username' => 'private-activity-user', 'email' => $email,
            'status' => 'success', 'app' => 'workforce', 'error_code' => 'private-activity-error',
            'client_ip' => '198.51.100.77', 'navigation' => 'https://private.example.org/activity-fixture',
            'created_at' => '2001-03-15 00:00:00',
        ], $row), ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        portalweave_sso_assert($result === 1, 'Audit summary fixture could not be inserted.');
        $ids[] = (int) $wpdb->insert_id;
        return (int) $wpdb->insert_id;
    };
    try {
        $test($insert, $email, $table);
    } finally {
        foreach ($ids as $id) { $wpdb->delete($table, ['id' => $id], ['%d']); }
    }
}

portalweave_sso_test('Activity aggregate dimensions reconcile when events change between statements', function (): void {
    portalweave_sso_activity_fixture(function ($insert, $email, $table): void {
        $insert();
        $writes = 0;
        $change_history = static function ($query) use ($insert, $table, &$writes) {
            if (preg_match('/^\s*SELECT\b/i', $query) && str_contains($query, $table)) {
                $writes++;
                $insert(['status' => 'failure']);
            }
            return $query;
        };
        add_filter('query', $change_history);
        try {
            $summary = \PortalWeaveSSO\Audit::summary(7, gmmktime(12, 0, 0, 3, 15, 2001));
        } finally {
            remove_filter('query', $change_history);
        }
        portalweave_sso_assert(is_array($summary) && $writes > 0, 'Statement-boundary history changes were not exercised.');
        $provider_events = array_sum(array_column($summary['providers'], 'count')) + $summary['other_providers'];
        portalweave_sso_assert($provider_events === $summary['total'], 'Activity dimensions disagree after a statement-boundary write: total ' . $summary['total'] . ', providers ' . $provider_events . '.');
        portalweave_sso_assert(array_sum($summary['types']) === $summary['total'] && array_sum(array_column($summary['daily'], 'count')) === $summary['total'], 'Daily and event-type dimensions disagree after a history change.');
        portalweave_sso_assert($writes === 1, 'Summary split its aggregate reads across multiple statements.');
    });
});

portalweave_sso_test('Activity summaries aggregate all retained events with UTC boundaries and no personal fields', function (): void {
    portalweave_sso_activity_fixture(function ($insert, $email, $table): void {
        global $wpdb;
        $now = gmmktime(0, 0, 0, 3, 15, 2001);
        for ($index = 0; $index < 119; $index++) { $insert(['created_at' => '2001-03-09 00:00:00']); }
        $insert(); // The current UTC second is included.
        foreach ([
            ['local_login', 2, '2001-03-14 23:59:59'], ['failure', 3, '2001-03-09 14:00:00'],
            ['local_failure', 4, '2001-03-11 14:00:00'], ['logout', 5, '2001-03-12 14:00:00'],
            ['local_logout', 6, '2001-03-13 14:00:00'], ['test_success', 7, '2001-03-14 14:00:00'],
            ['denied', 2, '2001-03-11 14:00:00'], ['test_failure', 2, '2001-03-11 14:00:00'],
            ['custom_event', 2, '2001-03-11 14:00:00'], ['success_extra', 2, '2001-03-11 14:00:00'],
        ] as [$status, $count, $date]) {
            for ($index = 0; $index < $count; $index++) { $insert(['status' => $status, 'created_at' => $date]); }
        }
        foreach (['2001-03-08 23:59:59', '2001-03-15 00:00:01', '2001-03-15 12:00:00', '2001-03-16 00:00:00'] as $date) {
            $insert(['created_at' => $date]);
        }
        $queries = [];
        $capture = static function ($query) use ($table, &$queries) {
            if (preg_match('/^\s*SELECT\b/i', $query) && str_contains($query, $table)) { $queries[] = $query; }
            return $query;
        };
        $timezone = get_option('timezone_string');
        $offset = get_option('gmt_offset');
        $php_timezone = date_default_timezone_get();
        try {
            update_option('timezone_string', 'America/Los_Angeles');
            update_option('gmt_offset', -8);
            date_default_timezone_set('Pacific/Honolulu');
            add_filter('query', $capture);
            $summary = \PortalWeaveSSO\Audit::summary(7, $now);
        } finally {
            remove_filter('query', $capture);
            date_default_timezone_set($php_timezone);
            update_option('timezone_string', $timezone);
            update_option('gmt_offset', $offset);
        }
        portalweave_sso_assert(is_array($summary), 'Audit summary failed.');
        portalweave_sso_assert($summary['days'] === 7 && $summary['start'] === '2001-03-09' && $summary['end'] === '2001-03-15', 'Audit window used local time or omitted the current UTC day.');
        portalweave_sso_assert($summary['total'] === 155, 'Summary sampled recent rows or included an out-of-window event.');
        portalweave_sso_assert($summary['daily'] === [
            ['date' => '2001-03-09', 'count' => 122], ['date' => '2001-03-10', 'count' => 0],
            ['date' => '2001-03-11', 'count' => 12], ['date' => '2001-03-12', 'count' => 5],
            ['date' => '2001-03-13', 'count' => 6], ['date' => '2001-03-14', 'count' => 9],
            ['date' => '2001-03-15', 'count' => 1],
        ], 'Daily counts lost zero days or UTC date boundaries.');
        portalweave_sso_assert($summary['types'] === ['sign_ins' => 122, 'failures' => 7, 'sign_outs' => 11, 'tests' => 7, 'other' => 8], 'Event types did not use the exact audit-status taxonomy.');
        portalweave_sso_assert(array_sum($summary['types']) === $summary['total'] && array_sum(array_column($summary['daily'], 'count')) === $summary['total'], 'Aggregate dimensions disagree.');
        portalweave_sso_assert(array_keys($summary) === ['days', 'start', 'end', 'total', 'daily', 'types', 'providers', 'other_providers'], 'Summary returned unexpected audit data.');
        foreach ($summary['providers'] as $provider) {
            portalweave_sso_assert(array_keys($provider) === ['app', 'count'] && is_string($provider['app']) && is_int($provider['count']), 'Provider aggregates contain personal fields or untyped counts.');
        }
        $json = wp_json_encode($summary);
        foreach ([$email, 'private-activity-user', 'private-activity-error', '198.51.100.77', 'private.example.org'] as $private) {
            portalweave_sso_assert(!str_contains($json, $private), 'An aggregate exposed a personal audit field.');
        }
        portalweave_sso_assert(count($queries) === 1, 'Summary must read both aggregates in one statement.');
        foreach ($queries as $query) {
            portalweave_sso_assert(!preg_match('/\b(?:user_id|username|email|client_ip|navigation|error_code)\b|SELECT\s+\*/i', $query), 'Aggregate query fetched personal audit fields.');
            portalweave_sso_assert(str_contains($query, "'2001-03-09 00:00:00'") && str_contains($query, "'2001-03-15 00:00:01'"), 'Aggregate query lost its shared UTC bounds.');
        }
    });
});

portalweave_sso_test('Activity periods allow only seven, thirty or ninety inclusive UTC days', function (): void {
    portalweave_sso_activity_fixture(function ($insert): void {
        $now = gmmktime(12, 34, 56, 3, 15, 2001);
        foreach (['2000-12-15 23:59:59', '2000-12-16 00:00:00', '2001-02-13 23:59:59', '2001-02-14 00:00:00', '2001-03-08 23:59:59', '2001-03-09 00:00:00', '2001-03-15 12:34:56'] as $date) {
            $insert(['created_at' => $date]);
        }
        foreach ([[7, '2001-03-09', 2], [30, '2001-02-14', 4], [90, '2000-12-16', 6]] as [$days, $start, $count]) {
            $summary = \PortalWeaveSSO\Audit::summary($days, $now);
            portalweave_sso_assert(is_array($summary) && $summary['days'] === $days && $summary['start'] === $start && $summary['end'] === '2001-03-15', 'A supported period has incorrect inclusive UTC bounds.');
            portalweave_sso_assert(count($summary['daily']) === $days && $summary['total'] === $count, 'A supported period counted the wrong retained rows.');
        }
        foreach ([-7, 0, 1, 8, 31, 365] as $days) {
            $summary = \PortalWeaveSSO\Audit::summary($days, $now);
            portalweave_sso_assert(is_array($summary) && $summary['days'] === 30 && count($summary['daily']) === 30 && $summary['total'] === 4, 'An unsupported period did not clamp to thirty days.');
        }
    });
});

portalweave_sso_test('Activity provider counts retain blank and deleted IDs, deterministic ties and the remainder', function (): void {
    portalweave_sso_activity_fixture(function ($insert): void {
        foreach (['' => 30, 'alpha' => 20, 'bravo' => 10, 'charlie' => 5, 'delta' => 5, 'echo' => 5, 'foxtrot' => 5, 'golf' => 5, 'hotel' => 5, 'india' => 2] as $app => $count) {
            for ($index = 0; $index < $count; $index++) { $insert(['app' => $app]); }
        }
        $summary = \PortalWeaveSSO\Audit::summary(7, gmmktime(12, 0, 0, 3, 15, 2001));
        portalweave_sso_assert(is_array($summary) && $summary['total'] === 92, 'Provider summary lost retained records.');
        portalweave_sso_assert($summary['providers'] === [
            ['app' => '', 'count' => 30], ['app' => 'alpha', 'count' => 20], ['app' => 'bravo', 'count' => 10],
            ['app' => 'charlie', 'count' => 5], ['app' => 'delta', 'count' => 5], ['app' => 'echo', 'count' => 5],
            ['app' => 'foxtrot', 'count' => 5], ['app' => 'golf', 'count' => 5],
        ], 'Top provider ordering, raw IDs or the eight-provider limit changed.');
        portalweave_sso_assert($summary['other_providers'] === 7 && array_sum(array_column($summary['providers'], 'count')) + $summary['other_providers'] === $summary['total'], 'Remaining provider events were lost or confused with provider cardinality.');
    });
});

portalweave_sso_test('Activity history survives anonymization and disabled collection and updates without a cache', function (): void {
    portalweave_sso_activity_fixture(function ($insert, $email): void {
        $now = gmmktime(12, 0, 0, 3, 15, 2001);
        $insert(['user_id' => 12345]);
        $before = \PortalWeaveSSO\Audit::summary(7, $now);
        portalweave_sso_assert(is_array($before) && $before['total'] === 1, 'Initial history fixture is missing.');
        portalweave_sso_assert(\PortalWeaveSSO\Audit::anonymize($email) === 1, 'The audit row was not anonymized.');
        portalweave_sso_assert(\PortalWeaveSSO\Audit::summary(7, $now) === $before, 'Anonymization deleted retained event statistics.');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['audit_enabled' => false]) === true, 'Disabling collection failed.');
        portalweave_sso_assert(\PortalWeaveSSO\Audit::summary(7, $now) === $before, 'Disabling new collection hid retained history.');
        $disabled_app = 'activity-disabled-' . bin2hex(random_bytes(6));
        \PortalWeaveSSO\Audit::record('failure', $disabled_app);
        portalweave_sso_assert(\PortalWeaveSSO\Audit::recent(['app' => $disabled_app]) === [], 'Disabled collection persisted a new event.');
        $insert(['status' => 'failure']);
        $after = \PortalWeaveSSO\Audit::summary(7, $now);
        portalweave_sso_assert(is_array($after) && $after['total'] === 2 && $after['types']['failures'] === 1, 'Repeated summary calls returned a stale aggregate.');
    });
});

portalweave_sso_test('Activity distinguishes empty history from its aggregate statement failing safely', function (): void {
    global $wpdb;
    $now = gmmktime(12, 0, 0, 3, 15, 2001);
    $empty = \PortalWeaveSSO\Audit::summary(7, $now);
    portalweave_sso_assert(is_array($empty) && $empty['total'] === 0 && count($empty['daily']) === 7 && $empty['providers'] === [] && $empty['other_providers'] === 0, 'Empty history did not produce a genuine zero-filled summary.');
    portalweave_sso_assert(array_sum($empty['types']) === 0 && array_sum(array_column($empty['daily'], 'count')) === 0, 'Empty history reported events.');
    $table = $wpdb->prefix . 'portalweave_sso_audit';
    $show_errors = $wpdb->show_errors(true);
    $suppressed = $wpdb->suppress_errors(false);
    $calls = 0;
    $fail = static function ($query) use ($wpdb, $table, &$calls) {
        if (preg_match('/^\s*SELECT\b/i', $query) && str_contains($query, $table)) {
            $calls++;
            return $wpdb->prepare('SELECT portalweave_sso_activity_missing_column FROM %i', $table);
        }
        return $query;
    };
    try {
        add_filter('query', $fail);
        ob_start();
        try {
            $result = \PortalWeaveSSO\Audit::summary(7, $now);
        } finally {
            $noise = ob_get_clean();
            remove_filter('query', $fail);
        }
        portalweave_sso_error($result, 'portalweave_sso_audit_summary');
        portalweave_sso_assert($calls === 1 && $noise === '', 'Statement failure printed database details or executed more aggregates.');
        portalweave_sso_assert($wpdb->suppress_errors === false && $wpdb->show_errors === true, 'Summary changed the caller\'s database error settings.');
        portalweave_sso_assert(!str_contains($result->get_error_message(), $table) && !str_contains($result->get_error_message(), 'missing_column') && !str_contains($result->get_error_message(), 'SELECT'), 'Summary exposed SQL diagnostics.');
    } finally {
        $wpdb->suppress_errors($suppressed);
        $wpdb->show_errors($show_errors);
    }
    $restored = \PortalWeaveSSO\Audit::summary(7, $now);
    portalweave_sso_assert(is_array($restored) && $restored['total'] === 0, 'Query failure was cached as an error or empty data.');
});

portalweave_sso_test('Audit pages reach all retained events without overlap and stay stable when new events arrive', function (): void {
    portalweave_sso_activity_fixture(function ($insert): void {
        $baseline = \PortalWeaveSSO\Audit::page();
        portalweave_sso_assert(is_array($baseline), 'Unable to read baseline audit history');
        $ids = [];
        for ($index = 0; $index < 137; $index++) {
            $ids[] = $insert(['app' => 'paged-history', 'username' => 'paged-user-' . $index]);
        }
        $first = \PortalWeaveSSO\Audit::page();
        portalweave_sso_assert(is_array($first) && $first['total'] === $baseline['total'] + 137
            && $first['per_page'] === 50 && count($first['rows']) === 50 && $first['page'] === 1, 'First audit page still samples only the latest hundred rows');
        $expected = array_reverse($ids);
        portalweave_sso_assert(array_map('intval', array_column($first['rows'], 'id')) === array_slice($expected, 0, 50), 'Audit page is not ordered by descending unique ID');
        $new_id = $insert(['app' => 'appended-after-first-page']);
        $seen = [];
        for ($page = 1; $page <= $first['pages']; $page++) {
            $result = \PortalWeaveSSO\Audit::page($page, 50, $first['snapshot']);
            portalweave_sso_assert(is_array($result) && $result['total'] === $first['total'] && $result['snapshot'] === $first['snapshot']
                && $result['page'] === $page && count($result['rows']) <= 50, 'Pagination lost its bounded snapshot metadata');
            foreach ($result['rows'] as $row) {
                $id = (int) $row['id'];
                portalweave_sso_assert(!isset($seen[$id]) && $id !== $new_id, 'Adjacent audit pages overlap after a new event');
                $seen[$id] = true;
            }
        }
        portalweave_sso_assert(count($seen) === $first['total'], 'Pagination omitted retained audit events');
        foreach ($ids as $id) { portalweave_sso_assert(isset($seen[$id]), 'An event older than the first hundred rows is unreachable'); }
        $fresh = \PortalWeaveSSO\Audit::page();
        portalweave_sso_assert(is_array($fresh) && $fresh['total'] === $first['total'] + 1 && (int) $fresh['rows'][0]['id'] === $new_id, 'Refreshing latest did not include the newly appended event');
        $last = \PortalWeaveSSO\Audit::page(PHP_INT_MAX, 50, $first['snapshot']);
        portalweave_sso_assert(is_array($last) && $last['page'] === $first['pages'] && $last['rows'], 'An out-of-range page did not clamp to available history');
        $bounded = \PortalWeaveSSO\Audit::page(-10, 10000, -1);
        portalweave_sso_assert(is_array($bounded) && $bounded['page'] === 1 && $bounded['per_page'] === 100 && count($bounded['rows']) <= 100, 'Audit page size or lower page bound is unbounded');
    });
});

portalweave_sso_test('Audit pagination distinguishes unavailable queries from empty views without leaking SQL details', function (): void {
    portalweave_sso_activity_fixture(function ($insert, $email, $table): void {
        global $wpdb;
        $insert();
        foreach (['COUNT(*)', 'SELECT id, username'] as $statement) {
            $calls = 0;
            $fail = static function ($query) use ($wpdb, $table, $statement, &$calls) {
                if (str_contains($query, $table) && str_contains($query, $statement)) {
                    $calls++;
                    return $wpdb->prepare('SELECT portalweave_sso_pagination_missing_column FROM %i', $table);
                }
                return $query;
            };
            $old_suppression = $wpdb->suppress_errors(false);
            add_filter('query', $fail);
            ob_start();
            try {
                $result = \PortalWeaveSSO\Audit::page();
            } finally {
                $noise = ob_get_clean();
                remove_filter('query', $fail);
                $restored = $wpdb->suppress_errors;
                $wpdb->suppress_errors($old_suppression);
            }
            portalweave_sso_error($result, 'portalweave_sso_audit_page');
            portalweave_sso_assert($calls === 1 && $noise === '' && $restored === false, 'Pagination printed database errors or changed the caller error policy');
            portalweave_sso_assert(!str_contains($result->get_error_message(), $table)
                && !str_contains($result->get_error_message(), 'missing_column'), 'Pagination exposed SQL diagnostics');
        }
        // Exercise the empty-result branch against the database without deleting other fixtures' retained history.
        $empty_query = static function ($query) use ($table) {
            return str_contains($query, $table) && str_contains($query, 'COUNT(*) AS total') ? str_replace('WHERE (', 'WHERE 1 = 0 AND (', $query) : $query;
        };
        add_filter('query', $empty_query);
        try { $empty = \PortalWeaveSSO\Audit::page(PHP_INT_MAX); }
        finally { remove_filter('query', $empty_query); }
        portalweave_sso_assert(is_array($empty) && $empty['rows'] === [] && $empty['total'] === 0
            && $empty['page'] === 1 && $empty['pages'] === 1 && $empty['snapshot'] === 0, 'Empty history was confused with a query failure or invalid page');
        wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
        $fail_view = static function ($query) use ($wpdb, $table) {
            return str_contains($query, $table) && str_contains($query, 'COUNT(*) AS total')
                ? $wpdb->prepare('SELECT portalweave_sso_pagination_missing_column FROM %i', $table) : $query;
        };
        add_filter('query', $fail_view);
        try { $html = portalweave_sso_admin_activity_page(['activity_view' => 'audit']); }
        finally { remove_filter('query', $fail_view); }
        portalweave_sso_assert(str_contains($html, 'Audit events could not be loaded.')
            && !str_contains($html, 'No retained events in this view.') && !str_contains($html, 'missing_column')
            && str_contains($html, 'name="settings[audit_enabled]"'), 'Audit viewer confused a database failure with empty history or hid collection controls');
    });
});

portalweave_sso_test('Audit viewer renders page links and safely preserves pagination through preference saves', function (): void {
    portalweave_sso_activity_fixture(function ($insert): void {
        wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
        for ($index = 0; $index < 120; $index++) { $insert(['username' => 'audit-pagination-view-' . $index]); }
        $history = \PortalWeaveSSO\Audit::page(2);
        portalweave_sso_assert(is_array($history), 'Unable to load viewer fixture');
        foreach (['2', '-1', '2junk', ['2'], str_repeat('9', 30)] as $requested) {
            $selected = $requested === '2' ? 2 : 1;
            $html = portalweave_sso_admin_activity_page(['activity_view' => 'audit', 'activity_days' => '90', 'audit_page' => $requested, 'audit_snapshot' => (string) $history['snapshot']]);
            portalweave_sso_assert(substr_count($html, 'data-audit-pagination=') === 2
                && str_contains($html, 'data-audit-page="' . $selected . '"'), 'Viewer lost pagination or reflected an invalid page');
            portalweave_sso_assert(substr_count($html, '<tr data-audit-id=') === 50, 'Viewer did not enforce fifty rows per page');
            portalweave_sso_assert(preg_match('/<a\b[^>]*data-audit-link="next"[^>]*href="([^"]+)"/', $html, $matches) === 1, 'Viewer has no next-page link');
            parse_str((string) wp_parse_url(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);
            portalweave_sso_assert(($query['activity_view'] ?? '') === 'audit' && ($query['activity_days'] ?? '') === '90'
                && (int) ($query['audit_page'] ?? 0) === $selected + 1 && (int) ($query['audit_snapshot'] ?? 0) === $history['snapshot'], 'Viewer pagination lost view, range or stable ceiling');
        }
        $context = ['portalweave_sso_screen' => 'reports', 'portalweave_sso_activity_view' => 'audit', 'portalweave_sso_activity_days' => '90',
            'portalweave_sso_audit_page' => '2', 'portalweave_sso_audit_snapshot' => (string) $history['snapshot']];
        $destination = portalweave_sso_admin_form_submit(['audit_enabled' => '0'], $context);
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['portalweave_sso_notice'] ?? '') === 'saved' && ($query['audit_page'] ?? '') === '2'
            && (int) ($query['audit_snapshot'] ?? 0) === $history['snapshot'], 'Preference save lost its selected audit page');
        $stored = get_option('portalweave_sso_settings');
        portalweave_sso_assert(!isset($stored['audit_page']) && !isset($stored['audit_snapshot']), 'Pagination context entered settings');
        $destination = portalweave_sso_admin_form_submit(['audit_enabled' => '1'], array_replace($context, ['portalweave_sso_audit_page' => ['2']]));
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['portalweave_sso_notice'] ?? '') === 'error' && get_option('portalweave_sso_settings') === $stored, 'Structured pagination mutated settings');
        delete_transient('portalweave_sso_admin_error_' . get_current_user_id());
    });
});
