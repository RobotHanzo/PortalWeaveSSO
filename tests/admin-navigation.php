<?php
/** Exercise the real administrator save boundary without following its terminating redirect. */
function portalweave_sso_admin_form_submit(array $settings, array $context): string {
    $old_post = $_POST;
    $old_request = $_REQUEST;
    $destination = '';
    $redirect = static function ($url) use (&$destination) {
        $destination = $url;
        throw new RuntimeException('portalweave_sso_expected_form_redirect');
    };
    add_filter('wp_redirect', $redirect);
    try {
        $_POST = ['settings' => wp_slash($settings), 'portalweave_sso_complete' => '1', '_wpnonce' => wp_create_nonce('portalweave_sso_save')] + $context;
        $_REQUEST = $_POST;
        try {
            \PortalWeaveSSO\Admin::save();
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'portalweave_sso_expected_form_redirect') {
                throw $error;
            }
        }
    } finally {
        remove_filter('wp_redirect', $redirect);
        $_POST = $old_post;
        $_REQUEST = $old_request;
    }
    portalweave_sso_assert($destination !== '', 'Settings action did not redirect');
    return $destination;
}

function portalweave_sso_admin_activity_page(array $args = []): string {
    $old_get = $_GET;
    $buffer_level = ob_get_level();
    try {
        $_GET = array_merge(['page' => 'portalweave-sso', 'tab' => 'reports'], $args);
        ob_start();
        \PortalWeaveSSO\Admin::page();
        return ob_get_clean();
    } finally {
        while (ob_get_level() > $buffer_level) { ob_end_clean(); }
        $_GET = $old_get;
    }
}

portalweave_sso_test('Admin sidebar registration preserves site and network capabilities and existing bookmarks', function () {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $names = ['menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', 'parent_file', 'plugin_page', 'pagenow'];
    $previous = [];
    foreach ($names as $name) {
        $previous[$name] = ['exists' => array_key_exists($name, $GLOBALS), 'value' => $GLOBALS[$name] ?? null];
        $GLOBALS[$name] = in_array($name, ['parent_file', 'plugin_page', 'pagenow'], true) ? '' : [];
    }
    $hook = 'toplevel_page_portalweave-sso';
    $had_callback = has_action($hook, [\PortalWeaveSSO\Admin::class, 'page']);
    try {
        wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
        \PortalWeaveSSO\Admin::menu();
        $entry = array_values($GLOBALS['menu'])[0];
        portalweave_sso_assert($entry[0] === 'PortalWeave SSO' && $entry[1] === 'manage_options'
            && $entry[2] === 'portalweave-sso' && $entry[5] === $hook, 'Site settings were not registered as a capability-gated top-level page');
        portalweave_sso_assert(empty($GLOBALS['submenu']['options-general.php']), 'Site menu retained a duplicate Settings entry');
        portalweave_sso_assert(get_plugin_page_hook('portalweave-sso', 'admin.php') === $hook
            && get_plugin_page_hook('portalweave-sso', 'options-general.php') === $hook, 'Existing Settings bookmarks lost the registered page callback');
        $links = apply_filters('plugin_action_links_' . plugin_basename(PORTALWEAVE_SSO_FILE), []);
        portalweave_sso_assert(str_contains(implode('', $links), admin_url('admin.php?page=portalweave-sso')), 'Plugin Settings action uses the old route');
        $GLOBALS['menu'] = [];
        \PortalWeaveSSO\Admin::network_menu();
        $network = array_values($GLOBALS['menu'])[0];
        portalweave_sso_assert($network[1] === 'manage_network_options' && $network[2] === 'portalweave-sso', 'Network menu lost its separate capability');
        wp_set_current_user(0);
        $level = ob_get_level();
        ob_start();
        try {
            \PortalWeaveSSO\Admin::page();
            portalweave_sso_assert(ob_get_contents() === '', 'An unauthorized visitor could render the settings page');
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
    } finally {
        if ($had_callback === false) remove_action($hook, [\PortalWeaveSSO\Admin::class, 'page']);
        foreach ($previous as $name => $saved) {
            if ($saved['exists']) $GLOBALS[$name] = $saved['value'];
            else unset($GLOBALS[$name]);
        }
    }
});

portalweave_sso_test('Admin form saves one screen without changing hidden providers or login policy', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $app = portalweave_sso_app();
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture', 'popup' => true]) === true);
    $before = get_option('portalweave_sso_settings');
    $destination = portalweave_sso_admin_form_submit(['button' => ['shadow' => '0', 'text' => 'Continue with {app}']], ['portalweave_sso_screen' => 'appearance']);
    portalweave_sso_assert(wp_parse_url($destination, PHP_URL_PATH) === wp_parse_url(admin_url('admin.php'), PHP_URL_PATH), 'Save did not return to the top-level admin page');
    $after = get_option('portalweave_sso_settings');
    portalweave_sso_assert($after['apps'] === $before['apps'], 'Appearance save changed provider credentials/configuration');
    portalweave_sso_assert($after['default_app'] === 'fixture' && $after['popup'] === true, 'Appearance save changed login policy');
    portalweave_sso_assert($after['button']['shadow'] === false && $after['button']['text'] === 'Continue with {app}', 'Submitted appearance fields were not saved');
    portalweave_sso_assert($after['button']['theme'] === $before['button']['theme'], 'Unsubmitted appearance fields were reset');
    parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
    portalweave_sso_assert(($query['tab'] ?? '') === 'appearance' && ($query['portalweave_sso_notice'] ?? '') === 'saved', 'Save did not return to the current screen');
});

portalweave_sso_test('Admin form rejects cross-screen fields and structured navigation before mutation', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $before = get_option('portalweave_sso_settings');
    foreach ([['appearance', ['force_auth' => '1']], [['malformed'], ['button' => ['shadow' => '0']]], ['unknown', ['popup' => '1']]] as [$screen, $settings]) {
        $destination = portalweave_sso_admin_form_submit($settings, ['portalweave_sso_screen' => $screen]);
        portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'A rejected settings scope changed the configuration');
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['portalweave_sso_notice'] ?? '') === 'error', 'A rejected scope did not report an error');
    }
    delete_transient('portalweave_sso_admin_error_' . get_current_user_id());
});

portalweave_sso_test('Activity routes expose aggregate-only overview and a separate complete audit screen', function () {
    global $wpdb;
    $admin = get_user_by('login', 'portalweave_sso_test_admin');
    wp_set_current_user($admin->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['audit_enabled' => true, 'audit_ip' => true]) === true, 'Unable to enable Activity privacy fixture');
    $marker = 'activity-ui-' . bin2hex(random_bytes(6));
    $navigation = 'https://example.org/private-' . $marker;
    $previous_ip = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.83';
        \PortalWeaveSSO\Audit::record('success', $marker, $admin, 'activity_private_fixture', $navigation);
        foreach ([
            [[], 'overview', '30'],
            [['activity_view' => 'overview', 'activity_days' => '7'], 'overview', '7'],
            [['activity_view' => 'audit', 'activity_days' => '90'], 'audit', '90'],
            [['activity_view' => 'audit', 'activity_days' => '7'], 'audit', '7'],
            [['activity_view' => 'unknown-view', 'activity_days' => '3650'], 'overview', '30'],
            [['activity_view' => ['audit'], 'activity_days' => ['90']], 'overview', '30'],
            [['activity_view' => 'audit', 'activity_days' => '7trailing'], 'audit', '30'],
        ] as [$args, $view, $days]) {
            $html = portalweave_sso_admin_activity_page($args);
            portalweave_sso_assert(str_contains($html, 'portalweave-sso-activity-tabs') && substr_count($html, 'aria-current="page"') === 1, 'Activity lost its view links or duplicated the active main navigation');
            foreach (['overview', 'audit'] as $destination) {
                portalweave_sso_assert(preg_match('/<a\b[^>]*\bdata-activity-view="' . $destination . '"[^>]*>/', $html, $link) === 1, 'Activity view has no ordinary link: ' . $destination);
                portalweave_sso_assert(str_contains($link[0], 'aria-current="true"') === ($destination === $view), 'Activity link has the wrong active state');
                portalweave_sso_assert(preg_match('/\bhref="([^"]+)"/', $link[0], $href) === 1, 'Activity link has no destination');
                parse_str((string) wp_parse_url(html_entity_decode($href[1], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);
                portalweave_sso_assert(($query['tab'] ?? '') === 'reports' && ($query['activity_view'] ?? 'overview') === $destination
                    && (string) ($query['activity_days'] ?? '30') === $days, 'Activity link lost the selected view or bounded date range');
            }
            if ($view === 'overview') {
                portalweave_sso_assert(str_contains($html, 'data-activity-overview') && !str_contains($html, 'id="portalweave-sso-reports"')
                    && !str_contains($html, 'Export audit CSV') && !str_contains($html, 'portalweave-sso-settings-form'), 'Overview rendered raw audit controls instead of aggregate-only graphs');
                foreach ([$admin->user_login, $admin->user_email, '192.0.2.83', $navigation] as $personal) {
                    portalweave_sso_assert(!str_contains($html, $personal), 'Activity overview rendered a personal audit value');
                }
            } else {
                portalweave_sso_assert(!str_contains($html, 'data-activity-overview') && str_contains($html, 'id="portalweave-sso-reports"')
                    && str_contains($html, 'Export audit CSV') && str_contains($html, 'name="settings[audit_enabled]"')
                    && str_contains($html, 'name="portalweave_sso_complete" value="1"'), 'Audit view lost its existing table, export or guarded preferences form');
                portalweave_sso_assert(str_contains($html, 'name="portalweave_sso_activity_view" value="audit"')
                    && str_contains($html, 'name="portalweave_sso_activity_days" value="' . $days . '"'), 'Audit settings form has no bounded return context');
                foreach ([$admin->user_login, $admin->user_email, '192.0.2.83', $navigation] as $personal) {
                    portalweave_sso_assert(str_contains($html, esc_html($personal)), 'Audit view lost a current-site transaction field');
                }
            }
        }
    } finally {
        $wpdb->delete($wpdb->prefix . 'portalweave_sso_audit', ['app' => $marker], ['%s']);
        if ($previous_ip === null) { unset($_SERVER['REMOTE_ADDR']); }
        else { $_SERVER['REMOTE_ADDR'] = $previous_ip; }
    }
});

portalweave_sso_test('Activity preferences retain view and date range without changing hidden providers or policies', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app()], 'default_app' => 'fixture', 'popup' => true,
        'audit_enabled' => true, 'audit_ip' => true, 'debug' => true]) === true, 'Unable to save Activity preferences fixture');
    $before = get_option('portalweave_sso_settings');
    foreach (['7', '30', '90'] as $days) {
        $destination = portalweave_sso_admin_form_submit(['audit_enabled' => '0', 'audit_ip' => '0', 'debug' => '0', 'audit_retention_days' => '37'],
            ['portalweave_sso_screen' => 'reports', 'portalweave_sso_activity_view' => 'audit', 'portalweave_sso_activity_days' => $days]);
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['tab'] ?? '') === 'reports' && ($query['activity_view'] ?? '') === 'audit'
            && (string) ($query['activity_days'] ?? '30') === $days && ($query['portalweave_sso_notice'] ?? '') === 'saved', 'Activity save lost its view or date range');
        $after = get_option('portalweave_sso_settings');
        portalweave_sso_assert(!$after['audit_enabled'] && !$after['audit_ip'] && !$after['debug'] && $after['audit_retention_days'] === 37, 'Audit preferences did not preserve explicit false controls');
        portalweave_sso_assert($after['apps'] === $before['apps'] && $after['default_app'] === 'fixture' && $after['popup'] === true, 'Activity save changed a hidden provider, ciphertext or login policy');
        portalweave_sso_assert(!isset($after['activity_view']) && !isset($after['activity_days']), 'Activity navigation entered stored configuration');
    }
    $before_error = get_option('portalweave_sso_settings');
    try {
        $destination = portalweave_sso_admin_form_submit(['popup' => '0'],
            ['portalweave_sso_screen' => 'reports', 'portalweave_sso_activity_view' => 'audit', 'portalweave_sso_activity_days' => '90']);
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['activity_view'] ?? '') === 'audit' && (string) ($query['activity_days'] ?? '') === '90'
            && ($query['portalweave_sso_notice'] ?? '') === 'error', 'Rejected Activity save lost its selected audit view');
        portalweave_sso_assert(get_option('portalweave_sso_settings') === $before_error, 'Rejected Activity scope changed settings');
    } finally { delete_transient('portalweave_sso_admin_error_' . get_current_user_id()); }
});

portalweave_sso_test('Activity POST navigation rejects structured values and bounds unknown scalar destinations', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $context = ['portalweave_sso_screen' => 'reports', 'portalweave_sso_activity_view' => 'audit', 'portalweave_sso_activity_days' => '90'];
    $before = get_option('portalweave_sso_settings');
    try {
        foreach (['portalweave_sso_activity_view' => ['audit'], 'portalweave_sso_activity_days' => ['90']] as $key => $value) {
            $destination = portalweave_sso_admin_form_submit(['audit_retention_days' => '44'], array_replace($context, [$key => $value]));
            parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
            portalweave_sso_assert(($query['portalweave_sso_notice'] ?? '') === 'error' && get_option('portalweave_sso_settings') === $before, 'Structured Activity navigation was accepted before mutation');
            portalweave_sso_assert(is_string($query['activity_view'] ?? '') && is_string($query['activity_days'] ?? ''), 'Structured Activity context entered the redirect');
        }
        foreach ([['audit_retention_days' => '44'], ['audit_enabled' => ['nested']]] as $index => $settings) {
            $destination = portalweave_sso_admin_form_submit($settings, array_replace($context,
                ['portalweave_sso_activity_view' => 'unknown-view', 'portalweave_sso_activity_days' => '7trailing']));
            parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
            portalweave_sso_assert(($query['activity_view'] ?? '') === 'audit' && (string) ($query['activity_days'] ?? '30') === '30'
                && ($query['portalweave_sso_notice'] ?? '') === ($index === 0 ? 'saved' : 'error'), 'Unknown Activity form navigation did not fall back to the audit editor and 30 days');
            portalweave_sso_assert(!str_contains($destination, 'unknown-view') && !str_contains($destination, '7trailing'), 'Unknown Activity navigation was reflected into a redirect');
        }
        portalweave_sso_assert(\PortalWeaveSSO\Config::get('audit_retention_days') === 44, 'Invalid Activity submission replaced the preceding valid preference');
    } finally { delete_transient('portalweave_sso_admin_error_' . get_current_user_id()); }
});

portalweave_sso_test('Activity overview and audit records require administrator access', function () {
    $viewer = wp_insert_user(['user_login' => 'portalweave_sso_activity_' . bin2hex(random_bytes(5)), 'user_pass' => wp_generate_password(40),
        'user_email' => 'activity-' . bin2hex(random_bytes(5)) . '@example.org', 'role' => 'subscriber']);
    portalweave_sso_assert(!is_wp_error($viewer), 'Unable to create the unprivileged Activity viewer');
    try {
        foreach ([0, $viewer] as $user_id) {
            wp_set_current_user($user_id);
            foreach (['overview', 'audit'] as $view) {
                portalweave_sso_assert(portalweave_sso_admin_activity_page(['activity_view' => $view]) === '', 'Activity exposed information to an unauthorized visitor');
            }
        }
    } finally { wp_delete_user($viewer); }
});

portalweave_sso_test('Provider sections round trip through real saves without narrowing the submitted provider', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [
        'alpha' => portalweave_sso_app(['id' => 'alpha', 'keep_email' => true, 'jwt_secret' => 'fixture-jwt-secret-at-least-thirty-two-bytes']),
        'beta' => portalweave_sso_app(['id' => 'beta', 'name' => 'Unrelated provider']),
    ], 'default_app' => 'beta', 'popup' => true]) === true, 'Unable to save provider-section fixture');
    $before = get_option('portalweave_sso_settings');
    foreach (['connection', 'endpoints', 'security', 'mapping', 'accounts', 'integrations', 'button'] as $section) {
        $destination = portalweave_sso_admin_form_submit(['apps' => ['alpha' => [
            'id' => 'alpha', 'name' => 'Edited ' . $section, 'client_secret' => '', 'jwt_secret' => '', 'keep_email' => '0',
            'mapping' => '{"email":"mail","groups":"department.groups"}', 'button_text' => 'Continue with ' . $section,
        ]]], ['portalweave_sso_screen' => 'providers', 'portalweave_sso_provider' => 'alpha', 'portalweave_sso_provider_section' => $section]);
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['provider'] ?? '') === 'alpha' && ($query['provider_section'] ?? '') === $section
            && ($query['portalweave_sso_notice'] ?? '') === 'saved', 'Provider save lost its selected section: ' . $section);
        $after = get_option('portalweave_sso_settings');
        $app = \PortalWeaveSSO\Config::app('alpha');
        portalweave_sso_assert($app['name'] === 'Edited ' . $section && $app['button_text'] === 'Continue with ' . $section
            && !$app['keep_email'] && $app['mapping']['email'] === 'mail' && $app['mapping']['groups'] === 'department.groups', 'A section save dropped fields from another section');
        foreach (['client_secret', 'jwt_secret'] as $key) {
            portalweave_sso_assert($after['apps']['alpha'][$key] === $before['apps']['alpha'][$key], 'Blank secret input changed stored ciphertext: ' . $key);
        }
        portalweave_sso_assert($after['apps']['beta'] === $before['apps']['beta'] && $after['default_app'] === 'beta' && $after['popup'] === true, 'Section navigation changed an unrelated provider or login policy');
        portalweave_sso_assert(!isset($after['provider_section']) && !isset($after['apps']['alpha']['provider_section']), 'Navigation context entered stored provider configuration');
    }
});

portalweave_sso_test('Provider section survives parser, scope and configuration errors without changing settings', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['alpha' => portalweave_sso_app(['id' => 'alpha'])]]) === true, 'Unable to save provider-error fixture');
    $before = get_option('portalweave_sso_settings');
    try {
        foreach ([
            ['mapping', ['apps' => ['alpha' => ['id' => 'alpha', 'mapping' => '{"email":']]]],
            ['accounts', ['apps' => ['alpha' => ['id' => 'alpha', 'name' => 'Must not save']], 'popup' => '1']],
            ['endpoints', ['apps' => ['alpha' => ['id' => 'alpha', 'token_url' => 'http://example.org/token']]]],
        ] as [$section, $settings]) {
            $destination = portalweave_sso_admin_form_submit($settings, ['portalweave_sso_screen' => 'providers', 'portalweave_sso_provider' => 'alpha', 'portalweave_sso_provider_section' => $section]);
            parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
            portalweave_sso_assert(($query['provider'] ?? '') === 'alpha' && ($query['provider_section'] ?? '') === $section
                && ($query['portalweave_sso_notice'] ?? '') === 'error', 'A provider error lost its selected section: ' . $section);
            portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'A rejected provider save changed settings');
        }
    } finally { delete_transient('portalweave_sso_admin_error_' . get_current_user_id()); }
});

portalweave_sso_test('Provider section rejects structured POST context and bounds unknown destinations', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['alpha' => portalweave_sso_app(['id' => 'alpha'])]]) === true, 'Unable to save section-type fixture');
    $context = ['portalweave_sso_screen' => 'providers', 'portalweave_sso_provider' => 'alpha'];
    $before = get_option('portalweave_sso_settings');
    try {
        $destination = portalweave_sso_admin_form_submit(['apps' => ['alpha' => ['id' => 'alpha', 'name' => 'Must not save']]], $context + ['portalweave_sso_provider_section' => ['accounts']]);
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['portalweave_sso_notice'] ?? '') === 'error' && ($query['provider_section'] ?? 'connection') === 'connection', 'Structured section navigation was accepted or reflected');
        portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'Structured section context changed a provider before rejection');
        foreach ([
            ['apps' => ['alpha' => ['id' => 'alpha', 'name' => 'Safe fallback']]],
            ['apps' => ['alpha' => ['id' => 'alpha', 'mapping' => '{invalid-json']]],
        ] as $index => $settings) {
            $destination = portalweave_sso_admin_form_submit($settings, $context + ['portalweave_sso_provider_section' => 'unknown-section']);
            parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
            portalweave_sso_assert(($query['provider'] ?? '') === 'alpha' && ($query['provider_section'] ?? 'connection') === 'connection'
                && ($query['portalweave_sso_notice'] ?? '') === ($index === 0 ? 'saved' : 'error'), 'Unknown scalar section did not fall back to the connection editor');
            portalweave_sso_assert(!str_contains($destination, 'unknown-section'), 'Unknown section was reflected into the return URL');
        }
        portalweave_sso_assert(\PortalWeaveSSO\Config::app('alpha')['name'] === 'Safe fallback', 'Fallback navigation prevented a valid save or allowed a rejected save');
    } finally { delete_transient('portalweave_sso_admin_error_' . get_current_user_id()); }
});

portalweave_sso_test('Deleting a provider from a section returns to the list without stale editor context', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [
        'alpha' => portalweave_sso_app(['id' => 'alpha']), 'beta' => portalweave_sso_app(['id' => 'beta']),
    ], 'default_app' => 'alpha']) === true, 'Unable to save provider-deletion fixture');
    $before = get_option('portalweave_sso_settings');
    $destination = portalweave_sso_admin_form_submit(['apps' => ['alpha' => ['id' => 'alpha', '_delete' => '1']]],
        ['portalweave_sso_screen' => 'providers', 'portalweave_sso_provider' => 'alpha', 'portalweave_sso_provider_section' => 'button']);
    parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
    portalweave_sso_assert(($query['tab'] ?? '') === 'providers' && ($query['portalweave_sso_notice'] ?? '') === 'saved'
        && !isset($query['provider']) && !isset($query['new_provider']) && !isset($query['provider_section']), 'Deletion returned to a stale provider section');
    $after = get_option('portalweave_sso_settings');
    portalweave_sso_assert(!isset($after['apps']['alpha']) && $after['apps']['beta'] === $before['apps']['beta'] && $after['default_app'] === '', 'Provider deletion changed another provider or retained the deleted default');
});

portalweave_sso_test('Provider sections render one complete secret-free form and remain usable without JavaScript', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $app = portalweave_sso_app(['id' => 'alpha', 'jwt_secret' => 'fixture-only-jwt-signing-secret-with-thirty-two-bytes']);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['alpha' => $app]]) === true, 'Unable to save provider-render fixture');
    $stored = get_option('portalweave_sso_settings');
    $original_get = $_GET;
    try {
        foreach ([['mapping', 'mapping'], ['unknown-section', 'connection'], [['accounts'], 'connection']] as [$requested, $selected]) {
            $_GET = ['page' => 'portalweave-sso', 'tab' => 'providers', 'provider' => 'alpha', 'provider_section' => $requested];
            ob_start();
            \PortalWeaveSSO\Admin::page();
            $html = ob_get_clean();
            portalweave_sso_assert(preg_match('/<[^>]*\bdata-provider-editor\b[^>]*>/', $html, $editor) === 1
                && str_contains($editor[0], 'data-provider-section="' . $selected . '"'), 'Provider editor did not bound its selected section');
            portalweave_sso_assert(substr_count($html, '<form ') === 1 && str_contains($html, 'name="portalweave_sso_complete" value="1"'), 'Section rendering split the form or lost the truncation guard');
            portalweave_sso_assert(str_contains($html, 'name="portalweave_sso_provider_section" value="' . $selected . '"'), 'Selected section has no separate POST navigation context');
            foreach (['connection', 'endpoints', 'security', 'mapping', 'accounts', 'integrations', 'button'] as $section) {
                $panel_id = 'portalweave-sso-provider-panel-' . $section;
                portalweave_sso_assert(preg_match('/<a\b[^>]*\bdata-provider-tab="' . $section . '"[^>]*>/', $html, $tab) === 1
                    && str_contains($tab[0], 'id="portalweave-sso-provider-tab-' . $section . '"') && str_contains($tab[0], 'href="#' . $panel_id . '"'), 'A provider section has no ordinary anchor navigation: ' . $section);
                portalweave_sso_assert(preg_match('/<div\b[^>]*\bdata-provider-panel="' . $section . '"[^>]*>/', $html, $panel) === 1
                    && str_contains($panel[0], 'id="' . $panel_id . '"') && !preg_match('/\shidden(?:\s|=|>)|aria-hidden="true"|display\s*:\s*none/', $panel[0]), 'A section is absent or hidden before JavaScript: ' . $section);
            }
            foreach (['name', 'token_url', 'algorithms', 'mapping', 'verified_attribute', 'avatar_attribute', 'button_text'] as $field) {
                portalweave_sso_assert(substr_count($html, 'name="settings[apps][alpha][' . $field . ']"') === 1, 'A section field is missing or duplicated: ' . $field);
            }
            foreach (['client_secret', 'jwt_secret'] as $key) {
                portalweave_sso_assert(!str_contains($html, $app[$key]) && !str_contains($html, $stored['apps']['alpha'][$key]), 'Section rendering disclosed a stored credential: ' . $key);
                portalweave_sso_assert(preg_match('/<input\b(?=[^>]*\bname="settings\[apps\]\[alpha\]\[' . $key . '\]")(?=[^>]*\btype="password")(?=[^>]*\bvalue="")[^>]*>/', $html) === 1, 'Stored secret is not represented by an empty password input: ' . $key);
            }
        }
    } finally { $_GET = $original_get; }
});

portalweave_sso_test('Guided provider form creates one connection and returns to its advanced editor', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['existing' => portalweave_sso_app(['id' => 'existing'])], 'default_app' => 'existing']) === true, 'Existing provider fixture failed');
    $before = get_option('portalweave_sso_settings');
    $secret = "  Fixture-Only+Secret\\with<>&\"'bytes  ";
    $payload = ['provider' => 'discord', 'id' => 'guided-discord', 'name' => 'Community Discord', 'callback_registered' => '1', 'client_id' => 'discord-web-client', 'client_secret' => $secret];
    $context = ['portalweave_sso_screen' => 'providers', 'portalweave_sso_creating' => '1', 'portalweave_sso_provider' => '', 'portalweave_sso_provider_section' => 'accounts'];
    $destination = portalweave_sso_admin_form_submit(['provider_setup' => $payload], $context);
    $after = get_option('portalweave_sso_settings');
    $app = \PortalWeaveSSO\Config::app('guided-discord');
    portalweave_sso_assert(is_array($app) && $app['client_secret'] === $secret && $app['issuer'] === 'https://discord.com' && $app['verified_attribute'] === 'verified', 'Guided form lost its provider profile or opaque secret');
    portalweave_sso_assert($after['apps']['existing'] === $before['apps']['existing'] && $after['default_app'] === 'existing', 'Guided form changed another provider or default');
    parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
    portalweave_sso_assert(($query['provider'] ?? '') === 'guided-discord' && ($query['portalweave_sso_notice'] ?? '') === 'saved', 'Guided form did not return to the created provider');
    portalweave_sso_assert(($query['provider_section'] ?? 'connection') === 'connection', 'Guided creation inherited a stale advanced-editor section');
    portalweave_sso_assert(!str_contains($destination, $secret) && !str_contains($destination, $payload['client_id']), 'Guided redirect disclosed credentials');
    $destination = portalweave_sso_admin_form_submit(['provider_setup' => $payload], $context);
    parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
    portalweave_sso_assert(($query['portalweave_sso_notice'] ?? '') === 'error' && ($query['setup_provider'] ?? '') === 'discord', 'Duplicate creation lost the selected recipe or failed to report an error');
    portalweave_sso_assert(get_option('portalweave_sso_settings') === $after, 'Duplicate guided creation replaced a provider');
    $error = get_transient('portalweave_sso_admin_error_' . get_current_user_id());
    portalweave_sso_assert(is_string($error) && !str_contains($error, $secret) && !str_contains($error, $payload['client_id']), 'Guided error retained credentials');
    delete_transient('portalweave_sso_admin_error_' . get_current_user_id());
});

portalweave_sso_test('Guided provider form rejects nested values and cross-screen submissions before saving', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $before = get_option('portalweave_sso_settings');
    $payload = ['provider' => 'discord', 'id' => 'rejected-discord', 'callback_registered' => '1', 'client_id' => 'discord-web-client', 'client_secret' => 'Fixture-Only+Secret-never-saved'];
    foreach ([
        [array_replace($payload, ['client_secret' => ['nested']]), ['portalweave_sso_screen' => 'providers', 'portalweave_sso_creating' => '1']],
        [$payload, ['portalweave_sso_screen' => 'appearance', 'portalweave_sso_creating' => '1']],
        [$payload, ['portalweave_sso_screen' => 'providers', 'portalweave_sso_provider' => 'existing', 'portalweave_sso_creating' => '0']],
    ] as [$setup, $context]) {
        $destination = portalweave_sso_admin_form_submit(['provider_setup' => $setup], $context);
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        portalweave_sso_assert(($query['portalweave_sso_notice'] ?? '') === 'error' && get_option('portalweave_sso_settings') === $before, 'Malformed or retargeted guided form mutated settings');
    }
    delete_transient('portalweave_sso_admin_error_' . get_current_user_id());
});

portalweave_sso_test('Provider wizard renders only selected recipe fields with a no-JavaScript save path', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $original_get = $_GET;
    try {
        foreach (['discord', 'authentik', 'onelogin'] as $provider) {
            $_GET = ['page' => 'portalweave-sso', 'tab' => 'providers', 'new_provider' => '1', 'setup_provider' => $provider];
            ob_start();
            \PortalWeaveSSO\Admin::page();
            $html = ob_get_clean();
            portalweave_sso_assert(str_contains($html, 'data-setup-step="registration"') && str_contains($html, 'data-setup-fallback-submit') && str_contains($html, 'name="portalweave_sso_complete" value="1"'), 'Wizard lost its progressive-enhancement or form guard contract');
            portalweave_sso_assert(!str_contains($html, 'name="settings[default_app]"') && !str_contains($html, 'name="settings[new_app]'), 'Wizard included unrelated or advanced submitted configuration');
            portalweave_sso_assert(str_contains($html, 'name="settings[provider_setup][client_id]"'), 'Wizard has no client ID input');
            if ($provider === 'discord') {
                portalweave_sso_assert(!str_contains($html, 'data-setup-step="location"') && !str_contains($html, 'name="settings[provider_setup][authorize_url]"'), 'Discord wizard asks for unnecessary server details');
            } elseif ($provider === 'authentik') {
                portalweave_sso_assert(str_contains($html, 'name="settings[provider_setup][host]"') && str_contains($html, 'name="settings[provider_setup][application]"') && str_contains($html, 'portalweave_sso_action=logout_callback'), 'authentik wizard omitted its instance, application or logout return URI');
            } else {
                portalweave_sso_assert(!str_contains($html, 'name="settings[provider_setup][client_secret]"'), 'OneLogin public-client recipe asks for an unused secret');
            }
        }
    } finally { $_GET = $original_get; }
});

portalweave_sso_test('Every provider recipe displays a bundled local logo with an accessible service name', function () {
    $base = admin_url('admin.php?page=portalweave-sso&tab=providers&new_provider=1');
    foreach (\PortalWeaveSSO\ProviderSetup::catalog() as $key => $recipe) {
        ob_start();
        \PortalWeaveSSO\ProviderWizard::render($key, false, false, $base);
        $html = ob_get_clean();
        portalweave_sso_assert(preg_match('/<img src="([^"]+)"[^>]* alt=""/', $html, $matches) === 1, 'A provider recipe has no bundled logo: ' . $key);
        $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $directory = plugins_url('assets/providers/', PORTALWEAVE_SSO_FILE);
        portalweave_sso_assert(str_starts_with($url, $directory), 'Provider logo uses a remote or unexpected source: ' . $key);
        $file = basename((string) wp_parse_url($url, PHP_URL_PATH));
        portalweave_sso_assert(in_array(pathinfo($file, PATHINFO_EXTENSION), ['svg', 'png'], true) && is_file(PORTALWEAVE_SSO_DIR . 'assets/providers/' . $file), 'Provider logo is missing or has an unsupported format: ' . $key);
        portalweave_sso_assert(str_contains($html, '<strong>' . esc_html($recipe['label']) . '</strong>'), 'Provider logo has no adjacent accessible service name: ' . $key);
    }
});
