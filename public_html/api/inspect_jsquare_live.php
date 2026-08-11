<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/token_manager.php';
require_once __DIR__ . '/../includes/meta_api.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $cStmt = $db->prepare("SELECT * FROM clients WHERE id = 4");
    $cStmt->execute();
    $client = $cStmt->fetch();

    $plainToken = TokenManager::decrypt($client['meta_access_token'] ?? '');
    $adAccountId = 'act_1520125500129977';

    $metaApi = new MetaAPI($plainToken, $adAccountId);
    $dateStart = (new DateTime())->modify('-30 days')->format('Y-m-d');
    $dateStop  = (new DateTime())->modify('-1 day')->format('Y-m-d');

    // Fetch live campaign insights directly from Meta Graph API for act_1520125500129977
    $liveData = $metaApi->getInsights('campaign', $dateStart, $dateStop);

    // Fetch account details
    $acctDetails = $metaApi->getAccountDetails();

    echo json_encode([
        'account_id' => $adAccountId,
        'account_details' => $acctDetails,
        'live_campaign_count' => count($liveData),
        'live_campaigns' => array_map(function($row) {
            return [
                'id' => $row['campaign_id'] ?? $row['id'] ?? '',
                'name' => $row['campaign_name'] ?? '',
                'spend' => $row['spend'] ?? 0
            ];
        }, $liveData)
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
