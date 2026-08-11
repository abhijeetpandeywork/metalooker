<?php
/**
 * Test Meta App Credentials API Endpoint
 *
 * Validates Meta App ID and App Secret against Meta Graph API in real time.
 *
 * @package MetaPanel\API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole('super_admin');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];

$appId = trim($input['app_id'] ?? META_APP_ID);
$appSecret = trim($input['app_secret'] ?? META_APP_SECRET);

if (empty($appId)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Meta App ID is required.'
    ]);
    exit;
}

if (empty($appSecret)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Meta App Secret is required.'
    ]);
    exit;
}

// Make curl request to Meta Graph API
$url = "https://graph.facebook.com/" . META_GRAPH_VERSION . "/{$appId}?access_token={$appId}|{$appSecret}";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode([
        'success' => false,
        'error'   => 'Network error connecting to Meta Graph API: ' . $curlErr
    ]);
    exit;
}

$data = json_decode($response, true) ?: [];

if (isset($data['error'])) {
    $msg = $data['error']['message'] ?? 'Invalid Meta App credentials.';
    echo json_encode([
        'success' => false,
        'error'   => 'Meta Graph API Error: ' . $msg,
        'http_code' => $httpCode
    ]);
    exit;
}

echo json_encode([
    'success'    => true,
    'app_id'     => $data['id'] ?? $appId,
    'app_name'   => $data['name'] ?? 'Meta App Validated',
    'link'       => $data['link'] ?? '',
    'http_code'  => $httpCode
]);
