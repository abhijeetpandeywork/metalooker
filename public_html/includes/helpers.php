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
