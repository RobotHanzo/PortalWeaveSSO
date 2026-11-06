<?php
/** Local operational commands. */
namespace WPSSO;

defined('ABSPATH') || exit;

final class Cli {
    public static function register(): void {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('wpsso', self::class);
        }
    }

    /** List provider configuration status without credentials.
     *
     * ## OPTIONS
     * [--format=<format>]
     * : table, json, csv or yaml.
     */
    public function providers(array $args, array $assoc): void {
        $rows = [];
        foreach (Config::apps() as $id => $app) {
            $rows[] = ['id' => $id, 'name' => $app['name'], 'enabled' => $app['enabled'] ? 'yes' : 'no',
                'protocol' => $app['protocol'], 'grant' => $app['grant']];
        }
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['id', 'name', 'enabled', 'protocol', 'grant']);
    }

    /** Diagnose local provider configuration. Use the admin test for a real login.
     *
     * ## OPTIONS
     * [<app>]
     * : Optional provider ID.
     */
    public function diagnose(array $args): void {
        $apps = Config::apps();
        if (!empty($args[0])) {
            if (!isset($apps[$args[0]])) {
                \WP_CLI::error('Unknown provider.');
            }
            $apps = [$args[0] => $apps[$args[0]]];
        }
        $out = [];
        foreach ($apps as $id => $app) {
            $out[$id] = Abilities::diagnose_app($app);
        }
        \WP_CLI::line(wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Export a redacted backup. A new installation requires credentials after restore.
     *
     * ## OPTIONS
     * [<file>]
     * : Destination file. Omit to print redacted JSON.
     */
    public function export(array $args): void {
        $json = wp_json_encode(Config::redacted(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (empty($args[0])) {
            \WP_CLI::line($json);
            return;
        }
        $result = self::write_backup($args[0], $json . "\n");
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success('Redacted backup written. Client identifiers and secrets are excluded.');
    }

    /** @internal Write a local CLI backup without overwriting any existing destination, even in a race. */
    public static function write_backup(string $file, string $contents) {
        if ($file === '' || str_contains($file, "\0") || wp_is_stream($file)) {
            return new \WP_Error('wpsso_backup_path', 'Choose a local filesystem path for the backup.');
        }
        if (file_exists($file) || is_link($file)) {
            return new \WP_Error('wpsso_backup_exists', 'The destination already exists; choose a new backup path.');
        }
        // WP_Filesystem::put_contents() opens with "wb" and has no exclusive-create mode.
        // The CLI must never truncate a destination created between the check above and opening it.
        // A restrictive creation mask avoids a readable interval before descriptor permissions are checked.
        $previous_mask = umask(0077);
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local CLI exclusive-create semantics are unavailable in the WordPress Filesystem API.
            $stream = @fopen($file, 'x');
        } finally {
            umask($previous_mask);
        }
        if (!$stream) {
            return new \WP_Error('wpsso_backup_create', 'Cannot create the backup file.');
        }
        $success = false;
        try {
            // Set permissions on the descriptor we exclusively created, rather than a replaceable path.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fchmod -- WordPress has no API for permissions on an already-open exclusive file descriptor.
            if (!@fchmod($stream, 0600)) {
                return new \WP_Error('wpsso_backup_permissions', 'Cannot secure the backup permissions. An incomplete file may remain at the requested path.');
            }
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write through the exclusive descriptor; reopening with WP_Filesystem would lose the no-overwrite guarantee.
                $written = fwrite($stream, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    return new \WP_Error('wpsso_backup_write', 'Could not finish writing the backup. An incomplete file may remain at the requested path.');
                }
                $offset += $written;
            }
            $success = true;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the same exclusive descriptor in every success and failure path.
            $closed = fclose($stream);
        }
        return $success && $closed ? true : new \WP_Error('wpsso_backup_write', 'Could not finish writing the backup. An incomplete file may remain at the requested path.');
    }

    /** Import a JSON configuration file.
     *
     * ## OPTIONS
     * <file>
     * : A local JSON file no larger than 1 MiB.
     * [--network]
     * : Write settings for the current network.
     */
    public function import(array $args, array $assoc): void {
        $file = $args[0] ?? '';
        if (!is_file($file) || !is_readable($file) || filesize($file) > 1048576) {
            \WP_CLI::error('Choose a readable JSON file no larger than 1 MiB.');
        }
        $data = json_decode((string) file_get_contents($file), true, 32);
        if (!is_array($data)) {
            \WP_CLI::error('The file must contain a JSON settings object.');
        }
        $result = Config::import($data, isset($assoc['network']));
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success('Settings imported. Existing credentials were retained by app ID. Incomplete providers and login enforcement are disabled until credentials are configured. Run a browser SSO test before enforcing login.');
    }

    /** Set one noncredential global option using a JSON value.
     *
     * ## OPTIONS
     * <key>
     * : Setting key. Provider settings should be imported from a protected JSON file.
     * <json-value>
     * : JSON value, for example false or ["example.com"].
     * [--network]
     * : Write current-network settings.
     */
    public function set(array $args, array $assoc): void {
        $allowed = ['force_auth', 'restrict_site', 'disable_wp_login', 'single_flow', 'popup', 'login_redirect', 'logout_redirect',
            'redirect_allowlist', 'logout_confirmation', 'allowed_domains', 'blocked_domains', 'debug', 'audit_retention_days', 'abilities_enabled', 'default_app'];
        $key = $args[0] ?? '';
        if (!in_array($key, $allowed, true)) {
            \WP_CLI::error('Unsupported option. Use a protected import file for structured settings or credentials.');
        }
        $value = json_decode($args[1] ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \WP_CLI::error('Supply a valid JSON value.');
        }
        $result = Config::save([$key => $value], isset($assoc['network']));
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success('Setting updated.');
    }

    /** Show recent login transactions.
     *
     * ## OPTIONS
     * [--limit=<number>]
     * : Number of records, at most 200. Default 25.
     * [--app=<id>]
     * : Provider ID filter.
     * [--format=<format>]
     * : table, json, csv or yaml.
     */
    public function audit(array $args, array $assoc): void {
        $rows = Audit::recent(['limit' => min(200, max(1, (int) ($assoc['limit'] ?? 25))), 'app' => sanitize_key($assoc['app'] ?? '')]);
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['username', 'status', 'app', 'created_at', 'email', 'client_ip', 'navigation', 'error_code']);
    }

    /** Recover access by disabling site login enforcement; provider credentials remain encrypted. */
    public function recover(array $args, array $assoc): void {
        $network = isset($assoc['network']);
        $result = Config::save(['force_auth' => false, 'restrict_site' => false, 'disable_wp_login' => false, 'single_flow' => false], $network);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success('WordPress login access restored.');
    }
}
