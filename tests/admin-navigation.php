<?php
/** Exercise the real administrator save boundary without following its terminating redirect. */
function wpsso_admin_form_submit(array $settings, array $context): string {
    $old_post = $_POST;
    $old_request = $_REQUEST;
    $destination = '';
    $redirect = static function ($url) use (&$destination) {
        $destination = $url;
        throw new RuntimeException('wpsso_expected_form_redirect');
    };
    add_filter('wp_redirect', $redirect);
    try {
        $_POST = ['settings' => $settings, 'wpsso_complete' => '1', '_wpnonce' => wp_create_nonce('wpsso_save')] + $context;
        $_REQUEST = $_POST;
        try {
            \WPSSO\Admin::save();
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'wpsso_expected_form_redirect') {
                throw $error;
            }
        }
    } finally {
        remove_filter('wp_redirect', $redirect);
        $_POST = $old_post;
        $_REQUEST = $old_request;
    }
    wpsso_assert($destination !== '', 'Settings action did not redirect');
    return $destination;
}

wpsso_test('Admin form saves one screen without changing hidden providers or login policy', function () {
    wp_set_current_user(get_user_by('login', 'wpsso_test_admin')->ID);
    $app = wpsso_app();
    wpsso_assert(\WPSSO\Config::save(['apps' => ['fixture' => $app], 'default_app' => 'fixture', 'popup' => true]) === true);
    $before = get_option('wpsso_settings');
    $destination = wpsso_admin_form_submit(['button' => ['shadow' => '0', 'text' => 'Continue with {app}']], ['wpsso_screen' => 'appearance']);
    $after = get_option('wpsso_settings');
    wpsso_assert($after['apps'] === $before['apps'], 'Appearance save changed provider credentials/configuration');
    wpsso_assert($after['default_app'] === 'fixture' && $after['popup'] === true, 'Appearance save changed login policy');
    wpsso_assert($after['button']['shadow'] === false && $after['button']['text'] === 'Continue with {app}', 'Submitted appearance fields were not saved');
    wpsso_assert($after['button']['theme'] === $before['button']['theme'], 'Unsubmitted appearance fields were reset');
    parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
    wpsso_assert(($query['tab'] ?? '') === 'appearance' && ($query['wpsso_notice'] ?? '') === 'saved', 'Save did not return to the current screen');
});

wpsso_test('Admin form rejects cross-screen fields and structured navigation before mutation', function () {
    wp_set_current_user(get_user_by('login', 'wpsso_test_admin')->ID);
    $before = get_option('wpsso_settings');
    foreach ([['appearance', ['force_auth' => '1']], [['malformed'], ['button' => ['shadow' => '0']]], ['unknown', ['popup' => '1']]] as [$screen, $settings]) {
        $destination = wpsso_admin_form_submit($settings, ['wpsso_screen' => $screen]);
        wpsso_assert(get_option('wpsso_settings') === $before, 'A rejected settings scope changed the configuration');
        parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
        wpsso_assert(($query['wpsso_notice'] ?? '') === 'error', 'A rejected scope did not report an error');
    }
    delete_transient('wpsso_admin_error_' . get_current_user_id());
});
