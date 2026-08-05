<?php

namespace App\Services\Reports;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;

/**
 * The hosting fee: a flat monthly charge for infrastructure.
 *
 * Unlike the SYNC platform fee — which multiplies a per-subscriber rate by a
 * headcount — this is a single negotiated amount. It exists nowhere in any
 * monitored database and, like SyncPricing, is set through MONITOR's own
 * settings rather than imported from an expenses ledger.
 *
 * Two decisions carried over from SyncPricing, for the same reasons:
 *
 *  - The rate is an operator-editable setting rather than config. It is
 *    renegotiated more often than the application is deployed, and a figure that
 *    lands under Net Income should not need a release to correct.
 *
 *  - An unset rate reports `configured: false` rather than ₱0.00. Under a Net
 *    Income figure those read very differently: zero is a claim that hosting is
 *    free, and the panel says "not configured" instead of making it.
 */
class HostingFee
{
    /** Settings key for the flat monthly hosting fee. */
    public const SETTING_KEY = 'hosting_fee_monthly';

    /**
     * The hosting fee now in force, in pesos per month.
     *
     * Falls back to config when no setting has been saved, so a fresh
     * installation can ship a rate without an operator having to key one in.
     *
     * A failed lookup falls back to the same place rather than propagating —
     * see SyncPricing::rate() for the reasoning.
     */
    public static function rate(): float
    {
        try {
            $stored = AppSetting::get(self::SETTING_KEY);
        } catch (\Throwable $e) {
            Log::warning('Hosting fee setting unreadable; falling back to config', [
                'error' => $e->getMessage(),
            ]);

            $stored = null;
        }

        $value = $stored !== null && $stored !== ''
            ? (float) $stored
            : (float) config('reporting.hosting_fee.default', 0);

        // A negative fee would subtract from expenses and quietly inflate net
        // income, which is the one direction a mis-keyed figure must not go.
        return max(0.0, $value);
    }

    /**
     * The block the Expenses section renders.
     *
     * Flat fee — no multiplier, no headcount. If the rate is zero the panel
     * reports "not configured" rather than a confident ₱0.00.
     */
    public static function build(): array
    {
        $rate = self::rate();
        $configured = $rate > 0;

        return [
            'configured' => $configured,
            'rate' => round($rate, 2),
            // The total equals the rate for a flat fee — kept as a separate key
            // so the frontend renders it the same way it renders SyncPricing,
            // and so a future change to per-site or per-branch pricing can add
            // a multiplier without touching every consumer.
            'total' => $configured ? round($rate, 2) : null,
        ];
    }
}
