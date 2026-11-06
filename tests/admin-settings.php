<?php
/** Screen saves use the real configuration API, including encrypted provider storage. */

wpsso_test('Admin settings: appearance saves preserve providers, secrets and unrelated policy', function () {
    $alpha = wpsso_app(['id' => 'alpha', 'name' => 'Alpha']);
    $beta = wpsso_app(['id' => 'beta', 'name' => 'Beta', 'client_secret' => 'another-provider-secret-at-least-thirty-two-bytes']);
    wpsso_assert(\WPSSO\Config::save(['apps' => ['alpha' => $alpha, 'beta' => $beta], 'default_app' => 'alpha', 'force_auth' => true,
        'button' => ['text' => 'Company sign-in', 'radius' => 19, 'shadow' => true], 'modules' => ['avatar' => true],
        'support' => ['email' => 'support@example.org']]) === true, 'Unable to save appearance fixture');
    $before = get_option('wpsso_settings');
    $candidate = \WPSSO\AdminSettings::prepare(['button' => ['theme' => 'white', 'shadow' => '0']], 'appearance');
    wpsso_assert(!is_wp_error($candidate) && !isset($candidate['apps']), 'Appearance candidate resubmitted hidden providers');
    wpsso_assert(\WPSSO\Config::save($candidate) === true, 'Appearance screen save failed');
    $after = get_option('wpsso_settings');
    foreach (['apps', 'default_app', 'force_auth', 'modules', 'support'] as $key) {
        wpsso_assert($after[$key] === $before[$key], 'Appearance save changed unrelated setting: ' . $key);
    }
    wpsso_assert($after['button']['theme'] === 'white' && !$after['button']['shadow'], 'Appearance changes were not saved');
    wpsso_assert($after['button']['text'] === 'Company sign-in' && $after['button']['radius'] === 19, 'Partial appearance save reset hidden fields');
});

wpsso_test('Admin settings: unchecked controls and nested partial saves preserve other fields', function () {
    wpsso_assert(\WPSSO\Config::save(['popup' => true, 'audit_ip' => true, 'debug' => true, 'audit_retention_days' => 120,
        'modules' => ['avatar' => true, 'moderation' => true], 'support' => ['email' => 'help@example.org', 'forum_url' => 'https://example.org/forum']]) === true, 'Unable to save partial-update fixture');
    foreach ([
        [['audit_enabled' => '0', 'audit_ip' => '0', 'debug' => '0'], 'reports'],
        [['modules' => ['avatar' => '0']], 'modules'],
        [['support' => ['email' => 'new-help@example.org']], 'tools'],
    ] as [$input, $screen]) {
        $candidate = \WPSSO\AdminSettings::prepare($input, $screen);
        wpsso_assert(!is_wp_error($candidate) && \WPSSO\Config::save($candidate) === true, 'Partial screen save failed: ' . $screen);
    }
    $settings = \WPSSO\Config::all();
    wpsso_assert(!$settings['audit_enabled'] && !$settings['audit_ip'] && !$settings['debug'], 'Unchecked report controls were retained');
    wpsso_assert($settings['popup'] && $settings['audit_retention_days'] === 120, 'Report save reset unrelated values');
    wpsso_assert(!$settings['modules']['avatar'] && $settings['modules']['moderation'], 'Module save reset an unsubmitted module');
    wpsso_assert($settings['support']['email'] === 'new-help@example.org' && $settings['support']['forum_url'] === 'https://example.org/forum', 'Support save lost an unsubmitted destination');
});

wpsso_test('Admin settings: unknown screens, cross-screen fields and provider retargeting reject without mutations', function () {
    $app = wpsso_app(['id' => 'alpha']);
    wpsso_assert(\WPSSO\Config::save(['apps' => ['alpha' => $app]]) === true, 'Unable to save scope fixture');
    $before = get_option('wpsso_settings');
    foreach ([
        [['button' => ['theme' => 'white']], 'overview'],
        [['debug' => '1'], 'unknown'],
        [['force_auth' => '1'], 'appearance'],
        [['allowed_domains' => ['example.org']], 'login'],
        [['apps' => []], 'reports'],
        [['network_enforced' => '1'], 'access'],
        [['button' => ['client_secret' => 'wrong-scope']], 'appearance'],
    ] as [$input, $screen]) {
        wpsso_error(\WPSSO\AdminSettings::prepare($input, $screen), 'wpsso_settings_scope');
    }
    wpsso_error(\WPSSO\AdminSettings::prepare(['apps' => ['other' => ['id' => 'other']]], 'providers', 'alpha'), 'wpsso_provider_target');
    wpsso_error(\WPSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'other']]], 'providers', 'alpha'), 'wpsso_provider_target');
    wpsso_error(\WPSSO\AdminSettings::prepare(['audit_ip' => '0'], 'reports', 'alpha'), 'wpsso_provider_target');
    wpsso_assert(get_option('wpsso_settings') === $before, 'Rejected screen preparation mutated saved settings');
});

wpsso_test('Admin settings: editing one provider retains every hidden provider and stored credential', function () {
    wpsso_assert(\WPSSO\Config::save(['apps' => [
        'alpha' => wpsso_app(['id' => 'alpha', 'name' => 'Alpha', 'keep_email' => true, 'client_secret' => 'alpha-provider-secret-at-least-thirty-two-bytes']),
        'beta' => wpsso_app(['id' => 'beta', 'name' => 'Beta', 'client_secret' => 'beta-provider-secret-at-least-thirty-two-bytes']),
    ], 'default_app' => 'beta']) === true, 'Unable to save two-provider fixture');
    $before = get_option('wpsso_settings');
    $candidate = \WPSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'alpha', 'name' => 'Edited Alpha', 'keep_email' => '0', 'client_secret' => '']]], 'providers', 'alpha');
    wpsso_assert(!is_wp_error($candidate) && $candidate['apps']['beta'] === [], 'Hidden provider was forwarded instead of preserved');
    wpsso_assert(!str_contains(wp_json_encode($candidate), 'enc:v1:'), 'Candidate forwarded stored ciphertext');
    wpsso_assert(\WPSSO\Config::save($candidate) === true, 'Single-provider save failed');
    $after = get_option('wpsso_settings');
    wpsso_assert($after['apps']['beta'] === $before['apps']['beta'], 'Editing Alpha changed Beta');
    wpsso_assert($after['apps']['alpha']['client_secret'] === $before['apps']['alpha']['client_secret'], 'Blank provider secret was re-encrypted or cleared');
    wpsso_assert(\WPSSO\Config::app('alpha')['name'] === 'Edited Alpha' && !\WPSSO\Config::app('alpha')['keep_email'], 'Selected provider changes were lost');
    wpsso_assert($after['default_app'] === 'beta', 'Provider edit reset the default provider');
});

wpsso_test('Admin settings: new is a valid provider ID and creation cannot replace existing providers', function () {
    wpsso_assert(\WPSSO\Config::save(['apps' => ['alpha' => wpsso_app(['id' => 'alpha'])], 'default_app' => 'alpha']) === true, 'Unable to save creation fixture');
    $candidate = \WPSSO\AdminSettings::prepare(['new_app' => wpsso_app(['id' => 'new', 'name' => 'New provider'])], 'providers', '', true);
    wpsso_assert(!is_wp_error($candidate) && $candidate['apps']['alpha'] === [], 'Adding a provider did not preserve the existing app');
    wpsso_assert(\WPSSO\Config::save($candidate) === true, 'Provider with ID new could not be created');
    wpsso_assert(array_keys(\WPSSO\Config::apps()) === ['alpha', 'new'], 'Provider creation replaced the collection');
    wpsso_error(\WPSSO\AdminSettings::prepare(['new_app' => wpsso_app(['id' => 'alpha'])], 'providers', '', true), 'wpsso_app_exists');
    $numeric = \WPSSO\AdminSettings::prepare(['new_app' => wpsso_app(['id' => '123'])], 'providers', '', true);
    wpsso_assert(!is_wp_error($numeric) && \WPSSO\Config::save($numeric) === true, 'A numeric provider ID was rejected');
    $numeric_edit = \WPSSO\AdminSettings::prepare(['apps' => [123 => ['id' => '123', 'name' => 'Numeric provider']]], 'providers', '123');
    wpsso_assert(!is_wp_error($numeric_edit) && \WPSSO\Config::save($numeric_edit) === true, 'Numeric PHP array keys broke provider editing');
    wpsso_assert(\WPSSO\Config::get('default_app') === 'alpha', 'Creation changed the existing default');
});

wpsso_test('Admin settings: explicit provider deletion removes only the selected app and safely handles its default', function () {
    wpsso_assert(\WPSSO\Config::save(['apps' => [
        'alpha' => wpsso_app(['id' => 'alpha']), 'beta' => wpsso_app(['id' => 'beta']),
    ], 'default_app' => 'alpha']) === true, 'Unable to save deletion fixture');
    $candidate = \WPSSO\AdminSettings::prepare(['apps' => ['beta' => ['id' => 'beta', '_delete' => '1']]], 'providers', 'beta');
    wpsso_assert(!is_wp_error($candidate) && !isset($candidate['default_app']) && \WPSSO\Config::save($candidate) === true, 'Deleting a nondefault provider failed');
    wpsso_assert(array_keys(\WPSSO\Config::apps()) === ['alpha'] && \WPSSO\Config::get('default_app') === 'alpha', 'Nondefault deletion changed another app/default');
    wpsso_assert(\WPSSO\Config::save(['force_auth' => true]) === true, 'Unable to enable guarded deletion fixture');
    $before = get_option('wpsso_settings');
    $candidate = \WPSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'alpha', '_delete' => '1']]], 'providers', 'alpha');
    wpsso_assert(!is_wp_error($candidate) && $candidate['default_app'] === '', 'Default deletion did not clear its reference');
    wpsso_error(\WPSSO\Config::save($candidate), 'wpsso_lockout');
    wpsso_assert(get_option('wpsso_settings') === $before, 'Rejected deletion changed an enforced login policy');
    wpsso_assert(\WPSSO\Config::save(['force_auth' => false]) === true && \WPSSO\Config::save($candidate) === true, 'Explicit default deletion failed after disabling enforcement');
    wpsso_assert(\WPSSO\Config::apps() === [] && \WPSSO\Config::get('default_app') === '', 'Default deletion left a dangling default');
});

if (is_multisite()) {
    wpsso_test('Admin settings: network screen saves preserve encrypted credentials and independent local settings', function () {
        $original = get_site_option('wpsso_settings', []);
        $local = get_option('wpsso_settings', []);
        try {
            wpsso_assert(\WPSSO\Config::save(['apps' => [
                'alpha' => wpsso_app(['id' => 'alpha', 'client_secret' => 'network-alpha-secret-at-least-thirty-two-bytes']),
                'beta' => wpsso_app(['id' => 'beta', 'client_secret' => 'network-beta-secret-at-least-thirty-two-bytes']),
            ], 'default_app' => 'alpha', 'scim_token' => 'network-scim-secret-at-least-thirty-two-bytes'], true) === true, 'Unable to save network fixture');
            $before = get_site_option('wpsso_settings');
            $candidate = \WPSSO\AdminSettings::prepare(['apps' => ['alpha' => ['id' => 'alpha', 'name' => 'Network Alpha', 'client_secret' => '']]], 'providers', 'alpha', false, true);
            wpsso_assert(!is_wp_error($candidate) && !str_contains(wp_json_encode($candidate), 'enc:v1:'), 'Network candidate forwarded ciphertext');
            wpsso_assert(\WPSSO\Config::save($candidate, true) === true, 'Network provider save failed');
            $appearance = \WPSSO\AdminSettings::prepare(['button' => ['theme' => 'white']], 'appearance', '', false, true);
            wpsso_assert(!is_wp_error($appearance) && !isset($appearance['apps']) && \WPSSO\Config::save($appearance, true) === true, 'Network appearance save failed');
            $after = get_site_option('wpsso_settings');
            foreach (['alpha', 'beta'] as $id) {
                wpsso_assert($after['apps'][$id]['client_secret'] === $before['apps'][$id]['client_secret'], 'Network secret was double-encrypted or lost');
                wpsso_assert(\WPSSO\Secrets::decrypt($after['apps'][$id]['client_secret']) === \WPSSO\Secrets::decrypt($before['apps'][$id]['client_secret']), 'Network secret can no longer decrypt');
            }
            wpsso_assert($after['scim_token'] === $before['scim_token'], 'Unrelated network bearer credential changed');
            wpsso_assert(get_option('wpsso_settings', []) === $local, 'Network form saved into local settings');
            $enforcement = \WPSSO\AdminSettings::prepare(['network_enforced' => '1'], 'access', '', false, true);
            wpsso_assert(!is_wp_error($enforcement) && \WPSSO\Config::save($enforcement, true) === true, 'Network-only enforcement control failed');
        } finally { update_site_option('wpsso_settings', $original); }
    });
}
