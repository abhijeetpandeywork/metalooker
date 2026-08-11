<?php
/**
 * Tabular Analytics CSV Export Endpoint
 *
 * Generates and streams down formatted CSV reports for a given client and date range.
 *
 * @package MetaPanel\API
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!isLoggedIn()) {
    die("Unauthorized access.");
}

$db = Database::getInstance();
$role   = $_SESSION['user_role'];
$userId = $_SESSION['user_id'];

$clientId = (int)($_GET['client_id'] ?? $_SESSION['client_id'] ?? 0);

if ($role === 'client') {
    if (empty($_SESSION['client_id']) || $clientId !== (int)$_SESSION['client_id']) {
        die("Forbidden access.");
    }
} elseif ($role === 'team_member') {
    $tcaStmt = $db->prepare("SELECT 1 FROM team_client_access WHERE user_id = ? AND client_id = ?");
    $tcaStmt->execute([$userId, $clientId]);
    if (!$tcaStmt->fetch()) {
        die("Forbidden access.");
    }
}

if ($clientId <= 0) {
    die("Invalid client specified.");
}

// Fetch Client Info
$cStmt = $db->prepare("SELECT business_name, currency FROM clients WHERE id = ? LIMIT 1");
$cStmt->execute([$clientId]);
$client = $cStmt->fetch();

$from = $_GET['from'] ?? (new DateTime('-30 days'))->format('Y-m-d');
$to   = $_GET['to']   ?? (new DateTime('-1 day'))->format('Y-m-d');

// Query Campaign Level Insights
$stmt = $db->prepare("
    SELECT
        date_start,
        date_stop,
        level,
        object_name,
        impressions,
        reach,
        clicks,
        ctr,
        cpc,
        cpm,
        spend,
        conversions,
        cost_per_result,
        roas
    FROM ad_data_cache
    WHERE client_id = ? AND level = 'campaign'
    AND date_start >= ? AND date_stop <= ?
    ORDER BY date_start ASC, spend DESC
");
$stmt->execute([$clientId, $from, $to]);
$rows = $stmt->fetchAll();

$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $client['business_name'] ?? 'Client');
$filename = "metapanel_report_{$safeName}_{$from}_to_{$to}.csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Output CSV Headers
fputcsv($output, [
    'Date Start',
    'Date Stop',
    'Level',
    'Campaign Name',
    'Impressions',
    'Reach',
    'Clicks',
    'CTR (%)',
    'CPC (' . ($client['currency'] ?? 'INR') . ')',
    'CPM (' . ($client['currency'] ?? 'INR') . ')',
    'Spend (' . ($client['currency'] ?? 'INR') . ')',
    'Conversions',
    'Cost Per Result',
    'ROAS (x)'
]);

foreach ($rows as $r) {
    fputcsv($output, [
        $r['date_start'],
        $r['date_stop'],
        strtoupper($r['level']),
        $r['object_name'],
        $r['impressions'],
        $r['reach'],
        $r['clicks'],
        $r['ctr'] . '%',
        $r['cpc'],
        $r['cpm'],
        $r['spend'],
        $r['conversions'],
        $r['cost_per_result'],
        $r['roas'] . 'x'
    ]);
}

fclose($output);
exit;
