<?php
/**
 * Database Singleton Helper
 *
 * Provides a thread-safe PDO instance connected to MySQL database.
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
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // In production, avoid leaking raw db passwords/credentials
                error_log("Database Connection Error: " . $e->getMessage());
                throw new Exception("Database connection failed. Please check system logs.");
            }
        }
        return self::$instance;
    }
}
