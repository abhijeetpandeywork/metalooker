<?php
/**
 * Configuration Loader and Initializer
 *
 * Loads environment variables from .env file and defines global application constants.
 * Enforces basic security headers and session initialization.
 *
 * @package MetaPanel\Includes
 */

// Set strict error reporting for production/dev handling
error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * Helper to load environment variables from .env file
 *
 * @param string $path Path to .env file
 * @return array Parsed key-value pairs
 */
function loadEnv(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Strip surrounding quotes if present
            if ((strpos($value, '"') === 0 && substr($value, -1) === '"') ||
                (strpos($value, "'") === 0 && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            $env[$key] = $value;
        }
    }
    return $env;
}

// Locate .env in project root
$rootDir = dirname(__DIR__, 2);
$envVars = loadEnv($rootDir . '/.env');

// Define constants
define('DB_HOST', $envVars['DB_HOST'] ?? '127.0.0.1');
define('DB_NAME', $envVars['DB_NAME'] ?? 'metapanel_db');
define('DB_USER', $envVars['DB_USER'] ?? 'root');
define('DB_PASS', $envVars['DB_PASS'] ?? '');
define('DB_PORT', $envVars['DB_PORT'] ?? '3306');

define('APP_ENV', $envVars['APP_ENV'] ?? 'production');
define('APP_URL', rtrim($envVars['APP_URL'] ?? 'http://localhost', '/'));
define('AES_KEY', $envVars['AES_KEY'] ?? 'default_insecure_32_character_key!');

define('META_APP_ID', $envVars['META_APP_ID'] ?? '');
define('META_APP_SECRET', $envVars['META_APP_SECRET'] ?? '');
define('META_GRAPH_VERSION', $envVars['META_GRAPH_VERSION'] ?? 'v21.0');
define('MOCK_META_API', filter_var($envVars['MOCK_META_API'] ?? false, FILTER_VALIDATE_BOOLEAN));

// Security Headers
if (!headers_sent()) {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}
