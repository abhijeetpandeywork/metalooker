<?php
/**
 * Admin Client Edit and Dashboard Configuration Portal
 *
 * Allows Super Admins and authorized Team Members to edit client profile settings,
 * upload brand logos, set custom brand colors, configure dashboard widget visibility,
 * and initiate Meta OAuth connections.
 *
 * @package MetaPanel\Admin
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['super_admin', 'team_member']);

$db = Database::getInstance();
$clientId = (int)($_GET['id'] ?? 0);

if ($clientId <= 0) {
    header("Location: " . APP_URL . "/admin/clients.php?error=invalid_id");
    exit;
}

// Fetch Client & Dashboard Config
$stmt = $db->prepare("
    SELECT c.*, u.email as client_email, u.name as client_name, dc.*
    FROM clients c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN dashboard_config dc ON dc.client_id = c.id
    WHERE c.id = ? LIMIT 1
");
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if (!$client) {
    header("Location: " . APP_URL . "/admin/clients.php?error=not_found");
    exit;
}

$successMessage = $_GET['success'] ?? null;
$errorMessage = $_GET['error'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $errorMessage = "Security validation failed. Please try again.";
    } else {
        $businessName = trim($_POST['business_name'] ?? '');
        $brandColor   = trim($_POST['brand_color'] ?? '#0F2D55');
        $currency     = strtoupper(trim($_POST['currency'] ?? 'INR'));
        $reportTitle  = trim($_POST['report_title'] ?? 'My Ads Performance');
        $defaultRange = $_POST['default_range'] ?? 'last_30';
        $metaAdAccountId = trim($_POST['meta_ad_account_id'] ?? '');

        // Widget Toggles
        $showSpend       = isset($_POST['show_spend']) ? 1 : 0;
        $showRoas        = isset($_POST['show_roas']) ? 1 : 0;
        $showLeads       = isset($_POST['show_leads']) ? 1 : 0;
        $showCpc         = isset($_POST['show_cpc']) ? 1 : 0;
        $showCtr         = isset($_POST['show_ctr']) ? 1 : 0;
        $showImpressions = isset($_POST['show_impressions']) ? 1 : 0;
        $showCampaigns   = isset($_POST['show_campaigns']) ? 1 : 0;
        $showAdsets      = isset($_POST['show_adsets']) ? 1 : 0;

        // Handle Logo Upload
        $logoPath = $client['logo_path'];
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['logo']['tmp_name'];
            $fileName = $_FILES['logo']['name'];
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowed = ['jpg', 'jpeg', 'png', 'svg', 'webp'];
            if (in_array($fileExt, $allowed, true)) {
                $uploadDir = __DIR__ . '/../assets/logos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $newFileName = 'logo_client_' . $clientId . '_' . time() . '.' . $fileExt;
                $targetFile  = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmp, $targetFile)) {
                    $logoPath = APP_URL . '/assets/logos/' . $newFileName;
                }
            } else {
                $errorMessage = "Invalid image file type. Allowed: JPG, PNG, SVG, WEBP.";
            }
        }

        if (!$errorMessage) {
            try {
                $db->beginTransaction();

                $updateClient = $db->prepare("
                    UPDATE clients
                    SET business_name = ?,
                        logo_path = ?,
                        brand_color = ?,
                        currency = ?,
                        meta_ad_account_id = ?
                    WHERE id = ?
                ");
                $updateClient->execute([$businessName, $logoPath, $brandColor, $currency, $metaAdAccountId, $clientId]);

                $upsertConfig = $db->prepare("
                    INSERT INTO dashboard_config (
                        client_id, default_range, show_spend, show_roas, show_leads,
                        show_cpc, show_ctr, show_impressions, show_campaigns, show_adsets, report_title
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        default_range = VALUES(default_range),
                        show_spend = VALUES(show_spend),
                        show_roas = VALUES(show_roas),
                        show_leads = VALUES(show_leads),
                        show_cpc = VALUES(show_cpc),
                        show_ctr = VALUES(show_ctr),
                        show_impressions = VALUES(show_impressions),
                        show_campaigns = VALUES(show_campaigns),
                        show_adsets = VALUES(show_adsets),
                        report_title = VALUES(report_title)
                ");
                $upsertConfig->execute([
                    $clientId, $defaultRange, $showSpend, $showRoas, $showLeads,
                    $showCpc, $showCtr, $showImpressions, $showCampaigns, $showAdsets, $reportTitle
                ]);

                $db->commit();
                logActivity($_SESSION['user_id'], "Updated client settings for client ID {$clientId}");

                $successMessage = "Client profile and dashboard configuration saved successfully.";
                $stmt->execute([$clientId]);
                $client = $stmt->fetch();
            } catch (Exception $e) {
                $db->rollBack();
                $errorMessage = "Database update error: " . $e->getMessage();
            }
        }
    }
}

// Token Health Indicator Status
$tokenHealth = 'red';
$healthLabel = 'Not Connected / No Token';

if (!empty($client['meta_access_token']) && !empty($client['token_expires_at'])) {
    $expires = new DateTime($client['token_expires_at']);
    $now = new DateTime();
    $daysLeft = (int)$now->diff($expires)->format('%r%a');

    if ($daysLeft > 30) {
        $tokenHealth = 'green';
        $healthLabel = "Healthy ({$daysLeft} days remaining)";
    } elseif ($daysLeft > 0) {
        $tokenHealth = 'yellow';
        $healthLabel = "Expiring Soon ({$daysLeft} days remaining)";
    } else {
        $tokenHealth = 'red';
        $healthLabel = "Expired on " . $expires->format('d M Y');
    }
}

// Build Meta OAuth Redirect URL
$oauthState = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $oauthState;
$_SESSION['oauth_client_id'] = $clientId;

$oauthUrl = "https://www.facebook.com/" . META_GRAPH_VERSION . "/dialog/oauth?" . http_build_query([
    'client_id'     => META_APP_ID,
    'redirect_uri'  => APP_URL . '/oauth_callback.php',
    'scope'         => 'ads_read,ads_management,business_management,read_insights',
    'state'         => $oauthState,
    'response_type' => 'code'
]);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Client Settings — MetaPanel Admin</title>
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
                <li class="nav-item mb-1">
                    <a href="<?= APP_URL ?>/admin/team.php" class="nav-link">
                        <i class="fa-solid fa-users me-2"></i> Team Access
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
                    <h3 class="fw-bold m-0 font-heading">Client Configuration: <?= e($client['business_name']) ?></h3>
                    <p class="text-muted m-0">Custom branding, Meta Graph API onboarding, and widget visibility</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-dark btn-theme-toggle me-2 shadow-sm">
                        <i class="fa-solid fa-moon me-1"></i> Dark Mode
                    </button>
                    <a href="<?= APP_URL ?>/admin/clients.php" class="btn btn-outline-secondary shadow-sm">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Clients
                    </a>
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

            <!-- Meta API Status Card -->
            <div class="card glass-card mb-4 shadow-sm">
                <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-heading"><i class="fa-brands fa-meta me-2 text-primary"></i> Meta Marketing API Connection</h5>
                    <span class="badge bg-<?= $tokenHealth === 'green' ? 'success' : ($tokenHealth === 'yellow' ? 'warning' : 'danger') ?> fs-6">
                        <?= e($healthLabel) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <p class="mb-1"><strong>Ad Account ID:</strong> <code><?= e($client['meta_ad_account_id'] ?: 'Not Connected') ?></code></p>
                            <p class="text-muted small mb-0">Token Expiry: <?= e($client['token_expires_at'] ? date('F j, Y g:i A', strtotime($client['token_expires_at'])) : 'N/A') ?></p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <?php if (MOCK_META_API): ?>
                                <a href="<?= APP_URL ?>/oauth_callback.php?state=<?= $oauthState ?>&code=mock_code" class="btn btn-primary shadow-sm font-heading">
                                    <i class="fa-brands fa-facebook me-1"></i> Connect Meta Account (Mock)
                                </a>
                            <?php else: ?>
                                <a href="<?= $oauthUrl ?>" class="btn btn-primary shadow-sm font-heading">
                                    <i class="fa-brands fa-facebook me-1"></i> Connect Meta Account
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configuration Form -->
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <div class="row">
                    <!-- Branding Settings -->
                    <div class="col-lg-6 mb-4">
                        <div class="card glass-card h-100 shadow-sm">
                            <div class="card-header bg-transparent border-bottom">
                                <h5 class="m-0 font-heading"><i class="fa-solid fa-palette me-2 text-info"></i> Branding & Portal Customization</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">
                                        Business Name
                                        <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Business Name" data-bs-content="Displayed at top of client dashboard report header.">
                                            i
                                        </button>
                                    </label>
                                    <input type="text" name="business_name" class="form-control shadow-sm" value="<?= e($client['business_name']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">Client Login Email</label>
                                    <input type="email" class="form-control bg-body-tertiary shadow-sm" value="<?= e($client['client_email']) ?>" readonly disabled>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label text-muted small fw-semibold">
                                            Brand Color Accent
                                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Brand Color" data-bs-content="Injects client brand accent into dashboard header borders, buttons, and line charts.">
                                                i
                                            </button>
                                        </label>
                                        <input type="color" name="brand_color" class="form-control form-control-color w-100 shadow-sm" value="<?= e($client['brand_color'] ?? '#0F2D55') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-muted small fw-semibold">
                                            Currency Code
                                            <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Currency" data-bs-content="Formats spend, CPC, and CPM metrics in the requested currency symbol.">
                                                i
                                            </button>
                                        </label>
                                        <select name="currency" class="form-select shadow-sm">
                                            <option value="INR" <?= ($client['currency'] ?? 'INR') === 'INR' ? 'selected' : '' ?>>INR (₹)</option>
                                            <option value="USD" <?= ($client['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                                            <option value="AED" <?= ($client['currency'] ?? '') === 'AED' ? 'selected' : '' ?>>AED (AED)</option>
                                            <option value="EUR" <?= ($client['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                                            <option value="GBP" <?= ($client['currency'] ?? '') === 'GBP' ? 'selected' : '' ?>>GBP (£)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">
                                        Client Ad Account ID
                                        <button type="button" class="info-popover-btn" data-bs-toggle="popover" title="Ad Account ID" data-bs-content="Standard Meta Ad Account ID format: act_1234567890. Automatically populated during OAuth or overridden manually.">
                                            i
                                        </button>
                                    </label>
                                    <input type="text" name="meta_ad_account_id" class="form-control shadow-sm" placeholder="act_123456789" value="<?= e($client['meta_ad_account_id']) ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">Upload Custom Logo</label>
                                    <input type="file" name="logo" class="form-control shadow-sm" accept="image/*">
                                    <?php if (!empty($client['logo_path'])): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">Current Logo:</small><br>
                                            <img src="<?= e($client['logo_path']) ?>" alt="Logo" style="max-height: 45px;" class="img-thumbnail mt-1">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dashboard Widget Controls -->
                    <div class="col-lg-6 mb-4">
                        <div class="card glass-card h-100 shadow-sm">
                            <div class="card-header bg-transparent border-bottom">
                                <h5 class="m-0 font-heading"><i class="fa-solid fa-sliders me-2 text-warning"></i> Dashboard Customization</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">Custom Report Title</label>
                                    <input type="text" name="report_title" class="form-control shadow-sm" value="<?= e($client['report_title'] ?? 'My Ads Performance') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">Default Date Range Preset</label>
                                    <select name="default_range" class="form-select shadow-sm">
                                        <option value="last_7" <?= ($client['default_range'] ?? '') === 'last_7' ? 'selected' : '' ?>>Last 7 Days</option>
                                        <option value="last_30" <?= ($client['default_range'] ?? 'last_30') === 'last_30' ? 'selected' : '' ?>>Last 30 Days</option>
                                        <option value="this_month" <?= ($client['default_range'] ?? '') === 'this_month' ? 'selected' : '' ?>>This Month</option>
                                        <option value="last_month" <?= ($client['default_range'] ?? '') === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                                    </select>
                                </div>

                                <h6 class="mt-4 mb-3 border-bottom pb-2 font-heading">Visible Metrics & Widgets</h6>
                                <div class="row">
                                    <div class="col-6 mb-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="show_spend" id="show_spend" <?= ($client['show_spend'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label text-muted small fw-semibold" for="show_spend">Total Spend</label>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="show_roas" id="show_roas" <?= ($client['show_roas'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label text-muted small fw-semibold" for="show_roas">Purchase ROAS</label>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="show_leads" id="show_leads" <?= ($client['show_leads'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label text-muted small fw-semibold" for="show_leads">Conversions / Results</label>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="show_cpc" id="show_cpc" <?= ($client['show_cpc'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label text-muted small fw-semibold" for="show_cpc">Avg. CPC</label>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="show_ctr" id="show_ctr" <?= ($client['show_ctr'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label text-muted small fw-semibold" for="show_ctr">Avg. CTR</label>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="show_impressions" id="show_impressions" <?= ($client['show_impressions'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label text-muted small fw-semibold" for="show_impressions">Impressions & Reach</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-success btn-lg px-4 shadow-sm font-heading">
                        <i class="fa-solid fa-floppy-disk me-2"></i> Save Client Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.APP_URL = "<?= APP_URL ?>";
    </script>
    <script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
</body>
</html>
