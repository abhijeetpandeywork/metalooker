<?php
/**
 * Admin SaaS Global Meta App Settings Portal
 *
 * Allows Super Admins to configure Meta App credentials (App ID, App Secret), toggle
 * Mock API mode, test Graph API connections in real time, and copy OAuth redirect URIs.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "CSRF security check failed.";
    } else {
        $appId     = trim($_POST['meta_app_id'] ?? '');
        $appSecret = trim($_POST['meta_app_secret'] ?? '');
        $mockMode  = isset($_POST['mock_meta_api']) ? 'true' : 'false';

        // 1. Save to Database System Settings Table
        setSystemSetting('meta_app_id', $appId);
        setSystemSetting('meta_app_secret', $appSecret);
        setSystemSetting('mock_meta_api', $mockMode);

        // 2. Save directly to .env file on disk for persistent fallback
        updateEnvFile('META_APP_ID', $appId);
        updateEnvFile('META_APP_SECRET', $appSecret);
        updateEnvFile('MOCK_META_API', $mockMode);

        logActivity($_SESSION['user_id'], "Updated global Meta App API settings (App ID: {$appId})");

        header("Location: " . APP_URL . "/admin/settings.php?success=" . urlencode("Global Meta App settings saved successfully. System updated and persistent."));
        exit;
    }
}

$currentAppId     = META_APP_ID ?: getSystemSetting('meta_app_id', '');
$currentAppSecret = META_APP_SECRET ?: getSystemSetting('meta_app_secret', '');
$currentMockMode  = MOCK_META_API;
$redirectUri      = APP_URL . '/oauth_callback.php';

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta App Settings — MetaPanel Admin</title>
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
                    <a href="<?= APP_URL ?>/admin/team.php" class="nav-link">
                        <i class="fa-solid fa-users me-2"></i> Team Access
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/settings.php" class="nav-link active">
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

        <!-- Main Content -->
        <div class="admin-content flex-grow-1 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold m-0 font-heading">Global SaaS Meta App Settings</h3>
                    <p class="text-muted m-0">Configure your agency's Meta Developer App credentials (Set once for all 50+ clients)</p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-dark btn-theme-toggle shadow-sm">
                    <i class="fa-solid fa-moon me-1"></i> Dark Mode
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

            <!-- Diagnostic Connection Banner Result -->
            <div id="test-result-box" class="d-none mb-4"></div>

            <div class="row">
                <!-- Credentials Configuration Form -->
                <div class="col-lg-7 mb-4">
                    <div class="card glass-card h-100 shadow-sm">
                        <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
                            <h5 class="m-0 font-heading"><i class="fa-brands fa-meta me-2 text-primary"></i> Agency Meta Developer Credentials</h5>
                            <span class="badge bg-<?= $currentMockMode ? 'warning' : (!empty($currentAppId) ? 'success' : 'danger') ?> fs-6">
                                <?= $currentMockMode ? 'Mock Mode Active' : (!empty($currentAppId) ? '✅ Live App Configured (' . e($currentAppId) . ')' : 'Missing App ID') ?>
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" id="meta-settings-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">
                                        Meta App ID *
                                        <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Meta App ID" data-bs-content="Found in Meta Developers portal -> App Settings -> Basic. Used for OAuth authorization dialogs.">
                                            i
                                        </button>
                                    </label>
                                    <input type="text" id="meta_app_id" name="meta_app_id" class="form-control shadow-sm font-monospace" placeholder="e.g. 2118891216178554" value="<?= e($currentAppId) ?>" autocomplete="off" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">
                                        Meta App Secret *
                                        <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Meta App Secret" data-bs-content="Found in Meta Developers portal -> App Settings -> Basic -> Show Secret. Used to exchange authorization codes for long-lived 60-day tokens.">
                                            i
                                        </button>
                                    </label>
                                    <div class="input-group shadow-sm">
                                        <input type="password" id="meta_app_secret" name="meta_app_secret" class="form-control font-monospace" placeholder="••••••••••••••••••••••••••••••••" value="<?= e($currentAppSecret) ?>" autocomplete="new-password" required>
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleSecretVisibility()">
                                            <i class="fa-solid fa-eye" id="secret-eye-icon"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label text-muted small fw-semibold">
                                        OAuth Valid Redirect URI (Copy & Paste to Meta Developer App)
                                        <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="OAuth Redirect URI" data-bs-content="Paste this exact URL in your Meta App -> Facebook Login Settings -> Valid OAuth Redirect URIs.">
                                            i
                                        </button>
                                    </label>
                                    <div class="input-group shadow-sm">
                                        <input type="text" id="redirect-uri-input" class="form-control bg-body-tertiary font-monospace" value="<?= e($redirectUri) ?>" readonly>
                                        <button type="button" class="btn btn-outline-primary" onclick="copyRedirectUri()">
                                            <i class="fa-regular fa-copy me-1"></i> Copy URI
                                        </button>
                                    </div>
                                    <small class="text-muted mt-1 d-block" id="copy-feedback-text"></small>
                                </div>

                                <div class="p-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 mb-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="mock_meta_api" id="mock_meta_api" <?= $currentMockMode ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-warning-emphasis" for="mock_meta_api">
                                            Enable Mock Meta API Mode (For Instant Sandbox Testing)
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        When active, MetaPanel simulates 1-click OAuth authorizations and generates realistic daily campaign spend/ROAS data without requiring real Meta Graph API calls.
                                    </small>
                                </div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <button type="button" id="btn-test-connection" class="btn btn-outline-info shadow-sm font-heading">
                                        <i class="fa-solid fa-plug-circle-check me-1"></i> Test Connection
                                    </button>
                                    <button type="submit" class="btn btn-success shadow-sm font-heading px-4">
                                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Global Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Setup Guide Card -->
                <div class="col-lg-5 mb-4">
                    <div class="card glass-card h-100 shadow-sm">
                        <div class="card-header bg-transparent border-bottom">
                            <h5 class="m-0 font-heading"><i class="fa-solid fa-book-open me-2 text-info"></i> Agency Meta App Setup Guide</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="onboarding-step-card">
                                <h6 class="fw-bold text-primary mb-1">Step 1: Create Meta Developer App</h6>
                                <p class="small text-muted mb-0">
                                    Log into <a href="https://developers.facebook.com" target="_blank" class="fw-bold">developers.facebook.com</a> ➔ Click <strong>My Apps</strong> ➔ <strong>Create App</strong> ➔ Choose <strong>Other / Business</strong>.
                                </p>
                            </div>

                            <div class="onboarding-step-card">
                                <h6 class="fw-bold text-primary mb-1">Step 2: Add Facebook Login Product</h6>
                                <p class="small text-muted mb-0">
                                    Under Products, add <strong>Facebook Login for Business</strong>. Go to Settings and paste your 1-Click Copyable Redirect URI above into <strong>Valid OAuth Redirect URIs</strong>.
                                </p>
                            </div>

                            <div class="onboarding-step-card">
                                <h6 class="fw-bold text-primary mb-1">Step 3: Copy App Credentials & Save</h6>
                                <p class="small text-muted mb-0">
                                    Go to <strong>App Settings ➔ Basic</strong>, copy your <strong>App ID</strong> and <strong>App Secret</strong> into the form on the left, then click <strong>Save Global Settings</strong>.
                                </p>
                            </div>

                            <div class="onboarding-step-card">
                                <h6 class="fw-bold text-primary mb-1">Step 4: Test Connection</h6>
                                <p class="small text-muted mb-0">
                                    Click <strong>Test Connection</strong>. The system will perform a live diagnostic call to Meta Graph API to verify credentials before client logins.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.APP_URL = "<?= APP_URL ?>";

        function toggleSecretVisibility() {
            const input = document.getElementById('meta_app_secret');
            const icon = document.getElementById('secret-eye-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-solid fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa-solid fa-eye';
            }
        }

        function copyRedirectUri() {
            const input = document.getElementById('redirect-uri-input');
            input.select();
            navigator.clipboard.writeText(input.value).then(() => {
                const feedback = document.getElementById('copy-feedback-text');
                feedback.innerText = '✅ Redirect URI copied to clipboard!';
                feedback.className = 'text-success small mt-1 d-block';
                setTimeout(() => { feedback.innerText = ''; }, 3000);
            });
        }

        // Test Meta Connection AJAX Handler
        document.getElementById('btn-test-connection').addEventListener('click', function() {
            const appId = document.getElementById('meta_app_id').value.trim();
            const appSecret = document.getElementById('meta_app_secret').value.trim();
            const box = document.getElementById('test-result-box');
            const btn = this;

            if (!appId || !appSecret) {
                alert('Please enter both Meta App ID and Meta App Secret before testing.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Testing Meta API...';

            fetch('<?= APP_URL ?>/api/test_meta_app.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ app_id: appId, app_secret: appSecret })
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-plug-circle-check me-1"></i> Test Connection';
                box.classList.remove('d-none');

                if (data.success) {
                    box.className = 'alert alert-success alert-dismissible fade show shadow-sm mb-4';
                    box.innerHTML = `
                        <h6 class="fw-bold mb-1"><i class="fa-solid fa-circle-check me-2"></i> Meta Graph API Connection Successful!</h6>
                        <p class="mb-0 small">App Name: <strong>${data.app_name}</strong> | App ID: <code>${data.app_id}</code> | HTTP ${data.http_code} OK</p>
                    `;
                } else {
                    box.className = 'alert alert-danger alert-dismissible fade show shadow-sm mb-4';
                    box.innerHTML = `
                        <h6 class="fw-bold mb-1"><i class="fa-solid fa-circle-xmark me-2"></i> Meta API Connection Failed</h6>
                        <p class="mb-0 small">${data.error}</p>
                    `;
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-plug-circle-check me-1"></i> Test Connection';
                box.classList.remove('d-none');
                box.className = 'alert alert-danger alert-dismissible fade show shadow-sm mb-4';
                box.innerHTML = `<h6 class="fw-bold mb-1">Request Error</h6><p class="mb-0 small">${err}</p>`;
            });
        });
    </script>
    <script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
</body>
</html>
