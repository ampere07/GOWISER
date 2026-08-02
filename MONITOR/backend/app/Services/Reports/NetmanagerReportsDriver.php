<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The NETMANAGER schema: subscribers, payments, expenses, plans, routers,
 * installations, users.
 *
 * Every aggregate here is a port of that system's own PHP, so the two agree
 * figure for figure. The sections regroup that logic rather than replacing it:
 *
 *   subscriberAnalytics()  api/dashboard_stats.php + the subscriber and overdue
 *                          halves of modules/reports/index.php
 *   financial()            the money halves of both, plus
 *                          modules/reports/financial.php for the four horizons
 *   operations()           modules/installations/index.php
 *   employee()             modules/users + the cashier and payee attributions
 *   printable()            the print branch of modules/reports/index.php
 *
 * There is no tech() — see capabilities().
 *
 * Conventions carried over deliberately:
 *
 *  - Income counts only payments with status 'paid'.
 *  - Branch scope is a router_id. A payment records who paid, not where, so
 *    scoping a payment query means joining through `subscribers`.
 *  - Expenses are filtered by period_type (see ReportPeriod::expenseTypes) so a
 *    day never carries a month's rent.
 *  - Date anchors are computed in PHP, never with CURDATE()/NOW(). MySQL and
 *    the app can sit in different timezones, and the source system hit exactly
 *    that bug.
 */
class NetmanagerReportsDriver implements ReportsDriver
{
    /**
     * Plan mix is capped at ten slices — a pie chart with forty is unreadable.
     *
     * The barangay table used to share this cap and no longer does: it is a
     * table, not a chart, and the coverage question it answers needs the tail.
     */
    private const TOP_N = 10;

    /** Overdue ledger page size, matching $odPerPage in the source. */
    private const OVERDUE_PER_PAGE = 25;

    /**
     * No 'tech'. NETMANAGER has no technician records — `installations.user_id`
     * points at a lineman account, which is a *user* and belongs on the Employee
     * section. Declaring 'tech' here and rendering an employee list under a
     * technician heading would be worse than hiding the section.
     */
    public function capabilities(): array
    {
        return ['subscriber_analytics', 'financial', 'operations', 'employee'];
    }

    public function tech(ConnectionInterface $db, array $params): array
    {
        throw new RuntimeException('NetManager keeps no technician records.');
    }

    public function branches(ConnectionInterface $db): array
    {
        return $db->table('routers')
            ->select('router_id', 'name', 'municipality', 'province', 'region')
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->router_id,
                'label' => (string) $row->name,
                'location' => $this->joinLocation([$row->municipality, $row->province, $row->region]),
            ])
            ->all();
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SUBSCRIBER ANALYTICS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Who the subscribers are, and which of them are a problem.
     *
     * Not bounded by a date range: this is a statement of the base as it stands
     * right now. The one exception is `growth`, which needs a window to mean
     * anything, so it takes the range and says so.
     */
    public function subscriberAnalytics(ConnectionInterface $db, array $params): array
    {
        $branch = $this->branchId($params['branch'] ?? null);
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        $status = $this->subscriberStatusCounts($db, $branch);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => $branch !== null ? (string) $branch : null,
            'branch_label' => $this->branchLabel($db, $branch),
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            'kpi' => $this->subscriberKpis($db, $branch, $anchor->toDateString()),

            // The four billing-status counters the summary header reports.
            'billing_summary' => StatusMap::billingSummary($status['raw']),

            'status' => $status,
            'plans' => $this->activePlanMix($db, $branch),

            // Every barangay, not a top ten. A league table answers "who is
            // biggest"; management asked to see coverage, which needs the tail.
            'barangays' => $this->barangayBreakdown($db, $branch, $params),

            'growth' => [
                'new_in_range' => $this->newSubscribers($db, $from, $to, $branch),
                'expected_mrc' => round($this->expectedMrc($db, $branch), 2),
            ],
            'overdue' => $this->overdueAccounts($db, $branch, $params),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  FINANCIAL
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Money in, money out, and everything behind both.
     *
     * Carries three independent period controls, matching the source system: the
     * date range for the headline figures, the trend granularity, and the
     * branch-collections window. An operator comparing this month's takings
     * against the twelve-month trend needs them independent; tying them together
     * removes the only comparison the page is really for.
     */
    public function financial(ConnectionInterface $db, array $params): array
    {
        $branch = $this->branchId($params['branch'] ?? null);
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        $trendPeriod = ReportPeriod::normalise($params['period'] ?? null, 'monthly');
        $branchPeriod = ReportPeriod::normalise($params['branch_period'] ?? null, 'monthly');
        $branchYear = (int) ($params['branch_year'] ?? $anchor->year);

        // An arbitrary range still has to decide which expense horizons belong
        // in it — a whole calendar month includes monthly bookings, three days
        // in the middle of it do not.
        $expensePeriod = ReportPeriod::fromDateRange($from, $to);

        $revenue = $this->revenueStats($db, $from, $to, $branch);
        $income = $this->incomeKpi($db, $from, $to, $branch);
        $expenses = $this->expenseTotals($db, $expensePeriod, $from, $to, $branch);
        $expectedMrc = $this->expectedMrc($db, $branch);
        $net = $revenue['total'] - $expenses['total'];

        // Computed once and reused: the channel split and the OpEx/CapEx split
        // are regroupings of these two breakdowns, not fresh queries. Querying
        // twice risks the two halves of one page disagreeing if a row lands
        // between them.
        $byMethod = $this->revenueByMethod($db, $from, $to, $branch);
        $byExpenseType = $this->expensesByType($db, $expensePeriod, $from, $to, $branch);

        $base = $this->subscriberBase($db, $branch);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => $branch !== null ? (string) $branch : null,
            'branch_label' => $this->branchLabel($db, $branch),
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),
            'expense_period' => $expensePeriod,
            'supports_expenses' => true,

            'kpi' => [
                'income' => round($revenue['total'], 2),
                'income_count' => $revenue['count'],
                'average_payment' => round($revenue['average'], 2),
                'largest_payment' => round($revenue['largest'], 2),
                'office_income' => round($income['office_income'], 2),
                'office_count' => $income['office_count'],
                'portal_income' => round($income['portal_income'], 2),
                'portal_count' => $income['portal_count'],
                'office_by_type' => $this->officeCollectionsByType($db, $from, $to, $branch),
                'expenses' => round($expenses['total'], 2),
                'expenses_count' => $expenses['count'],
                'net' => round($net, 2),
                'margin_pct' => $revenue['total'] > 0 ? round($net / $revenue['total'] * 100, 1) : null,
                'expected_mrc' => round($expectedMrc, 2),
                // Capped at 999%: a month carrying back-payments can exceed
                // 100%, and an uncapped figure in the thousands reads as a bug.
                'collection_rate' => $expectedMrc > 0
                    ? min(999.0, round($revenue['total'] / $expectedMrc * 100, 1))
                    : 0.0,
            ],

            // Day-by-day across the selected range.
            'series' => $this->dailySeries($db, $from, $to, $expensePeriod, $branch),

            // Longer-horizon trend, independent of the range above.
            'trend' => [
                'period' => $trendPeriod,
                'points' => $this->trendSeries($db, $trendPeriod, $branch, $anchor),
            ],

            // Cash / PNB / Xendit, regrouped from by_method. See IncomeChannels
            // for why the mapping is config rather than SQL.
            'income_channels' => IncomeChannels::summarise($byMethod),

            // Prospective revenue, ARPU, collection efficiency, churn loss.
            'executive_metrics' => ExecutiveMetrics::build(
                $expectedMrc,
                $revenue['total'],
                $base['active'],
                $base['disconnected'],
                $base['lapsed_mrc'],
                $this->rangeLabel($from, $to)
            ),

            // Operating against capital spending. Reported apart because netting
            // an asset purchase against one month's income understates that month
            // and overstates every later one.
            'opex_capex' => ExpenseClassifier::opexCapex($byExpenseType),

            // Recurring and one-off payables, with MONITOR's own settlement
            // state joined on — the source database is never written to.
            'payables' => PayablesLedger::build(
                $this->sourceKey($params),
                $to,
                $this->payableLines($db, $expensePeriod, $from, $to, $branch)
            ),

            'by_plan' => $this->revenueByPlan($db, $from, $to, $branch),
            'by_method' => $byMethod,
            'by_expense_type' => $byExpenseType,
            'payment_notes' => $this->paymentNotes($db, $from, $to, $branch),

            // Never scoped to the branch filter — this panel *is* the comparison.
            'by_branch' => [
                'period' => $branchPeriod,
                'year' => $branchYear,
                'label' => $this->routerReportLabel($branchPeriod, $branchYear, $anchor),
                'rows' => $this->routerCollections($db, $branchPeriod, $branchYear, $anchor),
                'years' => $this->paymentYears($db, $anchor->year),
            ],

            // Daily / weekly / monthly / yearly side by side.
            'periods' => $this->summaryPeriods($db, $anchor, $branch),
        ];
    }

    /**
     * The subscriber base behind the executive metrics.
     *
     * `lapsed_mrc` is the monthly charge carried by accounts that have already
     * disconnected — the revenue actually at risk, rather than a headcount
     * multiplied by an average, which would misstate it wherever plan prices
     * differ by more than a little.
     */
    private function subscriberBase(ConnectionInterface $db, ?int $branch): array
    {
        $query = $db->table('subscribers as s')->leftJoin('plans as p', 'p.plan_id', '=', 's.plan_id');

        if ($branch !== null) {
            $query->where('s.router_id', $branch);
        }

        $row = $query
            ->selectRaw("SUM(s.status IN ('active', 'vip')) AS active")
            ->selectRaw("SUM(s.status IN ('expired', 'disconnected')) AS disconnected")
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN s.status IN ('expired', 'disconnected')"
                . ' THEN COALESCE(p.amount, 0) ELSE 0 END), 0) AS lapsed_mrc'
            )
            ->first();

        return [
            'active' => (int) ($row->active ?? 0),
            'disconnected' => (int) ($row->disconnected ?? 0),
            'lapsed_mrc' => (float) ($row->lapsed_mrc ?? 0),
        ];
    }

    /**
     * Payable lines for the range, one per expense type.
     *
     * Grouped by type rather than listed per row: an accounts-payable panel is
     * about obligations — rent, bandwidth, payroll — and a hundred individual
     * rows of the same recurring cost is a ledger, not a payables view. The
     * settlement tick therefore applies to the obligation for the month, which is
     * the unit finance actually settles.
     */
    private function payableLines(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to,
        ?int $branch
    ): array {
        return $this->expenseRows($db, $granularity, $from, $to, $branch)
            ->leftJoin('expense_types as et', 'et.type_id', '=', 'e.expense_type_id')
            ->selectRaw("COALESCE(NULLIF(et.name, ''), '(Uncategorized)') AS label")
            ->selectRaw('COALESCE(et.type_id, 0) AS type_id')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('SUM(e.amount) AS total')
            ->selectRaw('MAX(e.expense_date) AS last_booked')
            // The horizon the majority of this type's rows were booked against,
            // which is what ExpenseClassifier trusts ahead of the type name.
            ->selectRaw("MAX(COALESCE(e.period_type, 'daily')) AS period_type")
            ->groupBy('label', 'type_id')
            ->orderByRaw('SUM(e.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                // Stable across months so a tick keys to the obligation, not to
                // a row id that changes every time the expense is re-entered.
                'ref' => 'type:' . (int) $row->type_id,
                'label' => (string) $row->label,
                'type' => (string) $row->label,
                'amount' => round((float) $row->total, 2),
                'count' => (int) $row->cnt,
                'period_type' => (string) $row->period_type,
                'last_booked_at' => $row->last_booked,
            ])
            ->all();
    }

    /**
     * Which database this driver is answering for.
     *
     * The drivers are handed a connection, not a key, so the key travels in the
     * params — ReportingService puts it there. Needed because the payables
     * settlement table is keyed per source: two branches both owe rent, and one
     * paying it does not settle the other's.
     */
    private function sourceKey(array $params): string
    {
        $key = trim((string) ($params['source_key'] ?? ''));

        return $key !== '' ? $key : 'netmanager';
    }

    /**
     * The four horizons side by side, anchored on one date.
     *
     * Weekly here is the *calendar* week, whereas the trend's weekly bucket is
     * "the last 7 days". The two source modules genuinely differ and both are
     * reproduced as-is, so each figure agrees with its counterpart.
     */
    private function summaryPeriods(ConnectionInterface $db, $anchor, ?int $branch): array
    {
        $periods = [];

        foreach (ReportPeriod::summaryWindows($anchor->toDateString()) as $key => $window) {
            $revenue = $this->revenueStats($db, $window['from'], $window['to'], $branch);
            $expenses = $this->expenseTotals($db, $key, $window['from'], $window['to'], $branch);
            $net = $revenue['total'] - $expenses['total'];

            $periods[] = [
                'key' => $key,
                'label' => $window['label'],
                'accent' => $window['accent'],
                'date_label' => $window['date_label'],
                'range' => ['from' => $window['from'], 'to' => $window['to']],
                'income' => round($revenue['total'], 2),
                'payment_count' => $revenue['count'],
                'expenses' => round($expenses['total'], 2),
                'expenses_count' => $expenses['count'],
                'net' => round($net, 2),
                // Signed the way the source labels it: a margin in surplus, a
                // loss ratio otherwise.
                'ratio_pct' => $revenue['total'] > 0
                    ? round(abs($net) / $revenue['total'] * 100, 1)
                    : null,
            ];
        }

        return $periods;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  OPERATIONS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Field delivery: the installation pipeline and how fast it clears.
     *
     * NETMANAGER models field work as `installations` rows carrying a status and
     * an assigned user. There is no separate service-order concept, so repairs
     * and new connections are not distinguishable — `has_service_orders` says so
     * rather than leaving the frontend to imply a split the data cannot support.
     */
    public function operations(ConnectionInterface $db, array $params): array
    {
        $branch = $this->branchId($params['branch'] ?? null);
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => $branch !== null ? (string) $branch : null,
            'branch_label' => $this->branchLabel($db, $branch),
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            // NETMANAGER has one work queue, not several.
            'queues' => [
                [
                    'key' => 'installations',
                    'label' => 'Installations',
                    'statuses' => $this->installationStatuses($db, $branch, $from, $to),
                    'backlog' => $this->installationBacklog($db, $branch),
                ],
            ],
            'series' => $this->installationSeries($db, $branch, $from, $to),
            'turnaround' => $this->installationTurnaround($db, $branch, $from, $to),

            // Average completion time per kind of work. One queue here, so it is
            // segmented by the installation's own status vocabulary rather than
            // by order type — see turnaroundByType.
            'turnaround_by_type' => $this->turnaroundByType($db, $branch, $from, $to),

            'recent' => $this->recentInstallations($db, $branch),
            'has_service_orders' => false,
        ];
    }

    /**
     * Turnaround segmented by the work's own type.
     *
     * NETMANAGER models field work as one `installations` queue, so there is no
     * order type to segment on — what varies is the outcome status a job closed
     * under, and "approved in 6h, done in 40h" is a genuinely useful split. The
     * unit is hours because this schema ages a ticket rather than stamping time
     * on site; GOWISER measures minutes and says so in its own payload.
     */
    private function turnaroundByType(
        ConnectionInterface $db,
        ?int $branch,
        string $from,
        string $to
    ): array {
        return $this->installations($db, $branch)
            ->whereBetween(DB::raw('DATE(i.updated_at)'), [$from, $to])
            ->whereRaw("COALESCE(NULLIF(i.status, ''), '') IN ('Approved', 'Done', 'Completed')")
            ->whereNotNull('i.created_at')
            ->selectRaw("COALESCE(NULLIF(i.status, ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, i.created_at, i.updated_at)) AS avg_hours')
            ->selectRaw('MAX(TIMESTAMPDIFF(HOUR, i.created_at, i.updated_at)) AS max_hours')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'closed' => (int) $row->cnt,
                'average_hours' => $row->avg_hours !== null ? round((float) $row->avg_hours, 1) : null,
                'longest_hours' => $row->max_hours !== null ? (int) $row->max_hours : null,
                'unit' => 'hours',
            ])
            ->all();
    }

    /** Installations opened in the range, grouped by their current status. */
    private function installationStatuses(
        ConnectionInterface $db,
        ?int $branch,
        string $from,
        string $to
    ): array {
        return $this->installations($db, $branch)
            ->whereBetween(DB::raw('DATE(i.created_at)'), [$from, $to])
            ->selectRaw("COALESCE(NULLIF(i.status, ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->cnt])
            ->all();
    }

    /**
     * Work still open, and how long the oldest of it has been waiting.
     *
     * Deliberately ignores the date range: a job opened four months ago is still
     * a backlog item today, and filtering it out by report window is exactly how
     * a backlog disappears from the report that exists to surface it.
     */
    private function installationBacklog(ConnectionInterface $db, ?int $branch): array
    {
        $row = $this->installations($db, $branch)
            ->whereRaw("COALESCE(NULLIF(i.status, ''), 'Pending') NOT IN ('Approved', 'Done', 'Completed', 'Cancelled')")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('MIN(i.created_at) AS oldest')
            ->first();

        $oldest = $row->oldest ?? null;

        return [
            'open' => (int) ($row->cnt ?? 0),
            'oldest_opened_at' => $oldest,
            'oldest_age_days' => $oldest
                ? Carbon::parse($oldest)->startOfDay()->diffInDays(Carbon::now()->startOfDay())
                : null,
        ];
    }

    /** Installations opened and closed per day across the range. */
    private function installationSeries(
        ConnectionInterface $db,
        ?int $branch,
        string $from,
        string $to
    ): array {
        $opened = $this->installations($db, $branch)
            ->whereBetween(DB::raw('DATE(i.created_at)'), [$from, $to])
            ->selectRaw("DATE_FORMAT(i.created_at, '%Y-%m-%d') AS day")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $closed = $this->installations($db, $branch)
            ->whereBetween(DB::raw('DATE(i.updated_at)'), [$from, $to])
            ->whereRaw("COALESCE(NULLIF(i.status, ''), '') IN ('Approved', 'Done', 'Completed')")
            ->selectRaw("DATE_FORMAT(i.updated_at, '%Y-%m-%d') AS day")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Union of both sides: a day that only closed work still has to plot.
        $days = $opened->keys()->merge($closed->keys())->unique()->sort()->values();

        return $days->map(fn ($day) => [
            'period' => (string) $day,
            'label' => ReportPeriod::parse((string) $day)?->format('M d') ?? (string) $day,
            'opened' => (int) ($opened->get($day)->cnt ?? 0),
            'closed' => (int) ($closed->get($day)->cnt ?? 0),
        ])->all();
    }

    /**
     * How long completed installations took, from opened to closed.
     *
     * Measured only on work that actually closed inside the range. Including
     * still-open jobs would make the average fall every time a new one is
     * created, which is the opposite of what it should do.
     */
    private function installationTurnaround(
        ConnectionInterface $db,
        ?int $branch,
        string $from,
        string $to
    ): array {
        $row = $this->installations($db, $branch)
            ->whereBetween(DB::raw('DATE(i.updated_at)'), [$from, $to])
            ->whereRaw("COALESCE(NULLIF(i.status, ''), '') IN ('Approved', 'Done', 'Completed')")
            ->whereNotNull('i.created_at')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, i.created_at, i.updated_at)) AS avg_hours')
            ->selectRaw('MAX(TIMESTAMPDIFF(HOUR, i.created_at, i.updated_at)) AS max_hours')
            ->first();

        // Read through ?? rather than comparing $row->x against null: an aggregate
        // over no matching rows yields NULL columns, and first() itself can be
        // null. ?? is safe on both; a direct dereference is not.
        $average = $row->avg_hours ?? null;
        $longest = $row->max_hours ?? null;

        return [
            'closed' => (int) ($row->cnt ?? 0),
            'average_hours' => $average !== null ? round((float) $average, 1) : null,
            'longest_hours' => $longest !== null ? (int) $longest : null,
        ];
    }

    private function recentInstallations(ConnectionInterface $db, ?int $branch): array
    {
        return $this->installations($db, $branch)
            ->leftJoin('users as u', 'u.user_id', '=', 'i.user_id')
            ->leftJoin('plans as p', 'p.plan_id', '=', 's.plan_id')
            ->select(
                'i.installation_id',
                'i.status',
                'i.remark',
                'i.created_at',
                'i.updated_at',
                's.account_number',
                's.firstname',
                's.lastname',
                's.barangay',
                's.municipality',
                'p.title as plan_title',
                'u.firstname as assignee_first',
                'u.lastname as assignee_last'
            )
            ->orderByDesc('i.created_at')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->installation_id,
                'status' => (string) ($row->status ?? ''),
                'remark' => (string) ($row->remark ?? ''),
                'account_number' => (string) ($row->account_number ?? ''),
                'subscriber' => $this->fullName($row->firstname ?? '', $row->lastname ?? ''),
                'location' => $this->joinLocation([$row->barangay ?? null, $row->municipality ?? null]),
                'plan' => (string) ($row->plan_title ?? ''),
                'assignee' => $this->fullName($row->assignee_first ?? '', $row->assignee_last ?? ''),
                'opened_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->all();
    }

    /**
     * Installations joined to their subscriber.
     *
     * The join is unconditional here, unlike paidPayments(): an installation
     * without a subscriber is orphaned data with no branch and nothing to show,
     * and the source system joins the same way.
     */
    private function installations(ConnectionInterface $db, ?int $branch): Builder
    {
        $query = $db->table('installations as i')
            ->join('subscribers as s', 's.subscriber_id', '=', 'i.subscriber_id');

        if ($branch !== null) {
            $query->where('s.router_id', $branch);
        }

        return $query;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  EMPLOYEE
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Staff and what they produced in the range.
     *
     * Three different questions, deliberately kept apart rather than merged into
     * one "productivity" figure: cashiers collect money, field users close jobs,
     * and payees receive money. Only the first two are staff performance; the
     * payee ledger is spending, and it sits here because that is where the
     * source system records a person's name against an expense.
     */
    public function employee(ConnectionInterface $db, array $params): array
    {
        $branch = $this->branchId($params['branch'] ?? null);
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);
        $expensePeriod = ReportPeriod::fromDateRange($from, $to);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => $branch !== null ? (string) $branch : null,
            'branch_label' => $this->branchLabel($db, $branch),
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            'roster' => $this->staffRoster($db, $branch),
            'by_role' => $this->staffByRole($db, $branch),
            'collections' => $this->collectionsByCashier($db, $from, $to, $branch),
            'field_work' => $this->workByAssignee($db, $from, $to, $branch),
            'payees' => $this->expensesByPayee($db, $expensePeriod, $from, $to, $branch),
            'supports_payees' => true,
        ];
    }

    /**
     * Drops non-staff roles from an Employee-section query.
     *
     * NetManager keeps subscribers in their own table, so this schema is far less
     * exposed than GOWISER's shared `users` table — but the same config drives
     * both, so an installation that adds a non-staff role gets it excluded
     * everywhere rather than in one section and not the other.
     *
     * COALESCE guards the NULL case: `NULL NOT IN (...)` is NULL, not true, so a
     * roleless account would otherwise vanish from the roster.
     */
    private function excludeNonStaff($query, string $column)
    {
        $roles = array_values(array_filter(array_map(
            fn ($role) => strtolower(trim((string) $role)),
            (array) config('reporting.non_staff_roles', [])
        )));

        if ($roles === []) {
            return $query;
        }

        $placeholders = implode(', ', array_fill(0, count($roles), '?'));

        return $query->whereRaw(
            "LOWER(COALESCE({$column}, '')) NOT IN ({$placeholders})",
            $roles
        );
    }

    private function staffRoster(ConnectionInterface $db, ?int $branch): array
    {
        $query = $db->table('users as u')->leftJoin('routers as r', 'r.router_id', '=', 'u.router_id');

        if ($branch !== null) {
            $query->where('u.router_id', $branch);
        }

        $this->excludeNonStaff($query, 'u.role');

        return $query
            ->select(
                'u.user_id',
                'u.username',
                'u.firstname',
                'u.lastname',
                'u.email',
                'u.role',
                'u.is_active',
                'r.name as branch_name'
            )
            ->orderBy('u.role')
            ->orderBy('u.firstname')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->user_id,
                'name' => $this->fullName($row->firstname ?? '', $row->lastname ?? '') ?: (string) $row->username,
                'username' => (string) ($row->username ?? ''),
                'email' => (string) ($row->email ?? ''),
                'role' => (string) ($row->role ?? ''),
                'branch' => (string) ($row->branch_name ?? ''),
                'active' => (bool) ($row->is_active ?? false),
            ])
            ->all();
    }

    private function staffByRole(ConnectionInterface $db, ?int $branch): array
    {
        $query = $db->table('users');

        if ($branch !== null) {
            $query->where('router_id', $branch);
        }

        $this->excludeNonStaff($query, 'role');

        return $query
            ->selectRaw("COALESCE(NULLIF(role, ''), 'Unassigned') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('SUM(is_active = 1) AS active')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'active' => (int) $row->active,
            ])
            ->all();
    }

    /**
     * Collections credited to the user who recorded them.
     *
     * Left join on users so a payment recorded by a since-deleted account still
     * contributes under "(unattributed)" instead of dropping out and making this
     * page disagree with the Financial section's total.
     */
    private function collectionsByCashier(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        $name = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";

        return $this->paidPayments($db, $branch)
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to])
            ->leftJoin('users as u', 'u.user_id', '=', 'py.user_id')
            ->selectRaw("COALESCE(NULLIF({$name}, ''), '(unattributed)') AS label")
            ->selectRaw("COALESCE(NULLIF(u.role, ''), '') AS role")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(py.amount), 0) AS total')
            ->groupBy('label', 'role')
            ->orderByRaw('COALESCE(SUM(py.amount), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'role' => (string) $row->role,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /** Installations assigned and closed per field user in the range. */
    private function workByAssignee(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        $name = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";

        return $this->installations($db, $branch)
            ->whereBetween(DB::raw('DATE(i.created_at)'), [$from, $to])
            ->leftJoin('users as u', 'u.user_id', '=', 'i.user_id')
            ->selectRaw("COALESCE(NULLIF({$name}, ''), '(unassigned)') AS label")
            ->selectRaw("COALESCE(NULLIF(u.role, ''), '') AS role")
            ->selectRaw('COUNT(*) AS assigned')
            ->selectRaw("SUM(COALESCE(NULLIF(i.status, ''), '') IN ('Approved', 'Done', 'Completed')) AS completed")
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, i.created_at, i.updated_at)) AS avg_hours')
            ->groupBy('label', 'role')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'role' => (string) $row->role,
                'assigned' => (int) $row->assigned,
                'completed' => (int) $row->completed,
                'average_hours' => ($row->avg_hours ?? null) !== null ? round((float) $row->avg_hours, 1) : null,
            ])
            ->all();
    }

    /**
     * The payee ledger: who money was paid out to.
     *
     * Grouped on the free-text `employee` column, which is what the source
     * records — a payee name, not a foreign key. Two spellings of the same
     * person are therefore two rows here, exactly as they are there.
     */
    private function expensesByPayee(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to,
        ?int $branch
    ): array {
        return $this->expenseRows($db, $granularity, $from, $to, $branch)
            ->leftJoin('expense_types as et', 'et.type_id', '=', 'e.expense_type_id')
            ->whereNotNull('e.employee')
            ->where('e.employee', '<>', '')
            ->selectRaw('e.employee AS label')
            ->selectRaw("GROUP_CONCAT(DISTINCT COALESCE(NULLIF(et.name, ''), '(Uncategorized)') SEPARATOR ', ') AS types")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(e.amount), 0) AS total')
            ->groupBy('label')
            ->orderByRaw('COALESCE(SUM(e.amount), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'detail' => (string) ($row->types ?? ''),
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Headline subscriber counts plus the expiry pipeline.
     *
     * `expiring_3` / `expiring_7` deliberately look forward from the anchor and
     * only count active accounts — an already-expired subscriber is not
     * "expiring soon", it is a collections problem.
     */
    private function subscriberKpis(ConnectionInterface $db, ?int $branch, string $today): array
    {
        $query = $db->table('subscribers');

        if ($branch !== null) {
            $query->where('router_id', $branch);
        }

        $anchor = ReportPeriod::anchor($today);
        $plus3 = $anchor->copy()->addDays(3)->toDateString();
        $plus7 = $anchor->copy()->addDays(7)->toDateString();
        $minus30 = $anchor->copy()->subDays(30)->startOfDay()->toDateTimeString();

        // `total` counts subscribers, so pending applications are excluded — the
        // same rule StatusMap applies to the charts, applied here so the header
        // and the pie cannot disagree about how many subscribers there are.
        $row = $query
            ->selectRaw('SUM(' . StatusMap::excludeSql('status') . ') AS total')
            ->selectRaw("SUM(status = 'active') AS active")
            ->selectRaw("SUM(status = 'vip') AS vip")
            ->selectRaw("SUM(status = 'suspended') AS restricted")
            ->selectRaw("SUM(status IN ('expired', 'disconnected')) AS disconnected")
            ->selectRaw("SUM(status = 'active' AND subscription_end BETWEEN ? AND ?) AS expiring_3", [$today, $plus3])
            ->selectRaw("SUM(status = 'active' AND subscription_end BETWEEN ? AND ?) AS expiring_7", [$today, $plus7])
            ->selectRaw('SUM(created_at >= ?) AS new_30day', [$minus30])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'vip' => (int) ($row->vip ?? 0),
            'restricted' => (int) ($row->restricted ?? 0),
            'disconnected' => (int) ($row->disconnected ?? 0),
            'expiring_3day' => (int) ($row->expiring_3 ?? 0),
            'expiring_7day' => (int) ($row->expiring_7 ?? 0),
            'new_30day' => (int) ($row->new_30day ?? 0),
        ];
    }

    /**
     * Every barangay, with the billing-status split management asked for.
     *
     * Not a top ten. A league table answers "which barangay is biggest", which
     * is a question nobody was asking; the coverage question needs the tail,
     * including the barangays with three subscribers. The table is sorted client
     * side, so no ordering is imposed here beyond a stable one.
     *
     * Grouped by barangay *and* its municipality/province: "San Roque" exists in
     * many towns and merging them would be meaningless.
     *
     * Pending rows are excluded by the same rule as everywhere else, so a
     * barangay's row total matches its share of the subscriber base.
     */
    private function barangayBreakdown(ConnectionInterface $db, ?int $branch, array $params): array
    {
        $query = $db->table('subscribers')
            ->whereNotNull('barangay')
            ->where('barangay', '<>', '')
            ->whereRaw(StatusMap::excludeSql('status'));

        if ($branch !== null) {
            $query->where('router_id', $branch);
        }

        foreach ([
            'region' => $params['geo_region'] ?? '',
            'province' => $params['geo_province'] ?? '',
            'municipality' => $params['geo_municipality'] ?? '',
        ] as $column => $value) {
            if (trim((string) $value) !== '') {
                $query->where($column, trim((string) $value));
            }
        }

        return $query
            ->selectRaw('barangay, municipality, province')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(StatusMap::bucketSql('status', 'active') . ' AS active')
            ->selectRaw(StatusMap::bucketSql('status', 'vip') . ' AS vip')
            ->selectRaw(StatusMap::bucketSql('status', 'inactive') . ' AS inactive')
            ->selectRaw(StatusMap::bucketSql('status', 'pullout') . ' AS pullout')
            ->selectRaw("SUM(status = 'suspended') AS restricted")
            ->selectRaw("SUM(status IN ('expired', 'disconnected')) AS disconnected")
            ->groupBy('barangay', 'municipality', 'province')
            ->orderBy('barangay')
            ->get()
            ->map(fn ($row) => [
                'barangay' => (string) $row->barangay,
                'municipality' => (string) ($row->municipality ?? ''),
                'province' => (string) ($row->province ?? ''),
                'total' => (int) $row->total,
                'active' => (int) $row->active,
                'vip' => (int) $row->vip,
                'inactive' => (int) $row->inactive,
                'pullout' => (int) $row->pullout,
                'restricted' => (int) $row->restricted,
                'disconnected' => (int) $row->disconnected,
            ])
            ->all();
    }

    /**
     * Collections per router for the selected window.
     *
     * Never scoped to a single branch — this panel *is* the comparison. The
     * outer joins keep a router with no collections in the list at ₱0 rather
     * than dropping it, which is what makes a dead branch visible.
     */
    private function routerCollections(ConnectionInterface $db, string $period, int $year, $anchor): array
    {
        $rows = $db->table('routers as r')
            ->leftJoin('subscribers as s', 's.router_id', '=', 'r.router_id')
            ->leftJoin('payments as py', function ($join) use ($period, $year, $anchor) {
                $join->on('py.subscriber_id', '=', 's.subscriber_id')
                    ->where('py.status', '=', 'paid');

                $this->constrainRouterReportDates($join, $period, $year, $anchor);
            })
            ->selectRaw('r.router_id AS id')
            ->selectRaw('r.name AS label')
            ->selectRaw('r.municipality, r.province, r.region')
            ->selectRaw('COALESCE(SUM(py.amount), 0) AS collection')
            ->selectRaw('COUNT(DISTINCT s.subscriber_id) AS subscribers')
            ->groupBy('r.router_id', 'r.name', 'r.municipality', 'r.province', 'r.region')
            ->orderByRaw('COALESCE(SUM(py.amount), 0) DESC')
            ->get();

        $total = $rows->sum(fn ($row) => (float) $row->collection);

        return $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'label' => (string) $row->label,
            'location' => $this->joinLocation([$row->municipality, $row->province, $row->region]),
            'collection' => round((float) $row->collection, 2),
            'subscribers' => (int) $row->subscribers,
            // Share is computed server-side so the table and the pie chart can
            // never round to different percentages.
            'share_pct' => $total > 0 ? round((float) $row->collection / $total * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * The date predicate for the Router Reports panel, applied inside the join
     * so routers with no matching payments still appear.
     */
    private function constrainRouterReportDates($join, string $period, int $year, $anchor): void
    {
        switch ($period) {
            case 'daily':
                $join->whereRaw('DATE(py.payment_date) = ?', [$anchor->toDateString()]);
                break;
            case 'weekly':
                $join->whereRaw('DATE(py.payment_date) >= ?', [$anchor->copy()->subDays(7)->toDateString()]);
                break;
            case 'yearly':
                $join->whereRaw('YEAR(py.payment_date) = ?', [$year]);
                break;
            default:
                $join->whereRaw('YEAR(py.payment_date) = ?', [$anchor->year])
                    ->whereRaw('MONTH(py.payment_date) = ?', [$anchor->month]);
        }
    }

    private function routerReportLabel(string $period, int $year, $anchor): string
    {
        switch ($period) {
            case 'daily':
                return 'Today (' . $anchor->format('M d, Y') . ')';
            case 'weekly':
                return 'Last 7 Days';
            case 'yearly':
                return (string) $year;
            default:
                return $anchor->format('F Y');
        }
    }

    /** Years that actually have collections, so the year filter offers no dead options. */
    private function paymentYears(ConnectionInterface $db, int $currentYear): array
    {
        $years = $db->table('payments')
            ->where('status', 'paid')
            ->selectRaw('DISTINCT YEAR(payment_date) AS yr')
            ->orderByDesc('yr')
            ->pluck('yr')
            ->map(fn ($year) => (int) $year)
            ->filter()
            ->all();

        if (!in_array($currentYear, $years, true)) {
            array_unshift($years, $currentYear);
        }

        return array_values($years);
    }

    /** Count, total, average and largest collection in the range. */
    private function revenueStats(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        $row = $this->paidPayments($db, $branch)
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to])
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(py.amount), 0) AS total')
            ->selectRaw('COALESCE(AVG(py.amount), 0) AS avg_amount')
            ->selectRaw('COALESCE(MAX(py.amount), 0) AS max_amount')
            ->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'total' => (float) ($row->total ?? 0),
            'average' => (float) ($row->avg_amount ?? 0),
            'largest' => (float) ($row->max_amount ?? 0),
        ];
    }

    /**
     * Subscribers per status, in this portal's reported vocabulary.
     *
     * `raw` keeps the source's own values because the billing summary buckets on
     * them and because a reader tracing a figure back to NETMANAGER needs the
     * word that system actually stores. `by_status` is what the charts render:
     * pending removed, suspended shown as Restricted, expired as Disconnected —
     * see StatusMap for why each of the three.
     */
    private function subscriberStatusCounts(ConnectionInterface $db, ?int $branch): array
    {
        $query = $db->table('subscribers as s');

        if ($branch !== null) {
            $query->where('s.router_id', $branch);
        }

        $rows = $query->selectRaw('status, COUNT(*) AS cnt')->groupBy('status')->get();

        $raw = [];

        foreach ($rows as $row) {
            $raw[(string) $row->status] = (int) $row->cnt;
        }

        $reported = StatusMap::rewrite($raw);

        return [
            // Total counts subscribers, and a pending application is not one, so
            // it is the total of the reported map rather than of every row.
            'total' => array_sum($reported),
            'active' => $reported['Active'] ?? 0,
            'vip' => $reported['VIP'] ?? 0,
            'restricted' => $reported['Restricted'] ?? 0,
            'disconnected' => $reported['Disconnected'] ?? 0,
            'inactive' => $reported['Inactive'] ?? 0,
            'pullout' => $reported['Pullout'] ?? 0,
            'by_status' => $reported,
            'raw' => $raw,
            'excluded' => array_sum(array_intersect_key(
                $raw,
                array_flip(array_filter(array_keys($raw), fn ($key) => StatusMap::isExcluded($key)))
            )),
        ];
    }

    /**
     * Monthly recurring charge the active base *should* bill.
     *
     * Left join, so an active subscriber whose plan was deleted contributes
     * nothing instead of removing the whole row from the total.
     */
    private function expectedMrc(ConnectionInterface $db, ?int $branch): float
    {
        $query = $db->table('subscribers as s')
            ->leftJoin('plans as p', 'p.plan_id', '=', 's.plan_id')
            ->where('s.status', 'active');

        if ($branch !== null) {
            $query->where('s.router_id', $branch);
        }

        return (float) $query->sum(DB::raw('COALESCE(p.amount, 0)'));
    }

    private function newSubscribers(ConnectionInterface $db, string $from, string $to, ?int $branch): int
    {
        $query = $db->table('subscribers as s')
            ->whereBetween(DB::raw('DATE(s.created_at)'), [$from, $to]);

        if ($branch !== null) {
            $query->where('s.router_id', $branch);
        }

        return (int) $query->count();
    }

    /**
     * Day-by-day income and expenses across the selected range.
     *
     * Both sides are unioned on the date key so a day with expenses but no
     * collections still plots — otherwise a loss-making day silently vanishes
     * from the chart.
     */
    private function dailySeries(
        ConnectionInterface $db,
        string $from,
        string $to,
        string $expensePeriod,
        ?int $branch
    ): array {
        $income = $this->paidPayments($db, $branch)
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to])
            ->selectRaw("DATE_FORMAT(py.payment_date, '%Y-%m-%d') AS day")
            ->selectRaw('SUM(py.amount) AS total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $expenses = $this->expenseRows($db, $expensePeriod, $from, $to, $branch)
            ->selectRaw("DATE_FORMAT(e.expense_date, '%Y-%m-%d') AS day")
            ->selectRaw('SUM(e.amount) AS total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = $income->keys()->merge($expenses->keys())->unique()->sort()->values();

        return $days->map(function ($day) use ($income, $expenses) {
            $in = round((float) ($income->get($day)->total ?? 0), 2);
            $out = round((float) ($expenses->get($day)->total ?? 0), 2);

            return [
                'period' => (string) $day,
                'label' => ReportPeriod::parse((string) $day)?->format('M d') ?? (string) $day,
                'income' => $in,
                'expenses' => $out,
                'net' => round($in - $out, 2),
            ];
        })->all();
    }

    private function revenueByPlan(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        // Inner joins on purpose: revenue is attributed *to a plan*, so a
        // payment whose subscriber or plan is gone has no plan to report under.
        $query = $db->table('payments as py')
            ->join('subscribers as s', 's.subscriber_id', '=', 'py.subscriber_id')
            ->join('plans as p', 'p.plan_id', '=', 's.plan_id')
            ->where('py.status', 'paid')
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to]);

        if ($branch !== null) {
            $query->where('s.router_id', $branch);
        }

        return $query
            ->selectRaw('p.title AS label')
            ->selectRaw('COUNT(py.payment_id) AS cnt')
            ->selectRaw('SUM(py.amount) AS total')
            ->groupBy('p.plan_id', 'p.title')
            ->orderByRaw('SUM(py.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    private function revenueByMethod(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        return $this->paidPayments($db, $branch)
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to])
            ->selectRaw("COALESCE(NULLIF(py.method, ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('SUM(py.amount) AS total')
            ->groupBy('label')
            ->orderByRaw('SUM(py.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Collections grouped by the free-text note the cashier typed.
     *
     * Cashiers use the note field for promo and adjustment tags, so grouping on
     * it is how those campaigns get totalled. Blank notes are excluded.
     */
    private function paymentNotes(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        return $this->paidPayments($db, $branch)
            ->whereNotNull('py.notes')
            ->where('py.notes', '<>', '')
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to])
            ->selectRaw('py.notes AS note')
            ->selectRaw('COUNT(*) AS note_count')
            ->selectRaw('COUNT(DISTINCT py.subscriber_id) AS subscriber_count')
            ->selectRaw('COALESCE(SUM(py.amount), 0) AS total')
            ->groupBy('py.notes')
            ->orderByRaw('COALESCE(SUM(py.amount), 0) DESC')
            ->get()
            // Emitted in the shared label/count/total shape so one breakdown
            // component renders this, revenue-by-plan and revenue-by-method.
            // `count` is subscribers — the figure that says how many people a
            // promo tag actually reached — with the payment count in `detail`,
            // because one subscriber can pay against the same note twice.
            ->map(fn ($row) => [
                'label' => (string) $row->note,
                'count' => (int) $row->subscriber_count,
                'total' => round((float) $row->total, 2),
                'detail' => (int) $row->note_count === 1
                    ? '1 payment'
                    : number_format((int) $row->note_count) . ' payments',
            ])
            ->all();
    }

    /**
     * The overdue ledger: expired accounts, searchable, filterable by plan and
     * by how far past due, paginated.
     *
     * Note this is *not* bounded by the report's date range. It is a statement
     * of who currently owes, which is a different question from what happened
     * between two dates — the source system treats it the same way.
     */
    private function overdueAccounts(ConnectionInterface $db, ?int $branch, array $params): array
    {
        $search = trim((string) ($params['overdue_search'] ?? ''));
        $planId = (int) ($params['overdue_plan_id'] ?? 0);
        $bucket = (string) ($params['overdue_bucket'] ?? '');
        $page = max(1, (int) ($params['overdue_page'] ?? 1));

        $today = ReportPeriod::anchor($params['as_of'] ?? null);

        $filtered = function () use ($db, $branch, $search, $planId, $bucket, $today): Builder {
            $query = $db->table('subscribers as s')->where('s.status', 'expired');

            if ($branch !== null) {
                $query->where('s.router_id', $branch);
            }

            if ($search !== '') {
                $like = '%' . $search . '%';

                $query->where(function ($group) use ($like) {
                    $group->where('s.firstname', 'like', $like)
                        ->orWhere('s.lastname', 'like', $like)
                        ->orWhere('s.account_number', 'like', $like)
                        ->orWhere('s.contact_number', 'like', $like);
                });
            }

            if ($planId > 0) {
                $query->where('s.plan_id', $planId);
            }

            // Buckets are the source's: over 30 days, 1–7 days, 8–30 days.
            switch ($bucket) {
                case '30':
                    $query->where('s.subscription_end', '<', $today->copy()->subDays(30)->toDateString());
                    break;
                case '7':
                    $query->whereBetween('s.subscription_end', [
                        $today->copy()->subDays(7)->toDateString(),
                        $today->copy()->subDay()->toDateString(),
                    ]);
                    break;
                case '8_30':
                    $query->whereBetween('s.subscription_end', [
                        $today->copy()->subDays(30)->toDateString(),
                        $today->copy()->subDays(8)->toDateString(),
                    ]);
                    break;
            }

            return $query;
        };

        $total = (int) $filtered()->count();
        $totalPages = max(1, (int) ceil($total / self::OVERDUE_PER_PAGE));
        $page = min($page, $totalPages);

        // An aggregate request asks for the first N pages in one go rather than
        // page N alone: the caller merges several databases and slices the
        // combined pool, and a row can only reach the merged page N if it sits
        // within its own database's first N pages. Returning page N alone here
        // would leave holes in that pool.
        $fetchPages = max(1, (int) ($params['overdue_fetch_pages'] ?? 0));
        $widened = $fetchPages > 1;
        $limit = $widened ? $fetchPages * self::OVERDUE_PER_PAGE : self::OVERDUE_PER_PAGE;
        $offset = $widened ? 0 : ($page - 1) * self::OVERDUE_PER_PAGE;

        $rows = $filtered()
            ->leftJoin('plans as p', 'p.plan_id', '=', 's.plan_id')
            ->select(
                's.subscriber_id',
                's.account_number',
                's.firstname',
                's.lastname',
                's.contact_number',
                's.subscription_end',
                'p.title as plan_title',
                'p.amount as plan_amount'
            )
            ->selectRaw('DATEDIFF(?, s.subscription_end) AS days_overdue', [$today->toDateString()])
            ->orderByDesc('days_overdue')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'rows' => $rows->map(fn ($row) => [
                'id' => (string) $row->subscriber_id,
                'account_number' => (string) ($row->account_number ?? ''),
                'subscriber' => $this->fullName($row->firstname ?? '', $row->lastname ?? ''),
                'contact_number' => (string) ($row->contact_number ?? ''),
                'plan' => (string) ($row->plan_title ?? ''),
                'mrc' => round((float) ($row->plan_amount ?? 0), 2),
                'expired_on' => $row->subscription_end,
                'days_overdue' => (int) ($row->days_overdue ?? 0),
            ])->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => self::OVERDUE_PER_PAGE,
            'total_pages' => $totalPages,
            'filters' => [
                'search' => $search,
                'plan_id' => $planId,
                'bucket' => $bucket,
            ],
            // Only plans that actually have expired accounts, so the filter
            // never offers an option that returns nothing.
            'plans' => $db->table('plans as p')
                ->join('subscribers as s', 's.plan_id', '=', 'p.plan_id')
                ->where('s.status', 'expired')
                ->selectRaw('DISTINCT p.plan_id, p.title')
                ->orderBy('p.title')
                ->get()
                ->map(fn ($row) => ['id' => (int) $row->plan_id, 'label' => (string) $row->title])
                ->all(),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  PRINTABLE REPORTS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Every line the three print layouts need, in one payload.
     *
     * One request rather than three because the Financial Report prints both
     * ledgers together, and fetching them separately risks the two halves of
     * one printed page coming from different moments.
     */
    public function printable(ConnectionInterface $db, string $from, string $to, $branch = null): array
    {
        $branchId = $this->branchId($branch);
        $expensePeriod = ReportPeriod::fromDateRange($from, $to);

        $payments = $this->paymentLines($db, $from, $to, $branchId);
        $expenses = $this->expenseLines($db, $expensePeriod, $from, $to, $branchId);

        $income = array_sum(array_column($payments, 'amount'));
        $spend = array_sum(array_column($expenses, 'amount'));

        return [
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),
            'expense_period' => $expensePeriod,
            'branch' => $branchId !== null ? (string) $branchId : null,
            'branch_label' => $this->branchLabel($db, $branchId),
            'generated_at' => now()->toDateTimeString(),
            'company' => $this->company($db, $branchId),
            'payments' => $payments,
            'expenses' => $expenses,
            'payment_notes' => $this->paymentNotes($db, $from, $to, $branchId),
            'totals' => [
                'income' => round($income, 2),
                'income_count' => count($payments),
                'expenses' => round($spend, 2),
                'expenses_count' => count($expenses),
                'net' => round($income - $spend, 2),
            ],
        ];
    }

    /** Payment lines for the Payment Report and the Financial Report's income half. */
    private function paymentLines(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        $query = $db->table('payments as py')
            ->join('subscribers as s', 's.subscriber_id', '=', 'py.subscriber_id')
            ->leftJoin('payment_types as pt', 'pt.type_id', '=', 'py.payment_type_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'py.user_id')
            ->where('py.status', 'paid')
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to]);

        if ($branch !== null) {
            $query->where('s.router_id', $branch);
        }

        return $query
            ->select(
                'py.payment_id',
                'py.payment_date',
                'py.or_number',
                'py.amount',
                'py.method',
                'py.status',
                's.account_number',
                's.firstname',
                's.lastname',
                'pt.name as type_name',
                'u.firstname as cashier_first',
                'u.lastname as cashier_last'
            )
            ->orderBy('py.payment_date')
            ->orderBy('py.payment_id')
            ->get()
            ->map(fn ($row) => [
                'or_number' => (string) ($row->or_number ?? ''),
                'account_number' => (string) ($row->account_number ?? ''),
                'subscriber' => $this->fullName($row->firstname ?? '', $row->lastname ?? ''),
                'type' => (string) ($row->type_name ?? 'Subscription'),
                'method' => (string) ($row->method ?? ''),
                'status' => (string) ($row->status ?? ''),
                'amount' => round((float) $row->amount, 2),
                'payment_date' => $row->payment_date,
                'cashier' => $this->fullName($row->cashier_first ?? '', $row->cashier_last ?? ''),
            ])
            ->all();
    }

    /** Expense lines for the Expense Report and the Financial Report's outgoing half. */
    private function expenseLines(
        ConnectionInterface $db,
        string $expensePeriod,
        string $from,
        string $to,
        ?int $branch
    ): array {
        return $this->expenseRows($db, $expensePeriod, $from, $to, $branch)
            ->leftJoin('expense_types as et', 'et.type_id', '=', 'e.expense_type_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'e.user_id')
            ->select(
                'e.expense_id',
                'e.expense_date',
                'e.employee',
                'e.amount',
                'e.remark',
                'e.period_type',
                'et.name as type_name',
                'u.firstname as recorded_first',
                'u.lastname as recorded_last'
            )
            ->orderBy('e.expense_date')
            ->orderBy('e.expense_id')
            ->get()
            ->map(fn ($row) => [
                'expense_date' => $row->expense_date,
                'type' => (string) ($row->type_name ?? '(Uncategorized)'),
                'employee' => (string) ($row->employee ?? ''),
                'remark' => (string) ($row->remark ?? ''),
                'period_type' => (string) ($row->period_type ?? 'daily'),
                'amount' => round((float) $row->amount, 2),
                'recorded_by' => $this->fullName($row->recorded_first ?? '', $row->recorded_last ?? ''),
            ])
            ->all();
    }

    /**
     * Company header for the print layouts.
     *
     * When a branch is selected its own address replaces the corporate one, so
     * a branch's printed report carries the branch's address — the source does
     * the same, and a receipt showing head office for a branch transaction is
     * worse than useless.
     */
    private function company(ConnectionInterface $db, ?int $branch): array
    {
        $settings = $this->settings($db);

        $company = [
            'name' => $settings['company_name'] ?? 'ISP Company',
            'description' => $settings['company_desc'] ?? 'Internet Service Provider',
            'address' => $settings['company_address'] ?? '',
            'contact' => $settings['company_contact'] ?? '',
            'email' => $settings['company_email'] ?? '',
            'tin' => $settings['company_tin'] ?? '',
            'logo' => $settings['company_logo'] ?? '',
            'currency_symbol' => $settings['currency_symbol'] ?? '₱',
            'manager' => '',
        ];

        if ($branch === null) {
            return $company;
        }

        $router = $db->table('routers')
            ->select('name', 'manager', 'address', 'municipality', 'province', 'region')
            ->where('router_id', $branch)
            ->first();

        if (!$router) {
            return $company;
        }

        $company['manager'] = trim((string) ($router->manager ?? ''));

        $address = $this->joinLocation([
            $router->address ?? null,
            $router->municipality ?? null,
            $router->province ?? null,
            $router->region ?? null,
        ]);

        if ($address !== null) {
            $company['address'] = $address;
        }

        return $company;
    }

    /**
     * The source's key/value settings table.
     *
     * MONITOR cannot seed defaults the way the source does (that path INSERTs),
     * so a missing row falls back in PHP instead.
     *
     * @return array<string,string>
     */
    private function settings(ConnectionInterface $db): array
    {
        try {
            return $db->table('settings')
                ->pluck('setting_value', 'setting_key')
                ->map(fn ($value) => (string) $value)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SHARED BUILDING BLOCKS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Paid payments, optionally scoped to a branch.
     *
     * The join to `subscribers` only appears when a branch is selected: adding
     * it unconditionally would silently drop payments whose subscriber row was
     * deleted, changing unfiltered totals.
     */
    private function paidPayments(ConnectionInterface $db, ?int $branch): Builder
    {
        $query = $db->table('payments as py')->where('py.status', 'paid');

        if ($branch !== null) {
            $query->join('subscribers as s', 's.subscriber_id', '=', 'py.subscriber_id')
                ->where('s.router_id', $branch);
        }

        return $query;
    }

    /**
     * Income split into over-the-counter and online-portal collections.
     *
     * The split is decided by the payment method's remark containing "PORTAL" —
     * the source system's own convention. `payments.method` and
     * `payment_methods.code` are stored under different collations, so both
     * sides need an explicit COLLATE or MySQL refuses the comparison outright.
     */
    private function incomeKpi(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        $isPortal = "UPPER(COALESCE(pm.remark, '')) LIKE '%PORTAL%'";

        $row = $this->paidPayments($db, $branch)
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to])
            ->leftJoin(
                'payment_methods as pm',
                DB::raw('UPPER(pm.code COLLATE utf8mb4_unicode_ci)'),
                '=',
                DB::raw('UPPER(py.method COLLATE utf8mb4_unicode_ci)')
            )
            ->selectRaw('COALESCE(SUM(py.amount), 0) AS income')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isPortal} THEN py.amount ELSE 0 END), 0) AS portal_income")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isPortal} THEN 1 ELSE 0 END), 0) AS portal_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isPortal} THEN 0 ELSE py.amount END), 0) AS office_income")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isPortal} THEN 0 ELSE 1 END), 0) AS office_count")
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

    /** Over-the-counter collections itemised by charge type. */
    private function officeCollectionsByType(ConnectionInterface $db, string $from, string $to, ?int $branch): array
    {
        return $this->paidPayments($db, $branch)
            ->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to])
            ->leftJoin('payment_types as pt', 'pt.type_id', '=', 'py.payment_type_id')
            ->leftJoin(
                'payment_methods as pm',
                DB::raw('UPPER(pm.code COLLATE utf8mb4_unicode_ci)'),
                '=',
                DB::raw('UPPER(py.method COLLATE utf8mb4_unicode_ci)')
            )
            ->whereRaw("UPPER(COALESCE(pm.remark, '')) NOT LIKE '%PORTAL%'")
            ->selectRaw("COALESCE(NULLIF(pt.name, ''), 'Subscription') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(py.amount), 0) AS total')
            ->groupBy('label')
            ->orderByRaw('COALESCE(SUM(py.amount), 0) DESC')
            ->orderBy('label')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Expense rows for a range, restricted to the period_types that belong in a
     * report of this granularity. See ReportPeriod::expenseTypes.
     */
    private function expenseRows(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to,
        ?int $branch
    ): Builder {
        $query = $db->table('expenses as e')
            ->whereBetween(DB::raw('DATE(e.expense_date)'), [$from, $to])
            ->whereIn(
                DB::raw("COALESCE(e.period_type, 'daily')"),
                ReportPeriod::expenseTypes($granularity)
            );

        if ($branch !== null) {
            $query->where('e.router_id', $branch);
        }

        return $query;
    }

    private function expenseTotals(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to,
        ?int $branch
    ): array {
        $row = $this->expenseRows($db, $granularity, $from, $to, $branch)
            ->selectRaw('COALESCE(SUM(e.amount), 0) AS total')
            ->selectRaw('COUNT(*) AS cnt')
            ->first();

        return [
            'total' => (float) ($row->total ?? 0),
            'count' => (int) ($row->cnt ?? 0),
        ];
    }

    private function expensesByType(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to,
        ?int $branch
    ): array {
        return $this->expenseRows($db, $granularity, $from, $to, $branch)
            ->leftJoin('expense_types as et', 'et.type_id', '=', 'e.expense_type_id')
            ->selectRaw("COALESCE(NULLIF(et.name, ''), '(Uncategorized)') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('SUM(e.amount) AS total')
            ->groupBy('label')
            ->orderByRaw('SUM(e.amount) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Income, expenses and net on one timeline. Buckets follow the source
     * dashboard: last 30 days / last 12 weeks / last 12 months / last 10 years.
     */
    private function trendSeries(ConnectionInterface $db, string $granularity, ?int $branch, $anchor): array
    {
        [$from, $to] = $this->trendBounds($granularity, $anchor);
        [$bucketFor, $labelFor] = $this->trendExpressions($granularity);

        $incomeQuery = $this->paidPayments($db, $branch);

        if ($from !== null) {
            $incomeQuery->whereBetween(DB::raw('DATE(py.payment_date)'), [$from, $to]);
        }

        $income = $incomeQuery
            ->selectRaw($bucketFor('py.payment_date') . ' AS bucket')
            ->selectRaw($labelFor('py.payment_date') . ' AS label')
            ->selectRaw('SUM(py.amount) AS total')
            ->groupBy('bucket', 'label')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $expenseQuery = $db->table('expenses as e')
            ->whereIn(
                DB::raw("COALESCE(e.period_type, 'daily')"),
                ReportPeriod::expenseTypes($granularity)
            );

        if ($branch !== null) {
            $expenseQuery->where('e.router_id', $branch);
        }

        if ($from !== null) {
            $expenseQuery->whereBetween(DB::raw('DATE(e.expense_date)'), [$from, $to]);
        }

        $expenses = $expenseQuery
            ->selectRaw($bucketFor('e.expense_date') . ' AS bucket')
            ->selectRaw($labelFor('e.expense_date') . ' AS label')
            ->selectRaw('SUM(e.amount) AS total')
            ->groupBy('bucket', 'label')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        // Union of both sides: a period with expenses but no collections still
        // has to plot, or a loss-making month disappears from the chart.
        $buckets = $income->keys()->merge($expenses->keys())->unique()->sort()->values();

        if ($granularity === 'yearly') {
            // Ten years, most recent — not the source's "ORDER BY year ASC
            // LIMIT 10", which takes the *earliest* ten and so stops showing
            // the current year once a deployment passes its tenth. Treated as a
            // bug there rather than a rule worth reproducing; this also matches
            // what NetmanagerDriver already does for the Financials page.
            $buckets = $buckets->take(-10)->values();
        }

        return $buckets->map(function ($bucket) use ($income, $expenses) {
            $in = round((float) ($income->get($bucket)->total ?? 0), 2);
            $out = round((float) ($expenses->get($bucket)->total ?? 0), 2);

            return [
                'period' => (string) $bucket,
                'label' => (string) ($income->get($bucket)->label ?? $expenses->get($bucket)->label ?? $bucket),
                'income' => $in,
                'expenses' => $out,
                'net' => round($in - $out, 2),
            ];
        })->all();
    }

    /**
     * How far back the trend reaches. Yearly returns null so the whole history
     * is scanned and then trimmed to ten buckets — matching the source's
     * "GROUP BY year ... LIMIT 10".
     *
     * @return array{0:?string,1:string}
     */
    private function trendBounds(string $granularity, $anchor): array
    {
        $to = $anchor->copy()->endOfYear()->toDateString();

        switch ($granularity) {
            case 'daily':
                return [$anchor->copy()->subDays(30)->toDateString(), $to];
            case 'weekly':
                return [$anchor->copy()->subDays(84)->toDateString(), $to];
            case 'yearly':
                return [null, $to];
            default:
                return [$anchor->copy()->startOfMonth()->subMonths(11)->toDateString(), $to];
        }
    }

    /**
     * Bucket key and display label expressions for a date column.
     *
     * The key must sort lexicographically in chronological order, which is why
     * weekly zero-pads its week number.
     *
     * @return array{0:callable,1:callable}
     */
    private function trendExpressions(string $granularity): array
    {
        switch ($granularity) {
            case 'daily':
                return [
                    fn (string $column) => "DATE_FORMAT({$column}, '%Y-%m-%d')",
                    fn (string $column) => "DATE_FORMAT({$column}, '%b %d')",
                ];
            case 'weekly':
                return [
                    fn (string $column) => "CONCAT(YEAR({$column}), '-W', LPAD(WEEK({$column}, 3), 2, '0'))",
                    fn (string $column) => "CONCAT('Wk', LPAD(WEEK({$column}, 3), 2, '0'), ' ', YEAR({$column}))",
                ];
            case 'yearly':
                return [
                    fn (string $column) => "DATE_FORMAT({$column}, '%Y')",
                    fn (string $column) => "DATE_FORMAT({$column}, '%Y')",
                ];
            default:
                return [
                    fn (string $column) => "DATE_FORMAT({$column}, '%Y-%m')",
                    fn (string $column) => "DATE_FORMAT({$column}, '%Y-%m')",
                ];
        }
    }

    /**
     * Active subscribers per plan.
     *
     * The status filter lives in the join, not the WHERE clause: moving it out
     * would turn the left join into an inner one and hide plans with no active
     * subscribers, which is exactly the signal a plan mix should show.
     */
    private function activePlanMix(ConnectionInterface $db, ?int $branch): array
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
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->cnt])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values()
            ->all();
    }

    // ── Small helpers ────────────────────────────────────────────────────

    /** Normalises 'all', '' and null to null; anything else to a router id. */
    private function branchId($branch): ?int
    {
        if ($branch === null || $branch === '' || $branch === 'all') {
            return null;
        }

        $id = (int) $branch;

        return $id > 0 ? $id : null;
    }

    private function branchLabel(ConnectionInterface $db, ?int $branch): string
    {
        if ($branch === null) {
            return 'All branches';
        }

        $row = $db->table('routers')->where('router_id', $branch)->first();

        return (string) ($row->name ?? "Branch {$branch}");
    }

    /**
     * The report range, defaulting to month-to-date as the source does.
     *
     * A reversed range is swapped rather than rejected: BETWEEN would silently
     * return nothing, and an empty report looks like a data problem.
     *
     * @return array{0:string,1:string}
     */
    private function range(array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);

        $from = ReportPeriod::parse($params['date_from'] ?? null)
            ?? $anchor->copy()->startOfMonth();

        $to = ReportPeriod::parse($params['date_to'] ?? null) ?? $anchor->copy();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function rangeLabel(string $from, string $to): string
    {
        $start = ReportPeriod::parse($from);
        $end = ReportPeriod::parse($to);

        if ($start === null || $end === null) {
            return "{$from} – {$to}";
        }

        return $start->toDateString() === $end->toDateString()
            ? $start->format('M d, Y')
            : $start->format('M d, Y') . ' – ' . $end->format('M d, Y');
    }

    private function fullName($first, $last): string
    {
        return trim(trim((string) $first) . ' ' . trim((string) $last));
    }

    private function joinLocation(array $parts): ?string
    {
        $clean = array_filter(array_map(fn ($part) => trim((string) $part), $parts));

        return $clean ? implode(', ', $clean) : null;
    }
}
