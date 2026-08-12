<?php
/**
 * Admin Client Directory & Management Portal
 *
 * Allows Super Admin and Team members to list, create, pause/enable, and delete client accounts,
 * as well as view token health and ad account connection status.
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
        $businessName    = trim($_POST['business_name'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $currency        = strtoupper(trim($_POST['currency'] ?? 'INR'));
        $countryName     = trim($_POST['country_name'] ?? 'India');
        $countryCode     = strtoupper(trim($_POST['country_code'] ?? 'IN'));
        $brandColor      = trim($_POST['brand_color'] ?? '#0F2D55');
        $targetLeadValue = isset($_POST['target_lead_value']) && $_POST['target_lead_value'] !== '' ? (float)$_POST['target_lead_value'] : 500.00;

        if (empty($businessName) || empty($email) || empty($password)) {
            $errorMessage = "All fields marked with * are required.";
        } else {
            try {
                $db->beginTransaction();

                $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $userStmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'client')");
                $userStmt->execute([$businessName, $email, $passwordHash]);
                $userId = (int)$db->lastInsertId();

                $clientStmt = $db->prepare("
                    INSERT INTO clients (user_id, business_name, brand_color, currency, country_code, country_name, target_lead_value, active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $clientStmt->execute([$userId, $businessName, $brandColor, $currency, $countryCode, $countryName, $targetLeadValue]);
                $clientId = (int)$db->lastInsertId();

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
                if (strpos($e->getMessage(), 'UNIQUE constraint') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $errorMessage = "A user account with this email address already exists.";
                } else {
                    $errorMessage = "Failed to create client: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Client Status Toggle (Pause / Enable)
if (isset($_GET['toggle_id'])) {
    $toggleId = (int)$_GET['toggle_id'];
    $stmt = $db->prepare("UPDATE clients SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?");
    $stmt->execute([$toggleId]);
    logActivity($_SESSION['user_id'], "Toggled active status for client ID {$toggleId}");
    header("Location: " . APP_URL . "/admin/clients.php?success=" . urlencode("Client account status updated."));
    exit;
}

// Handle Client Permanent Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_client') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "CSRF verification failed.";
    } else {
        $deleteId = (int)$_POST['delete_client_id'];
        $cStmt = $db->prepare("SELECT user_id, business_name FROM clients WHERE id = ?");
        $cStmt->execute([$deleteId]);
        $clientRow = $cStmt->fetch();
        if ($clientRow) {
            $uId   = (int)$clientRow['user_id'];
            $bName = $clientRow['business_name'];
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uId]);
            logActivity($_SESSION['user_id'], "Permanently deleted client account: {$bName} (ID: {$deleteId})");
            header("Location: " . APP_URL . "/admin/clients.php?success=" . urlencode("Client account for '{$bName}' has been permanently removed."));
            exit;
        }
    }
}

// Handle Admin Password Reset for Client Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_reset_client_password') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "CSRF verification failed.";
    } else {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $newPassword  = $_POST['new_password'] ?? '';

        if ($targetUserId <= 0 || empty($newPassword)) {
            $errorMessage = "User ID and new password are required.";
        } elseif (strlen($newPassword) < 6) {
            $errorMessage = "Password must be at least 6 characters in length.";
        } else {
            try {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $targetUserId]);

                logActivity($_SESSION['user_id'], "Admin reset password for client user ID {$targetUserId}");
                $successMessage = "Client login password has been successfully updated!";
            } catch (Exception $e) {
                $errorMessage = "Failed to reset client password: " . $e->getMessage();
            }
        }
    }
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
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Directory — MetaPanel Admin</title>
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
                    <a href="<?= APP_URL ?>/admin/index.php" class="nav-link">
                        <i class="fa-solid fa-gauge me-2"></i> Dashboard Overview
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/clients.php" class="nav-link active">
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
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/change_password.php"><i class="fa-solid fa-key me-2"></i> Change Password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Sign Out</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="admin-content flex-grow-1 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold m-0 font-heading">Agency Client Directory</h3>
                    <p class="text-muted m-0">Manage multi-client credentials, Meta tokens, and dashboard settings</p>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-dark me-2 btn-theme-toggle shadow-sm">
                        <i class="fa-solid fa-moon me-1"></i> Dark Mode
                    </button>
                    <button class="btn btn-primary shadow-sm font-heading" data-bs-toggle="modal" data-bs-target="#newClientModal">
                        <i class="fa-solid fa-plus me-1"></i> Add New Client
                    </button>
                </div>
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

            <div class="card glass-card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
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
                                            $tokenBadge = '<span class="badge bg-secondary text-white px-2 py-1"><i class="fa-solid fa-plug-circle-xmark me-1"></i> Unconnected</span>';
                                            if (!empty($c['meta_access_token']) && !empty($c['token_expires_at'])) {
                                                $expires = new DateTime($c['token_expires_at']);
                                                $now = new DateTime();
                                                $days = (int)$now->diff($expires)->format('%r%a');
                                                if ($days > 30) {
                                                    $tokenBadge = '<span class="badge bg-success text-white px-2 py-1"><i class="fa-solid fa-circle-check me-1"></i> Healthy (' . $days . 'd)</span>';
                                                } elseif ($days > 0) {
                                                    $tokenBadge = '<span class="badge bg-warning text-dark px-2 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Expiring (' . $days . 'd)</span>';
                                                } else {
                                                    $tokenBadge = '<span class="badge bg-danger text-white px-2 py-1"><i class="fa-solid fa-circle-xmark me-1"></i> Expired</span>';
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td class="fw-semibold">
                                                <span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background-color: <?= e($c['brand_color'] ?? '#0F2D55') ?>;"></span>
                                                <?= e($c['business_name']) ?>
                                            </td>
                                            <td><?= e($c['client_email']) ?></td>
                                            <td><code><?= e($c['meta_ad_account_id'] ?: 'Not Connected') ?></code></td>
                                            <td><?= $tokenBadge ?></td>
                                            <td>
                                                <?php if ($c['active']): ?>
                                                    <span class="badge bg-success text-white px-3 py-2 fw-semibold shadow-sm"><i class="fa-solid fa-circle-check me-1"></i> Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary text-white px-3 py-2 fw-semibold shadow-sm"><i class="fa-solid fa-circle-pause me-1"></i> Paused</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <!-- Reset Password Button -->
                                                <button type="button" class="btn btn-sm btn-outline-warning me-1 shadow-sm btn-reset-client-pass" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#resetClientPassModal" 
                                                        data-user-id="<?= $c['user_id'] ?>" 
                                                        data-client-name="<?= e($c['business_name']) ?>" 
                                                        data-client-email="<?= e($c['client_email']) ?>" 
                                                        title="Set New Password for Client">
                                                    <i class="fa-solid fa-key"></i>
                                                </button>
                                                <!-- Power Button: Pause / Enable Client -->
                                                <a href="<?= APP_URL ?>/admin/clients.php?toggle_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-<?= $c['active'] ? 'warning' : 'success' ?> me-1 shadow-sm" title="<?= $c['active'] ? 'Pause Client Account' : 'Enable Client Account' ?>" data-bs-toggle="tooltip">
                                                    <i class="fa-solid fa-power-off"></i>
                                                </a>
                                                <!-- Edit Button -->
                                                <a href="<?= APP_URL ?>/admin/client_edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary me-1 shadow-sm font-heading" title="Edit Profile & Meta Connection">
                                                    <i class="fa-solid fa-pen-to-square me-1"></i> Edit & OAuth
                                                </a>
                                                <!-- Trash Button: Delete Client -->
                                                <button type="button" class="btn btn-sm btn-outline-danger shadow-sm" onclick="triggerDeleteModal(<?= $c['id'] ?>, '<?= e($c['business_name']) ?>')" title="Permanently Delete Client">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
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
            <div class="modal-content glass-card">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold font-heading">Add New Client Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_client">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Business / Client Name *</label>
                            <input type="text" name="business_name" class="form-control shadow-sm" placeholder="e.g. Sharma Jewellers" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Client Login Email *</label>
                            <input type="email" name="email" class="form-control shadow-sm" placeholder="client@business.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Temporary Password *</label>
                            <input type="password" name="password" class="form-control shadow-sm" placeholder="Minimum 8 characters" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label text-muted small fw-semibold">Primary Country</label>
                                <select name="country_name" class="form-select shadow-sm">
                                    <?php 
                                    $allCountriesList = getGlobalCountriesList();
                                    foreach ($allCountriesList as $cName => $cInfo): 
                                    ?>
                                        <option value="<?= e($cName) ?>" <?= $cName === 'India' ? 'selected' : '' ?>>
                                            <?= e($cName) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label text-muted small fw-semibold">Currency</label>
                                <select name="currency" class="form-select shadow-sm">
                                    <option value="INR" selected>INR (₹ - Rupee)</option>
                                    <option value="USD">USD ($ - Dollar)</option>
                                    <option value="AED">AED (AED - Dirham)</option>
                                    <option value="EUR">EUR (€ - Euro)</option>
                                    <option value="GBP">GBP (£ - Pound)</option>
                                    <option value="SAR">SAR (SAR - Riyal)</option>
                                    <option value="QAR">QAR (QR - Riyal)</option>
                                    <option value="KWD">KWD (KD - Dinar)</option>
                                    <option value="OMR">OMR (OMR - Rial)</option>
                                    <option value="BHD">BHD (BD - Dinar)</option>
                                    <option value="CAD">CAD (CA$ - Dollar)</option>
                                    <option value="AUD">AUD (A$ - Dollar)</option>
                                    <option value="SGD">SGD (S$ - Dollar)</option>
                                    <option value="MYR">MYR (RM - Ringgit)</option>
                                    <option value="THB">THB (฿ - Baht)</option>
                                    <option value="JPY">JPY (¥ - Yen)</option>
                                    <option value="ZAR">ZAR (R - Rand)</option>
                                    <option value="BRL">BRL (R$ - Real)</option>
                                    <option value="MXN">MXN (Mex$ - Peso)</option>
                                    <option value="EGP">EGP (E£ - Pound)</option>
                                    <option value="PHP">PHP (₱ - Peso)</option>
                                    <option value="IDR">IDR (Rp - Rupiah)</option>
                                    <option value="VND">VND (₫ - Dong)</option>
                                    <option value="PKR">PKR (Rs - Rupee)</option>
                                    <option value="BDT">BDT (৳ - Taka)</option>
                                    <option value="LKR">LKR (Rs - Rupee)</option>
                                    <option value="CHF">CHF (CHF - Franc)</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label text-muted small fw-semibold">Brand Color Accent</label>
                                <input type="color" name="brand_color" class="form-control form-control-color w-100 shadow-sm" value="#0F2D55">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">
                                Target Lead / Deal Value (In Account Currency)
                                <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Target Lead Value Guide" data-bs-content="<b>E-Commerce Accounts:</b> Leave default (500) or set to 0. Meta Pixel/CAPI automatically feeds exact sales numbers.<br><br><b>Lead Gen / WhatsApp Accounts:</b> Enter expected deal value per lead so ROAS can be calculated as (Leads * Target Value) / Ad Spend.">
                                    i
                                </button>
                            </label>
                            <input type="number" step="0.01" min="0" name="target_lead_value" class="form-control shadow-sm" value="500.00">
                            <small class="text-muted">For Lead Gen / WhatsApp ads. E-commerce Pixel purchase ads auto-detect sales value from Meta.</small>
                        </div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-heading">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteClientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-card">
                <div class="modal-header border-bottom bg-danger bg-opacity-10">
                    <h5 class="modal-title fw-bold text-danger font-heading"><i class="fa-solid fa-triangle-exclamation me-2"></i> Confirm Permanent Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_client">
                    <input type="hidden" name="delete_client_id" id="delete_client_id" value="">
                    <div class="modal-body p-4 text-center">
                        <i class="fa-solid fa-trash-can text-danger fs-1 mb-3"></i>
                        <h5 class="fw-bold mb-2">Delete <span id="delete_client_name" class="text-danger"></span>?</h5>
                        <p class="text-muted small mb-0">
                            This action is permanent and cannot be undone. All client user credentials, dashboard configurations, and cached Meta advertising data will be deleted immediately.
                        </p>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger font-heading px-4">Yes, Delete Permanently</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Client Password Modal -->
    <div class="modal fade" id="resetClientPassModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-card">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-heading"><i class="fa-solid fa-key text-warning me-2"></i> Set Client Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="admin_reset_client_password">
                    <input type="hidden" name="target_user_id" id="reset_client_user_id" value="">

                    <div class="modal-body">
                        <div class="alert alert-light border small text-muted mb-3">
                            Setting a new password for: <strong id="reset_client_business_name" class="text-dark"></strong> (<span id="reset_client_login_email"></span>)
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">New Password *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="new_password" id="modal_client_new_pass" class="form-control shadow-sm" placeholder="Minimum 6 characters" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" id="btnToggleClientPass">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button class="btn btn-outline-primary" type="button" id="btnGenClientPass" title="Generate Random Password">
                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Generate
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning font-heading shadow-sm"><i class="fa-solid fa-check me-1"></i> Save New Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.APP_URL = "<?= APP_URL ?>";

        function triggerDeleteModal(clientId, clientName) {
            document.getElementById('delete_client_id').value = clientId;
            document.getElementById('delete_client_name').innerText = clientName;
            var modal = new bootstrap.Modal(document.getElementById('deleteClientModal'));
            modal.show();
        }

        document.querySelectorAll('.btn-reset-client-pass').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('reset_client_user_id').value = this.dataset.userId;
                document.getElementById('reset_client_business_name').textContent = this.dataset.clientName;
                document.getElementById('reset_client_login_email').textContent = this.dataset.clientEmail;
                document.getElementById('modal_client_new_pass').value = '';
            });
        });

        document.getElementById('btnToggleClientPass')?.addEventListener('click', function() {
            const input = document.getElementById('modal_client_new_pass');
            const icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        document.getElementById('btnGenClientPass')?.addEventListener('click', function() {
            const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
            let pass = '';
            for (let i = 0; i < 10; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            const input = document.getElementById('modal_client_new_pass');
            input.type = 'text';
            input.value = pass;
            document.querySelector('#btnToggleClientPass i').classList.replace('fa-eye', 'fa-eye-slash');
        });
    </script>
    <script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
</body>
</html>
