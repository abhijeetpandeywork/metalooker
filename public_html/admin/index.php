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

    $spendStmt = $db->query("SELECT SUM(spend) as total_spend FROM ad_data_cache WHERE level = 'campaign'");
    $totalSpend = (float)($spendStmt->fetch()['total_spend'] ?? 0.0);

    $clientsListStmt = $db->query("
        SELECT c.*, u.email as client_email,
               (SELECT synced_at FROM sync_logs WHERE client_id = c.id ORDER BY id DESC LIMIT 1) as last_synced
        FROM clients c
        JOIN users u ON c.user_id = u.id
        WHERE c.active = 1
        ORDER BY c.business_name ASC
    ");
} else { // team_member
    $clientCountStmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM team_client_access tca
        JOIN clients c ON c.id = tca.client_id
        WHERE tca.user_id = ? AND c.active = 1
    ");
    $clientCountStmt->execute([$userId]);
    $totalClients = (int)($clientCountStmt->fetch()['cnt'] ?? 0);

    $spendStmt = $db->prepare("
        SELECT SUM(adc.spend) as total_spend
        FROM ad_data_cache adc
        JOIN team_client_access tca ON adc.client_id = tca.client_id
        WHERE tca.user_id = ? AND adc.level = 'campaign'
    ");
    $spendStmt->execute([$userId]);
    $totalSpend = (float)($spendStmt->fetch()['total_spend'] ?? 0.0);
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agency Console Overview — MetaPanel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="admin-body">
    <div class="d-flex">
        <!-- Sidebar Navigation -->
        <div class="admin-sidebar p-3 text-white">
            <div class="d-flex align-items-center mb-4">
                <i class="fa-solid fa-chart-line fs-3 text-primary me-2"></i>
                <h5 class="m-0 fw-bold">MetaPanel</h5>
            </div>
            <hr class="text-secondary">
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/index.php" class="nav-link active">
                        <i class="fa-solid fa-gauge me-2"></i> Dashboard Overview
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/clients.php" class="nav-link text-white">
                        <i class="fa-solid fa-building-user me-2"></i> Client Management
                    </a>
                </li>
                <?php if ($role === 'super_admin'): ?>
                    <li class="nav-item mb-1">
                        <a href="<?= APP_URL ?>/admin/team.php" class="nav-link text-white">
                            <i class="fa-solid fa-users me-2"></i> Team Access
                        </a>
                    </li>
                <?php endif; ?>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/sync_status.php" class="nav-link text-white">
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
                    <h3 class="fw-bold m-0 text-white">Agency Operations Dashboard</h3>
                    <p class="text-muted m-0">Digital Rubix — Multi-Client Meta Ads Management System</p>
                </div>
                <div>
                    <a href="<?= APP_URL ?>/admin/clients.php?action=new" class="btn btn-primary">
                        <i class="fa-solid fa-plus me-1"></i> Add New Client
                    </a>
                </div>
            </div>

            <!-- Stats Overview Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-4">
                    <div class="card bg-dark text-white border-secondary p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase">Active Clients</span>
                                <h2 class="fw-bold m-0 text-white mt-1"><?= formatNumber($totalClients) ?></h2>
                            </div>
                            <div class="p-3 bg-primary bg-opacity-20 text-primary rounded-3">
                                <i class="fa-solid fa-users-viewfinder fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="card bg-dark text-white border-secondary p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase">Total Ad Spend Tracked</span>
                                <h2 class="fw-bold m-0 text-success mt-1"><?= formatCurrency($totalSpend, 'INR') ?></h2>
                            </div>
                            <div class="p-3 bg-success bg-opacity-20 text-success rounded-3">
                                <i class="fa-solid fa-money-bill-trend-up fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="card bg-dark text-white border-secondary p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase">System Mode</span>
                                <h2 class="fw-bold m-0 text-warning mt-1"><?= MOCK_META_API ? 'Mock Mode' : 'Live Graph API' ?></h2>
                            </div>
                            <div class="p-3 bg-warning bg-opacity-20 text-warning rounded-3">
                                <i class="fa-solid fa-server fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Clients List Table -->
            <div class="card bg-dark text-white border-secondary mb-4 shadow-sm">
                <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                    <h5 class="m-0"><i class="fa-solid fa-building me-2 text-info"></i> Managed Client Portals</h5>
                    <a href="<?= APP_URL ?>/admin/clients.php" class="btn btn-sm btn-outline-light">Manage All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
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
                                            <td class="fw-semibold text-white">
                                                <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: <?= e($c['brand_color'] ?? '#0F2D55') ?>;"></span>
                                                <?= e($c['business_name']) ?>
                                            </td>
                                            <td><?= e($c['client_email']) ?></td>
                                            <td><code><?= e($c['meta_ad_account_id'] ?: 'Not Connected') ?></code></td>
                                            <td><span class="badge bg-secondary"><?= e($c['currency']) ?></span></td>
                                            <td class="small text-muted"><?= e($c['last_sync'] ? date('d M Y, g:i A', strtotime($c['last_sync'])) : 'Never') ?></td>
                                            <td class="text-end">
                                                <a href="<?= APP_URL ?>/dashboard.php?client_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info me-1" target="_blank" title="Preview Client Dashboard">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Dashboard
                                                </a>
                                                <a href="<?= APP_URL ?>/admin/client_edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Settings & Meta OAuth">
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
            <div class="card bg-dark text-white border-secondary shadow-sm">
                <div class="card-header border-secondary">
                    <h5 class="m-0"><i class="fa-solid fa-shield-halved text-warning me-2"></i> Recent Security & System Activity</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped align-middle mb-0">
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
                                        <td><span class="badge bg-secondary"><?= e($act['user_role'] ?? 'guest') ?></span></td>
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
</body>
</html>
