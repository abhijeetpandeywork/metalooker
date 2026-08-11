<?php
/**
 * User Login Portal
 *
 * Provides authentication interface for agency super admin, team members, and clients.
 * Includes CSRF validation and rate-limiting feedback.
 *
 * @package MetaPanel\Pages
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = $_SESSION['user_role'];
    if ($role === 'super_admin' || $role === 'team_member') {
        header("Location: " . APP_URL . "/admin/index.php");
    } else {
        header("Location: " . APP_URL . "/dashboard.php");
    }
    exit;
}

$errorMessage = null;

// Handle POST authentication request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "Invalid security token. Please refresh the page and try again.";
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $errorMessage = "Please fill in all required fields.";
        } else {
            $result = login($email, $password);
            if ($result['success']) {
                $role = $_SESSION['user_role'];
                if ($role === 'super_admin' || $role === 'team_member') {
                    header("Location: " . APP_URL . "/admin/index.php");
                } else {
                    header("Location: " . APP_URL . "/dashboard.php");
                }
                exit;
            } else {
                $errorMessage = $result['message'];
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Rubix — MetaPanel Login</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-card shadow-lg">
            <div class="auth-header text-center">
                <div class="auth-logo-badge mb-3">
                    <i class="fa-solid fa-chart-line text-primary fs-2"></i>
                </div>
                <h3 class="fw-bold text-white mb-1">Digital Rubix</h3>
                <p class="text-muted small">Meta Ads Performance Engine</p>
            </div>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2"></i> <?= e($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                
                <div class="mb-3">
                    <label for="email" class="form-label text-light-muted">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" placeholder="admin@digitalrubix.com" required autocomplete="email">
                    </div>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="password" class="form-label text-light-muted">Password</label>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                    <i class="fa-solid fa-right-to-bracket me-2"></i> Sign In to Dashboard
                </button>
            </form>

            <div class="auth-footer text-center mt-4">
                <small class="text-muted">&copy; <?= date('Y') ?> Digital Rubix. All rights reserved.</small>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
