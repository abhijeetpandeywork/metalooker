<?php
/**
 * Root Application Router
 *
 * Routes incoming requests based on user authentication state and role.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    header("Location: " . APP_URL . "/login.php");
    exit;
}

$role = $_SESSION['user_role'] ?? 'client';

if ($role === 'super_admin' || $role === 'team_member') {
    header("Location: " . APP_URL . "/admin/index.php");
    exit;
}

header("Location: " . APP_URL . "/dashboard.php");
exit;
