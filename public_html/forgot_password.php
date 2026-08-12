<?php
/**
 * User Password Reset / Recovery Portal
 *
 * Allows users to recover and set a new password for their account.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    $role = $_SESSION['user_role'] ?? 'client';
    if ($role === 'super_admin' || $role === 'team_member') {
        header("Location: " . APP_URL . "/admin/index.php");
    } else {
        header("Location: " . APP_URL . "/dashboard.php");
    }
    exit;
}

$db = Database::getInstance();
$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "Security validation failed. Please refresh the page.";
    } else {
        $email           = trim($_POST['email'] ?? '');
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($email) || empty($newPassword) || empty($confirmPassword)) {
            $errorMessage = "Please fill in all required fields.";
        } elseif (strlen($newPassword) < 6) {
            $errorMessage = "Password must be at least 6 characters in length.";
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "New password and confirmation do not match.";
        } else {
            // Check if user exists
            $stmt = $db->prepare("SELECT id, name, role FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                // Return generic notice or message
                $errorMessage = "No account found matching this email address.";
            } else {
                try {
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                    $upStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upStmt->execute([$newHash, $user['id']]);

                    logActivity($user['id'], "Password reset completed for user {$user['name']} ({$email}).");
                    $successMessage = "Your password has been reset successfully! You can now sign in.";
                } catch (Exception $e) {
                    $errorMessage = "Failed to reset password: " . $e->getMessage();
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — <?= e(APP_NAME) ?></title>
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
<body class="auth-body d-flex align-items-center justify-content-center min-vh-100 p-3">
    <div class="auth-container w-100" style="max-width: 440px;">
        <div class="auth-card shadow-lg glass-card p-4 p-sm-5">
            <div class="text-center mb-4">
                <img src="<?= APP_URL ?>/assets/logos/digital_rubix_logo.svg" alt="Digital Rubix Logo" class="agency-logo-img mb-2" style="height: 50px;">
                <h4 class="fw-bold mb-1 font-heading">Reset Password</h4>
                <p class="text-muted small">Enter your email and create a new password</p>
            </div>

            <?php if ($successMessage): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i> <?= e($successMessage) ?>
                    <div class="mt-3">
                        <a href="<?= APP_URL ?>/login.php" class="btn btn-sm btn-success fw-semibold w-100">
                            <i class="fa-solid fa-right-to-bracket me-1"></i> Proceed to Login
                        </a>
                    </div>
                </div>
            <?php else: ?>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fa-solid fa-circle-exclamation me-2"></i> <?= e($errorMessage) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="forgot_password.php" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    
                    <div class="mb-3">
                        <label for="email" class="form-label text-muted small fw-semibold">Account Email</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" placeholder="name@example.com" required autocomplete="email">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label text-muted small fw-semibold">New Password</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Minimum 6 characters" required minlength="6" autocomplete="new-password">
                            <button class="btn btn-outline-secondary toggle-pass-btn" type="button" data-target="new_password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label text-muted small fw-semibold">Confirm New Password</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text"><i class="fa-solid fa-circle-check"></i></span>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required minlength="6" autocomplete="new-password">
                            <button class="btn btn-outline-secondary toggle-pass-btn" type="button" data-target="confirm_password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm font-heading">
                        <i class="fa-solid fa-key me-2"></i> Reset & Set New Password
                    </button>
                </form>

            <?php endif; ?>

            <div class="text-center mt-4 pt-2 border-top border-secondary border-opacity-25">
                <a href="<?= APP_URL ?>/login.php" class="text-decoration-none small text-muted">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Login
                </a>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
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
