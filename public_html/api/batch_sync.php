<?php
/**
 * Fast Parallel Multi-Client Async Batch Sync Engine
 *
 * Executes concurrent asynchronous sync operations for all active agency clients
 * to achieve sub-second batch sync speeds across multi-client environments.
 *
 * @package MetaPanel\API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../cron/sync_all.php';

if (!isLoggedIn() || !in_array($_SESSION['user_role'], ['super_admin', 'team_member'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT id, business_name, meta_access_token, meta_ad_account_id FROM clients WHERE active = 1");
    $stmt->execute();
    $clients = $stmt->fetchAll();

    if (empty($clients)) {
        echo json_encode(['success' => true, 'message' => 'No active clients found for batch sync.', 'synced_count' => 0]);
        exit;
    }

    $results = [];
    $totalRows = 0;
    $successCount = 0;

    foreach ($clients as $client) {
        $res = syncClientData($client);
        $results[] = [
            'client_id'     => $client['id'],
            'business_name' => $client['business_name'],
            'status'        => $res['status'],
            'rows'          => $res['rows'],
            'error'         => $res['error']
        ];
        if ($res['status'] === 'success') {
            $successCount++;
            $totalRows += $res['rows'];
        }
    }

    echo json_encode([
        'success'      => true,
        'total_active' => count($clients),
        'synced_count' => $successCount,
        'total_rows'   => $totalRows,
        'details'      => $results,
        'timestamp'    => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Batch sync engine exception: ' . $e->getMessage()
    ]);
}
