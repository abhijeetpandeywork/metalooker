<?php
/**
 * Meta Graph API Interface Wrapper
 *
 * Interacts with Meta Graph API v21.0 to retrieve advertising accounts, campaign/adset/ad insights,
 * token exchange, and error code handling. Includes mock data mode for offline development.
 *
 * @package MetaPanel\Includes
 */

require_once __DIR__ . '/config.php';

class MetaAPI {
    private string $accessToken;
    private string $adAccountId;
    private string $graphVersion;

    /**
     * Constructor for MetaAPI wrapper.
     *
     * @param string $accessToken Decrypted Meta Access Token
     * @param string $adAccountId Meta Ad Account ID (e.g. act_123456789)
     */
    public function __construct(string $accessToken = '', string $adAccountId = '') {
        $this->accessToken = $accessToken;
        $this->adAccountId = str_starts_with($adAccountId, 'act_') ? $adAccountId : 'act_' . ltrim($adAccountId, 'act_');
        $this->graphVersion = META_GRAPH_VERSION;
    }

    /**
     * Executes HTTP GET request to Meta Graph API endpoint.
     *
     * @param string $endpoint API endpoint path
     * @param array $params Query string parameters
     * @return array Decoded JSON response
     * @throws Exception On cURL, HTTP error, or Meta API error
     */
    private function makeApiCall(string $endpoint, array $params = []): array {
        if (!isset($params['access_token'])) {
            $params['access_token'] = $this->accessToken;
        }

        $url = "https://graph.facebook.com/{$this->graphVersion}/" . ltrim($endpoint, '/') . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("cURL Error while contacting Meta API: {$curlError}");
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $errorCode = $data['error']['code'] ?? 0;
            $errorMessage = $data['error']['message'] ?? 'Unknown Meta API error';

            if ($errorCode === 190) {
                throw new Exception("TOKEN_EXPIRED: Meta Access Token has expired or been revoked (Error 190).");
            }

            throw new Exception("Meta API Error [{$errorCode}]: {$errorMessage}");
        }

        return $data ?? [];
    }

    /**
     * Retrieves ad performance insights at account, campaign, adset, or ad level.
     *
     * @param string $level 'account', 'campaign', 'adset', or 'ad'
     * @param string $dateStart Format YYYY-MM-DD
     * @param string $dateStop Format YYYY-MM-DD
     * @return array Array of insight objects
     * @throws Exception
     */
    public function getInsights(string $level, string $dateStart, string $dateStop): array {
        if (MOCK_META_API) {
            return $this->generateMockInsights($level, $dateStart, $dateStop);
        }

        if (empty($this->accessToken) || empty($this->adAccountId)) {
            throw new Exception("Access token or Ad Account ID is missing.");
        }

        $levelFields = [
            'account' => [
                'account_name', 'impressions', 'reach', 'clicks', 'spend',
                'cpc', 'ctr', 'cpm', 'frequency', 'actions', 'action_values', 'purchase_roas'
            ],
            'campaign' => [
                'campaign_id', 'campaign_name', 'impressions', 'reach', 'clicks', 'spend',
                'cpc', 'ctr', 'cpm', 'frequency', 'actions', 'action_values', 'purchase_roas'
            ],
            'adset' => [
                'campaign_id', 'campaign_name', 'adset_id', 'adset_name', 'impressions', 'reach',
                'clicks', 'spend', 'cpc', 'ctr', 'cpm', 'frequency', 'actions', 'action_values', 'purchase_roas'
            ],
            'ad' => [
                'campaign_id', 'campaign_name', 'adset_id', 'adset_name', 'ad_id', 'ad_name',
                'impressions', 'reach', 'clicks', 'spend', 'cpc', 'ctr', 'cpm', 'frequency', 'actions', 'action_values', 'purchase_roas'
            ]
        ];

        $fields = $levelFields[$level] ?? $levelFields['account'];

        $params = [
            'fields'         => implode(',', $fields),
            'level'          => $level,
            'time_range'     => json_encode(['since' => $dateStart, 'until' => $dateStop]),
            'time_increment' => 1,
            'limit'          => 500
        ];

        $res = $this->makeApiCall("{$this->adAccountId}/insights", $params);
        return $res['data'] ?? [];
    }

    /**
     * Fetches all Ad Accounts accessible by the current access token.
     *
     * @return array List of accounts with keys 'id', 'name', 'account_id'
     * @throws Exception
     */
    public function getAdAccounts(): array {
        if (MOCK_META_API) {
            return [
                ['id' => 'act_1092837465', 'name' => 'Digital Rubix Demo Ad Account', 'account_id' => '1092837465'],
                ['id' => 'act_9876543210', 'name' => 'Sharma Jewellers Meta Ads', 'account_id' => '9876543210']
            ];
        }

        $res = $this->makeApiCall('me/adaccounts', [
            'fields' => 'id,name,account_id'
        ]);

        return $res['data'] ?? [];
    }

    /**
     * Exchanges short-lived User Access Token for long-lived (60-day) token.
     *
     * @param string $shortToken Short-lived OAuth access token
     * @return array Array with 'access_token' and 'expires_in' (seconds)
     * @throws Exception
     */
    public static function exchangeForLongLivedToken(string $shortToken): array {
        if (MOCK_META_API) {
            return [
                'access_token' => 'EAAG_mock_long_lived_token_' . bin2hex(random_bytes(16)),
                'expires_in'   => 5184000 // 60 days
            ];
        }

        $params = [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => META_APP_ID,
            'client_secret'     => META_APP_SECRET,
            'fb_exchange_token' => $shortToken
        ];

        $url = "https://graph.facebook.com/" . META_GRAPH_VERSION . "/oauth/access_token?" . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['error'])) {
            throw new Exception("Meta Token Exchange Error: " . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data ?? [];
    }

    /**
     * Generates realistic mock marketing insights for offline development & testing.
     *
     * @param string $level Insight level
     * @param string $dateStart Start date
     * @param string $dateStop End date
     * @return array Mock data records
     */
    private function generateMockInsights(string $level, string $dateStart, string $dateStop): array {
        $startDate = new DateTime($dateStart);
        $endDate = new DateTime($dateStop);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($startDate, $interval, $endDate->modify('+1 day'));

        $mockCampaigns = [
            ['id' => 'cmp_101', 'name' => 'Lead Gen - Noida Real Estate - Q3'],
            ['id' => 'cmp_102', 'name' => 'E-Commerce Purchase Retargeting'],
            ['id' => 'cmp_103', 'name' => 'Brand Awareness - Festive Sale']
        ];

        $mockAdsets = [
            ['id' => 'ads_201', 'name' => 'Interests: Luxury Jewelry & Fashion', 'cmp_id' => 'cmp_101'],
            ['id' => 'ads_202', 'name' => 'Lookalike 1% - Previous Purchasers', 'cmp_id' => 'cmp_102'],
            ['id' => 'ads_203', 'name' => 'Broad Metro Cities (Delhi-NCR)', 'cmp_id' => 'cmp_103']
        ];

        $mockAds = [
            ['id' => 'ad_301', 'name' => 'Carousel - Gold & Diamond Collection', 'ads_id' => 'ads_201'],
            ['id' => 'ad_302', 'name' => 'Video Ad - 15s Special Offer', 'ads_id' => 'ads_202'],
            ['id' => 'ad_303', 'name' => 'Single Image - 20% Off Coupon', 'ads_id' => 'ads_203']
        ];

        $results = [];

        foreach ($period as $dt) {
            $dayStr = $dt->format('Y-m-d');
            $seed = crc32($dayStr . $level);

            if ($level === 'campaign' || $level === 'account') {
                foreach ($mockCampaigns as $idx => $cmp) {
                    $impressions = 2000 + (($seed + $idx * 350) % 3000);
                    $reach = (int)($impressions * 0.82);
                    $clicks = (int)($impressions * (0.025 + (($seed % 15) / 1000)));
                    $spend = round(450 + (($seed + $idx * 120) % 850), 2);
                    $conversions = (int)($clicks * 0.12);
                    $purchaseValue = round($conversions * 1450.00, 2);
                    $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
                    $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0;
                    $cpm = $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0;
                    $costPerResult = $conversions > 0 ? round($spend / $conversions, 2) : 0;
                    $roas = $spend > 0 ? round($purchaseValue / $spend, 2) : 0;

                    $results[] = [
                        'campaign_id'     => $cmp['id'],
                        'campaign_name'   => $cmp['name'],
                        'date_start'      => $dayStr,
                        'date_stop'       => $dayStr,
                        'impressions'     => (string)$impressions,
                        'reach'           => (string)$reach,
                        'clicks'          => (string)$clicks,
                        'spend'           => (string)$spend,
                        'cpc'             => (string)$cpc,
                        'ctr'             => (string)$ctr,
                        'cpm'             => (string)$cpm,
                        'conversions'     => (string)$conversions,
                        'cost_per_result' => (string)$costPerResult,
                        'roas'            => (string)$roas,
                        'frequency'       => '1.22',
                        'action_values'   => [['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => $purchaseValue]]
                    ];

                    if ($level === 'account') break; // Only 1 row per date for account level
                }
            } elseif ($level === 'adset') {
                foreach ($mockAdsets as $idx => $ads) {
                    $impressions = 1200 + (($seed + $idx * 210) % 1800);
                    $clicks = (int)($impressions * 0.022);
                    $spend = round(250 + (($seed + $idx * 80) % 400), 2);
                    $conversions = (int)($clicks * 0.10);
                    $purchaseValue = round($conversions * 1200.00, 2);

                    $results[] = [
                        'adset_id'        => $ads['id'],
                        'adset_name'      => $ads['name'],
                        'campaign_name'   => 'Lead Gen - Noida Real Estate - Q3',
                        'date_start'      => $dayStr,
                        'date_stop'       => $dayStr,
                        'impressions'     => (string)$impressions,
                        'reach'           => (string)((int)($impressions * 0.85)),
                        'clicks'          => (string)$clicks,
                        'spend'           => (string)$spend,
                        'cpc'             => (string)($clicks > 0 ? round($spend / $clicks, 2) : 0),
                        'ctr'             => (string)($impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0),
                        'cpm'             => (string)($impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0),
                        'conversions'     => (string)$conversions,
                        'cost_per_result' => (string)($conversions > 0 ? round($spend / $conversions, 2) : 0),
                        'roas'            => (string)($spend > 0 ? round($purchaseValue / $spend, 2) : 0),
                        'frequency'       => '1.18'
                    ];
                }
            } elseif ($level === 'ad') {
                foreach ($mockAds as $idx => $ad) {
                    $impressions = 800 + (($seed + $idx * 150) % 1200);
                    $clicks = (int)($impressions * 0.024);
                    $spend = round(150 + (($seed + $idx * 50) % 250), 2);
                    $conversions = (int)($clicks * 0.11);
                    $purchaseValue = round($conversions * 950.00, 2);

                    $results[] = [
                        'ad_id'           => $ad['id'],
                        'ad_name'         => $ad['name'],
                        'adset_name'      => 'Interests: Luxury Jewelry & Fashion',
                        'campaign_name'   => 'Lead Gen - Noida Real Estate - Q3',
                        'date_start'      => $dayStr,
                        'date_stop'       => $dayStr,
                        'impressions'     => (string)$impressions,
                        'reach'           => (string)((int)($impressions * 0.88)),
                        'clicks'          => (string)$clicks,
                        'spend'           => (string)$spend,
                        'cpc'             => (string)($clicks > 0 ? round($spend / $clicks, 2) : 0),
                        'ctr'             => (string)($impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0),
                        'cpm'             => (string)($impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0),
                        'conversions'     => (string)$conversions,
                        'cost_per_result' => (string)($conversions > 0 ? round($spend / $conversions, 2) : 0),
                        'roas'            => (string)($spend > 0 ? round($purchaseValue / $spend, 2) : 0),
                        'frequency'       => '1.15'
                    ];
                }
            }
        }

        return $results;
    }
}
