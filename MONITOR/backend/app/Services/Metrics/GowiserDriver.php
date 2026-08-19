<?php

namespace App\Services\Metrics;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The GOWISER schema: customers, billing_accounts, transactions, online_status,
 * service_orders, job_orders, applications.
 *
 * Note what is missing — GOWISER records no expenses, so it cannot report net
 * profit or margin. That is why 'financials' is absent from capabilities()
 * rather than returning zeros and implying the business broke even.
 */
class GowiserDriver implements MetricsDriver
{
    public function capabilities(): array
    {
        return ['overview', 'operations', 'revenue'];
    }

    public function branches(ConnectionInterface $db): array
    {
        return [];
    }

    public function financials(ConnectionInterface $db, string $period, $branch = null, ?string $asOf = null): array
    {
        throw new RuntimeException('GOWISER does not track expenses, so it cannot produce a profit and loss view.');
    }

    public function overview(ConnectionInterface $db): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $today = $now->toDateString();

        $sessions = $this->sessionBreakdown($db);

        $totalAccounts = $db->table('billing_accounts')->count();

        $receivables = (float) $db->table('billing_accounts')
            ->where('account_balance', '>', 0)
            ->sum('account_balance');

        $accountsInArrears = $db->table('billing_accounts')
            ->where('account_balance', '>', 0)
            ->count();

        $revenueMtd = (float) $this->collectedPayments($db)
            ->where('payment_date', '>=', $monthStart)
            ->sum('received_payment');

        $revenueToday = (float) $this->collectedPayments($db)
            ->whereDate('payment_date', $today)
            ->sum('received_payment');

        $applicationsMtd = $db->table('applications')
            ->where('timestamp', '>=', $monthStart)
            ->count();

        $installsMtd = $db->table('billing_accounts')
            ->where('date_installed', '>=', $monthStart->toDateString())
            ->count();

        return [
            'total_accounts' => $totalAccounts,
            'sessions' => $sessions,
            'receivables' => round($receivables, 2),
            'accounts_in_arrears' => $accountsInArrears,
            'revenue_mtd' => round($revenueMtd, 2),
            'revenue_today' => round($revenueToday, 2),
            'applications_mtd' => $applicationsMtd,
            'installs_mtd' => $installsMtd,
            'period' => [
                'month_start' => $monthStart->toDateString(),
                'as_of' => $now->toIso8601String(),
            ],
        ];
    }

    public function operations(ConnectionInterface $db): array
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $serviceOrderDate = DB::raw('COALESCE(service_orders.updated_at, service_orders.created_at)');

        $serviceOrders = fn () => $db->table('service_orders');
        $jobOrders = fn () => $db->table('job_orders');
        $applications = fn () => $db->table('applications');

        return [
            'support_status_today' => [
                'in_progress' => $serviceOrders()->where('support_status', 'In Progress')->whereDate('timestamp', $today)->count(),
                'for_visit' => $serviceOrders()->where('support_status', 'For Visit')->whereDate('timestamp', $today)->count(),
                'resolved' => $serviceOrders()->where('support_status', 'Resolved')->whereDate('timestamp', $today)->count(),
                'failed' => $serviceOrders()->where('support_status', 'Failed')->whereDate('timestamp', $today)->count(),
            ],
            'visit_status_today' => [
                'in_progress' => $serviceOrders()->where('visit_status', 'In Progress')->whereDate('timestamp', $today)->count(),
                'done' => $serviceOrders()->where('visit_status', 'Done')->whereDate('timestamp', $today)->count(),
                'rescheduled' => $serviceOrders()->where('visit_status', 'Reschedule')->whereDate('timestamp', $today)->count(),
                'failed' => $serviceOrders()->where('visit_status', 'Failed')->whereDate('timestamp', $today)->count(),
            ],
            'job_order_status_today' => [
                'pending' => $jobOrders()->where('onsite_status', 'Pending')->whereDate('timestamp', $today)->count(),
                'in_progress' => $jobOrders()->where('onsite_status', 'In Progress')->whereDate('timestamp', $today)->count(),
                'done' => $jobOrders()->where('onsite_status', 'Done')->whereDate('timestamp', $today)->count(),
                'failed' => $jobOrders()->where('onsite_status', 'Failed')->whereDate('timestamp', $today)->count(),
            ],
            'application_status_today' => [
                'scheduled' => $applications()->where('status', 'Scheduled')->whereDate('timestamp', $today)->count(),
                'in_progress' => $applications()->where('status', 'In Progress')->whereDate('timestamp', $today)->count(),
                'no_facility' => $applications()->where('status', 'No Facility')->whereDate('timestamp', $today)->count(),
                'cancelled' => $applications()->where('status', 'Cancelled')->whereDate('timestamp', $today)->count(),
                'no_slot' => $applications()->where('status', 'No Slot')->whereDate('timestamp', $today)->count(),
                'duplicate' => $applications()->where('status', 'Duplicate')->whereDate('timestamp', $today)->count(),
            ],
            'backlog' => [
                'applications_pending' => $applications()->where('status', 'Pending')->count(),
                'job_orders_in_progress' => $jobOrders()->where('onsite_status', 'In Progress')->count(),
                'service_orders_open' => $serviceOrders()->whereIn('support_status', ['In Progress', 'For Visit'])->count(),
            ],
            'monthly_support_concerns' => $serviceOrders()
                ->whereYear($serviceOrderDate, $now->year)
                ->whereMonth($serviceOrderDate, $now->month)
                ->whereNotNull('concern')
                ->where('concern', '<>', '')
                ->select('concern as label', DB::raw('count(*) as count'))
                ->groupBy('concern')
                ->orderByRaw('count(*) desc')
                ->limit(10)
                ->get()
                ->toArray(),
            'monthly_repair_categories' => $serviceOrders()
                ->whereYear($serviceOrderDate, $now->year)
                ->whereMonth($serviceOrderDate, $now->month)
                ->whereNotNull('repair_category')
                ->where('repair_category', '<>', '')
                ->select('repair_category as label', DB::raw('count(*) as count'))
                ->groupBy('repair_category')
                ->orderByRaw('count(*) desc')
                ->limit(10)
                ->get()
                ->toArray(),
        ];
    }

    public function revenue(ConnectionInterface $db, int $months): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $monthly = $this->collectedPayments($db)
            ->where('payment_date', '>=', $start)
            ->select(
                DB::raw("DATE_FORMAT(payment_date, '%Y-%m') as period"),
                DB::raw('SUM(received_payment) as total'),
                DB::raw('COUNT(*) as transactions')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        // Fill the gaps so a month with no collections charts as zero rather
        // than vanishing and skewing the shape of the line.
        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $period = $start->copy()->addMonths($i)->format('Y-m');
            $row = $monthly->get($period);

            $series[] = [
                'period' => $period,
                'total' => round((float) ($row->total ?? 0), 2),
                'transactions' => (int) ($row->transactions ?? 0),
            ];
        }

        $byMethod = $this->collectedPayments($db)
            ->where('payment_date', '>=', Carbon::now()->startOfMonth())
            ->whereNotNull('payment_method')
            ->where('payment_method', '<>', '')
            ->select(
                'payment_method as label',
                DB::raw('SUM(received_payment) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('payment_method')
            ->orderByRaw('SUM(received_payment) desc')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->count,
            ])
            ->toArray();

        $byType = $this->collectedPayments($db)
            ->where('payment_date', '>=', Carbon::now()->startOfMonth())
            ->whereNotNull('transaction_type')
            ->select('transaction_type as label', DB::raw('SUM(received_payment) as total'))
            ->groupBy('transaction_type')
            ->orderByRaw('SUM(received_payment) desc')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'total' => round((float) $row->total, 2),
            ])
            ->toArray();

        return [
            'monthly' => $series,
            'mtd_by_method' => $byMethod,
            'mtd_by_type' => $byType,
        ];
    }

    private function sessionBreakdown(ConnectionInterface $db): array
    {
        $rows = $db->table('online_status')
            ->select('session_status', DB::raw('count(*) as total'))
            ->groupBy('session_status')
            ->get();

        $breakdown = [
            'online' => 0,
            'offline' => 0,
            'disconnected' => 0,
            'restricted' => 0,
        ];

        foreach ($rows as $row) {
            $key = strtolower((string) $row->session_status);

            if (array_key_exists($key, $breakdown)) {
                $breakdown[$key] = (int) $row->total;
            }
        }

        return $breakdown;
    }

    /**
     * Money actually collected: cancelled, failed, voided, and still-pending rows are not
     * revenue, and counting them is the classic way an executive dashboard
     * ends up disagreeing with finance.
     */
    private function collectedPayments(ConnectionInterface $db)
    {
        return $db->table('transactions')
            ->whereNotNull('payment_date')
            ->whereNotNull('received_payment')
            ->whereIn(
                DB::raw("LOWER(TRIM(COALESCE(status, '')))"),
                ['paid', 'done', 'completed', 'complete', 'success', 'successful', 'approved', 'settled']
            );
    }
}
