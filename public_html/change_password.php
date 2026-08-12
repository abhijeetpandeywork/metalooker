<?php
/**
 * User Self-Service Password Update Portal
 *
 * Allows authenticated users (Super Admin, Team Members, Clients) to update
 * their account login password.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireAuth();

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'client';
$userName = $_SESSION['user_name'] ?? 'User';

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "Security validation failed. Please refresh and try again.";
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $errorMessage = "All password fields are required.";
        } elseif (strlen($newPassword) < 6) {
            $errorMessage = "New password must be at least 6 characters in length.";
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "New password and confirmation do not match.";
        } else {
            // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $userRow = $stmt->fetch();

            if (!$userRow || !password_verify($currentPassword, $userRow['password_hash'])) {
                $errorMessage = "The current password you entered is incorrect.";
            } else {
                try {
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                    $upStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upStmt->execute([$newHash, $userId]);

                    logActivity($userId, "User updated their account password successfully.");
                    $successMessage = "Your password has been successfully updated!";
                } catch (Exception $e) {
                    $errorMessage = "Failed to update password: " . $e->getMessage();
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$backUrl = ($userRole === 'super_admin' || $userRole === 'team_member') 
    ? APP_URL . '/admin/index.php' 
    : APP_URL . '/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — Digital Rubix MetaPanel</title>
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
<body class="bg-body-tertiary d-flex flex-column min-vh-100">

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg bg-body border-bottom shadow-sm px-3 py-2">
        <div class="container-fluid max-w-7xl">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($backUrl) ?>">
                <img src="<?= APP_URL ?>/assets/logos/digital_rubix_logo.svg" alt="Digital Rubix" style="height: 38px;">
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= e($backUrl) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                </a>
                <a href="<?= APP_URL ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="container my-auto py-5" style="max-width: 520px;">
        <div class="card shadow-sm border-0 glass-card">
            <div class="card-header bg-transparent border-0 pt-4 pb-2 text-center">
                <div class="avatar-circle mx-auto mb-3 bg-primary-subtle text-primary d-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-key fa-xl"></i>
                </div>
                <h4 class="fw-bold font-heading mb-1">Change Account Password</h4>
                <p class="text-muted small mb-0">Logged in as: <strong><?= e($userName) ?></strong> (<?= e(ucwords(str_replace('_', ' ', $userRole))) ?>)</p>
            </div>
            <div class="card-body p-4 pt-2">
                <?php if ($successMessage): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fa-solid fa-circle-check me-2"></i> <?= e($successMessage) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fa-solid fa-circle-exclamation me-2"></i> <?= e($errorMessage) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="change_password.php" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <div class="mb-3">
                        <label for="current_password" class="form-label text-muted small fw-semibold">Current Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-lock text-muted"></i></span>
                            <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password" placeholder="Enter existing password">
                            <button class="btn btn-outline-secondary toggle-pass-btn" type="button" data-target="current_password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label text-muted small fw-semibold">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-key text-muted"></i></span>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6" autocomplete="new-password" placeholder="Minimum 6 characters">
                            <button class="btn btn-outline-secondary toggle-pass-btn" type="button" data-target="new_password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label text-muted small fw-semibold">Confirm New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-circle-check text-muted"></i></span>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password" placeholder="Re-type new password">
                            <button class="btn btn-outline-secondary toggle-pass-btn" type="button" data-target="confirm_password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary py-2 fw-semibold shadow-sm font-heading">
                            <i class="fa-solid fa-shield-halved me-2"></i> Update Password
                        </button>
                        <a href="<?= e($backUrl) ?>" class="btn btn-light py-2 text-muted">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.toggle-pass-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const icon = this.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
    </script>
</body>
</html>
