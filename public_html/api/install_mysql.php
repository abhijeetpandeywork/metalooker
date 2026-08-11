<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

try {
    $db = Database::getInstance();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') {
        echo json_encode(['success' => false, 'error' => 'Not connected to MySQL', 'driver' => $driver]);
        exit;
    }

    $logs = [];

    $sqlStatements = [
        "SET FOREIGN_KEY_CHECKS = 0;",
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(191) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('super_admin', 'team_member', 'client') DEFAULT 'client',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS clients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            business_name VARCHAR(200) NOT NULL,
            logo_path VARCHAR(400) DEFAULT NULL,
            brand_color VARCHAR(7) DEFAULT '#0F2D55',
            currency CHAR(3) DEFAULT 'INR',
            country_code VARCHAR(10) DEFAULT 'IN',
            country_name VARCHAR(100) DEFAULT 'India',
            meta_ad_account_id VARCHAR(60) DEFAULT NULL,
            meta_access_token TEXT DEFAULT NULL,
            token_expires_at DATETIME DEFAULT NULL,
            target_lead_value DECIMAL(10,2) DEFAULT 500.00,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS ad_data_cache (
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
            UNIQUE KEY uq_ad_cache (client_id, level, object_id, date_start, date_stop)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS dashboard_config (
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
            report_title VARCHAR(200) DEFAULT 'My Ads Performance'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS sync_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('success', 'error', 'warning') DEFAULT 'success',
            rows_inserted INT UNSIGNED DEFAULT 0,
            error_message TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS team_client_access (
            user_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS activity_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED DEFAULT NULL,
            action VARCHAR(255) NOT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "SET FOREIGN_KEY_CHECKS = 1;"
    ];

    foreach ($sqlStatements as $idx => $sql) {
        $res = $db->exec($sql);
        if ($res === false) {
            $err = $db->errorInfo();
            $logs[] = "Stmt #{$idx} failed: " . ($err[2] ?? 'Unknown error');
        } else {
            $logs[] = "Stmt #{$idx} ok";
        }
    }

    try {
        $db->exec("ALTER TABLE clients ADD COLUMN target_lead_value DECIMAL(10,2) DEFAULT 500.00");
    } catch (Exception $eCol) {}

    try {
        $db->exec("ALTER TABLE clients ADD COLUMN country_code VARCHAR(10) DEFAULT 'IN'");
    } catch (Exception $eCol2) {}

    try {
        $db->exec("CREATE INDEX idx_client_level_dates ON ad_data_cache (client_id, level, date_start)");
    } catch (Exception $eIdx1) {}

    // Seed dashboard_config for all active clients
    $allClients = $db->query("SELECT id FROM clients")->fetchAll();
    foreach ($allClients as $cl) {
        $cId = (int)$cl['id'];
        $db->exec("
            INSERT INTO dashboard_config (client_id, show_spend, show_roas, show_leads, show_cpc, show_ctr, show_impressions, show_campaigns, show_adsets, report_title)
            VALUES ({$cId}, 1, 1, 1, 1, 1, 1, 1, 1, 'My Ads Performance')
            ON DUPLICATE KEY UPDATE show_campaigns=1, show_adsets=1, show_spend=1, show_roas=1, show_leads=1, show_cpc=1, show_ctr=1, show_impressions=1;
        ");
    }

    // Ensure client currency and country for Sky Line Crest is AED
    $db->exec("UPDATE clients SET currency = 'AED', country_code = 'AE', country_name = 'United Arab Emirates' WHERE id = 6 OR business_name LIKE '%Sky Line%'");

    echo json_encode([
        'success'          => true,
        'logs'             => $logs,
        'migrated_clients' => $migratedClients,
        'migrated_rows'    => $migratedRows
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
