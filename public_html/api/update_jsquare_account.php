<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../cron/sync_all.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    
    // Find J Square client ID or update by name/id
    $cStmt = $db->prepare("SELECT id FROM clients WHERE business_name LIKE '%J Square%' OR business_name LIKE '%JSquare%' OR id = 4 LIMIT 1");
    $cStmt->execute();
    $jSquare = $cStmt->fetch();

    if (!$jSquare) {
        echo json_encode(['success' => false, 'error' => 'J Square client not found in database.']);
        exit;
    }

    $jSquareId = (int)$jSquare['id'];

    // Update J Square ad account ID
    $up = $db->prepare("UPDATE clients SET meta_ad_account_id = 'act_1520125500129977' WHERE id = ?");
    $up->execute([$jSquareId]);

    // Clear old mismatched cache for J Square
    $del = $db->prepare("DELETE FROM ad_data_cache WHERE client_id = ?");
    $del->execute([$jSquareId]);

    // Fetch updated client row
    $fetchStmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $fetchStmt->execute([$jSquareId]);
    $client = $fetchStmt->fetch();

    // Trigger immediate sync with exact ad account ID act_1520125500129977
    $syncResult = syncClientData($client);

    echo json_encode([
        'success' => true,
        'client_id' => $jSquareId,
        'client_name' => $client['business_name'],
        'new_ad_account_id' => $client['meta_ad_account_id'],
        'sync_result' => $syncResult
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
