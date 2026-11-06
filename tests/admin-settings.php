<?php
/** Screen saves use the real configuration API, including encrypted provider storage. */

portalweave_sso_test('Admin settings: appearance saves preserve providers, secrets and unrelated policy', function () {
    $alpha = portalweave_sso_app(['id' => 'alpha', 'name' => 'Alpha']);
    $beta = portalweave_sso_app(['id' => 'beta', 'name' => 'Beta', 'client_secret' => 'another-provider-secret-at-least-thirty-two-bytes']);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['alpha' => $alpha, 'beta' => $beta], 'default_app' => 'alpha', 'force_auth' => true,
        'button' => ['text' => 'Company sign-in', 'radius' => 19, 'shadow' => true], 'modules' => ['avatar' => true],
        'support' => ['email' => 'support@example.org']]) === true, 'Unable to save appearance fixture');
    $before = get_option('portalweave_sso_settings');
    $candidate = \PortalWeaveSSO\AdminSettings::prepare(['button' => ['theme' => 'white', 'shadow' => '0']], 'appearance');
    portalweave_sso_assert(!is_wp_error($candidate) && !isset($candidate['apps']), 'Appearance candidate resubmitted hidden providers');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save($candidate) === true, 'Appearance screen save failed');
    $after = get_option('portalweave_sso_settings');
    foreach (['apps', 'default_app', 'force_auth', 'modules', 'support'] as $key) {
        portalweave_sso_assert($after[$key] === $before[$key], 'Appearance save changed unrelated setting: ' . $key);
    }
    portalweave_sso_assert($after['button']['theme'] === 'white' && !$after['button']['shadow'], 'Appearance changes were not saved');
    portalweave_sso_assert($after['button']['text'] === 'Company sign-in' && $after['button']['radius'] === 19, 'Partial appearance save reset hidden fields');
});

portalweave_sso_test('Admin settings: unchecked controls and nested partial saves preserve other fields', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['popup' => true, 'audit_ip' => true, 'debug' => true, 'audit_retention_days' => 120,
        'modules' => ['avatar' => true, 'moderation' => true], 'support' => ['email' => 'help@example.org', 'forum_url' => 'https://example.org/forum']]) === true, 'Unable to save partial-update fixture');
    foreach ([
        [['audit_enabled' => '0', 'audit_ip' => '0', 'debug' => '0'], 'reports'],
        [['modules' => ['avatar' => '0']], 'modules'],
        [['support' => ['email' => 'new-help@example.org']], 'tools'],
    ] as [$input, $screen]) {
        $candidate = \PortalWeaveSSO\AdminSettings::prepare($input, $screen);
        portalweave_sso_assert(!is_wp_error($candidate) && \PortalWeaveSSO\Config::save($candidate) === true, 'Partial screen save failed: ' . $screen);
    }
    $settings = \PortalWeaveSSO\Config::all();
    portalweave_sso_assert(!$settings['audit_enabled'] && !$settings['audit_ip'] && !$settings['debug'], 'Unchecked report controls were retained');
    portalweave_sso_assert($settings['popup'] && $settings['audit_retention_days'] === 120, 'Report save reset unrelated values');
    portalweave_sso_assert(!$settings['modules']['avatar'] && $settings['modules']['moderation'], 'Module save reset an unsubmitted module');
    portalweave_sso_assert($settings['support']['email'] === 'new-help@example.org' && $settings['support']['forum_url'] === 'https://example.org/forum', 'Support save lost an unsubmitted destination');
});

portalweave_sso_test('Admin settings: unknown screens, cross-screen fields and provider retargeting reject without mutations', function () {
    $app = portalweave_sso_app(['id' => 'alpha']);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['alpha' => $app]]) === true, 'Unable to save scope fixture');
    $before = get_option('portalweave_sso_settings');
    foreach ([
        [['button' => ['theme' => 'white']], 'overview'],
        [['debug' => '1'], 'unknown'],
        [['force_auth' => '1'], 'appearance'],
        [['allowed_domains' => ['example.org']], 'login'],
        [['apps' => []], 'reports'],
        [['network_enforced' => '1'], 'access'],
        [['button' => ['client_secret' => 'wrong-scope']], 'appearance'],
    ] as [$input, $screen]) {
        portalweave_sso_error(\PortalWeaveSSO\AdminSettings::prepare($input, $screen), 'portalweave_sso_settings_scope');
    }
    portalweave_sso_error(\PortalWeaveSSO\AdminSettings::prepare(['apps' => ['other' => ['id' => 'other']]], 'providers', 'alpha'), 'portalweave_sso_provider_target');
    portalweave_sso_error(\PortalWeaveSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'other']]], 'providers', 'alpha'), 'portalweave_sso_provider_target');
    portalweave_sso_error(\PortalWeaveSSO\AdminSettings::prepare(['audit_ip' => '0'], 'reports', 'alpha'), 'portalweave_sso_provider_target');
    portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'Rejected screen preparation mutated saved settings');
});

portalweave_sso_test('Admin settings: editing one provider retains every hidden provider and stored credential', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [
        'alpha' => portalweave_sso_app(['id' => 'alpha', 'name' => 'Alpha', 'keep_email' => true, 'client_secret' => 'alpha-provider-secret-at-least-thirty-two-bytes']),
        'beta' => portalweave_sso_app(['id' => 'beta', 'name' => 'Beta', 'client_secret' => 'beta-provider-secret-at-least-thirty-two-bytes']),
    ], 'default_app' => 'beta']) === true, 'Unable to save two-provider fixture');
    $before = get_option('portalweave_sso_settings');
    $candidate = \PortalWeaveSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'alpha', 'name' => 'Edited Alpha', 'keep_email' => '0', 'client_secret' => '']]], 'providers', 'alpha');
    portalweave_sso_assert(!is_wp_error($candidate) && $candidate['apps']['beta'] === [], 'Hidden provider was forwarded instead of preserved');
    portalweave_sso_assert(!str_contains(wp_json_encode($candidate), 'enc:v1:'), 'Candidate forwarded stored ciphertext');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save($candidate) === true, 'Single-provider save failed');
    $after = get_option('portalweave_sso_settings');
    portalweave_sso_assert($after['apps']['beta'] === $before['apps']['beta'], 'Editing Alpha changed Beta');
    portalweave_sso_assert($after['apps']['alpha']['client_secret'] === $before['apps']['alpha']['client_secret'], 'Blank provider secret was re-encrypted or cleared');
    portalweave_sso_assert(\PortalWeaveSSO\Config::app('alpha')['name'] === 'Edited Alpha' && !\PortalWeaveSSO\Config::app('alpha')['keep_email'], 'Selected provider changes were lost');
    portalweave_sso_assert($after['default_app'] === 'beta', 'Provider edit reset the default provider');
});

portalweave_sso_test('Admin settings: new is a valid provider ID and creation cannot replace existing providers', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['alpha' => portalweave_sso_app(['id' => 'alpha'])], 'default_app' => 'alpha']) === true, 'Unable to save creation fixture');
    $candidate = \PortalWeaveSSO\AdminSettings::prepare(['new_app' => portalweave_sso_app(['id' => 'new', 'name' => 'New provider'])], 'providers', '', true);
    portalweave_sso_assert(!is_wp_error($candidate) && $candidate['apps']['alpha'] === [], 'Adding a provider did not preserve the existing app');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save($candidate) === true, 'Provider with ID new could not be created');
    portalweave_sso_assert(array_keys(\PortalWeaveSSO\Config::apps()) === ['alpha', 'new'], 'Provider creation replaced the collection');
    portalweave_sso_error(\PortalWeaveSSO\AdminSettings::prepare(['new_app' => portalweave_sso_app(['id' => 'alpha'])], 'providers', '', true), 'portalweave_sso_app_exists');
    $numeric = \PortalWeaveSSO\AdminSettings::prepare(['new_app' => portalweave_sso_app(['id' => '123'])], 'providers', '', true);
    portalweave_sso_assert(!is_wp_error($numeric) && \PortalWeaveSSO\Config::save($numeric) === true, 'A numeric provider ID was rejected');
    $numeric_edit = \PortalWeaveSSO\AdminSettings::prepare(['apps' => [123 => ['id' => '123', 'name' => 'Numeric provider']]], 'providers', '123');
    portalweave_sso_assert(!is_wp_error($numeric_edit) && \PortalWeaveSSO\Config::save($numeric_edit) === true, 'Numeric PHP array keys broke provider editing');
    portalweave_sso_assert(\PortalWeaveSSO\Config::get('default_app') === 'alpha', 'Creation changed the existing default');
});

portalweave_sso_test('Admin settings: explicit provider deletion removes only the selected app and safely handles its default', function () {
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [
        'alpha' => portalweave_sso_app(['id' => 'alpha']), 'beta' => portalweave_sso_app(['id' => 'beta']),
    ], 'default_app' => 'alpha']) === true, 'Unable to save deletion fixture');
    $candidate = \PortalWeaveSSO\AdminSettings::prepare(['apps' => ['beta' => ['id' => 'beta', '_delete' => '1']]], 'providers', 'beta');
    portalweave_sso_assert(!is_wp_error($candidate) && !isset($candidate['default_app']) && \PortalWeaveSSO\Config::save($candidate) === true, 'Deleting a nondefault provider failed');
    portalweave_sso_assert(array_keys(\PortalWeaveSSO\Config::apps()) === ['alpha'] && \PortalWeaveSSO\Config::get('default_app') === 'alpha', 'Nondefault deletion changed another app/default');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['force_auth' => true]) === true, 'Unable to enable guarded deletion fixture');
    $before = get_option('portalweave_sso_settings');
    $candidate = \PortalWeaveSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'alpha', '_delete' => '1']]], 'providers', 'alpha');
    portalweave_sso_assert(!is_wp_error($candidate) && $candidate['default_app'] === '', 'Default deletion did not clear its reference');
    portalweave_sso_error(\PortalWeaveSSO\Config::save($candidate), 'portalweave_sso_lockout');
    portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'Rejected deletion changed an enforced login policy');
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['force_auth' => false]) === true && \PortalWeaveSSO\Config::save($candidate) === true, 'Explicit default deletion failed after disabling enforcement');
    portalweave_sso_assert(\PortalWeaveSSO\Config::apps() === [] && \PortalWeaveSSO\Config::get('default_app') === '', 'Default deletion left a dangling default');
});

if (is_multisite()) {
    portalweave_sso_test('Admin settings: network screen saves preserve encrypted credentials and independent local settings', function () {
        $original = get_site_option('portalweave_sso_settings', []);
        $local = get_option('portalweave_sso_settings', []);
        try {
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [
                'alpha' => portalweave_sso_app(['id' => 'alpha', 'client_secret' => 'network-alpha-secret-at-least-thirty-two-bytes']),
                'beta' => portalweave_sso_app(['id' => 'beta', 'client_secret' => 'network-beta-secret-at-least-thirty-two-bytes']),
            ], 'default_app' => 'alpha', 'scim_token' => 'network-scim-secret-at-least-thirty-two-bytes'], true) === true, 'Unable to save network fixture');
            $before = get_site_option('portalweave_sso_settings');
            $candidate = \PortalWeaveSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'alpha', 'name' => 'Network Alpha', 'client_secret' => '']]], 'providers', 'alpha', false, true);
            portalweave_sso_assert(!is_wp_error($candidate) && !str_contains(wp_json_encode($candidate), 'enc:v1:'), 'Network candidate forwarded ciphertext');
            portalweave_sso_assert(\PortalWeaveSSO\Config::save($candidate, true) === true, 'Network provider save failed');
            $appearance = \PortalWeaveSSO\AdminSettings::prepare(['button' => ['theme' => 'white']], 'appearance', '', false, true);
            portalweave_sso_assert(!is_wp_error($appearance) && !isset($appearance['apps']) && \PortalWeaveSSO\Config::save($appearance, true) === true, 'Network appearance save failed');
            $after = get_site_option('portalweave_sso_settings');
            foreach (['alpha', 'beta'] as $id) {
                portalweave_sso_assert($after['apps'][$id]['client_secret'] === $before['apps'][$id]['client_secret'], 'Network secret was double-encrypted or lost');
                portalweave_sso_assert(\PortalWeaveSSO\Secrets::decrypt($after['apps'][$id]['client_secret']) === \PortalWeaveSSO\Secrets::decrypt($before['apps'][$id]['client_secret']), 'Network secret can no longer decrypt');
            }
            portalweave_sso_assert($after['scim_token'] === $before['scim_token'], 'Unrelated network bearer credential changed');
            portalweave_sso_assert(get_option('portalweave_sso_settings', []) === $local, 'Network form saved into local settings');
            $enforcement = \PortalWeaveSSO\AdminSettings::prepare(['network_enforced' => '1'], 'access', '', false, true);
            portalweave_sso_assert(!is_wp_error($enforcement) && \PortalWeaveSSO\Config::save($enforcement, true) === true, 'Network-only enforcement control failed');
        } finally { update_site_option('portalweave_sso_settings', $original); }
    });
}
