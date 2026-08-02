<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The GOWISER schema: customers, billing_accounts, transactions, online_status,
 * applications, job_orders, service_orders, technicians, technician_locations,
 * users, roles.
 *
 * This is the source that can answer the Tech section. GOWISER records field
 * work as job orders (new connections) and service orders (repairs), each
 * carrying assigned technicians, start/end times and an onsite status — none of
 * which NETMANAGER models at all.
 *
 * Two absences shape capabilities():
 *
 *  - No expenses table. GOWISER cannot state net or margin, so it declares no
 *    'financial' capability rather than reporting collections as profit. This is
 *    the same call GowiserDriver already makes for the executive Financials page.
 *
 *  - No branch/router dimension. Everything is one operating company, so
 *    branches() returns [] and the frontend hides the branch filter instead of
 *    offering one that changes nothing.
 *
 * Status vocabularies here are free-text strings written by the app, not enums.
 * They are therefore grouped as found and normalised for display rather than
 * matched against a hardcoded list — a status this driver has never seen must
 * still appear, or new workflow states silently vanish from the report.
 */
class GowiserReportsDriver implements ReportsDriver
{
    private const TOP_N = 10;

    private const OVERDUE_PER_PAGE = 25;

    /**
     * Onsite/support states that mean the work is finished. Compared
     * case-insensitively because the app writes them inconsistently.
     */
    private const CLOSED_STATES = ['done', 'completed', 'resolved', 'approved'];

    /** A technician_locations row older than this is no longer "live". */
    private const LOCATION_STALE_MINUTES = 15;

    public function capabilities(): array
    {
        return ['subscriber_analytics', 'financial', 'operations', 'tech', 'employee'];
    }

    /**
     * GOWISER has no branch/router dimension of its own.
     *
     * Where several operating companies share one database they are separated by
     * `organization_id`, which is a property of the *connection* rather than
     * something the user picks per query — so it is applied as a scope by
     * ConnectionManager, not offered as a filter here.
     */
    public function branches(ConnectionInterface $db): array
    {
        return [];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SUBSCRIBER ANALYTICS
    // ═════════════════════════════════════════════════════════════════════

    public function subscriberAnalytics(ConnectionInterface $db, array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        $status = $this->accountStatusCounts($db);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => null,
            'branch_label' => 'All accounts',
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            'kpi' => $this->accountKpis($db, $anchor),

            // The four billing-status counters the summary header reports.
            'billing_summary' => StatusMap::billingSummary($status['raw']),

            'status' => $status,
            'plans' => $this->planMix($db),

            // Every barangay, not a top ten — see barangayBreakdown.
            'barangays' => $this->barangayBreakdown($db, $params),

            'growth' => [
                'new_in_range' => $this->newAccounts($db, $from, $to),
                'expected_mrc' => round($this->expectedMrc($db), 2),
            ],
            'overdue' => $this->overdueAccounts($db, $params),
            'sessions' => $this->sessionBreakdown($db),
        ];
    }

    /**
     * Headline account counts.
     *
     * GOWISER keeps status on billing_accounts as a `billing_status_id` with a
     * lookup table, so the names come from that table rather than being mapped
     * here — a status added in the app appears without a code change.
     */
    private function accountKpis(ConnectionInterface $db, Carbon $anchor): array
    {
        // Arrears counts are gone with the widget that showed them. The overdue
        // ledger below still reports who owes and how much, which is the
        // actionable form of the same question; a headline count of accounts in
        // arrears was a number nobody could do anything with.
        $row = $db->table('billing_accounts')
            ->selectRaw('COALESCE(SUM(created_at >= ?), 0) AS new_30day', [
                $anchor->copy()->subDays(30)->startOfDay()->toDateTimeString(),
            ])
            ->first();

        $status = $this->accountStatusCounts($db);
        $expiring = $this->prepaidExpiring($db, $anchor);

        return [
            // Counts subscribers, so pending applications are excluded — the
            // status map already dropped them, and taking the total from there
            // keeps the header and the pie chart agreeing.
            'total' => $status['total'],
            'active' => $status['active'],
            'vip' => $status['vip'],
            'restricted' => $status['restricted'],
            'disconnected' => $status['disconnected'],
            'new_30day' => (int) ($row->new_30day ?? 0),
            'expiring_3day' => $expiring['expiring_3day'],
            'expiring_7day' => $expiring['expiring_7day'],
        ];
    }

    /**
     * Active prepaid accounts whose service period lapses within N days.
     *
     * Mirrors AutoDisconnectService in the GOWISER app, deliberately: this figure
     * is only useful if it predicts what that service will actually do. Three
     * conditions come from there, and dropping any one of them inflates the count.
     *
     * 1. generation_type must be prepaid. The column is the "Billing Type", and
     *    prepaid_expires_at is only meaningful for prepaid accounts — a postpaid
     *    account can carry a stale value from before it was switched over. The
     *    three spellings are GOWISER's BillingAccount::PREPAID_ALIASES; 'Pre Paid'
     *    is listed separately because MySQL's default collation makes IN
     *    case-insensitive but not whitespace-insensitive. A NULL or unrecognised
     *    generation_type bills as postpaid, so it is correctly excluded.
     *
     * 2. Only accounts that are currently active. A suspended or already-expired
     *    account is not going to expire again, and counting it would report the
     *    same lapse twice.
     *
     * 3. The bare column, not DATE(prepaid_expires_at). The value carries a
     *    time-of-day — it is written as payment date + 30 days — and wrapping the
     *    column in a function makes the comparison unindexable across thousands of
     *    prepaid accounts.
     *
     * The window runs from the start of the as-of day, so an account expiring
     * earlier today still counts as expiring rather than silently vanishing
     * between "expiring" and "expired". Upper bound exclusive at midnight N days
     * out, which covers exactly N calendar days including today.
     */
    private function prepaidExpiring(ConnectionInterface $db, Carbon $anchor): array
    {
        $from = $anchor->copy()->startOfDay();

        $row = $db->table('billing_accounts as ba')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereIn('ba.generation_type', ['Prepaid', 'PrePaid', 'Pre Paid'])
            ->whereNotNull('ba.prepaid_expires_at')
            ->whereRaw("LOWER(TRIM(COALESCE(bs.status_name, ''))) = 'active'")
            ->selectRaw(
                'COALESCE(SUM(ba.prepaid_expires_at >= ? AND ba.prepaid_expires_at < ?), 0) AS d3',
                [$from->toDateTimeString(), $from->copy()->addDays(3)->toDateTimeString()]
            )
            ->selectRaw(
                'COALESCE(SUM(ba.prepaid_expires_at >= ? AND ba.prepaid_expires_at < ?), 0) AS d7',
                [$from->toDateTimeString(), $from->copy()->addDays(7)->toDateTimeString()]
            )
            ->first();

        return [
            'expiring_3day' => (int) ($row->d3 ?? 0),
            'expiring_7day' => (int) ($row->d7 ?? 0),
        ];
    }

    /**
     * Accounts per billing status, named from the lookup table.
     *
     * `by_status` carries every status the data actually holds; the four named
     * keys are a best-effort mapping for the headline cards, matched on the
     * status *name* so a renamed row keeps working.
     */
    private function accountStatusCounts(ConnectionInterface $db): array
    {
        $rows = $db->table('billing_accounts as ba')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->selectRaw("COALESCE(NULLIF(bs.status_name, ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        $byStatus = [];
        $total = 0;

        foreach ($rows as $row) {
            $count = (int) $row->cnt;
            $byStatus[(string) $row->label] = $count;
            $total += $count;
        }

        $reported = StatusMap::rewrite($byStatus);

        return [
            // The reported total, not every row: pending applications are not
            // subscribers and StatusMap has already dropped them.
            'total' => array_sum($reported),
            'active' => $reported['Active'] ?? 0,
            'vip' => $reported['VIP'] ?? 0,
            'restricted' => $reported['Restricted'] ?? 0,
            'disconnected' => $reported['Disconnected'] ?? 0,
            'inactive' => $reported['Inactive'] ?? 0,
            'pullout' => $reported['Pullout'] ?? 0,
            'by_status' => $reported,
            // The source's own vocabulary, kept so the billing summary can bucket
            // on it and so a figure can be traced back to what GOWISER stores.
            'raw' => $byStatus,
            'excluded' => $total - array_sum($reported),
        ];
    }

    /** Live session states, for the network-health card. */
    private function sessionBreakdown(ConnectionInterface $db): array
    {
        return $db->table('online_status')
            ->selectRaw("COALESCE(NULLIF(session_status, ''), 'Unknown') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->cnt])
            ->all();
    }

    /**
     * Active accounts per plan.
     *
     * Grouped on `plan_list` via billing_accounts.plan_id, not on the customer's
     * free-text `desired_plan`. That column is what the applicant *asked* for at
     * sign-up and is often stale; plan_id is what they are actually billed on.
     */
    private function planMix(ConnectionInterface $db): array
    {
        return $db->table('billing_accounts as ba')
            ->join('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereRaw("LOWER(COALESCE(bs.status_name, '')) = 'active'")
            ->selectRaw('pl.plan_name AS label')
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('pl.id', 'pl.plan_name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->cnt])
            ->all();
    }

    /**
     * Monthly recurring charge the active base should bill.
     *
     * Left join so an active account whose plan row was deleted contributes
     * nothing rather than dropping the account out of the total entirely.
     */
    private function expectedMrc(ConnectionInterface $db): float
    {
        return (float) $db->table('billing_accounts as ba')
            ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereRaw("LOWER(COALESCE(bs.status_name, '')) = 'active'")
            ->sum(DB::raw('COALESCE(pl.price, 0)'));
    }

    /**
     * Every barangay, with the billing-status split management asked for.
     *
     * Uncapped, unlike the plan mix beside it: this is a table answering a
     * coverage question, and a top ten drops exactly the thin coverage the
     * question is about. Sorting is left to the client so the same payload
     * serves a table sorted by any column.
     */
    private function barangayBreakdown(ConnectionInterface $db, array $params): array
    {
        $query = $db->table('customers as c')
            ->join('billing_accounts as ba', 'ba.customer_id', '=', 'c.id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereNotNull('c.barangay')
            ->where('c.barangay', '<>', '')
            ->whereRaw(StatusMap::excludeSql('bs.status_name'));

        foreach ([
            'c.region' => $params['geo_region'] ?? '',
            'c.city' => $params['geo_municipality'] ?? '',
        ] as $column => $value) {
            if (trim((string) $value) !== '') {
                $query->where($column, trim((string) $value));
            }
        }

        return $query
            ->selectRaw('c.barangay AS barangay')
            ->selectRaw('c.city AS municipality')
            ->selectRaw('c.region AS province')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(StatusMap::bucketSql('bs.status_name', 'active') . ' AS active')
            ->selectRaw(StatusMap::bucketSql('bs.status_name', 'vip') . ' AS vip')
            ->selectRaw(StatusMap::bucketSql('bs.status_name', 'inactive') . ' AS inactive')
            ->selectRaw(StatusMap::bucketSql('bs.status_name', 'pullout') . ' AS pullout')
            ->selectRaw("COALESCE(SUM(LOWER(TRIM(COALESCE(bs.status_name, ''))) = 'suspended'), 0) AS restricted")
            ->selectRaw(
                "COALESCE(SUM(LOWER(TRIM(COALESCE(bs.status_name, ''))) IN ('overdue', 'expired', 'disconnected')), 0)"
                . ' AS disconnected'
            )
            ->groupBy('c.barangay', 'c.city', 'c.region')
            ->orderBy('c.barangay')
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

    private function newAccounts(ConnectionInterface $db, string $from, string $to): int
    {
        return (int) $db->table('billing_accounts')
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->count();
    }

    /**
     * Accounts carrying a balance, worst first.
     *
     * GOWISER has no expiry date, so "overdue" here means an outstanding
     * balance rather than a lapsed subscription. The ageing buckets are
     * therefore balance bands, not day bands — the frontend reads
     * `bucket_kind` to label the filter correctly instead of showing day
     * ranges that do not exist.
     */
    private function overdueAccounts(ConnectionInterface $db, array $params): array
    {
        $search = trim((string) ($params['overdue_search'] ?? ''));
        $bucket = (string) ($params['overdue_bucket'] ?? '');
        $planId = (int) ($params['overdue_plan_id'] ?? 0);
        $page = max(1, (int) ($params['overdue_page'] ?? 1));

        $filtered = function () use ($db, $search, $bucket, $planId): Builder {
            $query = $db->table('billing_accounts as ba')
                ->join('customers as c', 'c.id', '=', 'ba.customer_id')
                ->where('ba.account_balance', '>', 0);

            if ($search !== '') {
                $like = '%' . $search . '%';

                $query->where(function ($group) use ($like) {
                    $group->where('c.first_name', 'like', $like)
                        ->orWhere('c.last_name', 'like', $like)
                        ->orWhere('ba.account_no', 'like', $like)
                        ->orWhere('c.contact_number_primary', 'like', $like);
                });
            }

            if ($planId > 0) {
                $query->where('ba.plan_id', $planId);
            }

            // Balance bands, mapped onto the same filter keys the NetManager
            // ledger uses so one frontend control drives both.
            switch ($bucket) {
                case '7':
                    $query->where('ba.account_balance', '<=', 1000);
                    break;
                case '8_30':
                    $query->whereBetween('ba.account_balance', [1000.01, 5000]);
                    break;
                case '30':
                    $query->where('ba.account_balance', '>', 5000);
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
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->select(
                'ba.id',
                'ba.account_no',
                'ba.account_balance',
                'ba.balance_update_date',
                'c.first_name',
                'c.last_name',
                'c.contact_number_primary',
                'pl.plan_name',
                'bs.status_name'
            )
            ->orderByDesc('ba.account_balance')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'rows' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'account_number' => (string) ($row->account_no ?? ''),
                'subscriber' => $this->fullName($row->first_name ?? '', $row->last_name ?? ''),
                'contact_number' => (string) ($row->contact_number_primary ?? ''),
                'plan' => (string) ($row->plan_name ?? ''),
                'mrc' => round((float) ($row->account_balance ?? 0), 2),
                'expired_on' => $row->balance_update_date,
                'days_overdue' => null,
                'status' => (string) ($row->status_name ?? ''),
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
            // Only plans that actually have an account in arrears, so the filter
            // never offers an option that returns nothing.
            'plans' => $db->table('plan_list as pl')
                ->join('billing_accounts as ba', 'ba.plan_id', '=', 'pl.id')
                ->where('ba.account_balance', '>', 0)
                ->selectRaw('DISTINCT pl.id, pl.plan_name')
                ->orderBy('pl.plan_name')
                ->get()
                ->map(fn ($row) => ['id' => (int) $row->id, 'label' => (string) $row->plan_name])
                ->all(),
            // Tells the frontend to label the amount column "Balance" and the
            // buckets as bands, not day ranges.
            'bucket_kind' => 'balance',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  FINANCIAL
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Money in, money out, and everything behind both.
     *
     * Income is `transactions`; spending is `expenses_logs`, GOWISER's expenses
     * module. Two rules carried over deliberately:
     *
     *  - `expense_type` is the same reporting-horizon concept NetManager calls
     *    period_type, so the same rule applies: a month's rent booked 'monthly'
     *    must not be charged against a single day. See ReportPeriod::expenseTypes.
     *
     *  - `expenses_logs` is soft-deleted. A deleted row is not spending, and
     *    including it is the classic way this page ends up disagreeing with the
     *    expenses screen the operator is looking at.
     *
     * There is no branch dimension, so `by_branch` is empty and the frontend
     * hides that panel rather than drawing one bar.
     */
    public function financial(ConnectionInterface $db, array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        $trendPeriod = ReportPeriod::normalise($params['period'] ?? null, 'monthly');
        $expensePeriod = ReportPeriod::fromDateRange($from, $to);

        $revenue = $this->revenueStats($db, $from, $to);
        $income = $this->incomeKpi($db, $from, $to);
        $expenses = $this->expenseTotals($db, $expensePeriod, $from, $to);
        $expectedMrc = $this->expectedMrc($db);
        $net = $revenue['total'] - $expenses['total'];

        // Computed once and regrouped, rather than queried again per panel: two
        // queries over the same rows can disagree if one lands between them.
        $byMethod = $this->revenueByMethod($db, $from, $to);
        $byExpenseType = $this->expensesByCategory($db, $expensePeriod, $from, $to);

        $base = $this->subscriberBase($db);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => null,
            'branch_label' => 'All accounts',
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
                'office_by_type' => $this->collectionsByType($db, $from, $to),
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

            'series' => $this->dailySeries($db, $from, $to, $expensePeriod),
            'trend' => [
                'period' => $trendPeriod,
                'points' => $this->trendSeries($db, $trendPeriod, $anchor),
            ],

            // Cash / PNB / Xendit, regrouped from by_method.
            'income_channels' => IncomeChannels::summarise($byMethod),

            'executive_metrics' => ExecutiveMetrics::build(
                $expectedMrc,
                $revenue['total'],
                $base['active'],
                $base['disconnected'],
                $base['lapsed_mrc'],
                $this->rangeLabel($from, $to)
            ),

            'opex_capex' => ExpenseClassifier::opexCapex($byExpenseType),

            'payables' => PayablesLedger::build(
                $this->sourceKey($params),
                $to,
                $this->payableLines($db, $expensePeriod, $from, $to)
            ),

            'by_plan' => $this->revenueByPlan($db, $from, $to),
            'by_method' => $byMethod,
            'by_expense_type' => $byExpenseType,
            'payment_notes' => $this->paymentRemarks($db, $from, $to),

            // No branch dimension in this schema.
            'by_branch' => [
                'period' => ReportPeriod::normalise($params['branch_period'] ?? null, 'monthly'),
                'year' => (int) ($params['branch_year'] ?? $anchor->year),
                'label' => '',
                'rows' => [],
                'years' => $this->paymentYears($db, $anchor->year),
            ],

            'periods' => $this->summaryPeriods($db, $anchor),
        ];
    }

    /**
     * The subscriber base behind the executive metrics.
     *
     * `lapsed_mrc` is the monthly charge carried by accounts that have already
     * disconnected — the revenue genuinely at risk, rather than a headcount times
     * an average, which misstates it wherever plan prices differ.
     */
    private function subscriberBase(ConnectionInterface $db): array
    {
        $lapsed = "LOWER(TRIM(COALESCE(bs.status_name, ''))) IN ('overdue', 'expired', 'disconnected')";

        $row = $db->table('billing_accounts as ba')
            ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->selectRaw("COALESCE(SUM(LOWER(TRIM(COALESCE(bs.status_name, ''))) IN ('active', 'vip')), 0) AS active")
            ->selectRaw("COALESCE(SUM({$lapsed}), 0) AS disconnected")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$lapsed} THEN COALESCE(pl.price, 0) ELSE 0 END), 0) AS lapsed_mrc")
            ->first();

        return [
            'active' => (int) ($row->active ?? 0),
            'disconnected' => (int) ($row->disconnected ?? 0),
            'lapsed_mrc' => (float) ($row->lapsed_mrc ?? 0),
        ];
    }

    /**
     * Payable lines for the range, one per expense category.
     *
     * Grouped by category rather than listed per row for the same reason the
     * NETMANAGER driver does it: an accounts-payable panel is about obligations,
     * and the settlement tick belongs to the obligation for the month, not to an
     * individual ledger entry that may be re-keyed.
     */
    private function payableLines(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to
    ): array {
        $label = "COALESCE(NULLIF(ec.category_name, ''), NULLIF(e.category, ''), '(Uncategorized)')";

        return $this->expenseRows($db, $granularity, $from, $to)
            ->leftJoin('expenses_category as ec', 'ec.id', '=', 'e.category_id')
            ->selectRaw("{$label} AS label")
            ->selectRaw('COALESCE(ec.id, 0) AS category_id')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(e.amount), 0) AS total')
            ->selectRaw('MAX(e.date) AS last_booked')
            ->selectRaw("MAX(LOWER(COALESCE(e.expense_type, 'daily'))) AS period_type")
            ->groupBy('label', 'category_id')
            ->orderByRaw('COALESCE(SUM(e.amount), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                // Falls back to the label when the row carries only the legacy
                // free-text category, so those rows still get a stable key
                // instead of all collapsing onto category 0.
                'ref' => (int) $row->category_id > 0
                    ? 'category:' . (int) $row->category_id
                    : 'category-name:' . strtolower(trim((string) $row->label)),
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
     * Travels in the params because a driver is handed a connection, not a key.
     * The payables settlement table is keyed per source: two companies both owe
     * rent, and one paying it does not settle the other's.
     */
    private function sourceKey(array $params): string
    {
        $key = trim((string) ($params['source_key'] ?? ''));

        return $key !== '' ? $key : 'gowiser';
    }

    /** Count, total, average and largest collection in the range. */
    private function revenueStats(ConnectionInterface $db, string $from, string $to): array
    {
        $row = $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS total')
            ->selectRaw('COALESCE(AVG(t.received_payment), 0) AS avg_amount')
            ->selectRaw('COALESCE(MAX(t.received_payment), 0) AS max_amount')
            ->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'total' => (float) ($row->total ?? 0),
            'average' => (float) ($row->avg_amount ?? 0),
            'largest' => (float) ($row->max_amount ?? 0),
        ];
    }

    /**
     * Income split into over-the-counter and online-portal collections.
     *
     * GOWISER has no payment_methods lookup carrying a "PORTAL" remark the way
     * NetManager does, so the split is decided on the method name itself. Kept
     * broad on purpose — portal payments arrive under several gateway names, and
     * under-counting them would silently inflate office collections.
     */
    private function incomeKpi(ConnectionInterface $db, string $from, string $to): array
    {
        $isPortal = "UPPER(COALESCE(t.payment_method, '')) REGEXP 'PORTAL|ONLINE|GCASH|MAYA|PAYMAYA|BANK|E-?WALLET'";

        $row = $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS income')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isPortal} THEN t.received_payment ELSE 0 END), 0) AS portal_income")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isPortal} THEN 1 ELSE 0 END), 0) AS portal_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$isPortal} THEN 0 ELSE t.received_payment END), 0) AS office_income")
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

    /** Collections itemised by charge type. */
    private function collectionsByType(ConnectionInterface $db, string $from, string $to): array
    {
        return $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->selectRaw("COALESCE(NULLIF(t.transaction_type, ''), 'Subscription') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS total')
            ->groupBy('label')
            ->orderByRaw('COALESCE(SUM(t.received_payment), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    private function revenueByPlan(ConnectionInterface $db, string $from, string $to): array
    {
        // Joined through the account, so revenue is attributed to the plan the
        // subscriber is actually billed on rather than the one they applied for.
        return $this->collectedTransactions($db)
            ->join('billing_accounts as ba', 'ba.account_no', '=', 't.account_no')
            ->join('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->selectRaw('pl.plan_name AS label')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS total')
            ->groupBy('pl.id', 'pl.plan_name')
            ->orderByRaw('COALESCE(SUM(t.received_payment), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    private function revenueByMethod(ConnectionInterface $db, string $from, string $to): array
    {
        return $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->selectRaw("COALESCE(NULLIF(t.payment_method, ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS total')
            ->groupBy('label')
            ->orderByRaw('COALESCE(SUM(t.received_payment), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Collections grouped by the free-text remark the cashier typed.
     *
     * The GOWISER equivalent of NetManager's payment notes: cashiers use it for
     * promo and adjustment tags, so grouping on it is how those get totalled.
     */
    private function paymentRemarks(ConnectionInterface $db, string $from, string $to): array
    {
        return $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->whereNotNull('t.remarks')
            ->where('t.remarks', '<>', '')
            ->selectRaw('t.remarks AS label')
            ->selectRaw('COUNT(*) AS note_count')
            ->selectRaw('COUNT(DISTINCT t.account_no) AS subscriber_count')
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS total')
            ->groupBy('t.remarks')
            ->orderByRaw('COALESCE(SUM(t.received_payment), 0) DESC')
            ->get()
            // Shared label/count/total shape; `count` is subscribers, with the
            // payment count in `detail` since one account can pay twice.
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->subscriber_count,
                'total' => round((float) $row->total, 2),
                'detail' => (int) $row->note_count === 1
                    ? '1 payment'
                    : number_format((int) $row->note_count) . ' payments',
            ])
            ->all();
    }

    /** Years that actually have collections, so the year filter offers no dead options. */
    private function paymentYears(ConnectionInterface $db, int $currentYear): array
    {
        $years = $this->collectedTransactions($db)
            ->selectRaw('DISTINCT YEAR(t.payment_date) AS yr')
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

    // ── Expenses ─────────────────────────────────────────────────────────

    /**
     * Expense rows for a range, restricted to the horizons that belong in a
     * report of this granularity, and excluding soft-deleted rows.
     *
     * `expense_type` is GOWISER's name for NetManager's period_type and carries
     * the same meaning, so the same rule governs both: a longer report absorbs
     * the shorter horizons, a shorter one never absorbs the longer.
     */
    private function expenseRows(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to
    ): Builder {
        return $db->table('expenses_logs as e')
            ->whereNull('e.deleted_at')
            ->whereBetween(DB::raw('DATE(e.date)'), [$from, $to])
            ->whereIn(
                DB::raw("LOWER(COALESCE(e.expense_type, 'daily'))"),
                ReportPeriod::expenseTypes($granularity)
            );
    }

    private function expenseTotals(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to
    ): array {
        $row = $this->expenseRows($db, $granularity, $from, $to)
            ->selectRaw('COALESCE(SUM(e.amount), 0) AS total')
            ->selectRaw('COUNT(*) AS cnt')
            ->first();

        return [
            'total' => (float) ($row->total ?? 0),
            'count' => (int) ($row->cnt ?? 0),
        ];
    }

    /**
     * Spending by category.
     *
     * Prefers the `expenses_category` row via category_id and falls back to the
     * free-text `category` column, because rows written before the expenses
     * module was added carry only the string.
     */
    private function expensesByCategory(
        ConnectionInterface $db,
        string $granularity,
        string $from,
        string $to
    ): array {
        return $this->expenseRows($db, $granularity, $from, $to)
            ->leftJoin('expenses_category as ec', 'ec.id', '=', 'e.category_id')
            ->selectRaw("COALESCE(NULLIF(ec.category_name, ''), NULLIF(e.category, ''), '(Uncategorized)') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(e.amount), 0) AS total')
            ->groupBy('label')
            ->orderByRaw('COALESCE(SUM(e.amount), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    // ── Series ───────────────────────────────────────────────────────────

    /**
     * Day-by-day income and expenses across the range.
     *
     * Both sides are unioned on the date key so a day with expenses but no
     * collections still plots — otherwise a loss-making day silently vanishes.
     */
    private function dailySeries(
        ConnectionInterface $db,
        string $from,
        string $to,
        string $expensePeriod
    ): array {
        $income = $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->selectRaw("DATE_FORMAT(t.payment_date, '%Y-%m-%d') AS day")
            ->selectRaw('SUM(t.received_payment) AS total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $expenses = $this->expenseRows($db, $expensePeriod, $from, $to)
            ->selectRaw("DATE_FORMAT(e.date, '%Y-%m-%d') AS day")
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

    /**
     * Income, expenses and net on one timeline. Buckets follow the same horizons
     * the NetManager driver uses, so the two systems' charts read alike.
     */
    private function trendSeries(ConnectionInterface $db, string $granularity, $anchor): array
    {
        [$from, $to] = $this->trendBounds($granularity, $anchor);
        [$bucketFor, $labelFor] = $this->trendExpressions($granularity);

        $incomeQuery = $this->collectedTransactions($db);

        if ($from !== null) {
            $incomeQuery->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to]);
        }

        $income = $incomeQuery
            ->selectRaw($bucketFor('t.payment_date') . ' AS bucket')
            ->selectRaw($labelFor('t.payment_date') . ' AS label')
            ->selectRaw('SUM(t.received_payment) AS total')
            ->groupBy('bucket', 'label')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $expenseQuery = $db->table('expenses_logs as e')
            ->whereNull('e.deleted_at')
            ->whereIn(
                DB::raw("LOWER(COALESCE(e.expense_type, 'daily'))"),
                ReportPeriod::expenseTypes($granularity)
            );

        if ($from !== null) {
            $expenseQuery->whereBetween(DB::raw('DATE(e.date)'), [$from, $to]);
        }

        $expenses = $expenseQuery
            ->selectRaw($bucketFor('e.date') . ' AS bucket')
            ->selectRaw($labelFor('e.date') . ' AS label')
            ->selectRaw('SUM(e.amount) AS total')
            ->groupBy('bucket', 'label')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $buckets = $income->keys()->merge($expenses->keys())->unique()->sort()->values();

        if ($granularity === 'yearly') {
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
     * How far back the trend reaches. Yearly returns null so the whole history is
     * scanned and then trimmed to the ten most recent buckets.
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
     * Bucket key and display label expressions for a date column. The key must
     * sort lexicographically in chronological order, which is why weekly
     * zero-pads its week number.
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
     * The four horizons side by side, anchored on one date.
     *
     * Weekly here is the calendar week, matching the summary module rather than
     * the trend's rolling bucket — the two source modules differ and both are
     * reproduced as-is.
     */
    private function summaryPeriods(ConnectionInterface $db, $anchor): array
    {
        $periods = [];

        foreach (ReportPeriod::summaryWindows($anchor->toDateString()) as $key => $window) {
            $revenue = $this->revenueStats($db, $window['from'], $window['to']);
            $expenses = $this->expenseTotals($db, $key, $window['from'], $window['to']);
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
                'ratio_pct' => $revenue['total'] > 0
                    ? round(abs($net) / $revenue['total'] * 100, 1)
                    : null,
            ];
        }

        return $periods;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  PRINTABLE
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Line-level data behind the three print layouts.
     *
     * One request rather than three because the Financial Report prints both
     * ledgers together, and fetching them separately risks the two halves of one
     * printed page coming from different moments.
     */
    public function printable(ConnectionInterface $db, string $from, string $to, $branch = null): array
    {
        $expensePeriod = ReportPeriod::fromDateRange($from, $to);

        $payments = $this->paymentLines($db, $from, $to);
        $expenses = $this->expenseLines($db, $expensePeriod, $from, $to);

        $income = array_sum(array_column($payments, 'amount'));
        $spend = array_sum(array_column($expenses, 'amount'));

        return [
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),
            'expense_period' => $expensePeriod,
            'branch' => null,
            'branch_label' => 'All accounts',
            'generated_at' => now()->toDateTimeString(),
            'company' => $this->company($db),
            'payments' => $payments,
            'expenses' => $expenses,
            'payment_notes' => $this->paymentRemarks($db, $from, $to),
            'totals' => [
                'income' => round($income, 2),
                'income_count' => count($payments),
                'expenses' => round($spend, 2),
                'expenses_count' => count($expenses),
                'net' => round($income - $spend, 2),
            ],
        ];
    }

    private function paymentLines(ConnectionInterface $db, string $from, string $to): array
    {
        return $this->collectedTransactions($db)
            ->leftJoin('billing_accounts as ba', 'ba.account_no', '=', 't.account_no')
            ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->select(
                't.id',
                't.payment_date',
                't.or_no',
                't.received_payment',
                't.payment_method',
                't.transaction_type',
                't.status',
                't.account_no',
                't.processed_by_user',
                'c.first_name',
                'c.last_name'
            )
            ->orderBy('t.payment_date')
            ->orderBy('t.id')
            ->get()
            ->map(fn ($row) => [
                'or_number' => (string) ($row->or_no ?? ''),
                'account_number' => (string) ($row->account_no ?? ''),
                'subscriber' => $this->fullName($row->first_name ?? '', $row->last_name ?? ''),
                'type' => (string) ($row->transaction_type ?? 'Subscription'),
                'method' => (string) ($row->payment_method ?? ''),
                'status' => strtolower((string) ($row->status ?? '')),
                'amount' => round((float) $row->received_payment, 2),
                'payment_date' => $row->payment_date,
                'cashier' => (string) ($row->processed_by_user ?? ''),
            ])
            ->all();
    }

    private function expenseLines(
        ConnectionInterface $db,
        string $expensePeriod,
        string $from,
        string $to
    ): array {
        return $this->expenseRows($db, $expensePeriod, $from, $to)
            ->leftJoin('expenses_category as ec', 'ec.id', '=', 'e.category_id')
            ->select(
                'e.id',
                'e.date',
                'e.payee',
                'e.provider',
                'e.supplier',
                'e.amount',
                'e.description',
                'e.expense_type',
                'e.category',
                'e.processed_by',
                'ec.category_name'
            )
            ->orderBy('e.date')
            ->orderBy('e.id')
            ->get()
            ->map(fn ($row) => [
                'expense_date' => $row->date,
                'type' => (string) ($row->category_name ?: $row->category ?: '(Uncategorized)'),
                // Payee first, then whoever the money actually went to. The
                // reference report's column is "Employee / Payee", and a blank
                // one on a signed document is worse than a supplier name.
                'employee' => (string) ($row->payee ?: $row->supplier ?: $row->provider ?: ''),
                'remark' => (string) ($row->description ?? ''),
                'period_type' => strtolower((string) ($row->expense_type ?? 'daily')),
                'amount' => round((float) $row->amount, 2),
                'recorded_by' => (string) ($row->processed_by ?? ''),
            ])
            ->all();
    }

    /**
     * Company header for the print layouts.
     *
     * GOWISER keeps no settings table of the kind NetManager has, so these are
     * the registration details as printed on its own receipts. Overridable per
     * deployment through config/reporting.php rather than hardcoded at the call
     * site, so a second operating company does not need a code change.
     */
    private function company(ConnectionInterface $db): array
    {
        $company = config('reporting.company', []);

        return [
            'name' => $company['name'] ?? 'GO WISER CORPORATION',
            'description' => $company['description'] ?? 'Internet Service Provider',
            'address' => $company['address'] ?? '',
            'contact' => $company['contact'] ?? '',
            'email' => $company['email'] ?? '',
            'tin' => $company['tin'] ?? '',
            'logo' => $company['logo'] ?? '',
            'currency_symbol' => $company['currency_symbol'] ?? '₱',
            'manager' => $company['manager'] ?? '',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  OPERATIONS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Field delivery across all three GOWISER work queues.
     *
     * Applications, job orders and service orders are genuinely different kinds
     * of work — a sales enquiry, a new connection, a repair — so they are
     * reported as three queues rather than summed into one meaningless total.
     */
    public function operations(ConnectionInterface $db, array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => null,
            'branch_label' => 'All accounts',
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            'queues' => [
                [
                    'key' => 'applications',
                    'label' => 'Applications',
                    'statuses' => $this->queueStatuses($db, 'applications', 'status', 'timestamp', $from, $to),
                    'backlog' => $this->queueBacklog($db, 'applications', 'status', 'timestamp'),
                ],
                [
                    'key' => 'job_orders',
                    'label' => 'Job Orders',
                    'statuses' => $this->queueStatuses($db, 'job_orders', 'onsite_status', 'timestamp', $from, $to),
                    'backlog' => $this->queueBacklog($db, 'job_orders', 'onsite_status', 'timestamp'),
                ],
                [
                    'key' => 'service_orders',
                    'label' => 'Service Orders',
                    'statuses' => $this->queueStatuses($db, 'service_orders', 'support_status', 'timestamp', $from, $to),
                    'backlog' => $this->queueBacklog($db, 'service_orders', 'support_status', 'timestamp'),
                ],
            ],
            'series' => $this->operationsSeries($db, $from, $to),
            'turnaround' => $this->operationsTurnaround($db, $from, $to),

            // Average completion time per work-order type. The headline
            // turnaround above answers "how long does a job take"; this answers
            // "which kind of job is the slow one", which is the question that
            // leads somewhere.
            'turnaround_by_type' => $this->turnaroundByType($db, $from, $to),

            'concerns' => $this->serviceOrderConcerns($db, $from, $to),
            'repair_categories' => $this->serviceOrderRepairs($db, $from, $to),
            'recent' => $this->recentJobOrders($db),
            'has_service_orders' => true,
        ];
    }

    /** Rows opened in the range, grouped by their current status. */
    private function queueStatuses(
        ConnectionInterface $db,
        string $table,
        string $statusColumn,
        string $dateColumn,
        string $from,
        string $to
    ): array {
        return $db->table($table)
            ->whereBetween(DB::raw("DATE({$dateColumn})"), [$from, $to])
            ->selectRaw("COALESCE(NULLIF({$statusColumn}, ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->cnt])
            ->all();
    }

    /**
     * Still-open work, ignoring the date range for the same reason the
     * NetManager driver does: old backlog is still backlog.
     */
    private function queueBacklog(
        ConnectionInterface $db,
        string $table,
        string $statusColumn,
        string $dateColumn
    ): array {
        $closed = $this->quotedClosedStates();

        $row = $db->table($table)
            ->whereRaw("LOWER(COALESCE({$statusColumn}, '')) NOT IN ({$closed})")
            ->whereRaw("LOWER(COALESCE({$statusColumn}, '')) NOT IN ('cancelled', 'duplicate')")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw("MIN({$dateColumn}) AS oldest")
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

    /** Job orders opened and completed per day across the range. */
    private function operationsSeries(ConnectionInterface $db, string $from, string $to): array
    {
        $closed = $this->quotedClosedStates();

        $opened = $db->table('job_orders')
            ->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to])
            ->selectRaw("DATE_FORMAT(timestamp, '%Y-%m-%d') AS day")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $completed = $db->table('job_orders')
            ->whereBetween(DB::raw('DATE(date_installed)'), [$from, $to])
            ->whereRaw("LOWER(COALESCE(onsite_status, '')) IN ({$closed})")
            ->selectRaw("DATE_FORMAT(date_installed, '%Y-%m-%d') AS day")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = $opened->keys()->merge($completed->keys())->unique()->sort()->values();

        return $days->map(fn ($day) => [
            'period' => (string) $day,
            'label' => ReportPeriod::parse((string) $day)?->format('M d') ?? (string) $day,
            'opened' => (int) ($opened->get($day)->cnt ?? 0),
            'closed' => (int) ($completed->get($day)->cnt ?? 0),
        ])->all();
    }

    /**
     * How long onsite work took.
     *
     * Uses start_time/end_time, which the technician's app stamps — the actual
     * time on site, not the age of the ticket. Rows missing either stamp are
     * excluded rather than treated as zero-duration.
     */
    private function operationsTurnaround(ConnectionInterface $db, string $from, string $to): array
    {
        $measure = function (string $table) use ($db, $from, $to) {
            return $db->table($table)
                ->whereBetween(DB::raw('DATE(end_time)'), [$from, $to])
                ->whereNotNull('start_time')
                ->whereNotNull('end_time')
                ->whereRaw('end_time >= start_time')
                ->selectRaw('COUNT(*) AS cnt')
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS avg_minutes')
                ->selectRaw('MAX(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS max_minutes')
                ->first();
        };

        $jobs = $measure('job_orders');
        $services = $measure('service_orders');

        // Read through ?? rather than comparing $row->x against null: an aggregate
        // over no matching rows yields NULL columns, and first() itself can be
        // null. ?? is safe on both; a direct dereference is not.
        $shape = function ($row): array {
            $average = $row->avg_minutes ?? null;
            $longest = $row->max_minutes ?? null;

            return [
                'closed' => (int) ($row->cnt ?? 0),
                'average_minutes' => $average !== null ? round((float) $average, 1) : null,
                'longest_minutes' => $longest !== null ? (int) $longest : null,
            ];
        };

        return [
            'job_orders' => $shape($jobs),
            'service_orders' => $shape($services),
        ];
    }

    /**
     * Average time on site, segmented by the type of work order.
     *
     * Job orders are new connections and service orders are repairs, and they
     * genuinely take different amounts of time — so a single blended average
     * tells a field manager nothing about which queue is slipping. Service orders
     * are split further by repair category where the row carries one, which is
     * where the actionable difference usually is.
     *
     * Minutes, from the technician app's own start/end stamps, consistent with
     * operationsTurnaround. Rows missing either stamp are excluded rather than
     * counted as instantaneous.
     */
    private function turnaroundByType(ConnectionInterface $db, string $from, string $to): array
    {
        // Read through ?? throughout: an aggregate over no matching rows yields
        // NULL columns and first() itself can be null, so a direct dereference is
        // not safe here even though the callers guard on the count.
        $shape = function ($row, string $label, ?string $group = null): array {
            $average = $row->avg_minutes ?? null;
            $longest = $row->max_minutes ?? null;

            return [
                'label' => $label,
                'group' => $group,
                'closed' => (int) ($row->cnt ?? 0),
                'average_minutes' => $average !== null ? round((float) $average, 1) : null,
                'longest_minutes' => $longest !== null ? (int) $longest : null,
                'unit' => 'minutes',
            ];
        };

        $measured = fn (string $table) => $db->table($table)
            ->whereBetween(DB::raw('DATE(end_time)'), [$from, $to])
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereRaw('end_time >= start_time');

        $rows = [];

        $jobs = $measured('job_orders')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS avg_minutes')
            ->selectRaw('MAX(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS max_minutes')
            ->first();

        if ((int) ($jobs->cnt ?? 0) > 0) {
            $rows[] = $shape($jobs, 'Job Orders (new connections)', 'job_orders');
        }

        $services = $measured('service_orders')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS avg_minutes')
            ->selectRaw('MAX(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS max_minutes')
            ->first();

        if ((int) ($services->cnt ?? 0) > 0) {
            $rows[] = $shape($services, 'Service Orders (repairs)', 'service_orders');
        }

        $byCategory = $measured('service_orders')
            ->whereNotNull('repair_category')
            ->where('repair_category', '<>', '')
            ->selectRaw('repair_category AS label')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS avg_minutes')
            ->selectRaw('MAX(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS max_minutes')
            ->groupBy('label')
            ->orderByRaw('AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) DESC')
            ->limit(self::TOP_N)
            ->get();

        foreach ($byCategory as $row) {
            $rows[] = $shape($row, (string) $row->label, 'service_orders');
        }

        return $rows;
    }

    private function serviceOrderConcerns(ConnectionInterface $db, string $from, string $to): array
    {
        return $this->labelledCount($db, 'service_orders', 'concern', $from, $to);
    }

    private function serviceOrderRepairs(ConnectionInterface $db, string $from, string $to): array
    {
        return $this->labelledCount($db, 'service_orders', 'repair_category', $from, $to);
    }

    private function labelledCount(
        ConnectionInterface $db,
        string $table,
        string $column,
        string $from,
        string $to
    ): array {
        return $db->table($table)
            ->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to])
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->selectRaw("{$column} AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->cnt])
            ->all();
    }

    private function recentJobOrders(ConnectionInterface $db): array
    {
        return $db->table('job_orders as jo')
            ->leftJoin('billing_accounts as ba', 'ba.id', '=', 'jo.account_id')
            ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
            ->select(
                'jo.id',
                'jo.onsite_status',
                'jo.onsite_remarks',
                'jo.timestamp',
                'jo.date_installed',
                'jo.technicians',
                'jo.assigned_email',
                'jo.visit_by',
                'ba.account_no',
                'c.first_name',
                'c.last_name',
                'c.barangay',
                'c.city',
                'c.desired_plan'
            )
            ->orderByDesc('jo.timestamp')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'status' => (string) ($row->onsite_status ?? ''),
                'remark' => (string) ($row->onsite_remarks ?? ''),
                'account_number' => (string) ($row->account_no ?? ''),
                'subscriber' => $this->fullName($row->first_name ?? '', $row->last_name ?? ''),
                'location' => $this->joinLocation([$row->barangay ?? null, $row->city ?? null]),
                'plan' => (string) ($row->desired_plan ?? ''),
                'assignee' => $this->technicianLabel($row->technicians ?? null, $row->visit_by ?? $row->assigned_email ?? ''),
                'opened_at' => $row->timestamp,
                'updated_at' => $row->date_installed,
            ])
            ->all();
    }

    // ═════════════════════════════════════════════════════════════════════
    //  TECH
    // ═════════════════════════════════════════════════════════════════════

    /**
     * The technician roster and its workload.
     *
     * Attribution is the awkward part. GOWISER records who did a job in three
     * different ways depending on which app wrote the row: a JSON `technicians`
     * blob, a `visit_by` name, or an `assigned_email`. Rather than pick one and
     * under-report the rest, the workload table is built from the technician
     * roster and each name is matched against all three — see technicianWorkload().
     */
    public function tech(ConnectionInterface $db, array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        $roster = $this->technicianRoster($db);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            'roster' => $roster,
            'roster_count' => count($roster),
            'workload' => $this->technicianWorkload($db, $roster, $from, $to),
            'locations' => $this->technicianLocations($db),
            'unattributed' => $this->unattributedWork($db, $from, $to),
            'turnaround' => $this->operationsTurnaround($db, $from, $to),
        ];
    }

    private function technicianRoster(ConnectionInterface $db): array
    {
        return $db->table('technicians')
            ->select('id', 'first_name', 'middle_initial', 'last_name', 'updated_at')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $this->fullName($row->first_name ?? '', $row->last_name ?? ''),
                'initial' => (string) ($row->middle_initial ?? ''),
                'updated_at' => $row->updated_at,
            ])
            ->filter(fn (array $row) => $row['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * Jobs and services each technician appears on, in the range.
     *
     * One query per queue rather than per technician: the roster is small but a
     * per-technician query would be N round trips, and the whole point of the
     * three-way match below is that it happens in PHP where all three shapes can
     * be reconciled.
     *
     * Matching is on the technician's name appearing in the row's attribution
     * fields. That is a substring match, which is imprecise by nature — two
     * technicians sharing a surname can both match one row. `match_quality`
     * reports that so the number is read with the right confidence rather than
     * as an exact count.
     */
    private function technicianWorkload(ConnectionInterface $db, array $roster, string $from, string $to): array
    {
        if ($roster === []) {
            return [];
        }

        $closed = $this->quotedClosedStates();

        $jobs = $db->table('job_orders')
            ->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to])
            ->selectRaw('technicians, visit_by, assigned_email, onsite_status AS status')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, start_time, end_time) AS minutes')
            ->get();

        $services = $db->table('service_orders')
            ->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to])
            ->selectRaw('technicians, visit_by_user AS visit_by, assigned_email, support_status AS status')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, start_time, end_time) AS minutes')
            ->get();

        $closedStates = self::CLOSED_STATES;

        $tally = function ($rows, string $name) use ($closedStates): array {
            $total = 0;
            $done = 0;
            $minutes = [];

            foreach ($rows as $row) {
                if (!$this->rowMentions($row, $name)) {
                    continue;
                }

                $total++;

                if (in_array(strtolower(trim((string) ($row->status ?? ''))), $closedStates, true)) {
                    $done++;
                }

                if ($row->minutes !== null && (int) $row->minutes >= 0) {
                    $minutes[] = (int) $row->minutes;
                }
            }

            return [
                'total' => $total,
                'done' => $done,
                'average_minutes' => $minutes ? round(array_sum($minutes) / count($minutes), 1) : null,
            ];
        };

        $workload = [];

        foreach ($roster as $technician) {
            $jobTally = $tally($jobs, $technician['name']);
            $serviceTally = $tally($services, $technician['name']);

            $total = $jobTally['total'] + $serviceTally['total'];

            $workload[] = [
                'id' => $technician['id'],
                'name' => $technician['name'],
                'job_orders' => $jobTally['total'],
                'job_orders_done' => $jobTally['done'],
                'service_orders' => $serviceTally['total'],
                'service_orders_done' => $serviceTally['done'],
                'total' => $total,
                'completed' => $jobTally['done'] + $serviceTally['done'],
                'average_minutes' => $this->mergeAverages(
                    [$jobTally['average_minutes'], $jobTally['total']],
                    [$serviceTally['average_minutes'], $serviceTally['total']]
                ),
            ];
        }

        usort($workload, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $workload;
    }

    /**
     * Whether a work row names this technician, across all three attribution
     * shapes GOWISER uses.
     *
     * `technicians` is a JSON blob in newer rows and a plain comma list in older
     * ones, so it is searched as text either way — decoding would fail on half
     * the table.
     */
    private function rowMentions($row, string $name): bool
    {
        $needle = strtolower(trim($name));

        if ($needle === '') {
            return false;
        }

        foreach (['technicians', 'visit_by', 'assigned_email'] as $field) {
            $value = strtolower(trim((string) ($row->{$field} ?? '')));

            if ($value !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Weighted mean of two averages, so the combined figure is not skewed. */
    private function mergeAverages(array $first, array $second): ?float
    {
        [$firstAvg, $firstCount] = $first;
        [$secondAvg, $secondCount] = $second;

        $weight = 0;
        $sum = 0.0;

        if ($firstAvg !== null && $firstCount > 0) {
            $sum += $firstAvg * $firstCount;
            $weight += $firstCount;
        }

        if ($secondAvg !== null && $secondCount > 0) {
            $sum += $secondAvg * $secondCount;
            $weight += $secondCount;
        }

        return $weight > 0 ? round($sum / $weight, 1) : null;
    }

    /**
     * Last known field position per technician.
     *
     * `status` in the table is what the device last reported, which keeps saying
     * "online" after a phone loses signal. Freshness is therefore derived from
     * last_updated_at here rather than trusted from the column — a fifteen
     * minute old fix is not a live one.
     */
    private function technicianLocations(ConnectionInterface $db): array
    {
        $rows = $db->table('technician_locations as tl')
            ->leftJoin('users as u', 'u.id', '=', 'tl.user_id')
            ->select(
                'tl.user_id',
                'tl.latitude',
                'tl.longitude',
                'tl.accuracy',
                'tl.speed',
                'tl.status',
                'tl.last_updated_at',
                'u.first_name',
                'u.last_name',
                'u.email_address'
            )
            ->orderByDesc('tl.last_updated_at')
            ->get();

        $cutoff = Carbon::now()->subMinutes(self::LOCATION_STALE_MINUTES);

        return $rows->map(function ($row) use ($cutoff) {
            $seenAt = $row->last_updated_at ? Carbon::parse($row->last_updated_at) : null;
            $fresh = $seenAt !== null && $seenAt->greaterThanOrEqualTo($cutoff);

            return [
                'user_id' => (string) $row->user_id,
                'name' => $this->fullName($row->first_name ?? '', $row->last_name ?? '')
                    ?: (string) ($row->email_address ?? 'Unknown'),
                'latitude' => $row->latitude !== null ? (float) $row->latitude : null,
                'longitude' => $row->longitude !== null ? (float) $row->longitude : null,
                'accuracy_m' => $row->accuracy !== null ? (float) $row->accuracy : null,
                'speed' => $row->speed !== null ? (float) $row->speed : null,
                'reported_status' => (string) ($row->status ?? ''),
                'last_seen_at' => $row->last_updated_at,
                'minutes_ago' => $seenAt ? $seenAt->diffInMinutes(Carbon::now()) : null,
                'is_live' => $fresh,
            ];
        })->all();
    }

    /**
     * Work in the range with nobody recorded against it.
     *
     * Surfaced deliberately: it is the number that tells you the per-technician
     * figures above are incomplete, and hiding it would make a partial table
     * look authoritative.
     */
    private function unattributedWork(ConnectionInterface $db, string $from, string $to): array
    {
        $blank = function (string $table, array $fields) use ($db, $from, $to): int {
            $query = $db->table($table)->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to]);

            foreach ($fields as $field) {
                $query->whereRaw("COALESCE(NULLIF(TRIM({$field}), ''), '') = ''");
            }

            return (int) $query->count();
        };

        return [
            'job_orders' => $blank('job_orders', ['technicians', 'visit_by', 'assigned_email']),
            'service_orders' => $blank('service_orders', ['technicians', 'visit_by_user', 'assigned_email']),
        ];
    }

    /** A readable assignee from whichever attribution field is populated. */
    private function technicianLabel($technicians, string $fallback): string
    {
        $raw = trim((string) ($technicians ?? ''));

        if ($raw !== '') {
            // Newer rows hold JSON; older ones a plain list. Try JSON, fall back
            // to the raw string rather than printing "[" to the user.
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $names = array_filter(array_map(
                    fn ($item) => is_array($item)
                        ? trim((string) ($item['name'] ?? ''))
                        : trim((string) $item),
                    $decoded
                ));

                if ($names) {
                    return implode(', ', $names);
                }
            } elseif ($raw[0] !== '[' && $raw[0] !== '{') {
                return $raw;
            }
        }

        return trim($fallback);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  EMPLOYEE
    // ═════════════════════════════════════════════════════════════════════

    public function employee(ConnectionInterface $db, array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);
        [$from, $to] = $this->range($params);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => null,
            'branch_label' => 'All accounts',
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            'roster' => $this->staffRoster($db),
            'by_role' => $this->staffByRole($db),
            'collections' => $this->collectionsByProcessor($db, $from, $to),
            'field_work' => $this->workByAssignedUser($db, $from, $to),
            'payees' => [],
            // GOWISER has no expense ledger, so there is no payee list. Said
            // explicitly so the frontend omits the panel rather than showing an
            // empty one that looks like a data problem.
            'supports_payees' => false,
        ];
    }

    /**
     * Drops non-staff roles from an Employee-section query.
     *
     * Subscribers and employees share the `users` table here, so a roster that
     * does not filter is overwhelmingly customers.
     *
     * COALESCE is load-bearing: a user with no role has role_name NULL, and
     * `NULL NOT IN (...)` evaluates to NULL rather than true, so an unfiltered
     * comparison would silently drop exactly the accounts most worth noticing.
     * Excluded roles are bound, never interpolated.
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

    private function staffRoster(ConnectionInterface $db): array
    {
        $query = $db->table('users as u')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id');

        return $this->excludeNonStaff($query, 'r.role_name')
            ->select(
                'u.id',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email_address',
                'u.active',
                'u.last_login',
                'r.role_name'
            )
            ->orderBy('r.role_name')
            ->orderBy('u.first_name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $this->fullName($row->first_name ?? '', $row->last_name ?? '') ?: (string) $row->username,
                'username' => (string) ($row->username ?? ''),
                'email' => (string) ($row->email_address ?? ''),
                'role' => (string) ($row->role_name ?? ''),
                'branch' => '',
                'active' => (bool) ($row->active ?? false),
                'last_login' => $row->last_login,
            ])
            ->all();
    }

    private function staffByRole(ConnectionInterface $db): array
    {
        $query = $db->table('users as u')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id');

        return $this->excludeNonStaff($query, 'r.role_name')
            ->selectRaw("COALESCE(NULLIF(r.role_name, ''), 'Unassigned') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(u.active = 1), 0) AS active')
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
     * Collections credited to the user who processed them.
     *
     * `processed_by_user` is an email string rather than a foreign key, so it is
     * joined to users on the address to recover a display name, and falls back
     * to the raw value when no account matches.
     */
    private function collectionsByProcessor(ConnectionInterface $db, string $from, string $to): array
    {
        $name = "TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))";

        return $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.payment_date)'), [$from, $to])
            ->leftJoin('users as u', DB::raw('LOWER(u.email_address)'), '=', DB::raw('LOWER(t.processed_by_user)'))
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->selectRaw("COALESCE(NULLIF({$name}, ''), NULLIF(t.processed_by_user, ''), '(unattributed)') AS label")
            ->selectRaw("COALESCE(NULLIF(r.role_name, ''), '') AS role")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS total')
            ->groupBy('label', 'role')
            ->orderByRaw('COALESCE(SUM(t.received_payment), 0) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'role' => (string) $row->role,
                'count' => (int) $row->cnt,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /** Job orders per assigned user account, in the range. */
    private function workByAssignedUser(ConnectionInterface $db, string $from, string $to): array
    {
        $closed = $this->quotedClosedStates();
        $name = "TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))";

        return $db->table('job_orders as jo')
            ->whereBetween(DB::raw('DATE(jo.timestamp)'), [$from, $to])
            ->leftJoin('users as u', DB::raw('LOWER(u.email_address)'), '=', DB::raw('LOWER(jo.assigned_email)'))
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->selectRaw("COALESCE(NULLIF({$name}, ''), NULLIF(jo.assigned_email, ''), '(unassigned)') AS label")
            ->selectRaw("COALESCE(NULLIF(r.role_name, ''), '') AS role")
            ->selectRaw('COUNT(*) AS assigned')
            ->selectRaw("COALESCE(SUM(LOWER(COALESCE(jo.onsite_status, '')) IN ({$closed})), 0) AS completed")
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, jo.start_time, jo.end_time)) AS avg_minutes')
            ->groupBy('label', 'role')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'role' => (string) $row->role,
                'assigned' => (int) $row->assigned,
                'completed' => (int) $row->completed,
                // Minutes here, not hours: GOWISER stamps actual time on site,
                // which is a job of minutes rather than the days a ticket ages.
                'average_hours' => ($row->avg_minutes ?? null) !== null
                    ? round((float) $row->avg_minutes / 60, 2)
                    : null,
            ])
            ->all();
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SHARED
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Money actually collected.
     *
     * Cancelled and still-pending rows are not revenue, and counting them is the
     * classic way a dashboard ends up disagreeing with finance. Matches
     * GowiserDriver::collectedPayments so the two never diverge.
     */
    private function collectedTransactions(ConnectionInterface $db): Builder
    {
        return $db->table('transactions as t')
            ->whereNotNull('t.payment_date')
            ->whereNotNull('t.received_payment')
            ->where(function ($query) {
                $query->whereNull('t.status')
                    ->orWhereNotIn(DB::raw('LOWER(t.status)'), ['cancelled', 'pending', 'voided']);
            });
    }

    /** CLOSED_STATES as a quoted SQL list. Values are class constants, not input. */
    private function quotedClosedStates(): string
    {
        return implode(', ', array_map(
            fn (string $state) => "'" . $state . "'",
            self::CLOSED_STATES
        ));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function range(array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);

        $from = ReportPeriod::parse($params['date_from'] ?? null) ?? $anchor->copy()->startOfMonth();
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
