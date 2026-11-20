<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Exchange rate between UGX and USD
|--------------------------------------------------------------------------
| Activity/event requests are usually raised in UGX while project/program
| budget lines are kept in USD. Wherever a request is compared with a budget
| line (finance reports, budget burn, director approval) the request amount
| is converted into the budget line's currency with this rate.
|
| The rate is system_settings.usd_ugx_rate (System Settings > General,
| "1 USD = ? UGX"); 3,700 until an administrator changes it. Only UGX <-> USD
| is converted; other currency pairs must match exactly.
*/

const IMS_DEFAULT_USD_UGX_RATE = 3700.0;

if (!function_exists('ims_usd_ugx_rate')) {
    function ims_usd_ugx_rate(?mysqli $conn = null): float
    {
        static $rate = null;
        if ($rate !== null) {
            return $rate;
        }
        $rate = IMS_DEFAULT_USD_UGX_RATE;
        $conn ??= ($GLOBALS['conn'] ?? null);
        if ($conn instanceof mysqli) {
            try {
                $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'usd_ugx_rate' LIMIT 1");
                if ($stmt && $stmt->execute()) {
                    $value = (float)($stmt->get_result()->fetch_column() ?? 0);
                    if ($value > 0) {
                        $rate = $value;
                    }
                }
                $stmt?->close();
            } catch (Throwable $e) {
                error_log('ims_usd_ugx_rate: ' . $e->getMessage());
            }
        }
        return $rate;
    }
}

if (!function_exists('ims_convert_amount')) {
    /** Convert between UGX and USD; null when the pair cannot be converted. */
    function ims_convert_amount(float $amount, string $from, string $to, float $rate): ?float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === $to) {
            return $amount;
        }
        if ($rate <= 0) {
            return null;
        }
        if ($from === 'UGX' && $to === 'USD') {
            return $amount / $rate;
        }
        if ($from === 'USD' && $to === 'UGX') {
            return $amount * $rate;
        }
        return null;
    }
}

if (!function_exists('ims_fx_sql')) {
    /**
     * SQL expression converting $amount (in currency column $from) into the
     * currency column $to. Amounts in currencies that cannot be converted
     * count as 0. The rate is a validated float, never user text.
     */
    function ims_fx_sql(string $amount, string $from, string $to, float $rate): string
    {
        $r = sprintf('%.6F', max(0.000001, $rate));
        return "(CASE WHEN UPPER({$from}) = UPPER({$to}) THEN {$amount}"
            . " WHEN UPPER({$from}) = 'UGX' AND UPPER({$to}) = 'USD' THEN {$amount} / {$r}"
            . " WHEN UPPER({$from}) = 'USD' AND UPPER({$to}) = 'UGX' THEN {$amount} * {$r}"
            . ' ELSE 0 END)';
    }
}

if (!function_exists('ims_fx_convertible_sql')) {
    /** SQL condition: true when $from can be converted into $to. */
    function ims_fx_convertible_sql(string $from, string $to): string
    {
        return "(UPPER({$from}) = UPPER({$to}) OR (UPPER({$from}) IN ('UGX','USD') AND UPPER({$to}) IN ('UGX','USD')))";
    }
}
