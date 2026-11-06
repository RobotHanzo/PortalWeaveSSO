<?php
namespace WPSSO;

defined('ABSPATH') || exit;

final class Accounts
{
    public static function claim(array $attributes, string $path, $default = null)
    {
        if ($path === '') {
            return $default;
        }
        if (array_key_exists($path, $attributes)) {
            return $attributes[$path];
        }
        $value = $attributes;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function verified(array $app, array $attributes): bool
    {
        $value = self::claim($attributes, $app['verified_attribute'] ?? 'email_verified');
        $expected = $app['verified_value'] ?? true;
        // Providers commonly serialize verified as JSON boolean or the string "true".
        if ($expected === true || $expected === 'true' || $expected === '1' || $expected === 1) {
            return in_array($value, [true, 'true', 1, '1'], true);
        }
        return is_scalar($expected) && $value !== null && (string) $expected === (string) $value;
    }

    public static function is_privileged(\WP_User $user): bool
    {
        return $user->has_cap('manage_options') || $user->has_cap('promote_users') || $user->has_cap('edit_users') || (is_multisite() && is_super_admin($user->ID));
    }

    private static function role_privileged(string $slug): bool
    {
        $role = get_role($slug);
        return !$role || !empty($role->capabilities['manage_options']) || !empty($role->capabilities['promote_users']) || !empty($role->capabilities['edit_users']);
    }

    private static function lock(string $identity)
    {
        $key = '_wpsso_lock_' . hash('sha256', $identity);
        global $wpdb;
        // Atomic stale-lock deletion does not remove a new owner's unexpired lock.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $key, time()));
        wp_cache_delete($key, 'options');
        return add_option($key, time() + 120, '', false) ? $key : new \WP_Error('wpsso_login_busy', 'This account is already being processed. Retry sign-in shortly.');
    }

    public static function resolve(array $app, array $attributes, array $tokens = [])
    {
        $app = array_replace(Config::app_defaults(), $app);
        $pre = apply_filters('wpsso_pre_resolve', null, $app, $attributes, $tokens);
        if ($pre instanceof \WP_User || is_wp_error($pre)) {
            return $pre;
        }
        $subject = self::claim($attributes, $app['protocol'] === 'oidc' ? 'sub' : $app['subject_attribute']);
        if (!is_scalar($subject) || (string) $subject === '' || strlen((string) $subject) > 1024) {
            return new \WP_Error('wpsso_subject', 'The provider did not return a stable account identifier.');
        }
        $scope = (string) get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
        $identity_key = '_wpsso_identity_' . hash('sha256', $scope);
        $identity = hash('sha256', (string) $subject);
        $identity_lock = self::lock($identity_key . $identity);
        if (is_wp_error($identity_lock)) {
            return $identity_lock;
        }
        $email_lock = null;
        try {
            $email = self::claim($attributes, $app['mapping']['email'], '');
            if (!is_string($email) || !is_email($email)) {
                return new \WP_Error('wpsso_email', 'A valid email address is required from the provider.');
            }
            $email = strtolower(sanitize_email($email));
            $original_domain = substr(strrchr($email, '@'), 1);
            $domain = $app['domain_mapping'][$original_domain] ?? $original_domain;
            if (!Config::domain($domain)) {
                return new \WP_Error('wpsso_domain', 'Invalid mapped email domain.');
            }
            $email = substr($email, 0, strrpos($email, '@') + 1) . $domain;
            $blocked = Config::get('blocked_domains', []);
            $allowed = Config::get('allowed_domains', []);
            if (in_array($original_domain, $blocked, true) || in_array($domain, $blocked, true)
                || ($allowed && !in_array($original_domain, $allowed, true))) {
                return new \WP_Error('wpsso_domain_blocked', 'Your email domain is not permitted on this site.');
            }
            $verified = self::verified($app, $attributes);
            if ($app['require_verified_email'] && !$verified) {
                return new \WP_Error('wpsso_unverified', 'Your identity provider must verify your account before sign-in.');
            }
            $matches = get_users(['meta_key' => $identity_key, 'meta_value' => $identity, 'number' => 2, 'blog_id' => 0]);
            if (count($matches) > 1) {
                return new \WP_Error('wpsso_identity_conflict', 'The provider identity is linked to more than one local account.');
            }
            $user = $matches[0] ?? null;
            $email_lock = self::lock('email|' . $email);
            if (is_wp_error($email_lock)) {
                return $email_lock;
            }
            $email_users = get_users(['search' => $email, 'search_columns' => ['user_email'], 'number' => 3, 'blog_id' => 0]);
            $email_users = array_values(array_filter($email_users, static fn($u) => strtolower($u->user_email) === $email));
            if (!$user && $email_users && $app['link_existing']) {
                // Rewriting a domain cannot prove ownership of the destination mailbox.
                if (!$verified || $domain !== $original_domain || count($email_users) !== 1) {
                    return new \WP_Error('wpsso_link_verification', 'Account linking requires a unique, verified, unmapped email address.');
                }
                $user = $email_users[0];
                $other_subject = get_user_meta($user->ID, $identity_key, true);
                if ($other_subject && !hash_equals((string) $other_subject, $identity)) {
                    return new \WP_Error('wpsso_identity_conflict', 'That local account is already linked to another provider identity.');
                }
            }
            if (!$user && $email_users && !$app['allow_duplicate_emails']) {
                return new \WP_Error('wpsso_link_required', 'A local account already uses that email. Ask an administrator to configure verified account linking.');
            }
            $new = !$user;
            $guest = !empty(Config::get('modules', [])['guest']) && $app['guest'];
            if ($new && !$app['auto_register'] && !$guest) {
                return new \WP_Error('wpsso_registration_disabled', 'Only existing linked users can sign in.');
            }
            if ($user && self::is_privileged($user) && (!$app['allow_admin'] || !$verified)) {
                return new \WP_Error('wpsso_admin_disabled', 'Administrator SSO requires an enabled provider and its verified account claim.');
            }
            if ($user && (!empty($user->spam) || !empty($user->deleted) || get_user_meta($user->ID, '_wpsso_scim_active', true) === '0')) {
                return new \WP_Error('wpsso_account_disabled', 'This local account has been disabled.');
            }
            $groups = self::claim($attributes, $app['mapping']['groups'], []);
            $groups = array_values(array_unique(array_filter(array_map(static fn($v) => is_scalar($v) ? (string) $v : '', is_array($groups) ? $groups : [$groups]), static fn($v) => $v !== '')));
            $roles = [];
            foreach ($groups as $group) {
                $roles = array_merge($roles, Config::list($app['role_mapping'][$group] ?? []));
            }
            $roles = array_merge($roles, Config::list($app['domain_roles'][$original_domain] ?? []));
            if ($app['block_unmapped'] && !$roles) {
                return new \WP_Error('wpsso_role_unmapped', 'No provider group or email-domain role mapping matched your account.');
            }
            $roles = array_values(array_unique($roles ?: [$app['default_role']]));
            if ($guest) {
                if ($user && !get_user_meta($user->ID, '_wpsso_guest_expires', true)) {
                    return new \WP_Error('wpsso_guest_link', 'Guest providers cannot sign in as permanent accounts.');
                }
                $roles = ['subscriber'];
            }
            foreach ($roles as $role) {
                if (!get_role($role) || (self::role_privileged($role) && (!$app['allow_admin'] || !$verified))) {
                    return new \WP_Error('wpsso_privileged_role', 'This provider cannot assign the requested privileged role.');
                }
            }
            $permitted = apply_filters('wpsso_account_allowed', true, $user, $app, $attributes);
            if (is_wp_error($permitted) || $permitted !== true) {
                return is_wp_error($permitted) ? $permitted : new \WP_Error('wpsso_account_denied', 'Sign-in was denied by an account policy.');
            }
            $data = [];
            if ($new || !$app['keep_attributes']) {
                foreach (['first_name', 'last_name', 'display_name'] as $field) {
                    $value = self::claim($attributes, $app['mapping'][$field], '');
                    if (is_scalar($value)) {
                        $data[$field] = sanitize_text_field((string) $value);
                    }
                }
            }
            if ($new || !$app['keep_email']) {
                $data['user_email'] = $email;
            }
            if ($new) {
                $username = self::claim($attributes, $app['mapping']['username'], '');
                $username = is_scalar($username) ? sanitize_user((string) $username, true) : '';
                $username = substr($username ?: strstr($email, '@', true), 0, 45);
                $base = $username ?: 'sso';
                for ($suffix = 0; username_exists($username); $suffix++) {
                    $username = $base . '_' . substr(hash('sha256', $scope . $identity . $suffix), 0, 10);
                }
                $data['user_login'] = $username;
                $data['user_pass'] = wp_generate_password(64, true, true);
                $data['role'] = $roles[0];
            } else {
                $data['ID'] = $user->ID;
                // Cross-blog user attachment still requires a validated provider identity and policies.
            }
            $other_email_users = array_filter($email_users, static fn($u) => !$user || $u->ID !== $user->ID);
            if ($other_email_users && isset($data['user_email']) && !$app['allow_duplicate_emails']) {
                return new \WP_Error('wpsso_duplicate_email', 'Another local account uses the mapped email address.');
            }
            $restore_email = null;
            if ($other_email_users && isset($data['user_email']) && $app['allow_duplicate_emails']) {
                // WP checks duplicates before the documented pre-insert data filter.
                $data['user_email'] = '';
                $restore_email = static function ($row) use ($email) { $row['user_email'] = $email; return $row; };
                add_filter('wp_pre_insert_user_data', $restore_email, 999);
            }
            try {
                $id = $new ? wp_insert_user($data) : wp_update_user($data);
            } finally {
                if ($restore_email) {
                    remove_filter('wp_pre_insert_user_data', $restore_email, 999);
                }
            }
            if (is_wp_error($id)) {
                return new \WP_Error('wpsso_user_save', 'The local account could not be saved.');
            }
            $user = get_userdata($id);
            if ($new || !$app['keep_roles'] || !is_user_member_of_blog($id)) {
                $user->set_role(array_shift($roles));
                foreach ($roles as $role) {
                    $user->add_role($role);
                }
            }
            update_user_meta($id, $identity_key, $identity);
            update_user_meta($id, '_wpsso_groups', $groups);
            update_user_meta($id, '_wpsso_app', $app['id']);
            if ($guest && $new) {
                update_user_meta($id, '_wpsso_guest_expires', time() + (int) Config::get('guest_ttl', 3600));
            }
            if ($new || !$app['keep_attributes']) {
                foreach ($app['custom_mapping'] as $meta => $path) {
                    if (Config::safe_meta_key($meta)) {
                        $value = self::claim($attributes, $path);
                        if ($value !== null) {
                            update_user_meta($id, $meta, map_deep($value, static fn($v) => is_scalar($v) ? sanitize_text_field((string) $v) : ''));
                        }
                    }
                }
            }
            do_action('wpsso_user_resolved', $user, $app, $attributes, $tokens);
            return $user;
        } finally {
            delete_option($identity_lock);
            if (is_string($email_lock)) {
                delete_option($email_lock);
            }
        }
    }
}
