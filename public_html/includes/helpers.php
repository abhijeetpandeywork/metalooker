<?php
/**
 * Helper Utility Functions
 *
 * Provides view formatting, currency calculations, date range parsing, .env writing, and sanitization tools.
 *
 * @package MetaPanel\Includes
 */

/**
 * Escapes strings for safe HTML output (XSS Prevention).
 *
 * @param string|null $string String to escape
 * @return string Escaped HTML string
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formats a float or decimal amount to a localized currency string.
 *
 * @param float|int|string $amount Numeric value
 * @param string $currency ISO currency code (e.g. INR, USD, AED)
 * @return string Formatted currency string
 */
function formatCurrency($amount, string $currency = 'INR'): string {
    $val = (float)$amount;
    $curr = strtoupper(trim($currency));
    $symbol = match ($curr) {
        'INR' => '₹',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AED' => 'AED ',
        'SAR' => 'SAR ',
        'QAR' => 'QAR ',
        'KWD' => 'KWD ',
        'OMR' => 'OMR ',
        'BHD' => 'BHD ',
        'CAD' => 'CA$',
        'AUD' => 'A$',
        'SGD' => 'S$',
        'NZD' => 'NZ$',
        'MYR' => 'RM ',
        'THB' => '฿',
        'JPY' => '¥',
        'ZAR' => 'R ',
        'BRL' => 'R$',
        'MXN' => 'Mex$',
        'EGP' => 'E£ ',
        'PHP' => '₱',
        'IDR' => 'Rp ',
        'VND' => '₫',
        'PKR' => 'Rs ',
        'BDT' => '৳',
        'LKR' => 'Rs ',
        'CHF' => 'CHF ',
        default => $curr . ' '
    };

    return $symbol . number_format($val, 2, '.', ',');
}

/**
 * Formats integer values into comma-separated strings (e.g. 10,450).
 *
 * @param int|float|string $num Number to format
 * @return string Formatted integer string
 */
function formatNumber($num): string {
    return number_format((float)$num, 0, '.', ',');
}

/**
 * Formats decimal percentages (e.g. 2.45%).
 *
 * @param float|int|string $val Decimal value
 * @return string Formatted percentage string
 */
function formatPercent($val): string {
    return number_format((float)$val, 2, '.', '') . '%';
}

/**
 * Returns date range bounds (start date and end date YYYY-MM-DD) based on preset key.
 *
 * @param string $preset Preset key: last_7, last_14, last_30, this_month, last_month
 * @return array Array containing 'start' and 'end' date strings
 */
function getDateRangeBounds(string $preset): array {
    $today = new DateTime();
    $yesterday = (new DateTime())->modify('-1 day');

    switch ($preset) {
        case 'last_7':
            $start = (new DateTime())->modify('-7 days')->format('Y-m-d');
            $end = $yesterday->format('Y-m-d');
            break;
        case 'last_14':
            $start = (new DateTime())->modify('-14 days')->format('Y-m-d');
            $end = $yesterday->format('Y-m-d');
            break;
        case 'this_month':
            $start = (new DateTime())->modify('first day of this month')->format('Y-m-d');
            $end = $today->format('Y-m-d');
            break;
        case 'last_month':
            $start = (new DateTime())->modify('first day of last month')->format('Y-m-d');
            $end = (new DateTime())->modify('last day of last month')->format('Y-m-d');
            break;
        case 'last_30':
        default:
            $start = (new DateTime())->modify('-30 days')->format('Y-m-d');
            $end = $yesterday->format('Y-m-d');
            break;
    }

    return ['start' => $start, 'end' => $end];
}

/**
 * Updates a key-value pair in .env files directly on disk.
 *
 * @param string $key Environment variable key
 * @param string $value Environment variable value
 * @return bool True if updated successfully
 */
function updateEnvFile(string $key, string $value): bool {
    $paths = [
        dirname(__DIR__) . '/.env',
        dirname(__DIR__, 2) . '/.env'
    ];
    $success = false;

    foreach ($paths as $envPath) {
        if (!file_exists($envPath)) {
            @file_put_contents($envPath, "");
        }
        $content = file_get_contents($envPath);
        $keyPattern = "/^" . preg_quote($key, '/') . "=.*$/m";
        if (preg_match($keyPattern, $content)) {
            $content = preg_replace($keyPattern, "{$key}={$value}", $content);
        } else {
            $content = rtrim($content) . "\n{$key}={$value}\n";
        }
        if (file_put_contents($envPath, $content) !== false) {
            $success = true;
        }
    }
    return $success;
}

/**
 * Returns complete global country list with default ISO currency codes.
 *
 * @return array Map of country names to country code & currency
 */
function getGlobalCountriesList(): array {
    return [
        'India' => ['code' => 'IN', 'currency' => 'INR', 'symbol' => '₹'],
        'United States' => ['code' => 'US', 'currency' => 'USD', 'symbol' => '$'],
        'United Arab Emirates' => ['code' => 'AE', 'currency' => 'AED', 'symbol' => 'AED '],
        'Saudi Arabia' => ['code' => 'SA', 'currency' => 'SAR', 'symbol' => 'SAR '],
        'Qatar' => ['code' => 'QA', 'currency' => 'QAR', 'symbol' => 'QAR '],
        'Kuwait' => ['code' => 'KW', 'currency' => 'KWD', 'symbol' => 'KWD '],
        'Oman' => ['code' => 'OM', 'currency' => 'OMR', 'symbol' => 'OMR '],
        'Bahrain' => ['code' => 'BH', 'currency' => 'BHD', 'symbol' => 'BHD '],
        'United Kingdom' => ['code' => 'GB', 'currency' => 'GBP', 'symbol' => '£'],
        'Canada' => ['code' => 'CA', 'currency' => 'CAD', 'symbol' => 'CA$'],
        'Australia' => ['code' => 'AU', 'currency' => 'AUD', 'symbol' => 'A$'],
        'Singapore' => ['code' => 'SG', 'currency' => 'SGD', 'symbol' => 'S$'],
        'Malaysia' => ['code' => 'MY', 'currency' => 'MYR', 'symbol' => 'RM '],
        'Thailand' => ['code' => 'TH', 'currency' => 'THB', 'symbol' => '฿'],
        'Japan' => ['code' => 'JP', 'currency' => 'JPY', 'symbol' => '¥'],
        'Germany' => ['code' => 'DE', 'currency' => 'EUR', 'symbol' => '€'],
        'France' => ['code' => 'FR', 'currency' => 'EUR', 'symbol' => '€'],
        'Italy' => ['code' => 'IT', 'currency' => 'EUR', 'symbol' => '€'],
        'Spain' => ['code' => 'ES', 'currency' => 'EUR', 'symbol' => '€'],
        'Netherlands' => ['code' => 'NL', 'currency' => 'EUR', 'symbol' => '€'],
        'Switzerland' => ['code' => 'CH', 'currency' => 'CHF', 'symbol' => 'CHF '],
        'South Africa' => ['code' => 'ZA', 'currency' => 'ZAR', 'symbol' => 'R '],
        'Brazil' => ['code' => 'BR', 'currency' => 'BRL', 'symbol' => 'R$'],
        'Mexico' => ['code' => 'MX', 'currency' => 'MXN', 'symbol' => 'Mex$'],
        'Egypt' => ['code' => 'EG', 'currency' => 'EGP', 'symbol' => 'E£ '],
        'Philippines' => ['code' => 'PH', 'currency' => 'PHP', 'symbol' => '₱'],
        'Indonesia' => ['code' => 'ID', 'currency' => 'IDR', 'symbol' => 'Rp '],
        'Vietnam' => ['code' => 'VN', 'currency' => 'VND', 'symbol' => '₫'],
        'Pakistan' => ['code' => 'PK', 'currency' => 'PKR', 'symbol' => 'Rs '],
        'Bangladesh' => ['code' => 'BD', 'currency' => 'BDT', 'symbol' => '৳'],
        'Sri Lanka' => ['code' => 'LK', 'currency' => 'LKR', 'symbol' => 'Rs '],
        'Nepal' => ['code' => 'NP', 'currency' => 'NPR', 'symbol' => 'NPR ']
    ];
}

/**
 * Maps 2-letter ISO country code to human readable country name.
 *
 * @param string $code 2-letter ISO country code
 * @return string Country Name
 */
function getCountryNameByCode(string $code): string {
    $cCode = strtoupper(trim($code));
    $list = getGlobalCountriesList();
    foreach ($list as $cName => $cInfo) {
        if ($cInfo['code'] === $cCode) {
            return $cName;
        }
    }
    return 'India';
}

/**
 * Converts monetary amount from target currency to INR base representation.
 *
 * @param float $amount Amount in source currency
 * @param string $currency 3-letter ISO Currency Code
 * @return float Equivalent amount in INR
 */
function convertToInr(float $amount, string $currency): float {
    $c = strtoupper(trim($currency));
    $rates = [
        'INR' => 1.0,
        'USD' => 84.50,
        'EUR' => 92.00,
        'GBP' => 108.00,
        'AED' => 23.00,
        'SAR' => 22.50,
        'QAR' => 23.20,
        'KWD' => 275.00,
        'OMR' => 219.00,
        'BHD' => 224.00,
        'CAD' => 62.00,
        'AUD' => 55.00,
        'SGD' => 63.00,
        'NZD' => 51.00,
        'MYR' => 19.00,
        'THB' => 2.40,
        'JPY' => 0.55,
        'ZAR' => 4.60,
        'BRL' => 15.20,
        'MXN' => 4.30,
        'EGP' => 1.75,
        'PHP' => 1.48,
        'IDR' => 0.0055,
        'VND' => 0.0034,
        'PKR' => 0.30,
        'BDT' => 0.72,
        'LKR' => 0.28,
        'NPR' => 0.63
    ];
    return $amount * ($rates[$c] ?? 1.0);
}
