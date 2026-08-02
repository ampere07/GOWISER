<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ConsolidatedNotificationController extends Controller
{
    /** roles.id for SuperAdmin. Matches the $roleId == 7 checks elsewhere in the app. */
    private const SUPERADMIN_ROLE_ID = 7;

    public function index(Request $request)
    {
        try {
            $limit = $request->get('limit', 15);
            $latest = collect();

            // 1. Fetch Applications (Pending)
            $applications = DB::table('applications')
                ->where('status', 'Pending')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($app) {
                    $createdAt = Carbon::parse($app->created_at);
                    return [
                        'id' => $app->id,
                        'type' => 'application',
                        'customer_name' => trim(($app->first_name ?? '') . ' ' . ($app->last_name ?? '')),
                        'plan_name' => $app->desired_plan ?? 'Unknown Plan',
                        'title' => 'New Application',
                        'message' => 'New application received',
                        'timestamp' => $createdAt->timestamp,
                        'formatted_date' => $createdAt->format('Y-m-d h:i:s A'), // e.g. 2026-02-11 05:53:42 PM
                        'raw_date' => $createdAt->toIso8601String()
                    ];
                });

            // 2. Fetch Job Orders (Done)
            $jobCompeltions = DB::table('job_orders')
                ->join('applications', 'job_orders.application_id', '=', 'applications.id')
                ->where('job_orders.onsite_status', 'Done')
                ->orderBy('job_orders.updated_at', 'desc')
                ->limit($limit)
                ->select(
                    'job_orders.id',
                    'job_orders.updated_at',
                    'applications.first_name',
                    'applications.last_name',
                    'applications.desired_plan'
                )
                ->get()
                ->map(function ($job) {
                    $updatedAt = Carbon::parse($job->updated_at);
                    return [
                        'id' => $job->id,
                        'type' => 'job_order_done',
                        'customer_name' => trim(($job->first_name ?? '') . ' ' . ($job->last_name ?? '')),
                        'plan_name' => $job->desired_plan ?? 'Unknown Plan',
                        'title' => 'Job Order Completed',
                        'message' => 'Onsite status marked as Done',
                        'timestamp' => $updatedAt->timestamp,
                        'formatted_date' => $updatedAt->format('Y-m-d h:i:s A'), // e.g. 2026-02-11 05:53:42 PM
                        'raw_date' => $updatedAt->toIso8601String()
                    ];
                });

            // 3. Fetch Service Orders (Visit Done)
            //
            // visit_status is the service order's equivalent of a job order's
            // onsite_status: it records what happened on the visit. support_status is a
            // separate axis (For Visit / Resolved / Cancelled) and is deliberately not
            // used here — "the technician finished the visit" is the event being reported.
            //
            // Joined through the billing account because a service order stores only an
            // account_no, unlike a job order which carries the application.
            $serviceCompletions = DB::table('service_orders')
                ->leftJoin('billing_accounts', 'service_orders.account_no', '=', 'billing_accounts.account_no')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->where('service_orders.visit_status', 'Done')
                ->orderBy('service_orders.updated_at', 'desc')
                ->limit($limit)
                ->select(
                    'service_orders.id',
                    'service_orders.updated_at',
                    'service_orders.account_no',
                    'service_orders.concern',
                    'customers.first_name',
                    'customers.last_name'
                )
                ->get()
                ->map(function ($order) {
                    $updatedAt = Carbon::parse($order->updated_at);
                    $name = trim(($order->first_name ?? '') . ' ' . ($order->last_name ?? ''));

                    return [
                        'id' => $order->id,
                        'type' => 'service_order_done',
                        // Falls back to the account number so a service order whose
                        // customer record is missing is still identifiable.
                        'customer_name' => $name !== '' ? $name : ($order->account_no ?? 'Unknown account'),
                        // The concern rather than a plan: it is what the visit was about.
                        'plan_name' => $order->concern ?? 'Service visit',
                        'title' => 'Service Order Completed',
                        'message' => 'Visit status marked as Done',
                        'timestamp' => $updatedAt->timestamp,
                        'formatted_date' => $updatedAt->format('Y-m-d h:i:s A'),
                        'raw_date' => $updatedAt->toIso8601String(),
                    ];
                });

            // 4. Pending transaction reverts — SUPERADMIN ONLY.
            //
            // A revert undoes money that was already posted, so only the role that can
            // action one is told about it. Gated on role_id 7 explicitly and failing
            // closed when there is no authenticated user: this route is not behind
            // auth:sanctum, so an unauthenticated caller reaches it, and treating
            // "no user" as unrestricted (as some older controllers here do) would
            // publish reverts to anyone who asked.
            $authUser = auth()->user();
            $isSuperadmin = $authUser && (int) $authUser->role_id === self::SUPERADMIN_ROLE_ID;

            $reverts = $isSuperadmin
                ? DB::table('transaction_revert')
                    ->join('transactions', 'transaction_revert.transaction_id', '=', 'transactions.id')
                    ->leftJoin('billing_accounts', 'transactions.account_no', '=', 'billing_accounts.account_no')
                    ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                    // Only what still needs a decision. A revert already actioned is
                    // history, not a prompt.
                    ->where('transaction_revert.status', 'pending')
                    ->orderBy('transaction_revert.created_at', 'desc')
                    ->limit($limit)
                    ->select(
                        'transaction_revert.id',
                        'transaction_revert.created_at',
                        'transaction_revert.reason',
                        'transactions.received_payment',
                        'transactions.account_no',
                        'customers.first_name',
                        'customers.last_name'
                    )
                    ->get()
                    ->map(function ($revert) {
                        $createdAt = Carbon::parse($revert->created_at);
                        $name = trim(($revert->first_name ?? '') . ' ' . ($revert->last_name ?? ''));

                        return [
                            'id' => $revert->id,
                            'type' => 'transaction_revert',
                            // Falls back to the account number: a revert must still be
                            // identifiable when the customer record is missing.
                            'customer_name' => $name !== '' ? $name : ($revert->account_no ?? 'Unknown account'),
                            'plan_name' => '₱ ' . number_format((float) ($revert->received_payment ?? 0), 2),
                            'title' => 'Revert Requested',
                            'message' => $revert->reason ?? 'Transaction revert requested',
                            'timestamp' => $createdAt->timestamp,
                            'formatted_date' => $createdAt->format('Y-m-d h:i:s A'),
                            'raw_date' => $createdAt->toIso8601String(),
                        ];
                    })
                : collect();

            // Merge and Sort
            $all = $applications->concat($jobCompeltions)->concat($serviceCompletions)->concat($reverts)
                ->sortByDesc('timestamp')
                ->take($limit)
                ->values();

            return response()->json([
                'success' => true,
                'data' => $all
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch consolidated notifications', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}


