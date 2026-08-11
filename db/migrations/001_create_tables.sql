-- MetaPanel Database Migration Script
-- Version: 1.0
-- Engine: InnoDB, Charset: utf8mb4

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS sync_logs;
DROP TABLE IF EXISTS team_client_access;
DROP TABLE IF EXISTS ad_data_cache;
DROP TABLE IF EXISTS dashboard_config;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(191) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'team_member', 'client') DEFAULT 'client',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Clients Table
CREATE TABLE clients (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ad Data Cache Table
CREATE TABLE ad_data_cache (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Dashboard Config Table
CREATE TABLE dashboard_config (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Sync Logs Table
CREATE TABLE sync_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('success', 'error', 'warning') DEFAULT 'success',
    rows_inserted INT UNSIGNED DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    CONSTRAINT fk_sync_logs_client_id FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Team Client Access Table
CREATE TABLE team_client_access (
    user_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, client_id),
    CONSTRAINT fk_tca_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tca_client_id FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Activity Log Table
CREATE TABLE activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_log_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
