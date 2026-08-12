<?php
/**
 * Main Client Performance Dashboard View
 *
 * Renders the white-labeled ad analytics dashboard featuring customized branding,
 * toggleable metric KPI cards, Chart.js visualizations, tabular campaign breakdowns,
 * date range presets via Flatpickr, micro-info popovers, and CSV/PDF export actions.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireRole(['client', 'super_admin', 'team_member']);

$db = Database::getInstance();
$userRole = $_SESSION['user_role'];
$userId   = $_SESSION['user_id'];

// Target Client Context Resolution
$clientId = (int)($_GET['client_id'] ?? $_SESSION['client_id'] ?? 0);

if ($userRole === 'client') {
    $clientId = (int)($_SESSION['client_id'] ?? 0);
} elseif ($userRole === 'team_member') {
    if ($clientId > 0) {
        $tcaStmt = $db->prepare("SELECT 1 FROM team_client_access WHERE user_id = ? AND client_id = ?");
        $tcaStmt->execute([$userId, $clientId]);
        if (!$tcaStmt->fetch()) {
            header("Location: " . APP_URL . "/admin/index.php?error=unauthorized_client");
            exit;
        }
    } else {
        $tcaStmt = $db->prepare("SELECT client_id FROM team_client_access WHERE user_id = ? LIMIT 1");
        $tcaStmt->execute([$userId]);
        $first = $tcaStmt->fetch();
        $clientId = (int)($first['client_id'] ?? 0);
    }
} elseif ($userRole === 'super_admin') {
    if ($clientId <= 0) {
        $firstStmt = $db->query("SELECT id FROM clients WHERE active = 1 LIMIT 1");
        $clientId = (int)($firstStmt->fetch()['id'] ?? 0);
    }
}

if ($clientId <= 0) {
    echo "No client account context available. Please contact administrator.";
    exit;
}

// Fetch Client & Dashboard Config Record
$stmt = $db->prepare("
    SELECT c.*, dc.*
    FROM clients c
    LEFT JOIN dashboard_config dc ON dc.client_id = c.id
    WHERE c.id = ? LIMIT 1
");
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if (!$client) {
    echo "Client account not found.";
    exit;
}

$brandColor = $client['brand_color'] ?? '#0F2D55';
$reportTitle = $client['report_title'] ?? 'My Ads Performance';
$currency = $client['currency'] ?? 'INR';
$logoUrl = !empty($client['logo_path']) ? $client['logo_path'] : APP_URL . '/assets/logos/digital_rubix_logo.svg';

// Client switcher for Admins
$allClients = [];
if ($userRole === 'super_admin' || $userRole === 'team_member') {
    if ($userRole === 'super_admin') {
        $cStmt = $db->query("SELECT id, business_name FROM clients WHERE active = 1 ORDER BY business_name ASC");
        $allClients = $cStmt->fetchAll();
    } else {
        $cStmt = $db->prepare("
            SELECT c.id, c.business_name
            FROM clients c
            JOIN team_client_access tca ON c.id = tca.client_id
            WHERE tca.user_id = ? AND c.active = 1
            ORDER BY c.business_name ASC
        ");
        $cStmt->execute([$userId]);
        $allClients = $cStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($reportTitle) ?> — Digital Rubix</title>
    <!-- Prevent FOUT Theme Script -->
    <script>
        (function() {
            var t = localStorage.getItem('metapanel_theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Flatpickr Datepicker CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= time() ?>">

    <style>
        :root {
            --brand-color: <?= e($brandColor) ?>;
            --brand-accent: <?= e($brandColor) ?>;
        }
    </style>
</head>
<body>
    <!-- Client Top Header Bar -->
    <header class="client-dashboard-header d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center mb-2 mb-md-0">
            <a href="<?= APP_URL ?>" class="me-3">
                <img src="<?= e($logoUrl) ?>" alt="Digital Rubix Logo" class="agency-logo-img">
            </a>
            <div class="border-start ps-3 ms-2 d-none d-sm-block">
                <h5 class="m-0 fw-bold text-primary font-heading">
                    <?= e($client['business_name']) ?>
                    <span class="badge bg-light text-secondary border ms-2 fs-6 fw-normal"><i class="fa-solid fa-globe text-info me-1"></i><?= e($client['country_name'] ?? 'India') ?></span>
                    <span class="badge bg-light text-primary border ms-1 fs-6 fw-normal"><i class="fa-solid fa-coins text-warning me-1"></i><?= e($client['currency'] ?? 'INR') ?></span>
                </h5>
                <small class="text-muted"><?= e($reportTitle) ?></small>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (!empty($allClients)): ?>
                <select class="form-select form-select-sm me-2 shadow-sm" onchange="location.href='<?= APP_URL ?>/dashboard.php?client_id=' + this.value" style="width: auto;">
                    <?php foreach ($allClients as $ac): ?>
                        <option value="<?= $ac['id'] ?>" <?= $ac['id'] === $clientId ? 'selected' : '' ?>>
                            Switch: <?= e($ac['business_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <!-- Real-Time Data Sync Button & Progress Badge -->
            <button id="btn-realtime-sync" class="btn btn-sm btn-primary shadow-sm" data-client-id="<?= $clientId ?>">
                <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh Live Data
            </button>
            <span id="sync-status-badge" class="badge bg-light text-muted border px-2 py-1 align-self-center d-none">
                <i class="fa-solid fa-spinner fa-spin me-1 text-primary"></i> <span id="sync-timer-text">Syncing Meta API... ~5s</span>
            </span>

            <!-- Theme Mode Switcher -->
            <button type="button" class="btn btn-sm btn-outline-dark btn-theme-toggle shadow-sm">
                <i class="fa-solid fa-moon me-1"></i> Dark Mode
            </button>

            <!-- Admin Gateway Backlink -->
            <?php if ($userRole === 'super_admin' || $userRole === 'team_member'): ?>
                <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-dark me-1 shadow-sm">
                    <i class="fa-solid fa-user-gear me-1"></i> Admin Console
                </a>
            <?php endif; ?>

            <!-- Change Password Link -->
            <a href="<?= APP_URL ?>/change_password.php" class="btn btn-sm btn-outline-secondary shadow-sm" title="Change Password">
                <i class="fa-solid fa-key me-1"></i> Change Password
            </a>

            <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-outline-secondary shadow-sm" title="Sign Out">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </header>

    <div class="container-fluid px-4">
        <!-- Hidden Inputs for JS Engine -->
        <input type="hidden" id="meta-client-id" value="<?= $clientId ?>">
        <input type="hidden" id="meta-currency" value="<?= e($currency) ?>">

        <!-- Date Range Filter & Comparison Bar -->
        <div class="card glass-card p-3 mb-4 shadow-sm">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="btn-group shadow-sm" role="group" aria-label="Date presets">
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_7">7 Days</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_14">14 Days</button>
                        <button type="button" class="btn btn-sm btn-primary btn-preset-date active" data-preset="last_30">30 Days</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="this_month">This Month</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_month">Last Month</button>
                    </div>

                    <!-- Compare Toggle Switch -->
                    <div class="form-check form-switch ms-md-2 m-0 d-flex align-items-center gap-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="compare-mode-toggle">
                        <label class="form-check-label text-muted small fw-semibold user-select-none mb-0" for="compare-mode-toggle">
                            <i class="fa-solid fa-code-compare me-1 text-primary"></i> Compare Brackets
                        </label>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap flex-grow-1 flex-md-grow-0">
                    <div class="d-flex align-items-center gap-2" style="min-width: 220px;">
                        <span class="badge bg-primary-subtle text-primary border me-1 font-heading" title="Primary Bracket (Period A)">Period A</span>
                        <input type="text" id="date-range-picker" class="form-control form-control-sm shadow-sm" placeholder="Primary Date Bracket...">
                    </div>

                    <div id="compare-picker-wrapper" class="d-none align-items-center gap-2" style="min-width: 220px;">
                        <span class="badge bg-warning-subtle text-warning-emphasis border me-1 font-heading" title="Comparison Bracket (Period B)">Period B</span>
                        <input type="text" id="compare-range-picker" class="form-control form-control-sm shadow-sm border-warning" placeholder="Compare Bracket B...">
                    </div>
                </div>
            </div>
        </div>

        <!-- Metric KPI Cards Row -->
        <div class="row g-3 mb-4">
            <?php if ($client['show_spend'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Total Ad Spend</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Total Ad Spend" data-bs-content="Sum of all financial expenditure across active campaigns within the selected date range.">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-spend">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="trend-spend">Selected period total</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_roas'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Return on Ad Spend (ROAS)</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Purchase ROAS" data-bs-content="Formula: Total Conversion Revenue / Total Ad Spend. Values > 1.0x indicate profitable advertising return.">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value text-success" id="kpi-roas">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="trend-roas">Average Purchase ROAS</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_leads'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Conversions / Results</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Total Results" data-bs-content="Count of desired action outcomes (e.g. Lead Form submissions, Purchases, or Registrations).">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-conversions">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="trend-conversions">Total Attributed Results</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_leads'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Cost Per Result (CPR)</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Cost Per Result" data-bs-content="Formula: Total Ad Spend / Total Results. Measures acquisition cost per outcome.">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-cpr">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="trend-cpr">Avg. Acquisition Cost</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_ctr'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Click-Through Rate (CTR)</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="CTR Percentage" data-bs-content="Formula: (Total Clicks / Total Impressions) * 100. Measures ad creative engagement efficiency.">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-ctr">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="trend-ctr">Link Click Efficiency</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_cpc'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Cost Per Click (CPC)</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Avg Cost Per Click" data-bs-content="Formula: Total Ad Spend / Total Clicks. Lower values indicate cost-efficient traffic.">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-cpc">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="trend-cpc">Average Link Click Cost</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_impressions'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Cost Per 1,000 Impressions (CPM)</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Formulated CPM" data-bs-content="Formula: (Total Ad Spend / Total Impressions) * 1000. Formulated cost per thousand views.">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-cpm">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="trend-cpm">Cost Per 1K Views</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_impressions'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Reach & Frequency</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Reach & Frequency" data-bs-content="Reach: Unique users who saw your ads. Frequency: Impressions / Reach (average views per person).">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-reach">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted" id="kpi-frequency">Freq: —</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Visual Analytics Row (Charts) -->
        <div class="row g-4 mb-4">
            <!-- Line Chart -->
            <div class="col-lg-7">
                <div class="glass-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold m-0 font-heading"><i class="fa-solid fa-chart-area me-2 text-primary"></i> Daily Ad Spend Trend</h5>
                    </div>
                    <div style="height: 300px; position: relative;">
                        <canvas id="spendLineChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bar Chart -->
            <div class="col-lg-5">
                <div class="glass-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold m-0 font-heading"><i class="fa-solid fa-chart-bar me-2 text-success"></i> Campaign Performance</h5>
                    </div>
                    <div style="height: 300px; position: relative;">
                        <canvas id="impClickBarChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Breakdown Tables Section with Real-time Search -->
        <div class="card glass-card mb-5 shadow-sm">
            <div class="card-header bg-transparent border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <ul class="nav nav-tabs card-header-tabs m-0" id="breakdownTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-semibold" id="campaigns-tab" data-bs-toggle="tab" data-bs-target="#campaigns-pane" type="button" role="tab">
                            <i class="fa-solid fa-layer-group me-1"></i> Campaign Level
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold" id="adsets-tab" data-bs-toggle="tab" data-bs-target="#adsets-pane" type="button" role="tab">
                            <i class="fa-solid fa-cubes me-1"></i> Ad Sets Level
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold" id="ads-tab" data-bs-toggle="tab" data-bs-target="#ads-pane" type="button" role="tab">
                            <i class="fa-solid fa-rectangle-ad me-1"></i> Ads Level
                        </button>
                    </li>
                </ul>

                <!-- Realtime Table Search Box -->
                <div class="input-group input-group-sm shadow-sm" style="max-width: 260px;">
                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" id="table-search-input" class="form-control" placeholder="Search campaign or ad name...">
                </div>
            </div>

            <div class="card-body p-0">
                <div class="tab-content" id="breakdownTabContent">
                    <!-- Campaigns Pane -->
                    <div class="tab-pane fade show active p-3" id="campaigns-pane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Campaign Name</th>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Reach</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Impressions</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Frequency</th><?php endif; ?>
                                        <th>Clicks</th>
                                        <?php if ($client['show_ctr'] ?? 1): ?><th>CTR</th><?php endif; ?>
                                        <?php if ($client['show_cpc'] ?? 1): ?><th>CPC</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>CPM</th><?php endif; ?>
                                        <?php if ($client['show_spend'] ?? 1): ?><th>Spend</th><?php endif; ?>
                                        <?php if ($client['show_leads'] ?? 1): ?><th>Results</th><?php endif; ?>
                                        <?php if ($client['show_leads'] ?? 1): ?><th>CPR</th><?php endif; ?>
                                        <?php if ($client['show_roas'] ?? 1): ?><th>ROAS</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="campaigns-table-body">
                                    <tr><td colspan="12" class="text-center text-muted py-4">Loading campaign data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Adsets Pane -->
                    <!-- Adsets Pane -->
                    <div class="tab-pane fade p-3" id="adsets-pane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Ad Set Name</th>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Reach</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Impressions</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Frequency</th><?php endif; ?>
                                        <th>Clicks</th>
                                        <?php if ($client['show_ctr'] ?? 1): ?><th>CTR</th><?php endif; ?>
                                        <?php if ($client['show_cpc'] ?? 1): ?><th>CPC</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>CPM</th><?php endif; ?>
                                        <?php if ($client['show_spend'] ?? 1): ?><th>Spend</th><?php endif; ?>
                                        <?php if ($client['show_leads'] ?? 1): ?><th>Results</th><?php endif; ?>
                                        <?php if ($client['show_leads'] ?? 1): ?><th>CPR</th><?php endif; ?>
                                        <?php if ($client['show_roas'] ?? 1): ?><th>ROAS</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="adsets-table-body">
                                    <tr><td colspan="12" class="text-center text-muted py-4">Loading ad set data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Ads Pane -->
                    <div class="tab-pane fade p-3" id="ads-pane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Ad Name</th>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Reach</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Impressions</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>Frequency</th><?php endif; ?>
                                        <th>Clicks</th>
                                        <?php if ($client['show_ctr'] ?? 1): ?><th>CTR</th><?php endif; ?>
                                        <?php if ($client['show_cpc'] ?? 1): ?><th>CPC</th><?php endif; ?>
                                        <?php if ($client['show_impressions'] ?? 1): ?><th>CPM</th><?php endif; ?>
                                        <?php if ($client['show_spend'] ?? 1): ?><th>Spend</th><?php endif; ?>
                                        <?php if ($client['show_leads'] ?? 1): ?><th>Results</th><?php endif; ?>
                                        <?php if ($client['show_leads'] ?? 1): ?><th>CPR</th><?php endif; ?>
                                        <?php if ($client['show_roas'] ?? 1): ?><th>ROAS</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="ads-table-body">
                                    <tr><td colspan="12" class="text-center text-muted py-4">Loading ad data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Meta Onboarding & Connection Guide Modal -->
    <div class="modal fade" id="metaGuideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content glass-card">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-heading"><i class="fa-brands fa-meta text-primary me-2"></i> Meta Marketing API Client Connection Guide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="onboarding-step-card">
                        <h6 class="fw-bold text-primary mb-1">Step 1: Meta Business Login Authentication</h6>
                        <p class="small text-muted mb-0">
                            Click "Connect Meta Account" in Admin Config. The system will redirect you to Meta's secure OAuth portal requesting read access (`ads_read`, `read_insights`).
                        </p>
                    </div>
                    <div class="onboarding-step-card">
                        <h6 class="fw-bold text-primary mb-1">Step 2: Automated 60-Day Token Exchange</h6>
                        <p class="small text-muted mb-0">
                            MetaPanel automatically exchanges the short-lived access code for a 60-day long-lived User Token, encrypted at rest in MySQL using AES-256-CBC.
                        </p>
                    </div>
                    <div class="onboarding-step-card">
                        <h6 class="fw-bold text-primary mb-1">Step 3: Ad Account Format (`act_XXXXXXXX`)</h6>
                        <p class="small text-muted mb-0">
                            Your Meta Ad Account ID must follow the standard `act_1234567890` format. MetaPanel fetches your default account automatically, or admins can set a custom override.
                        </p>
                    </div>
                    <div class="onboarding-step-card">
                        <h6 class="fw-bold text-primary mb-1">Step 4: Automated 6-Hour Data Caching</h6>
                        <p class="small text-muted mb-0">
                            Performance metrics (Spend, Impressions, Clicks, ROAS) are synced every 6 hours by background cron jobs to provide instant, high-speed page loads without hitting API rate limits.
                        </p>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close Guide</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        window.APP_URL = "<?= APP_URL ?>";
        window.clientWidgetConfig = {
            show_spend: <?= (int)($client['show_spend'] ?? 1) ?>,
            show_roas: <?= (int)($client['show_roas'] ?? 1) ?>,
            show_leads: <?= (int)($client['show_leads'] ?? 1) ?>,
            show_cpc: <?= (int)($client['show_cpc'] ?? 1) ?>,
            show_ctr: <?= (int)($client['show_ctr'] ?? 1) ?>,
            show_impressions: <?= (int)($client['show_impressions'] ?? 1) ?>,
            show_campaigns: <?= (int)($client['show_campaigns'] ?? 1) ?>,
            show_adsets: <?= (int)($client['show_adsets'] ?? 1) ?>
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/dashboard.js?v=<?= time() ?>"></script>
</body>
</html>
