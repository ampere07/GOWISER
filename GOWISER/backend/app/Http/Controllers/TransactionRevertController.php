<?php

namespace App\Http\Controllers;

use App\Models\TransactionRevert;
use App\Models\Transaction;
use App\Models\User;
use App\Models\BillingAccount;
use App\Models\OnlineStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Events\TransactionUpdated;
use App\Events\TransactionRevertViewingUpdate;

class TransactionRevertController extends Controller
{
    /**
     * Remark stamped on the RADIUS restriction raised by
     * {@see restrictExpiredPrepaidAfterRevert()}.
     *
     * Deliberately different from AutoDisconnectService's 'Prepaid Period Expired' so support can
     * tell a routine nightly lapse apart from a payment that was taken back out from under the
     * customer — the two need very different conversations.
     */
    private const PREPAID_REVERT_RESTRICTION_REMARKS = 'Transaction Revert — Prepaid Period Expired';

    public function broadcastViewing(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'revert_id' => 'required|string',
                'action' => 'required|string|in:started_viewing,stopped_viewing'
            ]);

            $username = Auth::user()->username ?? Auth::user()->name ?? 'Unknown User';
            
            \Log::info('[Presence] Transaction Revert broadcast:', [
                'revert_id' => $validated['revert_id'],
                'username' => $username,
                'action' => $validated['action']
            ]);

            event(new TransactionRevertViewingUpdate(
                $validated['revert_id'],
                $username,
                $validated['action']
            ));

            return response()->json([
                'success' => true,
                'message' => 'Viewing status broadcasted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error broadcasting viewing status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to broadcast viewing status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $query = TransactionRevert::with([
                'transaction.account.customer',
                'transaction.processor',
                'transaction.paymentMethodInfo',
                'requester',
                'updater'
            ]);

            if ($request->has('updated_since')) {
                $query->where('updated_at', '>', $request->input('updated_since'));
            }

            if (!$isSuperAdmin && $organizationId) {
                $query->where('organization_id', $organizationId);
            }

            $reverts = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $reverts,
                'count' => $reverts->count(),
                'server_time' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching transaction reverts: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch revert requests',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_id' => 'required|exists:transactions,id',
                'remarks' => 'nullable|string',
                'reason' => 'required|string',
                'requested_by' => 'nullable|string',
            ]);

            // Find user by email address
            $requestedByUserId = null;
            if (!empty($validated['requested_by'])) {
                $user = User::where('email_address', $validated['requested_by'])->first();
                if ($user) {
                    $requestedByUserId = $user->id;
                }
            }

            // Check if there's already a pending revert request for this transaction
            $existing = TransactionRevert::where('transaction_id', $validated['transaction_id'])
                ->where('status', 'pending')
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'There is already a pending revert request for this transaction'
                ], 400);
            }

            $revert = TransactionRevert::create([
                'transaction_id' => $validated['transaction_id'],
                'remarks' => $validated['remarks'] ?? null,
                'reason' => $validated['reason'],
                'status' => 'pending',
                'requested_by' => $requestedByUserId,
                'updated_by' => null,
                'organization_id' => auth()->user() ? auth()->user()->organization_id : null,
            ]);

            $revert->load(['transaction.account.customer', 'transaction.processor', 'transaction.paymentMethodInfo', 'requester', 'updater']);

            \Log::info('Transaction revert request created', [
                'revert_id' => $revert->id,
                'transaction_id' => $validated['transaction_id'],
                'requested_by' => $validated['requested_by']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Revert request submitted successfully',
                'data' => $revert
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error creating transaction revert request: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit revert request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $revert = TransactionRevert::with([
                'transaction.account.customer',
                'transaction.processor',
                'transaction.paymentMethodInfo',
                'requester',
                'updater'
            ])->findOrFail($id);

            if (!$isSuperAdmin && $organizationId && $revert->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to revert request'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $revert
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revert request not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $validated = $request->validate([
                'status' => 'required|string|in:pending,done,rejected',
                'updated_by' => 'nullable|string',
            ]);

            /*
             * Carried out of the transaction so the prepaid expiry rule can be enforced AFTER the
             * commit. A RADIUS restriction is an external side effect that cannot be rolled back,
             * so issuing it inside the transaction would risk cutting off a customer whose revert
             * then failed to commit.
             *
             * $prepaidEnforcementAccountNo stays null unless a revert actually restored an account.
             * $restoredSessionGroup records where the session_group restore below put the customer,
             * so the expiry rule does not act a second time on someone already pushed offline.
             */
            $prepaidEnforcementAccountNo = null;
            $restoredSessionGroup        = null;

            DB::beginTransaction();

            $revert = TransactionRevert::findOrFail($id);

            if (!$isSuperAdmin && $organizationId && $revert->organization_id !== $organizationId) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to revert request'
                ], 403);
            }

            $updatedByUserId = null;
            if (!empty($validated['updated_by'])) {
                $user = User::where('email_address', $validated['updated_by'])->first();
                if ($user) {
                    $updatedByUserId = $user->id;
                }
            }

            $revert->status = $validated['status'];
            $revert->updated_by = $updatedByUserId ?? (Auth::id() ?? $revert->updated_by);
            $revert->save();

            // If status is being set to 'done', perform the actual revert on the transaction
            if ($validated['status'] === 'done') {
                $transaction = Transaction::findOrFail($revert->transaction_id);

                if ($transaction->status === 'Done') {
                    $accountNo = $transaction->account_no;
                    $transactionId = $transaction->id;
                    $userId = Auth::id();
                    $currentTime = now();

                    // Decode updated_column snapshot (old state before approval)
                    $snapshot = $transaction->updated_column; // already cast to array by model

                    if (!empty($snapshot) && is_array($snapshot)) {
                        // --- Restore billing_accounts balance from snapshot ---
                        $billingAccountSnapshot = collect($snapshot)->firstWhere('table', 'billing_accounts');

                        if ($billingAccountSnapshot && $accountNo) {
                            $billingAccount = BillingAccount::where('account_no', $accountNo)->first();

                            if ($billingAccount && $transaction->transaction_type !== 'Security Deposit') {
                                $billingAccount->account_balance = round(floatval($billingAccountSnapshot['old_account_balance']), 2);
                                $billingAccount->balance_update_date = $currentTime;
                                $billingAccount->updated_by = $userId;

                                // Restore billing_status_id only if the snapshot recorded it
                                // AND it actually changed during approval (e.g. reconnection set it to Active)
                                $snapshotStatusId = $billingAccountSnapshot['old_billing_status_id'] ?? null;
                                $currentStatusId  = $billingAccount->billing_status_id;

                                if ($snapshotStatusId !== null && $snapshotStatusId !== $currentStatusId) {
                                    $billingAccount->billing_status_id = $snapshotStatusId;
                                    \Log::info('Revert via snapshot: billing_status_id restored', [
                                        'account_no'   => $accountNo,
                                        'from_status'  => $currentStatusId,
                                        'to_status'    => $snapshotStatusId,
                                    ]);
                                } else {
                                    \Log::info('Revert via snapshot: billing_status_id unchanged, no restore needed', [
                                        'account_no'  => $accountNo,
                                        'status_id'   => $currentStatusId,
                                    ]);
                                }

                                $billingAccount->save();

                                \Log::info('Revert via snapshot: billing_accounts balance restored', [
                                    'account_no'       => $accountNo,
                                    'restored_balance' => $billingAccountSnapshot['old_account_balance'],
                                ]);
                            }
                        }

                        // --- Restore each invoice from snapshot ---
                        $invoiceSnapshots = collect($snapshot)->where('table', 'invoices')->values();

                        foreach ($invoiceSnapshots as $invSnap) {
                            $invoice = \App\Models\Invoice::find($invSnap['invoice_id']);
                            if (!$invoice) continue;

                            $invoice->received_payment = round(floatval($invSnap['old_received_payment']), 2);
                            $invoice->status           = $invSnap['old_status'];
                            $invoice->transaction_id   = null;
                            $invoice->updated_by       = Auth::check() ? Auth::user()->email_address : 'unknown';
                            $invoice->updated_at       = $currentTime;
                            $invoice->save();

                            \Log::info('Revert via snapshot: invoice restored', [
                                'invoice_id'           => $invSnap['invoice_id'],
                                'restored_status'      => $invSnap['old_status'],
                                'restored_received'    => $invSnap['old_received_payment'],
                            ]);
                        }

                        // Also clear transaction_id from any invoice that still references this transaction
                        // but was NOT in the snapshot (edge-case guard)
                        \App\Models\Invoice::where('transaction_id', (string)$transactionId)
                            ->whereNotIn('id', $invoiceSnapshots->pluck('invoice_id')->toArray())
                            ->update(['transaction_id' => null, 'updated_at' => $currentTime]);

                        /*
                         * --- Restore prepaid state from snapshot ---
                         *
                         * Undoes what attemptReconnectionAfterApproval() did after the
                         * approval committed: PrepaidRenewalService pushed
                         * prepaid_expires_at forward, and PrepaidPlanChangeService either
                         * switched plan_id outright or queued pending_plan_id /
                         * pending_plan_effective_at. None of that was previously captured,
                         * so a reverted prepaid customer kept the service days and the plan
                         * the payment bought them.
                         *
                         * Runs inside the surrounding DB transaction, so a failure here rolls
                         * back the balance and invoice restores too rather than leaving the
                         * account half-reverted.
                         *
                         * Idempotent: each field is written from the snapshot to an exact
                         * value, never adjusted relatively, and the snapshot is cleared at
                         * the end of this method — so a second revert cannot double-apply.
                         */
                        $prepaidSnapshot = collect($snapshot)->firstWhere('table', 'billing_accounts_prepaid');

                        if ($prepaidSnapshot && $accountNo) {
                            $restored = $this->restorePrepaidSnapshot($prepaidSnapshot, $accountNo, $userId);

                            \Log::info(
                                $restored === []
                                    ? 'Revert via snapshot: prepaid state already matches snapshot, nothing to restore'
                                    : 'Revert via snapshot: prepaid state restored',
                                [
                                    'account_no'     => $accountNo,
                                    'transaction_id' => $transactionId,
                                    'restored'       => $restored,
                                ]
                            );
                        } else {
                            // Approved before prepaid capture existed. Say so explicitly —
                            // silence here would look identical to "nothing needed to change".
                            \Log::warning('Revert: no prepaid snapshot on this transaction; prepaid expiry/plan NOT restored', [
                                'transaction_id' => $transactionId,
                                'account_no'     => $accountNo,
                            ]);
                        }

                        // --- Restore online_status session_group from snapshot ---
                        $onlineStatusSnapshot = collect($snapshot)->firstWhere('table', 'online_status');
                        if ($onlineStatusSnapshot) {
                            $onlineStatusRecord = OnlineStatus::where('account_id', $onlineStatusSnapshot['account_id'])->first();
                            if ($onlineStatusRecord) {
                                $snapshotGroup  = $onlineStatusSnapshot['old_session_group'] ?? null;
                                $snapshotStatus = $onlineStatusSnapshot['old_session_status'] ?? null;
                                $username       = $onlineStatusSnapshot['username'] ?? $onlineStatusRecord->username;

                                $normalizedGroup = strtolower(trim($snapshotGroup ?? ''));

                                // Remembered for the post-commit prepaid expiry check: 'restricted'
                                // and 'disconnected' mean this restore already took the customer
                                // offline, so the expiry rule must not restrict them again.
                                $restoredSessionGroup = $normalizedGroup;

                                if ($normalizedGroup === 'restricted') {
                                    // Old session_group was Restricted — push user back to Restricted in RADIUS
                                    try {
                                        $manualRadiusService = new \App\Services\ManualRadiusOperationsService();
                                        $result = $manualRadiusService->restrictedUser([
                                            'accountNumber' => $accountNo,
                                            'username'      => $username,
                                            'remarks'       => 'Transaction Revert — restoring Restricted status',
                                            'updatedBy'     => Auth::check() ? Auth::user()->email_address : 'unknown',
                                        ]);

                                        if ($result['status'] === 'success') {
                                            \Log::info('Revert: RADIUS restrictedUser called successfully', [
                                                'account_no' => $accountNo,
                                                'username'   => $username,
                                            ]);
                                        } else {
                                            \Log::warning('Revert: RADIUS restrictedUser call failed', [
                                                'account_no' => $accountNo,
                                                'username'   => $username,
                                                'reason'     => $result['message'] ?? 'unknown',
                                            ]);
                                        }
                                    } catch (\Throwable $radiusEx) {
                                        \Log::error('Revert: Exception calling restrictedUser', [
                                            'account_no' => $accountNo,
                                            'username'   => $username,
                                            'error'      => $radiusEx->getMessage(),
                                        ]);
                                    }

                                } elseif ($normalizedGroup === 'disconnected') {
                                    // Old session_group was Disconnected — push user back to Disconnected in RADIUS
                                    try {
                                        $manualRadiusService = new \App\Services\ManualRadiusOperationsService();
                                        $result = $manualRadiusService->disconnectUser([
                                            'accountNumber' => $accountNo,
                                            'username'      => $username,
                                            'remarks'       => 'Transaction Revert — restoring Disconnected status',
                                            'updatedBy'     => Auth::check() ? Auth::user()->email_address : 'unknown',
                                        ]);

                                        if ($result['status'] === 'success') {
                                            \Log::info('Revert: RADIUS disconnectUser called successfully', [
                                                'account_no' => $accountNo,
                                                'username'   => $username,
                                            ]);
                                        } else {
                                            \Log::warning('Revert: RADIUS disconnectUser call failed', [
                                                'account_no' => $accountNo,
                                                'username'   => $username,
                                                'reason'     => $result['message'] ?? 'unknown',
                                            ]);
                                        }
                                    } catch (\Throwable $radiusEx) {
                                        \Log::error('Revert: Exception calling disconnectUser', [
                                            'account_no' => $accountNo,
                                            'username'   => $username,
                                            'error'      => $radiusEx->getMessage(),
                                        ]);
                                    }

                                } else {
                                    // Old session_group is a plan name — no RADIUS call needed.
                                    // The DB-side online_status record is already correct after
                                    // reconnectUser() ran during approval; approval already set it
                                    // to the plan group, which is what it was before, so nothing to undo.
                                    \Log::info('Revert: online_status session_group is a plan name — no RADIUS action required', [
                                        'account_no'    => $accountNo,
                                        'username'      => $username,
                                        'session_group' => $snapshotGroup,
                                    ]);
                                }
                            }
                        }

                    } else {
                        // --- Fallback: no snapshot, use old subtraction-based logic ---
                        \Log::warning('Revert: no updated_column snapshot found, falling back to payment-subtraction logic', [
                            'transaction_id' => $transactionId,
                        ]);

                        if ($accountNo) {
                            $billingAccount = BillingAccount::where('account_no', $accountNo)->first();
                            $paymentToRevert = floatval($transaction->received_payment);

                            if ($billingAccount && $transaction->transaction_type !== 'Security Deposit') {
                                $billingAccount->account_balance = round(floatval($billingAccount->account_balance) + $paymentToRevert, 2);
                                $billingAccount->balance_update_date = $currentTime;
                                $billingAccount->updated_by = $userId;

                                // Fallback: no snapshot available for billing_status_id.
                                // We cannot safely restore the old status here, so we log a warning.
                                \Log::warning('Revert fallback: billing_status_id not restored (no snapshot)', [
                                    'transaction_id' => $transactionId,
                                    'account_no'     => $accountNo,
                                ]);

                                $billingAccount->save();

                                $invoices = \App\Models\Invoice::where('transaction_id', (string)$transactionId)
                                    ->orderBy('invoice_date', 'desc')
                                    ->get();

                                $remainingToRevert = $paymentToRevert;

                                foreach ($invoices as $invoice) {
                                    if ($remainingToRevert <= 0) break;

                                    $currentReceived = floatval($invoice->received_payment ?? 0);
                                    if ($currentReceived <= 0) continue;

                                    $toSubtract  = min($currentReceived, $remainingToRevert);
                                    $newReceived = $currentReceived - $toSubtract;

                                    $invoice->received_payment = round($newReceived, 2);
                                    $invoice->status           = $newReceived <= 0 ? 'Unpaid' : 'Partial';
                                    $invoice->transaction_id   = null;
                                    $invoice->updated_by       = Auth::check() ? Auth::user()->email_address : 'unknown';
                                    $invoice->updated_at       = $currentTime;
                                    $invoice->save();

                                    $remainingToRevert -= $toSubtract;
                                }
                            }
                        }
                    }

                    // Update transaction back to Pending and clear updated_column
                    $transaction->status               = 'Pending';
                    $transaction->date_processed       = null;
                    $transaction->approved_by          = null;
                    $transaction->account_balance_before = null;
                    $transaction->updated_column       = null;
                    $transaction->updated_by_user      = Auth::check() ? Auth::user()->email_address : 'unknown';
                    $transaction->save();

                    // The revert is fully applied — hand this account to the post-commit prepaid
                    // expiry check. Set unconditionally: the check self-gates on generation_type
                    // and on the restored expiry, so postpaid accounts and accounts still inside
                    // their period cost nothing more than a lookup.
                    $prepaidEnforcementAccountNo = $accountNo;

                    // Activity log
                    \App\Models\ActivityLog::log(
                        'Transaction Reverted via Revert Request',
                        "Transaction #{$transactionId} ({$accountNo}) reverted after revert request #{$revert->id} approved",
                        'warning',
                        [
                            'resource_type' => 'Transaction',
                            'resource_id'   => $transactionId,
                            'additional_data' => [
                                'revert_request_id' => $revert->id,
                                'account_no'        => $accountNo,
                                'used_snapshot'     => !empty($snapshot),
                            ]
                        ]
                    );

                    event(new TransactionUpdated(['action' => 'reverted', 'transaction_id' => $transactionId, 'account_no' => $accountNo]));
                }
            }

            DB::commit();

            // Prepaid customers whose restored expiry has already lapsed are restricted here, once
            // the revert is durable. Never throws — see the method's contract.
            $prepaidEnforcement = null;
            if ($prepaidEnforcementAccountNo !== null) {
                $prepaidEnforcement = $this->restrictExpiredPrepaidAfterRevert(
                    $prepaidEnforcementAccountNo,
                    $restoredSessionGroup,
                    Auth::check() ? Auth::user()->email_address : 'System',
                    Auth::id()
                );
            }

            $revert->load(['transaction.account.customer', 'transaction.processor', 'transaction.paymentMethodInfo', 'requester', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Revert request status updated successfully',
                'data' => $revert,
                'prepaid_enforcement' => $prepaidEnforcement
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating revert request status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Put an account's prepaid fields back to the values captured before approval.
     *
     * Undoes what attemptReconnectionAfterApproval() did once the approval had already
     * committed: PrepaidRenewalService pushed prepaid_expires_at forward, and
     * PrepaidPlanChangeService either switched plan_id outright or queued the switch via
     * pending_plan_id / pending_plan_effective_at. None of that used to be captured, so a
     * reverted prepaid customer silently kept the service days and the plan their
     * now-cancelled payment had bought.
     *
     * Called inside the caller's DB transaction, so a failure here rolls back the balance
     * and invoice restores too rather than leaving the account half-reverted.
     *
     * IDEMPOTENT by construction: every field is assigned an absolute value from the
     * snapshot, never adjusted relatively, and fields already matching are skipped. Running
     * it twice is therefore a no-op — which matters because the caller also clears
     * `updated_column`, so a repeat revert would otherwise find nothing to work from.
     *
     * @param  array<string,mixed>  $prepaidSnapshot  the `billing_accounts_prepaid` entry
     * @return array<string, array{from: ?string, to: ?string}>  fields actually changed
     */
    private function restorePrepaidSnapshot(array $prepaidSnapshot, string $accountNo, $userId): array
    {
        $account = BillingAccount::where('account_no', $accountNo)->first();
        if (!$account) {
            return [];
        }

        // Restore when the account was prepaid at approval time OR is prepaid now — either
        // way these fields could have been touched. Postpaid accounts that were never
        // prepaid are left completely alone, preserving existing behaviour.
        $isPrepaidNow = BillingAccount::isPrepaidType($account->generation_type);
        if (empty($prepaidSnapshot['was_prepaid']) && !$isPrepaidNow) {
            return [];
        }

        $columns = [
            'prepaid_expires_at'        => 'old_prepaid_expires_at',
            'plan_id'                   => 'old_plan_id',
            'pending_plan_id'           => 'old_pending_plan_id',
            'pending_plan_effective_at' => 'old_pending_plan_effective_at',
        ];

        $asText = static function ($value): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            return (string) $value;
        };

        $restored = [];

        foreach ($columns as $column => $snapshotKey) {
            // Only touch keys the snapshot actually carries. A transaction approved before
            // this capture existed must not have its columns blanked out by a revert.
            if (!array_key_exists($snapshotKey, $prepaidSnapshot)) {
                continue;
            }

            $currentText = $asText($account->{$column});
            $oldText     = $asText($prepaidSnapshot[$snapshotKey]);

            // Compared as text so a Carbon instance and its stored representation are not
            // mistaken for a difference, which would rewrite the row on every revert.
            if ($currentText === $oldText) {
                continue;
            }

            $account->{$column} = $prepaidSnapshot[$snapshotKey];
            $restored[$column]  = ['from' => $currentText, 'to' => $oldText];
        }

        if ($restored !== []) {
            $account->updated_by = $userId;
            $account->save();
        }

        return $restored;
    }

    /**
     * Restrict a prepaid customer whose revert restored an already-lapsed expiry.
     *
     * Closes the one gap {@see restorePrepaidSnapshot()} cannot cover on its own. When a prepaid
     * period had already lapsed but the 02:00 `cron:auto-disconnect-pullout` run had not yet
     * reached the customer, the pre-approval `session_group` snapshot holds their PLAN name rather
     * than 'restricted'. The session_group restore therefore issues no RADIUS call, and the
     * customer keeps service on a period their now-reverted payment no longer pays for until the
     * next nightly run — up to ~24 hours of free service bought by a cancelled payment.
     *
     * Applies exactly the rule {@see \App\Services\AutoDisconnectService::processPrepaidRestrictions()}
     * applies: the same grace day (both read
     * {@see \App\Services\AutoDisconnectService::PREPAID_GRACE_DAYS}), the same restrictedUser()
     * workflow, the same flip to Inactive, the same queue-on-failure. A customer therefore reaches
     * the same end state whichever path reaches them first. The flip to Inactive doubles as the
     * dedupe signal — the cron only selects Active accounts, so it will not restrict this customer
     * again tonight.
     *
     * Called AFTER the caller's DB::commit(), because a RADIUS restriction is an external side
     * effect that cannot be rolled back.
     *
     * Self-gating, so the caller can invoke it after every revert: a postpaid account, an expiry
     * still in the future, or a customer the session_group restore already pushed offline each
     * return 'skipped' without touching anything. NEVER THROWS — the revert has already committed
     * and must still be reported as successful, so every failure is logged and returned instead.
     *
     * @param  string|null  $restoredSessionGroup  normalised pre-approval session_group, if known
     * @param  string       $updatedBy             actor for RADIUS + audit logs
     * @param  int|null     $userId                actor for billing_accounts.updated_by
     * @return array{action: string, reason?: string, expires_at?: string, username?: string}
     */
    private function restrictExpiredPrepaidAfterRevert(
        string $accountNo,
        ?string $restoredSessionGroup,
        string $updatedBy,
        $userId
    ): array {
        try {
            // Already handled: the session_group restore put this customer back on the Restricted
            // or Disconnected profile, which is where the lapsed expiry would have put them anyway.
            if (in_array($restoredSessionGroup, ['restricted', 'disconnected'], true)) {
                return ['action' => 'skipped', 'reason' => 'session_group restore already took the customer offline'];
            }

            $account = BillingAccount::with('technicalDetails')->where('account_no', $accountNo)->first();

            if (!$account) {
                return ['action' => 'skipped', 'reason' => 'billing account not found'];
            }

            if (!BillingAccount::isPrepaidType($account->generation_type)) {
                return ['action' => 'skipped', 'reason' => 'not a prepaid account'];
            }

            if (empty($account->prepaid_expires_at)) {
                return ['action' => 'skipped', 'reason' => 'no prepaid_expires_at to judge'];
            }

            /*
             * Identical cutoff to processPrepaidRestrictions(): the expiry DATE is served in full
             * and restriction falls due at the start of the following day, so an account expiring
             * 07/30 is restricted on 07/31. Normalising to the calendar date is what stops the
             * expiry's inherited time-of-day from cutting some customers off mid-afternoon.
             */
            $restrictFrom = \Carbon\Carbon::now()
                ->startOfDay()
                ->subDays(\App\Services\AutoDisconnectService::PREPAID_GRACE_DAYS - 1);
            $expiry = \Carbon\Carbon::parse($account->prepaid_expires_at);

            if ($expiry->greaterThanOrEqualTo($restrictFrom)) {
                return [
                    'action'     => 'skipped',
                    'reason'     => 'restored prepaid period has not lapsed yet',
                    'expires_at' => $expiry->toDateTimeString(),
                ];
            }

            // Mirror the cron's dedupe: never issue a direct restrict while an earlier one is still
            // queued for retry, or the customer is restricted twice over.
            $alreadyQueued = DB::table('radius_operation_queue')
                ->where('account_no', $accountNo)
                ->where('operation', 'restricted_user')
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($alreadyQueued) {
                return ['action' => 'skipped', 'reason' => 'a restrict operation is already queued for retry'];
            }

            $username = optional($account->technicalDetails->first())->username;

            if (empty($username)) {
                \Log::warning('Revert: prepaid period lapsed but no PPPoE username to restrict', [
                    'account_no' => $accountNo,
                    'expires_at' => $expiry->toDateTimeString(),
                ]);

                return ['action' => 'skipped', 'reason' => 'no PPPoE username in technical_details'];
            }

            $params = [
                'accountNumber' => $accountNo,
                'username'      => $username,
                'remarks'       => self::PREPAID_REVERT_RESTRICTION_REMARKS,
                'updatedBy'     => $updatedBy,
            ];

            $result = app(\App\Services\ManualRadiusOperationsService::class)->restrictedUser($params);

            if (($result['status'] ?? '') !== 'success') {
                $reason = $result['message'] ?? 'Unknown RADIUS error';

                // Queue rather than fail: the revert itself is already committed and correct, and
                // cron:process-radius-queue will land the restriction within minutes.
                \App\Services\RadiusQueueService::queue([
                    'organization_id' => $account->organization_id ?? null,
                    'source_type'     => 'transaction_revert',
                    'source_id'       => $account->id,
                    'account_no'      => $accountNo,
                    'operation'       => 'restricted_user',
                    'params'          => $params,
                    'last_error'      => 'RADIUS restrict failed during transaction revert: ' . $reason,
                    'created_by'      => $updatedBy,
                ]);

                \Log::warning('Revert: prepaid restrict failed, queued for retry', [
                    'account_no' => $accountNo,
                    'username'   => $username,
                    'expires_at' => $expiry->toDateTimeString(),
                    'reason'     => $reason,
                ]);

                return [
                    'action'     => 'queued',
                    'reason'     => $reason,
                    'expires_at' => $expiry->toDateTimeString(),
                    'username'   => $username,
                ];
            }

            /*
             * Billing status flips only once RADIUS has accepted the restriction — same ordering as
             * the cron, so an Inactive row always means a restriction that really landed.
             *
             * This deliberately overrides the billing_status_id the snapshot restore just put back.
             * That is the point: the pre-approval status was Active only because the nightly run
             * had not reached this customer yet, and the restored expiry says the restriction is
             * now due. Restoring Active and leaving it there would re-open the gap this closes.
             */
            $inactiveStatusId = DB::table('billing_status')->where('status_name', 'Inactive')->value('id') ?? 4;

            $account->billing_status_id = $inactiveStatusId;
            $account->updated_by        = $userId;
            $account->save();

            \Log::info('Revert: prepaid period already lapsed — customer restricted', [
                'account_no'        => $accountNo,
                'username'          => $username,
                'restored_expiry'   => $expiry->toDateTimeString(),
                'billing_status_id' => $inactiveStatusId,
            ]);

            \App\Models\ActivityLog::log(
                'Prepaid Restricted After Transaction Revert',
                "Account {$accountNo} restricted — reverted payment left the prepaid period expired on {$expiry->toDateString()}",
                'warning',
                [
                    'resource_type'   => 'BillingAccount',
                    'resource_id'     => $account->id,
                    'additional_data' => [
                        'account_no'        => $accountNo,
                        'username'          => $username,
                        'restored_expiry'   => $expiry->toDateTimeString(),
                        'billing_status_id' => $inactiveStatusId,
                    ],
                ]
            );

            return [
                'action'     => 'restricted',
                'expires_at' => $expiry->toDateTimeString(),
                'username'   => $username,
            ];
        } catch (\Throwable $e) {
            // The revert is committed and correct; only the enforcement failed. Report it and let
            // the nightly processPrepaidRestrictions() run pick this customer up instead.
            \Log::error('Revert: prepaid expiry enforcement failed', [
                'account_no' => $accountNo,
                'error'      => $e->getMessage(),
            ]);

            return ['action' => 'error', 'reason' => $e->getMessage()];
        }
    }
}

