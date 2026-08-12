<?php
/**
 * Unauthenticated Password Recovery Disabled
 * Public resets are prohibited. Password updates must be performed while logged in
 * or requested via the agency administrator.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
header("Location: " . APP_URL . "/login.php");
exit;
