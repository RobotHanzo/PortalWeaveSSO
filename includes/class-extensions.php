<?php
/** Optional provisioning and account integrations. */
namespace WPSSO;

defined('ABSPATH') || exit;

final class Extensions {
    private const SCIM = 'urn:ietf:params:scim:schemas:core:2.0:';
    private const API = 'urn:ietf:params:scim:api:messages:2.0:';
    private const SHARED_COOKIE = '__Secure-wpsso-shared';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_post_dispatch', [self::class, 'scim_headers'], 10, 3);
        add_action('init', [self::class, 'jwt_route'], 1);
        add_action('template_redirect', [self::class, 'shared_consume'], 1);
        add_action('wpsso_authenticated', [self::class, 'shared_issue'], 10, 3);
        add_action('wpsso_user_resolved', [self::class, 'resolved'], 10, 4);
        add_filter('wpsso_account_allowed', [self::class, 'account_allowed'], 10, 4);
        add_filter('wpsso_pre_authenticate', [self::class, 'account_check'], 10, 3);
        add_filter('authenticate', [self::class, 'local_account_check'], 99, 3);
        add_filter('wpsso_login_redirect', [self::class, 'attribute_redirect'], 10, 4);
        add_filter('get_avatar_data', [self::class, 'avatar'], 10, 2);
        add_action('show_user_profile', [self::class, 'moderation_profile']);
        add_action('edit_user_profile', [self::class, 'moderation_profile']);
        add_action('personal_options_update', [self::class, 'moderation_save']);
        add_action('edit_user_profile_update', [self::class, 'moderation_save']);
        add_action('wpsso_extension_cleanup', [self::class, 'cleanup']);
        add_action('wpsso_expire_claim', [self::class, 'expire_claim']);
        add_action('wp_logout', [self::class, 'shared_clear']);
        if (!wp_next_scheduled('wpsso_extension_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wpsso_extension_cleanup');
        }
    }

    public static function enabled(string $module): bool {
        return !empty(Config::get('modules', [])[$module]);
    }

    public static function routes(): void {
        if (!self::enabled('scim')) {
            return;
        }
        $permission = [self::class, 'scim_permission'];
        foreach (['Users', 'Groups'] as $resource) {
            register_rest_route('wpsso/v1', '/scim/v2/' . $resource, [
                ['methods' => 'GET,POST', 'callback' => [self::class, 'scim_collection'], 'permission_callback' => $permission],
            ]);
            register_rest_route('wpsso/v1', '/scim/v2/' . $resource . '/(?P<id>[a-zA-Z0-9-]+)', [
                ['methods' => 'GET,PUT,PATCH,DELETE', 'callback' => [self::class, 'scim_item'], 'permission_callback' => $permission],
            ]);
        }
        foreach (['ServiceProviderConfig', 'Schemas', 'ResourceTypes'] as $resource) {
            register_rest_route('wpsso/v1', '/scim/v2/' . $resource, [
                'methods' => 'GET', 'callback' => [self::class, 'scim_discovery'], 'permission_callback' => $permission,
            ]);
        }
    }

    public static function scim_permission($request) {
        $secret = (string) Config::get('scim_token', '');
        $header = (string) $request->get_header('authorization');
        if (!self::enabled('scim') || !is_ssl() || strlen($secret) < 32 ||
            !preg_match('/^Bearer ([^\s]+)$/iD', $header, $match) ||
            !hash_equals(hash('sha256', $secret), hash('sha256', $match[1]))) {
            return new \WP_Error('wpsso_scim_auth', 'A valid SCIM bearer credential over HTTPS is required.', ['status' => 401]);
        }
        return true;
    }

    public static function scim_headers($response, $server, $request) {
        if (strpos($request->get_route(), '/wpsso/v1/scim/v2/') !== 0) {
            return $response;
        }
        $response = rest_ensure_response($response);
        if ($response->is_error()) {
            $data = $response->get_data();
            $response->set_data(['schemas' => [self::API . 'Error'], 'status' => (string) $response->get_status(),
                'detail' => (string) ($data['message'] ?? 'SCIM request failed.')]);
        }
        $response->header('Content-Type', 'application/scim+json; charset=UTF-8');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function response(array $body, int $status = 200): \WP_REST_Response {
        return new \WP_REST_Response($body, $status);
    }

    private static function error(string $detail, int $status = 400, string $type = 'invalidValue'): \WP_REST_Response {
        return self::response(['schemas' => [self::API . 'Error'], 'status' => (string) $status, 'scimType' => $type, 'detail' => $detail], $status);
    }

    private static function list_response(array $rows, int $total, int $start): \WP_REST_Response {
        return self::response(['schemas' => [self::API . 'ListResponse'], 'totalResults' => $total,
            'startIndex' => $start, 'itemsPerPage' => count($rows), 'Resources' => array_values($rows)]);
    }

    public static function scim_discovery($request) {
        if (str_ends_with($request->get_route(), '/ServiceProviderConfig')) {
            return self::response(['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
                'patch' => ['supported' => true], 'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
                'filter' => ['supported' => true, 'maxResults' => 200], 'changePassword' => ['supported' => false],
                'sort' => ['supported' => false], 'etag' => ['supported' => false],
                'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token',
                    'description' => 'Dedicated administrator-configured SCIM bearer token over HTTPS.']]]);
        }
        $rows = [];
        foreach (['User', 'Group'] as $type) {
            if (str_ends_with($request->get_route(), '/ResourceTypes')) {
                $rows[] = ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'], 'id' => $type,
                    'name' => $type, 'endpoint' => '/' . $type . 's', 'schema' => self::SCIM . $type,
                    'meta' => ['resourceType' => 'ResourceType']];
            } else {
                $attributes = $type === 'User' ? [
                    ['name' => 'userName', 'type' => 'string', 'required' => true, 'multiValued' => false, 'uniqueness' => 'server', 'mutability' => 'readWrite', 'returned' => 'default', 'caseExact' => false],
                    ['name' => 'active', 'type' => 'boolean', 'required' => false, 'multiValued' => false, 'mutability' => 'readWrite', 'returned' => 'default'],
                    ['name' => 'displayName', 'type' => 'string', 'required' => false, 'multiValued' => false, 'mutability' => 'readWrite', 'returned' => 'default'],
                    ['name' => 'name', 'type' => 'complex', 'required' => false, 'multiValued' => false, 'mutability' => 'readWrite', 'returned' => 'default',
                        'subAttributes' => [['name' => 'givenName', 'type' => 'string', 'multiValued' => false], ['name' => 'familyName', 'type' => 'string', 'multiValued' => false]]],
                    ['name' => 'emails', 'type' => 'complex', 'required' => false, 'multiValued' => true, 'mutability' => 'readWrite', 'returned' => 'default',
                        'subAttributes' => [['name' => 'value', 'type' => 'string', 'multiValued' => false], ['name' => 'primary', 'type' => 'boolean', 'multiValued' => false], ['name' => 'type', 'type' => 'string', 'multiValued' => false]]],
                ] : [
                    ['name' => 'displayName', 'type' => 'string', 'required' => true, 'multiValued' => false, 'mutability' => 'readWrite', 'returned' => 'default'],
                    ['name' => 'members', 'type' => 'complex', 'multiValued' => true, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default',
                        'subAttributes' => [['name' => 'value', 'type' => 'string', 'multiValued' => false], ['name' => 'display', 'type' => 'string', 'multiValued' => false, 'mutability' => 'readOnly']]],
                ];
                $rows[] = ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'], 'id' => self::SCIM . $type,
                    'name' => $type, 'attributes' => $attributes, 'meta' => ['resourceType' => 'Schema']];
            }
        }
        return self::list_response($rows, 2, 1);
    }

    /** Supported filter grammar is intentionally bounded: equality on identity attributes. */
    public static function parse_filter(string $filter) {
        if ($filter === '') {
            return null;
        }
        if (!preg_match('/^(userName|externalId|id|displayName|emails\.value)\s+eq\s+("(?:[^"\\\\]|\\\\.)*")$/iD', trim($filter), $m)) {
            return new \WP_Error('wpsso_scim_filter', 'Supported filters are equality on userName, externalId, id, displayName, or emails.value.');
        }
        $value = json_decode($m[2], true);
        if (!is_string($value) || strlen($value) > 512) {
            return new \WP_Error('wpsso_scim_filter', 'Invalid filter value.');
        }
        return [strtolower($m[1]), $value];
    }

    private static function filter_matches(array $resource, ?array $filter): bool {
        if (!$filter) {
            return true;
        }
        [$key, $value] = $filter;
        if ($key === 'emails.value') {
            foreach ($resource['emails'] ?? [] as $email) {
                if (strcasecmp((string) $email['value'], $value) === 0) {
                    return true;
                }
            }
            return false;
        }
        $keys = ['username' => 'userName', 'externalid' => 'externalId', 'displayname' => 'displayName', 'id' => 'id'];
        return strcasecmp((string) ($resource[$keys[$key]] ?? ''), $value) === 0;
    }

    public static function scim_collection($request) {
        $group = str_ends_with($request->get_route(), '/Groups');
        if ($request->get_method() === 'POST') {
            $body = self::body($request);
            if (is_wp_error($body)) {
                return self::error($body->get_error_message());
            }
            return $group ? self::write_group('', $body) : self::write_user(0, $body);
        }
        $filter = self::parse_filter((string) $request->get_param('filter'));
        if (is_wp_error($filter)) {
            return self::error($filter->get_error_message(), 400, 'invalidFilter');
        }
        $start = max(1, (int) ($request->get_param('startIndex') ?: 1));
        $count = $request->get_param('count') === null ? 100 : min(200, max(0, (int) $request->get_param('count')));
        $rows = [];
        if ($group) {
            foreach (get_option('wpsso_scim_groups', []) as $id => $value) {
                $resource = self::group_resource($id, $value);
                if (self::filter_matches($resource, $filter)) {
                    $rows[] = $resource;
                }
            }
        } else {
            // Batch querying prevents loading user objects for an entire large directory at once.
            $offset = 0;
            do {
                $users = get_users(['meta_key' => '_wpsso_scim_managed', 'meta_value' => '1', 'number' => 200, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC']);
                foreach ($users as $user) {
                    $resource = self::user_resource($user);
                    if (self::filter_matches($resource, $filter)) {
                        $rows[] = $resource;
                    }
                }
                $offset += 200;
            } while (count($users) === 200);
        }
        return self::list_response(array_slice($rows, $start - 1, $count), count($rows), $start);
    }

    private static function body($request) {
        if (strlen($request->get_body()) > 262144) {
            return new \WP_Error('wpsso_scim_body', 'Payload exceeds 256 KiB.');
        }
        $data = json_decode($request->get_body(), true, 32);
        return is_array($data) && !array_is_list($data) ? $data : new \WP_Error('wpsso_scim_body', 'A JSON object is required.');
    }

    public static function scim_item($request) {
        $group = strpos($request->get_route(), '/Groups/') !== false;
        $id = (string) $request['id'];
        $resource = null;
        if ($group) {
            $groups = get_option('wpsso_scim_groups', []);
            if (isset($groups[$id])) {
                $resource = self::group_resource($id, $groups[$id]);
            }
        } elseif (ctype_digit($id)) {
            $user = get_user_by('id', (int) $id);
            if ($user && get_user_meta($user->ID, '_wpsso_scim_managed', true) === '1') {
                $resource = self::user_resource($user);
            }
        }
        if (!$resource) {
            return self::error('Resource not found.', 404, 'noTarget');
        }
        if ($request->get_method() === 'GET') {
            return self::response($resource);
        }
        if (!$group && self::privileged((int) $id)) {
            return self::error('SCIM cannot change privileged WordPress accounts.', 403, 'mutability');
        }
        if ($request->get_method() === 'DELETE') {
            if ($group) {
                return self::write_group($id, ['_delete' => true]);
            }
            update_user_meta((int) $id, '_wpsso_scim_active', '0');
            \WP_Session_Tokens::get_instance((int) $id)->destroy_all();
            Audit::record('provisioning', 'scim', get_user_by('id', (int) $id), 'wpsso_scim_deactivated');
            return self::response([], 204);
        }
        $body = self::body($request);
        if (is_wp_error($body)) {
            return self::error($body->get_error_message());
        }
        if ($request->get_method() === 'PATCH') {
            $body = self::patch($resource, $body);
            if (is_wp_error($body)) {
                return self::error($body->get_error_message(), 400, 'invalidPath');
            }
        }
        return $group ? self::write_group($id, $body) : self::write_user((int) $id, $body);
    }

    public static function patch(array $resource, array $patch) {
        if (empty($patch['Operations']) || !is_array($patch['Operations']) || count($patch['Operations']) > 100) {
            return new \WP_Error('wpsso_scim_patch', 'One to 100 PATCH operations are required.');
        }
        $map = ['username' => 'userName', 'externalid' => 'externalId', 'displayname' => 'displayName',
            'active' => 'active', 'emails' => 'emails', 'name' => 'name', 'members' => 'members'];
        foreach ($patch['Operations'] as $op) {
            if (!is_array($op)) {
                return new \WP_Error('wpsso_scim_patch', 'Malformed PATCH operation.');
            }
            $action = strtolower((string) ($op['op'] ?? ''));
            $path = (string) ($op['path'] ?? '');
            $value = $op['value'] ?? null;
            if (!in_array($action, ['add', 'replace', 'remove'], true)) {
                return new \WP_Error('wpsso_scim_patch', 'Unsupported PATCH operation.');
            }
            if ($path === '' && in_array($action, ['add', 'replace'], true) && is_array($value) && !array_is_list($value)) {
                foreach ($value as $key => $entry) {
                    $canonical = $map[strtolower($key)] ?? null;
                    if (!$canonical) {
                        return new \WP_Error('wpsso_scim_patch', 'Attribute is unsupported or read-only.');
                    }
                    $resource[$canonical] = $entry;
                }
                continue;
            }
            if (preg_match('/^members\[value eq "([a-zA-Z0-9-]+)"\]$/iD', $path, $match) && $action === 'remove') {
                $resource['members'] = array_values(array_filter($resource['members'] ?? [], static fn($member) => (string) ($member['value'] ?? '') !== $match[1]));
                continue;
            }
            if (preg_match('/^name\.(givenName|familyName)$/iD', $path, $match)) {
                $resource['name'][strtolower($match[1]) === 'givenname' ? 'givenName' : 'familyName'] = $action === 'remove' ? '' : $value;
                continue;
            }
            $key = $map[strtolower($path)] ?? null;
            if (!$key) {
                return new \WP_Error('wpsso_scim_patch', 'Attribute path is unsupported or read-only.');
            }
            if ($action === 'remove') {
                unset($resource[$key]);
            } elseif ($action === 'add' && in_array($key, ['members', 'emails'], true)) {
                if (!is_array($value)) {
                    return new \WP_Error('wpsso_scim_patch', 'Multivalued attribute requires an array.');
                }
                $resource[$key] = array_merge($resource[$key] ?? [], $value);
            } else {
                $resource[$key] = $value;
            }
        }
        return $resource;
    }

    private static function privileged(int $user_id): bool {
        $user = get_user_by('id', $user_id);
        return !$user || is_super_admin($user_id) || user_can($user, 'manage_options') || user_can($user, 'edit_users') || user_can($user, 'promote_users');
    }

    private static function write_user(int $id, array $body) {
        $username = $body['userName'] ?? '';
        $external = $body['externalId'] ?? '';
        if (!is_string($username) || $username === '' || strlen($username) > 60 || sanitize_user($username, true) !== $username || !is_string($external) || strlen($external) > 255) {
            return self::error('userName must be a valid WordPress username; externalId is at most 255 characters.');
        }
        if (isset($body['active']) && !is_bool($body['active'])) {
            return self::error('active must be a boolean.');
        }
        foreach (['password', 'roles', 'user_type', 'user_pass', 'capabilities'] as $forbidden) {
            if (array_key_exists($forbidden, $body)) {
                return self::error('SCIM cannot set passwords, roles, or capabilities.', 403, 'mutability');
            }
        }
        $email = '';
        if (isset($body['emails']) && !is_array($body['emails'])) {
            return self::error('emails must be an array.');
        }
        foreach ($body['emails'] ?? [] as $entry) {
            if (!is_array($entry) || !is_string($entry['value'] ?? null) || !is_email($entry['value'])) {
                return self::error('Every email value must be valid.');
            }
            if ($email === '' || !empty($entry['primary'])) {
                $email = $entry['value'];
            }
        }
        foreach (['displayName'] as $key) {
            if (isset($body[$key]) && !is_string($body[$key])) {
                return self::error('Profile attributes must be strings.');
            }
        }
        if (isset($body['name']) && !is_array($body['name'])) {
            return self::error('name must be an object.');
        }
        foreach (['givenName', 'familyName'] as $name) {
            if (isset($body['name'][$name]) && !is_string($body['name'][$name])) {
                return self::error('Name attributes must be strings.');
            }
        }
        $lock = 'wpsso_scim_write_lock';
        if (!add_option($lock, time(), '', false)) {
            return self::error('Another provisioning write is in progress; retry.', 409, 'uniqueness');
        }
        try {
            if ($id && self::privileged($id)) {
                return self::error('SCIM cannot change privileged accounts.', 403, 'mutability');
            }
            $existing = username_exists($username);
            if (($existing && (int) $existing !== $id) || ($email && ($owner = email_exists($email)) && (int) $owner !== $id)) {
                return self::error('Username or email is already assigned.', 409, 'uniqueness');
            }
            if ($id && get_user_by('id', $id)->user_login !== $username) {
                return self::error('WordPress usernames cannot be renamed.', 400, 'mutability');
            }
            if ($external !== '') {
                $owners = get_users(['meta_key' => '_wpsso_scim_external', 'meta_value' => $external, 'fields' => 'ID', 'number' => 2]);
                if ($owners && (count($owners) > 1 || (int) $owners[0] !== $id)) {
                    return self::error('externalId is already assigned.', 409, 'uniqueness');
                }
            }
            $data = ['user_email' => $email, 'display_name' => sanitize_text_field($body['displayName'] ?? $username),
                'first_name' => sanitize_text_field($body['name']['givenName'] ?? ''), 'last_name' => sanitize_text_field($body['name']['familyName'] ?? '')];
            if ($id) {
                $data['ID'] = $id;
            } else {
                $data['user_login'] = $username;
                $data['user_pass'] = wp_generate_password(64, true, true);
                $data['role'] = 'subscriber';
            }
            $result = $id ? wp_update_user($data) : wp_insert_user($data);
            if (is_wp_error($result)) {
                return self::error('Unable to provision this account.', 409, 'uniqueness');
            }
            update_user_meta($result, '_wpsso_scim_managed', '1');
            update_user_meta($result, '_wpsso_scim_external', $external);
            update_user_meta($result, '_wpsso_scim_active', ($body['active'] ?? true) ? '1' : '0');
            update_user_meta($result, '_wpsso_scim_modified', gmdate('c'));
            if (($body['active'] ?? true) === false) {
                \WP_Session_Tokens::get_instance($result)->destroy_all();
            }
            $user = get_user_by('id', $result);
            Audit::record('provisioning', 'scim', $user, $id ? 'wpsso_scim_updated' : 'wpsso_scim_created');
            $response = self::response(self::user_resource($user), $id ? 200 : 201);
            $response->header('Location', rest_url('wpsso/v1/scim/v2/Users/' . $result));
            return $response;
        } finally {
            delete_option($lock);
        }
    }

    private static function user_resource($user): array {
        return ['schemas' => [self::SCIM . 'User'], 'id' => (string) $user->ID,
            'externalId' => (string) get_user_meta($user->ID, '_wpsso_scim_external', true),
            'userName' => $user->user_login, 'displayName' => $user->display_name,
            'active' => get_user_meta($user->ID, '_wpsso_scim_active', true) !== '0',
            'name' => ['givenName' => (string) $user->first_name, 'familyName' => (string) $user->last_name],
            'emails' => $user->user_email ? [['value' => $user->user_email, 'primary' => true, 'type' => 'work']] : [],
            'groups' => array_map(static fn($id) => ['value' => (string) $id], (array) get_user_meta($user->ID, '_wpsso_scim_groups', true)),
            'meta' => ['resourceType' => 'User', 'created' => gmdate('c', strtotime($user->user_registered . ' UTC')),
                'lastModified' => (string) get_user_meta($user->ID, '_wpsso_scim_modified', true), 'location' => rest_url('wpsso/v1/scim/v2/Users/' . $user->ID)]];
    }

    private static function group_resource(string $id, array $group): array {
        $members = [];
        foreach ($group['members'] ?? [] as $user_id) {
            $user = get_user_by('id', (int) $user_id);
            if ($user) {
                $members[] = ['value' => (string) $user_id, 'display' => $user->display_name, '$ref' => rest_url('wpsso/v1/scim/v2/Users/' . $user_id)];
            }
        }
        return ['schemas' => [self::SCIM . 'Group'], 'id' => $id, 'externalId' => $group['externalId'] ?? '',
            'displayName' => $group['displayName'], 'members' => $members,
            'meta' => ['resourceType' => 'Group', 'created' => $group['created'], 'lastModified' => $group['modified'],
                'location' => rest_url('wpsso/v1/scim/v2/Groups/' . $id)]];
    }

    private static function write_group(string $id, array $body) {
        if (!empty($body['_delete']) && !$id) {
            return self::error('Resource not found.', 404, 'noTarget');
        }
        if (empty($body['_delete']) && (!is_string($body['displayName'] ?? null) || trim($body['displayName']) === '' || strlen($body['displayName']) > 255)) {
            return self::error('A group displayName of at most 255 characters is required.');
        }
        if (isset($body['externalId']) && (!is_string($body['externalId']) || strlen($body['externalId']) > 255)) {
            return self::error('externalId must be a string of at most 255 characters.');
        }
        if (isset($body['members']) && (!is_array($body['members']) || count($body['members']) > 10000)) {
            return self::error('members must be an array with at most 10000 entries.');
        }
        $members = [];
        foreach ($body['members'] ?? [] as $member) {
            if (!is_array($member) || !ctype_digit((string) ($member['value'] ?? ''))) {
                return self::error('Each member requires a numeric SCIM user ID.');
            }
            $user_id = (int) $member['value'];
            if (get_user_meta($user_id, '_wpsso_scim_managed', true) !== '1' || self::privileged($user_id)) {
                return self::error('Group membership can only include provisioned nonprivileged accounts.', 403, 'mutability');
            }
            $members[] = $user_id;
        }
        $lock = 'wpsso_scim_write_lock';
        if (!add_option($lock, time(), '', false)) {
            return self::error('Another provisioning write is in progress; retry.', 409, 'uniqueness');
        }
        try {
            $groups = get_option('wpsso_scim_groups', []);
            $old = $id ? ($groups[$id] ?? null) : null;
            if ($id && !$old) {
                return self::error('Resource not found.', 404, 'noTarget');
            }
            $created = !$id;
            $id = $id ?: wp_generate_uuid4();
            if (!empty($body['_delete'])) {
                unset($groups[$id]);
            } else {
                foreach ($groups as $other_id => $group) {
                    if ($other_id !== $id && (!empty($body['externalId']) && $group['externalId'] === $body['externalId'])) {
                        return self::error('externalId is already assigned.', 409, 'uniqueness');
                    }
                }
                $groups[$id] = ['displayName' => sanitize_text_field($body['displayName']), 'externalId' => $body['externalId'] ?? '',
                    'members' => array_values(array_unique($members)), 'created' => $old['created'] ?? gmdate('c'), 'modified' => gmdate('c')];
            }
            update_option('wpsso_scim_groups', $groups, false);
            foreach (array_unique(array_merge($old['members'] ?? [], $members)) as $user_id) {
                $ids = [];
                $names = [];
                foreach ($groups as $group_id => $group) {
                    if (in_array((int) $user_id, $group['members'], true)) {
                        $ids[] = $group_id;
                        $names[] = $group['displayName'];
                    }
                }
                update_user_meta($user_id, '_wpsso_scim_groups', $ids);
                update_user_meta($user_id, '_wpsso_scim_group_names', array_values(array_unique($names)));
            }
            Audit::record('provisioning', 'scim', null, !empty($body['_delete']) ? 'wpsso_scim_group_deleted' : 'wpsso_scim_group_saved');
            if (!empty($body['_delete'])) {
                return self::response([], 204);
            }
            $response = self::response(self::group_resource($id, $groups[$id]), $created ? 201 : 200);
            $response->header('Location', rest_url('wpsso/v1/scim/v2/Groups/' . $id));
            return $response;
        } finally {
            delete_option($lock);
        }
    }

    public static function resolved($user, array $app, array $attributes, array $tokens = []): void {
        if (self::enabled('moderation') && !empty($app['moderation']) && !self::privileged($user->ID) && get_user_meta($user->ID, '_wpsso_moderation', true) === '') {
            update_user_meta($user->ID, '_wpsso_moderation', 'pending');
        }
        if (self::enabled('avatar')) {
            $url = self::claim($attributes, (string) ($app['avatar_attribute'] ?? 'picture'));
            if (is_string($url) && strpos($url, 'https://') === 0 && wp_http_validate_url($url)) {
                update_user_meta($user->ID, '_wpsso_avatar', esc_url_raw($url));
            }
        }
    }

    public static function account_check($user, array $app = [], array $attributes = []) {
        if (is_wp_error($user) || !($user instanceof \WP_User)) {
            return $user;
        }
        if (get_user_meta($user->ID, '_wpsso_scim_active', true) === '0') {
            return new \WP_Error('wpsso_account_inactive', 'This account has been deactivated by the identity provider.');
        }
        $expires = (int) get_user_meta($user->ID, '_wpsso_guest_expires', true);
        if ($expires && $expires <= time()) {
            return new \WP_Error('wpsso_guest_expired', 'This guest account has expired.');
        }
        if (get_user_meta($user->ID, '_wpsso_moderation', true) === 'pending') {
            return new \WP_Error('wpsso_approval_pending', 'An administrator must approve this account before login.');
        }
        if (get_user_meta($user->ID, '_wpsso_moderation', true) === 'blocked') {
            return new \WP_Error('wpsso_account_blocked', 'An administrator has blocked this account.');
        }
        return $user;
    }

    public static function account_allowed($allowed, $user, array $app, array $attributes) {
        if (is_wp_error($allowed) || !$allowed || !($user instanceof \WP_User)) {
            return $allowed;
        }
        $checked = self::account_check($user, $app, $attributes);
        return is_wp_error($checked) ? $checked : $allowed;
    }

    public static function local_account_check($user, $username = '', $password = '') {
        return self::account_check($user);
    }

    public static function moderation_profile($user): void {
        if (!current_user_can('manage_options') || !current_user_can('edit_user', $user->ID) || !self::enabled('moderation')) {
            return;
        }
        $status = get_user_meta($user->ID, '_wpsso_moderation', true);
        echo '<h2>SSO account moderation</h2><table class="form-table"><tr><th><label for="wpsso-moderation">Approval</label></th><td>';
        wp_nonce_field('wpsso_moderate_' . $user->ID, 'wpsso_moderation_nonce');
        echo '<select id="wpsso-moderation" name="wpsso_moderation">';
        foreach (['' => 'No moderation policy', 'pending' => 'Pending approval', 'approved' => 'Approved', 'blocked' => 'Blocked'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Approval never changes WordPress roles.</p></td></tr></table>';
    }

    public static function moderation_save(int $user_id): void {
        if (!self::enabled('moderation') || !current_user_can('manage_options') || !current_user_can('edit_user', $user_id) ||
            empty($_POST['wpsso_moderation_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpsso_moderation_nonce'])), 'wpsso_moderate_' . $user_id)) {
            return;
        }
        $status = sanitize_key($_POST['wpsso_moderation'] ?? '');
        if (!in_array($status, ['', 'pending', 'approved', 'blocked'], true)) {
            return;
        }
        update_user_meta($user_id, '_wpsso_moderation', $status);
        if (in_array($status, ['pending', 'blocked'], true)) {
            \WP_Session_Tokens::get_instance($user_id)->destroy_all();
        }
        Audit::record('moderation', '', get_user_by('id', $user_id), 'wpsso_moderation_' . ($status ?: 'cleared'));
    }

    public static function avatar(array $args, $id_or_email): array {
        if (!self::enabled('avatar')) {
            return $args;
        }
        $user = null;
        if ($id_or_email instanceof \WP_User) {
            $user = $id_or_email;
        } elseif (is_numeric($id_or_email)) {
            $user = get_user_by('id', (int) $id_or_email);
        } elseif (is_string($id_or_email)) {
            $user = get_user_by('email', $id_or_email);
        } elseif ($id_or_email instanceof \WP_Comment && $id_or_email->user_id) {
            $user = get_user_by('id', $id_or_email->user_id);
        }
        if ($user) {
            $url = (string) get_user_meta($user->ID, '_wpsso_avatar', true);
            if (strpos($url, 'https://') === 0 && wp_http_validate_url($url)) {
                $args['url'] = $url;
                $args['found_avatar'] = true;
            }
        }
        return $args;
    }

    public static function attribute_redirect(string $url, array $app, array $attributes, $user): string {
        if (!self::enabled('attribute_redirect')) {
            return $url;
        }
        foreach ($app['redirect_rules'] ?? [] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $actual = self::claim($attributes, (string) ($rule['claim'] ?? ''));
            if (is_scalar($actual) && isset($rule['equals']) && (string) $actual === (string) $rule['equals']) {
                return Redirect::validate((string) ($rule['url'] ?? ''), $url);
            }
        }
        return $url;
    }

    private static function claim(array $attributes, string $path) {
        if (array_key_exists($path, $attributes)) {
            return $attributes[$path];
        }
        $value = $attributes;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** JWT SSO uses a real OIDC form_post challenge, rather than a replayable URL token. */
    public static function jwt_route(): void {
        $action = isset($_GET['wpsso_action']) && is_string($_GET['wpsso_action']) ? sanitize_key($_GET['wpsso_action']) : '';
        if (!in_array($action, ['jwt_start', 'jwt_callback'], true)) {
            return;
        }
        if (!self::enabled('jwt_sso') || !is_ssl()) {
            self::fail(new \WP_Error('wpsso_jwt_disabled', 'JWT SSO is unavailable.'));
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        if ($action === 'jwt_start') {
            $id = isset($_GET['app']) && is_string($_GET['app']) ? sanitize_key($_GET['app']) : '';
            $app = Config::app($id);
            if (!$app || empty($app['enabled']) || empty($app['jwt_sso']) || empty($app['authorize_url']) || ($app['protocol'] ?? '') !== 'oidc') {
                self::fail(new \WP_Error('wpsso_jwt_app', 'Choose an enabled OpenID provider with JWT SSO enabled.'));
            }
            $state = bin2hex(random_bytes(32));
            $binding = bin2hex(random_bytes(32));
            $nonce = bin2hex(random_bytes(32));
            $redirect = isset($_GET['redirect_to']) && is_string($_GET['redirect_to']) ? wp_unslash($_GET['redirect_to']) : '';
            set_transient('wpsso_jwt_state_' . hash('sha256', $state), ['app' => $id, 'binding' => hash('sha256', $binding), 'nonce' => $nonce,
                'redirect' => Redirect::validate($redirect, home_url('/')), 'expires' => time() + 300], 300);
            setcookie('__Secure-wpsso-jwt', $binding, ['expires' => time() + 300, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'None']);
            $target = add_query_arg(['client_id' => $app['client_id'], 'response_type' => 'id_token', 'response_mode' => 'form_post',
                'scope' => 'openid', 'redirect_uri' => home_url('/?wpsso_action=jwt_callback'), 'state' => $state, 'nonce' => $nonce], $app['authorize_url']);
            wp_redirect($target, 302, 'WPSSO');
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($_POST['state'] ?? null) || !is_string($_POST['id_token'] ?? null)) {
            self::fail(new \WP_Error('wpsso_jwt_request', 'JWT callback requires a form POST containing state and id_token.'));
        }
        $state = wp_unslash($_POST['state']);
        $key = 'wpsso_jwt_state_' . hash('sha256', $state);
        $flow = get_transient($key);
        $binding = isset($_COOKIE['__Secure-wpsso-jwt']) && is_string($_COOKIE['__Secure-wpsso-jwt']) ? $_COOKIE['__Secure-wpsso-jwt'] : '';
        if (!is_array($flow) || $flow['expires'] < time() || !hash_equals($flow['binding'], hash('sha256', $binding))) {
            self::fail(new \WP_Error('wpsso_state', 'The JWT challenge expired or belongs to another browser.'));
        }
        if (!self::consume('jwt-state', $state, 300)) {
            self::fail(new \WP_Error('wpsso_replay', 'This JWT challenge has already been used.'));
        }
        delete_transient($key);
        setcookie('__Secure-wpsso-jwt', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'None']);
        $app = Config::app($flow['app']);
        if (!$app || empty($app['enabled']) || empty($app['jwt_sso'])) {
            self::fail(new \WP_Error('wpsso_jwt_app', 'This JWT provider is disabled.'));
        }
        $claims = Jwt::validate(wp_unslash($_POST['id_token']), $app, ['nonce' => $flow['nonce']]);
        if (is_wp_error($claims)) {
            self::fail($claims, $app['id']);
        }
        if (empty($claims['jti']) || !is_string($claims['jti']) || ($claims['exp'] ?? 0) > time() + 300 || !self::consume('jwt', $claims['iss'] . '|' . $claims['jti'], 600)) {
            self::fail(new \WP_Error('wpsso_replay', 'A unique jti and a JWT exp within five minutes are required.'), $app['id']);
        }
        $user = Accounts::resolve($app, $claims, []);
        self::session($user, $app, $claims);
        $redirect = apply_filters('wpsso_login_redirect', $flow['redirect'], $app, $claims, $user);
        wp_redirect(Redirect::validate($redirect, home_url('/')), 302, 'WPSSO');
        exit;
    }

    private static function session($user, array $app, array $attributes): void {
        $user = apply_filters('wpsso_pre_authenticate', $user, $app, $attributes);
        if (is_wp_error($user) || !($user instanceof \WP_User)) {
            self::fail(is_wp_error($user) ? $user : new \WP_Error('wpsso_account', 'Unable to resolve this identity.'), $app['id']);
        }
        Auth::remember_sid($user->ID, $app, $attributes);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, true);
        update_user_meta($user->ID, '_wpsso_app', $app['id']);
        do_action('wp_login', $user->user_login, $user);
        do_action('wpsso_authenticated', $user, $app, $attributes);
        Audit::record('success', $app['id'], $user);
    }

    private static function fail(\WP_Error $error, string $app = ''): void {
        Audit::record('failure', $app, null, $error->get_error_code());
        wp_die(esc_html($error->get_error_message()), 'SSO login failed', ['response' => 403]);
    }

    public static function consume(string $purpose, string $id, int $ttl): bool {
        $name = 'wpsso_claim_' . hash('sha256', $purpose . '|' . $id);
        if (!add_option($name, time() + $ttl, '', false)) {
            return false;
        }
        wp_schedule_single_event(time() + $ttl, 'wpsso_expire_claim', [$name]);
        return true;
    }

    public static function expire_claim(string $name): void {
        if (preg_match('/^wpsso_claim_[a-f0-9]{64}$/D', $name) && (int) get_option($name, PHP_INT_MAX) <= time()) {
            delete_option($name);
        }
    }

    private static function origin(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '');
    }

    private static function shared_policy(): ?array {
        if (!self::enabled('shared_sso') || !is_ssl()) {
            return null;
        }
        $domain = ltrim(strtolower((string) Config::get('shared_cookie_domain', '')), '.');
        $secret = (string) Config::get('shared_secret', '');
        $sites = (array) Config::get('shared_sites', []);
        $origin = self::origin(home_url('/'));
        if (strlen($secret) < 32 || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/D', $domain) || count(explode('.', $domain)) < 2 || count($sites) < 2 || !in_array($origin, $sites, true)) {
            return null;
        }
        foreach ($sites as $site) {
            $host = wp_parse_url($site, PHP_URL_HOST);
            if (self::origin($site) !== $site || !$host || ($host !== $domain && !str_ends_with($host, '.' . $domain))) {
                return null;
            }
        }
        return ['domain' => $domain, 'secret' => $secret, 'sites' => $sites, 'origin' => $origin];
    }

    public static function shared_issue($user, array $app, array $attributes): void {
        $policy = self::shared_policy();
        if (!$policy || empty($app['shared_sso']) || headers_sent()) {
            return;
        }
        // An assertion lives for one minute, never contains OAuth credentials, and is one-use at each relying site.
        $paths = array_merge(['sub', 'sid', $app['subject_attribute'] ?? 'sub', $app['verified_attribute'] ?? 'email_verified'],
            array_values($app['mapping'] ?? []), array_values($app['custom_mapping'] ?? []));
        $safe_attributes = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || preg_match('/token|secret|password|credential/i', $path)) {
                continue;
            }
            $value = self::claim($attributes, $path);
            if ($value !== null) {
                $safe_attributes[$path] = $value;
            }
        }
        $payload = ['v' => 1, 'source' => $policy['origin'], 'aud' => $policy['sites'], 'app' => $app['id'],
            'iss' => $app['issuer'], 'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(24)), 'attributes' => $safe_attributes];
        $json = wp_json_encode($payload);
        if (!is_string($json) || strlen($json) > 2400) {
            return;
        }
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $token = $encoded . '.' . hash_hmac('sha256', $encoded, $policy['secret']);
        setcookie(self::SHARED_COOKIE, $token, ['expires' => time() + 60, 'path' => '/', 'domain' => $policy['domain'], 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }

    public static function shared_consume(): void {
        if (is_user_logged_in() || is_admin() || !empty($_GET['wpsso_action']) || !isset($_COOKIE[self::SHARED_COOKIE]) || !is_string($_COOKIE[self::SHARED_COOKIE])) {
            return;
        }
        $policy = self::shared_policy();
        if (!$policy) {
            return;
        }
        $token = $_COOKIE[self::SHARED_COOKIE];
        if (strlen($token) > 4000 || substr_count($token, '.') !== 1) {
            return;
        }
        [$encoded, $signature] = explode('.', $token, 2);
        if (!hash_equals(hash_hmac('sha256', $encoded, $policy['secret']), $signature)) {
            return;
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true, 24) : null;
        if (!is_array($claims) || ($claims['v'] ?? null) !== 1 || !is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null) ||
            $claims['exp'] <= time() || $claims['iat'] > time() + 5 || $claims['exp'] - $claims['iat'] > 60 ||
            empty($claims['jti']) || !is_string($claims['jti']) || !in_array($claims['source'] ?? '', $policy['sites'], true) ||
            $claims['source'] === $policy['origin'] || !in_array($policy['origin'], (array) ($claims['aud'] ?? []), true) || !is_array($claims['attributes'] ?? null)) {
            return;
        }
        $app = Config::app((string) ($claims['app'] ?? ''));
        if (!$app || empty($app['enabled']) || empty($app['shared_sso']) || !hash_equals((string) $app['issuer'], (string) ($claims['iss'] ?? '')) ||
            !self::consume('shared-' . $policy['origin'], $claims['jti'], 120)) {
            return;
        }
        $user = Accounts::resolve($app, $claims['attributes'], []);
        $user = apply_filters('wpsso_pre_authenticate', $user, $app, $claims['attributes']);
        if (is_wp_error($user)) {
            Audit::record('failure', $app['id'], null, $user->get_error_code());
            return;
        }
        // Do not issue a fresh shared assertion while consuming one; that would turn one minute into an indefinite trust chain.
        Auth::remember_sid($user->ID, $app, $claims['attributes']);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, true);
        update_user_meta($user->ID, '_wpsso_app', $app['id']);
        do_action('wp_login', $user->user_login, $user);
        Audit::record('success', $app['id'], $user, 'wpsso_shared_login');
        wp_safe_redirect(Redirect::current());
        exit;
    }

    public static function shared_clear(): void {
        $policy = self::shared_policy();
        if ($policy && !headers_sent()) {
            setcookie(self::SHARED_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'domain' => $policy['domain'], 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    public static function cleanup(): void {
        $users = get_users(['meta_key' => '_wpsso_guest_expires', 'meta_value' => time(), 'meta_compare' => '<=', 'meta_type' => 'NUMERIC', 'number' => 100]);
        foreach ($users as $user) {
            if (self::privileged($user->ID) || (int) get_user_meta($user->ID, '_wpsso_guest_expires', true) <= 0) {
                continue;
            }
            \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            // Retain an expired guest that authored content, rather than deleting their work.
            if ((int) count_user_posts($user->ID, 'post', false) > 0 || get_posts(['author' => $user->ID, 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids'])) {
                continue;
            }
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user($user->ID);
        }
        // Release a dead worker's provisioning mutex; the live operation cannot run longer than this lease.
        $lock = (int) get_option('wpsso_scim_write_lock', 0);
        if ($lock && $lock < time() - 600) {
            delete_option('wpsso_scim_write_lock');
        }
    }
}
