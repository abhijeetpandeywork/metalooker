<?php
/**
 * Meta Business OAuth Redirect Callback Handler
 *
 * Handles incoming Meta OAuth 2.0 redirect, verifies state parameter against session,
 * exchanges authorization code for short-lived and long-lived access tokens,
 * encrypts token with AES-256-CBC, and updates client records.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/token_manager.php';
require_once __DIR__ . '/includes/meta_api.php';
require_once __DIR__ . '/includes/helpers.php';

// Requires super_admin or team_member session
requireRole(['super_admin', 'team_member']);

$clientId = (int)($_SESSION['oauth_client_id'] ?? 0);
$sessionState = $_SESSION['oauth_state'] ?? '';

// Clear transient OAuth session keys
unset($_SESSION['oauth_client_id'], $_SESSION['oauth_state']);

$receivedState = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
$error = $_GET['error_description'] ?? $_GET['error'] ?? null;

if ($clientId <= 0) {
    header("Location: " . APP_URL . "/admin/clients.php?error=" . urlencode("No active client context found for OAuth callback."));
    exit;
}

if (!empty($error)) {
    header("Location: " . APP_URL . "/admin/client_edit.php?id={$clientId}&error=" . urlencode("Meta authorization failed: {$error}"));
    exit;
}

if (empty($receivedState) || empty($sessionState) || !hash_equals($sessionState, $receivedState)) {
    header("Location: " . APP_URL . "/admin/client_edit.php?id={$clientId}&error=" . urlencode("Security validation failed (OAuth state mismatch)."));
    exit;
}

if (empty($code)) {
    header("Location: " . APP_URL . "/admin/client_edit.php?id={$clientId}&error=" . urlencode("No authorization code received from Meta."));
    exit;
}

try {
    $redirectUri = APP_URL . '/oauth_callback.php';

    // In Mock Mode, generate token immediately
    if (MOCK_META_API) {
        $longLivedToken = 'EAAG_mock_long_lived_token_' . bin2hex(random_bytes(16));
        $expiresIn = 60 * 86400; // 60 days
        $adAccountId = 'act_1092837465';
    } else {
        // Exchange Code for Short-Lived Token
        $tokenUrl = "https://graph.facebook.com/" . META_GRAPH_VERSION . "/oauth/access_token?" . http_build_query([
            'client_id'     => META_APP_ID,
            'client_secret' => META_APP_SECRET,
            'redirect_uri'  => $redirectUri,
            'code'          => $code
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $tokenUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $tokenData = json_decode($response, true);
        if (empty($tokenData['access_token'])) {
            throw new Exception("Failed to retrieve access token from Meta: " . ($tokenData['error']['message'] ?? 'Unknown error'));
        }

        $shortLivedToken = $tokenData['access_token'];

        // Exchange for Long-Lived 60-day token
        $longTokenData = MetaAPI::exchangeForLongLivedToken($shortLivedToken);
        $longLivedToken = $longTokenData['access_token'] ?? $shortLivedToken;
        $expiresIn = $longTokenData['expires_in'] ?? (60 * 86400);

        // Fetch primary Ad Account ID
        $metaApi = new MetaAPI($longLivedToken);
        $adAccounts = $metaApi->getAdAccounts();
        $adAccountId = !empty($adAccounts[0]['id']) ? $adAccounts[0]['id'] : '';
    }

    // Encrypt token using AES-256-CBC
    $encryptedToken = TokenManager::encrypt($longLivedToken);
    $expiresAt = (new DateTime())->modify("+{$expiresIn} seconds")->format('Y-m-d H:i:s');

    // Update Client record in MySQL
    $db = Database::getInstance();
    $stmt = $db->prepare("
        UPDATE clients
        SET meta_access_token = ?,
            meta_ad_account_id = ?,
            token_expires_at = ?
        WHERE id = ?
    ");
    $stmt->execute([$encryptedToken, $adAccountId, $expiresAt, $clientId]);

    logActivity($_SESSION['user_id'], "Connected Meta Ad Account ({$adAccountId}) for client ID {$clientId}");

    header("Location: " . APP_URL . "/admin/client_edit.php?id={$clientId}&success=" . urlencode("Meta Ad Account successfully connected and long-lived token stored."));
    exit;

} catch (Exception $e) {
    error_log("OAuth Callback Exception: " . $e->getMessage());
    header("Location: " . APP_URL . "/admin/client_edit.php?id={$clientId}&error=" . urlencode("Meta connection error: " . $e->getMessage()));
    exit;
}
