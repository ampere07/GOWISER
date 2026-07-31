<?php

namespace App\Services\Metrics;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The NETMANAGER schema: subscribers, payments, expenses, routers, plans.
 *
 * Ported from that system's own dashboard (api/dashboard_stats.php and
 * modules/reports/financial.php) so the figures here agree with the figures
 * the branch staff already see. Where a rule looked arbitrary it was kept
 * anyway — matching their numbers matters more than tidiness.
 *
 * Unlike GOWISER this schema records expenses, so it can show real net and
 * margin rather than collections alone.
 */
class NetmanagerDriver implements MetricsDriver
{
    /** Report period -> expense period_type values that belong in it. */
    private const EXPENSE_PERIOD_TYPES = [
        'daily' => ['daily'],
        'weekly' => ['daily'],
        'monthly' => ['daily', 'monthly'],
        'yearly' => ['daily', 'monthly', 'yearly'],
    ];

    public function capabilities(): array
    {
        // Deliberately financials-only. NETMANAGER has no equivalent of
        // GOWISER's service orders or RADIUS session table, and inventing
        // lookalike numbers for those pages would be worse than hiding them.
        return ['financials'];
    }

    public function overview(ConnectionInterface $db): array
    {
        throw new RuntimeException('NetManager exposes financial analytics only.');
    }

    public function operations(ConnectionInterface $db): array
    {
        throw new RuntimeException('NetManager exposes financial analytics only.');
    }

    public function revenue(ConnectionInterface $db, int $months): array
    {
        throw new RuntimeException('Use the financials view for NetManager.');
    }

    /**
     * Branches are MikroTik routers — one per site, e.g. "GO WISER ZAMBOANGA".
     */
    public function branches(ConnectionInterface $db): array
    {
        return $db->table('routers')
            ->select('router_id as id', 'name as label', 'municipality', 'province')
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'label' => $row->label,
                'location' => trim(implode(', ', array_filter([$row->municipality, $row->province]))) ?: null,
            ])
            ->toArray();
    }

    public function financials(ConnectionInterface $db, string $period, $branch = null, ?string $asOf = null): array
    {
        $period = isset(self::EXPENSE_PERIOD_TYPES[$period]) ? $period : 'monthly';
        $branch = ($branch === null || $branch === '' || $branch === 'all') ? null : (int) $branch;

        $anchor = $this->anchor($asOf);
        $range = $this->periodRange($period, $anchor);

        $income = $this->incomeKpi($db, $range, $branch);
        $expenses = $this->expenseKpi($db, $period, $range, $branch);

        $net = $income['income'] - $expenses['expenses'];
        $margin = $income['income'] > 0 ? round($net / $income['income'] * 100, 1) : null;

        return [
            'period' => $period,
            'period_label' => $range['label'],
            'range' => ['from' => $range['from'], 'to' => $range['to']],
            'branch' => $branch !== null ? (string) $branch : null,
            'branch_label' => $this->branchLabel($db, $branch),

            'kpi' => [
                'income' => round($income['income'], 2),
                'income_count' => $income['count'],
                'office_income' => round($income['office_income'], 2),
                'office_count' => $income['office_count'],
                'portal_income' => round($income['portal_income'], 2),
                'portal_count' => $income['portal_count'],
                'expenses' => round($expenses['expenses'], 2),
                'expenses_count' => $expenses['count'],
                'net' => round($net, 2),
                'margin_pct' => $margin,
            ],

            'as_of' => $anchor->toDateString(),
            'series' => $this->series($db, $period, $branch, $anchor),
            'by_method' => $this->byMethod($db, $range, $branch),
            'by_payment_type' => $this->byPaymentType($db, $range, $branch),
            'by_expense_type' => $this->byExpenseType($db, $period, $range, $branch),
            'by_branch' => $this->byBranch($db, $period, $range),
            'plans' => $this->plansDistribution($db, $branch),
            'subscribers' => $this->subscriberCounts($db, $branch),
        ];
    }

    // ── Period handling ──────────────────────────────────────────────────

    /**
     * The date every period is measured from. Defaults to today; an explicit
     * date lets management look back at a closed month without waiting for a
     * report to be run for them.
     */
    private function anchor(?string $asOf): Carbon
    {
        if ($asOf === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            return Carbon::now();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $asOf)->startOfDay();
        } catch (\Throwable $e) {
            return Carbon::now();
        }
    }

    /**
     * Matches the source dashboard: weekly means "the last 7 days", not
     * "the calendar week".
     */
    private function periodRange(string $period, Carbon $now): array
    {
        switch ($period) {
            case 'daily':
                return [
                    'from' => $now->toDateString(),
                    'to' => $now->toDateString(),
                    'label' => $now->format('M d, Y'),
                ];
            case 'weekly':
                return [
                    'from' => $now->copy()->subDays(7)->toDateString(),
                    'to' => $now->toDateString(),
                    'label' => 'Last 7 days',
                ];
            case 'yearly':
                return [
                    'from' => $now->copy()->startOfYear()->toDateString(),
                    'to' => $now->copy()->endOfYear()->toDateString(),
                    'label' => $now->format('Y'),
                ];
            default:
                return [
                    'from' => $now->copy()->startOfMonth()->toDateString(),
                    'to' => $now->copy()->endOfMonth()->toDateString(),
                    'label' => $now->format('F Y'),
                ];
        }
    }

    // ── Income ───────────────────────────────────────────────────────────

    /**
     * Only rows marked paid count as income.
     *
     * Branch scoping goes through subscribers because a payment records who
     * paid, not where they paid.
     */
    private function paidPayments(ConnectionInterface $db, array $range, ?int $branch): Builder
    {
        $query = $db->table('payments as py')
            ->where('py.status', 'paid')
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$range['from'], $range['to']]);

        if ($branch !== null) {
            $query->join('subscribers as s', 's.subscriber_id', '=', 'py.subscriber_id')
                ->where('s.router_id', $branch);
        }

        return $query;
    }

    /**
     * Office vs portal is decided by the payment method's remark containing
     * "PORTAL" — the source system's own convention, joined case-insensitively
     * because method codes are stored inconsistently between the two tables.
     */
    private function incomeKpi(ConnectionInterface $db, array $range, ?int $branch): array
    {
        $portalCase = "UPPER(COALESCE(pm.remark, '')) LIKE '%PORTAL%'";

        // payments.method and payment_methods.code are stored under different
        // collations, so both sides need an explicit COLLATE or MySQL refuses
        // the comparison outright. NETMANAGER's own query does the same.
        $row = $this->paidPayments($db, $range, $branch)
            ->leftJoin(
                'payment_methods as pm',
                DB::raw('UPPER(pm.code COLLATE utf8mb4_unicode_ci)'),
                '=',
                DB::raw('UPPER(py.method COLLATE utf8mb4_unicode_ci)')
            )
            ->selectRaw('COALESCE(SUM(py.amount),0) AS income')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$portalCase} THEN py.amount ELSE 0 END),0) AS portal_income")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$portalCase} THEN 1 ELSE 0 END),0) AS portal_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$portalCase} THEN 0 ELSE py.amount END),0) AS office_income")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$portalCase} THEN 0 ELSE 1 END),0) AS office_count")
            ->first();

        return [
            'income' => (float) ($row->income ?? 0),
            'count' => (int) ($row->cnt ?? 0),
            'portal_income' => (float) ($row->portal_income ?? 0),
            'portal_count' => (int) ($row->portal_count ?? 0),
            'office_income' => (float) ($row->office_income ?? 0),
            'office_count' => (int) ($row->office_count ?? 0),
        ];
    }

    private function byMethod(ConnectionInterface $db, array $range, ?int $branch): array
    {
        return $this->paidPayments($db, $range, $branch)
            ->selectRaw("COALESCE(NULLIF(py.method, ''), 'Unspecified') AS label")
            ->selectRaw('SUM(py.amount) AS total')
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('SUM(py.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->cnt,
            ])
            ->toArray();
    }

    private function byPaymentType(ConnectionInterface $db, array $range, ?int $branch): array
    {
        return $this->paidPayments($db, $range, $branch)
            ->leftJoin('payment_types as pt', 'pt.type_id', '=', 'py.payment_type_id')
            ->selectRaw("COALESCE(NULLIF(pt.name, ''), 'Subscription') AS label")
            ->selectRaw('SUM(py.amount) AS total')
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('SUM(py.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->cnt,
            ])
            ->toArray();
    }

    // ── Expenses ─────────────────────────────────────────────────────────

    /**
     * Expenses carry a period_type saying which reporting horizon they belong
     * to. A rent entry tagged 'monthly' must not land in a daily view, or the
     * day shows a loss that never happened. This is the source system's rule
     * and the single most important thing to get right here.
     */
    private function expenseRows(ConnectionInterface $db, string $period, array $range, ?int $branch): Builder
    {
        $query = $db->table('expenses as e')
            ->whereBetween(DB::raw('DATE(e.expense_date)'), [$range['from'], $range['to']])
            ->whereIn(DB::raw("COALESCE(e.period_type, 'daily')"), self::EXPENSE_PERIOD_TYPES[$period]);

        if ($branch !== null) {
            $query->where('e.router_id', $branch);
        }

        return $query;
    }

    private function expenseKpi(ConnectionInterface $db, string $period, array $range, ?int $branch): array
    {
        $row = $this->expenseRows($db, $period, $range, $branch)
            ->selectRaw('COALESCE(SUM(e.amount),0) AS total')
            ->selectRaw('COUNT(*) AS cnt')
            ->first();

        return [
            'expenses' => (float) ($row->total ?? 0),
            'count' => (int) ($row->cnt ?? 0),
        ];
    }

    private function byExpenseType(ConnectionInterface $db, string $period, array $range, ?int $branch): array
    {
        return $this->expenseRows($db, $period, $range, $branch)
            ->leftJoin('expense_types as et', 'et.type_id', '=', 'e.expense_type_id')
            ->selectRaw("COALESCE(NULLIF(et.name, ''), '(Uncategorized)') AS label")
            ->selectRaw('SUM(e.amount) AS total')
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('SUM(e.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->cnt,
            ])
            ->toArray();
    }

    // ── Trend series ─────────────────────────────────────────────────────

    /**
     * Income, expenses and net on one timeline. Buckets follow the source
     * dashboard: 30 days / 12 weeks / 12 months / 10 years.
     */
    private function series(ConnectionInterface $db, string $period, ?int $branch, Carbon $anchor): array
    {
        [$incomeRows, $expenseRows] = $this->seriesRows($db, $period, $branch, $anchor);

        $income = collect($incomeRows)->keyBy('bucket');
        $expenses = collect($expenseRows)->keyBy('bucket');

        // Union of both sides: a month with expenses but no collections still
        // has to appear, otherwise a loss-making period silently disappears.
        $buckets = $income->keys()->merge($expenses->keys())->unique()->sort()->values();

        return $buckets->map(function ($bucket) use ($income, $expenses) {
            $inc = round((float) ($income->get($bucket)->total ?? 0), 2);
            $exp = round((float) ($expenses->get($bucket)->total ?? 0), 2);

            return [
                'period' => (string) $bucket,
                'label' => (string) ($income->get($bucket)->label ?? $expenses->get($bucket)->label ?? $bucket),
                'income' => $inc,
                'expenses' => $exp,
                'net' => round($inc - $exp, 2),
            ];
        })->toArray();
    }

    private function seriesRows(ConnectionInterface $db, string $period, ?int $branch, Carbon $anchor): array
    {
        $now = $anchor->copy();
        $to = $now->copy()->endOfYear()->toDateString();

        switch ($period) {
            case 'daily':
                $from = $now->copy()->subDays(30)->toDateString();
                break;
            case 'weekly':
                $from = $now->copy()->subDays(84)->toDateString();
                break;
            case 'yearly':
                $from = $now->copy()->subYears(9)->startOfYear()->toDateString();
                break;
            default:
                $from = $now->copy()->startOfMonth()->subMonths(11)->toDateString();
        }

        // Given a date column, returns [sortable bucket key, display label].
        $expressions = function (string $column) use ($period): array {
            switch ($period) {
                case 'daily':
                    return ["DATE_FORMAT({$column}, '%Y-%m-%d')", "DATE_FORMAT({$column}, '%b %d')"];
                case 'weekly':
                    return [
                        "CONCAT(YEAR({$column}), '-W', LPAD(WEEK({$column}, 3), 2, '0'))",
                        "CONCAT('Wk', LPAD(WEEK({$column}, 3), 2, '0'))",
                    ];
                case 'yearly':
                    return ["DATE_FORMAT({$column}, '%Y')", "DATE_FORMAT({$column}, '%Y')"];
                default:
                    return ["DATE_FORMAT({$column}, '%Y-%m')", "DATE_FORMAT({$column}, '%b %Y')"];
            }
        };

        [$incomeBucket, $incomeLabel] = $expressions('py.payment_date');
        [$expenseBucket, $expenseLabel] = $expressions('e.expense_date');

        $incomeQuery = $db->table('payments as py')
            ->where('py.status', 'paid')
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to]);

        if ($branch !== null) {
            $incomeQuery->join('subscribers as s', 's.subscriber_id', '=', 'py.subscriber_id')
                ->where('s.router_id', $branch);
        }

        $incomeRows = $incomeQuery
            ->selectRaw($incomeBucket . ' AS bucket')
            ->selectRaw($incomeLabel . ' AS label')
            ->selectRaw('SUM(py.amount) AS total')
            ->groupBy('bucket', 'label')
            ->orderBy('bucket')
            ->get();

        $expenseQuery = $db->table('expenses as e')
            ->whereBetween(DB::raw('DATE(e.expense_date)'), [$from, $to])
            ->whereIn(DB::raw("COALESCE(e.period_type, 'daily')"), self::EXPENSE_PERIOD_TYPES[$period]);

        if ($branch !== null) {
            $expenseQuery->where('e.router_id', $branch);
        }

        $expenseRows = $expenseQuery
            ->selectRaw($expenseBucket . ' AS bucket')
            ->selectRaw($expenseLabel . ' AS label')
            ->selectRaw('SUM(e.amount) AS total')
            ->groupBy('bucket', 'label')
            ->orderBy('bucket')
            ->get();

        return [$incomeRows, $expenseRows];
    }

    // ── Branch comparison ────────────────────────────────────────────────

    /**
     * Collections and expenses per branch for the selected period. Ignores the
     * branch filter on purpose — this panel is the comparison.
     */
    private function byBranch(ConnectionInterface $db, string $period, array $range): array
    {
        $collections = $db->table('routers as r')
            ->leftJoin('subscribers as s', 's.router_id', '=', 'r.router_id')
            ->leftJoin('payments as py', function ($join) use ($range) {
                $join->on('py.subscriber_id', '=', 's.subscriber_id')
                    ->where('py.status', '=', 'paid')
                    ->whereBetween(DB::raw('DATE(py.payment_date)'), [$range['from'], $range['to']]);
            })
            ->selectRaw('r.router_id AS id')
            ->selectRaw('r.name AS label')
            ->selectRaw('COALESCE(SUM(py.amount), 0) AS income')
            ->selectRaw('COUNT(DISTINCT s.subscriber_id) AS subscribers')
            ->groupBy('r.router_id', 'r.name')
            ->get()
            ->keyBy('id');

        $expenses = $db->table('expenses as e')
            ->whereBetween(DB::raw('DATE(e.expense_date)'), [$range['from'], $range['to']])
            ->whereIn(DB::raw("COALESCE(e.period_type, 'daily')"), self::EXPENSE_PERIOD_TYPES[$period])
            ->whereNotNull('e.router_id')
            ->selectRaw('e.router_id AS id')
            ->selectRaw('SUM(e.amount) AS total')
            ->groupBy('e.router_id')
            ->get()
            ->keyBy('id');

        return $collections->map(function ($row) use ($expenses) {
            $income = (float) $row->income;
            $expense = (float) ($expenses->get($row->id)->total ?? 0);

            return [
                'id' => (string) $row->id,
                'label' => $row->label,
                'income' => round($income, 2),
                'expenses' => round($expense, 2),
                'net' => round($income - $expense, 2),
                'subscribers' => (int) $row->subscribers,
            ];
        })
            ->sortByDesc('income')
            ->values()
            ->toArray();
    }

    // ── Subscriber context ───────────────────────────────────────────────

    private function plansDistribution(ConnectionInterface $db, ?int $branch): array
    {
        return $db->table('plans as p')
            ->leftJoin('subscribers as s', function ($join) use ($branch) {
                $join->on('s.plan_id', '=', 'p.plan_id')->where('s.status', '=', 'active');

                if ($branch !== null) {
                    $join->where('s.router_id', '=', $branch);
                }
            })
            ->selectRaw('p.title AS label')
            ->selectRaw('COUNT(s.subscriber_id) AS cnt')
            ->groupBy('p.plan_id', 'p.title')
            ->orderByRaw('COUNT(s.subscriber_id) DESC')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'count' => (int) $row->cnt])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values()
            ->toArray();
    }

    private function subscriberCounts(ConnectionInterface $db, ?int $branch): array
    {
        $query = $db->table('subscribers');

        if ($branch !== null) {
            $query->where('router_id', $branch);
        }

        $row = $query->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(status = 'active') AS active")
            ->selectRaw("SUM(status = 'expired') AS expired")
            ->selectRaw("SUM(status = 'suspended') AS suspended")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'suspended' => (int) ($row->suspended ?? 0),
        ];
    }

    private function branchLabel(ConnectionInterface $db, ?int $branch): string
    {
        if ($branch === null) {
            return 'All branches';
        }

        $row = $db->table('routers')->where('router_id', $branch)->first();

        return $row->name ?? "Branch {$branch}";
    }
}
