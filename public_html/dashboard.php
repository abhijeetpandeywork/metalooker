<?php
/**
 * Main Client Performance Dashboard View
 *
 * Renders the white-labeled ad analytics dashboard featuring customized branding,
 * toggleable metric KPI cards, Chart.js visualizations, tabular campaign breakdowns,
 * date range presets via Flatpickr, and CSV/PDF export actions.
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
        // Grab first authorized client for team member
        $tcaStmt = $db->prepare("SELECT client_id FROM team_client_access WHERE user_id = ? LIMIT 1");
        $tcaStmt->execute([$userId]);
        $first = $tcaStmt->fetch();
        $clientId = (int)($first['client_id'] ?? 0);
    }
} elseif ($userRole === 'super_admin') {
    if ($clientId <= 0) {
        // Default to first active client for super admin view
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
$logoUrl = !empty($client['logo_path']) ? $client['logo_path'] : null;

// Allow super admin/team member to switch client view via dropdown
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($reportTitle) ?> — Digital Rubix</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Flatpickr Datepicker CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

    <style>
        :root {
            --brand-color: <?= e($brandColor) ?>;
            --brand-color-alpha: <?= e($brandColor) ?>22;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Client Top Header Bar -->
    <header class="client-dashboard-header d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center mb-2 mb-md-0">
            <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="Client Logo" style="max-height: 42px;" class="me-3">
            <?php else: ?>
                <div class="auth-logo-badge me-3 bg-brand-accent p-2 rounded">
                    <i class="fa-solid fa-chart-line text-white fs-4"></i>
                </div>
            <?php endif; ?>
            <div>
                <h4 class="m-0 fw-bold client-brand-accent"><?= e($client['business_name']) ?></h4>
                <small class="text-muted"><?= e($reportTitle) ?></small>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (!empty($allClients)): ?>
                <select class="form-select form-select-sm me-2" onchange="location.href='<?= APP_URL ?>/dashboard.php?client_id=' + this.value" style="width: auto;">
                    <?php foreach ($allClients as $ac): ?>
                        <option value="<?= $ac['id'] ?>" <?= $ac['id'] === $clientId ? 'selected' : '' ?>>
                            Switch Client: <?= e($ac['business_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <!-- Export Buttons -->
            <button id="btn-export-csv" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-file-csv me-1 text-success"></i> CSV Export
            </button>
            <button id="btn-export-pdf" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-file-pdf me-1 text-danger"></i> PDF Export
            </button>

            <!-- Admin Gateway Backlink -->
            <?php if ($userRole === 'super_admin' || $userRole === 'team_member'): ?>
                <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-dark me-2">
                    <i class="fa-solid fa-user-gear me-1"></i> Admin Portal
                </a>
            <?php endif; ?>

            <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-outline-danger" title="Sign Out">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </header>

    <div class="container-fluid px-4">
        <!-- Hidden Inputs for JS Engine -->
        <input type="hidden" id="meta-client-id" value="<?= $clientId ?>">
        <input type="hidden" id="meta-currency" value="<?= e($currency) ?>">

        <!-- Date Range Filter Bar -->
        <div class="card custom-card p-3 mb-4 shadow-sm">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="btn-group" role="group" aria-label="Date presets">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_7">7 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_14">14 Days</button>
                    <button type="button" class="btn btn-sm btn-primary btn-preset-date active" data-preset="last_30">30 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="this_month">This Month</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-preset-date" data-preset="last_month">Last Month</button>
                </div>

                <div class="d-flex align-items-center">
                    <i class="fa-regular fa-calendar me-2 text-muted"></i>
                    <input type="text" id="date-range-picker" class="form-control form-control-sm bg-white" placeholder="Select custom date range..." style="min-width: 230px;">
                </div>
            </div>
        </div>

        <!-- Metric KPI Cards Row -->
        <div class="row g-3 mb-4">
            <?php if ($client['show_spend'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-title">Total Ad Spend</span>
                            <div class="kpi-icon-wrapper"><i class="fa-solid fa-wallet"></i></div>
                        </div>
                        <h3 class="kpi-value" id="kpi-spend">—</h3>
                        <small class="text-muted">Calculated for selected period</small>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_roas'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-title">Return on Ad Spend (ROAS)</span>
                            <div class="kpi-icon-wrapper"><i class="fa-solid fa-chart-line"></i></div>
                        </div>
                        <h3 class="kpi-value text-success" id="kpi-roas">—</h3>
                        <small class="text-muted">Average Purchase ROAS</small>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_leads'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-title">Conversions / Results</span>
                            <div class="kpi-icon-wrapper"><i class="fa-solid fa-bullseye"></i></div>
                        </div>
                        <h3 class="kpi-value" id="kpi-conversions">—</h3>
                        <small class="text-muted">Total Results Triggered</small>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_ctr'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-title">Click-Through Rate (CTR)</span>
                            <div class="kpi-icon-wrapper"><i class="fa-solid fa-arrow-pointer"></i></div>
                        </div>
                        <h3 class="kpi-value" id="kpi-ctr">—</h3>
                        <small class="text-muted">Avg. Clicks vs Impressions</small>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_cpc'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-title">Cost Per Click (CPC)</span>
                            <div class="kpi-icon-wrapper"><i class="fa-solid fa-coins"></i></div>
                        </div>
                        <h3 class="kpi-value" id="kpi-cpc">—</h3>
                        <small class="text-muted">Average Link Click Cost</small>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($client['show_impressions'] ?? 1): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-title">Total Impressions</span>
                            <div class="kpi-icon-wrapper"><i class="fa-solid fa-eye"></i></div>
                        </div>
                        <h3 class="kpi-value" id="kpi-impressions">—</h3>
                        <small class="text-muted">Total Ad Impressions Delivered</small>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Visual Analytics Row (Charts) -->
        <div class="row g-4 mb-4">
            <!-- Line Chart -->
            <div class="col-lg-7">
                <div class="custom-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark m-0"><i class="fa-solid fa-chart-area me-2 client-brand-accent"></i> Daily Ad Spend Trend</h5>
                    </div>
                    <div style="height: 300px; position: relative;">
                        <canvas id="spendLineChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bar Chart -->
            <div class="col-lg-5">
                <div class="custom-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark m-0"><i class="fa-solid fa-chart-bar me-2 text-success"></i> Campaign Performance</h5>
                    </div>
                    <div style="height: 300px; position: relative;">
                        <canvas id="impClickBarChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Breakdown Tables Section -->
        <div class="card custom-card mb-5 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <ul class="nav nav-tabs card-header-tabs" id="breakdownTabs" role="tablist">
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
            </div>
            <div class="card-body p-0">
                <div class="tab-content" id="breakdownTabContent">
                    <!-- Campaigns Pane -->
                    <div class="tab-pane fade show active p-3" id="campaigns-pane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-light">
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
                                <table class="table table-hover table-striped align-middle mb-0">
                                    <thead class="table-light">
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
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-light">
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
