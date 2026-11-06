<?php
/** WordPress.org compliance regressions at the filesystem and administrative API boundaries. */

wpsso_test('compliance: a WordPress file-deletion veto cannot report public media as protected', function (): void {
    $bytes = 'Private media deletion veto ' . bin2hex(random_bytes(8));
    $upload = wp_upload_bits('wpsso-compliance-' . bin2hex(random_bytes(8)) . '.txt', null, $bytes);
    wpsso_assert(empty($upload['error']), 'Unable to create attachment fixture.');
    $id = wp_insert_attachment(['post_title' => 'SSO deletion veto fixture', 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $upload['file']);
    wpsso_assert(!is_wp_error($id) && $id > 0, 'Unable to create attachment record.');
    $veto = static fn($path) => $path === $upload['file'] ? '' : $path;
    try {
        add_filter('wp_delete_file', $veto);
        wpsso_error(\WPSSO\Access::protect_media($id), 'wpsso_media_unlink');
        wpsso_assert(is_file($upload['file']), 'Deletion veto unexpectedly removed public bytes.');
        $private = get_post_meta($id, '_wpsso_private_files', true);
        wpsso_assert(is_array($private) && file_get_contents($private[basename($upload['file'])]) === $bytes, 'Failed protection lost its private recovery copy.');
        // A retry must not mistake a void wp_delete_file() return for successful deletion either.
        wpsso_error(\WPSSO\Access::protect_media($id), 'wpsso_media_unlink');
        remove_filter('wp_delete_file', $veto);
        wpsso_assert(\WPSSO\Access::protect_media($id) === true && !file_exists($upload['file']), 'Protection did not recover after deletion became available.');
        wpsso_assert(\WPSSO\Access::unprotect_media($id) === true, 'Unable to restore attachment fixture.');
        wpsso_assert(file_get_contents($upload['file']) === $bytes, 'Protection retry or restoration changed the original bytes.');
    } finally {
        remove_filter('wp_delete_file', $veto);
        wp_delete_attachment($id, true);
    }
});

wpsso_test('compliance: CLI backups create a private local file and never replace files or symlinks', function (): void {
    $directory = get_temp_dir() . 'wpsso-backup-test-' . bin2hex(random_bytes(8));
    wpsso_assert(wp_mkdir_p($directory), 'Unable to create backup fixture directory.');
    $file = $directory . '/backup.json';
    $link = $directory . '/existing-link.json';
    $contents = "{\"backup\":\"redacted\"}\n";
    $original_mask = umask();
    try {
        wpsso_assert(\WPSSO\Cli::write_backup($file, $contents) === true, 'Exclusive backup creation failed.');
        wpsso_assert(file_get_contents($file) === $contents, 'Backup bytes were incomplete.');
        wpsso_assert(umask() === $original_mask, 'Backup creation changed the process permission mask.');
        if (PHP_OS_FAMILY !== 'Windows') {
            wpsso_assert((fileperms($file) & 0777) === 0600, 'Backup must only be readable and writable by its owner.');
        }
        wpsso_error(\WPSSO\Cli::write_backup($file, 'replacement'), 'wpsso_backup_exists');
        wpsso_assert(file_get_contents($file) === $contents, 'A backup invocation overwrote an existing file.');
        if (function_exists('symlink') && PHP_OS_FAMILY !== 'Windows') {
            wpsso_assert(symlink($directory . '/missing-target', $link), 'Unable to create dangling-symlink fixture.');
            wpsso_error(\WPSSO\Cli::write_backup($link, 'replacement'), 'wpsso_backup_exists');
            wpsso_assert(is_link($link) && !file_exists($directory . '/missing-target'), 'Backup followed a pre-existing dangling symlink.');
        }
        wpsso_error(\WPSSO\Cli::write_backup('php://output', 'replacement'), 'wpsso_backup_path');
        wpsso_error(\WPSSO\Cli::write_backup("bad\0name", 'replacement'), 'wpsso_backup_path');
        wpsso_error(\WPSSO\Cli::write_backup($directory . '/absent/backup.json', 'replacement'), 'wpsso_backup_create');
        wpsso_assert(umask() === $original_mask, 'Failed backup creation changed the process permission mask.');
    } finally {
        wp_delete_file($link);
        wp_delete_file($file);
        rmdir($directory);
    }
});

wpsso_test('compliance: malformed SCIM query and PATCH values return errors without PHP coercion', function (): void {
    $request = new WP_REST_Request('GET', '/wpsso/v1/scim/v2/Users');
    $request->set_query_params(['filter' => ['userName eq "example"']]);
    $response = \WPSSO\Extensions::scim_collection($request);
    wpsso_assert($response->get_status() === 400 && $response->get_data()['scimType'] === 'invalidFilter', 'SCIM accepted a non-string equality filter.');
    foreach ([['op' => ['replace'], 'path' => 'active', 'value' => false], ['op' => 'replace', 'path' => ['active'], 'value' => false]] as $operation) {
        wpsso_error(\WPSSO\Extensions::patch(['userName' => 'example'], ['Operations' => [$operation]]), 'wpsso_scim_patch');
    }
    $secret = bin2hex(random_bytes(32));
    wpsso_assert(\WPSSO\Config::save(['modules' => ['scim' => true], 'scim_token' => $secret]) === true, 'Unable to configure SCIM fixture.');
    \WPSSO\Extensions::routes();
    $request = new WP_REST_Request('POST', '/wpsso/v1/scim/v2/Groups');
    $request->set_header('Authorization', 'Bearer ' . $secret);
    $request->set_header('Content-Type', 'application/scim+json');
    $request->set_body(wp_json_encode(['displayName' => 'Malformed member', 'members' => [['value' => ['1']]]]));
    $response = rest_do_request($request);
    wpsso_assert($response->get_status() === 400, 'SCIM accepted a structured member identifier.');
});

wpsso_test('compliance: direct ability execution validates the declared input schema before changing settings', function (): void {
    $admin = get_user_by('login', 'wpsso_test_admin');
    wpsso_assert($admin instanceof WP_User, 'Test administrator fixture is missing.');
    wp_set_current_user($admin->ID);
    wpsso_assert(\WPSSO\Config::save(['abilities_enabled' => true]) === true, 'Unable to enable abilities fixture.');
    $before = get_option('wpsso_settings');
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
        wpsso_assert(is_wp_error(\WPSSO\Abilities::execute($ability, $input)), 'Direct ability execution accepted malformed input for ' . $ability);
        wpsso_assert(get_option('wpsso_settings') === $before, 'Invalid ability input changed provider settings.');
    }
    $result = \WPSSO\Abilities::execute('list-providers');
    wpsso_assert(is_array($result) && array_key_exists('providers', $result), 'Schema validation rejected an empty optional input object.');
    $draft = \WPSSO\Abilities::execute('support-query', ['subject' => 'Strict JSON fixture', 'message' => 'Create a local draft only.', 'send' => false]);
    wpsso_assert(is_array($draft) && $draft['status'] === 'draft', 'Schema validation rejected a real boolean false.');
    delete_transient('wpsso_support_draft_' . $draft['draft']['id']);
});

wpsso_test('compliance: moderation ignores malformed fields even with a valid administrator nonce', function (): void {
    $admin = get_user_by('login', 'wpsso_test_admin');
    wpsso_assert($admin instanceof WP_User, 'Test administrator fixture is missing.');
    $post = $_POST;
    $id = wp_insert_user(['user_login' => 'moderation_input_' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    wpsso_assert(!is_wp_error($id), 'Unable to create moderation fixture.');
    try {
        wp_set_current_user($admin->ID);
        wpsso_assert(\WPSSO\Config::save(['modules' => ['moderation' => true]]) === true, 'Unable to enable moderation fixture.');
        update_user_meta($id, '_wpsso_moderation', 'blocked');
        $nonce = wp_create_nonce('wpsso_moderate_' . $id);
        foreach ([['wpsso_moderation_nonce' => [$nonce], 'wpsso_moderation' => 'approved'],
            ['wpsso_moderation_nonce' => $nonce, 'wpsso_moderation' => ['approved']],
            ['wpsso_moderation_nonce' => $nonce]] as $values) {
            $_POST = $values;
            \WPSSO\Extensions::moderation_save($id);
            wpsso_assert(get_user_meta($id, '_wpsso_moderation', true) === 'blocked', 'Malformed moderation input cleared the access block.');
        }
        $_POST = ['wpsso_moderation_nonce' => $nonce, 'wpsso_moderation' => 'approved'];
        \WPSSO\Extensions::moderation_save($id);
        wpsso_assert(get_user_meta($id, '_wpsso_moderation', true) === 'approved', 'Valid moderation update was rejected.');
    } finally {
        $_POST = $post;
        wp_delete_user($id);
    }
});
