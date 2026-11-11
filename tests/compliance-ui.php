<?php
/** Regressions for WordPress directory escaping, asset scope, and admin boundaries. */
portalweave_sso_test('Compliance: widget wrappers and session messages reject active markup', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app()], 'button' => ['logout_message' => '<strong>Welcome ##user##</strong> ##Sign Out## <a href="javascript:alert(1)" onclick="alert(1)">unsafe</a><script>alert(1)</script>']]) === true, 'Fixture configuration failed');
    wp_set_current_user(0);
    $widget = new \PortalWeaveSSO\Login_Widget();
    ob_start();
    try {
        $widget->widget([
            'before_widget' => '<aside class="widget" onclick="alert(1)"><script>alert(1)</script>',
            'before_title' => '<h2 onmouseover="alert(1)">', 'after_title' => '</h2>',
            'after_widget' => '<img src="x" onerror="alert(1)"></aside>',
        ], ['title' => '<svg onload="alert(1)">Title</svg>', 'app' => 'fixture']);
    } finally {
        $html = ob_get_clean();
    }
    portalweave_sso_assert(str_contains($html, '<aside class="widget">') && str_contains($html, 'portalweave-sso-button'), 'Escaping removed valid widget structure or login links');
    portalweave_sso_assert(!preg_match('/<(?:script|svg)\b|<[^>]+\son[a-z]+\s*=/i', $html), 'Widget emitted executable markup');
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $html = do_shortcode('[portalweave_sso_login]');
    portalweave_sso_assert(str_contains($html, '<strong>Welcome ') && str_contains($html, 'portalweave-sso-logout'), 'Session placeholders or permitted message markup broke');
    portalweave_sso_assert(!str_contains($html, '##user##') && !str_contains($html, '##Sign Out##'), 'Session placeholders were not replaced');
    portalweave_sso_assert(!preg_match('/<script\b|<[^>]+\son[a-z]+\s*=|href\s*=\s*["\']javascript:/i', $html), 'Session message emitted executable markup');
});

portalweave_sso_test('Compliance: login assets load only for relevant content and use deferred scripts', function () {
    $old_scripts = $GLOBALS['wp_scripts'] ?? null;
    $old_styles = $GLOBALS['wp_styles'] ?? null;
    $old_query = $GLOBALS['wp_query'] ?? null;
    $old_pagenow = $GLOBALS['pagenow'] ?? null;
    $empty_widgets = static fn() => [];
    add_filter('sidebars_widgets', $empty_widgets);
    try {
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        $GLOBALS['wp_styles'] = new WP_Styles();
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->posts = [];
        $GLOBALS['pagenow'] = 'index.php';
        \PortalWeaveSSO\Display::frontend_assets();
        portalweave_sso_assert(!wp_style_is('portalweave-sso-buttons', 'enqueued') && !wp_script_is('portalweave-sso-login', 'enqueued'), 'Unrelated pages load login assets');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app()]]) === true, 'Fixture configuration failed');
        wp_set_current_user(0);
        $GLOBALS['wp_query']->posts = [new WP_Post((object) ['ID' => 0, 'post_content' => '[portalweave_sso_login]'])];
        \PortalWeaveSSO\Display::frontend_assets();
        portalweave_sso_assert(wp_style_is('portalweave-sso-buttons', 'enqueued') && wp_script_is('portalweave-sso-login', 'enqueued'), 'Shortcode content did not enqueue its assets');
        portalweave_sso_assert(wp_scripts()->get_data('portalweave-sso-login', 'strategy') === 'defer', 'Popup script does not use WordPress defer strategy');
        ob_start();
        try {
            \PortalWeaveSSO\Display::late_styles();
            \PortalWeaveSSO\Display::late_styles();
        } finally {
            $html = ob_get_clean();
        }
        portalweave_sso_assert(preg_match_all('/id=["\']portalweave-sso-buttons-css["\']/', $html) === 1, 'Late shortcode styles were omitted or printed more than once');
        wp_dequeue_script('portalweave-sso-login');
        wp_dequeue_style('portalweave-sso-buttons');
        do_shortcode('[portalweave_sso_login]');
        portalweave_sso_assert(wp_script_is('portalweave-sso-login', 'enqueued'), 'Template-rendered shortcode lost popup behavior');
    } finally {
        remove_filter('sidebars_widgets', $empty_widgets);
        $GLOBALS['wp_scripts'] = $old_scripts;
        $GLOBALS['wp_styles'] = $old_styles;
        $GLOBALS['wp_query'] = $old_query;
        $GLOBALS['pagenow'] = $old_pagenow;
    }
});

portalweave_sso_test('Compliance: content policy writes require a scalar nonce, permission and valid field types', function () {
    $old_post = $_POST;
    $post_id = wp_insert_post(['post_title' => 'Compliance policy fixture', 'post_status' => 'draft']);
    $term = wp_insert_term('Compliance policy ' . wp_generate_password(8, false), 'category');
    portalweave_sso_assert($post_id > 0 && !is_wp_error($term), 'Policy fixtures failed');
    $term_id = $term['term_id'];
    $prior = ['login' => true, 'roles' => ['editor'], 'groups' => ['staff']];
    update_post_meta($post_id, '_portalweave_sso_rule', $prior);
    update_term_meta($term_id, '_portalweave_sso_rule', $prior);
    try {
        $admin = get_user_by('login', 'portalweave_sso_test_admin');
        wp_set_current_user($admin->ID);
        foreach (['forged', ['malformed']] as $nonce) {
            $_POST = ['portalweave_sso_policy_nonce' => $nonce, 'portalweave_sso_rule' => ['login' => '0']];
            \PortalWeaveSSO\Admin::save_post($post_id, get_post($post_id));
            \PortalWeaveSSO\Admin::save_category($term_id);
            portalweave_sso_assert(get_post_meta($post_id, '_portalweave_sso_rule', true) === $prior && get_term_meta($term_id, '_portalweave_sso_rule', true) === $prior, 'Invalid nonce changed an access policy');
        }
        $_POST = ['portalweave_sso_policy_nonce' => wp_create_nonce('portalweave_sso_policy'), 'portalweave_sso_rule' => ['roles' => [['administrator']]]];
        \PortalWeaveSSO\Admin::save_post($post_id, get_post($post_id));
        \PortalWeaveSSO\Admin::save_category($term_id);
        portalweave_sso_assert(get_post_meta($post_id, '_portalweave_sso_rule', true) === $prior && get_term_meta($term_id, '_portalweave_sso_rule', true) === $prior, 'Malformed nested policy changed existing restrictions');
        $_POST = ['portalweave_sso_policy_nonce' => wp_create_nonce('portalweave_sso_policy'), 'portalweave_sso_rule' => ['login' => '1', 'roles' => 'editor, subscriber', 'groups' => '<b>staff</b>,students']];
        wp_set_current_user(0);
        \PortalWeaveSSO\Admin::save_post($post_id, get_post($post_id));
        portalweave_sso_assert(get_post_meta($post_id, '_portalweave_sso_rule', true) === $prior, 'Anonymous policy write succeeded');
        wp_set_current_user($admin->ID);
        \PortalWeaveSSO\Admin::save_post($post_id, get_post($post_id));
        \PortalWeaveSSO\Admin::save_category($term_id);
        $expected = ['login' => true, 'roles' => ['editor', 'subscriber'], 'groups' => ['staff', 'students']];
        portalweave_sso_assert(get_post_meta($post_id, '_portalweave_sso_rule', true) === $expected && get_term_meta($term_id, '_portalweave_sso_rule', true) === $expected, 'Valid policy values were not safely saved');
    } finally {
        $_POST = $old_post;
        wp_delete_post($post_id, true);
        wp_delete_term($term_id, 'category');
    }
});

portalweave_sso_test('Compliance: every admin action rejects missing, nested and forged security tokens', function () {
    $old_post = $_POST;
    $old_request = $_REQUEST;
    $old_get = $_GET;
    $handler = static fn() => static function () { throw new RuntimeException('portalweave_sso_expected_security_rejection'); };
    add_filter('wp_die_handler', $handler);
    try {
        wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
        $prior = get_option('portalweave_sso_settings');
        foreach (['save', 'export', 'import', 'support'] as $action) {
            foreach ([null, ['nested'], 'forged'] as $nonce) {
                $_POST = ['settings' => ['apps' => 'malformed'], 'portalweave_sso_complete' => '1'];
                $_GET = [];
                $_REQUEST = $nonce === null ? [] : ['_wpnonce' => $nonce];
                $rejected = false;
                try {
                    \PortalWeaveSSO\Admin::$action();
                } catch (RuntimeException $error) {
                    $rejected = $error->getMessage() === 'portalweave_sso_expected_security_rejection';
                }
                portalweave_sso_assert($rejected, 'Admin action accepted an invalid nonce: ' . $action);
                portalweave_sso_assert(get_option('portalweave_sso_settings') === $prior, 'Rejected admin action changed configuration');
            }
        }
    } finally {
        remove_filter('wp_die_handler', $handler);
        $_POST = $old_post;
        $_GET = $old_get;
        $_REQUEST = $old_request;
    }
});
