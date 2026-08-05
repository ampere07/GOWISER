<?php

namespace App\Services\Reports;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;

/**
 * The SYNC platform fee: a rate per billable subscriber, times the headcount.
 *
 * This is an expense that exists nowhere in any monitored database. SYNC is
 * licensed per subscriber, so the charge is a headcount times a negotiated rate
 * rather than a row somebody keys into `expenses_logs` — and MONITOR could not
 * write it there anyway. The count therefore comes from the source (see
 * GowiserReportsDriver::syncBillableAccounts) and the rate from MONITOR's own
 * settings table, and this class is the one place they are multiplied.
 *
 * Two decisions worth keeping:
 *
 *  - The rate is an operator-editable setting rather than config. It is
 *    renegotiated more often than the application is deployed, and a figure that
 *    lands under Net Income should not need a release to correct.
 *
 *  - An unset rate reports `configured: false` rather than ₱0.00. Under a Net
 *    Income figure those read very differently: zero is a claim that SYNC is
 *    free, and the panel says "not configured" instead of making it.
 *
 * VIP and Pullout accounts are excluded from the headcount, per the brief. That
 * exclusion is applied in SQL where the count is taken, not here, so it costs
 * nothing and cannot drift between the count and the total.
 */
class SyncPricing
{
    /** Settings key for the negotiated per-subscriber rate. */
    public const SETTING_KEY = 'sync_price_per_customer';

    /**
     * The rate now in force, in pesos per billable subscriber per month.
     *
     * Falls back to config when no setting has been saved, so a fresh
     * installation can ship a rate without an operator having to key one in.
     *
     * A failed lookup falls back to the same place rather than propagating. This
     * one value is read while composing a page assembled from four sections
     * across every monitored database, and letting a settings-table read take
     * that whole page down would be the tail wagging the dog — every other
     * section already degrades rather than blanking the screen.
     */
    public static function rate(): float
    {
        try {
            $stored = AppSetting::get(self::SETTING_KEY);
        } catch (\Throwable $e) {
            Log::warning('SYNC price setting unreadable; falling back to config', [
                'error' => $e->getMessage(),
            ]);

            $stored = null;
        }

        $value = $stored !== null && $stored !== ''
            ? (float) $stored
            : (float) config('reporting.sync_price.default', 0);

        // A negative rate would subtract from expenses and quietly inflate net
        // income, which is the one direction a mis-keyed figure must not go.
        return max(0.0, $value);
    }

    /**
     * Billing statuses that are not charged for, lower-cased.
     *
     * @return string[]
     */
    public static function excludedStatuses(): array
    {
        $configured = config('reporting.sync_price.excluded_statuses');
        $values = is_array($configured) && $configured !== []
            ? $configured
            : ['vip', 'pullout', 'pulled out'];

        return array_values(array_unique(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $values
        )));
    }

    /**
     * The block the Expenses section renders.
     *
     * @param int|null $billableAccounts null when the source could not be asked
     */
    public static function build(?int $billableAccounts): array
    {
        $rate = self::rate();
        $configured = $rate > 0;

        return [
            'configured' => $configured,
            'rate' => round($rate, 2),
            'billable_accounts' => $billableAccounts,
            'excluded_statuses' => self::excludedStatuses(),
            // Null rather than 0 when either half is missing, so the Expenses
            // panel can say which — an unset rate and an unreachable database
            // are different problems with different fixes.
            'total' => $configured && $billableAccounts !== null
                ? round($rate * $billableAccounts, 2)
                : null,
        ];
    }
}
