<?php
namespace WPSSO;

defined('ABSPATH') || exit;

/** WordPress privacy tools for provider-linked profile data; credentials are never exported. */
final class Privacy
{
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'policy']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
    }

    public static function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content') || !is_admin() || !did_action('admin_init')) {
            return;
        }
        $text = '<p class="privacy-policy-tutorial">' . esc_html__('Adapt this suggested text to your configured identity providers, support recipient and retention policy. This plugin does not determine your legal obligations.', 'portalweave-sso') . '</p>';
        $text .= '<h2>' . esc_html__('Single sign-on and provisioning', 'portalweave-sso') . '</h2>';
        $text .= '<p>' . esc_html__('If you use single sign-on, your browser visits the identity provider selected by this site. The site sends the registered client identifier, scopes, callback URL, random state and nonce, and a PKCE challenge where applicable. Token exchange, signing-key lookup and profile/group requests use the configured provider endpoints over HTTPS. We receive the account identifier, email, profile fields and groups needed to identify your local account and apply the site access policy.', 'portalweave-sso') . '</p>';
        $text .= '<p>' . esc_html__('Provider account links, selected profile fields and groups are stored in the WordPress database. Access, refresh and ID tokens are encrypted using this installation’s WordPress salts. Tokens are never included in personal-data or support exports. Passwords entered for an explicitly enabled legacy password grant are sent only to the configured provider and are not stored by this plugin.', 'portalweave-sso') . '</p>';
        /* translators: %d: Number of days configured for local authentication audit retention. */
        $text .= '<p>' . esc_html(sprintf(__('When authentication auditing is enabled, the site stores the account name, email, provider, result, time and navigation path for up to %d days. IP recording is a separate opt-in and is disabled by default. Diagnostic records contain status and error codes, not tokens, and expire after seven days. Scheduled deletion depends on working WordPress cron.', 'portalweave-sso'), (int) Config::get('audit_retention_days', 90))) . '</p>';
        $text .= '<p>' . esc_html__('Short-lived Secure, HttpOnly cookies bind login and logout responses to the initiating browser. WordPress creates its normal authentication cookies after sign-in. Optional JWT sign-in uses a five-minute challenge cookie. Optional shared-subdomain sign-in uses a signed parent-domain cookie lasting one minute, accessible to participating trusted sites; it contains mapped identity fields and never OAuth tokens.', 'portalweave-sso') . '</p>';
        $text .= '<p>' . esc_html__('Optional SCIM provisioning receives profile and group information from the configured provisioning client. Optional mapped profile pictures and custom provider icons are fetched by the visitor’s browser from the administrator-selected HTTPS image host. Enabling those images discloses the browser’s network information to that host. This plugin sends no usage analytics or telemetry to its developer and downloads no executable code.', 'portalweave-sso') . '</p>';
        $text .= '<p>' . esc_html__('Support information is emailed only when an administrator explicitly submits the support form or enables sending in a support ability. The destination is configured by the administrator. Client identifiers, credentials and tokens are omitted from the attached configuration. MCP abilities are off by default; enabling them allows an authenticated administrator’s configured MCP client to request information, including personal audit data, through the separately installed WordPress MCP Adapter.', 'portalweave-sso') . '</p>';
        $text .= '<p>' . esc_html__('WordPress personal-data tools can export or anonymize local audit data and export or erase provider links, tokens, groups and plugin-mapped custom fields. Erasure revokes local sessions. It does not delete the WordPress account, content, role assignments, moderation or provisioning blocks, protected files, or the account at an external provider. Other sites in a multisite installation have their own retained records. After a later sign-in or provisioning request, the provider may supply profile data again.', 'portalweave-sso') . '</p>';
        $text .= '<p>' . esc_html__('Deactivation preserves data. Uninstall also preserves it unless an administrator has selected the data-removal setting. Private-media files and their recovery metadata are retained even with cleanup selected, so deleting the plugin does not publish or destroy private content. The site administrator manages the private vault, backups, external provider policies and any retained records.', 'portalweave-sso') . '</p>';
        wp_add_privacy_policy_content('PortalWeave SSO', wp_kses_post($text));
    }

    public static function exporters(array $exporters): array
    {
        $exporters['wpsso-profile'] = ['exporter_friendly_name' => __('PortalWeave SSO linked profile', 'portalweave-sso'), 'callback' => [self::class, 'export']];
        return $exporters;
    }

    public static function erasers(array $erasers): array
    {
        $erasers['wpsso-profile'] = ['eraser_friendly_name' => __('PortalWeave SSO linked profile', 'portalweave-sso'), 'callback' => [self::class, 'erase']];
        return $erasers;
    }

    private static function users(string $email, int $page): array
    {
        global $wpdb;
        if (!is_email($email)) {
            return [];
        }
        // Exact equality is necessary: '*' is valid in email local parts but is a wildcard in WP_User_Query search.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated exact-email privacy lookup must include duplicate-email accounts and current data.
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT ID FROM %i WHERE user_email = %s ORDER BY ID LIMIT 100 OFFSET %d', $wpdb->users, sanitize_email($email), (max(1, $page) - 1) * 100)));
    }

    private static function suffix(): string
    {
        return is_multisite() ? '_' . get_current_blog_id() : '';
    }

    private static function identity_keys(int $id): array
    {
        $keys = (array) get_user_meta($id, Accounts::identity_keys_meta_key(), true);
        foreach (Config::apps() as $app) {
            $scope = get_current_blog_id() . '|' . $app['id'] . '|' . $app['issuer'] . '|' . $app['client_id'];
            $keys[] = '_wpsso_identity_' . hash('sha256', $scope);
            $keys[] = '_wpsso_sid_' . hash('sha256', $scope);
        }
        if (!is_multisite()) {
            $keys = array_merge($keys, array_keys(get_user_meta($id)));
        }
        return array_values(array_unique(array_filter($keys, static fn($key) => is_string($key) && preg_match('/^_wpsso_(?:identity|sid)_[a-f0-9]{64}$/D', $key))));
    }

    private static function profile_keys(int $id): array
    {
        $keys = [Accounts::groups_meta_key(), Auth::app_meta_key(), '_wpsso_avatar' . self::suffix()];
        foreach (['external', 'groups', 'group_names', 'modified'] as $field) {
            $keys[] = '_wpsso_scim_' . $field . self::suffix();
        }
        $custom = (array) get_user_meta($id, Accounts::mapped_keys_meta_key(), true);
        foreach ($custom as $key) {
            if (is_string($key) && Config::safe_meta_key($key)) {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    public static function export(string $email, int $page = 1): array
    {
        $ids = self::users($email, $page);
        $items = [];
        foreach ($ids as $id) {
            $fields = [];
            foreach (array_merge(self::profile_keys($id), self::identity_keys($id), ['_wpsso_moderation' . self::suffix(), '_wpsso_scim_active' . self::suffix(), '_wpsso_guest_expires']) as $key) {
                $value = get_user_meta($id, $key, true);
                if ($value !== '' && $value !== []) {
                    $fields[] = ['name' => $key, 'value' => is_scalar($value) ? (string) $value : wp_json_encode($value)];
                }
            }
            if ($fields) {
                $items[] = ['group_id' => 'portalweave-sso-profile', 'group_label' => __('Single sign-on profile', 'portalweave-sso'), 'item_id' => 'portalweave-sso-' . get_current_blog_id() . '-' . $id, 'data' => $fields];
            }
        }
        return ['data' => $items, 'done' => count($ids) < 100];
    }

    public static function erase(string $email, int $page = 1): array
    {
        $ids = self::users($email, $page);
        $removed = false;
        $retained = false;
        foreach ($ids as $id) {
            $user_removed = false;
            $keys = array_merge(self::profile_keys($id), self::identity_keys($id), [Accounts::mapped_keys_meta_key(), Accounts::identity_keys_meta_key()]);
            $token_prefix = '_wpsso_tokens_' . (is_multisite() ? get_current_blog_id() . '_' : '');
            foreach (array_keys(get_user_meta($id)) as $key) {
                if (str_starts_with($key, $token_prefix)) {
                    $keys[] = $key;
                }
            }
            foreach (array_unique($keys) as $key) {
                $user_removed = delete_user_meta($id, $key) || $user_removed;
            }
            $removed = $user_removed || $removed;
            if ($user_removed) {
                \WP_Session_Tokens::get_instance($id)->destroy_all();
            }
            // Removing a block or expiry can re-enable an account; retain security policy rather than invert it.
            foreach (['_wpsso_moderation' . self::suffix(), '_wpsso_scim_active' . self::suffix(), '_wpsso_guest_expires'] as $key) {
                $retained = metadata_exists('user', $id, $key) || $retained;
            }
        }
        return [
            'items_removed' => $removed,
            'items_retained' => $retained,
            'messages' => $retained ? [__('Account moderation, provisioning activity and guest-expiry policy are retained to prevent erasure from enabling a blocked account. WordPress accounts, roles, content and private files are managed separately.', 'portalweave-sso')] : [],
            'done' => count($ids) < 100,
        ];
    }
}
