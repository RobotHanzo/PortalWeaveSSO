<?php
/** Independent WordPress login appearance uses the real settings and renderer boundaries. */
function portalweave_sso_button_render(string $page, string $surface = 'buttons'): array {
    $scripts = $GLOBALS['wp_scripts'] ?? null;
    $styles = $GLOBALS['wp_styles'] ?? null;
    $previous_page = $GLOBALS['pagenow'] ?? null;
    $user = get_current_user_id();
    try {
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        $GLOBALS['wp_styles'] = new WP_Styles();
        $GLOBALS['pagenow'] = $page;
        wp_set_current_user(0);
        if ($surface === 'shortcode') {
            $html = do_shortcode('[portalweave_sso_login]');
        } elseif ($surface === 'widget') {
            ob_start();
            try {
                (new \PortalWeaveSSO\Login_Widget())->widget(['before_widget' => '', 'after_widget' => '', 'before_title' => '', 'after_title' => ''], []);
            } finally { $html = ob_get_clean(); }
        } elseif ($surface === 'woocommerce') {
            ob_start();
            try { do_action('woocommerce_login_form_end'); }
            finally { $html = ob_get_clean(); }
        } else {
            $html = \PortalWeaveSSO\Display::buttons();
        }
        return ['html' => $html, 'css' => implode("\n", (array) wp_styles()->get_data('portalweave-sso-buttons', 'after')),
            'styled' => wp_style_is('portalweave-sso-buttons', 'enqueued'), 'script' => wp_script_is('portalweave-sso-login', 'enqueued')];
    } finally {
        $GLOBALS['wp_scripts'] = $scripts;
        $GLOBALS['wp_styles'] = $styles;
        $GLOBALS['pagenow'] = $previous_page;
        wp_set_current_user($user);
    }
}

function portalweave_sso_button_save(array $input): string {
    $previous_user = get_current_user_id();
    $post = $_POST;
    $request = $_REQUEST;
    $destination = '';
    $redirect = static function ($url) use (&$destination) { $destination = $url; throw new RuntimeException('button_expected_redirect'); };
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    portalweave_sso_assert(!empty($admins), 'Administrator fixture is required for the Appearance POST');
    add_filter('wp_redirect', $redirect);
    try {
        wp_set_current_user($admins[0]->ID);
        $_POST = ['settings' => wp_slash($input), 'portalweave_sso_screen' => 'appearance', 'portalweave_sso_complete' => '1', '_wpnonce' => wp_create_nonce('portalweave_sso_save')];
        $_REQUEST = $_POST;
        try { \PortalWeaveSSO\Admin::save(); }
        catch (RuntimeException $error) { if ($error->getMessage() !== 'button_expected_redirect') { throw $error; } }
    } finally {
        remove_filter('wp_redirect', $redirect);
        $_POST = $post;
        $_REQUEST = $request;
        wp_set_current_user($previous_user);
    }
    portalweave_sso_assert($destination !== '', 'Appearance POST did not redirect');
    return $destination;
}

portalweave_sso_test('WordPress sign-in appearance defaults to shared styling and validates bounded structured controls', function () {
    $defaults = \PortalWeaveSSO\Config::defaults()['wp_login_button'];
    portalweave_sso_assert($defaults['enabled'] === false && $defaults['text'] === '' && $defaults['icon_source'] === 'provider', 'Independent WordPress styling must be opt-in');
    foreach ([
        [['radius' => 999, 'font_size' => '999', 'padding_y' => 999, 'padding_x' => 999, 'icon_size' => 999], [50, 32, 24, 40, 48]],
        [['radius' => -9, 'font_size' => '-9', 'padding_y' => -9, 'padding_x' => -9, 'icon_size' => -9], [0, 10, 8, 8, 12]],
    ] as [$input, $expected]) {
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['wp_login_button' => $input + ['background' => '#AbC', 'full_width' => '0', 'shadow' => '1']]) === true, 'Valid structured appearance was rejected');
        $style = \PortalWeaveSSO\Config::get('wp_login_button');
        foreach (array_combine(['radius', 'font_size', 'padding_y', 'padding_x', 'icon_size'], $expected) as $key => $value) {
            portalweave_sso_assert($style[$key] === $value, 'WordPress button dimension was not bounded as an integer: ' . $key);
        }
        portalweave_sso_assert($style['background'] === '#abc' && $style['full_width'] === false && $style['shadow'] === true, 'Short hex colors or explicit false checkbox values were lost');
    }
});

portalweave_sso_test('WordPress sign-in appearance rejects malformed styles and icon URLs without mutating settings', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app(['id' => 'fixture'])], 'default_app' => 'fixture']) === true, 'Appearance validation fixture failed');
    $before = get_option('portalweave_sso_settings');
    foreach ([
        'not-a-map', ['enabled' => ['nested']], ['enabled' => 'yes'], ['text' => ['nested']], ['text' => str_repeat('a', 201)],
        ['background' => true], ['background' => '#fff;}body{display:none}'], ['text_color' => 'red'], ['border_color' => '#12345'],
        ['hover_background' => 'url(https://images.example.org/pixel)'], ['hover_text_color' => '#123456; color:red'],
        ['radius' => '12px'], ['font_size' => []], ['padding_x' => 12.5], ['icon_size' => new stdClass()],
        ['icon_source' => 'remote'], ['icon_position' => 'top'], ['icon_url' => ['nested']],
        ['enabled' => true, 'icon_source' => 'custom', 'icon_url' => ''],
        ['icon_url' => 'http://images.example.org/logo.png'], ['icon_url' => 'javascript:alert(1)'],
        ['icon_url' => 'data:image/svg+xml,unsafe'], ['icon_url' => 'https://user:password@images.example.org/logo.png'],
        ['icon_url' => 'https://images.example.org/logo.png#fragment'], ['icon_url' => "https://images.example.org/logo\n.png"],
        ['custom_css' => 'body{display:none}'],
    ] as $input) {
        portalweave_sso_assert(is_wp_error(\PortalWeaveSSO\Config::save(['wp_login_button' => $input])), 'Malformed WordPress button settings were accepted');
        portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'Rejected WordPress appearance changed existing settings or credentials');
    }
    foreach ([['background' => '#fff;}body{display:none}'], ['radius' => '1px; color:red'], ['icon_url' => 'javascript:alert(1)']] as $input) {
        portalweave_sso_assert(\PortalWeaveSSO\Display::wp_login_css($input) === '', 'Preview CSS rendered unvalidated appearance values');
    }
});

portalweave_sso_test('Appearance scope preserves hidden providers and redacts custom icon query data', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app(['id' => 'fixture'])], 'default_app' => 'fixture', 'popup' => true,
        'button' => ['theme' => 'white', 'text' => 'Shared {app}', 'custom_css' => '.portalweave-sso-login{--shared-fixture:1}'],
        'wp_login_button' => ['enabled' => true, 'radius' => 17, 'icon_source' => 'custom', 'icon_url' => 'https://images.example.org/logo.png?private=icon-query-fixture']]) === true, 'Partial appearance fixture failed');
    $before = get_option('portalweave_sso_settings');
    $candidate = \PortalWeaveSSO\AdminSettings::prepare(['wp_login_button' => ['text' => 'Portal {app}', 'shadow' => '0']], 'appearance');
    portalweave_sso_assert(is_array($candidate) && !isset($candidate['apps']), 'Scoped WordPress appearance preparation forwarded hidden providers');
    $destination = portalweave_sso_button_save(['wp_login_button' => ['text' => 'Portal {app}', 'shadow' => '0']]);
    parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
    portalweave_sso_assert(($query['tab'] ?? '') === 'appearance' && ($query['portalweave_sso_notice'] ?? '') === 'saved', 'Actual Appearance POST did not return to the saved screen');
    $after = get_option('portalweave_sso_settings');
    portalweave_sso_assert($after['apps'] === $before['apps'] && $after['button'] === $before['button'] && $after['default_app'] === 'fixture' && $after['popup'], 'Scoped appearance save changed shared style, provider ciphertext or login policy');
    portalweave_sso_assert($after['wp_login_button']['text'] === 'Portal {app}' && !$after['wp_login_button']['shadow']
        && $after['wp_login_button']['radius'] === 17 && $after['wp_login_button']['icon_url'] === $before['wp_login_button']['icon_url'], 'Partial appearance save reset omitted controls');
    portalweave_sso_error(\PortalWeaveSSO\AdminSettings::prepare(['wp_login_button' => ['enabled' => '1']], 'login'), 'portalweave_sso_settings_scope');
    portalweave_sso_assert(is_wp_error(\PortalWeaveSSO\AdminSettings::prepare(['wp_login_button' => ['text' => ['nested']]], 'appearance')), 'Nested appearance values escaped scoped validation');
    $redact = new ReflectionMethod(\PortalWeaveSSO\Admin::class, 'redact');
    foreach ([\PortalWeaveSSO\Config::redacted(), $redact->invoke(null, \PortalWeaveSSO\Config::all())] as $export) {
        portalweave_sso_assert($export['wp_login_button']['icon_url'] === 'https://images.example.org/logo.png'
            && !str_contains(wp_json_encode($export), 'icon-query-fixture'), 'A configuration export disclosed custom icon query data');
    }
});

portalweave_sso_test('Independent WordPress appearance leaves shortcode, widget and WooCommerce styles unchanged', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app(['id' => 'fixture', 'name' => 'Workforce', 'provider' => 'google', 'button_text' => 'Existing provider label'])],
        'button' => ['theme' => 'white', 'admin_style' => false, 'custom_css' => '.portalweave-sso-login{--shared-only-fixture:1}']]) === true, 'Renderer isolation fixture failed');
    $legacy = portalweave_sso_button_render('wp-login.php');
    portalweave_sso_assert(!$legacy['styled'] && $legacy['script'] && str_contains($legacy['html'], 'Existing provider label')
        && !str_contains($legacy['html'], 'portalweave-sso-login-screen'), 'Opt-out WordPress login no longer inherits the existing unstyled behavior');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['wp_login_button' => ['enabled' => true, 'text' => 'Portal {app}', 'background' => '#123456',
        'font_size' => 19, 'radius' => 11, 'icon_size' => 27, 'icon_position' => 'right']]) === true, 'Independent renderer fixture failed');
    $wp = portalweave_sso_button_render('wp-login.php');
    portalweave_sso_assert($wp['styled'] && $wp['script'] && str_contains($wp['html'], 'portalweave-sso-login-screen')
        && str_contains($wp['html'], 'portalweave-sso-theme-custom') && str_contains($wp['html'], 'Portal Workforce')
        && !str_contains($wp['html'], 'Existing provider label'), 'WordPress-only appearance or label override was not applied');
    portalweave_sso_assert(str_contains($wp['css'], '.portalweave-sso-login.portalweave-sso-login-screen') && str_contains($wp['css'], '#123456')
        && str_contains($wp['css'], '19px') && str_contains($wp['css'], '11px') && str_contains($wp['css'], '27px')
        && !str_contains($wp['css'], 'shared-only-fixture'), 'Structured WordPress CSS lost its scope or included arbitrary shared CSS');
    portalweave_sso_assert(str_contains($wp['html'], 'portalweave-sso-icon-right'), 'Configured icon placement was ignored');
    foreach (['buttons', 'shortcode', 'widget', 'woocommerce'] as $surface) {
        $public = portalweave_sso_button_render('index.php', $surface);
        portalweave_sso_assert($public['styled'] && str_contains($public['html'], 'portalweave-sso-theme-white') && str_contains($public['html'], 'Existing provider label')
            && !str_contains($public['html'], 'portalweave-sso-login-screen') && !str_contains($public['html'], 'Portal Workforce'), 'Independent styling leaked into a public placement: ' . $surface);
        portalweave_sso_assert(!str_contains($public['css'], 'shared-only-fixture') && !str_contains($public['css'], '#123456'), 'Public placement rendered retired arbitrary CSS or inherited WordPress override values');
    }
});

portalweave_sso_test('WordPress provider icons use bundled safe defaults, custom precedence or an explicit no-icon choice', function () {
    $requests = [];
    $capture = static function ($pre, $args, $url) use (&$requests) {
        $requests[] = $url;
        return new WP_Error('portalweave_sso_unexpected_icon_request', 'Rendering icons must not make an HTTP request.');
    };
    add_filter('pre_http_request', $capture, 10, 3);
    try {
        foreach (['google' => 'google.svg', 'onelogin' => 'onelogin.png', 'classlink' => 'classlink.png', '../../outside' => 'custom.svg'] as $provider => $file) {
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app(['id' => 'fixture', 'provider' => $provider])],
                'wp_login_button' => ['enabled' => true, 'icon_source' => 'provider']]) === true, 'Provider icon fixture failed');
            $html = portalweave_sso_button_render('wp-login.php')['html'];
            portalweave_sso_assert(str_contains($html, '/assets/providers/' . $file) && !str_contains($html, 'portalweave-sso-mark'), 'Provider icon did not use its bundled allowlisted fallback');
        }
        $provider_icon = 'https://images.example.org/provider.png';
        $custom_icon = 'https://images.example.org/wordpress.png';
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app(['id' => 'fixture', 'provider' => 'google', 'icon_url' => $provider_icon])]]) === true, 'Custom provider icon fixture failed');
        $html = portalweave_sso_button_render('wp-login.php')['html'];
        portalweave_sso_assert(str_contains($html, $provider_icon) && !str_contains($html, '/providers/google.svg') && str_contains($html, 'alt=""')
            && str_contains($html, 'referrerpolicy="no-referrer"'), 'Configured provider image did not take precedence or lost its privacy attributes');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['wp_login_button' => ['icon_source' => 'custom', 'icon_url' => $custom_icon]]) === true, 'Custom WordPress icon was rejected');
        $html = portalweave_sso_button_render('wp-login.php')['html'];
        portalweave_sso_assert(str_contains($html, $custom_icon) && !str_contains($html, $provider_icon), 'WordPress icon override did not replace the per-provider image');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['wp_login_button' => ['icon_source' => 'none', 'text' => 'SSO <img src=x onerror=alert(1)> {app}']]) === true, 'No-icon appearance was rejected');
        $html = portalweave_sso_button_render('wp-login.php')['html'];
        portalweave_sso_assert(!str_contains($html, '<img') && !str_contains($html, 'portalweave-sso-mark') && str_contains($html, 'SSO '), 'No-icon mode retained an image/initial or removed the button label');
        portalweave_sso_assert(!preg_match('/<(?:script|svg)\b|<[^>]+\son[a-z]+\s*=/i', $html), 'WordPress button text emitted executable markup');
        portalweave_sso_assert($requests === [], 'Rendering an icon made a server-side image request');
    } finally { remove_filter('pre_http_request', $capture, 10); }
});

portalweave_sso_test('Empty WordPress label override retains existing labels and an accessible fallback', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app(['id' => 'fixture', 'name' => 'Workforce', 'button_text' => 'Provider sign-in'])],
        'wp_login_button' => ['enabled' => true, 'text' => '', 'icon_source' => 'none']]) === true, 'Empty-label fixture failed');
    portalweave_sso_assert(str_contains(portalweave_sso_button_render('wp-login.php')['html'], 'Provider sign-in'), 'An empty override replaced the provider-specific label');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app(['id' => 'fixture', 'name' => 'Workforce', 'button_text' => ''])], 'button' => ['text' => '']]) === true, 'Blank shared-label fixture failed');
    portalweave_sso_assert(str_contains(portalweave_sso_button_render('wp-login.php')['html'], 'Sign in with Workforce'), 'WordPress custom mode produced an icon-only or empty sign-in link');
});

if (is_multisite()) {
    portalweave_sso_test('Network WordPress appearance saves preserve local styles and enforce the active network policy', function () {
        $network = get_site_option('portalweave_sso_settings', []);
        try {
            update_site_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults());
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['local' => portalweave_sso_app(['id' => 'local', 'client_secret' => 'local-fixture-secret-with-thirty-two-bytes'])],
                'default_app' => 'local', 'wp_login_button' => ['enabled' => true, 'text' => 'Local {app}']]) === true, 'Local appearance fixture failed');
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['network' => portalweave_sso_app(['id' => 'network', 'client_secret' => 'network-fixture-secret-with-thirty-two-bytes'])],
                'default_app' => 'network'], true) === true, 'Network appearance fixture failed');
            $local = get_option('portalweave_sso_settings');
            $prior = get_site_option('portalweave_sso_settings');
            $candidate = \PortalWeaveSSO\AdminSettings::prepare(['wp_login_button' => ['enabled' => '1', 'text' => 'Network {app}']], 'appearance', '', false, true);
            portalweave_sso_assert(is_array($candidate) && !isset($candidate['apps']) && \PortalWeaveSSO\Config::save($candidate, true) === true, 'Scoped network appearance save failed');
            $after = get_site_option('portalweave_sso_settings');
            portalweave_sso_assert($after['apps'] === $prior['apps'] && $after['default_app'] === 'network' && get_option('portalweave_sso_settings') === $local, 'Network appearance changed provider ciphertext or local policy');
            portalweave_sso_assert(\PortalWeaveSSO\Config::get('wp_login_button')['text'] === 'Local {app}', 'An inactive network candidate overrode local appearance');
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['network_enforced' => true], true) === true, 'Unable to enforce network appearance');
            portalweave_sso_assert(\PortalWeaveSSO\Config::get('wp_login_button')['text'] === 'Network {app}', 'Enforced network appearance was ignored');
            portalweave_sso_error(\PortalWeaveSSO\Config::save(['wp_login_button' => ['text' => 'Must not save']]), 'portalweave_sso_network_enforced');
            portalweave_sso_assert(get_option('portalweave_sso_settings') === $local, 'A rejected local save changed hidden appearance or credentials');
        } finally { update_site_option('portalweave_sso_settings', $network); }
    });
}
