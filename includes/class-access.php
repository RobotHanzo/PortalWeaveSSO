<?php
namespace WPSSO;

defined('ABSPATH') || exit;

final class Access
{
    private static bool $moving = false;

    public static function register(): void
    {
        add_action('init', [self::class, 'media_request'], 3);
        add_action('login_init', [self::class, 'login_gate'], 20);
        add_action('template_redirect', [self::class, 'gate'], 1);
        add_action('pre_get_posts', [self::class, 'filter_query']);
        add_filter('rest_authentication_errors', [self::class, 'rest_gate'], 100);
        add_filter('rest_pre_dispatch', [self::class, 'rest_content'], 20, 3);
        foreach (get_post_types(['show_in_rest' => true]) as $type) {
            add_filter('rest_' . $type . '_query', [self::class, 'rest_query'], 20);
        }
        add_filter('xmlrpc_enabled', static fn($enabled) => (Config::get('force_auth') || Config::get('restrict_site')) ? false : $enabled);
        add_filter('wp_get_attachment_url', [self::class, 'media_url'], 20, 2);
        add_filter('image_downsize', [self::class, 'image_size'], 20, 3);
        add_filter('wp_calculate_image_srcset', [self::class, 'srcset'], 20, 5);
        add_filter('get_attached_file', [self::class, 'attached_file'], 20, 2);
        add_action('added_post_meta', [self::class, 'media_flag'], 20, 4);
        add_action('updated_post_meta', [self::class, 'media_flag'], 20, 4);
        add_action('delete_attachment', [self::class, 'delete_media']);
        add_action('admin_notices', [self::class, 'media_notice']);
    }

    public static function allows(array $rule, ?\WP_User $user = null): bool
    {
        if (!$rule || (empty($rule['login']) && empty($rule['roles']) && empty($rule['groups']))) {
            return true;
        }
        $user = $user ?: wp_get_current_user();
        if (!$user->exists()) {
            return false;
        }
        if (Accounts::is_privileged($user)) {
            return true;
        }
        $roles = Config::list($rule['roles'] ?? []);
        $groups = Config::list($rule['groups'] ?? []);
        if (!$roles && !$groups) {
            return true;
        }
        $user_groups = array_merge((array) get_user_meta($user->ID, '_wpsso_groups', true), (array) get_user_meta($user->ID, '_wpsso_scim_groups', true), (array) get_user_meta($user->ID, '_wpsso_scim_group_names', true));
        return (bool) array_intersect($roles, $user->roles) || (bool) array_intersect($groups, $user_groups);
    }

    public static function can_post(int $id, ?\WP_User $user = null): bool
    {
        $rules = Config::get('content_rules', []);
        $rule = get_post_meta($id, '_wpsso_rule', true);
        if (!self::allows(is_array($rule) ? $rule : [], $user) || !self::allows((array) ($rules['posts'][$id] ?? []), $user)) {
            return false;
        }
        if (get_post_type($id) === 'attachment' && self::is_protected($id)) {
            $user = $user ?: wp_get_current_user();
            if (!$user->exists()) {
                return false;
            }
            $parent = wp_get_post_parent_id($id);
            if ($parent && !self::can_post($parent, $user)) {
                return false;
            }
        }
        $terms = wp_get_post_categories($id);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $rule = get_term_meta($term, '_wpsso_rule', true);
                if (!self::allows(is_array($rule) ? $rule : [], $user) || !self::allows((array) ($rules['categories'][$term] ?? []), $user)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function deny(): void
    {
        nocache_headers();
        if (is_user_logged_in()) {
            wp_die(esc_html__('Your account does not have access to this content.', 'wpsso'), 'SSO access denied', ['response' => 403]);
        }
        $app = Config::get('default_app');
        if ($app && !empty(Config::app($app)['enabled'])) {
            wp_redirect(Auth::login_url($app, Redirect::current()), 302, 'WPSSO');
            exit;
        }
        auth_redirect();
        exit;
    }

    public static function gate(): void
    {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if (!is_user_logged_in() && (Config::get('force_auth') || Config::get('restrict_site'))) {
            self::deny();
        }
        if (is_singular() && !self::can_post((int) get_queried_object_id())) {
            self::deny();
        }
        if (is_category()) {
            $id = get_queried_object_id();
            if (!self::allows((array) get_term_meta($id, '_wpsso_rule', true)) || !self::allows((array) (Config::get('content_rules', [])['categories'][$id] ?? []))) {
                self::deny();
            }
        }
    }

    public static function login_gate(): void
    {
        if (is_user_logged_in()) {
            return;
        }
        $action = sanitize_key($_REQUEST['action'] ?? 'login');
        if (in_array($action, ['logout', 'postpass', 'confirm_admin_email'], true)) {
            return;
        }
        // Emergency recovery is a server-configured switch, never a public URL backdoor.
        if (defined('WPSSO_ALLOW_LOCAL_LOGIN') && WPSSO_ALLOW_LOCAL_LOGIN) {
            return;
        }
        if (Config::get('disable_wp_login') || Config::get('single_flow')) {
            self::deny();
        }
    }

    public static function denied_ids(): array
    {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wpsso_rule','_wpsso_protected')"));
        $configured = Config::get('content_rules', []);
        $ids = array_merge($ids, array_map('intval', array_keys($configured['posts'] ?? [])), array_map('intval', Config::get('protected_media', [])));
        $term_ids = array_map('intval', $wpdb->get_col("SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_wpsso_rule'"));
        $term_ids = array_merge($term_ids, array_map('intval', array_keys($configured['categories'] ?? [])));
        foreach (array_unique($term_ids) as $term_id) {
            $rule = (array) get_term_meta($term_id, '_wpsso_rule', true);
            if (!self::allows($rule) || !self::allows((array) ($configured['categories'][$term_id] ?? []))) {
                $objects = get_objects_in_term($term_id, 'category');
                if (!is_wp_error($objects)) {
                    $ids = array_merge($ids, array_map('intval', $objects));
                }
            }
        }
        return array_values(array_filter(array_unique($ids), static fn($id) => !self::can_post($id)));
    }

    public static function filter_query(\WP_Query $query): void
    {
        if (is_admin() || $query->is_singular()) {
            return;
        }
        $query->set('post__not_in', array_unique(array_merge((array) $query->get('post__not_in'), self::denied_ids())));
    }

    public static function rest_query(array $args): array
    {
        $args['post__not_in'] = array_unique(array_merge((array) ($args['post__not_in'] ?? []), self::denied_ids()));
        return $args;
    }

    public static function rest_gate($error)
    {
        if ($error || is_user_logged_in() || (!Config::get('force_auth') && !Config::get('restrict_site'))) {
            return $error;
        }
        // SCIM authenticates its own high-entropy bearer token in its permission callback.
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? ($_GET['rest_route'] ?? '');
        if (str_starts_with((string) $route, '/wpsso/v1/scim/')) {
            return $error;
        }
        return new \WP_Error('wpsso_login_required', 'Authentication is required.', ['status' => 401]);
    }

    public static function rest_content($result, $server, \WP_REST_Request $request)
    {
        if ($result !== null) {
            return $result;
        }
        if (str_starts_with($request->get_route(), '/wp/v2/') && $request->get_param('id') && !self::can_post((int) $request->get_param('id'))) {
            return new \WP_Error('wpsso_content_denied', 'You do not have access to this content.', ['status' => is_user_logged_in() ? 403 : 401]);
        }
        return $result;
    }

    public static function is_protected(int $id): bool
    {
        return (bool) get_post_meta($id, '_wpsso_protected', true) || in_array($id, array_map('intval', Config::get('protected_media', [])), true);
    }

    private static function vault()
    {
        $dir = defined('WPSSO_PRIVATE_MEDIA_DIR') ? WPSSO_PRIVATE_MEDIA_DIR : dirname(rtrim(ABSPATH, '/\\')) . '/wpsso-private-' . substr(hash('sha256', wp_salt()), 0, 12);
        if (!wp_mkdir_p($dir)) {
            return new \WP_Error('wpsso_media_vault', 'The private media directory is not writable.');
        }
        $real = wp_normalize_path(realpath($dir));
        $public = wp_normalize_path(realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ABSPATH) ?: ABSPATH);
        if ($real === $public || str_starts_with($real . '/', rtrim($public, '/') . '/')) {
            return new \WP_Error('wpsso_media_public', 'Define WPSSO_PRIVATE_MEDIA_DIR outside the web document root.');
        }
        return $real;
    }

    public static function protect_media(int $id)
    {
        if (get_post_meta($id, '_wpsso_private_files', true)) {
            return true;
        }
        $vault = self::vault();
        if (is_wp_error($vault)) {
            return $vault;
        }
        $file = get_attached_file($id, true);
        if (!$file || !is_file($file)) {
            return new \WP_Error('wpsso_media_missing', 'The attachment file does not exist.');
        }
        $uploads = wp_get_upload_dir();
        $parent = realpath(dirname($file));
        $upload_root = realpath($uploads['basedir']);
        if (!$parent || !$upload_root || !str_starts_with(wp_normalize_path($parent) . '/', rtrim(wp_normalize_path($upload_root), '/') . '/')) {
            return new \WP_Error('wpsso_media_path', 'Only files in this site upload directory can be protected.');
        }
        $meta = wp_get_attachment_metadata($id) ?: [];
        $files = [basename($file) => $file];
        foreach ($meta['sizes'] ?? [] as $size) {
            $files[basename($size['file'])] = dirname($file) . '/' . basename($size['file']);
        }
        if (!empty($meta['original_image'])) {
            $files[basename($meta['original_image'])] = dirname($file) . '/' . basename($meta['original_image']);
        }
        foreach ((array) get_post_meta($id, '_wp_attachment_backup_sizes', true) as $size) {
            if (!empty($size['file'])) {
                $files[basename($size['file'])] = dirname($file) . '/' . basename($size['file']);
            }
        }
        $target = $vault . '/' . get_current_blog_id() . '/' . $id;
        if (!wp_mkdir_p($target)) {
            return new \WP_Error('wpsso_media_vault', 'Unable to create the attachment vault directory.');
        }
        $private = [];
        foreach ($files as $name => $source) {
            if (!is_file($source)) {
                continue;
            }
            if (!copy($source, $target . '/' . $name)) {
                return new \WP_Error('wpsso_media_copy', 'The private copy failed. No public files were removed.');
            }
            $private[$name] = $target . '/' . $name;
        }
        update_post_meta($id, '_wpsso_private_files', $private);
        update_post_meta($id, '_wpsso_public_files', array_intersect_key($files, $private));
        foreach ($files as $name => $source) {
            if (isset($private[$name]) && is_file($source) && !unlink($source)) {
                return new \WP_Error('wpsso_media_unlink', 'A public file could not be removed. Fix filesystem permissions before relying on protection.');
            }
        }
        clean_post_cache($id);
        return true;
    }

    public static function unprotect_media(int $id)
    {
        $private = (array) get_post_meta($id, '_wpsso_private_files', true);
        $public = (array) get_post_meta($id, '_wpsso_public_files', true);
        foreach ($private as $name => $source) {
            if (!isset($public[$name]) || (file_exists($public[$name]) && !hash_equals(hash_file('sha256', $source), hash_file('sha256', $public[$name])))) {
                return new \WP_Error('wpsso_media_restore', 'An existing public file prevents safe restoration.');
            }
            if (!wp_mkdir_p(dirname($public[$name])) || !copy($source, $public[$name])) {
                return new \WP_Error('wpsso_media_restore', 'Unable to restore the public media file.');
            }
        }
        foreach ($private as $source) {
            wp_delete_file($source);
        }
        delete_post_meta($id, '_wpsso_private_files');
        delete_post_meta($id, '_wpsso_public_files');
        clean_post_cache($id);
        return true;
    }

    public static function media_flag($meta_id, $id, $key, $value): void
    {
        if ($key !== '_wpsso_protected' || self::$moving || get_post_type($id) !== 'attachment') {
            return;
        }
        self::$moving = true;
        $result = $value ? self::protect_media($id) : self::unprotect_media($id);
        if (is_wp_error($result)) {
            set_transient('wpsso_media_error_' . get_current_user_id(), $result->get_error_message(), 300);
            // Keep the restrictive flag when any private copy exists; never report a failed restore as public.
            update_post_meta($id, '_wpsso_protected', (bool) get_post_meta($id, '_wpsso_private_files', true));
            Audit::record('media_error', '', null, $result->get_error_code());
        }
        self::$moving = false;
    }

    public static function media_notice(): void
    {
        $error = get_transient('wpsso_media_error_' . get_current_user_id());
        if ($error) {
            delete_transient('wpsso_media_error_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }

    public static function media_url($url, $id)
    {
        if (!self::is_protected((int) $id)) {
            return $url;
        }
        return add_query_arg(['wpsso_action' => 'media', 'attachment_id' => (int) $id], home_url('/'));
    }

    public static function attached_file($file, $id)
    {
        $files = (array) get_post_meta($id, '_wpsso_private_files', true);
        return $files[basename($file)] ?? $file;
    }

    public static function image_size($result, $id, $size)
    {
        if (!self::is_protected((int) $id)) {
            return $result;
        }
        $meta = wp_get_attachment_metadata($id) ?: [];
        $image = is_string($size) ? ($meta['sizes'][$size] ?? null) : image_get_intermediate_size($id, $size);
        $name = $image['file'] ?? basename($meta['file'] ?? '');
        return [add_query_arg('file', $name, self::media_url('', $id)), (int) ($image['width'] ?? $meta['width'] ?? 0), (int) ($image['height'] ?? $meta['height'] ?? 0), (bool) $image];
    }

    public static function srcset($sources, $size, $src, $meta, $id)
    {
        if (self::is_protected((int) $id) && is_array($sources)) {
            foreach ($sources as &$source) {
                $source['url'] = add_query_arg('file', basename(wp_parse_url($source['url'], PHP_URL_PATH)), self::media_url('', $id));
            }
        }
        return $sources;
    }

    public static function media_request(): void
    {
        if (($_GET['wpsso_action'] ?? '') !== 'media') {
            return;
        }
        $id = absint($_GET['attachment_id'] ?? 0);
        nocache_headers();
        if (!$id || !self::is_protected($id) || !self::can_post($id)) {
            self::deny();
        }
        $files = (array) get_post_meta($id, '_wpsso_private_files', true);
        $original = get_attached_file($id, true);
        $name = isset($_GET['file']) ? wp_unslash($_GET['file']) : basename((string) $original);
        if (!is_string($name) || basename($name) !== $name || !isset($files[$name]) || !is_file($files[$name])) {
            wp_die('Protected media is unavailable.', 'SSO media', ['response' => 404]);
        }
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Type: ' . (wp_check_filetype($name)['type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($files[$name]));
        header('Content-Disposition: inline; filename="' . sanitize_file_name($name) . '"');
        readfile($files[$name]);
        exit;
    }

    public static function delete_media(int $id): void
    {
        foreach ((array) get_post_meta($id, '_wpsso_private_files', true) as $file) {
            wp_delete_file($file);
        }
    }
}
