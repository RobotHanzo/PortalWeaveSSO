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

portalweave_sso_test('Guided provider form creates one connection and returns to its advanced editor', function () {
    wp_set_current_user(get_user_by('login', 'portalweave_sso_test_admin')->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['existing' => portalweave_sso_app(['id' => 'existing'])], 'default_app' => 'existing']) === true, 'Existing provider fixture failed');
    $before = get_option('portalweave_sso_settings');
    $secret = "  Fixture-Only+Secret\\with<>&\"'bytes  ";
    $payload = ['provider' => 'discord', 'id' => 'guided-discord', 'name' => 'Community Discord', 'callback_registered' => '1', 'client_id' => 'discord-web-client', 'client_secret' => $secret];
    $context = ['portalweave_sso_screen' => 'providers', 'portalweave_sso_creating' => '1', 'portalweave_sso_provider' => ''];
    $destination = portalweave_sso_admin_form_submit(['provider_setup' => $payload], $context);
    $after = get_option('portalweave_sso_settings');
    $app = \PortalWeaveSSO\Config::app('guided-discord');
    portalweave_sso_assert(is_array($app) && $app['client_secret'] === $secret && $app['issuer'] === 'https://discord.com' && $app['verified_attribute'] === 'verified', 'Guided form lost its provider profile or opaque secret');
    portalweave_sso_assert($after['apps']['existing'] === $before['apps']['existing'] && $after['default_app'] === 'existing', 'Guided form changed another provider or default');
    parse_str((string) wp_parse_url($destination, PHP_URL_QUERY), $query);
    portalweave_sso_assert(($query['provider'] ?? '') === 'guided-discord' && ($query['portalweave_sso_notice'] ?? '') === 'saved', 'Guided form did not return to the created provider');
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
    $base = admin_url('options-general.php?page=portalweave-sso&tab=providers&new_provider=1');
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
