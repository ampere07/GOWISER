<?php

namespace App\Services;

use App\Models\BillingAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Manages the prepaid service-period window (billing_accounts.prepaid_expires_at).
 *
 * Invoked from BOTH payment pipelines at the moment a payment settles the balance (the same
 * point the existing auto-reconnect fires):
 *   - Portal  : PaymentWorkerService::processPayment (after commit, in the balance-settled block)
 *   - Manual  : TransactionController::attemptReconnectionAfterApproval (after the balance check)
 *
 * This class ONLY manages prepaid_expires_at. RADIUS reconnection and re-activation
 * (billing_status -> Active) are intentionally left to the existing reconnect flow so there is a
 * single source of truth for reconnection and we never double-kick a live PPPoE session.
 */
class PrepaidRenewalService
{
    /** Length of one prepaid service period, in days. */
    public const PREPAID_PERIOD_DAYS = 30;

    /**
     * Extend or (re)start a prepaid customer's service period after a settling payment.
     *
     * No-op for non-prepaid accounts, so it is safe to call unconditionally on every payment.
     *
     * Rules (see spec):
     *   - Still active (prepaid_expires_at is in the future relative to the payment): EXTEND from
     *     the current expiry (+30 days) so an early payer never loses their remaining days.
     *   - Expired or never set (null / in the past): start a FRESH 30-day period from the
     *     payment date.
     *
     * @return array{prepaid:bool, mode?:string, previous_expiry?:?string, new_expiry?:string, error?:string}
     */
    public function renewByAccountNo(string $accountNo, ?Carbon $paymentDate = null): array
    {
        try {
            $account = BillingAccount::where('account_no', $accountNo)->first();
            if (!$account) {
                return ['prepaid' => false];
            }
            return $this->renew($account, $paymentDate);
        } catch (\Throwable $e) {
            Log::error('[PREPAID RENEWAL] Failed for account ' . $accountNo . ': ' . $e->getMessage());
            return ['prepaid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @see renewByAccountNo()
     */
    public function renew(BillingAccount $account, ?Carbon $paymentDate = null): array
    {
        // Only prepaid accounts have a service period; postpaid is entirely unaffected.
        if ($account->generation_type !== 'Pre Paid') {
            return ['prepaid' => false];
        }

        $paymentDate = $paymentDate ? $paymentDate->copy() : Carbon::now();
        $current = $account->prepaid_expires_at ? Carbon::parse($account->prepaid_expires_at) : null;

        if ($current && $current->greaterThan($paymentDate)) {
            // Early payment while still active — extend from the EXISTING expiry, preserving
            // every remaining prepaid day (e.g. expiry Jul 31 + pay Jul 20 => Aug 30).
            $newExpiry = $current->copy()->addDays(self::PREPAID_PERIOD_DAYS);
            $mode = 'extended';
        } else {
            // Expired or never set — start a fresh period from the payment date.
            $newExpiry = $paymentDate->copy()->addDays(self::PREPAID_PERIOD_DAYS);
            $mode = 'renewed';
        }

        DB::table('billing_accounts')
            ->where('id', $account->id)
            ->update([
                'prepaid_expires_at' => $newExpiry,
                'updated_by' => 'Prepaid Renewal',
                'updated_at' => Carbon::now(),
            ]);

        Log::info('[PREPAID RENEWAL] Prepaid period ' . $mode, [
            'account_no' => $account->account_no,
            'previous_expiry' => $current?->toDateTimeString(),
            'new_expiry' => $newExpiry->toDateTimeString(),
            'payment_date' => $paymentDate->toDateTimeString(),
        ]);

        return [
            'prepaid' => true,
            'mode' => $mode,
            'previous_expiry' => $current?->toDateTimeString(),
            'new_expiry' => $newExpiry->toDateTimeString(),
        ];
    }
}
