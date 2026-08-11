<?php
/**
 * Live Meta Graph API Diagnostic Test Script
 *
 * Runs basic direct cURL requests against Meta Graph API for a specified client account
 * to verify access tokens, account permissions, campaign listings, and insights.
 *
 * @package MetaPanel\API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/token_manager.php';

$clientId = (int)($_GET['client_id'] ?? 2);

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        echo json_encode(['error' => "Client ID {$clientId} not found in database."]);
        exit;
    }

    $plainToken = TokenManager::decrypt($client['meta_access_token'] ?? '');
    $rawAdAccountId = $client['meta_ad_account_id'] ?? '';
    $adAccountId = str_starts_with($rawAdAccountId, 'act_') ? $rawAdAccountId : 'act_' . ltrim($rawAdAccountId, 'act_');

    $diagnostics = [
        'client_id'          => $clientId,
        'business_name'      => $client['business_name'],
        'ad_account_id'      => $adAccountId,
        'token_present'      => !empty($plainToken),
        'token_snippet'      => substr($plainToken, 0, 15) . '...',
        'tests'              => []
    ];

    if (empty($plainToken)) {
        echo json_encode(['error' => 'No access token available for this client. Please connect via Meta OAuth first.'], JSON_PRETTY_PRINT);
        exit;
    }

    // Helper cURL function
    $callMeta = function($endpoint, $params) use ($plainToken) {
        $params['access_token'] = $plainToken;
        $url = "https://graph.facebook.com/v21.0/" . ltrim($endpoint, '/') . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => $code,
            'response'  => json_decode($res, true) ?? $res
        ];
    };

    // Test 1: Ad Account Info
    $diagnostics['tests']['1_account_details'] = $callMeta($adAccountId, [
        'fields' => 'id,name,account_id,account_status,currency'
    ]);

    // Test 2: Campaigns List
    $diagnostics['tests']['2_campaigns_list'] = $callMeta("{$adAccountId}/campaigns", [
        'fields' => 'id,name,status,effective_status,created_time',
        'limit'  => 10
    ]);

    // Test 3: Account Insights (Date Preset: maximum)
    $diagnostics['tests']['3_insights_maximum'] = $callMeta("{$adAccountId}/insights", [
        'fields'      => 'impressions,reach,clicks,spend,cpc,ctr,cpm',
        'date_preset' => 'maximum',
        'limit'       => 50
    ]);

    // Test 4: Account Insights (Date Preset: last_30d)
    $diagnostics['tests']['4_insights_last_30d'] = $callMeta("{$adAccountId}/insights", [
        'fields'      => 'impressions,reach,clicks,spend,cpc,ctr,cpm',
        'date_preset' => 'last_30d',
        'limit'       => 50
    ]);

    echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
