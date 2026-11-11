<?php
/** Reviewer regressions using real WordPress settings, screens and media. */
portalweave_sso_test('Reviewer: retired CSS is ignored on read and removed by ordinary and imported saves', function () {
    $legacy = \PortalWeaveSSO\Config::defaults();
    $legacy['button']['custom_css'] = 'body{background:url(https://untrusted.example/legacy-css)}';
    update_option('portalweave_sso_settings', $legacy, false);
    portalweave_sso_assert(!array_key_exists('custom_css', \PortalWeaveSSO\Config::get('button')), 'Legacy CSS remained in runtime configuration');
    foreach (['index.php', 'wp-login.php'] as $page) {
        $render = portalweave_sso_button_render($page);
        portalweave_sso_assert(!str_contains($render['css'], 'untrusted.example'), 'Legacy CSS reached an inline stylesheet');
    }
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['debug' => false]) === true, 'Ordinary save failed');
    portalweave_sso_assert(!array_key_exists('custom_css', get_option('portalweave_sso_settings')['button']), 'Ordinary save retained retired CSS');
    foreach (['body{display:none}', ['malformed'], '<script>alert(1)</script>'] as $code) {
        $input = $legacy;
        $input['button']['custom_css'] = $code;
        portalweave_sso_assert(\PortalWeaveSSO\Config::save($input) === true, 'Legacy import could not retain its supported settings');
        portalweave_sso_assert(!array_key_exists('custom_css', get_option('portalweave_sso_settings')['button']), 'Imported custom CSS persisted');
        portalweave_sso_assert(\PortalWeaveSSO\Config::get('button')['radius'] === $legacy['button']['radius'], 'Legacy import lost supported appearance');
    }
});

portalweave_sso_test('Reviewer: only prefixed shortcodes are registered', function () {
    portalweave_sso_assert(shortcode_exists('portalweave_sso_login'), 'Prefixed shortcode missing');
    portalweave_sso_assert(!shortcode_exists('sso_oauth_login'), 'Unprefixed shortcode remains registered');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_app()]]) === true, 'Shortcode fixture failed');
    wp_set_current_user(0);
    portalweave_sso_assert(str_contains(do_shortcode('[portalweave_sso_login app="fixture"]'), 'portalweave-sso-button'), 'Prefixed shortcode does not render login buttons');
});

portalweave_sso_test('Reviewer: media errors remain on media screens and require upload permission', function () {
    $screen = $GLOBALS['current_screen'] ?? null;
    $admin = get_user_by('login', 'portalweave_sso_test_admin')->ID;
    $key = 'portalweave_sso_media_error_' . $admin;
    $capture = static function (): string {
        ob_start();
        try { \PortalWeaveSSO\Access::media_notice(); } finally { $html = ob_get_clean(); }
        return $html;
    };
    try {
        wp_set_current_user($admin);
        set_transient($key, '<script>alert(1)</script>media-error-fixture', 300);
        foreach (['dashboard', 'plugins', 'edit-post', 'post'] as $id) {
            set_current_screen($id);
            portalweave_sso_assert($capture() === '' && get_transient($key) !== false, 'Unrelated screen displayed or consumed a media error: ' . $id);
        }
        set_current_screen('upload');
        wp_set_current_user(0);
        portalweave_sso_assert($capture() === '', 'User without upload permission saw a media error');
        wp_set_current_user($admin);
        $html = $capture();
        portalweave_sso_assert(str_contains($html, 'media-error-fixture') && !str_contains($html, '<script>'), 'Media error missing or not escaped');
        portalweave_sso_assert($capture() === '', 'Media error repeated after it was consumed');
        set_transient($key, 'attachment-error-fixture', 300);
        set_current_screen('attachment');
        portalweave_sso_assert(str_contains($capture(), 'attachment-error-fixture'), 'Attachment editor lost media failures');
    } finally {
        delete_transient($key);
        $GLOBALS['current_screen'] = $screen;
    }
});

portalweave_sso_test('Reviewer: default vault identity is persistent, salt-independent and fails closed on corruption', function () {
    $key = 'portalweave_sso_media_vault_id';
    $prior = get_option($key, false);
    $method = new ReflectionMethod(\PortalWeaveSSO\Access::class, 'default_vault_directory');
    $salt = static fn() => 'changed-authentication-salt-fixture';
    try {
        delete_option($key);
        $first = $method->invoke(null);
        portalweave_sso_assert(is_string($first) && preg_match('/\/portalweave-sso-private-[a-f0-9]{32}$/D', $first), 'Default vault did not use an independent random identifier');
        add_filter('salt', $salt);
        portalweave_sso_assert($method->invoke(null) === $first, 'Authentication salt rotation changed the vault directory');
        foreach (['../escape', ['nested'], '', str_repeat('f', 31)] as $invalid) {
            update_option($key, $invalid, false);
            portalweave_sso_error($method->invoke(null), 'portalweave_sso_media_vault');
            portalweave_sso_assert(get_option($key) === $invalid, 'Invalid identity was silently replaced');
        }
    } finally {
        remove_filter('salt', $salt);
        if ($prior === false) { delete_option($key); } else { update_option($key, $prior, false); }
    }
});

portalweave_sso_test('Reviewer: recorded legacy media paths survive a changed default vault identity', function () {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $key = 'portalweave_sso_media_vault_id';
        $bytes = 'Existing vault recovery fixture';
        $upload = wp_upload_bits($prefix . '-legacy-vault.txt', null, $bytes);
        portalweave_sso_assert(empty($upload['error']), 'Legacy media upload failed');
        $id = wp_insert_attachment(['post_title' => $prefix, 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
        $posts[] = $id;
        portalweave_sso_assert(\PortalWeaveSSO\Access::protect_media($id) === true, 'Legacy protection fixture failed');
        $prior = get_option($key, false);
        $private = get_attached_file($id);
        try {
            update_option($key, bin2hex(random_bytes(16)), false);
            portalweave_sso_assert(get_attached_file($id) === $private && file_get_contents($private) === $bytes, 'Existing private path was changed');
            portalweave_sso_assert(\PortalWeaveSSO\Access::protect_media($id) === true && get_attached_file($id) === $private, 'Retry moved existing private media');
            portalweave_sso_assert(\PortalWeaveSSO\Access::unprotect_media($id) === true && file_get_contents($upload['file']) === $bytes, 'Legacy manifest could not restore original bytes');
        } finally {
            if ($prior === false) { delete_option($key); } else { update_option($key, $prior, false); }
        }
    });
});

portalweave_sso_test('Reviewer: the default vault still rejects public document roots', function () {
    $prior = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $vault = new ReflectionMethod(\PortalWeaveSSO\Access::class, 'vault');
    try {
        $_SERVER['DOCUMENT_ROOT'] = dirname(rtrim(ABSPATH, '/\\'));
        portalweave_sso_error($vault->invoke(null), 'portalweave_sso_media_public');
    } finally {
        if ($prior === null) { unset($_SERVER['DOCUMENT_ROOT']); }
        else { $_SERVER['DOCUMENT_ROOT'] = $prior; }
    }
});
