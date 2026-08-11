<?php
/**
 * Admin Portal Overview Landing Page
 *
 * Provides agency super admins and team members with aggregate client stats, total ad spend metrics,
 * recent activity audits, and quick navigation shortcuts.
 *
 * @package MetaPanel\Admin
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['super_admin', 'team_member']);

$db = Database::getInstance();
$role = $_SESSION['user_role'];
$userId = $_SESSION['user_id'];

// Client Count Calculation
if ($role === 'super_admin') {
    $clientCountStmt = $db->query("SELECT COUNT(*) as cnt FROM clients WHERE active = 1");
    $totalClients = (int)($clientCountStmt->fetch()['cnt'] ?? 0);

    $thirtyDaysAgo = (new DateTime())->modify('-30 days')->format('Y-m-d');

    $spendStmt = $db->prepare("
        SELECT c.currency, SUM(adc.spend) as cat_spend
        FROM ad_data_cache adc
        JOIN clients c ON c.id = adc.client_id
        WHERE adc.level = 'campaign' AND adc.date_start >= ? AND c.active = 1
        GROUP BY c.currency
    ");
    $spendStmt->execute([$thirtyDaysAgo]);
    $spendByCurrency = $spendStmt->fetchAll();
} else {
    $clientCountStmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM team_client_access tca
        JOIN clients c ON c.id = tca.client_id
        WHERE tca.user_id = ? AND c.active = 1
    ");
    $clientCountStmt->execute([$userId]);
    $totalClients = (int)($clientCountStmt->fetch()['cnt'] ?? 0);

    $thirtyDaysAgo = (new DateTime())->modify('-30 days')->format('Y-m-d');
    $spendStmt = $db->prepare("
        SELECT c.currency, SUM(adc.spend) as cat_spend
        FROM ad_data_cache adc
        JOIN clients c ON c.id = adc.client_id
        JOIN team_client_access tca ON c.id = tca.client_id
        WHERE tca.user_id = ? AND adc.level = 'campaign' AND adc.date_start >= ? AND c.active = 1
        GROUP BY c.currency
    ");
    $spendStmt->execute([$userId, $thirtyDaysAgo]);
    $spendByCurrency = $spendStmt->fetchAll();
}

$totalSpendInrEquiv = 0.0;
$currencyBreakdowns = [];

foreach ($spendByCurrency as $row) {
    $curr = strtoupper($row['currency'] ?: 'INR');
    $val  = (float)$row['cat_spend'];
    if ($val > 0) {
        $currencyBreakdowns[$curr] = $val;
        $totalSpendInrEquiv += convertToInr($val, $curr);
    }
}

// Fetch Recent Activity Audit Log
$activityStmt = $db->query("
    SELECT al.*, u.name as user_name, u.role as user_role
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.id DESC
    LIMIT 15
");
$recentActivities = $activityStmt->fetchAll();

// Fetch Active Clients Table
$clientsStmt = $db->query("
    SELECT c.*, u.email as client_email,
           (SELECT synced_at FROM sync_logs WHERE client_id = c.id ORDER BY id DESC LIMIT 1) as last_sync
    FROM clients c
    JOIN users u ON c.user_id = u.id
    WHERE c.active = 1
    ORDER BY c.business_name ASC
");
$activeClients = $clientsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agency Console Overview — MetaPanel Admin</title>
    <!-- Prevent FOUT Theme Script -->
    <script>
        (function() {
            var t = localStorage.getItem('metapanel_theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
    <div class="d-flex admin-wrapper">
        <!-- Sidebar Navigation -->
        <div class="admin-sidebar p-3 text-white">
            <div class="d-flex align-items-center mb-4">
                <img src="<?= APP_URL ?>/assets/logos/digital_rubix_logo.svg" alt="Digital Rubix Logo" style="height: 40px;" class="me-2">
            </div>
            <hr class="text-secondary">
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/index.php" class="nav-link active">
                        <i class="fa-solid fa-gauge me-2"></i> Dashboard Overview
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/clients.php" class="nav-link">
                        <i class="fa-solid fa-building-user me-2"></i> Client Directory
                    </a>
                </li>
                <?php if (isSuperAdmin()): ?>
                    <li class="nav-item mb-1">
                        <a href="<?= APP_URL ?>/admin/team.php" class="nav-link">
                            <i class="fa-solid fa-users me-2"></i> Team Access
                        </a>
                    </li>
                    <li class="nav-item mb-1">
                        <a href="<?= APP_URL ?>/admin/settings.php" class="nav-link">
                            <i class="fa-solid fa-gears me-2"></i> Meta App Settings
                        </a>
                    </li>
                <?php endif; ?>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/sync_status.php" class="nav-link">
                        <i class="fa-solid fa-arrows-rotate me-2"></i> Cron Sync Status
                    </a>
                </li>
            </ul>
            <hr class="text-secondary">
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa-solid fa-circle-user fs-4 me-2"></i>
                    <strong><?= e($_SESSION['user_name']) ?></strong>
                </a>
                <ul class="dropdown-menu dropdown-menu-dark shadow">
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/logout.php">Sign Out</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="admin-content flex-grow-1 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold m-0 font-heading">Agency Operations Dashboard</h3>
                    <p class="text-muted m-0">Digital Rubix Meta Panel</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-dark btn-theme-toggle me-2 shadow-sm">
                        <i class="fa-solid fa-moon me-1"></i> Dark Mode
                    </button>
                    <a href="<?= APP_URL ?>/admin/clients.php" class="btn btn-primary shadow-sm font-heading">
                        <i class="fa-solid fa-plus me-1"></i> Add New Client
                    </a>
                </div>
            </div>

            <!-- Stats Overview Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-4">
                    <div class="card glass-card p-3 h-100 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase font-heading">
                                    Active Client Portals
                                    <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Active Portals" data-bs-content="Total number of client accounts currently enabled for login and reporting.">
                                        i
                                    </button>
                                </span>
                                <h2 class="fw-bold m-0 mt-1 font-heading"><?= formatNumber($totalClients) ?></h2>
                            </div>
                            <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-3">
                                <i class="fa-solid fa-users-viewfinder fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="card glass-card p-3 h-100 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase font-heading">
                                    Total Ad Spend Managed (30 Days)
                                    <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Multi-Currency Spend" data-bs-content="Calculates cumulative ad spend across multi-currency client accounts converted to INR base rate for accurate agency financial reporting.">
                                        i
                                    </button>
                                </span>
                                <h2 class="fw-bold m-0 text-success mt-1 font-heading">
                                    <?= formatCurrency($totalSpendInrEquiv, 'INR') ?> 
                                    <span class="fs-6 text-muted font-normal" style="font-size: 13px; font-weight: 500;">(Normalized INR)</span>
                                </h2>
                                <?php if (!empty($currencyBreakdowns)): ?>
                                    <div class="mt-2 d-flex flex-wrap gap-1">
                                        <?php foreach ($currencyBreakdowns as $cCode => $cVal): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 11px;">
                                                <?= formatCurrency($cVal, $cCode) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="p-3 bg-success bg-opacity-10 text-success rounded-3 align-self-start">
                                <i class="fa-solid fa-money-bill-trend-up fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="card glass-card p-3 h-100 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase font-heading">Meta API Mode</span>
                                <h2 class="fw-bold m-0 text-warning mt-1 font-heading"><?= MOCK_META_API ? 'Mock Data Mode' : 'Live Graph API' ?></h2>
                            </div>
                            <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-3">
                                <i class="fa-solid fa-server fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Clients List Table -->
            <div class="card glass-card mb-4 shadow-sm">
                <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-heading"><i class="fa-solid fa-building me-2 text-primary"></i> Managed Client Portals</h5>
                    <a href="<?= APP_URL ?>/admin/clients.php" class="btn btn-sm btn-outline-secondary shadow-sm">Manage Directory</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Business Name</th>
                                    <th>Client Email</th>
                                    <th>Meta Ad Account</th>
                                    <th>Currency</th>
                                    <th>Last Synced</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($activeClients)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No active client accounts found. Click "Add New Client" to start.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($activeClients as $c): ?>
                                        <tr>
                                            <td class="fw-semibold">
                                                <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: <?= e($c['brand_color'] ?? '#0F2D55') ?>;"></span>
                                                <?= e($c['business_name']) ?>
                                            </td>
                                            <td><?= e($c['client_email']) ?></td>
                                            <td><code><?= e($c['meta_ad_account_id'] ?: 'Not Connected') ?></code></td>
                                            <td><span class="badge bg-secondary bg-opacity-20 text-body border"><?= e($c['currency']) ?></span></td>
                                            <td class="small text-muted"><?= e($c['last_sync'] ? date('d M Y, g:i A', strtotime($c['last_sync'])) : 'Never') ?></td>
                                            <td class="text-end">
                                                <a href="<?= APP_URL ?>/dashboard.php?client_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info me-1 shadow-sm" target="_blank" title="Preview Dashboard">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Dashboard
                                                </a>
                                                <a href="<?= APP_URL ?>/admin/client_edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm" title="Edit Settings & Meta OAuth">
                                                    <i class="fa-solid fa-gear"></i> Config
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Activity Log Audit Trail -->
            <div class="card glass-card shadow-sm">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="m-0 font-heading"><i class="fa-solid fa-shield-halved text-warning me-2"></i> Security & Activity Log</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivities as $act): ?>
                                    <tr>
                                        <td class="small text-muted"><?= date('d M Y H:i:s', strtotime($act['created_at'])) ?></td>
                                        <td class="fw-semibold"><?= e($act['user_name'] ?? 'System / Anonymous') ?></td>
                                        <td><span class="badge bg-secondary bg-opacity-15 text-body"><?= e($act['user_role'] ?? 'guest') ?></span></td>
                                        <td><?= e($act['action']) ?></td>
                                        <td class="small text-muted"><code><?= e($act['ip']) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.APP_URL = "<?= APP_URL ?>";
    </script>
    <script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
</body>
</html>
