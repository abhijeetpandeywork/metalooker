<?php
/**
 * Logout Route
 *
 * Terminates session and redirects to login portal.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

logout();
header("Location: " . APP_URL . "/login.php?logged_out=1");
exit;
