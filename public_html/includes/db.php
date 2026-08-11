<?php
/**
 * Database Singleton Helper
 *
 * Provides a thread-safe PDO instance connected to MySQL database with automatic SQLite fallback,
 * auto-migration capability, and system settings manager.
 *
 * @package MetaPanel\Includes
 */

require_once __DIR__ . '/config.php';

class Database {
    /**
     * @var PDO|null Cached PDO connection instance
     */
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {}

    /**
     * Private clone method to prevent cloning
     */
    private function __clone() {}

    /**
     * Returns the singleton PDO database connection instance.
     *
     * @return PDO
     * @throws Exception If database connection fails
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
                PDO::ATTR_TIMEOUT            => 60
            ];

            // 1. Try MySQL Connection
            try {
                $dsn = sprintf(
                    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                    DB_HOST,
                    DB_PORT,
                    DB_NAME
                );
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                self::ensureTables(self::$instance);
            } catch (PDOException $e) {
                // 2. Automatic Fallback to Embedded SQLite
                error_log("MySQL Connection Failed (" . $e->getMessage() . "). Falling back to SQLite.");
                $dbDir = __DIR__ . '/storage';
                if (!is_dir($dbDir)) {
                    @mkdir($dbDir, 0777, true);
                }
                @chmod($dbDir, 0777);
                $sqlitePath = $dbDir . '/metapanel.sqlite';
                if (file_exists($sqlitePath)) {
                    @chmod($sqlitePath, 0777);
                }
                $sqliteDsn = "sqlite:" . $sqlitePath;

                self::$instance = new PDO($sqliteDsn, null, null, $options);
                self::$instance->exec("PRAGMA foreign_keys = ON;");
                self::$instance->exec("PRAGMA busy_timeout = 60000;");
                self::$instance->exec("PRAGMA journal_mode = WAL;");
                self::$instance->exec("PRAGMA synchronous = NORMAL;");

                self::ensureSqliteSchema(self::$instance);
            }
        }
        return self::$instance;
    }

    /**
     * Ensures MySQL system tables exist and migrates data from SQLite if fresh.
     */
    private static function ensureTables(PDO $pdo): void {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(191) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('super_admin', 'team_member', 'client') DEFAULT 'client',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                business_name VARCHAR(200) NOT NULL,
                logo_path VARCHAR(400) DEFAULT NULL,
                brand_color VARCHAR(7) DEFAULT '#0F2D55',
                currency CHAR(3) DEFAULT 'INR',
                meta_ad_account_id VARCHAR(60) DEFAULT NULL,
                meta_access_token TEXT DEFAULT NULL,
                token_expires_at DATETIME DEFAULT NULL,
                active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_clients_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS ad_data_cache (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id INT UNSIGNED NOT NULL,
                level ENUM('account', 'campaign', 'adset', 'ad') NOT NULL,
                object_id VARCHAR(80) NOT NULL,
                object_name VARCHAR(300) NOT NULL,
                date_start DATE NOT NULL,
                date_stop DATE NOT NULL,
                impressions INT UNSIGNED DEFAULT 0,
                reach INT UNSIGNED DEFAULT 0,
                clicks INT UNSIGNED DEFAULT 0,
                spend DECIMAL(12,2) DEFAULT 0.00,
                cpc DECIMAL(10,4) DEFAULT 0.0000,
                ctr DECIMAL(8,4) DEFAULT 0.0000,
                cpm DECIMAL(10,4) DEFAULT 0.0000,
                conversions INT UNSIGNED DEFAULT 0,
                cost_per_result DECIMAL(10,4) DEFAULT 0.0000,
                roas DECIMAL(10,4) DEFAULT 0.0000,
                frequency DECIMAL(8,4) DEFAULT 0.0000,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ad_cache (client_id, level, object_id, date_start, date_stop),
                CONSTRAINT fk_ad_cache_client_id FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_config (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id INT UNSIGNED NOT NULL UNIQUE,
                widgets_json JSON DEFAULT NULL,
                default_range ENUM('last_7', 'last_30', 'this_month', 'last_month') DEFAULT 'last_30',
                show_spend TINYINT(1) DEFAULT 1,
                show_roas TINYINT(1) DEFAULT 1,
                show_leads TINYINT(1) DEFAULT 1,
                show_cpc TINYINT(1) DEFAULT 1,
                show_ctr TINYINT(1) DEFAULT 1,
                show_impressions TINYINT(1) DEFAULT 1,
                show_campaigns TINYINT(1) DEFAULT 1,
                show_adsets TINYINT(1) DEFAULT 1,
                report_title VARCHAR(200) DEFAULT 'My Ads Performance',
                CONSTRAINT fk_dash_config_client_id FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS sync_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id INT UNSIGNED NOT NULL,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status ENUM('success', 'error', 'warning') DEFAULT 'success',
                rows_inserted INT UNSIGNED DEFAULT 0,
                error_message TEXT DEFAULT NULL,
                CONSTRAINT fk_sync_logs_client_id FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS team_client_access (
                user_id INT UNSIGNED NOT NULL,
                client_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (user_id, client_id),
                CONSTRAINT fk_tca_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_tca_client_id FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED DEFAULT NULL,
                action VARCHAR(255) NOT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_act_log_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Seed Super Admin
            $adminHash = password_hash('Change@123', PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Digital Rubix Admin', 'admin@digitalrubix.com', '{$adminHash}', 'super_admin') ON DUPLICATE KEY UPDATE name=VALUES(name)");

            // Automatically migrate clients & data from SQLite file if present
            $sqliteFile = __DIR__ . '/storage/metapanel.sqlite';
            if (file_exists($sqliteFile)) {
                try {
                    $sPdo = new PDO("sqlite:" . $sqliteFile);
                    $sPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                    $clients = $sPdo->query("SELECT * FROM clients")->fetchAll();
                    foreach ($clients as $c) {
                        $stmt = $pdo->prepare("INSERT INTO clients (id, user_id, business_name, logo_path, brand_color, currency, meta_ad_account_id, meta_access_token, token_expires_at, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE business_name=VALUES(business_name)");
                        $stmt->execute([$c['id'], $c['user_id'], $c['business_name'], $c['logo_path'], $c['brand_color'], $c['currency'], $c['meta_ad_account_id'], $c['meta_access_token'], $c['token_expires_at'], $c['active'], $c['created_at']]);
                    }

                    $cache = $sPdo->query("SELECT * FROM ad_data_cache")->fetchAll();
                    foreach ($cache as $r) {
                        $stmt = $pdo->prepare("INSERT INTO ad_data_cache (client_id, level, object_id, object_name, date_start, date_stop, impressions, reach, clicks, spend, cpc, ctr, cpm, conversions, cost_per_result, roas, frequency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE object_name=VALUES(object_name)");
                        $stmt->execute([$r['client_id'], $r['level'], $r['object_id'], $r['object_name'], $r['date_start'], $r['date_stop'], $r['impressions'], $r['reach'], $r['clicks'], $r['spend'], $r['cpc'], $r['ctr'], $r['cpm'], $r['conversions'], $r['cost_per_result'], $r['roas'], $r['frequency']]);
                    }
                } catch (Exception $ex) {
                    error_log("SQLite migration to MySQL failed: " . $ex->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("ensureTables failed: " . $e->getMessage());
        }
    }

    /**
     * Ensures SQLite database tables exist.
     */
    private static function ensureSqliteSchema(PDO $pdo): void {
        $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $rows = $check ? $check->fetchAll() : [];
        if ($check) {
            $check->closeCursor();
            $check = null;
        }

        if (empty($rows)) {
            $sqliteSchema = "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'client',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                business_name TEXT NOT NULL,
                logo_path TEXT DEFAULT NULL,
                brand_color TEXT DEFAULT '#0F2D55',
                currency TEXT DEFAULT 'INR',
                meta_ad_account_id TEXT DEFAULT NULL,
                meta_access_token TEXT DEFAULT NULL,
                token_expires_at DATETIME DEFAULT NULL,
                active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS ad_data_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                level TEXT NOT NULL,
                object_id TEXT NOT NULL,
                object_name TEXT NOT NULL,
                date_start DATE NOT NULL,
                date_stop DATE NOT NULL,
                impressions INTEGER DEFAULT 0,
                reach INTEGER DEFAULT 0,
                clicks INTEGER DEFAULT 0,
                spend REAL DEFAULT 0.00,
                cpc REAL DEFAULT 0.0000,
                ctr REAL DEFAULT 0.0000,
                cpm REAL DEFAULT 0.0000,
                conversions INTEGER DEFAULT 0,
                cost_per_result REAL DEFAULT 0.0000,
                roas REAL DEFAULT 0.0000,
                frequency REAL DEFAULT 0.0000,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (client_id, level, object_id, date_start, date_stop),
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS dashboard_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL UNIQUE,
                widgets_json TEXT DEFAULT NULL,
                default_range TEXT DEFAULT 'last_30',
                show_spend INTEGER DEFAULT 1,
                show_roas INTEGER DEFAULT 1,
                show_leads INTEGER DEFAULT 1,
                show_cpc INTEGER DEFAULT 1,
                show_ctr INTEGER DEFAULT 1,
                show_impressions INTEGER DEFAULT 1,
                show_campaigns INTEGER DEFAULT 1,
                show_adsets INTEGER DEFAULT 1,
                report_title TEXT DEFAULT 'My Ads Performance',
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS sync_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT DEFAULT 'success',
                rows_inserted INTEGER DEFAULT 0,
                error_message TEXT DEFAULT NULL,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS team_client_access (
                user_id INTEGER NOT NULL,
                client_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, client_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER DEFAULT NULL,
                action TEXT NOT NULL,
                ip TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT DEFAULT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            ";
            $pdo->exec($sqliteSchema);

            // Seed Super Admin User
            $adminHash = password_hash('Change@123', PASSWORD_BCRYPT, ['cost' => 12]);
            $seedStmt = $pdo->prepare("INSERT OR IGNORE INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin')");
            $seedStmt->execute(['Digital Rubix Admin', 'admin@digitalrubix.com', $adminHash]);
        }
    }
}

/**
 * Gets a global system setting from Database.
 */
function getSystemSetting(string $key, ?string $default = null): ?string {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return ($row && $row['setting_value'] !== null && $row['setting_value'] !== '') ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Sets a global system setting in Database.
 */
function setSystemSetting(string $key, string $value): bool {
    try {
        $db = Database::getInstance();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $db->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        } else {
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
        }
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        return false;
    }
}
