<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/token_manager.php';
require_once __DIR__ . '/../../cron/sync_all.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();

    // 1. Completely WIPE the entire ad_data_cache table to remove any legacy mixed records!
    $db->exec("TRUNCATE TABLE ad_data_cache");

    // 2. Fetch all active clients with valid ad account IDs
    $stmt = $db->query("SELECT * FROM clients WHERE active = 1 AND meta_ad_account_id IS NOT NULL AND meta_ad_account_id != ''");
    $clients = $stmt->fetchAll();

    $results = [];
    foreach ($clients as $client) {
        // Trigger a fresh 90-day sync for each client using their isolated ad account ID
        $res = syncClientData($client);
        $results[$client['business_name']] = [
            'id' => $client['id'],
            'ad_account' => $client['meta_ad_account_id'],
            'sync_status' => $res
        ];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Completely wiped old cache and resynced all clients cleanly with isolated ad account IDs.',
        'details' => $results
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
