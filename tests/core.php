<?php
/** Real-WordPress security and persistence checks for configuration, accounts, and access. */

function portalweave_sso_core_fixture(callable $test): void {
    $stored = get_option('portalweave_sso_settings', null);
    $current = get_current_user_id();
    $users = [];
    $posts = [];
    $terms = [];
    $prefix = 'portalweave_sso_' . strtolower(wp_generate_password(12, false, false));
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    try {
        update_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults(), false);
        $test($users, $posts, $terms, $prefix);
    } finally {
        wp_set_current_user($current);
        foreach (array_reverse($posts) as $post_id) {
            if (get_post_type($post_id) === 'attachment') {
                wp_delete_attachment($post_id, true);
            } else {
                wp_delete_post($post_id, true);
            }
        }
        foreach ($terms as $term_id) {
            wp_delete_term($term_id, 'category');
        }
        foreach (array_unique($users) as $user_id) {
            wp_delete_user($user_id);
        }
        if ($stored === null) {
            delete_option('portalweave_sso_settings');
        } else {
            update_option('portalweave_sso_settings', $stored, false);
        }
    }
}

function portalweave_sso_core_app(array $overrides = []): array {
    return array_replace(\PortalWeaveSSO\Config::app_defaults(), [
        'id' => 'core-test', 'name' => 'Core test', 'client_id' => 'client-core-test',
        'authorize_url' => 'https://idp.example.test/authorize', 'token_url' => 'https://idp.example.test/token',
        'userinfo_url' => 'https://idp.example.test/userinfo', 'issuer' => 'https://idp.example.test',
        'jwks_url' => 'https://idp.example.test/jwks',
    ], $overrides);
}

function portalweave_sso_core_attributes(string $prefix, array $overrides = []): array {
    return array_replace([
        'sub' => $prefix . '-subject', 'email' => $prefix . '@example.test', 'email_verified' => true,
        'preferred_username' => $prefix, 'given_name' => 'Provider', 'family_name' => 'Person',
        'name' => 'Provider Person', 'groups' => [],
    ], $overrides);
}

function portalweave_sso_core_local(array &$users, string $prefix, string $role = 'subscriber'): WP_User {
    $id = wp_insert_user(['user_login' => $prefix, 'user_email' => $prefix . '@example.test',
        'user_pass' => wp_generate_password(48, true, true), 'role' => $role,
        'first_name' => 'Local', 'last_name' => 'Person', 'display_name' => 'Local Person']);
    portalweave_sso_assert(!is_wp_error($id), 'Local fixture user could not be created.');
    $users[] = $id;
    return get_userdata($id);
}

portalweave_sso_test('core: secure defaults keep admin SSO, automation and legacy flows off', function (): void {
    $defaults = \PortalWeaveSSO\Config::defaults();
    $app = \PortalWeaveSSO\Config::app_defaults();
    portalweave_sso_assert(!$defaults['abilities_enabled'] && !array_filter($defaults['modules']), 'Opt-in modules must start disabled.');
    portalweave_sso_assert(!$app['allow_admin'] && !$app['legacy_grants'] && !$app['link_existing'], 'Sensitive account policies must start disabled.');
    portalweave_sso_assert($app['pkce'] && $app['grant'] === 'authorization_code', 'Code with PKCE must be the default flow.');
});

portalweave_sso_test('core: credentials encrypt at rest, preserve blank edits, and clear only explicitly', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $secret = $prefix . '-client-secret';
        $app = portalweave_sso_core_app(['client_secret' => $secret, 'jwt_secret' => str_repeat('h', 40)]);
        $saved = \PortalWeaveSSO\Config::save(['apps' => [$app['id'] => $app], 'scim_token' => str_repeat('s', 40)]);
        portalweave_sso_assert($saved === true, 'A valid provider should save.');
        $raw = get_option('portalweave_sso_settings');
        portalweave_sso_assert(str_starts_with($raw['apps'][$app['id']]['client_secret'], 'enc:v1:'), 'Client secret is not authenticated ciphertext.');
        portalweave_sso_assert(!str_contains(wp_json_encode($raw), $secret), 'Client secret leaked in persisted settings.');
        portalweave_sso_assert(\PortalWeaveSSO\Config::app($app['id'])['client_secret'] === $secret, 'Credential round-trip changed the secret.');
        $result = \PortalWeaveSSO\Config::save(['apps' => [$app['id'] => ['client_secret' => '', 'name' => 'Renamed']]]);
        portalweave_sso_assert($result === true && \PortalWeaveSSO\Config::app($app['id'])['client_secret'] === $secret, 'A blank form edit must retain the credential.');
        $result = \PortalWeaveSSO\Config::save(['apps' => [$app['id'] => ['clear_client_secret' => true]], 'clear_scim_token' => true]);
        portalweave_sso_assert($result === true && \PortalWeaveSSO\Config::app($app['id'])['client_secret'] === '' && \PortalWeaveSSO\Config::get('scim_token') === '', 'Explicit clear controls did not remove credentials.');
    });
});

portalweave_sso_test('core: support configuration strips client IDs, credentials, endpoint queries and custom state', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $app = portalweave_sso_core_app(['client_id' => $prefix . '-client-id', 'client_secret' => $prefix . '-secret',
            'authorize_url' => 'https://idp.example.test/authorize?client_id=must-not-export', 'state_parameter' => $prefix . '-private-state']);
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [$app['id'] => $app], 'scim_token' => str_repeat('x', 40)]) === true, 'Provider fixture failed to save.');
        $export = wp_json_encode(\PortalWeaveSSO\Config::redacted());
        foreach ([$prefix . '-client-id', $prefix . '-secret', $prefix . '-private-state', 'must-not-export', str_repeat('x', 40)] as $sensitive) {
            portalweave_sso_assert(!str_contains($export, $sensitive), 'Redacted export leaked sensitive configuration.');
        }
    });
});

portalweave_sso_test('core: redacted backups retain local credentials and restore incomplete apps safely on clean installs', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $secret = $prefix . '-restore-secret';
        $app = portalweave_sso_core_app(['client_id' => $prefix . '-restore-client', 'client_secret' => $secret]);
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => [$app['id'] => $app], 'default_app' => $app['id'], 'force_auth' => true, 'disable_wp_login' => true]) === true, 'Backup source fixture failed.');
        $backup = \PortalWeaveSSO\Config::redacted();
        portalweave_sso_assert(\PortalWeaveSSO\Config::import($backup) === true, 'Same-site redacted restore failed.');
        $restored = \PortalWeaveSSO\Config::app($app['id']);
        portalweave_sso_assert($restored['client_id'] === $app['client_id'] && $restored['client_secret'] === $secret && $restored['enabled'], 'Same-site restore lost existing credentials or disabled a complete provider.');
        portalweave_sso_assert(\PortalWeaveSSO\Config::get('force_auth') === true, 'Same-site restore lost a complete access policy.');
        // The admin backup omits credential fields; the CLI backup includes redacted blank fields.
        unset($backup['apps'][$app['id']]['client_id'], $backup['apps'][$app['id']]['client_secret'], $backup['apps'][$app['id']]['jwt_secret']);
        portalweave_sso_assert(\PortalWeaveSSO\Config::import($backup) === true && \PortalWeaveSSO\Config::app($app['id'])['client_secret'] === $secret, 'Omitted-field backup failed to retain the existing credential.');
        update_option('portalweave_sso_settings', \PortalWeaveSSO\Config::defaults(), false);
        portalweave_sso_assert(\PortalWeaveSSO\Config::import($backup) === true, 'Credential-free backup could not restore on a clean site.');
        $clean = \PortalWeaveSSO\Config::app($app['id']);
        portalweave_sso_assert(is_array($clean) && !$clean['enabled'] && $clean['client_id'] === '' && $clean['client_secret'] === '', 'Clean restore enabled an incomplete provider or invented credentials.');
        foreach (['force_auth', 'restrict_site', 'disable_wp_login', 'single_flow'] as $key) {
            portalweave_sso_assert(\PortalWeaveSSO\Config::get($key) === false, 'Clean restore enabled login enforcement without a complete provider: ' . $key);
        }
    });
});

portalweave_sso_test('core: endpoint, callback, algorithm, PKCE and privileged-meta policies fail closed', function (): void {
    portalweave_sso_core_fixture(function (): void {
        $cases = [
            [['token_url' => 'http://idp.example.test/token'], 'portalweave_sso_endpoint'],
            [['callback_url' => 'https://other.example.test/callback'], 'portalweave_sso_callback_origin'],
            [['algorithms' => ['none']], 'portalweave_sso_algorithm'],
            [['algorithms' => ['RS256', 'HS256']], 'portalweave_sso_algorithm_family'],
            [['credential_method' => 'none', 'pkce' => false], 'portalweave_sso_pkce'],
            [['grant' => 'password', 'legacy_grants' => false], 'portalweave_sso_legacy_grant'],
            [['custom_mapping' => ['wp_capabilities' => 'roles']], 'portalweave_sso_meta'],
            [['custom_mapping' => ['session_tokens' => 'session']], 'portalweave_sso_meta'],
            [['custom_mapping' => ['portalweave_sso_moderation' => 'status']], 'portalweave_sso_meta'],
            [['custom_mapping' => ['profile_portalweave_sso_groups' => 'groups']], 'portalweave_sso_meta'],
        ];
        foreach ($cases as [$overrides, $code]) {
            $app = portalweave_sso_core_app($overrides);
            portalweave_sso_error(\PortalWeaveSSO\Config::save(['apps' => [$app['id'] => $app]]), $code);
        }
        portalweave_sso_error(\PortalWeaveSSO\Config::save(['force_auth' => true]), 'portalweave_sso_lockout');
        portalweave_sso_assert(\PortalWeaveSSO\Config::get('force_auth') === false, 'A rejected policy write mutated saved settings.');
    });
});

portalweave_sso_test('core: redirect allowlists enforce exact origins, path boundaries and normalization', function (): void {
    portalweave_sso_core_fixture(function (): void {
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['redirect_allowlist' => ['https://portal.example.test/members']]) === true, 'Allowlist fixture failed.');
        $fallback = home_url('/safe-fallback');
        portalweave_sso_assert(\PortalWeaveSSO\Redirect::validate('https://portal.example.test/members/profile?tab=1', $fallback) === 'https://portal.example.test/members/profile?tab=1', 'Allowed path was rejected.');
        portalweave_sso_assert(\PortalWeaveSSO\Redirect::validate(home_url('/inside'), $fallback) === home_url('/inside'), 'Same-origin URL was rejected.');
        foreach (['https://portal.example.test/membership', 'https://portal.example.test.evil.test/members', 'https://portal.example.test:444/members',
            'https://portal.example.test/members/%2e%2e/private', 'https://portal.example.test/members/%252e%252e/private',
            'https://portal.example.test/members/%5cprivate', '//evil.example.test/path', 'javascript:alert(1)',
            'https://portal.example.test/members/%0d%0aLocation:evil', 'https://user@portal.example.test/members'] as $url) {
            portalweave_sso_assert(\PortalWeaveSSO\Redirect::validate($url, $fallback) === $fallback, 'Untrusted redirect passed: ' . $url);
        }
    });
});

portalweave_sso_test('core: verified first login creates an account and immutable subject reuses it', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $app = portalweave_sso_core_app(['custom_mapping' => ['department' => 'org.department']]);
        $attributes = portalweave_sso_core_attributes($prefix, ['org' => ['department' => 'Engineering']]);
        $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($user instanceof WP_User, 'Verified provider login did not create a user.');
        $users[] = $user->ID;
        portalweave_sso_assert($user->user_email === $attributes['email'] && in_array('subscriber', $user->roles, true), 'Created account has incorrect email or role.');
        portalweave_sso_assert(get_user_meta($user->ID, 'department', true) === 'Engineering', 'Custom nested attribute was not saved.');
        $again = \PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['preferred_username' => $prefix . '_renamed', 'email' => $prefix . '-new@example.test']));
        portalweave_sso_assert($again instanceof WP_User && $again->ID === $user->ID, 'The immutable provider identity created another account.');
        portalweave_sso_assert($again->user_login === $user->user_login, 'Provider profile changes renamed the immutable WP login.');
    });
});

portalweave_sso_test('core: OIDC always binds the signed subject while OAuth honors its configured identifier', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $app = portalweave_sso_core_app(['subject_attribute' => 'mutable_alias']);
        $attributes = portalweave_sso_core_attributes($prefix, ['mutable_alias' => 'first-alias']);
        $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($user instanceof WP_User, 'OIDC fixture failed.');
        $users[] = $user->ID;
        $again = \PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['mutable_alias' => 'changed-alias']));
        portalweave_sso_assert($again instanceof WP_User && $again->ID === $user->ID, 'A mutable OIDC alias replaced the signed subject binding.');
        $missing_sub = $attributes;
        unset($missing_sub['sub']);
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, $missing_sub), 'portalweave_sso_subject');
        $oauth = portalweave_sso_core_app(['id' => 'core-oauth', 'protocol' => 'oauth2', 'subject_attribute' => 'provider_id']);
        $oauth_attributes = portalweave_sso_core_attributes($prefix . '_oauth', ['provider_id' => 'immutable-oauth-id']);
        unset($oauth_attributes['sub']);
        $oauth_user = \PortalWeaveSSO\Accounts::resolve($oauth, $oauth_attributes);
        portalweave_sso_assert($oauth_user instanceof WP_User, 'OAuth did not honor its configured immutable identifier.');
        $users[] = $oauth_user->ID;
    });
});

portalweave_sso_test('core: existing-account email linking is opt-in and requires verified mailbox ownership', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $local = portalweave_sso_core_local($users, $prefix);
        $attributes = portalweave_sso_core_attributes($prefix);
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_core_app(), $attributes), 'portalweave_sso_link_required');
        $app = portalweave_sso_core_app(['link_existing' => true, 'require_verified_email' => false]);
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['email_verified' => false])), 'portalweave_sso_link_verification');
        $linked = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($linked instanceof WP_User && $linked->ID === $local->ID, 'Verified email did not link the existing account.');
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['sub' => 'other-provider-identity'])), 'portalweave_sso_identity_conflict');
    });
});

portalweave_sso_test('core: registration-disabled, missing subjects and unverified claims reject before creation', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = portalweave_sso_core_attributes($prefix);
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_core_app(['auto_register' => false]), $attributes), 'portalweave_sso_registration_disabled');
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_core_app(), array_replace($attributes, ['sub' => []])), 'portalweave_sso_subject');
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_core_app(), array_replace($attributes, ['email_verified' => false])), 'portalweave_sso_unverified');
        portalweave_sso_assert(!get_user_by('email', $attributes['email']), 'A rejected identity created a local account.');
    });
});

portalweave_sso_test('core: administrators require per-provider enablement and a verified configured claim', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $admin = portalweave_sso_core_local($users, $prefix, 'administrator');
        $attributes = portalweave_sso_core_attributes($prefix, ['account' => ['status' => 'approved']]);
        $app = portalweave_sso_core_app(['link_existing' => true, 'verified_attribute' => 'account.status', 'verified_value' => 'approved']);
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, $attributes), 'portalweave_sso_admin_disabled');
        $app['allow_admin'] = true;
        $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($user instanceof WP_User && $user->ID === $admin->ID && $user->has_cap('manage_options'), 'Approved admin lost its account or roles.');
        $app['require_verified_email'] = false;
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['account' => ['status' => 'pending']])), 'portalweave_sso_admin_disabled');
    });
});

portalweave_sso_test('core: group role mapping blocks implicit privilege escalation', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = portalweave_sso_core_attributes($prefix, ['groups' => ['admins']]);
        $app = portalweave_sso_core_app(['role_mapping' => ['admins' => ['administrator']]]);
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, $attributes), 'portalweave_sso_privileged_role');
        portalweave_sso_assert(!get_user_by('email', $attributes['email']), 'Rejected privileged mapping still created an account.');
    });
});

portalweave_sso_test('core: group and domain mappings assign roles and enforce explicit-match policy', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = portalweave_sso_core_attributes($prefix, ['groups' => ['editors', 'writers']]);
        $app = portalweave_sso_core_app(['role_mapping' => ['editors' => ['editor'], 'writers' => ['author']], 'block_unmapped' => true]);
        $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($user instanceof WP_User, 'Mapped role login failed.');
        $users[] = $user->ID;
        portalweave_sso_assert(in_array('editor', $user->roles, true) && in_array('author', $user->roles, true), 'Multiple mapped roles were not applied.');
        portalweave_sso_assert(get_user_meta($user->ID, '_portalweave_sso_groups', true) === ['editors', 'writers'], 'Provider groups were not persisted.');
        $other = portalweave_sso_core_attributes($prefix . '_other');
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, $other), 'portalweave_sso_role_unmapped');
        $app['domain_roles'] = ['example.test' => ['contributor']];
        $domain_user = \PortalWeaveSSO\Accounts::resolve($app, $other);
        portalweave_sso_assert($domain_user instanceof WP_User, 'Domain mapping did not satisfy explicit mapping.');
        $users[] = $domain_user->ID;
        portalweave_sso_assert(in_array('contributor', $domain_user->roles, true), 'Domain role was not applied.');
    });
});

portalweave_sso_test('core: keep-existing attributes, email and roles preserve local data', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $local = portalweave_sso_core_local($users, $prefix, 'editor');
        update_user_meta($local->ID, 'department', 'Local department');
        $app = portalweave_sso_core_app(['link_existing' => true, 'keep_attributes' => true, 'keep_email' => true,
            'keep_roles' => true, 'role_mapping' => ['readers' => ['subscriber']], 'custom_mapping' => ['department' => 'department']]);
        $attributes = portalweave_sso_core_attributes($prefix, ['department' => 'Provider department', 'groups' => ['readers']]);
        $first = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($first instanceof WP_User && $first->ID === $local->ID, 'Existing account link failed.');
        $second = \PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['email' => $prefix . '-updated@example.test', 'name' => 'Changed provider name']));
        portalweave_sso_assert($second instanceof WP_User && $second->user_email === $local->user_email && $second->display_name === 'Local Person', 'Keep-existing policy overwrote local profile data.');
        portalweave_sso_assert(in_array('editor', $second->roles, true) && !in_array('subscriber', $second->roles, true), 'Keep-existing roles were replaced.');
        portalweave_sso_assert(get_user_meta($second->ID, 'department', true) === 'Local department', 'Keep-existing custom attributes were overwritten.');
    });
});

portalweave_sso_test('core: duplicate emails require opt-in and remain separate subject identities', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $local = portalweave_sso_core_local($users, $prefix);
        $attributes = portalweave_sso_core_attributes($prefix);
        $app = portalweave_sso_core_app(['allow_duplicate_emails' => true]);
        $user = \PortalWeaveSSO\Accounts::resolve($app, $attributes);
        portalweave_sso_assert($user instanceof WP_User, 'Opt-in duplicate-email creation failed.');
        $users[] = $user->ID;
        portalweave_sso_assert($user->ID !== $local->ID && $user->user_email === $local->user_email, 'Duplicate-email policy linked the wrong account or lost the email.');
        $app['link_existing'] = true;
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['sub' => $prefix . '-other'])), 'portalweave_sso_link_verification');
    });
});

portalweave_sso_test('core: domain allow/block policy and domain rewrites cannot take over existing mailboxes', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = portalweave_sso_core_attributes($prefix);
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['allowed_domains' => ['allowed.test']]) === true, 'Allowed domain fixture failed.');
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_core_app(), $attributes), 'portalweave_sso_domain_blocked');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['allowed_domains' => [], 'blocked_domains' => ['example.test']]) === true, 'Blocked domain fixture failed.');
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve(portalweave_sso_core_app(), $attributes), 'portalweave_sso_domain_blocked');
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['blocked_domains' => []]) === true, 'Clearing domain fixture failed.');
        portalweave_sso_core_local($users, $prefix);
        $app = portalweave_sso_core_app(['link_existing' => true, 'domain_mapping' => ['old.test' => 'example.test']]);
        portalweave_sso_error(\PortalWeaveSSO\Accounts::resolve($app, array_replace($attributes, ['email' => $prefix . '@old.test'])), 'portalweave_sso_link_verification');
    });
});

portalweave_sso_test('core: role/group access is enforced for anonymous, local and SCIM-mapped users', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $user = portalweave_sso_core_local($users, $prefix);
        $anonymous = new WP_User(0);
        portalweave_sso_assert(\PortalWeaveSSO\Access::allows([], $anonymous), 'Empty policy should permit public access.');
        portalweave_sso_assert(!\PortalWeaveSSO\Access::allows(['login' => true], $anonymous), 'Anonymous user passed a login requirement.');
        portalweave_sso_assert(\PortalWeaveSSO\Access::allows(['login' => true], $user), 'Logged-in user failed the login requirement.');
        portalweave_sso_assert(!\PortalWeaveSSO\Access::allows(['roles' => ['editor']], $user), 'Unmatched role was granted access.');
        update_user_meta($user->ID, '_portalweave_sso_groups', ['staff']);
        update_user_meta($user->ID, '_portalweave_sso_scim_groups', ['provisioned']);
        portalweave_sso_assert(\PortalWeaveSSO\Access::allows(['roles' => ['editor'], 'groups' => ['staff']], $user), 'Matching provider group did not grant access.');
        portalweave_sso_assert(\PortalWeaveSSO\Access::allows(['groups' => ['provisioned']], $user), 'SCIM group did not grant access.');
    });
});

portalweave_sso_test('core: post and category policies intersect and REST cannot expose restricted content', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $subscriber = portalweave_sso_core_local($users, $prefix);
        $editor = portalweave_sso_core_local($users, $prefix . '_editor', 'editor');
        $term = wp_insert_term($prefix, 'category');
        portalweave_sso_assert(!is_wp_error($term), 'Category fixture failed.');
        $terms[] = $term['term_id'];
        $post = wp_insert_post(['post_title' => $prefix, 'post_content' => 'Private body', 'post_status' => 'publish', 'post_category' => [$term['term_id']]]);
        portalweave_sso_assert($post > 0, 'Post fixture failed.');
        $posts[] = $post;
        update_post_meta($post, '_portalweave_sso_rule', ['login' => true]);
        update_term_meta($term['term_id'], '_portalweave_sso_rule', ['roles' => ['editor']]);
        portalweave_sso_assert(!\PortalWeaveSSO\Access::can_post($post, new WP_User(0)) && !\PortalWeaveSSO\Access::can_post($post, $subscriber), 'Post/category policy leaked to an ineligible user.');
        portalweave_sso_assert(\PortalWeaveSSO\Access::can_post($post, $editor), 'Eligible editor could not access the post.');
        wp_set_current_user(0);
        portalweave_sso_assert(in_array($post, \PortalWeaveSSO\Access::denied_ids(), true), 'Restricted category post was not excluded from archive queries.');
        $request = new WP_REST_Request('GET', '/wp/v2/posts/' . $post);
        $request->set_param('id', $post);
        portalweave_sso_error(\PortalWeaveSSO\Access::rest_content(null, rest_get_server(), $request), 'portalweave_sso_content_denied');
        wp_set_current_user($editor->ID);
        portalweave_sso_assert(\PortalWeaveSSO\Access::rest_content(null, rest_get_server(), $request) === null, 'REST blocked an eligible editor.');
    });
});

portalweave_sso_test('core: protected attachments leave public uploads, rewrite URLs, and safely restore', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $upload = wp_upload_bits($prefix . '.txt', null, 'Private media fixture ' . $prefix);
        portalweave_sso_assert(empty($upload['error']), 'Upload fixture failed.');
        $attachment = wp_insert_attachment(['post_title' => $prefix, 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
        portalweave_sso_assert(!is_wp_error($attachment) && $attachment > 0, 'Attachment fixture failed.');
        $posts[] = $attachment;
        $thumbnail = dirname($upload['file']) . '/' . $prefix . '-small.txt';
        file_put_contents($thumbnail, 'Private thumbnail fixture');
        wp_update_attachment_metadata($attachment, ['file' => basename($upload['file']), 'width' => 400, 'height' => 300,
            'sizes' => ['thumbnail' => ['file' => basename($thumbnail), 'width' => 100, 'height' => 75, 'mime-type' => 'text/plain']]]);
        $hash = hash_file('sha256', $upload['file']);
        $result = \PortalWeaveSSO\Access::protect_media($attachment);
        portalweave_sso_assert($result === true, is_wp_error($result) ? $result->get_error_message() : 'Protection failed.');
        update_post_meta($attachment, '_portalweave_sso_protected', 1);
        portalweave_sso_assert(!file_exists($upload['file']) && !file_exists($thumbnail), 'Public original or thumbnail remains readable after protection.');
        $private = get_attached_file($attachment);
        portalweave_sso_assert(is_file($private) && hash_file('sha256', $private) === $hash, 'Private original is missing or changed.');
        $url = wp_get_attachment_url($attachment);
        portalweave_sso_assert(str_contains($url, 'portalweave_sso_action=media') && str_contains($url, 'attachment_id=' . $attachment), 'Protected URL bypasses the authenticated delivery route.');
        portalweave_sso_assert(!\PortalWeaveSSO\Access::can_post($attachment, new WP_User(0)), 'Anonymous user passed protected media policy.');
        $image = \PortalWeaveSSO\Access::image_size(false, $attachment, 'thumbnail');
        portalweave_sso_assert(is_array($image) && str_contains($image[0], 'file=' . rawurlencode(basename($thumbnail))), 'Thumbnail URL was not protected.');
        $restored = \PortalWeaveSSO\Access::unprotect_media($attachment);
        portalweave_sso_assert($restored === true && is_file($upload['file']) && is_file($thumbnail), 'Private media did not restore to original public paths.');
        update_post_meta($attachment, '_portalweave_sso_protected', 0);
        portalweave_sso_assert(hash_file('sha256', $upload['file']) === $hash && !get_post_meta($attachment, '_portalweave_sso_private_files', true), 'Restoration changed bytes or retained private metadata.');
    });
});

portalweave_sso_test('core: rejected settings cannot restore protected media to a public URL', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $upload = wp_upload_bits($prefix . '-policy.txt', null, 'Protected policy fixture');
        portalweave_sso_assert(empty($upload['error']), 'Policy upload fixture failed.');
        $attachment = wp_insert_attachment(['post_title' => $prefix, 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
        portalweave_sso_assert(!is_wp_error($attachment) && $attachment > 0, 'Policy attachment fixture failed.');
        $posts[] = $attachment;
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['protected_media' => [$attachment]]) === true, 'Config media protection failed.');
        portalweave_sso_assert(!file_exists($upload['file']), 'Configured protection left public bytes accessible.');
        $result = \PortalWeaveSSO\Config::save(['protected_media' => [], 'shared_cookie_domain' => 'invalid']);
        portalweave_sso_error($result, 'portalweave_sso_shared_domain');
        portalweave_sso_assert(in_array($attachment, \PortalWeaveSSO\Config::get('protected_media'), true), 'Rejected policy lost its media restriction.');
        portalweave_sso_assert(!file_exists($upload['file']), 'Rejected settings restored protected bytes to a public static URL.');
    });
});

portalweave_sso_test('core: protection retries remove unchanged public leftovers and preserve replacement bytes', function (): void {
    portalweave_sso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $bytes = 'Protected retry fixture ' . $prefix;
        $upload = wp_upload_bits($prefix . '-retry.txt', null, $bytes);
        portalweave_sso_assert(empty($upload['error']), 'Retry upload fixture failed.');
        $attachment = wp_insert_attachment(['post_title' => $prefix, 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
        portalweave_sso_assert(!is_wp_error($attachment) && $attachment > 0, 'Retry attachment fixture failed.');
        $posts[] = $attachment;
        portalweave_sso_assert(\PortalWeaveSSO\Access::protect_media($attachment) === true, 'Initial protection failed.');
        file_put_contents($upload['file'], $bytes);
        portalweave_sso_assert(\PortalWeaveSSO\Access::protect_media($attachment) === true && !file_exists($upload['file']), 'Protection retry reported success while unchanged public bytes remained.');
        file_put_contents($upload['file'], 'New public replacement bytes');
        $result = \PortalWeaveSSO\Access::protect_media($attachment);
        portalweave_sso_assert(is_wp_error($result), 'Protection silently accepted a different public replacement.');
        portalweave_sso_assert(file_get_contents($upload['file']) === 'New public replacement bytes', 'Protection retry deleted unrelated replacement bytes.');
        $private = (array) get_post_meta($attachment, '_portalweave_sso_private_files', true);
        portalweave_sso_assert(file_get_contents($private[basename($upload['file'])]) === $bytes, 'Protection retry changed the private original.');
        // Remove the replacement fixture so standard attachment cleanup can delete the private original.
        unlink($upload['file']);
    });
});

portalweave_sso_test('core: admin navigation renders one task screen and keeps provider editing separate', function (): void {
    portalweave_sso_core_fixture(function (): void {
        $admin = get_user_by('login', 'portalweave_sso_test_admin');
        portalweave_sso_assert($admin instanceof WP_User, 'Test administrator fixture is missing.');
        wp_set_current_user($admin->ID);
        $old_get = $_GET;
        try {
            foreach (['overview' => '', 'providers' => '', 'login' => 'settings[default_app]', 'access' => 'settings[allowed_domains]', 'appearance' => 'settings[button][theme]', 'modules' => 'settings[scim_token]', 'reports' => '', 'tools' => 'settings[support][email]'] as $screen => $field) {
                $_GET = ['page' => 'portalweave-sso', 'tab' => $screen];
                ob_start();
                \PortalWeaveSSO\Admin::page();
                $html = ob_get_clean();
                portalweave_sso_assert(substr_count($html, 'aria-current="page"') === 1, 'Navigation must identify exactly one active screen');
                foreach (['overview', 'providers', 'login', 'access', 'appearance', 'modules', 'reports', 'tools'] as $destination) {
                    portalweave_sso_assert(str_contains($html, 'tab=' . $destination), 'Missing navigable screen: ' . $destination);
                }
                portalweave_sso_assert(!str_contains($html, 'settings[new_app]'), 'A hidden new-provider form was rendered on an unrelated screen');
                if ($field !== '') {
                    portalweave_sso_assert(str_contains($html, 'name="' . $field . '"'), 'The current screen is missing its settings');
                    portalweave_sso_assert(str_contains($html, 'name="portalweave_sso_complete" value="1"'), 'The form lacks truncation protection');
                } else {
                    portalweave_sso_assert(!str_contains($html, 'class="portalweave-sso-settings-form"'), 'Read-only navigation screens must not render a settings form');
                }
                if ($screen !== 'reports') {
                    portalweave_sso_assert(!str_contains($html, 'Export audit CSV'), 'Audit data should only render on Activity');
                } else {
                    portalweave_sso_assert(str_contains($html, 'data-activity-overview') && !str_contains($html, 'id="portalweave-sso-reports"')
                        && !str_contains($html, 'Export audit CSV'), 'Default Activity must show aggregate graphs instead of the raw audit table');
                }
                if ($screen !== 'appearance') {
                    portalweave_sso_assert(!str_contains($html, 'name="settings[button][theme]"'), 'Appearance fields leaked into another task');
                }
            }
            $_GET = ['page' => 'portalweave-sso', 'tab' => 'providers', 'new_provider' => '1'];
            ob_start();
            \PortalWeaveSSO\Admin::page();
            $html = ob_get_clean();
            portalweave_sso_assert(str_contains($html, 'portalweave-sso-provider-options') && str_contains($html, 'setup_provider=authentik') && str_contains($html, 'setup_provider=discord'), 'New providers must start with the guided provider choice');
            portalweave_sso_assert(!str_contains($html, 'name="settings[default_app]"'), 'Provider editing rendered site-wide login settings');
            portalweave_sso_assert(\PortalWeaveSSO\Config::save(['apps' => ['fixture' => portalweave_sso_core_app(['id' => 'fixture'])]]) === true, 'Provider editor fixture failed');
            $_GET = ['page' => 'portalweave-sso', 'tab' => 'providers', 'provider' => 'fixture'];
            ob_start();
            \PortalWeaveSSO\Admin::page();
            $html = ob_get_clean();
            portalweave_sso_assert(str_contains($html, 'settings[apps][fixture][backchannel_logout]'), 'The advanced editor lost its back-channel setting');
            $_GET = ['page' => 'portalweave-sso', 'tab' => ['malformed']];
            ob_start();
            \PortalWeaveSSO\Admin::page();
            $html = ob_get_clean();
            portalweave_sso_assert(str_contains($html, 'Your sign-in workspace'), 'Invalid navigation must safely fall back to Overview');
        } finally {
            $_GET = $old_get;
        }
    });
});
