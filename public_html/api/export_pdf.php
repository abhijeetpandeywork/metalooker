<?php
/**
 * Executive Ad Performance PDF Export Endpoint
 *
 * Renders an executive performance summary report. Uses mPDF if available in vendor directory;
 * otherwise provides an inline printable HTML dashboard report with PDF print styling.
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
    die("Invalid client account.");
}

$stmt = $db->prepare("
    SELECT c.*, dc.report_title
    FROM clients c
    LEFT JOIN dashboard_config dc ON dc.client_id = c.id
    WHERE c.id = ? LIMIT 1
");
$stmt->execute([$clientId]);
$client = $stmt->fetch();

$from = $_GET['from'] ?? (new DateTime('-30 days'))->format('Y-m-d');
$to   = $_GET['to']   ?? (new DateTime('-1 day'))->format('Y-m-d');

// Fetch Aggregated KPIs
$kpiStmt = $db->prepare("
    SELECT
        SUM(impressions) as impressions,
        SUM(clicks) as clicks,
        SUM(spend) as spend,
        SUM(conversions) as conversions,
        AVG(roas) as roas
    FROM ad_data_cache
    WHERE client_id = ? AND level = 'campaign'
    AND date_start >= ? AND date_stop <= ?
");
$kpiStmt->execute([$clientId, $from, $to]);
$kpis = $kpiStmt->fetch();

$impressions = (int)($kpis['impressions'] ?? 0);
$clicks      = (int)($kpis['clicks'] ?? 0);
$spend       = (float)($kpis['spend'] ?? 0.0);
$conversions = (int)($kpis['conversions'] ?? 0);
$ctr         = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;
$cpc         = $clicks > 0 ? round($spend / $clicks, 2) : 0.0;
$roas        = round((float)($kpis['roas'] ?? 0.0), 2);
$currency    = $client['currency'] ?? 'INR';

// Fetch Top Campaigns
$cmpStmt = $db->prepare("
    SELECT object_name, SUM(impressions) as imp, SUM(clicks) as clk, SUM(spend) as spd, SUM(conversions) as conv, AVG(roas) as avg_roas
    FROM ad_data_cache
    WHERE client_id = ? AND level = 'campaign'
    AND date_start >= ? AND date_stop <= ?
    GROUP BY object_id, object_name
    ORDER BY spd DESC
    LIMIT 10
");
$cmpStmt->execute([$clientId, $from, $to]);
$campaigns = $cmpStmt->fetchAll();

// HTML Report Template
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Executive Meta Ads Report</title>
    <style>
        body { font-family: sans-serif; color: #111827; margin: 0; padding: 20px; }
        .header { border-bottom: 2px solid ' . e($client['brand_color'] ?? '#0F2D55') . '; padding-bottom: 15px; margin-bottom: 25px; }
        .title { font-size: 24px; font-weight: bold; color: ' . e($client['brand_color'] ?? '#0F2D55') . '; margin: 0; }
        .subtitle { font-size: 14px; color: #6b7280; margin-top: 4px; }
        .grid { display: table; width: 100%; margin-bottom: 25px; }
        .row { display: table-row; }
        .col { display: table-cell; width: 25%; padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; text-align: center; }
        .kpi-num { font-size: 20px; font-weight: bold; margin-top: 4px; color: #1f2937; }
        .kpi-label { font-size: 11px; text-transform: uppercase; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { background: #1f2937; color: #ffffff; text-align: left; padding: 8px; font-size: 12px; }
        td { border-bottom: 1px solid #e5e7eb; padding: 8px; font-size: 12px; }
        .footer { margin-top: 40px; border-top: 1px solid #e5e7eb; padding-top: 12px; text-align: center; font-size: 11px; color: #9ca3af; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 8px 16px; background: #0F2D55; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
            Print / Save as PDF
        </button>
    </div>

    <div class="header">
        <div class="title">' . e($client['business_name']) . '</div>
        <div class="subtitle">' . e($client['report_title'] ?? 'Meta Ads Performance') . ' | Period: ' . e($from) . ' to ' . e($to) . '</div>
    </div>

    <div class="grid">
        <div class="row">
            <div class="col">
                <div class="kpi-label">Total Spend</div>
                <div class="kpi-num">' . formatCurrency($spend, $currency) . '</div>
            </div>
            <div class="col">
                <div class="kpi-label">Average ROAS</div>
                <div class="kpi-num">' . $roas . 'x</div>
            </div>
            <div class="col">
                <div class="kpi-label">Conversions</div>
                <div class="kpi-num">' . formatNumber($conversions) . '</div>
            </div>
            <div class="col">
                <div class="kpi-label">Average CTR</div>
                <div class="kpi-num">' . $ctr . '%</div>
            </div>
        </div>
    </div>

    <h3>Top Campaign Performance Breakdown</h3>
    <table>
        <thead>
            <tr>
                <th>Campaign Name</th>
                <th>Impressions</th>
                <th>Clicks</th>
                <th>CTR</th>
                <th>Spend</th>
                <th>Conversions</th>
                <th>ROAS</th>
            </tr>
        </thead>
        <tbody>';

foreach ($campaigns as $c) {
    $cImp = (int)$c['imp'];
    $cClk = (int)$c['clk'];
    $cCtr = $cImp > 0 ? round(($cClk / $cImp) * 100, 2) : 0.0;
    $html .= '
            <tr>
                <td>' . e($c['object_name']) . '</td>
                <td>' . formatNumber($cImp) . '</td>
                <td>' . formatNumber($cClk) . '</td>
                <td>' . $cCtr . '%</td>
                <td>' . formatCurrency($c['spd'], $currency) . '</td>
                <td>' . formatNumber($c['conv']) . '</td>
                <td>' . round((float)$c['avg_roas'], 2) . 'x</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>

    <div class="footer">
        Generated by Digital Rubix MetaPanel Engine &bull; Confidential &bull; ' . date('Y-m-d H:i:s') . '
    </div>
</body>
</html>';

// Check if mPDF Composer Autoloader is available
$composerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    if (class_exists('\Mpdf\Mpdf')) {
        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15
        ]);
        $mpdf->WriteHTML($html);
        $mpdf->Output("metapanel_report_" . date('Ymd') . ".pdf", \Mpdf\Output\Destination::INLINE);
        exit;
    }
}

// Fallback to styled printable HTML page
echo $html;
