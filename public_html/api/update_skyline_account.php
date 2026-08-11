<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../cron/sync_all.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    
    // Update Skyline Crest (client id 3) ad account ID
    $stmt = $db->prepare("UPDATE clients SET meta_ad_account_id = 'act_1568346498205053', currency = 'AED' WHERE id = 3");
    $stmt->execute();

    // Clear old cache for client 3
    $del = $db->prepare("DELETE FROM ad_data_cache WHERE client_id = 3");
    $del->execute();

    // Fetch updated client row
    $cStmt = $db->prepare("SELECT * FROM clients WHERE id = 3");
    $cStmt->execute();
    $client = $cStmt->fetch();

    // Trigger immediate sync
    $syncResult = syncClientData($client);

    echo json_encode([
        'success' => true,
        'client' => $client['business_name'],
        'new_ad_account_id' => $client['meta_ad_account_id'],
        'sync_result' => $syncResult
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
