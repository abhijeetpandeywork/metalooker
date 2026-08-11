<?php
/**
 * Admin Cron Sync Log Monitor
 *
 * Displays recent synchronization logs per client, status indicators, and provides
 * manual AJAX sync trigger actions.
 *
 * @package MetaPanel\Admin
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['super_admin', 'team_member']);

$db = Database::getInstance();

// Fetch Active Clients with last sync info
$clientsStmt = $db->query("
    SELECT c.id, c.business_name, c.meta_ad_account_id,
           (SELECT synced_at FROM sync_logs WHERE client_id = c.id ORDER BY id DESC LIMIT 1) as last_sync,
           (SELECT status FROM sync_logs WHERE client_id = c.id ORDER BY id DESC LIMIT 1) as last_status
    FROM clients c
    WHERE c.active = 1
    ORDER BY c.business_name ASC
");
$clients = $clientsStmt->fetchAll();

// Fetch Last 30 Global Sync Log Entries
$logsStmt = $db->query("
    SELECT sl.*, c.business_name
    FROM sync_logs sl
    JOIN clients c ON sl.client_id = c.id
    ORDER BY sl.id DESC
    LIMIT 30
");
$recentLogs = $logsStmt->fetchAll();

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cron Sync Console — MetaPanel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
                    <a href="<?= APP_URL ?>/admin/index.php" class="nav-link">
                        <i class="fa-solid fa-gauge me-2"></i> Dashboard Overview
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/clients.php" class="nav-link">
                        <i class="fa-solid fa-building-user me-2"></i> Client Directory
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/team.php" class="nav-link">
                        <i class="fa-solid fa-users me-2"></i> Team Access
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/sync_status.php" class="nav-link active">
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
                    <h3 class="fw-bold m-0">Meta API Data Sync Console</h3>
                    <p class="text-muted m-0">Monitor 6-hour cron sync status and trigger manual client refreshes</p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-dark btn-theme-toggle">
                    <i class="fa-solid fa-moon me-1"></i> Dark Mode
                </button>
            </div>

            <!-- Client Sync Triggers Grid -->
            <div class="card glass-card mb-4 shadow-sm">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="m-0"><i class="fa-solid fa-bolt text-warning me-2"></i> Quick Sync Console</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Client Business</th>
                                    <th>Ad Account ID</th>
                                    <th>Last Synced</th>
                                    <th>Last Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $c): ?>
                                    <tr id="client-row-<?= $c['id'] ?>">
                                        <td class="fw-semibold"><?= e($c['business_name']) ?></td>
                                        <td><code><?= e($c['meta_ad_account_id'] ?: 'N/A') ?></code></td>
                                        <td class="sync-time"><?= e($c['last_sync'] ? date('d M Y, h:i A', strtotime($c['last_sync'])) : 'Never') ?></td>
                                        <td class="sync-status">
                                            <?php if ($c['last_status'] === 'success'): ?>
                                                <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25">Success</span>
                                            <?php elseif ($c['last_status'] === 'error'): ?>
                                                <span class="badge bg-danger bg-opacity-15 text-danger border border-danger border-opacity-25">Error</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary btn-sync-now" data-client-id="<?= $c['id'] ?>">
                                                <i class="fa-solid fa-rotate me-1"></i> Sync Now
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Execution History Table -->
            <div class="card glass-card shadow-sm">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="m-0"><i class="fa-solid fa-list-check text-info me-2"></i> Execution History (Last 30 Runs)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Client Name</th>
                                    <th>Status</th>
                                    <th>Rows Pulled</th>
                                    <th>Error Log</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td class="small text-muted"><?= date('d M Y H:i:s', strtotime($log['synced_at'])) ?></td>
                                        <td class="fw-semibold"><?= e($log['business_name']) ?></td>
                                        <td>
                                            <?php if ($log['status'] === 'success'): ?>
                                                <span class="badge bg-success bg-opacity-15 text-success"><i class="fa-solid fa-check me-1"></i> Success</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-15 text-danger"><i class="fa-solid fa-xmark me-1"></i> Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= formatNumber($log['rows_inserted']) ?></span></td>
                                        <td class="small text-danger text-truncate" style="max-width: 280px;" title="<?= e($log['error_message']) ?>">
                                            <?= e($log['error_message'] ?: '—') ?>
                                        </td>
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
    <script>
        document.querySelectorAll('.btn-sync-now').forEach(button => {
            button.addEventListener('click', function() {
                const clientId = this.getAttribute('data-client-id');
                const row = document.getElementById(`client-row-${clientId}`);
                const btn = this;
                
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Syncing...';

                fetch('<?= APP_URL ?>/api/sync.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ client_id: clientId })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        row.querySelector('.sync-time').innerText = data.synced_at;
                        row.querySelector('.sync-status').innerHTML = '<span class="badge bg-success bg-opacity-15 text-success">Success</span>';
                        btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Done!';
                        setTimeout(() => { location.reload(); }, 1200);
                    } else {
                        row.querySelector('.sync-status').innerHTML = '<span class="badge bg-danger bg-opacity-15 text-danger">Error</span>';
                        alert('Sync Failed: ' + data.error);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i> Sync Now';
                    }
                })
                .catch(err => {
                    alert('Request failed: ' + err);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i> Sync Now';
                });
            });
        });
    </script>
</body>
</html>
