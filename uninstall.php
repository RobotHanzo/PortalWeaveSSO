<?php
/** WordPress invokes this only after an authorized plugin deletion. */
defined('WP_UNINSTALL_PLUGIN') || exit;
require_once __DIR__ . '/includes/class-cleanup.php';
\WPSSO\Cleanup::run();
