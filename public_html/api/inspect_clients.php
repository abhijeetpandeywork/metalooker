<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/token_manager.php';
require_once __DIR__ . '/../includes/meta_api.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT id, business_name, meta_ad_account_id, currency, active, token_expires_at FROM clients");
    $clients = $stmt->fetchAll();

    $results = [];
    foreach ($clients as $c) {
        $token = TokenManager::decrypt($c['meta_access_token'] ?? '');
        $cacheStmt = $db->prepare("SELECT count(*) as cnt, sum(spend) as spend FROM ad_data_cache WHERE client_id = ? AND level = 'campaign'");
        $cacheStmt->execute([$c['id']]);
        $cache = $cacheStmt->fetch();

        $results[] = [
            'id' => $c['id'],
            'name' => $c['business_name'],
            'ad_account' => $c['meta_ad_account_id'],
            'currency' => $c['currency'],
            'active' => $c['active'],
            'cached_rows' => $cache['cnt'] ?? 0,
            'cached_spend' => $cache['spend'] ?? 0.0
        ];
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
