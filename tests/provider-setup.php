<?php
/** Provider recipes use independent expected protocol values and the real configuration boundary. */
function portalweave_sso_setup_input(string $provider, array $overrides = []): array {
    $specific = [
        'custom' => ['protocol' => 'oidc', 'authorize_url' => 'https://idp.example.org/authorize', 'token_url' => 'https://idp.example.org/token', 'issuer' => 'https://idp.example.org/', 'jwks_url' => 'https://idp.example.org/jwks', 'scope' => 'openid profile email'],
        'entra' => ['tenant_id' => '01234567-89ab-cdef-0123-456789abcdef'],
        'office365' => ['tenant_id' => '01234567-89ab-cdef-0123-456789abcdef'],
        'b2c' => ['client_id' => '76543210-abcd-1234-abcd-0123456789ab', 'authority' => 'https://school.b2clogin.com/school.onmicrosoft.com/B2C_1_signin', 'issuer' => 'https://school.b2clogin.com/01234567-89ab-cdef-0123-456789abcdef/v2.0/'],
        'cognito' => ['issuer' => 'https://cognito-idp.us-east-1.amazonaws.com/us-east-1_ABC123', 'domain' => 'https://school.auth.us-east-1.amazoncognito.com'],
        'keycloak' => ['host' => 'https://idp.example.org/auth', 'realm' => 'school'],
        'okta' => ['host' => 'https://school.okta.com'],
        'salesforce' => ['environment' => 'production'],
        'google' => [],
        'auth0' => ['host' => 'https://school.eu.auth0.com'],
        'onelogin' => ['host' => 'https://school.onelogin.com'],
        'discord' => [],
        'classlink' => ['authorize_url' => 'https://partner.example.org/authorize', 'token_url' => 'https://partner.example.org/token', 'userinfo_url' => 'https://partner.example.org/profile', 'scope' => 'profile email', 'subject_attribute' => 'stable_id', 'email_attribute' => 'mail.address', 'verified_attribute' => 'mail.verified'],
        'authentik' => ['host' => 'https://login.example.org', 'application' => 'school-portal'],
    ];
    $input = array_replace(['id' => 'wizard-' . $provider, 'provider' => $provider, 'name' => 'School sign-in', 'callback_registered' => '1', 'client_id' => 'application-client', 'client_secret' => 'test-only-application-secret'], $specific[$provider]);
    if ($provider === 'onelogin') { unset($input['client_secret']); }
    return array_replace($input, $overrides);
}

portalweave_sso_test('Provider setup catalog exposes complete local registration instructions and conditional field contracts', function () {
    $catalog = \PortalWeaveSSO\ProviderSetup::catalog();
    portalweave_sso_assert(array_keys($catalog) === ['cognito', 'auth0', 'authentik', 'b2c', 'classlink', 'custom', 'discord', 'google', 'keycloak', 'office365', 'entra', 'okta', 'onelogin', 'salesforce'], 'Provider choices must retain their IDs and follow alphabetical display-name order');
    foreach ($catalog as $key => $recipe) {
        portalweave_sso_assert($recipe['label'] !== '' && $recipe['summary'] !== '' && str_starts_with($recipe['docs'], 'https://'), 'Provider instructions or official documentation are missing: ' . $key);
        foreach (['registration', 'credentials'] as $step) {
            portalweave_sso_assert(!empty($recipe['steps'][$step]['title']) && !empty($recipe['steps'][$step]['instructions']), 'Provider has no actionable registration or credential instructions: ' . $key);
        }
        foreach ($recipe['fields'] as $field) {
            portalweave_sso_assert(isset($recipe['steps'][$field['step']]) && is_bool($field['required']) && $field['label'] !== '' && $field['help'] !== '', 'Wizard field contract is incomplete: ' . $key);
        }
    }
    foreach (['google', 'discord'] as $key) {
        portalweave_sso_assert(!isset($catalog[$key]['steps']['location']) && array_keys($catalog[$key]['fields']) === ['client_id', 'client_secret'], 'A fixed public provider unexpectedly asks for tenant details');
    }
    portalweave_sso_assert(!isset($catalog['onelogin']['fields']['client_secret']), 'Public OneLogin PKCE setup asks for a client secret');
    foreach (['custom', 'classlink'] as $key) {
        $secret = $catalog[$key]['fields']['client_secret'];
        portalweave_sso_assert($secret['required'] && $secret['when'] === ['credential_method' => ['header', 'body']], 'Manual confidential clients do not expose conditional secret validation');
    }
});

portalweave_sso_test('Provider setup derives every researched endpoint family without changing secure account defaults', function () {
    $expected = [
        'custom' => ['issuer' => 'https://idp.example.org/', 'authorize_url' => 'https://idp.example.org/authorize', 'jwks_url' => 'https://idp.example.org/jwks'],
        'entra' => ['issuer' => 'https://login.microsoftonline.com/01234567-89ab-cdef-0123-456789abcdef/v2.0', 'token_url' => 'https://login.microsoftonline.com/01234567-89ab-cdef-0123-456789abcdef/oauth2/v2.0/token', 'credential_method' => 'body'],
        'office365' => ['issuer' => 'https://login.microsoftonline.com/01234567-89ab-cdef-0123-456789abcdef/v2.0', 'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo'],
        'b2c' => ['issuer' => 'https://school.b2clogin.com/01234567-89ab-cdef-0123-456789abcdef/v2.0/', 'authorize_url' => 'https://school.b2clogin.com/school.onmicrosoft.com/B2C_1_signin/oauth2/v2.0/authorize', 'scope' => 'openid 76543210-abcd-1234-abcd-0123456789ab', 'userinfo_url' => ''],
        'cognito' => ['issuer' => 'https://cognito-idp.us-east-1.amazonaws.com/us-east-1_ABC123', 'userinfo_url' => 'https://school.auth.us-east-1.amazoncognito.com/oauth2/userInfo', 'jwks_url' => 'https://cognito-idp.us-east-1.amazonaws.com/us-east-1_ABC123/.well-known/jwks.json', 'logout_url' => ''],
        'keycloak' => ['issuer' => 'https://idp.example.org/auth/realms/school', 'jwks_url' => 'https://idp.example.org/auth/realms/school/protocol/openid-connect/certs'],
        'okta' => ['issuer' => 'https://school.okta.com', 'authorize_url' => 'https://school.okta.com/oauth2/v1/authorize'],
        'salesforce' => ['issuer' => 'https://login.salesforce.com', 'token_url' => 'https://login.salesforce.com/services/oauth2/token', 'logout_url' => ''],
        'google' => ['issuer' => 'https://accounts.google.com', 'token_url' => 'https://oauth2.googleapis.com/token', 'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs', 'logout_url' => ''],
        'auth0' => ['issuer' => 'https://school.eu.auth0.com/', 'logout_url' => 'https://school.eu.auth0.com/oidc/logout'],
        'onelogin' => ['issuer' => 'https://school.onelogin.com/oidc/2', 'token_url' => 'https://school.onelogin.com/oidc/2/token', 'credential_method' => 'none', 'client_secret' => ''],
        'discord' => ['protocol' => 'oauth2', 'issuer' => 'https://discord.com', 'scope' => 'identify email', 'subject_attribute' => 'id', 'verified_attribute' => 'verified', 'userinfo_url' => 'https://discord.com/api/users/@me'],
        'classlink' => ['protocol' => 'oauth2', 'authorize_url' => 'https://partner.example.org/authorize', 'userinfo_url' => 'https://partner.example.org/profile', 'subject_attribute' => 'stable_id', 'verified_attribute' => 'mail.verified'],
        'authentik' => ['issuer' => 'https://login.example.org/application/o/school-portal/', 'jwks_url' => 'https://login.example.org/application/o/school-portal/jwks/', 'logout_url' => 'https://login.example.org/application/o/school-portal/end-session/'],
    ];
    foreach ($expected as $provider => $fields) {
        $app = \PortalWeaveSSO\ProviderSetup::prepare(portalweave_sso_setup_input($provider));
        portalweave_sso_assert(!is_wp_error($app), 'Valid provider recipe rejected: ' . $provider);
        foreach ($fields as $key => $value) {
            portalweave_sso_assert($app[$key] === $value, 'Provider contract mismatch: ' . $provider . '/' . $key);
        }
        portalweave_sso_assert($app['enabled'] && $app['grant'] === 'authorization_code' && $app['pkce'] && !$app['legacy_grants'], 'Setup weakened authorization-code defaults');
        portalweave_sso_assert($app['algorithms'] === ['RS256'] && $app['require_verified_email'] && !$app['link_existing'] && !$app['allow_admin'], 'Setup weakened token or account verification');
        portalweave_sso_assert($app['default_role'] === 'subscriber' && !$app['guest'] && !$app['shared_sso'] && !$app['jwt_sso'], 'Setup enabled privileged or optional behavior');
    }
});

portalweave_sso_test('Provider setup preserves issuer distinctions, custom server variants and public-client PKCE', function () {
    $cognito = \PortalWeaveSSO\ProviderSetup::prepare(portalweave_sso_setup_input('cognito', ['issuer' => 'https://issuer-cognito-idp.us-east-1.amazonaws.com/us-west-2_ABC123', 'domain' => 'https://login.school.example/']));
    portalweave_sso_assert(!is_wp_error($cognito) && $cognito['jwks_url'] === 'https://issuer-cognito-idp.us-east-1.amazonaws.com/us-west-2_ABC123/.well-known/jwks.json' && $cognito['authorize_url'] === 'https://login.school.example/oauth2/authorize', 'Updated Cognito issuer or custom domain was lost');
    $okta = \PortalWeaveSSO\ProviderSetup::prepare(portalweave_sso_setup_input('okta', ['server_type' => 'custom', 'server_id' => 'default']));
    portalweave_sso_assert(!is_wp_error($okta) && $okta['issuer'] === 'https://school.okta.com/oauth2/default' && $okta['token_url'] === 'https://school.okta.com/oauth2/default/v1/token', 'Explicit Okta custom server was not honored');
    $sandbox = \PortalWeaveSSO\ProviderSetup::prepare(portalweave_sso_setup_input('salesforce', ['environment' => 'sandbox']));
    portalweave_sso_assert(!is_wp_error($sandbox) && $sandbox['issuer'] === 'https://test.salesforce.com', 'Salesforce sandbox silently uses production');
    $salesforce = \PortalWeaveSSO\ProviderSetup::prepare(portalweave_sso_setup_input('salesforce', ['environment' => 'custom', 'host' => 'https://school.my.salesforce.com', 'issuer' => 'https://login.salesforce.com']));
    portalweave_sso_assert(!is_wp_error($salesforce) && $salesforce['issuer'] === 'https://login.salesforce.com' && $salesforce['token_url'] === 'https://school.my.salesforce.com/services/oauth2/token', 'Custom Salesforce authority was confused with its exact issuer');
    $b2c_issuer = 'https://school.b2clogin.com/tfp/01234567-89ab-cdef-0123-456789abcdef/B2C_1_signin/v2.0/';
    $b2c = \PortalWeaveSSO\ProviderSetup::prepare(portalweave_sso_setup_input('b2c', ['issuer' => $b2c_issuer]));
    portalweave_sso_assert(!is_wp_error($b2c) && $b2c['issuer'] === $b2c_issuer && $b2c['mapping']['email'] === 'emails.0', 'B2C metadata issuer or array email mapping was guessed');
    $public_input = portalweave_sso_setup_input('custom', ['protocol' => 'oauth2', 'scope' => 'profile email', 'credential_method' => 'none', 'client_secret' => '', 'userinfo_url' => 'https://idp.example.org/profile', 'subject_attribute' => 'immutable_id', 'issuer' => '']);
    $public = \PortalWeaveSSO\ProviderSetup::prepare($public_input);
    portalweave_sso_assert(!is_wp_error($public) && $public['pkce'] && $public['credential_method'] === 'none' && $public['client_secret'] === '' && $public['issuer'] === '' && $public['jwks_url'] === '', 'Public OAuth2 setup retained inactive OIDC settings or disabled PKCE');
    $issuer = \PortalWeaveSSO\ProviderSetup::prepare(array_replace($public_input, ['issuer' => 'https://idp.example.org/authorization-server/']));
    portalweave_sso_assert(!is_wp_error($issuer) && $issuer['issuer'] === 'https://idp.example.org/authorization-server/', 'Custom OAuth2 setup discarded the authorization response issuer');
    foreach (['custom', 'classlink'] as $provider) {
        foreach (['header', 'body'] as $method) {
            $confidential = portalweave_sso_setup_input($provider, ['credential_method' => $method]);
            portalweave_sso_assert(!is_wp_error(\PortalWeaveSSO\ProviderSetup::prepare($confidential)), 'Manual confidential client with a secret was rejected');
            portalweave_sso_assert(is_wp_error(\PortalWeaveSSO\ProviderSetup::prepare(array_replace($confidential, ['client_secret' => '']))), 'Manual confidential client accepted a missing secret');
        }
        $without_secret = portalweave_sso_setup_input($provider, ['credential_method' => 'none', 'client_secret' => '']);
        $public_app = \PortalWeaveSSO\ProviderSetup::prepare($without_secret);
        portalweave_sso_assert(!is_wp_error($public_app) && $public_app['credential_method'] === 'none' && $public_app['pkce'] && $public_app['client_secret'] === '', 'Manual public client unexpectedly requires a secret');
    }
});

portalweave_sso_test('Provider setup rejects malformed, ambiguous and injected inputs without disclosing credentials', function () {
    $secret = 'private-setup-secret-never-in-errors';
    $cases = [
        ['google', ['provider' => 'unknown']], ['google', ['callback_registered' => '0']], ['google', ['callback_registered' => ['1']]],
        ['google', ['id' => '../other']], ['google', ['id' => '0']], ['google', ['id' => str_repeat('a', 101)]],
        ['google', ['client_id' => ['nested']]], ['google', ['client_secret' => str_repeat('s', 65537)]], ['google', ['client_secret' => '********']], ['google', ['pkce' => '0']], ['google', ['allow_admin' => '1']], ['google', ['name' => str_repeat('n', 201)]],
        ['entra', ['tenant_id' => 'common']], ['entra', ['tenant_id' => 'YOUR_TENANT_ID']],
        ['authentik', ['application' => '../other']], ['authentik', ['application' => 'slug/other']], ['authentik', ['application' => '%2fother']],
        ['keycloak', ['host' => 'https://login.example.org/%2e%2e/other']], ['keycloak', ['host' => 'https://user:password@login.example.org']], ['keycloak', ['host' => 'http://login.example.org']],
        ['auth0', ['host' => 'https://login.example.org/application']], ['auth0', ['host' => 'https://login.example.org?token=private']], ['auth0', ['host' => 'https://login.example.org/#fragment']], ['auth0', ['host' => 'https://YOUR_HOST']],
        ['b2c', ['authority' => 'https://school.b2clogin.com/other.onmicrosoft.com/B2C_1_signin']], ['b2c', ['issuer' => 'https://unrelated.example.org/issuer']], ['b2c', ['client_id' => 'not-an-application-uuid']],
        ['cognito', ['issuer' => 'https://school.auth.us-east-1.amazoncognito.com']], ['cognito', ['domain' => 'https://login.school.example/path']],
        ['okta', ['server_type' => 'custom', 'server_id' => '']], ['okta', ['server_type' => 'custom', 'server_id' => 'default/keys']], ['salesforce', ['environment' => 'unrecognized']],
        ['classlink', ['userinfo_url' => '']], ['classlink', ['subject_attribute' => '']], ['classlink', ['verified_attribute' => '']],
        ['custom', ['scope' => 'profile email']], ['custom', ['issuer' => '']], ['custom', ['credential_method' => 'none', 'client_secret' => $secret]],
    ];
    foreach ($cases as [$provider, $overrides]) {
        $input = portalweave_sso_setup_input($provider, ['client_secret' => $secret]);
        $error = \PortalWeaveSSO\ProviderSetup::prepare(array_replace($input, $overrides));
        portalweave_sso_assert(is_wp_error($error) && $error->get_error_code() === 'portalweave_sso_provider_setup', 'Unsafe provider setup input was accepted: ' . $provider);
        portalweave_sso_assert(!str_contains(wp_json_encode($error), $secret), 'Setup error disclosed a submitted secret');
    }
    $one_login = portalweave_sso_setup_input('onelogin', ['client_secret' => $secret]);
    portalweave_sso_assert(is_wp_error(\PortalWeaveSSO\ProviderSetup::prepare($one_login)), 'Public OneLogin setup accepted an unknown secret field');
});

portalweave_sso_test('Provider wizard creation preserves prior providers and encrypts newly submitted secret bytes once', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['existing' => portalweave_sso_app(['id' => 'existing'])], 'default_app' => 'existing', 'popup' => true]) === true, 'Existing provider fixture failed');
    $before = get_option('portalweave_sso_settings');
    $secret = "  literal+<&>\\\\\"' secret bytes  ";
    $input = portalweave_sso_setup_input('google', ['client_secret' => $secret]);
    $direct = \PortalWeaveSSO\ProviderSetup::prepare($input);
    portalweave_sso_assert(!is_wp_error($direct) && $direct['client_secret'] === $secret, 'Setup altered opaque secret bytes');
    $prepared = \PortalWeaveSSO\AdminSettings::prepare(['provider_setup' => $input], 'providers', '', true);
    portalweave_sso_assert(!is_wp_error($prepared) && $prepared['apps']['existing'] === [], 'Wizard preparation forwarded hidden provider contents');
    portalweave_sso_assert(!str_contains(wp_json_encode($prepared), 'enc:v1:'), 'Wizard resubmitted stored ciphertext');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save($prepared) === true, 'Wizard provider could not be saved');
    $after = get_option('portalweave_sso_settings');
    portalweave_sso_assert($after['apps']['existing'] === $before['apps']['existing'] && $after['default_app'] === 'existing' && $after['popup'], 'Wizard reset an unrelated provider or login policy');
    portalweave_sso_assert(\PortalWeaveSSO\Config::app('wizard-google')['client_secret'] === $secret, 'Stored wizard secret cannot decrypt to its original bytes');
    foreach ([
        [['provider_setup' => $input], 'appearance', '', true],
        [['provider_setup' => $input], 'providers', 'existing', false],
        [['provider_setup' => $input, 'new_app' => portalweave_sso_app()], 'providers', '', true],
        [['provider_setup' => $input, 'apps' => ['existing' => []]], 'providers', '', true],
    ] as [$payload, $screen, $provider, $creating]) {
        portalweave_sso_assert(is_wp_error(\PortalWeaveSSO\AdminSettings::prepare($payload, $screen, $provider, $creating)), 'Wizard escaped the creation-only settings scope');
        portalweave_sso_assert(get_option('portalweave_sso_settings') === $after, 'Rejected wizard scope mutated configuration');
    }
    $duplicate = \PortalWeaveSSO\AdminSettings::prepare(['provider_setup' => $input], 'providers', '', true);
    portalweave_sso_error($duplicate, 'portalweave_sso_app_exists');
});

if (is_multisite()) {
    portalweave_sso_test('Provider wizard network creation preserves local settings and network credentials', function () {
        $network = get_site_option('portalweave_sso_settings', []);
        $local = get_option('portalweave_sso_settings', []);
        try {
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['existing' => portalweave_sso_app(['id' => 'existing'])], 'default_app' => 'existing'], true) === true, 'Network wizard fixture failed');
            $before = get_site_option('portalweave_sso_settings');
            $input = portalweave_sso_setup_input('authentik');
            $prepared = \PortalWeaveSSO\AdminSettings::prepare(['provider_setup' => $input], 'providers', '', true, true);
            portalweave_sso_assert(!is_wp_error($prepared) && $prepared['apps']['existing'] === [] && \PortalWeaveSSO\Config::save($prepared, true) === true, 'Network wizard creation failed');
            $after = get_site_option('portalweave_sso_settings');
            portalweave_sso_assert($after['apps']['existing'] === $before['apps']['existing'] && $after['default_app'] === 'existing', 'Wizard changed another network provider or credential');
            portalweave_sso_assert(isset($after['apps']['wizard-authentik']) && get_option('portalweave_sso_settings', []) === $local, 'Network wizard saved into the local site');
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['network_enforced' => true], true) === true, 'Unable to enforce network fixture');
            $site_prepared = \PortalWeaveSSO\AdminSettings::prepare(['provider_setup' => portalweave_sso_setup_input('google')], 'providers', '', true, false);
            $site_result = is_wp_error($site_prepared) ? $site_prepared : \PortalWeaveSSO\Config::save($site_prepared);
            portalweave_sso_assert(is_wp_error($site_result), 'Network enforcement accepted a local wizard save');
            portalweave_sso_assert(get_option('portalweave_sso_settings', []) === $local, 'Network enforcement allowed a local wizard mutation');
        } finally { update_site_option('portalweave_sso_settings', $network); }
    });
}
