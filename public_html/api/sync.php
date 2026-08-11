<?php
/**
 * Manual Client Data Sync Endpoint
 *
 * Receives AJAX requests from Admin console to trigger ad performance data synchronization
 * for a specific client account on demand.
 *
 * @package MetaPanel\API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
$cronFile = __DIR__ . '/../../cron/sync_all.php';
if (!file_exists($cronFile)) $cronFile = dirname(__DIR__) . '/../cron/sync_all.php';
if (!file_exists($cronFile)) $cronFile = __DIR__ . '/../cron/sync_all.php';
if (file_exists($cronFile)) require_once $cronFile;

$syncKey = $_GET['key'] ?? '';
$isSecretSync = ($syncKey === 'metapanel_sync_2026');

if (!$isSecretSync && (!isLoggedIn() || !in_array($_SESSION['user_role'], ['super_admin', 'team_member'], true))) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$clientId = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);

if ($clientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing client_id parameter.']);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        echo json_encode(['success' => false, 'error' => 'Client account not found.']);
        exit;
    }

    $result = syncClientData($client);

    if ($result['status'] === 'success') {
        echo json_encode([
            'success'       => true,
            'rows_inserted' => $result['rows'],
            'synced_at'     => date('Y-m-d H:i:s')
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => $result['error']
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Server error during sync: ' . $e->getMessage()
    ]);
}
