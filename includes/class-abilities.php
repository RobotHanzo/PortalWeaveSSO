<?php
/** WordPress Abilities API and MCP adapter integration. */
namespace WPSSO;

defined('ABSPATH') || exit;

final class Abilities {
    public static function register(): void {
        add_action('wp_abilities_api_categories_init', [self::class, 'category']);
        add_action('wp_abilities_api_init', [self::class, 'abilities']);
    }

    public static function permission($input = null): bool {
        return (bool) Config::get('abilities_enabled', false) && current_user_can('manage_options');
    }

    public static function category(): void {
        if (Config::get('abilities_enabled', false) && function_exists('wp_register_ability_category')) {
            wp_register_ability_category('wpsso', ['label' => __('PortalWeave SSO', 'portalweave-sso'), 'description' => __('Configure and diagnose SSO with administrator permissions.', 'portalweave-sso')]);
        }
    }

    public static function definitions(): array {
        $id = ['type' => 'string', 'pattern' => '^[a-z0-9_-]+$', 'maxLength' => 100];
        return [
            'list-providers' => [__('List SSO providers', 'portalweave-sso'), __('Return provider names and configuration status without credentials.', 'portalweave-sso'), [], [], true],
            'get-provider' => [__('Get SSO provider', 'portalweave-sso'), __('Return the redacted settings of one provider.', 'portalweave-sso'), ['id' => $id], ['id'], true],
            'configure-provider' => [__('Configure SSO provider', 'portalweave-sso'), __('Create or update one provider. Input credentials are encrypted and never returned.', 'portalweave-sso'), ['id' => $id, 'settings' => ['type' => 'object']], ['id', 'settings'], false],
            'diagnose' => [__('Diagnose SSO configuration', 'portalweave-sso'), __('Report local configuration errors, callback URL and safe corrective actions.', 'portalweave-sso'), ['id' => $id], [], true],
            'fix-common-error' => [__('Fix a common SSO error', 'portalweave-sso'), __('Apply an explicit safe fix: enable PKCE, use code grant, restore openid scope or clear callback override.', 'portalweave-sso'),
                ['id' => $id, 'fix' => ['type' => 'string', 'enum' => ['enable-pkce', 'use-code-grant', 'openid-scope', 'clear-callback']]], ['id', 'fix'], false],
            'export-config' => [__('Export redacted SSO configuration', 'portalweave-sso'), __('Export settings with client identifiers, credentials, provisioning and shared secrets removed.', 'portalweave-sso'), [], [], true],
            'login-audit' => [__('Read SSO login audit', 'portalweave-sso'), __('Read recent SSO transaction records. This includes user personal data and requires administrator access.', 'portalweave-sso'),
                ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200], 'app' => $id], [], true],
            'support-query' => [__('Prepare or send an SSO support query', 'portalweave-sso'), __('Prepare a local support draft with redacted configuration. Send only when send=true and a support email is configured.', 'portalweave-sso'),
                ['subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160], 'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10000], 'send' => ['type' => 'boolean']], ['subject', 'message'], false],
        ];
    }

    public static function abilities(): void {
        if (!Config::get('abilities_enabled', false) || !function_exists('wp_register_ability')) {
            return;
        }
        foreach (self::definitions() as $name => $definition) {
            [$label, $description, $properties, $required, $readonly] = $definition;
            wp_register_ability('wpsso/' . $name, [
                'label' => $label, 'description' => $description, 'category' => 'wpsso',
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
                'execute_callback' => static fn($input = []) => self::execute($name, is_array($input) ? $input : []),
                'permission_callback' => [self::class, 'permission'],
                'meta' => ['public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                    'annotations' => ['readonly' => $readonly, 'destructive' => false, 'idempotent' => $name !== 'support-query']],
            ]);
        }
    }

    public static function execute(string $ability, array $input = []) {
        if (!self::permission($input)) {
            return new \WP_Error('wpsso_ability_forbidden', 'Enable SSO abilities and authenticate with manage_options.', ['status' => 403]);
        }
        $definition = self::definitions()[$ability] ?? null;
        if (!$definition) {
            return new \WP_Error('wpsso_ability', 'Unknown SSO ability.');
        }
        // Core's REST validator accepts coercible values such as "true" for a boolean.
        // Require the declared JSON types before validation so direct PHP and MCP calls cannot silently change meaning.
        foreach ($definition[2] as $property => $schema) {
            if (!array_key_exists($property, $input)) {
                continue;
            }
            $value = $input[$property];
            $matches = match ($schema['type']) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                default => false,
            };
            if (!$matches) {
                return new \WP_Error('wpsso_input_type', 'Input ' . $property . ' must have JSON type ' . $schema['type'] . '.', ['status' => 400]);
            }
        }
        // Keep direct PHP execution subject to the remaining REST/MCP schema constraints.
        $valid = rest_validate_value_from_schema($input, ['type' => 'object', 'properties' => $definition[2],
            'required' => $definition[3], 'additionalProperties' => false], 'input');
        if (is_wp_error($valid)) {
            return $valid;
        }
        $id = is_string($input['id'] ?? null) ? sanitize_key($input['id']) : '';
        $redacted = Config::redacted();
        switch ($ability) {
            case 'list-providers':
                $rows = [];
                foreach (Config::apps() as $app_id => $app) {
                    $rows[] = ['id' => $app_id, 'name' => $app['name'], 'provider' => $app['provider'],
                        'enabled' => $app['enabled'], 'protocol' => $app['protocol'], 'issues' => count(self::diagnose_app($app)['issues'])];
                }
                return ['providers' => $rows];
            case 'get-provider':
                return $redacted['apps'][$id] ?? new \WP_Error('wpsso_app', 'Provider not found.');
            case 'configure-provider':
                if (!$id || !isset($input['settings']) || !is_array($input['settings'])) {
                    return new \WP_Error('wpsso_input', 'Provider ID and settings object are required.');
                }
                $settings = Config::all();
                $settings['apps'][$id] = array_replace($settings['apps'][$id] ?? Config::app_defaults(), $input['settings'], ['id' => $id]);
                $result = Config::save($settings);
                return is_wp_error($result) ? $result : ['provider' => Config::redacted()['apps'][$id], 'diagnostics' => self::diagnose_app(Config::app($id))];
            case 'diagnose':
                if ($id) {
                    $app = Config::app($id);
                    return $app ? self::diagnose_app($app) : new \WP_Error('wpsso_app', 'Provider not found.');
                }
                $apps = [];
                foreach (Config::apps() as $app_id => $app) {
                    $apps[$app_id] = self::diagnose_app($app);
                }
                return ['https' => is_ssl(), 'abilities_available' => function_exists('wp_register_ability'),
                    'mcp_adapter_available' => class_exists('WP\\MCP\\Plugin') || class_exists('WP\\MCP\\Core\\McpAdapter'), 'providers' => $apps];
            case 'fix-common-error':
                return self::fix($id, (string) ($input['fix'] ?? ''));
            case 'export-config':
                return $redacted;
            case 'login-audit':
                return ['transactions' => Audit::recent(['limit' => min(200, max(1, (int) ($input['limit'] ?? 25))), 'app' => sanitize_key((string) ($input['app'] ?? ''))])];
            case 'support-query':
                return self::support($input);
            default:
                return new \WP_Error('wpsso_ability', 'Unknown SSO ability.');
        }
    }

    public static function diagnose_app(array $app): array {
        $issues = [];
        foreach (['client_id', 'authorize_url', 'token_url'] as $key) {
            if (empty($app[$key]) && !($key === 'token_url' && ($app['grant'] ?? '') === 'implicit')) {
                $issues[] = ['code' => 'wpsso_missing_' . $key, 'message' => 'Configure ' . $key . '.'];
            }
        }
        if (($app['protocol'] ?? '') === 'oidc') {
            if (empty($app['issuer'])) {
                $issues[] = ['code' => 'wpsso_issuer', 'message' => 'Set the exact issuer from provider discovery.'];
            }
            $algorithms = (array) ($app['algorithms'] ?? []);
            if (array_filter($algorithms, static fn($alg) => str_starts_with($alg, 'RS')) && empty($app['jwks_url'])) {
                $issues[] = ['code' => 'wpsso_jwks', 'message' => 'RSA verification needs the trusted JWKS endpoint.'];
            }
            if (array_filter($algorithms, static fn($alg) => str_starts_with($alg, 'HS')) && empty($app['jwt_secret']) && empty($app['client_secret'])) {
                $issues[] = ['code' => 'wpsso_hmac_secret', 'message' => 'HMAC verification needs a provider-approved shared signing secret.'];
            }
            if (!in_array('openid', preg_split('/\s+/', (string) ($app['scope'] ?? '')), true)) {
                $issues[] = ['code' => 'wpsso_scope', 'message' => 'OpenID Connect needs the openid scope.', 'fix' => 'openid-scope'];
            }
        } elseif (empty($app['userinfo_url'])) {
            $issues[] = ['code' => 'wpsso_userinfo', 'message' => 'OAuth-only login needs a trusted user-info endpoint and immutable subject mapping.'];
        }
        if (empty($app['pkce']) && ($app['grant'] ?? '') === 'authorization_code') {
            $issues[] = ['code' => 'wpsso_pkce', 'message' => 'Enable PKCE S256.', 'fix' => 'enable-pkce'];
        }
        if (($app['grant'] ?? '') !== 'authorization_code') {
            $issues[] = ['code' => 'wpsso_legacy_grant', 'message' => 'Prefer Authorization Code with PKCE.', 'fix' => 'use-code-grant'];
        }
        return ['id' => $app['id'] ?? '', 'callback' => Config::callback($app), 'issues' => $issues,
            'live_provider_test' => 'Use Test SSO Config in the WordPress admin to perform a browser-bound authentication test.'];
    }

    private static function fix(string $id, string $fix) {
        $app = Config::app($id);
        if (!$app) {
            return new \WP_Error('wpsso_app', 'Provider not found.');
        }
        switch ($fix) {
            case 'enable-pkce': $app['pkce'] = true; break;
            case 'use-code-grant': $app['grant'] = 'authorization_code'; $app['pkce'] = true; $app['legacy_grants'] = false; break;
            case 'openid-scope':
                $scopes = preg_split('/\s+/', trim((string) $app['scope']));
                $app['scope'] = implode(' ', array_unique(array_filter(array_merge(['openid'], $scopes))));
                break;
            case 'clear-callback': $app['callback_url'] = ''; break;
            default: return new \WP_Error('wpsso_fix', 'Choose one of the documented safe fixes.');
        }
        $all = Config::all();
        $all['apps'][$id] = $app;
        $result = Config::save($all);
        if (is_wp_error($result)) {
            return $result;
        }
        Audit::record('configuration', $id, wp_get_current_user(), 'wpsso_fix_' . str_replace('-', '_', $fix));
        return ['applied' => $fix, 'diagnostics' => self::diagnose_app(Config::app($id))];
    }

    private static function support(array $input) {
        if (!is_string($input['subject'] ?? null) || !is_string($input['message'] ?? null) ||
            trim($input['subject']) === '' || trim($input['message']) === '' || strlen($input['message']) > 10000 || strlen($input['subject']) > 160) {
            return new \WP_Error('wpsso_support_input', 'Provide a subject (up to 160 characters) and message (up to 10000 characters).');
        }
        $draft = ['id' => wp_generate_uuid4(), 'subject' => sanitize_text_field($input['subject']),
            'message' => sanitize_textarea_field($input['message']), 'configuration' => Config::redacted(), 'created' => gmdate('c')];
        set_transient('wpsso_support_draft_' . $draft['id'], $draft, DAY_IN_SECONDS);
        if (($input['send'] ?? false) !== true) {
            return ['status' => 'draft', 'draft' => $draft, 'expires_in' => DAY_IN_SECONDS];
        }
        $email = Config::get('support', [])['email'] ?? '';
        if (!is_email($email)) {
            return new \WP_Error('wpsso_support_destination', 'Configure a support email before sending. The local draft was retained.');
        }
        $body = $draft['message'] . "\n\nRedacted configuration:\n" . wp_json_encode($draft['configuration'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!wp_mail($email, '[WPSSO] ' . $draft['subject'], $body)) {
            return new \WP_Error('wpsso_support_mail', 'WordPress could not submit this email. The local draft was retained.');
        }
        Audit::record('support', '', wp_get_current_user(), 'wpsso_support_sent');
        return ['status' => 'sent', 'draft_id' => $draft['id']];
    }
}
