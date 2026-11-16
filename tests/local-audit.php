<?php
/** Local failure fixtures retain only their own row IDs and restore request/debug state. */
function portalweave_sso_local_audit_fixture(callable $test): void {
    global $wpdb, $wp;
    $previous = [$_SERVER, $_GET, $wp->query_vars, $GLOBALS['pagenow'] ?? null, get_option('portalweave_sso_debug_log', null)];
    $ids = [];
    $capture = static function ($status) use ($wpdb, &$ids): void {
        if ($status === 'local_failure') { $ids[] = (int) $wpdb->insert_id; }
    };
    $rows = static function () use ($wpdb, &$ids): array {
        return array_map(static fn($id) => $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'portalweave_sso_audit', $id
        ), ARRAY_A), $ids);
    };
    add_action('portalweave_sso_audit_recorded', $capture);
    try {
        $wp->query_vars = [];
        unset($GLOBALS['pagenow']);
        $test($rows);
    } finally {
        remove_action('portalweave_sso_audit_recorded', $capture);
        foreach ($ids as $id) { $wpdb->delete($wpdb->prefix . 'portalweave_sso_audit', ['id' => $id], ['%d']); }
        [$_SERVER, $_GET, $wp->query_vars, $page, $debug] = $previous;
        if ($page === null) { unset($GLOBALS['pagenow']); } else { $GLOBALS['pagenow'] = $page; }
        if ($debug === null) { delete_option('portalweave_sso_debug_log'); }
        else { update_option('portalweave_sso_debug_log', $debug, false); }
    }
}

portalweave_sso_test('Real local authentication failures retain specific reasons once without recording submitted identities or credentials', function (): void {
    portalweave_sso_login_audit_fixture(function ($user): void {
        portalweave_sso_local_audit_fixture(function ($rows) use ($user): void {
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['debug' => true]) === true, 'Unable to enable diagnostic fixture');
            $secret = 'local-audit-private-' . bin2hex(random_bytes(12));
            $GLOBALS['pagenow'] = 'wp-login.php';
            $_SERVER['REQUEST_URI'] = '/wp-login.php?code=' . $secret . '#private';
            $missing = 'missing_' . bin2hex(random_bytes(6));
            foreach ([[$user->user_login, 'incorrect_password'], [$missing, 'invalid_username'], [$missing . '@example.org', 'invalid_email']] as $index => [$name, $reason]) {
                $result = wp_authenticate($name, $secret);
                portalweave_sso_error($result, $reason);
                $events = $rows();
                portalweave_sso_assert(count($events) === $index + 1, 'One authentication failure did not produce exactly one event');
                $event = $events[$index];
                portalweave_sso_assert($event['error_code'] === 'portalweave_sso_local_login_' . $reason, 'The real WordPress failure reason was lost');
                portalweave_sso_assert((int) $event['user_id'] === 0 && $event['username'] === '' && $event['email'] === '' && $event['app'] === '', 'Failed credentials were attributed to a verified identity/provider');
                portalweave_sso_assert($event['client_ip'] === '' && $event['navigation'] === home_url('/wp-login.php'), 'Failure collection changed IP opt-in or retained query/fragment data');
            }
            $serialized = wp_json_encode([$rows(), get_option('portalweave_sso_debug_log')]);
            foreach ([$secret, $missing, $user->user_login, $user->user_email] as $private) {
                portalweave_sso_assert(!str_contains($serialized, $private), 'A failure audit or debug record retained private input');
            }
        });
    });
});

portalweave_sso_test('Local failure audits allow only fixed error identifiers and tolerate legacy or malformed hook arguments', function (): void {
    portalweave_sso_local_audit_fixture(function ($rows): void {
        \PortalWeaveSSO\Config::save(['debug' => true]);
        $secret = 'untrusted-audit-payload-' . bin2hex(random_bytes(10));
        foreach ([
            [null, 'credentials'], [new WP_Error(), 'credentials'], [[], 'credentials'], [$secret, 'credentials'],
            [new WP_Error(42, $secret), 'credentials'], [new WP_Error($secret, $secret, ['token' => $secret]), 'credentials'],
            [new WP_Error('portalweave_sso_account_inactive', $secret), 'account_inactive'],
            [new WP_Error('portalweave_sso_guest_expired', $secret), 'guest_expired'],
            [new WP_Error('portalweave_sso_approval_pending', $secret), 'approval_pending'],
            [new WP_Error('portalweave_sso_account_blocked', $secret), 'account_blocked'],
            [new WP_Error('application_passwords_disabled', $secret), 'application_passwords_disabled'],
            [new WP_Error('application_passwords_disabled_for_user', $secret), 'application_passwords_disabled_for_user'],
            [new WP_Error('authentication_failed', $secret), 'authentication_failed'],
        ] as [$error, $reason]) {
            do_action('wp_login_failed', [$secret], $error);
            $events = $rows();
            portalweave_sso_assert(end($events)['error_code'] === 'portalweave_sso_local_' . $reason, 'A failure escaped the fixed reason allowlist');
        }
        do_action('wp_login_failed', $secret); // Older third-party emitters can supply only the name.
        $events = $rows();
        portalweave_sso_assert(end($events)['error_code'] === 'portalweave_sso_local_credentials', 'The one-argument hook lost its safe fallback');
        portalweave_sso_assert(!str_contains(wp_json_encode([$events, get_option('portalweave_sso_debug_log')]), $secret), 'Untrusted error code/message/data/name reached diagnostics');
        \PortalWeaveSSO\Config::save(['audit_enabled' => false]);
        $debug = get_option('portalweave_sso_debug_log');
        do_action('wp_login_failed', $secret, new WP_Error('incorrect_password', $secret));
        portalweave_sso_assert($rows() === $events && get_option('portalweave_sso_debug_log') === $debug, 'Disabled collection recorded a failure or diagnostic event');
    });
});

portalweave_sso_test('Failure context follows WordPress-resolved REST routing without retaining or trusting raw query parameters', function (): void {
    portalweave_sso_local_audit_fixture(function ($rows): void {
        global $wp;
        $_GET = ['rest_route' => '/forged', 'portalweave_sso_action' => 'callback'];
        $_SERVER['REQUEST_URI'] = '/?rest_route=/private-route&token=private-query';
        $error = new WP_Error('incorrect_password', 'private-message');
        do_action('wp_login_failed', 'private-name', $error);
        $wp->query_vars['rest_route'] = '/private-route';
        do_action('wp_login_failed', 'private-name', $error);
        $wp->query_vars['rest_route'] = ['/invalid-shape'];
        do_action('wp_login_failed', 'private-name', $error);
        $events = $rows();
        portalweave_sso_assert(array_column($events, 'error_code') === [
            'portalweave_sso_local_incorrect_password', 'portalweave_sso_local_rest_incorrect_password', 'portalweave_sso_local_incorrect_password',
        ], 'A raw request selected the context or a resolved REST route was lost');
        portalweave_sso_assert(array_unique(array_column($events, 'navigation')) === [home_url('/')], 'REST context required storing sensitive query parameters');
        foreach (['private-route', 'private-query', 'private-message', 'private-name'] as $private) {
            portalweave_sso_assert(!str_contains(wp_json_encode($events), $private), 'Failure context leaked untrusted route/error/identity data');
        }
    });
});
