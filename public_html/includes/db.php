<?php
/**
 * Database Singleton Helper
 *
 * Provides a thread-safe PDO instance connected to MySQL database with automatic SQLite fallback
 * and auto-migration capability.
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
            } catch (PDOException $e) {
                // 2. Automatic Fallback to Embedded SQLite
                error_log("MySQL Connection Failed (" . $e->getMessage() . "). Falling back to SQLite.");
                $dbDir = dirname(__DIR__, 2) . '/db';
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
                $sqlitePath = $dbDir . '/metapanel.sqlite';
                $sqliteDsn  = "sqlite:" . $sqlitePath;

                self::$instance = new PDO($sqliteDsn, null, null, $options);
                self::$instance->exec("PRAGMA foreign_keys = ON;");

                self::ensureSqliteSchema(self::$instance);
            }
        }
        return self::$instance;
    }

    /**
     * Ensures SQLite database tables and seed admin exist.
     *
     * @param PDO $pdo SQLite PDO instance
     * @return void
     */
    private static function ensureSqliteSchema(PDO $pdo): void {
        $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        if (!$check->fetch()) {
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
            ";
            $pdo->exec($sqliteSchema);

            // Seed Super Admin User
            $adminHash = password_hash('Change@123', PASSWORD_BCRYPT, ['cost' => 12]);
            $seedStmt = $pdo->prepare("INSERT OR IGNORE INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin')");
            $seedStmt->execute(['Digital Rubix Admin', 'admin@digitalrubix.com', $adminHash]);
        }
    }
}
