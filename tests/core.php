<?php
/** Real-WordPress security and persistence checks for configuration, accounts, and access. */

function wpsso_core_fixture(callable $test): void {
    $stored = get_option('wpsso_settings', null);
    $current = get_current_user_id();
    $users = [];
    $posts = [];
    $terms = [];
    $prefix = 'wpsso_' . strtolower(wp_generate_password(12, false, false));
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    try {
        update_option('wpsso_settings', \WPSSO\Config::defaults(), false);
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
            delete_option('wpsso_settings');
        } else {
            update_option('wpsso_settings', $stored, false);
        }
    }
}

function wpsso_core_app(array $overrides = []): array {
    return array_replace(\WPSSO\Config::app_defaults(), [
        'id' => 'core-test', 'name' => 'Core test', 'client_id' => 'client-core-test',
        'authorize_url' => 'https://idp.example.test/authorize', 'token_url' => 'https://idp.example.test/token',
        'userinfo_url' => 'https://idp.example.test/userinfo', 'issuer' => 'https://idp.example.test',
        'jwks_url' => 'https://idp.example.test/jwks',
    ], $overrides);
}

function wpsso_core_attributes(string $prefix, array $overrides = []): array {
    return array_replace([
        'sub' => $prefix . '-subject', 'email' => $prefix . '@example.test', 'email_verified' => true,
        'preferred_username' => $prefix, 'given_name' => 'Provider', 'family_name' => 'Person',
        'name' => 'Provider Person', 'groups' => [],
    ], $overrides);
}

function wpsso_core_local(array &$users, string $prefix, string $role = 'subscriber'): WP_User {
    $id = wp_insert_user(['user_login' => $prefix, 'user_email' => $prefix . '@example.test',
        'user_pass' => wp_generate_password(48, true, true), 'role' => $role,
        'first_name' => 'Local', 'last_name' => 'Person', 'display_name' => 'Local Person']);
    wpsso_assert(!is_wp_error($id), 'Local fixture user could not be created.');
    $users[] = $id;
    return get_userdata($id);
}

wpsso_test('core: secure defaults keep admin SSO, automation and legacy flows off', function (): void {
    $defaults = \WPSSO\Config::defaults();
    $app = \WPSSO\Config::app_defaults();
    wpsso_assert(!$defaults['abilities_enabled'] && !array_filter($defaults['modules']), 'Opt-in modules must start disabled.');
    wpsso_assert(!$app['allow_admin'] && !$app['legacy_grants'] && !$app['link_existing'], 'Sensitive account policies must start disabled.');
    wpsso_assert($app['pkce'] && $app['grant'] === 'authorization_code', 'Code with PKCE must be the default flow.');
});

wpsso_test('core: credentials encrypt at rest, preserve blank edits, and clear only explicitly', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $secret = $prefix . '-client-secret';
        $app = wpsso_core_app(['client_secret' => $secret, 'jwt_secret' => str_repeat('h', 40)]);
        $saved = \WPSSO\Config::save(['apps' => [$app['id'] => $app], 'scim_token' => str_repeat('s', 40)]);
        wpsso_assert($saved === true, 'A valid provider should save.');
        $raw = get_option('wpsso_settings');
        wpsso_assert(str_starts_with($raw['apps'][$app['id']]['client_secret'], 'enc:v1:'), 'Client secret is not authenticated ciphertext.');
        wpsso_assert(!str_contains(wp_json_encode($raw), $secret), 'Client secret leaked in persisted settings.');
        wpsso_assert(\WPSSO\Config::app($app['id'])['client_secret'] === $secret, 'Credential round-trip changed the secret.');
        $result = \WPSSO\Config::save(['apps' => [$app['id'] => ['client_secret' => '', 'name' => 'Renamed']]]);
        wpsso_assert($result === true && \WPSSO\Config::app($app['id'])['client_secret'] === $secret, 'A blank form edit must retain the credential.');
        $result = \WPSSO\Config::save(['apps' => [$app['id'] => ['clear_client_secret' => true]], 'clear_scim_token' => true]);
        wpsso_assert($result === true && \WPSSO\Config::app($app['id'])['client_secret'] === '' && \WPSSO\Config::get('scim_token') === '', 'Explicit clear controls did not remove credentials.');
    });
});

wpsso_test('core: support configuration strips client IDs, credentials, endpoint queries and custom state', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $app = wpsso_core_app(['client_id' => $prefix . '-client-id', 'client_secret' => $prefix . '-secret',
            'authorize_url' => 'https://idp.example.test/authorize?client_id=must-not-export', 'state_parameter' => $prefix . '-private-state']);
        wpsso_assert(\WPSSO\Config::save(['apps' => [$app['id'] => $app], 'scim_token' => str_repeat('x', 40)]) === true, 'Provider fixture failed to save.');
        $export = wp_json_encode(\WPSSO\Config::redacted());
        foreach ([$prefix . '-client-id', $prefix . '-secret', $prefix . '-private-state', 'must-not-export', str_repeat('x', 40)] as $sensitive) {
            wpsso_assert(!str_contains($export, $sensitive), 'Redacted export leaked sensitive configuration.');
        }
    });
});

wpsso_test('core: redacted backups retain local credentials and restore incomplete apps safely on clean installs', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $secret = $prefix . '-restore-secret';
        $app = wpsso_core_app(['client_id' => $prefix . '-restore-client', 'client_secret' => $secret]);
        wpsso_assert(\WPSSO\Config::save(['apps' => [$app['id'] => $app], 'default_app' => $app['id'], 'force_auth' => true, 'disable_wp_login' => true]) === true, 'Backup source fixture failed.');
        $backup = \WPSSO\Config::redacted();
        wpsso_assert(\WPSSO\Config::import($backup) === true, 'Same-site redacted restore failed.');
        $restored = \WPSSO\Config::app($app['id']);
        wpsso_assert($restored['client_id'] === $app['client_id'] && $restored['client_secret'] === $secret && $restored['enabled'], 'Same-site restore lost existing credentials or disabled a complete provider.');
        wpsso_assert(\WPSSO\Config::get('force_auth') === true, 'Same-site restore lost a complete access policy.');
        // The admin backup omits credential fields; the CLI backup includes redacted blank fields.
        unset($backup['apps'][$app['id']]['client_id'], $backup['apps'][$app['id']]['client_secret'], $backup['apps'][$app['id']]['jwt_secret']);
        wpsso_assert(\WPSSO\Config::import($backup) === true && \WPSSO\Config::app($app['id'])['client_secret'] === $secret, 'Omitted-field backup failed to retain the existing credential.');
        update_option('wpsso_settings', \WPSSO\Config::defaults(), false);
        wpsso_assert(\WPSSO\Config::import($backup) === true, 'Credential-free backup could not restore on a clean site.');
        $clean = \WPSSO\Config::app($app['id']);
        wpsso_assert(is_array($clean) && !$clean['enabled'] && $clean['client_id'] === '' && $clean['client_secret'] === '', 'Clean restore enabled an incomplete provider or invented credentials.');
        foreach (['force_auth', 'restrict_site', 'disable_wp_login', 'single_flow'] as $key) {
            wpsso_assert(\WPSSO\Config::get($key) === false, 'Clean restore enabled login enforcement without a complete provider: ' . $key);
        }
    });
});

wpsso_test('core: endpoint, callback, algorithm, PKCE and privileged-meta policies fail closed', function (): void {
    wpsso_core_fixture(function (): void {
        $cases = [
            [['token_url' => 'http://idp.example.test/token'], 'wpsso_endpoint'],
            [['callback_url' => 'https://other.example.test/callback'], 'wpsso_callback_origin'],
            [['algorithms' => ['none']], 'wpsso_algorithm'],
            [['algorithms' => ['RS256', 'HS256']], 'wpsso_algorithm_family'],
            [['credential_method' => 'none', 'pkce' => false], 'wpsso_pkce'],
            [['grant' => 'password', 'legacy_grants' => false], 'wpsso_legacy_grant'],
            [['custom_mapping' => ['wp_capabilities' => 'roles']], 'wpsso_meta'],
            [['custom_mapping' => ['session_tokens' => 'session']], 'wpsso_meta'],
        ];
        foreach ($cases as [$overrides, $code]) {
            $app = wpsso_core_app($overrides);
            wpsso_error(\WPSSO\Config::save(['apps' => [$app['id'] => $app]]), $code);
        }
        wpsso_error(\WPSSO\Config::save(['force_auth' => true]), 'wpsso_lockout');
        wpsso_assert(\WPSSO\Config::get('force_auth') === false, 'A rejected policy write mutated saved settings.');
    });
});

wpsso_test('core: redirect allowlists enforce exact origins, path boundaries and normalization', function (): void {
    wpsso_core_fixture(function (): void {
        wpsso_assert(\WPSSO\Config::save(['redirect_allowlist' => ['https://portal.example.test/members']]) === true, 'Allowlist fixture failed.');
        $fallback = home_url('/safe-fallback');
        wpsso_assert(\WPSSO\Redirect::validate('https://portal.example.test/members/profile?tab=1', $fallback) === 'https://portal.example.test/members/profile?tab=1', 'Allowed path was rejected.');
        wpsso_assert(\WPSSO\Redirect::validate(home_url('/inside'), $fallback) === home_url('/inside'), 'Same-origin URL was rejected.');
        foreach (['https://portal.example.test/membership', 'https://portal.example.test.evil.test/members', 'https://portal.example.test:444/members',
            'https://portal.example.test/members/%2e%2e/private', 'https://portal.example.test/members/%252e%252e/private',
            'https://portal.example.test/members/%5cprivate', '//evil.example.test/path', 'javascript:alert(1)',
            'https://portal.example.test/members/%0d%0aLocation:evil', 'https://user@portal.example.test/members'] as $url) {
            wpsso_assert(\WPSSO\Redirect::validate($url, $fallback) === $fallback, 'Untrusted redirect passed: ' . $url);
        }
    });
});

wpsso_test('core: verified first login creates an account and immutable subject reuses it', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $app = wpsso_core_app(['custom_mapping' => ['department' => 'org.department']]);
        $attributes = wpsso_core_attributes($prefix, ['org' => ['department' => 'Engineering']]);
        $user = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($user instanceof WP_User, 'Verified provider login did not create a user.');
        $users[] = $user->ID;
        wpsso_assert($user->user_email === $attributes['email'] && in_array('subscriber', $user->roles, true), 'Created account has incorrect email or role.');
        wpsso_assert(get_user_meta($user->ID, 'department', true) === 'Engineering', 'Custom nested attribute was not saved.');
        $again = \WPSSO\Accounts::resolve($app, array_replace($attributes, ['preferred_username' => $prefix . '_renamed', 'email' => $prefix . '-new@example.test']));
        wpsso_assert($again instanceof WP_User && $again->ID === $user->ID, 'The immutable provider identity created another account.');
        wpsso_assert($again->user_login === $user->user_login, 'Provider profile changes renamed the immutable WP login.');
    });
});

wpsso_test('core: OIDC always binds the signed subject while OAuth honors its configured identifier', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $app = wpsso_core_app(['subject_attribute' => 'mutable_alias']);
        $attributes = wpsso_core_attributes($prefix, ['mutable_alias' => 'first-alias']);
        $user = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($user instanceof WP_User, 'OIDC fixture failed.');
        $users[] = $user->ID;
        $again = \WPSSO\Accounts::resolve($app, array_replace($attributes, ['mutable_alias' => 'changed-alias']));
        wpsso_assert($again instanceof WP_User && $again->ID === $user->ID, 'A mutable OIDC alias replaced the signed subject binding.');
        $missing_sub = $attributes;
        unset($missing_sub['sub']);
        wpsso_error(\WPSSO\Accounts::resolve($app, $missing_sub), 'wpsso_subject');
        $oauth = wpsso_core_app(['id' => 'core-oauth', 'protocol' => 'oauth2', 'subject_attribute' => 'provider_id']);
        $oauth_attributes = wpsso_core_attributes($prefix . '_oauth', ['provider_id' => 'immutable-oauth-id']);
        unset($oauth_attributes['sub']);
        $oauth_user = \WPSSO\Accounts::resolve($oauth, $oauth_attributes);
        wpsso_assert($oauth_user instanceof WP_User, 'OAuth did not honor its configured immutable identifier.');
        $users[] = $oauth_user->ID;
    });
});

wpsso_test('core: existing-account email linking is opt-in and requires verified mailbox ownership', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $local = wpsso_core_local($users, $prefix);
        $attributes = wpsso_core_attributes($prefix);
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_core_app(), $attributes), 'wpsso_link_required');
        $app = wpsso_core_app(['link_existing' => true, 'require_verified_email' => false]);
        wpsso_error(\WPSSO\Accounts::resolve($app, array_replace($attributes, ['email_verified' => false])), 'wpsso_link_verification');
        $linked = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($linked instanceof WP_User && $linked->ID === $local->ID, 'Verified email did not link the existing account.');
        wpsso_error(\WPSSO\Accounts::resolve($app, array_replace($attributes, ['sub' => 'other-provider-identity'])), 'wpsso_identity_conflict');
    });
});

wpsso_test('core: registration-disabled, missing subjects and unverified claims reject before creation', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = wpsso_core_attributes($prefix);
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_core_app(['auto_register' => false]), $attributes), 'wpsso_registration_disabled');
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_core_app(), array_replace($attributes, ['sub' => []])), 'wpsso_subject');
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_core_app(), array_replace($attributes, ['email_verified' => false])), 'wpsso_unverified');
        wpsso_assert(!get_user_by('email', $attributes['email']), 'A rejected identity created a local account.');
    });
});

wpsso_test('core: administrators require per-provider enablement and a verified configured claim', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $admin = wpsso_core_local($users, $prefix, 'administrator');
        $attributes = wpsso_core_attributes($prefix, ['account' => ['status' => 'approved']]);
        $app = wpsso_core_app(['link_existing' => true, 'verified_attribute' => 'account.status', 'verified_value' => 'approved']);
        wpsso_error(\WPSSO\Accounts::resolve($app, $attributes), 'wpsso_admin_disabled');
        $app['allow_admin'] = true;
        $user = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($user instanceof WP_User && $user->ID === $admin->ID && $user->has_cap('manage_options'), 'Approved admin lost its account or roles.');
        $app['require_verified_email'] = false;
        wpsso_error(\WPSSO\Accounts::resolve($app, array_replace($attributes, ['account' => ['status' => 'pending']])), 'wpsso_admin_disabled');
    });
});

wpsso_test('core: group role mapping blocks implicit privilege escalation', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = wpsso_core_attributes($prefix, ['groups' => ['admins']]);
        $app = wpsso_core_app(['role_mapping' => ['admins' => ['administrator']]]);
        wpsso_error(\WPSSO\Accounts::resolve($app, $attributes), 'wpsso_privileged_role');
        wpsso_assert(!get_user_by('email', $attributes['email']), 'Rejected privileged mapping still created an account.');
    });
});

wpsso_test('core: group and domain mappings assign roles and enforce explicit-match policy', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = wpsso_core_attributes($prefix, ['groups' => ['editors', 'writers']]);
        $app = wpsso_core_app(['role_mapping' => ['editors' => ['editor'], 'writers' => ['author']], 'block_unmapped' => true]);
        $user = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($user instanceof WP_User, 'Mapped role login failed.');
        $users[] = $user->ID;
        wpsso_assert(in_array('editor', $user->roles, true) && in_array('author', $user->roles, true), 'Multiple mapped roles were not applied.');
        wpsso_assert(get_user_meta($user->ID, '_wpsso_groups', true) === ['editors', 'writers'], 'Provider groups were not persisted.');
        $other = wpsso_core_attributes($prefix . '_other');
        wpsso_error(\WPSSO\Accounts::resolve($app, $other), 'wpsso_role_unmapped');
        $app['domain_roles'] = ['example.test' => ['contributor']];
        $domain_user = \WPSSO\Accounts::resolve($app, $other);
        wpsso_assert($domain_user instanceof WP_User, 'Domain mapping did not satisfy explicit mapping.');
        $users[] = $domain_user->ID;
        wpsso_assert(in_array('contributor', $domain_user->roles, true), 'Domain role was not applied.');
    });
});

wpsso_test('core: keep-existing attributes, email and roles preserve local data', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $local = wpsso_core_local($users, $prefix, 'editor');
        update_user_meta($local->ID, 'department', 'Local department');
        $app = wpsso_core_app(['link_existing' => true, 'keep_attributes' => true, 'keep_email' => true,
            'keep_roles' => true, 'role_mapping' => ['readers' => ['subscriber']], 'custom_mapping' => ['department' => 'department']]);
        $attributes = wpsso_core_attributes($prefix, ['department' => 'Provider department', 'groups' => ['readers']]);
        $first = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($first instanceof WP_User && $first->ID === $local->ID, 'Existing account link failed.');
        $second = \WPSSO\Accounts::resolve($app, array_replace($attributes, ['email' => $prefix . '-updated@example.test', 'name' => 'Changed provider name']));
        wpsso_assert($second instanceof WP_User && $second->user_email === $local->user_email && $second->display_name === 'Local Person', 'Keep-existing policy overwrote local profile data.');
        wpsso_assert(in_array('editor', $second->roles, true) && !in_array('subscriber', $second->roles, true), 'Keep-existing roles were replaced.');
        wpsso_assert(get_user_meta($second->ID, 'department', true) === 'Local department', 'Keep-existing custom attributes were overwritten.');
    });
});

wpsso_test('core: duplicate emails require opt-in and remain separate subject identities', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $local = wpsso_core_local($users, $prefix);
        $attributes = wpsso_core_attributes($prefix);
        $app = wpsso_core_app(['allow_duplicate_emails' => true]);
        $user = \WPSSO\Accounts::resolve($app, $attributes);
        wpsso_assert($user instanceof WP_User, 'Opt-in duplicate-email creation failed.');
        $users[] = $user->ID;
        wpsso_assert($user->ID !== $local->ID && $user->user_email === $local->user_email, 'Duplicate-email policy linked the wrong account or lost the email.');
        $app['link_existing'] = true;
        wpsso_error(\WPSSO\Accounts::resolve($app, array_replace($attributes, ['sub' => $prefix . '-other'])), 'wpsso_link_verification');
    });
});

wpsso_test('core: domain allow/block policy and domain rewrites cannot take over existing mailboxes', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $attributes = wpsso_core_attributes($prefix);
        wpsso_assert(\WPSSO\Config::save(['allowed_domains' => ['allowed.test']]) === true, 'Allowed domain fixture failed.');
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_core_app(), $attributes), 'wpsso_domain_blocked');
        wpsso_assert(\WPSSO\Config::save(['allowed_domains' => [], 'blocked_domains' => ['example.test']]) === true, 'Blocked domain fixture failed.');
        wpsso_error(\WPSSO\Accounts::resolve(wpsso_core_app(), $attributes), 'wpsso_domain_blocked');
        wpsso_assert(\WPSSO\Config::save(['blocked_domains' => []]) === true, 'Clearing domain fixture failed.');
        wpsso_core_local($users, $prefix);
        $app = wpsso_core_app(['link_existing' => true, 'domain_mapping' => ['old.test' => 'example.test']]);
        wpsso_error(\WPSSO\Accounts::resolve($app, array_replace($attributes, ['email' => $prefix . '@old.test'])), 'wpsso_link_verification');
    });
});

wpsso_test('core: role/group access is enforced for anonymous, local and SCIM-mapped users', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $user = wpsso_core_local($users, $prefix);
        $anonymous = new WP_User(0);
        wpsso_assert(\WPSSO\Access::allows([], $anonymous), 'Empty policy should permit public access.');
        wpsso_assert(!\WPSSO\Access::allows(['login' => true], $anonymous), 'Anonymous user passed a login requirement.');
        wpsso_assert(\WPSSO\Access::allows(['login' => true], $user), 'Logged-in user failed the login requirement.');
        wpsso_assert(!\WPSSO\Access::allows(['roles' => ['editor']], $user), 'Unmatched role was granted access.');
        update_user_meta($user->ID, '_wpsso_groups', ['staff']);
        update_user_meta($user->ID, '_wpsso_scim_groups', ['provisioned']);
        wpsso_assert(\WPSSO\Access::allows(['roles' => ['editor'], 'groups' => ['staff']], $user), 'Matching provider group did not grant access.');
        wpsso_assert(\WPSSO\Access::allows(['groups' => ['provisioned']], $user), 'SCIM group did not grant access.');
    });
});

wpsso_test('core: post and category policies intersect and REST cannot expose restricted content', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $subscriber = wpsso_core_local($users, $prefix);
        $editor = wpsso_core_local($users, $prefix . '_editor', 'editor');
        $term = wp_insert_term($prefix, 'category');
        wpsso_assert(!is_wp_error($term), 'Category fixture failed.');
        $terms[] = $term['term_id'];
        $post = wp_insert_post(['post_title' => $prefix, 'post_content' => 'Private body', 'post_status' => 'publish', 'post_category' => [$term['term_id']]]);
        wpsso_assert($post > 0, 'Post fixture failed.');
        $posts[] = $post;
        update_post_meta($post, '_wpsso_rule', ['login' => true]);
        update_term_meta($term['term_id'], '_wpsso_rule', ['roles' => ['editor']]);
        wpsso_assert(!\WPSSO\Access::can_post($post, new WP_User(0)) && !\WPSSO\Access::can_post($post, $subscriber), 'Post/category policy leaked to an ineligible user.');
        wpsso_assert(\WPSSO\Access::can_post($post, $editor), 'Eligible editor could not access the post.');
        wp_set_current_user(0);
        wpsso_assert(in_array($post, \WPSSO\Access::denied_ids(), true), 'Restricted category post was not excluded from archive queries.');
        $request = new WP_REST_Request('GET', '/wp/v2/posts/' . $post);
        $request->set_param('id', $post);
        wpsso_error(\WPSSO\Access::rest_content(null, rest_get_server(), $request), 'wpsso_content_denied');
        wp_set_current_user($editor->ID);
        wpsso_assert(\WPSSO\Access::rest_content(null, rest_get_server(), $request) === null, 'REST blocked an eligible editor.');
    });
});

wpsso_test('core: protected attachments leave public uploads, rewrite URLs, and safely restore', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $upload = wp_upload_bits($prefix . '.txt', null, 'Private media fixture ' . $prefix);
        wpsso_assert(empty($upload['error']), 'Upload fixture failed.');
        $attachment = wp_insert_attachment(['post_title' => $prefix, 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
        wpsso_assert(!is_wp_error($attachment) && $attachment > 0, 'Attachment fixture failed.');
        $posts[] = $attachment;
        $thumbnail = dirname($upload['file']) . '/' . $prefix . '-small.txt';
        file_put_contents($thumbnail, 'Private thumbnail fixture');
        wp_update_attachment_metadata($attachment, ['file' => basename($upload['file']), 'width' => 400, 'height' => 300,
            'sizes' => ['thumbnail' => ['file' => basename($thumbnail), 'width' => 100, 'height' => 75, 'mime-type' => 'text/plain']]]);
        $hash = hash_file('sha256', $upload['file']);
        $result = \WPSSO\Access::protect_media($attachment);
        wpsso_assert($result === true, is_wp_error($result) ? $result->get_error_message() : 'Protection failed.');
        update_post_meta($attachment, '_wpsso_protected', 1);
        wpsso_assert(!file_exists($upload['file']) && !file_exists($thumbnail), 'Public original or thumbnail remains readable after protection.');
        $private = get_attached_file($attachment);
        wpsso_assert(is_file($private) && hash_file('sha256', $private) === $hash, 'Private original is missing or changed.');
        $url = wp_get_attachment_url($attachment);
        wpsso_assert(str_contains($url, 'wpsso_action=media') && str_contains($url, 'attachment_id=' . $attachment), 'Protected URL bypasses the authenticated delivery route.');
        wpsso_assert(!\WPSSO\Access::can_post($attachment, new WP_User(0)), 'Anonymous user passed protected media policy.');
        $image = \WPSSO\Access::image_size(false, $attachment, 'thumbnail');
        wpsso_assert(is_array($image) && str_contains($image[0], 'file=' . rawurlencode(basename($thumbnail))), 'Thumbnail URL was not protected.');
        $restored = \WPSSO\Access::unprotect_media($attachment);
        wpsso_assert($restored === true && is_file($upload['file']) && is_file($thumbnail), 'Private media did not restore to original public paths.');
        update_post_meta($attachment, '_wpsso_protected', 0);
        wpsso_assert(hash_file('sha256', $upload['file']) === $hash && !get_post_meta($attachment, '_wpsso_private_files', true), 'Restoration changed bytes or retained private metadata.');
    });
});

wpsso_test('core: rejected settings cannot restore protected media to a public URL', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $upload = wp_upload_bits($prefix . '-policy.txt', null, 'Protected policy fixture');
        wpsso_assert(empty($upload['error']), 'Policy upload fixture failed.');
        $attachment = wp_insert_attachment(['post_title' => $prefix, 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
        wpsso_assert(!is_wp_error($attachment) && $attachment > 0, 'Policy attachment fixture failed.');
        $posts[] = $attachment;
        wpsso_assert(\WPSSO\Config::save(['protected_media' => [$attachment]]) === true, 'Config media protection failed.');
        wpsso_assert(!file_exists($upload['file']), 'Configured protection left public bytes accessible.');
        $result = \WPSSO\Config::save(['protected_media' => [], 'shared_cookie_domain' => 'invalid']);
        wpsso_error($result, 'wpsso_shared_domain');
        wpsso_assert(in_array($attachment, \WPSSO\Config::get('protected_media'), true), 'Rejected policy lost its media restriction.');
        wpsso_assert(!file_exists($upload['file']), 'Rejected settings restored protected bytes to a public static URL.');
    });
});

wpsso_test('core: protection retries remove unchanged public leftovers and preserve replacement bytes', function (): void {
    wpsso_core_fixture(function (&$users, &$posts, &$terms, $prefix): void {
        $bytes = 'Protected retry fixture ' . $prefix;
        $upload = wp_upload_bits($prefix . '-retry.txt', null, $bytes);
        wpsso_assert(empty($upload['error']), 'Retry upload fixture failed.');
        $attachment = wp_insert_attachment(['post_title' => $prefix, 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
        wpsso_assert(!is_wp_error($attachment) && $attachment > 0, 'Retry attachment fixture failed.');
        $posts[] = $attachment;
        wpsso_assert(\WPSSO\Access::protect_media($attachment) === true, 'Initial protection failed.');
        file_put_contents($upload['file'], $bytes);
        wpsso_assert(\WPSSO\Access::protect_media($attachment) === true && !file_exists($upload['file']), 'Protection retry reported success while unchanged public bytes remained.');
        file_put_contents($upload['file'], 'New public replacement bytes');
        $result = \WPSSO\Access::protect_media($attachment);
        wpsso_assert(is_wp_error($result), 'Protection silently accepted a different public replacement.');
        wpsso_assert(file_get_contents($upload['file']) === 'New public replacement bytes', 'Protection retry deleted unrelated replacement bytes.');
        $private = (array) get_post_meta($attachment, '_wpsso_private_files', true);
        wpsso_assert(file_get_contents($private[basename($upload['file'])]) === $bytes, 'Protection retry changed the private original.');
        // Remove the replacement fixture so standard attachment cleanup can delete the private original.
        unlink($upload['file']);
    });
});

wpsso_test('core: admin navigation renders one task screen and keeps provider editing separate', function (): void {
    wpsso_core_fixture(function (): void {
        $admin = get_user_by('login', 'wpsso_test_admin');
        wpsso_assert($admin instanceof WP_User, 'Test administrator fixture is missing.');
        wp_set_current_user($admin->ID);
        $old_get = $_GET;
        try {
            foreach (['overview' => '', 'providers' => '', 'login' => 'settings[default_app]', 'access' => 'settings[allowed_domains]', 'appearance' => 'settings[button][theme]', 'modules' => 'settings[scim_token]', 'reports' => 'settings[audit_enabled]', 'tools' => 'settings[support][email]'] as $screen => $field) {
                $_GET = ['page' => 'wpsso', 'tab' => $screen];
                ob_start();
                \WPSSO\Admin::page();
                $html = ob_get_clean();
                wpsso_assert(substr_count($html, 'aria-current="page"') === 1, 'Navigation must identify exactly one active screen');
                foreach (['overview', 'providers', 'login', 'access', 'appearance', 'modules', 'reports', 'tools'] as $destination) {
                    wpsso_assert(str_contains($html, 'tab=' . $destination), 'Missing navigable screen: ' . $destination);
                }
                wpsso_assert(!str_contains($html, 'settings[new_app]'), 'A hidden new-provider form was rendered on an unrelated screen');
                if ($field !== '') {
                    wpsso_assert(str_contains($html, 'name="' . $field . '"'), 'The current screen is missing its settings');
                    wpsso_assert(str_contains($html, 'name="wpsso_complete" value="1"'), 'The form lacks truncation protection');
                } else {
                    wpsso_assert(!str_contains($html, 'class="wpsso-settings-form"'), 'Read-only navigation screens must not render a settings form');
                }
                if ($screen !== 'reports') {
                    wpsso_assert(!str_contains($html, 'Export audit CSV'), 'Audit data should only render on Activity');
                }
                if ($screen !== 'appearance') {
                    wpsso_assert(!str_contains($html, 'name="settings[button][theme]"'), 'Appearance fields leaked into another task');
                }
            }
            $_GET = ['page' => 'wpsso', 'tab' => 'providers', 'new_provider' => '1'];
            ob_start();
            \WPSSO\Admin::page();
            $html = ob_get_clean();
            wpsso_assert(str_contains($html, 'settings[new_app][backchannel_logout]'), 'The provider editor lost its back-channel setting');
            wpsso_assert(!str_contains($html, 'name="settings[default_app]"'), 'Provider editing rendered site-wide login settings');
            $_GET = ['page' => 'wpsso', 'tab' => ['malformed']];
            ob_start();
            \WPSSO\Admin::page();
            $html = ob_get_clean();
            wpsso_assert(str_contains($html, 'Your sign-in workspace'), 'Invalid navigation must safely fall back to Overview');
        } finally {
            $_GET = $old_get;
        }
    });
});
