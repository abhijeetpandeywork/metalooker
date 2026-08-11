<?php
/**
 * Admin Team Member Access Control Console
 *
 * Manages team member user accounts (e.g. Tanisha / Kumkum) and assigns selective
 * per-client access rights.
 *
 * @package MetaPanel\Admin
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole('super_admin');

$db = Database::getInstance();
$successMessage = $_GET['success'] ?? null;
$errorMessage   = $_GET['error'] ?? null;

// Handle New Team Member Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_team_member') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "CSRF verification failed.";
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            $errorMessage = "All fields are required.";
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'team_member')");
                $stmt->execute([$name, $email, $hash]);

                logActivity($_SESSION['user_id'], "Added new team member: {$name} ({$email})");
                $successMessage = "Team member created successfully.";
            } catch (Exception $e) {
                $errorMessage = "Error creating team member: " . $e->getMessage();
            }
        }
    }
}

// Handle Client Access Assignment Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_access') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "CSRF verification failed.";
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $clientIds    = $_POST['client_ids'] ?? [];

        try {
            $db->beginTransaction();

            $delStmt = $db->prepare("DELETE FROM team_client_access WHERE user_id = ?");
            $delStmt->execute([$targetUserId]);

            if (!empty($clientIds) && is_array($clientIds)) {
                $insStmt = $db->prepare("INSERT INTO team_client_access (user_id, client_id) VALUES (?, ?)");
                foreach ($clientIds as $cId) {
                    $insStmt->execute([$targetUserId, (int)$cId]);
                }
            }

            $db->commit();
            logActivity($_SESSION['user_id'], "Updated client access rights for team member ID {$targetUserId}");
            $successMessage = "Team member client access updated successfully.";
        } catch (Exception $e) {
            $db->rollBack();
            $errorMessage = "Failed to update access rights: " . $e->getMessage();
        }
    }
}

// Fetch All Team Members
$teamMembersStmt = $db->query("SELECT * FROM users WHERE role = 'team_member' ORDER BY name ASC");
$teamMembers = $teamMembersStmt->fetchAll();

// Fetch Active Clients
$activeClientsStmt = $db->query("SELECT id, business_name FROM clients WHERE active = 1 ORDER BY business_name ASC");
$activeClients = $activeClientsStmt->fetchAll();

// Map User Client Access
$accessMap = [];
$tcaStmt = $db->query("SELECT user_id, client_id FROM team_client_access");
while ($row = $tcaStmt->fetch()) {
    $accessMap[$row['user_id']][] = (int)$row['client_id'];
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Access Control — MetaPanel Admin</title>
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
                    <a href="<?= APP_URL ?>/admin/clients.php" class="nav-link">
                        <i class="fa-solid fa-building-user me-2"></i> Client Directory
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/team.php" class="nav-link active">
                        <i class="fa-solid fa-users me-2"></i> Team Access
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/settings.php" class="nav-link">
                        <i class="fa-solid fa-gears me-2"></i> Meta App Settings
                    </a>
                </li>
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

        <!-- Main Content View -->
        <div class="admin-content flex-grow-1 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold m-0 font-heading">Team Member Access Management</h3>
                    <p class="text-muted m-0">Assign selective client view permissions to team members (e.g. Tanisha / Kumkum)</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-dark btn-theme-toggle me-2 shadow-sm">
                        <i class="fa-solid fa-moon me-1"></i> Dark Mode
                    </button>
                    <button class="btn btn-primary shadow-sm font-heading" data-bs-toggle="modal" data-bs-target="#newTeamMemberModal">
                        <i class="fa-solid fa-user-plus me-1"></i> Add Team Member
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

            <div class="row">
                <?php if (empty($teamMembers)): ?>
                    <div class="col-12 text-center text-muted py-5">
                        <i class="fa-solid fa-users-slash fs-1 mb-3"></i>
                        <p>No team members created yet. Click "Add Team Member" above to create one.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($teamMembers as $tm): ?>
                        <?php $assignedClientIds = $accessMap[$tm['id']] ?? []; ?>
                        <div class="col-lg-6 mb-4">
                            <div class="card glass-card shadow-sm">
                                <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
                                    <h5 class="m-0 font-heading"><i class="fa-solid fa-id-badge text-info me-2"></i> <?= e($tm['name']) ?></h5>
                                    <small class="text-muted"><?= e($tm['email']) ?></small>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_access">
                                        <input type="hidden" name="user_id" value="<?= $tm['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                        <label class="form-label text-muted small fw-semibold mb-2">Assigned Client Portals:</label>
                                        <div class="row g-2 mb-3 max-vh-25 overflow-auto">
                                            <?php foreach ($activeClients as $ac): ?>
                                                <?php $isAssigned = in_array($ac['id'], $assignedClientIds, true); ?>
                                                <div class="col-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="client_ids[]" value="<?= $ac['id'] ?>" id="tm_<?= $tm['id'] ?>_c_<?= $ac['id'] ?>" <?= $isAssigned ? 'checked' : '' ?>>
                                                        <label class="form-check-label text-muted small" for="tm_<?= $tm['id'] ?>_c_<?= $ac['id'] ?>">
                                                            <?= e($ac['business_name']) ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <button type="submit" class="btn btn-sm btn-success shadow-sm font-heading">
                                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Permissions
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Team Member Modal -->
    <div class="modal fade" id="newTeamMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-card">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-heading"><i class="fa-solid fa-user-plus text-primary me-2"></i> Register Team Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_team_member">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Full Name *</label>
                            <input type="text" name="name" class="form-control shadow-sm" placeholder="e.g. Tanisha Sharma" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Email Address *</label>
                            <input type="email" name="email" class="form-control shadow-sm" placeholder="tanisha@digitalrubix.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Password *</label>
                            <input type="password" name="password" class="form-control shadow-sm" placeholder="••••••••" required>
                        </div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-heading shadow-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Create Member</button>
                    </div>
                </form>
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
