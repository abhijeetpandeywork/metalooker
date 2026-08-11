<?php
/**
 * Admin Client Account Management Console
 *
 * Provides CRUD interface for creating client user accounts, toggling active status,
 * managing Meta Ad Account credentials, and routing to client configuration.
 *
 * @package MetaPanel\Admin
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['super_admin', 'team_member']);

$db = Database::getInstance();
$successMessage = $_GET['success'] ?? null;
$errorMessage   = $_GET['error'] ?? null;

// Handle New Client Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_client') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "CSRF verification failed.";
    } else {
        $businessName = trim($_POST['business_name'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $password     = $_POST['password'] ?? '';
        $currency     = strtoupper(trim($_POST['currency'] ?? 'INR'));
        $brandColor   = trim($_POST['brand_color'] ?? '#0F2D55');

        if (empty($businessName) || empty($email) || empty($password)) {
            $errorMessage = "All fields marked with * are required.";
        } else {
            try {
                $db->beginTransaction();

                // 1. Create User Login Account
                $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $userStmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'client')");
                $userStmt->execute([$businessName, $email, $passwordHash]);
                $userId = (int)$db->lastInsertId();

                // 2. Create Client Record
                $clientStmt = $db->prepare("
                    INSERT INTO clients (user_id, business_name, brand_color, currency, active)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $clientStmt->execute([$userId, $businessName, $brandColor, $currency]);
                $clientId = (int)$db->lastInsertId();

                // 3. Initialize Default Dashboard Config
                $configStmt = $db->prepare("
                    INSERT INTO dashboard_config (client_id, default_range, report_title)
                    VALUES (?, 'last_30', ?)
                ");
                $configStmt->execute([$clientId, $businessName . ' Performance Dashboard']);

                $db->commit();
                logActivity($_SESSION['user_id'], "Created new client account: {$businessName} (ID: {$clientId})");

                $successMessage = "Client account created successfully. You can now configure Meta connection.";
            } catch (Exception $e) {
                $db->rollBack();
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $errorMessage = "A user account with this email address already exists.";
                } else {
                    $errorMessage = "Failed to create client: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Client Status Toggle (Activate/Deactivate)
if (isset($_GET['toggle_id'])) {
    $toggleId = (int)$_GET['toggle_id'];
    $stmt = $db->prepare("UPDATE clients SET active = IF(active=1, 0, 1) WHERE id = ?");
    $stmt->execute([$toggleId]);
    logActivity($_SESSION['user_id'], "Toggled active status for client ID {$toggleId}");
    header("Location: " . APP_URL . "/admin/clients.php?success=" . urlencode("Client status updated."));
    exit;
}

// Fetch All Clients
$clientsQuery = "
    SELECT c.*, u.email as client_email, u.created_at as user_created_at,
           (SELECT synced_at FROM sync_logs WHERE client_id = c.id ORDER BY id DESC LIMIT 1) as last_sync
    FROM clients c
    JOIN users u ON c.user_id = u.id
    ORDER BY c.id DESC
";
$clients = $db->query($clientsQuery)->fetchAll();

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Directory — MetaPanel Admin</title>
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
                    <a href="<?= APP_URL ?>/admin/index.php" class="nav-link text-white">
                        <i class="fa-solid fa-gauge me-2"></i> Dashboard Overview
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/clients.php" class="nav-link active">
                        <i class="fa-solid fa-building-user me-2"></i> Client Management
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/team.php" class="nav-link text-white">
                        <i class="fa-solid fa-users me-2"></i> Team Access
                    </a>
                </li>
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

        <!-- Main Content View -->
        <div class="admin-content flex-grow-1 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold m-0 text-white">Agency Client Portals</h3>
                    <p class="text-muted m-0">Manage multi-client login credentials, Meta access tokens, and portal configurations</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClientModal">
                    <i class="fa-solid fa-plus me-1"></i> Add New Client
                </button>
            </div>

            <?php if ($successMessage): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i> <?= e($successMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= e($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Client Directory Table -->
            <div class="card bg-dark text-white border-secondary shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Business Name</th>
                                    <th>Client Login Email</th>
                                    <th>Meta Ad Account</th>
                                    <th>Token Health</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($clients)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No clients registered. Click "Add New Client" above to create your first client.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($clients as $c): ?>
                                        <?php
                                            $tokenBadge = '<span class="badge bg-secondary">Unconnected</span>';
                                            if (!empty($c['meta_access_token']) && !empty($c['token_expires_at'])) {
                                                $expires = new DateTime($c['token_expires_at']);
                                                $now = new DateTime();
                                                $days = (int)$now->diff($expires)->format('%r%a');
                                                if ($days > 30) {
                                                    $tokenBadge = '<span class="badge bg-success">Healthy (' . $days . 'd)</span>';
                                                } elseif ($days > 0) {
                                                    $tokenBadge = '<span class="badge bg-warning text-dark">Expiring (' . $days . 'd)</span>';
                                                } else {
                                                    $tokenBadge = '<span class="badge bg-danger">Expired</span>';
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td class="fw-semibold text-white">
                                                <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: <?= e($c['brand_color'] ?? '#0F2D55') ?>;"></span>
                                                <?= e($c['business_name']) ?>
                                            </td>
                                            <td><?= e($c['client_email']) ?></td>
                                            <td><code><?= e($c['meta_ad_account_id'] ?: 'Not Connected') ?></code></td>
                                            <td><?= $tokenBadge ?></td>
                                            <td>
                                                <?php if ($c['active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="<?= APP_URL ?>/admin/clients.php?toggle_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning me-1" title="Toggle Active Status">
                                                    <i class="fa-solid fa-power-off"></i>
                                                </a>
                                                <a href="<?= APP_URL ?>/admin/client_edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="fa-solid fa-pen-to-square me-1"></i> Edit & OAuth
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
        </div>
    </div>

    <!-- Create New Client Modal -->
    <div class="modal fade" id="newClientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="fa-solid fa-building-circle-check text-primary me-2"></i> Register New Client</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_client">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-muted">Business Name *</label>
                            <input type="text" name="business_name" class="form-control bg-dark text-white border-secondary" placeholder="e.g. Sharma Jewellers" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted">Client Login Email *</label>
                            <input type="email" name="email" class="form-control bg-dark text-white border-secondary" placeholder="client@sharmajewellers.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted">Client Password *</label>
                            <input type="password" name="password" class="form-control bg-dark text-white border-secondary" placeholder="••••••••" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted">Reporting Currency</label>
                                <select name="currency" class="form-select bg-dark text-white border-secondary">
                                    <option value="INR">INR (₹)</option>
                                    <option value="USD">USD ($)</option>
                                    <option value="AED">AED (AED)</option>
                                    <option value="EUR">EUR (€)</option>
                                    <option value="GBP">GBP (£)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted">Brand Color Accent</label>
                                <input type="color" name="brand_color" class="form-control form-control-color w-100 bg-dark border-secondary" value="#0F2D55">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Create Client Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
