<?php
/** WordPress.org compliance regressions at the filesystem and administrative API boundaries. */

portalweave_sso_test('compliance: a WordPress file-deletion veto cannot report public media as protected', function (): void {
    $bytes = 'Private media deletion veto ' . bin2hex(random_bytes(8));
    $upload = wp_upload_bits('portalweave-sso-compliance-' . bin2hex(random_bytes(8)) . '.txt', null, $bytes);
    portalweave_sso_assert(empty($upload['error']), 'Unable to create attachment fixture.');
    $id = wp_insert_attachment(['post_title' => 'SSO deletion veto fixture', 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
    portalweave_sso_assert(!is_wp_error($id) && $id > 0, 'Unable to create attachment record.');
    $veto = static fn($path) => $path === $upload['file'] ? '' : $path;
    try {
        add_filter('wp_delete_file', $veto);
        portalweave_sso_error(\PortalWeaveSSO\Access::protect_media($id), 'portalweave_sso_media_unlink');
        portalweave_sso_assert(is_file($upload['file']), 'Deletion veto unexpectedly removed public bytes.');
        $private = get_post_meta($id, '_portalweave_sso_private_files', true);
        portalweave_sso_assert(is_array($private) && file_get_contents($private[basename($upload['file'])]) === $bytes, 'Failed protection lost its private recovery copy.');
        // A retry must not mistake a void wp_delete_file() return for successful deletion either.
        portalweave_sso_error(\PortalWeaveSSO\Access::protect_media($id), 'portalweave_sso_media_unlink');
        remove_filter('wp_delete_file', $veto);
        portalweave_sso_assert(\PortalWeaveSSO\Access::protect_media($id) === true && !file_exists($upload['file']), 'Protection did not recover after deletion became available.');
        portalweave_sso_assert(\PortalWeaveSSO\Access::unprotect_media($id) === true, 'Unable to restore attachment fixture.');
        portalweave_sso_assert(file_get_contents($upload['file']) === $bytes, 'Protection retry or restoration changed the original bytes.');
    } finally {
        remove_filter('wp_delete_file', $veto);
        wp_delete_attachment($id, true);
    }
});

portalweave_sso_test('compliance: CLI backups create a private local file and never replace files or symlinks', function (): void {
    $directory = get_temp_dir() . 'portalweave-sso-backup-test-' . bin2hex(random_bytes(8));
    portalweave_sso_assert(wp_mkdir_p($directory), 'Unable to create backup fixture directory.');
    $file = $directory . '/backup.json';
    $link = $directory . '/existing-link.json';
    $contents = "{\"backup\":\"redacted\"}\n";
    $original_mask = umask();
    try {
        portalweave_sso_assert(\PortalWeaveSSO\Cli::write_backup($file, $contents) === true, 'Exclusive backup creation failed.');
        portalweave_sso_assert(file_get_contents($file) === $contents, 'Backup bytes were incomplete.');
        portalweave_sso_assert(umask() === $original_mask, 'Backup creation changed the process permission mask.');
        if (PHP_OS_FAMILY !== 'Windows') {
            portalweave_sso_assert((fileperms($file) & 0777) === 0600, 'Backup must only be readable and writable by its owner.');
        }
        portalweave_sso_error(\PortalWeaveSSO\Cli::write_backup($file, 'replacement'), 'portalweave_sso_backup_exists');
        portalweave_sso_assert(file_get_contents($file) === $contents, 'A backup invocation overwrote an existing file.');
        if (function_exists('symlink') && PHP_OS_FAMILY !== 'Windows') {
            portalweave_sso_assert(symlink($directory . '/missing-target', $link), 'Unable to create dangling-symlink fixture.');
            portalweave_sso_error(\PortalWeaveSSO\Cli::write_backup($link, 'replacement'), 'portalweave_sso_backup_exists');
            portalweave_sso_assert(is_link($link) && !file_exists($directory . '/missing-target'), 'Backup followed a pre-existing dangling symlink.');
        }
        portalweave_sso_error(\PortalWeaveSSO\Cli::write_backup('php://output', 'replacement'), 'portalweave_sso_backup_path');
        portalweave_sso_error(\PortalWeaveSSO\Cli::write_backup("bad\0name", 'replacement'), 'portalweave_sso_backup_path');
        portalweave_sso_error(\PortalWeaveSSO\Cli::write_backup($directory . '/absent/backup.json', 'replacement'), 'portalweave_sso_backup_create');
        portalweave_sso_assert(umask() === $original_mask, 'Failed backup creation changed the process permission mask.');
    } finally {
        wp_delete_file($link);
        wp_delete_file($file);
        rmdir($directory);
    }
});

portalweave_sso_test('compliance: malformed SCIM query and PATCH values return errors without PHP coercion', function (): void {
    $request = new WP_REST_Request('GET', '/portalweave-sso/v1/scim/v2/Users');
    $request->set_query_params(['filter' => ['userName eq "example"']]);
    $response = \PortalWeaveSSO\Extensions::scim_collection($request);
    portalweave_sso_assert($response->get_status() === 400 && $response->get_data()['scimType'] === 'invalidFilter', 'SCIM accepted a non-string equality filter.');
    foreach ([['op' => ['replace'], 'path' => 'active', 'value' => false], ['op' => 'replace', 'path' => ['active'], 'value' => false]] as $operation) {
        portalweave_sso_error(\PortalWeaveSSO\Extensions::patch(['userName' => 'example'], ['Operations' => [$operation]]), 'portalweave_sso_scim_patch');
    }
    $secret = bin2hex(random_bytes(32));
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]) === true, 'Unable to configure SCIM fixture.');
    \PortalWeaveSSO\Extensions::routes();
    $request = new WP_REST_Request('POST', '/portalweave-sso/v1/scim/v2/Groups');
    $request->set_header('Authorization', 'Bearer ' . $secret);
    $request->set_header('Content-Type', 'application/scim+json');
    $request->set_body(wp_json_encode(['displayName' => 'Malformed member', 'members' => [['value' => ['1']]]]));
    $response = rest_do_request($request);
    portalweave_sso_assert($response->get_status() === 400, 'SCIM accepted a structured member identifier.');
});

portalweave_sso_test('compliance: direct ability execution validates the declared input schema before changing settings', function (): void {
    $admin = get_user_by('login', 'portalweave_sso_test_admin');
    portalweave_sso_assert($admin instanceof WP_User, 'Test administrator fixture is missing.');
    wp_set_current_user($admin->ID);
    portalweave_sso_assert(\PortalWeaveSSO\Config::save(['abilities_enabled' => true]) === true, 'Unable to enable abilities fixture.');
    $before = get_option('portalweave_sso_settings');
    foreach ([
        ['configure-provider', ['id' => 'invalid<script>', 'settings' => []]],
        ['configure-provider', ['id' => 123, 'settings' => []]],
        ['configure-provider', ['id' => 'fixture', 'settings' => ['a list']]],
        ['configure-provider', ['id' => 'fixture', 'settings' => [], 'unexpected' => true]],
        ['fix-common-error', ['id' => 'fixture', 'fix' => ['enable-pkce']]],
        ['login-audit', ['limit' => 201]],
        ['login-audit', ['limit' => '25']],
        ['login-audit', ['limit' => 25.0]],
        ['support-query', ['subject' => true, 'message' => 'Do not send', 'send' => false]],
        ['support-query', ['subject' => 'A draft', 'message' => 'Do not send', 'send' => 'true']],
    ] as [$ability, $input]) {
        portalweave_sso_assert(is_wp_error(\PortalWeaveSSO\Abilities::execute($ability, $input)), 'Direct ability execution accepted malformed input for ' . $ability);
        portalweave_sso_assert(get_option('portalweave_sso_settings') === $before, 'Invalid ability input changed provider settings.');
    }
    $result = \PortalWeaveSSO\Abilities::execute('list-providers');
    portalweave_sso_assert(is_array($result) && array_key_exists('providers', $result), 'Schema validation rejected an empty optional input object.');
    $draft = \PortalWeaveSSO\Abilities::execute('support-query', ['subject' => 'Strict JSON fixture', 'message' => 'Create a local draft only.', 'send' => false]);
    portalweave_sso_assert(is_array($draft) && $draft['status'] === 'draft', 'Schema validation rejected a real boolean false.');
    delete_transient('portalweave_sso_support_draft_' . $draft['draft']['id']);
});

portalweave_sso_test('compliance: moderation ignores malformed fields even with a valid administrator nonce', function (): void {
    $admin = get_user_by('login', 'portalweave_sso_test_admin');
    portalweave_sso_assert($admin instanceof WP_User, 'Test administrator fixture is missing.');
    $post = $_POST;
    $id = wp_insert_user(['user_login' => 'moderation_input_' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    portalweave_sso_assert(!is_wp_error($id), 'Unable to create moderation fixture.');
    try {
        wp_set_current_user($admin->ID);
        portalweave_sso_assert(\PortalWeaveSSO\Config::save(['modules' => ['moderation' => true]]) === true, 'Unable to enable moderation fixture.');
        update_user_meta($id, '_portalweave_sso_moderation', 'blocked');
        $nonce = wp_create_nonce('portalweave_sso_moderate_' . $id);
        foreach ([['portalweave_sso_moderation_nonce' => [$nonce], 'portalweave_sso_moderation' => 'approved'],
            ['portalweave_sso_moderation_nonce' => $nonce, 'portalweave_sso_moderation' => ['approved']],
            ['portalweave_sso_moderation_nonce' => $nonce]] as $values) {
            $_POST = $values;
            \PortalWeaveSSO\Extensions::moderation_save($id);
            portalweave_sso_assert(get_user_meta($id, '_portalweave_sso_moderation', true) === 'blocked', 'Malformed moderation input cleared the access block.');
        }
        $_POST = ['portalweave_sso_moderation_nonce' => $nonce, 'portalweave_sso_moderation' => 'approved'];
        \PortalWeaveSSO\Extensions::moderation_save($id);
        portalweave_sso_assert(get_user_meta($id, '_portalweave_sso_moderation', true) === 'approved', 'Valid moderation update was rejected.');
    } finally {
        $_POST = $post;
        wp_delete_user($id);
    }
});
