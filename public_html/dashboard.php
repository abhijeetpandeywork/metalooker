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
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

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
                <h5 class="m-0 fw-bold text-primary font-heading"><?= e($client['business_name']) ?></h5>
                <small class="text-muted"><i class="fa-solid fa-phone me-1 text-success"></i> Hotline: +91 9871633838 | <?= e($reportTitle) ?></small>
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

            <!-- Onboarding & Connection Guide Modal Trigger -->
            <button type="button" class="btn btn-sm btn-outline-info shadow-sm" data-bs-toggle="modal" data-bs-target="#metaGuideModal">
                <i class="fa-solid fa-circle-question me-1"></i> Meta API Guide
            </button>

            <!-- Export Buttons -->
            <button id="btn-export-csv" class="btn btn-sm btn-outline-success shadow-sm">
                <i class="fa-solid fa-file-csv me-1"></i> CSV Export
            </button>
            <button id="btn-export-pdf" class="btn btn-sm btn-outline-danger shadow-sm">
                <i class="fa-solid fa-file-pdf me-1"></i> PDF Export
            </button>

            <!-- Admin Gateway Backlink -->
            <?php if ($userRole === 'super_admin' || $userRole === 'team_member'): ?>
                <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-dark me-1 shadow-sm">
                    <i class="fa-solid fa-user-gear me-1"></i> Admin Console
                </a>
            <?php endif; ?>

            <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-outline-secondary shadow-sm" title="Sign Out">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </header>

    <div class="container-fluid px-4">
        <!-- Hidden Inputs for JS Engine -->
        <input type="hidden" id="meta-client-id" value="<?= $clientId ?>">
        <input type="hidden" id="meta-currency" value="<?= e($currency) ?>">

        <!-- Date Range Filter & Search Bar -->
        <div class="card glass-card p-3 mb-4 shadow-sm">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="btn-group shadow-sm" role="group" aria-label="Date presets">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_7">7 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_14">14 Days</button>
                    <button type="button" class="btn btn-sm btn-primary btn-preset-date active" data-preset="last_30">30 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="this_month">This Month</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_month">Last Month</button>
                </div>

                <div class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0" style="min-width: 260px;">
                    <i class="fa-regular fa-calendar text-muted"></i>
                    <input type="text" id="date-range-picker" class="form-control form-control-sm shadow-sm" placeholder="Select custom date range...">
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
                            <small class="text-muted">Calculated for selected period</small>
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
                            <small class="text-muted">Average Purchase ROAS Multiple</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_leads'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Conversions / Leads</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Total Results" data-bs-content="Count of desired action outcomes (e.g. Lead Form submissions, Purchases, or Registrations).">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-conversions">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">Total Attributed Results</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_ctr'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Click-Through Rate (CTR)</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="CTR Percentage" data-bs-content="Formula: (Total Clicks / Total Impressions) * 100. Measures ad creative engagement effectiveness.">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-ctr">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">Avg. Engagement Efficiency</small>
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
                            <small class="text-muted">Average Link Click Cost</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_impressions'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="kpi-title">Total Impressions</span>
                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Impressions" data-bs-content="Total number of times your ads were rendered on screen across Meta platforms (Facebook & Instagram).">
                                i
                            </button>
                        </div>
                        <h3 class="kpi-value" id="kpi-impressions">—</h3>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">Total Ad Views Delivered</small>
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
                    <?php if ($client['show_adsets'] ?? 1): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-semibold" id="adsets-tab" data-bs-toggle="tab" data-bs-target="#adsets-pane" type="button" role="tab">
                                <i class="fa-solid fa-cubes me-1"></i> Ad Sets Level
                            </button>
                        </li>
                    <?php endif; ?>
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
                                        <th>Impressions</th>
                                        <th>Clicks</th>
                                        <th>CTR</th>
                                        <th>CPC</th>
                                        <th>Spend</th>
                                        <th>Conversions</th>
                                        <th>ROAS</th>
                                    </tr>
                                </thead>
                                <tbody id="campaigns-table-body">
                                    <tr><td colspan="8" class="text-center text-muted py-4">Loading campaign data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Adsets Pane -->
                    <?php if ($client['show_adsets'] ?? 1): ?>
                        <div class="tab-pane fade p-3" id="adsets-pane" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Ad Set Name</th>
                                            <th>Impressions</th>
                                            <th>Clicks</th>
                                            <th>CTR</th>
                                            <th>CPC</th>
                                            <th>Spend</th>
                                            <th>Conversions</th>
                                            <th>ROAS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="adsets-table-body">
                                        <tr><td colspan="8" class="text-center text-muted py-4">Loading ad set data...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Ads Pane -->
                    <div class="tab-pane fade p-3" id="ads-pane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Ad Name</th>
                                        <th>Impressions</th>
                                        <th>Clicks</th>
                                        <th>CTR</th>
                                        <th>CPC</th>
                                        <th>Spend</th>
                                        <th>Conversions</th>
                                        <th>ROAS</th>
                                    </tr>
                                </thead>
                                <tbody id="ads-table-body">
                                    <tr><td colspan="8" class="text-center text-muted py-4">Loading ad data...</td></tr>
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
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
</body>
</html>
