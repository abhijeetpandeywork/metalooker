<?php
/**
 * Authentication and Session Management System
 *
 * Provides helper functions for session control, authentication, CSRF validation,
 * brute-force rate limiting, and activity logging.
 *
 * @package MetaPanel\Includes
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Verifies user credentials and initializes a secure session on success.
 *
 * @param string $email User email
 * @param string $password User raw password
 * @return array Array containing success boolean and error message if failed
 */
function login(string $email, string $password): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!checkBruteForce($ip)) {
        return [
            'success' => false,
            'message' => 'Too many failed login attempts. Please wait 15 minutes before trying again.'
        ];
    }

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['user_id']   = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();

            // If user is a client, fetch their associated client_id
            if ($user['role'] === 'client') {
                $clientStmt = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
                $clientStmt->execute([$user['id']]);
                $client = $clientStmt->fetch();
                if ($client) {
                    $_SESSION['client_id'] = (int)$client['id'];
                }
            }

            logActivity((int)$user['id'], 'User logged in successfully');
            return ['success' => true, 'user' => $user];
        }

        recordFailedLogin($ip);
        logActivity(null, "Failed login attempt for email: {$email}");

        return [
            'success' => false,
            'message' => 'Invalid email address or password.'
        ];
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'A system error occurred during authentication.'
        ];
    }
}

/**
 * Destroys current user session and logs out.
 *
 * @return void
 */
function logout(): void {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'User logged out');
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

/**
 * Checks if user is currently authenticated and session has not expired (8 hours max).
 *
 * @return bool
 */
function isLoggedIn(): bool {
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        return false;
    }

    // 8-hour session timeout check
    $maxLifetime = 8 * 3600;
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $maxLifetime)) {
        logout();
        return false;
    }

    return true;
}

/**
 * Requires user to have a specific role or set of roles; otherwise redirects to login.
 *
 * @param string|array $allowedRoles Single role string or array of allowed roles
 * @return void
 */
function requireRole($allowedRoles): void {
    if (!isLoggedIn()) {
        header("Location: " . APP_URL . "/login.php");
        exit;
    }

    $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        header("Location: " . APP_URL . "/login.php?error=unauthorized");
        exit;
    }
}

/**
 * Writes an entry to the activity_log table.
 *
 * @param int|null $userId User ID associated with the action
 * @param string $action Action description
 * @return void
 */
function logActivity(?int $userId, string $action): void {
    try {
        $db = Database::getInstance();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)");
        $stmt->execute([$userId, substr($action, 0, 250), $ip]);
    } catch (Exception $e) {
        error_log("Failed to write activity log: " . $e->getMessage());
    }
}

/**
 * Checks if IP address exceeds login rate limits (max 5 attempts per 15 minutes).
 *
 * @param string $ip Client IP address
 * @return bool True if allowed, false if blocked
 */
function checkBruteForce(string $ip): bool {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempts
            FROM activity_log
            WHERE ip = ? AND action = 'Failed login attempt'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        return ($row['attempts'] ?? 0) < 5;
    } catch (Exception $e) {
        return true; // Fallback to allowed on DB error
    }
}

/**
 * Records a failed login attempt for rate limiting.
 *
 * @param string $ip Client IP address
 * @return void
 */
function recordFailedLogin(string $ip): void {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, ip) VALUES (NULL, 'Failed login attempt', ?)");
        $stmt->execute([$ip]);
    } catch (Exception $e) {
        error_log("Failed to record failed login: " . $e->getMessage());
    }
}

/**
 * Generates or retrieves a CSRF token for the active session.
 *
 * @return string CSRF token hex string
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token against session token.
 *
 * @param string|null $token Submitted CSRF token
 * @return bool True if valid, false otherwise
 */
function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
