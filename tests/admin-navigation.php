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
        $_POST = ['settings' => $settings, 'portalweave_sso_complete' => '1', '_wpnonce' => wp_create_nonce('portalweave_sso_save')] + $context;
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

portalweave_sso_test('Admin form saves one screen without changing hidden providers or login policy', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    $app = portalweave_sso_app();
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture', 'popup' => true]) === true);
    $before = get_option('portalweave_sso_settings');
    $destination = portalweave_sso_admin_form_submit(['button' => ['shadow' => '0', 'text' => 'Continue with {app}']], ['portalweave_sso_screen' => 'appearance']);
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
