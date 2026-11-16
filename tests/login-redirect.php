<?php
portalweave_sso_test('SSO links retain nested redirect queries and fragments as one encoded value', function () {
    $destination = home_url('/members/?page=2&sort=name&next=%2Fprofile%2F#account');
    foreach ([\PortalWeaveSSO\Auth::login_url('fixture', $destination), \PortalWeaveSSO\Auth::logout_url($destination)] as $url) {
        parse_str(wp_parse_url($url, PHP_URL_QUERY), $params);
        portalweave_sso_assert(($params['redirect_to'] ?? '') === $destination, 'Nested redirect URL was split or decoded twice');
        portalweave_sso_assert(!isset($params['sort']) && !isset($params['next']), 'Destination query leaked into the SSO request');
        portalweave_sso_assert(wp_parse_url($url, PHP_URL_FRAGMENT) === null, 'Destination fragment escaped the parameter');
    }
});

portalweave_sso_test('Login redirect parameters validate ordered destinations and ignore malformed input', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['redirect_parameters' => ['next', 'return_to'],
        'redirect_allowlist' => ['https://portal.example.org/members/']]) === true, 'Unable to save redirect fixture');
    $cases = [
        [['redirect_to' => '/wp-admin/', 'next' => '/other/'], home_url('/wp-admin/')],
        [['next' => '/members/?page=2&sort=name', 'return_to' => '/other/'], home_url('/members/?page=2&sort=name')],
        [['redirect_to' => 'https://evil.example/', 'next' => '/members/'], home_url('/members/')],
        [['redirect_to' => ['nested'], 'next' => '/members/'], home_url('/members/')],
        [['next' => '', 'return_to' => '/return/'], home_url('/return/')],
        [['next' => 'https://portal.example.org/members/profile'], 'https://portal.example.org/members/profile'],
        [['next' => home_url('/')], home_url('/')],
        [['next' => str_repeat('x', 8193), 'return_to' => '/return/'], home_url('/return/')],
        [['next' => '//evil.example/'], ''],
        [['next' => 'https://portal.example.org/membership/'], ''],
        [['next' => 'https://portal.example.org/members/../outside'], ''],
        [['next' => '/members/%0d%0aLocation:evil'], ''],
        [['next' => 123, 'return_to' => false], ''],
        [['unconfigured' => '/members/', 'Next' => '/members/'], ''],
    ];
    foreach ($cases as [$input, $expected]) {
        portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(wp_slash($input)) === $expected, 'Unexpected redirect for ' . wp_json_encode($input));
    }
});

portalweave_sso_test('Login redirect settings save only in their screen and replace the configured list', function () {
    $candidate = \PortalWeaveSSO\AdminSettings::prepare(['redirect_parameters' => "next\nreturn_to\nnext"], 'login');
    portalweave_sso_assert(!is_wp_error($candidate) && \PortalWeaveSSO\Config::save($candidate) === true, 'Scoped parameter save failed');
    portalweave_sso_assert(\PortalWeaveSSO\Config::get('redirect_parameters') === ['next', 'return_to'], 'List order/deduplication changed');
    portalweave_sso_error(\PortalWeaveSSO\AdminSettings::prepare(['redirect_parameters' => ['next']], 'appearance'), 'portalweave_sso_settings_scope');
    foreach ([false, [['nested']], [123], ['bad[name]'], ['bad.name'], ['1next'], [str_repeat('a', 65)], array_map(static fn($i) => 'param' . $i, range(1, 33))] as $invalid) {
        $before = get_option('portalweave_sso_settings');
        portalweave_sso_error(\PortalWeaveSSO\Config::save(['redirect_parameters' => $invalid]), 'portalweave_sso_redirect_parameters');
        portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'Invalid list mutated settings');
    }
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['redirect_parameters' => []]) === true, 'Unable to clear aliases');
    portalweave_sso_assert(\PortalWeaveSSO\Config::get('redirect_parameters') === [], 'Recursive defaults resurrected a removed name');
    portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(['next' => '/members/']) === '', 'Removed alias remained active');
    portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(['redirect_to' => '/wp-admin/']) === home_url('/wp-admin/'), 'Internal canonical redirect stopped working');
});

portalweave_sso_test('Login buttons prefer explicit and request destinations over the configured default without reading cookies', function () {
    $get = $_GET;
    $post = $_POST;
    $request = $_REQUEST;
    $cookie = $_COOKIE;
    $user = get_current_user_id();
    try {
        wp_set_current_user(0);
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app()],
            'redirect_parameters' => ['next'], 'login_redirect' => home_url('/default/')]) === true, 'Unable to save button fixture');
        $target = static function ($args = []) {
            $html = \PortalWeaveSSO\Display::buttons($args);
            portalweave_sso_assert(preg_match('/href="([^"]+)"/', $html, $match) === 1, 'Missing SSO button');
            parse_str(wp_parse_url(html_entity_decode($match[1], ENT_QUOTES), PHP_URL_QUERY), $params);
            return $params['redirect_to'] ?? '';
        };
        $_GET = ['redirect_to' => home_url('/wp-admin/')];
        $_POST = [];
        $_REQUEST = [];
        portalweave_sso_assert($target() === home_url('/wp-admin/'), 'Default swallowed WordPress redirect_to');
        portalweave_sso_assert($target(['redirect' => '/explicit/']) === home_url('/explicit/'), 'Shortcode redirect lost precedence');
        $_GET = ['next' => '/alias/'];
        portalweave_sso_assert($target() === home_url('/alias/'), 'Configured alias was ignored');
        $_POST = ['next' => '/post/'];
        portalweave_sso_assert($target() === home_url('/alias/'), 'POST overrode GET');
        $_GET = [];
        portalweave_sso_assert($target() === home_url('/post/'), 'Form redirect was ignored');
        $_POST = [];
        $_COOKIE['redirect_to'] = '/cookie/';
        $_REQUEST = ['redirect_to' => '/cookie/'];
        portalweave_sso_assert($target() === home_url('/default/'), 'Cookie supplied redirect destination');
        $_GET = ['redirect_to' => 'https://evil.example/'];
        portalweave_sso_assert($target() === home_url('/default/'), 'Rejected request bypassed the default');
    } finally {
        $_GET = $get;
        $_POST = $post;
        $_REQUEST = $request;
        $_COOKIE = $cookie;
        wp_set_current_user($user);
    }
});

if (is_multisite()) {
    portalweave_sso_test('Redirect parameter lists stay local to each blog and honor network enforcement', function () {
        global $portalweave_sso_blog_b;
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['redirect_parameters' => ['next']]) === true, 'Unable to save first site list');
        switch_to_blog($portalweave_sso_blog_b);
        try {
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['redirect_parameters' => ['return_to']]) === true, 'Unable to save second site list');
            portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(['next' => '/other/']) === '', 'First site alias leaked into second site');
            portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(['return_to' => '/local/']) === home_url('/local/'), 'Second site alias missing');
        } finally { restore_current_blog(); }
        portalweave_sso_assert(\PortalWeaveSSO\Config::get('redirect_parameters') === ['next'], 'Second site replaced first site list');
        $candidate = \PortalWeaveSSO\AdminSettings::prepare(['redirect_parameters' => ['continue']], 'login', '', false, true);
        portalweave_sso_assert(!is_wp_error($candidate) && \PortalWeaveSSO\Config::save($candidate, true) === true, 'Network login screen rejected parameter list');
        portalweave_sso_assert(\PortalWeaveSSO\Config::get('redirect_parameters') === ['next'], 'Unenforced network replaced local list');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['network_enforced' => true], true) === true, 'Unable to enforce network list');
        portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(['continue' => '/network/']) === home_url('/network/'), 'Network alias missing on first site');
        portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(['next' => '/local/']) === '', 'Local alias survived network enforcement');
        switch_to_blog($portalweave_sso_blog_b);
        try {
            portalweave_sso_assert(\PortalWeaveSSO\Config::get('redirect_parameters') === ['continue'], 'Network list did not replace second site list');
            portalweave_sso_assert(\PortalWeaveSSO\Redirect::requested(['continue' => '/network/']) === home_url('/network/'), 'Network alias missing on second site');
            portalweave_sso_assert(get_option('portalweave_sso_settings')['redirect_parameters'] === ['return_to'], 'Enforcement mutated stored site list');
        } finally { restore_current_blog(); }
    });
}
