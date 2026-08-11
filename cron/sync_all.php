<?php
/**
 * Cron Data Synchronization Engine
 *
 * Traverses active MetaPanel client accounts, pulls marketing API insights from Meta Graph API,
 * upserts tabular metrics into MySQL ad_data_cache table, and records detailed execution logs.
 * Designed for execution via Hostinger hPanel Cron Job every 6 hours.
 *
 * @package MetaPanel\Cron
 */

// Hostinger Cron environment configuration
set_time_limit(300);
ini_set('memory_limit', '256M');

// Absolute inclusion paths for CLI/Cron environment
$baseDir = dirname(__DIR__);
if (file_exists($baseDir . '/includes/config.php')) {
    require_once $baseDir . '/includes/config.php';
    require_once $baseDir . '/includes/db.php';
    require_once $baseDir . '/includes/token_manager.php';
    require_once $baseDir . '/includes/meta_api.php';
    require_once $baseDir . '/includes/helpers.php';
} else {
    require_once $baseDir . '/public_html/includes/config.php';
    require_once $baseDir . '/public_html/includes/db.php';
    require_once $baseDir . '/public_html/includes/token_manager.php';
    require_once $baseDir . '/public_html/includes/meta_api.php';
    require_once $baseDir . '/public_html/includes/helpers.php';
}

/**
 * Synchronizes ad account performance data for a single client.
 *
 * @param array $client Client record array from DB
 * @return array Sync execution result array
 */
function syncClientData(array $client): array {
    $db = Database::getInstance();
    $clientId = (int)$client['id'];

    $plainToken = TokenManager::decrypt($client['meta_access_token'] ?? '');
    $adAccountId = trim($client['meta_ad_account_id'] ?? '');

    if (empty($adAccountId) && !MOCK_META_API) {
        return [
            'status' => 'error',
            'rows'   => 0,
            'error'  => 'Missing Meta Ad Account ID for client ' . ($client['business_name'] ?? '') . '. Please configure Ad Account ID in Client Config.'
        ];
    }

    if (empty($plainToken) && !MOCK_META_API) {
        return [
            'status' => 'error',
            'rows'   => 0,
            'error'  => 'Missing or invalid Meta access token.'
        ];
    }

    try {
        // Determine sync date range
        $checkStmt = $db->prepare("SELECT COUNT(*) as cnt FROM ad_data_cache WHERE client_id = ?");
        $checkStmt->execute([$clientId]);
        $hasData = ($checkStmt->fetch()['cnt'] ?? 0) > 0;
        $checkStmt->closeCursor();

        $dateStop = (new DateTime())->modify('-1 day')->format('Y-m-d');
        if ($hasData) {
            // Subsequent sync: overlap last 30 days for full attribution accuracy
            $dateStart = (new DateTime())->modify('-30 days')->format('Y-m-d');
        } else {
            // Initial sync: fetch last 90 days
            $dateStart = (new DateTime())->modify('-90 days')->format('Y-m-d');
        }

        $metaApi = new MetaAPI($plainToken, $adAccountId);
        $levels = ['account', 'campaign', 'adset', 'ad'];
        $allInsights = [];

        // Fetch all insights from Meta Graph API BEFORE touching the database
        foreach ($levels as $lvl) {
            $allInsights[$lvl] = $metaApi->getInsights($lvl, $dateStart, $dateStop);
        }

        $totalInserted = 0;

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $upsertStmt = $db->prepare("
                    INSERT OR REPLACE INTO ad_data_cache (
                        client_id, level, object_id, object_name, date_start, date_stop,
                        impressions, reach, clicks, spend, cpc, ctr, cpm, conversions,
                        cost_per_result, roas, frequency
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?
                    )
                ");
            } else {
                $upsertStmt = $db->prepare("
                    INSERT INTO ad_data_cache (
                        client_id, level, object_id, object_name, date_start, date_stop,
                        impressions, reach, clicks, spend, cpc, ctr, cpm, conversions,
                        cost_per_result, roas, frequency
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?
                    )
                    ON DUPLICATE KEY UPDATE
                        object_name = VALUES(object_name),
                        impressions = VALUES(impressions),
                        reach = VALUES(reach),
                        clicks = VALUES(clicks),
                        spend = VALUES(spend),
                        cpc = VALUES(cpc),
                        ctr = VALUES(ctr),
                        cpm = VALUES(cpm),
                        conversions = VALUES(conversions),
                        cost_per_result = VALUES(cost_per_result),
                        roas = VALUES(roas),
                        frequency = VALUES(frequency),
                        synced_at = NOW()
                ");
            }

            foreach ($levels as $lvl) {
                $insights = $allInsights[$lvl] ?? [];

                foreach ($insights as $row) {
                    $objectId = match ($lvl) {
                        'account'  => $adAccountId ?: 'account_total',
                        'campaign' => $row['campaign_id'] ?? ('cmp_' . md5($row['campaign_name'] ?? '')),
                        'adset'    => $row['adset_id'] ?? ('ads_' . md5($row['adset_name'] ?? '')),
                        'ad'       => $row['ad_id'] ?? ('ad_' . md5($row['ad_name'] ?? '')),
                    };

                    $objectName = match ($lvl) {
                        'account'  => $client['business_name'] . ' (Account Total)',
                        'campaign' => $row['campaign_name'] ?? 'Unnamed Campaign',
                        'adset'    => $row['adset_name'] ?? 'Unnamed Ad Set',
                        'ad'       => $row['ad_name'] ?? 'Unnamed Ad',
                    };

                    $impressions    = (int)($row['impressions'] ?? 0);
                    $reach          = (int)($row['reach'] ?? 0);
                    $clicks         = (int)($row['clicks'] ?? 0);
                    $spend          = (float)($row['spend'] ?? 0.0);
                    $cpc            = (float)($row['cpc'] ?? 0.0);
                    $ctr            = (float)($row['ctr'] ?? 0.0);
                    $cpm            = (float)($row['cpm'] ?? 0.0);
                    $frequency      = (float)($row['frequency'] ?? 1.0);
                    $rowStart       = $row['date_start'] ?? $dateStart;
                    $rowStop        = $row['date_stop'] ?? $dateStop;

                    // Robust Meta Actions & ROAS Extraction Engine
                    $conversions = 0;
                    if (isset($row['conversions']) && is_numeric($row['conversions'])) {
                        $conversions = (int)$row['conversions'];
                    } elseif (isset($row['actions']) && is_array($row['actions'])) {
                        $priorityActions = [
                            'lead',
                            'purchase',
                            'omni_purchase',
                            'onsite_conversion.messaging_conversation_started_7d',
                            'contact',
                            'complete_registration',
                            'schedule',
                            'submit_application',
                            'subscribe',
                            'landing_page_view'
                        ];
                        foreach ($row['actions'] as $act) {
                            $type = $act['action_type'] ?? '';
                            $val  = (int)($act['value'] ?? 0);
                            if (in_array($type, $priorityActions, true)) {
                                $conversions += $val;
                            }
                        }
                        if ($conversions === 0) {
                            foreach ($row['actions'] as $act) {
                                $type = $act['action_type'] ?? '';
                                if (str_contains($type, 'conversion') || str_contains($type, 'lead') || str_contains($type, 'purchase') || str_contains($type, 'messaging')) {
                                    $conversions += (int)($act['value'] ?? 0);
                                }
                            }
                        }
                    }

                    $roas = 0.0;
                    if (isset($row['roas']) && is_numeric($row['roas'])) {
                        $roas = (float)$row['roas'];
                    } elseif (isset($row['purchase_roas']) && is_array($row['purchase_roas'])) {
                        foreach ($row['purchase_roas'] as $rItem) {
                            if (!empty($rItem['value'])) {
                                $roas = (float)$rItem['value'];
                                break;
                            }
                        }
                    } elseif ($spend > 0 && isset($row['action_values']) && is_array($row['action_values'])) {
                        $purchaseVal = 0.0;
                        foreach ($row['action_values'] as $av) {
                            $type = $av['action_type'] ?? '';
                            if (in_array($type, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'], true)) {
                                $purchaseVal += (float)($av['value'] ?? 0.0);
                            }
                        }
                        if ($purchaseVal > 0) {
                            $roas = round($purchaseVal / $spend, 2);
                        }
                    }

                    $costPerResult = $conversions > 0 ? round($spend / $conversions, 2) : 0.0;

                    for ($attempt = 1; $attempt <= 5; $attempt++) {
                        try {
                            $upsertStmt->execute([
                                $clientId, $lvl, $objectId, $objectName, $rowStart, $rowStop,
                                $impressions, $reach, $clicks, $spend, $cpc, $ctr, $cpm, $conversions,
                                $costPerResult, $roas, $frequency
                            ]);
                            $upsertStmt->closeCursor();
                            break;
                        } catch (Exception $ex) {
                            if (str_contains($ex->getMessage(), 'locked') && $attempt < 5) {
                                usleep(100000);
                                continue;
                            }
                            throw $ex;
                        }
                    }

                    $totalInserted++;
                }
            }

        // Record success log
        $logStmt = $db->prepare("INSERT INTO sync_logs (client_id, status, rows_inserted) VALUES (?, 'success', ?)");
        $logStmt->execute([$clientId, $totalInserted]);
        $logStmt->closeCursor();

        return [
            'status' => 'success',
            'rows'   => $totalInserted,
            'error'  => null
        ];

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        $logStmt = $db->prepare("INSERT INTO sync_logs (client_id, status, rows_inserted, error_message) VALUES (?, 'error', 0, ?)");
        $logStmt->execute([$clientId, substr($errorMsg, 0, 500)]);
        $logStmt->closeCursor();

        return [
            'status' => 'error',
            'rows'   => 0,
            'error'  => $errorMsg
        ];
    }
}

// Global Execution Routine (Only run when executed directly as a CLI or standalone script)
if (php_sapi_name() === 'cli' || (isset($_SERVER['SCRIPT_FILENAME']) && basename($_SERVER['SCRIPT_FILENAME']) === 'sync_all.php')) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting MetaPanel Cron Sync Loop...\n";

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM clients WHERE active = 1 AND (meta_access_token IS NOT NULL OR 1=1)");
        $stmt->execute();
        $clients = $stmt->fetchAll();
        $stmt->closeCursor();

        echo "Found " . count($clients) . " active client account(s) to process.\n";

        foreach ($clients as $client) {
            echo "Processing Client: {$client['business_name']} (ID: {$client['id']})... ";
            $result = syncClientData($client);

            if ($result['status'] === 'success') {
                echo "SUCCESS ({$result['rows']} rows updated)\n";
            } else {
                echo "ERROR ({$result['error']})\n";
            }

            usleep(200000);
        }

        echo "[" . date('Y-m-d H:i:s') . "] Cron Sync Cycle Finished Successfully.\n";

    } catch (Exception $e) {
        echo "Fatal Cron Failure: " . $e->getMessage() . "\n";
        exit(1);
    }
}
