<?php
/**
 * Dashboard Performance Analytics JSON Endpoint
 *
 * Fetches, aggregates, and filters marketing insights from ad_data_cache table based on date range,
 * client scope, and widget permissions.
 *
 * @package MetaPanel\API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = Database::getInstance();
$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'guest';
$userId = $_SESSION['user_id'] ?? 0;

// Target Client ID selection
$clientId = (int)($_GET['client_id'] ?? $_SESSION['client_id'] ?? 0);

if (!isLoggedIn() && $clientId <= 0) {
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

// Authorization Checks
if ($role === 'client') {
    if (!empty($_SESSION['client_id']) && $clientId !== (int)$_SESSION['client_id']) {
        echo json_encode(['error' => 'Forbidden: You can only access your own client data.']);
        exit;
    }
} elseif ($role === 'team_member') {
    $tcaStmt = $db->prepare("SELECT 1 FROM team_client_access WHERE user_id = ? AND client_id = ?");
    $tcaStmt->execute([$userId, $clientId]);
    if (!$tcaStmt->fetch()) {
        echo json_encode(['error' => 'Forbidden: You do not have access to this client.']);
        exit;
    }
}

if ($clientId <= 0) {
    echo json_encode(['error' => 'Invalid client_id specified.']);
    exit;
}

// Fetch Client Dashboard Config
$configStmt = $db->prepare("SELECT * FROM dashboard_config WHERE client_id = ? LIMIT 1");
$configStmt->execute([$clientId]);
$config = $configStmt->fetch() ?: [
    'show_spend' => 1, 'show_roas' => 1, 'show_leads' => 1,
    'show_cpc' => 1, 'show_ctr' => 1, 'show_impressions' => 1,
    'show_campaigns' => 1, 'show_adsets' => 1,
    'default_range' => 'last_30', 'report_title' => 'My Ads Performance'
];

// Determine Date Range
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

if (empty($from) || empty($to)) {
    $bounds = getDateRangeBounds($config['default_range']);
    $from = $bounds['start'];
    $to   = $bounds['end'];
}

try {
    // 1. Aggregated Key Performance Indicators (Campaign Level ONLY to avoid double-counting with account level)
    $kpiStmt = $db->prepare("
        SELECT
            SUM(impressions) as impressions,
            SUM(reach) as reach,
            SUM(clicks) as clicks,
            SUM(spend) as spend,
            SUM(conversions) as conversions
        FROM ad_data_cache
        WHERE client_id = ? AND level = 'campaign'
        AND date_start >= ? AND date_start <= ?
    ");
    $kpiStmt->execute([$clientId, $from, $to]);
    $kpisRaw = $kpiStmt->fetch();

    $impressions = (int)($kpisRaw['impressions'] ?? 0);
    $reach       = (int)($kpisRaw['reach'] ?? 0);
    $clicks      = (int)($kpisRaw['clicks'] ?? 0);
    $spend       = (float)($kpisRaw['spend'] ?? 0.0);
    $conversions = (int)($kpisRaw['conversions'] ?? 0);

    $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;
    $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0.0;
    $cpm = $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0.0;
    $costPerResult = $conversions > 0 ? round($spend / $conversions, 2) : 0.0;

    // Fetch average ROAS
    $roasStmt = $db->prepare("
        SELECT AVG(roas) as avg_roas
        FROM ad_data_cache
        WHERE client_id = ? AND level = 'campaign'
        AND date_start >= ? AND date_start <= ? AND roas > 0
    ");
    $roasStmt->execute([$clientId, $from, $to]);
    $avgRoas = (float)($roasStmt->fetch()['avg_roas'] ?? 0.0);

    // 2. Daily Spend & Performance Series (Chart.js)
    $seriesStmt = $db->prepare("
        SELECT
            date_start as date,
            SUM(spend) as spend,
            SUM(impressions) as impressions,
            SUM(clicks) as clicks
        FROM ad_data_cache
        WHERE client_id = ? AND level = 'campaign'
        AND date_start >= ? AND date_start <= ?
        GROUP BY date_start
        ORDER BY date_start ASC
    ");
    $seriesStmt->execute([$clientId, $from, $to]);
    $dailySeries = $seriesStmt->fetchAll();

    // 3. Campaign Breakdown Table
    $cmpStmt = $db->prepare("
        SELECT
            object_id,
            object_name as name,
            SUM(impressions) as impressions,
            SUM(clicks) as clicks,
            SUM(spend) as spend,
            SUM(conversions) as conversions,
            AVG(roas) as roas
        FROM ad_data_cache
        WHERE client_id = ? AND level = 'campaign'
        AND date_start >= ? AND date_start <= ?
        GROUP BY object_id, object_name
        ORDER BY spend DESC
    ");
    $cmpStmt->execute([$clientId, $from, $to]);
    $campaigns = array_map(function($row) {
        $imp = (int)$row['impressions'];
        $clk = (int)$row['clicks'];
        $spd = (float)$row['spend'];
        $row['ctr'] = $imp > 0 ? round(($clk / $imp) * 100, 2) : 0.0;
        $row['cpc'] = $clk > 0 ? round($spd / $clk, 2) : 0.0;
        $row['cpm'] = $imp > 0 ? round(($spd / $imp) * 1000, 2) : 0.0;
        $row['roas'] = round((float)$row['roas'], 2);
        return $row;
    }, $cmpStmt->fetchAll());

    // 4. Ad Sets Breakdown Table
    $adsetStmt = $db->prepare("
        SELECT
            object_id,
            object_name as name,
            SUM(impressions) as impressions,
            SUM(clicks) as clicks,
            SUM(spend) as spend,
            SUM(conversions) as conversions,
            AVG(roas) as roas
        FROM ad_data_cache
        WHERE client_id = ? AND level = 'adset'
        AND date_start >= ? AND date_start <= ?
        GROUP BY object_id, object_name
        ORDER BY spend DESC
    ");
    $adsetStmt->execute([$clientId, $from, $to]);
    $adsets = array_map(function($row) {
        $imp = (int)$row['impressions'];
        $clk = (int)$row['clicks'];
        $spd = (float)$row['spend'];
        $row['ctr'] = $imp > 0 ? round(($clk / $imp) * 100, 2) : 0.0;
        $row['cpc'] = $clk > 0 ? round($spd / $clk, 2) : 0.0;
        $row['roas'] = round((float)$row['roas'], 2);
        return $row;
    }, $adsetStmt->fetchAll());

    // 5. Ads Breakdown Table
    $adStmt = $db->prepare("
        SELECT
            object_id,
            object_name as name,
            SUM(impressions) as impressions,
            SUM(clicks) as clicks,
            SUM(spend) as spend,
            SUM(conversions) as conversions,
            AVG(roas) as roas
        FROM ad_data_cache
        WHERE client_id = ? AND level = 'ad'
        AND date_start >= ? AND date_start <= ?
        GROUP BY object_id, object_name
        ORDER BY spend DESC
    ");
    $adStmt->execute([$clientId, $from, $to]);
    $ads = array_map(function($row) {
        $imp = (int)$row['impressions'];
        $clk = (int)$row['clicks'];
        $spd = (float)$row['spend'];
        $row['ctr'] = $imp > 0 ? round(($clk / $imp) * 100, 2) : 0.0;
        $row['cpc'] = $clk > 0 ? round($spd / $clk, 2) : 0.0;
        $row['roas'] = round((float)$row['roas'], 2);
        return $row;
    }, $adStmt->fetchAll());

    echo json_encode([
        'success'   => true,
        'date_from' => $from,
        'date_to'   => $to,
        'config'    => $config,
        'kpis'      => [
            'spend'           => $spend,
            'impressions'     => $impressions,
            'reach'           => $reach,
            'clicks'          => $clicks,
            'ctr'             => $ctr,
            'cpc'             => $cpc,
            'cpm'             => $cpm,
            'conversions'     => $conversions,
            'cost_per_result' => $costPerResult,
            'roas'            => round($avgRoas, 2)
        ],
        'chart_daily' => $dailySeries,
        'campaigns'   => $campaigns,
        'adsets'      => $adsets,
        'ads'         => $ads
    ]);

} catch (Throwable $e) {
    echo json_encode(['error' => 'Failed to fetch analytics data: ' . $e->getMessage()]);
}
