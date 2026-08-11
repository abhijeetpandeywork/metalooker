<?php
/**
 * Bagnomy Direct Live Sync & Verification Script
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../cron/sync_all.php')) {
    require_once __DIR__ . '/../cron/sync_all.php';
} else {
    require_once __DIR__ . '/../../cron/sync_all.php';
}

$db = Database::getInstance();
$stmt = $db->prepare("SELECT * FROM clients WHERE id = 2 LIMIT 1");
$stmt->execute();
$client = $stmt->fetch();

if (!$client) {
    echo json_encode(['error' => 'Client ID 2 not found']);
    exit;
}

$res = syncClientData($client);

// Check cached rows count
$countStmt = $db->prepare("SELECT level, COUNT(*) as cnt, SUM(spend) as total_spend FROM ad_data_cache WHERE client_id = 2 GROUP BY level");
$countStmt->execute();
$summary = $countStmt->fetchAll();

echo json_encode([
    'sync_result' => $res,
    'cached_summary' => $summary
], JSON_PRETTY_PRINT);
