<?php
/**
 * Global Configuration and Constants Loader
 *
 * Reads environment variables from .env file and dynamic system_settings database table,
 * sets PHP runtime configs, defines application constants, and sets up security headers.
 *
 * @package MetaPanel\Includes
 */

// Error Reporting Configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Loads key=value pairs from .env file into array.
 *
 * @param string $path Path to .env file
 * @return array Key-value map of environment variables
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

// Define database constants
define('DB_HOST', $envVars['DB_HOST'] ?? '127.0.0.1');
define('DB_NAME', $envVars['DB_NAME'] ?? 'metapanel_db');
define('DB_USER', $envVars['DB_USER'] ?? 'root');
define('DB_PASS', $envVars['DB_PASS'] ?? '');
define('DB_PORT', $envVars['DB_PORT'] ?? '3306');

define('APP_ENV', $envVars['APP_ENV'] ?? 'production');
$envAppUrl = rtrim($envVars['APP_URL'] ?? '', '/');
if (empty($envAppUrl) || str_contains($envAppUrl, 'localhost')) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'metalooker.digitalrubix.site';
    define('APP_URL', "{$scheme}://{$host}");
} else {
    define('APP_URL', $envAppUrl);
}
define('AES_KEY', $envVars['AES_KEY'] ?? 'default_insecure_32_character_key!');

// Dynamic Meta API Settings (Database System Settings with .env Fallback)
require_once __DIR__ . '/db.php';

$metaAppId     = getSystemSetting('meta_app_id', $envVars['META_APP_ID'] ?? '');
$metaAppSecret = getSystemSetting('meta_app_secret', $envVars['META_APP_SECRET'] ?? '');
$mockModeVal   = getSystemSetting('mock_meta_api', $envVars['MOCK_META_API'] ?? 'false');

define('META_APP_ID', $metaAppId);
define('META_APP_SECRET', $metaAppSecret);
define('META_GRAPH_VERSION', $envVars['META_GRAPH_VERSION'] ?? 'v21.0');
define('MOCK_META_API', filter_var($mockModeVal, FILTER_VALIDATE_BOOLEAN));

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
