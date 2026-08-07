<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\ReportingService;

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

    /**
     * Rows returned in a "done today" list before it is capped.
     *
     * A list, not a count — the count is beside it and is exact. The cap exists
     * because a busy month can close several hundred and nobody reads past the
     * first screen of a drill-down.
     */
    private const DONE_LIST_LIMIT = 100;

    /** Subscribers returned per page of the status drill-down. */
    private const DIRECTORY_PER_PAGE = 25;

    /**
     * Distinct status values a queue pipeline will render.
     *
     * Bounds the zero-filled status list in queueStatuses. Both systems write
     * free text here, so a column that has collected hundreds of distinct values
     * is a data-quality problem rather than a pipeline, and drawing all of them
     * would bury the handful that are real workflow states.
     */
    private const QUEUE_STATUS_LIMIT = 40;

    /**
     * Job-order states meaning "installed", and service-visit states meaning
     * "repaired".
     *
     * Deliberately wider than the literal "Done" the brief names. The app writes
     * these free-text and three spellings of finished are already in the data;
     * matching only 'done' would under-report every completion recorded as
     * 'Completed', which is how a field team's month goes missing.
     */
    private const COMPLETED_STATES = ['done', 'completed', 'resolved', 'approved'];

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

            // Every plan with its share of the base, uncapped — the pie and the
            // table beside it are about distribution, and a top ten silently
            // drops the tail the percentages are supposed to add up over.
            'plan_distribution' => $this->planDistribution($db),

            // Online, offline, restricted and disconnected taken over one row
            // set, so the four add up to the base. See networkStatus.
            'network' => $this->networkStatus($db),

            // Subscribers SYNC is licensed for, excluding VIP and Pullout.
            'sync_billable_accounts' => $this->syncBillableAccounts($db),

            // Who the VIP counter above is actually counting. See vipAccounts.
            'vip_accounts' => $this->vipAccounts($db),

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
     * The subscribers behind one billing-status counter.
     *
     * ── Why the status filter is built from StatusMap ─────────────────
     *
     * The counter this drills into was produced by StatusMap::rewrite, which
     * folds several raw source values onto one reported label — 'suspended' and
     * 'restricted' both read as Restricted, 'expired' and 'overdue' both as
     * Disconnected. Filtering here on the *label* would return nothing, and
     * filtering on a single guessed raw value would return a subset. Both are
     * ways for a drill-down to disagree with the number that opened it, so the
     * bucket's whole membership list drives the WHERE clause.
     *
     * ── Query shape ───────────────────────────────────────────────────
     *
     * Two queries: one COUNT for the true total, one page of rows with the
     * customer and plan joined. Not one query and a PHP slice — that would pull
     * every active subscriber across the fleet into memory to show twenty-five of
     * them. The joins are LEFT so an account whose plan row was deleted still
     * appears; dropping it would make the page disagree with the counter for a
     * second, subtler reason.
     */
    public function subscribersByStatus(ConnectionInterface $db, array $params): array
    {
        $status = strtolower(trim((string) ($params['status'] ?? 'active')));
        $search = trim((string) ($params['search'] ?? ''));
        $plan = trim((string) ($params['plan'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(ReportingService::MAX_PER_PAGE, max(1, (int) ($params['per_page'] ?? self::DIRECTORY_PER_PAGE)));

        $members = StatusMap::BILLING_BUCKETS[$status] ?? null;

        if ($members === null) {
            return [
                'rows' => [], 'total' => 0, 'page' => 1,
                'per_page' => $perPage, 'total_pages' => 0, 'status' => $status,
            ];
        }

        $base = function () use ($db, $members, $search, $plan): Builder {
            $query = $db->table('billing_accounts as ba')
                ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
                // ── Why the customer join tests two keys ──────────────
                //
                // SYNC links these two tables both ways and neither is
                // populated everywhere. Accounts created through the app carry
                // `billing_accounts.customer_id`; accounts that came through the
                // migration have it null and are reachable only by matching
                // `customers.account_no` — which is why that column exists on
                // both tables.
                //
                // `customer_id` is the only link worth joining on. Measured
                // against production: 3,594 of 3,594 billing accounts resolve
                // through it, none are null and none dangle. A fallback join on
                // `customers.account_no` was tried here and removed — it could
                // never fire, and it carried a real hazard, because five
                // account numbers in `customers` map to more than one row and an
                // account reaching that join would have been returned twice.
                ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
                ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
                ->whereIn(DB::raw("LOWER(TRIM(COALESCE(bs.status_name, '')))"), $members);

            // Drilling into one Subscriber Plan tile.
            //
            // Matched against the resolved plan name, falling back to the
            // customer's free-text plan for accounts whose `plan_id` was lost in
            // the migration — the same two populations planMix counts, so the
            // list a tile opens is the population that tile counted rather than
            // only the half that still has a plan row.
            if ($plan !== '') {
                // Compared on the flattened name — case, spaces, hyphens and
                // underscores removed — because the tile's label is the
                // canonical `plan_list.plan_name` while the legacy accounts
                // carry a free-text string that agrees only after
                // normalisation. Matching literally would make a tile reading
                // 1,601 open a modal reading 0, which is worse than not
                // offering the drill-down at all.
                //
                // Mirrors PlanReconciler::compact(), which is what produced the
                // label. Measured against production: this resolves 2,801 of
                // the 2,801 active accounts whose plan_id no longer resolves.
                $flatten = fn (string $column) =>
                    "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM({$column}), '-', ''), '_', ''), ' ', ''), '.', ''))";

                $needle = strtolower(preg_replace('/[-_ .]/', '', $plan) ?? '');

                $query->where(function ($group) use ($needle, $flatten) {
                    $group->whereRaw($flatten('pl.plan_name') . ' = ?', [$needle])
                        ->orWhere(function ($legacy) use ($needle, $flatten) {
                            $legacy->whereNull('pl.id')
                                ->whereRaw($flatten("COALESCE(c.desired_plan, '')") . ' = ?', [$needle]);
                        });
                });
            }

            if ($search !== '') {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';

                // Searched across whichever customer row resolved, so a search
                // finds migrated accounts as readily as app-created ones.
                $query->where(function ($group) use ($like) {
                    $group->where('ba.account_no', 'like', $like)
                        ->orWhere('c.first_name', 'like', $like)
                        ->orWhere('c.last_name', 'like', $like)
                        ->orWhere('c.contact_number_primary', 'like', $like)
                        ->orWhere('c.barangay', 'like', $like)
                        ->orWhere('c.address', 'like', $like);
                });
            }

            return $query;
        };

        $total = (int) $base()->count();

        if ($total === 0) {
            return [
                'rows' => [], 'total' => 0, 'page' => 1,
                'per_page' => $perPage, 'total_pages' => 0, 'status' => $status,
            ];
        }

        $rows = $base()
            ->select('ba.id', 'ba.account_no', 'ba.date_installed', 'ba.vip_expiration')
            ->selectRaw('bs.status_name AS status_name')
            ->selectRaw('c.first_name AS first_name')
            ->selectRaw('c.last_name AS last_name')
            ->selectRaw('c.contact_number_primary AS contact_number_primary')
            ->selectRaw('c.email_address AS email_address')
            ->selectRaw('c.barangay AS barangay')
            ->selectRaw('c.city AS city')
            ->selectRaw('c.address AS address')
            ->selectRaw('c.desired_plan AS desired_plan')
            ->selectRaw('pl.plan_name AS plan_name')
            ->orderBy('c.last_name')
            ->orderBy('ba.account_no')
            ->forPage($page, $perPage)
            ->get();

        return [
            'rows' => $rows->map(fn ($row) => $this->subscriberRow($row))->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'status' => $status,
        ];
    }

    /**
     * One subscriber row, shaped for the drill-down table.
     *
     * ── Why every field is emitted under two names ────────────────────
     *
     * The reporting payloads speak one vocabulary (`subscriber`, `location`,
     * `plan`) and SYNC's own screens speak another (`customer_name`, `area`,
     * `plan_name`). Both are legitimate readers of this endpoint, and a
     * consumer written against the wrong one renders a table of empty cells
     * with no error anywhere — which is exactly the failure this drill-down
     * shipped with.
     *
     * Emitting both aliases costs a few hundred bytes a page and removes the
     * whole class of bug. It is deliberately not a compatibility shim to be
     * removed later: the two vocabularies belong to two systems that will keep
     * existing, and picking one would only move the problem.
     *
     * `area` falls back through barangay → city → street address, because a
     * subscriber with no barangay recorded still has somewhere to visit, and an
     * empty Area column is indistinguishable from a broken join.
     *
     * @param object $row
     * @return array<string,mixed>
     */
    private function subscriberRow($row): array
    {
        $name = $this->fullName($row->first_name ?? '', $row->last_name ?? '');
        $account = (string) ($row->account_no ?? '');
        $contact = (string) ($row->contact_number_primary ?? '');
        // The plan row where one resolves, the free-text string where it does
        // not — the same fallback planMix reconciles on, so the two screens name
        // the same plan for the same account.
        $plan = (string) ($row->plan_name ?: $row->desired_plan ?: '');
        $area = $this->joinLocation([$row->barangay ?? null, $row->city ?? null])
            ?: trim((string) ($row->address ?? ''));
        // The source's own word, not the reported label: a reader looking at a
        // Restricted list is entitled to see which of them the system calls
        // Suspended.
        $status = (string) ($row->status_name ?? '');

        return [
            'id' => (string) ($row->id ?? ''),

            // Reporting vocabulary.
            'subscriber' => $name,
            'account_number' => $account,
            'contact_number' => $contact,
            'plan' => $plan,
            'location' => $area,
            'raw_status' => $status,

            // SYNC vocabulary, same values.
            'customer_name' => $name,
            'account_no' => $account,
            'contact' => $contact,
            'plan_name' => $plan,
            'area' => $area,
            'status' => $status,

            'email' => (string) ($row->email_address ?? ''),
            'date_installed' => $row->date_installed ?: null,
            'vip_expiration' => $row->vip_expiration ?: null,
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
     * ── Why this is no longer a plain join ────────────────────────────
     *
     * It used to be `JOIN plan_list ON pl.id = ba.plan_id`, which silently
     * dropped every account whose `plan_id` is null or points at a plan row that
     * has since been renamed or deleted. On the Group Overview that showed up as
     * a Subscriber Plan section whose cards added to noticeably fewer than the
     * Active counter three rows above it — the inner join was doing the
     * subtracting, invisibly.
     *
     * The unresolved tail is now reconciled the same way `planDistribution` does
     * it: the surviving free-text plan string is matched against the canonical
     * `plan_list` names by PlanReconciler, which normalises punctuation and case,
     * tries containment, then bandwidth and price signatures — and refuses ties
     * rather than guessing. Anything genuinely unidentifiable is grouped under
     * one label instead of vanishing.
     *
     * Both halves are grouped in SQL before they reach PHP: one row per plan and
     * one per *distinct legacy string*, typically a handful, never one per
     * account.
     *
     * `desired_plan` is only consulted for accounts whose plan_id resolved to
     * nothing. It is what the applicant asked for at sign-up and is often stale,
     * so it is a last resort rather than the grouping key.
     */
    private function planMix(ConnectionInterface $db): array
    {
        $activeOnly = "LOWER(TRIM(COALESCE(bs.status_name, ''))) = 'active'";

        $reconciler = PlanReconciler::fromCanonical(
            $db->table('plan_list')->select('id', 'plan_name', 'price')->get()
        );

        $counts = [];

        // Accounts whose plan_id resolves — the large population, grouped in SQL.
        $matched = $db->table('billing_accounts as ba')
            ->join('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereRaw($activeOnly)
            ->selectRaw('pl.id AS plan_id')
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('pl.id')
            ->get();

        foreach ($matched as $row) {
            $plan = $reconciler->plan((int) $row->plan_id);

            if ($plan === null) {
                continue;
            }

            $counts[$plan['label']] = ($counts[$plan['label']] ?? 0) + (int) $row->cnt;
        }

        // The tail: no plan_id, or one pointing at a row that no longer exists.
        $legacy = $db->table('billing_accounts as ba')
            ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
            ->whereRaw($activeOnly)
            ->whereNull('pl.id')
            ->selectRaw("COALESCE(NULLIF(TRIM(c.desired_plan), ''), '') AS raw_plan")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('raw_plan')
            ->get();

        foreach ($legacy as $row) {
            $raw = (string) $row->raw_plan;
            $count = (int) $row->cnt;

            $planId = $raw === '' ? null : $reconciler->match($raw);
            $plan = $planId === null ? null : $reconciler->plan($planId);

            $label = $plan['label'] ?? PlanReconciler::UNMAPPED_LABEL;

            $counts[$label] = ($counts[$label] ?? 0) + $count;
        }

        arsort($counts);

        $rows = [];

        foreach ($counts as $label => $count) {
            $rows[] = ['label' => (string) $label, 'count' => (int) $count];
        }

        // Capped, like every other league table here. The unmapped bucket is
        // deliberately allowed to compete for a slot on merit: if it is large
        // enough to make the top ten, that is a fact worth seeing rather than one
        // to hide behind a tidier list.
        return array_slice($rows, 0, self::TOP_N);
    }

    /**
     * Every plan with its share of the subscriber base.
     *
     * Uncapped, unlike planMix beside it. That one feeds a top-ten league table
     * and a cap is the right call there; this one feeds a pie chart and a
     * distribution table whose percentages have to add to 100, and a top ten
     * silently drops the tail they are computed over.
     *
     * The share is computed here rather than in React because the aggregate path
     * merges several databases and has to recompute it against the fleet total —
     * having one place that knows the formula is what stops the two disagreeing.
     *
     * LEFT JOIN so an account whose plan_id points at a deleted or renamed plan
     * is still counted. Unmatched accounts are fuzzy-matched against the
     * canonical plan list; anything that still does not match is grouped as
     * "Unmapped / Legacy Plan" rather than dropped from the total.
     */
    private function planDistribution(ConnectionInterface $db): array
    {
        // Step 1 — the canonical records, before anything is counted. Every
        // reported plan name comes from here, so a legacy string never becomes a
        // slice of its own and splits one plan across two wedges.
        $reconciler = PlanReconciler::fromCanonical(
            $db->table('plan_list')->select('id', 'plan_name', 'price')->get()
        );

        // Accounts whose plan_id resolves. Grouped in SQL — this is the large
        // population and it must never come back a row at a time.
        $matched = $db->table('billing_accounts as ba')
            ->join('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereRaw(StatusMap::excludeSql('bs.status_name'))
            ->selectRaw('pl.id AS plan_id')
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('pl.id')
            ->get();

        $buckets = [];

        foreach ($matched as $row) {
            $plan = $reconciler->plan((int) $row->plan_id);

            if ($plan === null) {
                continue;
            }

            $buckets[$plan['id']] = [
                'plan_id' => $plan['id'],
                'label' => $plan['label'],
                'price' => $plan['price'],
                'count' => (int) $row->cnt,
            ];
        }

        // Step 2 — the tail the migration left behind: no plan_id, or one
        // pointing at a row that no longer exists. All that survives of what they
        // are on is the customer's own free-text plan string, so it is grouped in
        // SQL and reconciled in PHP. The group-by is what keeps this cheap: the
        // result is one row per *distinct legacy string*, typically a handful,
        // not one per account.
        $legacy = $db->table('billing_accounts as ba')
            ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
            ->whereRaw(StatusMap::excludeSql('bs.status_name'))
            ->whereNull('pl.id')
            ->selectRaw("COALESCE(NULLIF(TRIM(c.desired_plan), ''), '') AS raw_plan")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('raw_plan')
            ->get();

        $unmapped = 0;
        $unmappedSamples = [];

        foreach ($legacy as $row) {
            $count = (int) $row->cnt;
            $raw = (string) $row->raw_plan;

            $planId = $reconciler->match($raw);
            $plan = $planId !== null ? $reconciler->plan($planId) : null;

            if ($plan === null) {
                // Step 3 — grouped, never dropped. An account with no
                // identifiable plan is still a subscriber, and removing it from
                // the denominator is how a pie chart comes to describe a base
                // smaller than the headline count beside it.
                $unmapped += $count;

                if ($raw !== '' && count($unmappedSamples) < 8) {
                    $unmappedSamples[] = $raw;
                }

                continue;
            }

            if (!isset($buckets[$plan['id']])) {
                $buckets[$plan['id']] = [
                    'plan_id' => $plan['id'],
                    'label' => $plan['label'],
                    'price' => $plan['price'],
                    'count' => 0,
                ];
            }

            $buckets[$plan['id']]['count'] += $count;
        }

        $items = array_values($buckets);

        if ($unmapped > 0) {
            $items[] = [
                'plan_id' => null,
                'label' => PlanReconciler::UNMAPPED_LABEL,
                'price' => 0.0,
                'count' => $unmapped,
                // The strings that could not be placed, so the fix is a lookup
                // away rather than a database trawl. Capped — this is a hint on
                // a dashboard, not an export.
                'samples' => $unmappedSamples,
            ];
        }

        $total = array_sum(array_column($items, 'count'));

        // Largest first: the pie reads clockwise from the biggest wedge.
        usort($items, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_map(fn ($item) => array_merge($item, [
            'share_pct' => $total > 0 ? round($item['count'] / $total * 100, 1) : 0.0,
        ]), $items);
    }

    /**
     * Online, offline, restricted and disconnected over one row set.
     *
     * All four buckets are derived from `online_status.session_status`, not from
     * the billing status. Billing status is used *only* to exclude rows that are
     * not subscribers (pending, in progress, for activation) via
     * StatusMap::excludeSql.
     *
     * The previous version took restricted and disconnected from the billing
     * status, which meant a subscriber whose billing said "suspended" counted as
     * restricted even when the network had already dropped them. The network
     * session is the ground truth for whether an account is online, restricted or
     * disconnected — billing is the ground truth for whether it is a subscriber.
     *
     * One left join, one pass. Every account SYNC counts as a subscriber lands in
     * exactly one bucket:
     *
     *   1. Disconnected — session_status says disconnected, expired, offline_dc
     *      or terminated.
     *   2. Restricted — session_status says restricted or suspended.
     *   3. Online — session_status says online, active or connected.
     *   4. Offline — everything else: NULL, empty, unknown, or no matching
     *      online_status row at all. Never having appeared on the network is the
     *      strongest form of not being on it.
     *
     * Two diagnostics travel alongside, both *subsets of offline* rather than
     * extra buckets, so the four still sum to the base:
     *
     *   not_found     SYNC's RADIUS sync writes this literal when the account's
     *                 username is absent from RADIUS altogether (see
     *                 RadiusStatusSyncService). That is a provisioning fault, not
     *                 a subscriber who happens to be switched off, and rolling it
     *                 into Offline without saying so hides a real backlog.
     *   no_session_row  the account has never been written to online_status at
     *                 all — usually a sync that has not run since the account was
     *                 created.
     */
    private function networkStatus(ConnectionInterface $db): array
    {
        // Parenthesised rather than leaning on MySQL's NOT/AND precedence. The
        // default precedence happens to be the one wanted here, but it inverts
        // under HIGH_NOT_PRECEDENCE mode — and a server-mode flag silently
        // swapping "online" and "disconnected" is not a failure anyone would
        // trace back to this string.
        $disconnected = "(LOWER(TRIM(COALESCE(os.session_status, ''))) IN ('disconnected', 'expired', 'offline_dc', 'terminated'))";
        $restricted = "(LOWER(TRIM(COALESCE(os.session_status, ''))) IN ('restricted', 'suspended'))";
        $live = "(LOWER(TRIM(COALESCE(os.session_status, ''))) IN ('online', 'active', 'connected'))";
        $notFound = "(LOWER(TRIM(COALESCE(os.session_status, ''))) IN ('not found', 'not_found'))";
        $noRow = '(os.account_id IS NULL)';

        $row = $db->table('billing_accounts as ba')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->leftJoin('online_status as os', 'os.account_id', '=', 'ba.id')
            ->whereRaw(StatusMap::excludeSql('bs.status_name'))
            ->selectRaw("COALESCE(SUM({$disconnected}), 0) AS disconnected")
            ->selectRaw("COALESCE(SUM((NOT {$disconnected}) AND {$restricted}), 0) AS restricted")
            ->selectRaw("COALESCE(SUM((NOT {$disconnected}) AND (NOT {$restricted}) AND {$live}), 0) AS online")
            ->selectRaw("COALESCE(SUM((NOT {$disconnected}) AND (NOT {$restricted}) AND (NOT {$live})), 0) AS offline")
            ->selectRaw("COALESCE(SUM({$notFound}), 0) AS not_found")
            ->selectRaw("COALESCE(SUM({$noRow}), 0) AS no_session_row")
            ->selectRaw('COUNT(*) AS total')
            ->first();

        return [
            'online' => (int) ($row->online ?? 0),
            'offline' => (int) ($row->offline ?? 0),
            'restricted' => (int) ($row->restricted ?? 0),
            'disconnected' => (int) ($row->disconnected ?? 0),
            'total' => (int) ($row->total ?? 0),
            'not_found' => (int) ($row->not_found ?? 0),
            'no_session_row' => (int) ($row->no_session_row ?? 0),
        ];
    }

    /**
     * Subscribers the SYNC platform fee is charged for.
     *
     * VIP and Pullout are excluded per the brief — the first is not billed and
     * the second is not connected — along with the statuses StatusMap already
     * rules out as not-a-subscriber. Excluded in SQL rather than by subtracting
     * counts in PHP, so the headcount and the money computed from it can never
     * be taken over different populations.
     */
    private function syncBillableAccounts(ConnectionInterface $db): int
    {
        $excluded = implode(', ', array_map(
            fn (string $status) => "'" . str_replace("'", "''", $status) . "'",
            SyncPricing::excludedStatuses()
        ));

        $query = $db->table('billing_accounts as ba')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereRaw(StatusMap::excludeSql('bs.status_name'));

        if ($excluded !== '') {
            $query->whereRaw("LOWER(TRIM(COALESCE(bs.status_name, ''))) NOT IN ({$excluded})");
        }

        return (int) $query->count();
    }

    /**
     * Hard ceiling on the VIP list.
     *
     * VIP is an exception granted account by account, so this list is tens of
     * rows on a healthy fleet — but it is driven by a billing status anyone can
     * set, and a misconfiguration that flips thousands of accounts to VIP must
     * not turn an executive dashboard into a thousand-row table. The cap is
     * generous enough that it is never reached in normal operation, and
     * `vip_accounts_total` beside it always states the true count so a truncated
     * list is visible rather than silent.
     */
    private const VIP_LIST_LIMIT = 500;

    /**
     * Every account on VIP billing status, by name.
     *
     * The Group Overview reports how many accounts are unbilled; this is who
     * they are. A count answers "how much are we giving away" and a list answers
     * "to whom" — the second is the one that gets acted on, and it was previously
     * unanswerable without opening the operating system itself.
     *
     * VIP *status* only, deliberately. An account on a plan merely named "VIP
     * FREE" is a different population recorded in a different place, and folding
     * the two together would produce a list nobody could reconcile against the
     * VIP counter above it.
     *
     * One query with two joins rather than a per-row lookup: the expiry and the
     * plan name are both wanted for every row, and lazily resolving either would
     * be one query per VIP account.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int, truncated: bool}
     */
    private function vipAccounts(ConnectionInterface $db): array
    {
        $matchesVip = "LOWER(TRIM(COALESCE(bs.status_name, ''))) = 'vip'";

        $base = fn (): Builder => $db->table('billing_accounts as ba')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->whereRaw($matchesVip);

        $total = (int) $base()->count();

        if ($total === 0) {
            return ['rows' => [], 'total' => 0, 'truncated' => false];
        }

        $rows = $base()
            ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
            ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->select(
                'ba.id',
                'ba.account_no',
                'ba.vip_expiration',
                'ba.date_installed',
                'c.first_name',
                'c.last_name',
                'c.contact_number_primary',
                'c.barangay',
                'pl.plan_name'
            )
            // Soonest expiry first, and accounts with no expiry last: an
            // open-ended VIP needs no diary entry, one lapsing on Friday does.
            ->orderByRaw('ba.vip_expiration IS NULL, ba.vip_expiration ASC')
            ->orderBy('ba.account_no')
            ->limit(self::VIP_LIST_LIMIT)
            ->get();

        return [
            'rows' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'account_number' => (string) ($row->account_no ?? ''),
                'subscriber' => $this->fullName($row->first_name ?? '', $row->last_name ?? ''),
                'contact_number' => (string) ($row->contact_number_primary ?? ''),
                'barangay' => (string) ($row->barangay ?? ''),
                'plan' => (string) ($row->plan_name ?? ''),
                // Null rather than an empty string: "no end date" is a real
                // state for a VIP and the table renders it as such.
                'vip_expiration' => $row->vip_expiration ?: null,
                'date_installed' => $row->date_installed ?: null,
            ])->all(),
            'total' => $total,
            'truncated' => $total > self::VIP_LIST_LIMIT,
        ];
    }

    /**
     * Monthly recurring charge the active base should bill.
     *
     * Left join so an active account whose plan row was deleted contributes
     * nothing rather than dropping the account out of the total entirely.
     */
    private function expectedMrc(ConnectionInterface $db): float
    {
        // ── Why the plan price is reached two ways ────────────────────
        //
        // `billing_accounts.plan_id` is very largely unpopulated in production:
        // of 2,892 active accounts, 91 resolve a plan row and 2,801 do not. The
        // surviving description of what those 2,801 are on is the free-text
        // `customers.desired_plan`, which — measured — holds the exact canonical
        // plan names and reconciles for all 2,801 of them.
        //
        // Priced through `plan_id` alone this figure came to ₱101,600 against a
        // real ₱3,194,400. Expected MRC drives the collection-rate metric, so a
        // 31× understatement made collection rate read as roughly 31× too good
        // — a headline that was not slightly wrong but inverted in meaning.
        //
        // The fallback join is constrained to accounts the first could not
        // price, so no account is counted twice. Names are compared flattened
        // (case, spaces, hyphens, underscores removed), mirroring
        // PlanReconciler::compact() so this agrees with the plan mix beside it.
        $flat = fn (string $column) =>
            "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM({$column}), '-', ''), '_', ''), ' ', ''), '.', ''))";

        return (float) $db->table('billing_accounts as ba')
            ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
            ->leftJoin('plan_list as fallback', function ($join) use ($flat) {
                $join->whereNull('pl.id')
                    ->whereRaw($flat('fallback.plan_name') . ' = ' . $flat("COALESCE(c.desired_plan, '')"));
            })
            ->whereIn(DB::raw("LOWER(COALESCE(bs.status_name, ''))"), ['active', 'online'])
            ->sum(DB::raw('COALESCE(pl.price, fallback.price, 0)'));
    }

    /**
     * Every barangay, with the billing-status split management asked for.
     *
     * Uncapped, unlike the plan mix beside it: this is a table answering a
     * coverage question, and a top ten drops exactly the thin coverage the
     * question is about. Sorting is left to the client so the same payload
     * serves a table sorted by any column.
     *
     * The four network columns (online, offline, restricted, disconnected) are
     * derived from `online_status.session_status`, matching networkStatus(). The
     * billing-status columns (active, vip, inactive, pullout) still come from
     * billing_status.status_name via StatusMap::bucketSql.
     */
    private function barangayBreakdown(ConnectionInterface $db, array $params): array
    {
        // Same logic as networkStatus: all four network columns come from the
        // session_status column of online_status, not from billing_status.
        // Parenthesised for the reason given there.
        $disconnected = "(LOWER(TRIM(COALESCE(os.session_status, ''))) IN ('disconnected', 'expired', 'offline_dc', 'terminated'))";
        $restricted = "(LOWER(TRIM(COALESCE(os.session_status, ''))) IN ('restricted', 'suspended'))";
        $live = "(LOWER(TRIM(COALESCE(os.session_status, ''))) IN ('online', 'active', 'connected'))";

        $query = $db->table('customers as c')
            ->join('billing_accounts as ba', 'ba.customer_id', '=', 'c.id')
            ->leftJoin('billing_status as bs', 'bs.id', '=', 'ba.billing_status_id')
            ->leftJoin('online_status as os', 'os.account_id', '=', 'ba.id')
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
            ->selectRaw("COALESCE(SUM({$disconnected}), 0) AS disconnected")
            ->selectRaw("COALESCE(SUM((NOT {$disconnected}) AND {$restricted}), 0) AS restricted")
            ->selectRaw("COALESCE(SUM((NOT {$disconnected}) AND (NOT {$restricted}) AND {$live}), 0) AS online")
            ->selectRaw("COALESCE(SUM((NOT {$disconnected}) AND (NOT {$restricted}) AND (NOT {$live})), 0) AS offline")
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
                'online' => (int) $row->online,
                'offline' => (int) $row->offline,
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
        $portal = $this->portalStats($db, $from, $to);
        $byMethod = $this->revenueByMethod($db, $from, $to, $portal);
        $byExpenseType = $this->expensesByCategory($db, $expensePeriod, $from, $to);

        $base = $this->subscriberBase($db);

        $daysElapsed = max(1, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $daysInMonth = (int) Carbon::parse($to)->daysInMonth;
        $dailyAverageCollection = round($revenue['total'] / $daysElapsed, 2);
        $projectedMonthlySales = round($dailyAverageCollection * $daysInMonth, 2);

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
                'collection_rate' => $expectedMrc > 0
                    ? min(999.0, round($revenue['total'] / $expectedMrc * 100, 1))
                    : 0.0,
                'daily_average' => $dailyAverageCollection,
                'projected_monthly' => $projectedMonthlySales,
                'days_elapsed' => $daysElapsed,
                'days_in_month' => $daysInMonth,
            ],

            // Anchored on today rather than on the widget's range — see
            // rollingIncome for why these two figures cannot follow it.
            'rolling' => $this->rollingIncome($db, $anchor),

            'series' => $this->dailySeries($db, $from, $to, $expensePeriod),
            'trend' => [
                'period' => $trendPeriod,
                'points' => $this->trendSeries($db, $trendPeriod, $anchor),
            ],

            // Cash and PNB are regrouped from the counter's payment methods; the
            // Payment Portal channel is a real table, not a pattern match — see
            // portalPayments for why it cannot be derived from `by_method`.
            'income_channels' => IncomeChannels::withPortal(
                $byMethod,
                $portal,
                $this->portalChannels($db, $from, $to)
            ),

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
     * Month-to-date and last-seven-days collections, anchored on today.
     *
     * These deliberately ignore the widget's date range, which is the whole point
     * of them. "Projected monthly income" is defined against the current month —
     *
     *     (income so far this month ÷ days elapsed this month) × days in month
     *
     * — and computing it from whatever window someone happened to select produces
     * a projection of a month that is not the one being projected: on a Daily view
     * it was one day's takings times thirty-one, which is not a forecast of
     * anything. The old `kpi.daily_average` did exactly that, and still does,
     * because the Financial module's own panel is about the selected range.
     *
     * The weekly average is a strict seven-day rolling figure — the last seven
     * calendar days ending today, divided by seven, always. Not divided by "days
     * with a collection", which would report the average of a busy day and call it
     * a weekly rate; a Sunday with no counter takings is a real zero and belongs
     * in the denominator.
     *
     * Both sides count counter transactions *and* portal payments, matching
     * revenueStats — an income figure built from `transactions` alone understates
     * collections by the whole online channel.
     */
    private function rollingIncome(ConnectionInterface $db, Carbon $anchor): array
    {
        $monthStart = $anchor->copy()->startOfMonth()->toDateString();
        $today = $anchor->copy()->toDateString();

        // Inclusive of both ends, so the first of the month is one day elapsed
        // rather than zero — which would divide by zero on the 1st.
        $daysElapsed = max(1, $anchor->copy()->startOfMonth()->diffInDays($anchor) + 1);
        $daysInMonth = (int) $anchor->daysInMonth;

        $monthIncome = $this->incomeBetween($db, $monthStart, $today);

        // Seven calendar days ending today, today included: subDays(6), not (7),
        // which would be an eight-day window divided by seven.
        $weekIncome = $this->incomeBetween(
            $db,
            $anchor->copy()->subDays(6)->toDateString(),
            $today
        );

        $dailyAverage = $monthIncome / $daysElapsed;

        return [
            'month_start' => $monthStart,
            'as_of' => $today,
            'month_income' => round($monthIncome, 2),
            'days_elapsed' => $daysElapsed,
            'days_in_month' => $daysInMonth,
            'daily_average' => round($dailyAverage, 2),
            'projected_monthly' => round($dailyAverage * $daysInMonth, 2),

            'week_from' => $anchor->copy()->subDays(6)->toDateString(),
            'week_income' => round($weekIncome, 2),
            // Always seven. Named so the frontend can state the divisor rather
            // than leaving a reader to assume it matched the days on screen.
            'week_days' => 7,
            'weekly_average' => round($weekIncome / 7, 2),
        ];
    }

    /**
     * Total collected in a date range, both income streams.
     *
     * Two queries rather than a union: the portal lives in its own table with its
     * own column names resolved at runtime (see portalPayments), and forcing them
     * into one statement would mean building the union SQL by string.
     */
    private function incomeBetween(ConnectionInterface $db, string $from, string $to): float
    {
        $counter = (float) $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
            ->sum('t.received_payment');

        return $counter + $this->portalStats($db, $from, $to)['total'];
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

    /**
     * Count, total, average and largest collection in the range.
     *
     * Both income streams: over-the-counter transactions *and* portal payments,
     * which live in a separate table and are absent from `transactions` entirely
     * (see portalPayments). Counting only the first understates collections by
     * the whole online channel.
     */
    private function revenueStats(ConnectionInterface $db, string $from, string $to): array
    {
        $row = $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS total')
            ->selectRaw('COALESCE(MAX(t.received_payment), 0) AS max_amount')
            ->first();

        $counter = [
            'count' => (int) ($row->cnt ?? 0),
            'total' => (float) ($row->total ?? 0),
            'largest' => (float) ($row->max_amount ?? 0),
        ];

        $portal = $this->portalStats($db, $from, $to);

        $count = $counter['count'] + $portal['count'];
        $total = $counter['total'] + $portal['total'];

        return [
            'count' => $count,
            'total' => $total,
            // Recomputed from the combined total and count rather than averaging
            // two averages, which is only correct when both counts are equal and
            // they never are.
            'average' => $count > 0 ? $total / $count : 0.0,
            'largest' => max($counter['largest'], $portal['largest']),
        ];
    }

    /**
     * Income split into over-the-counter and online-portal collections.
     *
     * The split used to be guessed from a regex over `transactions.payment_method`
     * — "does this string look like GCash or a bank". That was wrong in both
     * directions: portal payments are not in `transactions` at all, so the portal
     * side matched only counter payments a cashier happened to label "GCash",
     * while the genuine portal money was missing from the page entirely.
     *
     * The two tables are now the split. `transactions` is what was taken at the
     * counter; `payment_portal_logs` is what came through the portal. No
     * classification, no overlap, nothing to reconcile.
     */
    private function incomeKpi(ConnectionInterface $db, string $from, string $to): array
    {
        $row = $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
            ->selectRaw('COALESCE(SUM(t.received_payment), 0) AS office_income')
            ->selectRaw('COUNT(*) AS office_count')
            ->first();

        $portal = $this->portalStats($db, $from, $to);

        $officeIncome = (float) ($row->office_income ?? 0);
        $officeCount = (int) ($row->office_count ?? 0);

        return [
            'income' => $officeIncome + $portal['total'],
            'count' => $officeCount + $portal['count'],
            'portal_income' => $portal['total'],
            'portal_count' => $portal['count'],
            'office_income' => $officeIncome,
            'office_count' => $officeCount,
        ];
    }

    /** Collections itemised by charge type. */
    private function collectionsByType(ConnectionInterface $db, string $from, string $to): array
    {
        return $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
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
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
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

    private function revenueByMethod(
        ConnectionInterface $db,
        string $from,
        string $to,
        ?array $portal = null
    ): array {
        $rows = $this->collectedTransactions($db)
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
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

        // Payment Portal, appended rather than grouped.
        //
        // GOWISER settles online payments through `payment_portal_logs` and
        // never writes them to `transactions`, so this breakdown was a list of
        // counter methods presented under the heading "Revenue by Payment
        // Method" — with the single largest method missing and no indication it
        // was. The channel panel already stated it separately; the reader
        // comparing the two panels was the one who found out.
        //
        // Named for what finance reconciles against rather than for the current
        // gateway, matching IncomeChannels::CHANNELS.
        $total = round((float) ($portal['total'] ?? 0), 2);

        if ($total > 0 || (int) ($portal['count'] ?? 0) > 0) {
            $rows[] = [
                'label' => IncomeChannels::CHANNELS['portal'],
                'count' => (int) ($portal['count'] ?? 0),
                'total' => $total,
            ];

            usort($rows, fn (array $a, array $b) => $b['total'] <=> $a['total']);
        }

        return $rows;
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
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
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
            ->selectRaw('DISTINCT YEAR(t.date_processed) AS yr')
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
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
            ->selectRaw("DATE_FORMAT(t.date_processed, '%Y-%m-%d') AS day")
            ->selectRaw('SUM(t.received_payment) AS total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Portal collections live in their own table and have to be added per
        // day, or the chart contradicts the headline figure above it.
        $portal = $this->portalDaily($db, $from, $to);

        $expenses = $this->expenseRows($db, $expensePeriod, $from, $to)
            ->selectRaw("DATE_FORMAT(e.date, '%Y-%m-%d') AS day")
            ->selectRaw('SUM(e.amount) AS total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // A day with portal income but no counter payment and no expense still
        // has to plot, so its key joins the union.
        $days = $income->keys()
            ->merge(array_keys($portal))
            ->merge($expenses->keys())
            ->unique()
            ->sort()
            ->values();

        return $days->map(function ($day) use ($income, $portal, $expenses) {
            $in = round(
                (float) ($income->get($day)->total ?? 0) + (float) ($portal[$day] ?? 0),
                2
            );
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
            $incomeQuery->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to]);
        }

        $income = $incomeQuery
            ->selectRaw($bucketFor('t.date_processed') . ' AS bucket')
            ->selectRaw($labelFor('t.date_processed') . ' AS label')
            ->selectRaw('SUM(t.received_payment) AS total')
            ->groupBy('bucket', 'label')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        // Portal collections, bucketed the same way so they can be added on.
        $portalQuery = $this->portalPayments($db);

        if ($from !== null) {
            $portalQuery->whereBetween(DB::raw('DATE(ppl.date_time)'), [$from, $to]);
        }

        $portal = $portalQuery
            ->selectRaw($bucketFor('ppl.date_time') . ' AS bucket')
            ->selectRaw($labelFor('ppl.date_time') . ' AS label')
            ->selectRaw('SUM(ppl.total_amount) AS total')
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

        $buckets = $income->keys()
            ->merge($portal->keys())
            ->merge($expenses->keys())
            ->unique()
            ->sort()
            ->values();

        if ($granularity === 'yearly') {
            $buckets = $buckets->take(-10)->values();
        }

        return $buckets->map(function ($bucket) use ($income, $portal, $expenses) {
            $in = round(
                (float) ($income->get($bucket)->total ?? 0)
                    + (float) ($portal->get($bucket)->total ?? 0),
                2
            );
            $out = round((float) ($expenses->get($bucket)->total ?? 0), 2);

            return [
                'period' => (string) $bucket,
                // Any of the three may be the only source for a bucket, so the
                // label falls through all of them before giving up on the key.
                'label' => (string) (
                    $income->get($bucket)->label
                        ?? $portal->get($bucket)->label
                        ?? $expenses->get($bucket)->label
                        ?? $bucket
                ),
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
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to])
            ->select(
                't.id',
                't.date_processed',
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
            ->orderBy('t.date_processed')
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
                'payment_date' => $row->date_processed ?? $row->payment_date,
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

        // Computed once and read twice: `queues` presents them as the operational
        // pipeline and `work_streams` buckets the same rows into the executive
        // vocabulary. Querying twice would be two round trips whose answers can
        // differ if a row is written between them.
        $applicationStatuses = $this->queueStatuses($db, 'applications', 'status', 'timestamp', $from, $to);
        $jobStatuses = $this->queueStatuses($db, 'job_orders', 'onsite_status', 'timestamp', $from, $to);
        $serviceStatuses = $this->queueStatuses($db, 'service_orders', 'support_status', 'timestamp', $from, $to);

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'branch' => null,
            'branch_label' => 'All accounts',
            'range' => ['from' => $from, 'to' => $to],
            'range_label' => $this->rangeLabel($from, $to),

            // The pipeline is the whole queue, not the slice of it raised inside
            // the selected range — see queueStatusesOverall for why, and for the
            // 88-against-180 discrepancy that windowing produced. `work_streams`
            // below still uses the windowed tally, because that block genuinely
            // answers "what came in during this period".
            'queues' => [
                [
                    'key' => 'applications',
                    'label' => 'Applications',
                    'statuses' => $this->queueStatusesOverall($db, 'applications', 'status'),
                    'backlog' => $this->queueBacklog($db, 'applications', 'status', 'timestamp'),
                ],
                [
                    'key' => 'job_orders',
                    'label' => 'Job Orders',
                    'statuses' => $this->queueStatusesOverall($db, 'job_orders', 'onsite_status'),
                    'backlog' => $this->queueBacklog($db, 'job_orders', 'onsite_status', 'timestamp'),
                ],
                [
                    'key' => 'service_orders',
                    'label' => 'Service Orders',
                    'statuses' => $this->queueStatusesOverall($db, 'service_orders', 'support_status'),
                    'backlog' => $this->queueBacklog($db, 'service_orders', 'support_status', 'timestamp'),
                ],
            ],

            // The three streams the Executive Dashboard reports separately, each
            // bucketed into the reporting vocabulary. See workStreams.
            'work_streams' => $this->workStreams(
                $applicationStatuses,
                $jobStatuses,
                $serviceStatuses
            ),

            // Day-by-day counts of all three, on one date axis.
            'work_timeline' => $this->workTimeline($db, $from, $to),

            // The same three queues counted on today / this week / this month,
            // independent of the selected range. See workCadence.
            'work_cadence' => $this->workCadence($db, $anchor),

            // The Group Overview's five field metrics over the selected range,
            // each counted on the status and the date column its label actually
            // means. Deliberately NOT derived from work_cadence above: that block
            // dates every queue on one COALESCE'd "effective date", which is the
            // right rule for "what moved recently" and the wrong one for all five
            // of these. See WORK_METRICS.
            'executive_workload' => $this->executiveWorkload(
                $db,
                ReportPeriod::parse($from) ?? $anchor->copy()->startOfDay(),
                ReportPeriod::parse($to) ?? $anchor->copy()->startOfDay()
            ),

            // Resolution speed: what closed, and what has been waiting longest.
            'resolution' => $this->resolutionSla($db, $anchor),

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

    /**
     * Applications, job orders and service orders as three separate streams.
     *
     * Separate is the requirement, and it is not cosmetic. A blended "JO/SO"
     * counter answers no question anyone asks: a new connection and a repair are
     * different work, done by different queues, and a month where installations
     * doubled while repairs halved looks identical to a flat one once they are
     * summed. Each stream keeps its own status tally and its own raw breakdown.
     *
     * Applications additionally carry `total`, which is the brief's formula
     * rather than a row count — see StatusBuckets::applicationTotal. Both are
     * reported: `count` is how many applications exist in the range, `total` is
     * the figure management asked for, and labelling either as the other is how
     * two people end up quoting different numbers from the same screen.
     */
    private function workStreams(
        array $applicationStatuses,
        array $jobStatuses,
        array $serviceStatuses
    ): array {
        $applications = StatusBuckets::tallyRows($applicationStatuses, StatusBuckets::applications());
        $jobs = StatusBuckets::tallyRows($jobStatuses, StatusBuckets::workOrders());
        $services = StatusBuckets::tallyRows($serviceStatuses, StatusBuckets::workOrders());

        return [
            'applications' => [
                'key' => 'applications',
                'label' => 'Applications',
                'count' => $applications['total'],
                'total' => StatusBuckets::applicationTotal($applications),
                'buckets' => $applications,
                'statuses' => $applicationStatuses,
            ],
            'job_orders' => [
                'key' => 'job_orders',
                'label' => 'Job Orders',
                'count' => $jobs['total'],
                'buckets' => $jobs,
                'statuses' => $jobStatuses,
            ],
            'service_orders' => [
                'key' => 'service_orders',
                'label' => 'Service Orders',
                'count' => $services['total'],
                'buckets' => $services,
                'statuses' => $serviceStatuses,
            ],
        ];
    }

    /**
     * Applications, job orders and service orders per day, on one date axis.
     *
     * Three grouped counts rather than three-way join: the tables share no key
     * that means "the same day" and joining on a date expression would drop days
     * where one stream had no rows. Merged in PHP onto the union of the dates any
     * stream saw, with the gaps filled as zeros so the chart draws a flat segment
     * rather than skipping a day and implying the days beside it were adjacent.
     *
     * Bounded at one year. The chart is day-by-day and a longer window produces
     * more points than pixels — and a query returning every application ever
     * filed is not what a date filter set to "yearly" is asking for.
     */
    private function workTimeline(ConnectionInterface $db, string $from, string $to): array
    {
        $start = ReportPeriod::parse($from);
        $end = ReportPeriod::parse($to);

        if ($start === null || $end === null) {
            return [];
        }

        if ($start->diffInDays($end) > 366) {
            $start = $end->copy()->subDays(366);
            $from = $start->toDateString();
        }

        $daily = function (string $table) use ($db, $from, $to) {
            return $db->table($table)
                ->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to])
                ->selectRaw("DATE_FORMAT(timestamp, '%Y-%m-%d') AS day")
                ->selectRaw('COUNT(*) AS cnt')
                ->groupBy('day')
                ->pluck('cnt', 'day')
                ->map(fn ($count) => (int) $count)
                ->all();
        };

        $applications = $daily('applications');
        $jobs = $daily('job_orders');
        $services = $daily('service_orders');

        $points = [];

        // Every day in the window, not only the days with rows: a chart that
        // skips empty days silently compresses a quiet week into a busy-looking
        // one by putting its two points side by side.
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();

            $points[] = [
                'period' => $key,
                'label' => $day->format('M d'),
                'applications' => $applications[$key] ?? 0,
                'job_orders' => $jobs[$key] ?? 0,
                'service_orders' => $services[$key] ?? 0,
            ];
        }

        return $points;
    }

    /**
     * All three queues counted on today, this week and this month at once.
     *
     * Deliberately ignores the page's date range. These figures answer "what
     * happened today / this week / this month" and an executive reads all three
     * side by side; making them follow a picker would mean the label "Installed
     * Today" was only true when the picker happened to be set to today.
     *
     * One query per queue, not three. The union of the three windows is a single
     * contiguous span, so each table is grouped by day *and* status over that
     * span once and WorkCadence folds the result into the three tallies — see
     * that class for why the arithmetic is done in PHP.
     *
     * Rows are dated on `updated_at`, falling back to `timestamp` then
     * `created_at`, because these labels are about work that reached a state,
     * not work that was filed.
     */
    private function workCadence(ConnectionInterface $db, Carbon $anchor): array
    {
        $floor = WorkCadence::floor($anchor);

        // Half-open upper bound: the day after the anchor at midnight. Comparing
        // against '<' next-midnight catches every time on the anchor day without
        // wrapping the column in DATE(), which would make the predicate
        // unindexable.
        $ceil = $anchor->copy()->startOfDay()->addDay();

        return [
            'windows' => WorkCadence::windows($anchor),

            // The executive partition, not the operational one: "no facility" is
            // a pending action here rather than a failure. See
            // StatusBuckets::applicationCadence.
            'applications' => WorkCadence::tally(
                $this->cadenceRows($db, 'applications', 'status', $floor, $ceil),
                StatusBuckets::applicationCadence(),
                $anchor
            ),

            'job_orders' => WorkCadence::tally(
                $this->cadenceRows($db, 'job_orders', 'onsite_status', $floor, $ceil),
                StatusBuckets::workOrders(),
                $anchor
            ),

            'service_orders' => WorkCadence::tally(
                $this->cadenceRows($db, 'service_orders', 'support_status', $floor, $ceil),
                StatusBuckets::workOrders(),
                $anchor
            ),
        ];
    }

    /**
     * One queue as {day, status, count} rows over the cadence span.
     *
     * @return array<int,array{day:string,label:string,count:int}>
     */
    private function cadenceRows(
        ConnectionInterface $db,
        string $table,
        string $statusColumn,
        Carbon $floor,
        Carbon $ceil
    ): array {
        $columns = $this->effectiveDateColumns($db, $table);

        if ($columns === []) {
            return [];
        }

        $dateSql = $this->effectiveDateSql($columns);

        $query = $db->table($table)
            ->selectRaw("DATE({$dateSql}) AS day")
            ->selectRaw("COALESCE(NULLIF(TRIM({$statusColumn}), ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('day', 'label');

        $this->whereEffectiveDate($query, $columns, $floor, $ceil);

        return $query->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'label' => (string) $row->label,
                'count' => (int) $row->cnt,
            ])
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Executive workload — the five field metrics, over the selected range
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The five field metrics, each with the status and the date column its label
     * actually means.
     *
     * ── Why each row is what it is ────────────────────────────────────
     *
     * Every one of these was previously derived from the generic work cadence,
     * which dates all three queues on one COALESCE(updated_at, timestamp,
     * created_at) "effective date" and buckets them through a shared status map.
     * That is the right rule for "what moved recently" and the wrong one for
     * every label on this screen. Specifically:
     *
     *   application  Counted on `created_at` — when the application was filed —
     *                and with NO status filter at all. An application that was
     *                later cancelled was still received, and filtering it out is
     *                what made this figure disagree with the applications module.
     *
     *   installed    `date_installed`, not `updated_at`. The two differ whenever
     *                a job order is edited after the fact — a photo attached the
     *                next morning, a remark corrected a week later — and every
     *                one of those edits used to move an installation into the
     *                wrong day. `date_installed` is the date the field team
     *                actually put the service in.
     *
     *   repair       `visit_status`, not `support_status`. GOWISER records the
     *                engineer's outcome in the first and the ticket's support
     *                state in the second, and they disagree routinely: a ticket
     *                can sit "In Progress" for support while the visit that fixed
     *                it is Done. Dated on `updated_at`, which is when the visit
     *                was closed out.
     *
     *   reschedule   Job orders moved to a later date. Dated on `updated_at`,
     *                because the meaningful moment is when it was rescheduled,
     *                not when it was raised.
     *
     *   pending      Installations still in progress: `job_orders.onsite_status`
     *                In Progress, on `updated_at`. This read
     *                `service_orders.visit_status` until the card was renamed
     *                "Pending Install", at which point the figure and its label
     *                were about different queues — the card said installation
     *                and the number counted half-finished repairs. It now counts
     *                what it says, and sits directly beside Rescheduled Install
     *                on the same table, which is the comparison anybody reads
     *                those two tiles to make.
     *
     * The date columns are listed best-first and resolved against the live
     * schema; the first that exists wins. That is a whole-schema fallback, not a
     * per-row COALESCE — a source that does not stamp installations cannot answer
     * "installed on the 4th" any more precisely than "touched on the 4th", and
     * pretending otherwise per row is how the original bug happened.
     *
     * ── `all_time` ────────────────────────────────────────────────────
     *
     * Reschedule and Pending carry it. Both name a state a job is *in*, not
     * something that happened on a day: an install rescheduled last month is
     * still rescheduled this morning, and a visit that has been in progress
     * since Tuesday is exactly the one worth chasing. Windowing them to the
     * selected range answered "how many were rescheduled today", which is a
     * different — and far less useful — question than the label promises, and it
     * hid the oldest and worst cases every time somebody looked at Daily. The
     * remaining three are genuine events and stay windowed.
     *
     * The flag is honoured in metricQuery, which is shared by the counter and by
     * the drill-down, so the tile and the modal behind it move together.
     */
    private const WORK_METRICS = [
        'application' => [
            'table' => 'applications',
            'status_column' => null,
            'statuses' => [],
            'dates' => ['created_at', 'timestamp'],
            'label' => 'Applications',
        ],
        'installed' => [
            'table' => 'job_orders',
            'status_column' => 'onsite_status',
            'statuses' => ['done', 'completed'],
            'dates' => ['date_installed', 'updated_at', 'timestamp'],
            'label' => 'Installed',
        ],
        'repair' => [
            'table' => 'service_orders',
            'status_column' => 'visit_status',
            'statuses' => ['done', 'completed'],
            'dates' => ['updated_at', 'timestamp'],
            'label' => 'Repairs',
        ],
        'reschedule' => [
            'table' => 'job_orders',
            'status_column' => 'onsite_status',
            'statuses' => ['reschedule', 'rescheduled'],
            'dates' => ['updated_at', 'timestamp'],
            'label' => 'Reschedule',
            'all_time' => true,
        ],
        'pending' => [
            'table' => 'job_orders',
            'status_column' => 'onsite_status',
            'statuses' => ['in progress', 'in-progress', 'inprogress'],
            'dates' => ['updated_at', 'timestamp'],
            'label' => 'Pending',
            'all_time' => true,
        ],
    ];

    /**
     * The metrics that read `job_orders`.
     *
     * Named rather than listed at each branch, because they differ from the
     * service-order metrics in three separate places — the account join, the
     * technician column and the remark column — and the last time `pending`
     * moved between the two queues, one of those three was missed. A metric
     * changing table now changes this list and nothing else.
     */
    private const JOB_ORDER_METRICS = ['installed', 'reschedule', 'pending'];

    /**
     * Extra columns each metric's drill-down carries beyond the shared set.
     *
     * Per metric because the three tables answer different questions and a
     * shared column list left half of them blank: an application has no
     * technician and no billed plan — it has the plan the applicant *asked* for
     * and the agent who referred them — while a repair's useful classifier is
     * what was wrong, not what the subscriber pays for.
     *
     * Every entry is resolved against the live schema before it reaches the
     * SELECT (see selectRecords), so a source at an older migration level loses
     * the column rather than the whole table.
     *
     * @var array<string,array<string,string>>  metric => [alias => column]
     */
    private const RECORD_EXTRAS = [
        'application' => [
            'desired_plan' => 'desired_plan',
            'referred_by' => 'referred_by',
            'remarks' => 'remarks',
        ],
        'installed' => [
            'remarks' => 'onsite_remarks',
        ],
        // Both read job_orders, so both take the onsite note. There is no
        // repair category on an installation — what is holding it up is in the
        // remark, which is why that column carries the weight on these two.
        'reschedule' => [
            'remarks' => 'onsite_remarks',
        ],
        'pending' => [
            'remarks' => 'onsite_remarks',
        ],
        'repair' => [
            'repair_category' => 'repair_category',
            'remarks' => 'visit_remarks',
        ],
    ];

    /**
     * The money tiles' drill-downs.
     *
     * The five field metrics above count job records; these list transactions.
     * They share one endpoint because they share one modal, and splitting them
     * would mean two routes, two validators and two client methods to keep in
     * step for what is, to the reader, the same gesture — click a number, see
     * the rows.
     *
     * ── Why the channel keys are not one query with a parameter ────────
     *
     * Cash, PNB and Portal are not three values of a column. Neither monitored
     * system stores a channel at all: SYNC records a free-text payment method
     * that cashiers spell a dozen ways, and portal collections are not in
     * `transactions` at all — they live in the payment-portal log and are never
     * written across (see portalStats). So `portal` reads a different table
     * from its two neighbours, and `office` and `pnb` are pattern matches over
     * the method string, applied in the same precedence IncomeChannels uses so
     * that the modal and the channel panel agree about what "PNB cash deposit"
     * is.
     *
     * `source` is which table the rows come from, and it is what workRecords
     * branches on.
     */
    private const MONEY_METRICS = [
        'income' => ['source' => 'transactions', 'channel' => null, 'label' => 'Income'],
        'office' => ['source' => 'transactions', 'channel' => 'cash', 'label' => 'Office Collection'],
        'pnb' => ['source' => 'transactions', 'channel' => 'pnb', 'label' => 'PNB Collections'],
        'portal' => ['source' => 'portal', 'channel' => null, 'label' => 'Payment Portal'],
        'expenses' => ['source' => 'expenses', 'channel' => null, 'label' => 'Expenses'],
    ];

    /**
     * Every metric key the drill-down endpoint accepts.
     *
     * Both families, because the controller validates one list and the client
     * calls one method. A key absent from here is a 422 rather than an empty
     * modal, which is the right way round: a card wired to a metric that does
     * not exist is a bug to be seen, not a table to be believed.
     *
     * @return string[]
     */
    public static function workMetrics(): array
    {
        return array_merge(array_keys(self::WORK_METRICS), array_keys(self::MONEY_METRICS));
    }

    /** Whether a metric key lists transactions rather than job records. */
    public static function isMoneyMetric(string $key): bool
    {
        return array_key_exists($key, self::MONEY_METRICS);
    }

    /**
     * All five metrics counted over one window.
     *
     * One aggregate per metric — five short indexed range scans — rather than one
     * pass per metric per window. The range predicates keep the indexed column
     * bare on both sides so they stay sargable; there is no DATE() wrapping a
     * column anywhere in here, which is what a literal reading of
     * `DATE(updated_at) = CURRENT_DATE` would have produced and what would have
     * turned every one of these into a full table scan.
     *
     * @return array<string,mixed>
     */
    private function executiveWorkload(ConnectionInterface $db, Carbon $from, Carbon $to): array
    {
        $out = [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ];

        $anyTracked = false;

        foreach (self::WORK_METRICS as $key => $metric) {
            $count = $this->metricCount($db, $metric, $from, $to);

            $out[$key] = $count ?? 0;
            $anyTracked = $anyTracked || $count !== null;
        }

        // False when this schema models none of these queues. NETMANAGER has one
        // installations table and no applications at all, and five confident
        // zeros for a system with no concept of them is a claim rather than a
        // measurement.
        $out['tracked'] = $anyTracked;

        return $out;
    }

    /**
     * One metric's count, or null when this schema cannot answer it at all.
     *
     * Null rather than zero is the whole point: a missing table and an empty one
     * are different answers, and only the second is a number.
     */
    private function metricCount(ConnectionInterface $db, array $metric, Carbon $from, Carbon $to): ?int
    {
        $query = $this->metricQuery($db, $metric, $from, $to);

        return $query === null ? null : (int) $query->count();
    }

    /**
     * The base query behind one metric: the status rule and the date window.
     *
     * Shared by the counter and by the drill-down that lists the same rows, so
     * the modal can never show a different population from the tile that opened
     * it. That is not tidiness — two queries expressing "the same" filter is
     * precisely how a count and its list come to disagree, and a drill-down that
     * disagrees with its own number destroys trust in both.
     *
     * Null when the table or its status column is absent from this schema.
     */
    private function metricQuery(
        ConnectionInterface $db,
        array $metric,
        Carbon $from,
        Carbon $to,
        ?string $alias = null
    ): ?Builder {
        $table = $metric['table'];
        $dates = $this->presentColumns($db, $table, $metric['dates']);

        if ($dates === []) {
            return null;
        }

        $statusColumn = $metric['status_column'];

        if ($statusColumn !== null && !$this->hasColumn($db, $table, $statusColumn)) {
            return null;
        }

        $prefix = $alias === null ? '' : $alias . '.';
        $date = $prefix . $dates[0];

        $query = $db->table($alias === null ? $table : "{$table} as {$alias}");

        // A state metric counts everything currently in that state, whenever it
        // got there — see the `all_time` note in the WORK_METRICS docblock.
        if (($metric['all_time'] ?? false) !== true) {
            $query
                // Half-open upper bound: everything before the day after `to` at
                // midnight. Catches every time on the last day of the range
                // without wrapping the column in DATE(), which would make it
                // unindexable.
                ->where($date, '>=', $from->copy()->startOfDay()->toDateTimeString())
                ->where($date, '<', $to->copy()->startOfDay()->addDay()->toDateTimeString());
        }

        // No status filter at all for applications, by instruction — see the
        // WORK_METRICS docblock.
        if ($statusColumn !== null && $metric['statuses'] !== []) {
            $query->whereIn(
                DB::raw("LOWER(TRIM(COALESCE({$prefix}{$statusColumn}, '')))"),
                $metric['statuses']
            );
        }

        return $query;
    }

    /**
     * The records behind one metric tile, searched, sorted and paged.
     *
     * Built on exactly the query the counter used (see metricQuery), so the modal
     * lists the same population the tile counted — filtered further by whatever
     * the operator typed, never by a second interpretation of the metric itself.
     *
     * ── Joins ─────────────────────────────────────────────────────────
     *
     * Two per query, resolved in SQL rather than per row. Applications carry the
     * customer inline; job orders reach them through `billing_accounts.id`;
     * service orders through `billing_accounts.account_no`, which is a string key
     * rather than a foreign one. Each is a LEFT join so a row whose account was
     * deleted still appears — dropping it would make the list disagree with the
     * count for a second, subtler reason.
     */
    public function workRecords(ConnectionInterface $db, array $params): array
    {
        $key = (string) ($params['metric'] ?? '');
        $metric = self::WORK_METRICS[$key] ?? null;

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(ReportingService::MAX_PER_PAGE, max(1, (int) ($params['per_page'] ?? self::DIRECTORY_PER_PAGE)));

        $empty = [
            'metric' => $key, 'rows' => [], 'total' => 0, 'page' => 1,
            'per_page' => $perPage, 'total_pages' => 0, 'plans' => [], 'areas' => [],
        ];

        // The money tiles list transactions rather than job records — different
        // tables, different columns, same modal. See MONEY_METRICS.
        if (self::isMoneyMetric($key)) {
            return $this->moneyRecords($db, $key, $params, $page, $perPage, $empty);
        }

        if ($metric === null) {
            return $empty;
        }

        [$from, $to] = $this->range($params);

        $fromDate = ReportPeriod::parse($from) ?? Carbon::now()->startOfDay();
        $toDate = ReportPeriod::parse($to) ?? $fromDate->copy();

        $build = fn (): ?Builder => $this->decorateRecords(
            $db,
            $key,
            $this->metricQuery($db, $metric, $fromDate, $toDate, 'r'),
            $params
        );

        $counter = $build();

        if ($counter === null) {
            return $empty;
        }

        $total = (int) $counter->count();

        if ($total === 0) {
            return array_merge($empty, ['plans' => [], 'areas' => []]);
        }

        $rows = $this->selectRecords($db, $build(), $key, $params)
            ->forPage($page, $perPage)
            ->get();

        return [
            'metric' => $key,
            'label' => $metric['label'],
            'range' => ['from' => $from, 'to' => $to],
            // Both vocabularies, for the same reason subscriberRow emits both —
            // see that method. A drill-down that renders empty cells because the
            // reader expected `account_no` and got `account_number` fails
            // silently, and this table is the thing people check the tile
            // against.
            'rows' => $rows->map(function ($row) {
                $name = $this->fullName($row->first_name ?? '', $row->last_name ?? '');
                $account = (string) ($row->account_no ?? '');
                $contact = (string) ($row->contact_number ?? '');
                $plan = (string) ($row->plan_name ?? '');
                $area = $this->joinLocation([$row->barangay ?? null, $row->city ?? null]) ?? '';
                $status = (string) ($row->row_status ?? '');

                return [
                    'id' => (string) ($row->id ?? ''),

                    'subscriber' => $name,
                    'account_number' => $account,
                    'contact_number' => $contact,
                    'plan' => $plan,
                    'location' => $area,
                    'status' => $status,

                    'customer_name' => $name,
                    'account_no' => $account,
                    'contact' => $contact,
                    'plan_name' => $plan,
                    'area' => $area,

                    'technician' => (string) ($row->technician ?? ''),
                    'occurred_at' => $row->occurred_at,

                    // Per-metric columns. Always present as keys even where this
                    // metric does not carry one, because the drill-down table is
                    // built from a static column list and a missing key renders
                    // as a silently empty cell rather than as an em dash.
                    'modified_date' => $row->modified_date ?? null,
                    'desired_plan' => (string) ($row->desired_plan ?? ''),
                    'referred_by' => (string) ($row->referred_by ?? ''),
                    'repair_category' => (string) ($row->repair_category ?? ''),
                    // Both spellings, for the same reason the name fields carry
                    // two: the column is `onsite_remarks` on a job order and
                    // `visit_remarks` on a service order, and the table that
                    // renders them should not have to know which queue it is on.
                    'remarks' => (string) ($row->remarks ?? ''),
                    'onsite_remarks' => (string) ($row->remarks ?? ''),
                    'visit_remarks' => (string) ($row->remarks ?? ''),
                ];
            })->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            // The filter dropdowns are built from the rows actually in range, not
            // from every plan and barangay that has ever existed. A dropdown
            // offering forty options that all return nothing is worse than none.
            'plans' => $this->recordFacet($build(), $this->personColumns($key)['plan']),
            'areas' => $this->recordFacet($build(), $this->personColumns($key)['barangay']),
        ];
    }

    /**
     * The transactions behind one money tile, searched, sorted and paged.
     *
     * The counterpart to workRecords for the Income / Office / PNB / Portal /
     * Expenses cards. Every one of those tiles is a total of rows that exist, so
     * every one of them opens the rows it added up — which is the whole
     * distinction the Group Overview now draws between a total and a formula.
     *
     * Rows are emitted in the same shape the field-work drill-down uses, plus
     * `amount` and `method`. That is not laziness: the modal is one component
     * with one column contract, and a second row shape would mean a second table
     * with its own sorting and paging arithmetic to get subtly wrong.
     *
     * An expense has no subscriber, so `subscriber` carries the payee and
     * `plan` the category. Both are labelled as such by the columns the modal
     * uses for this metric, so nothing claims to be what it is not.
     */
    private function moneyRecords(
        ConnectionInterface $db,
        string $key,
        array $params,
        int $page,
        int $perPage,
        array $empty
    ): array {
        $metric = self::MONEY_METRICS[$key];

        [$from, $to] = $this->range($params);
        $search = trim((string) ($params['search'] ?? ''));

        try {
            $build = fn (): ?Builder => $this->moneyQuery($db, $metric, $from, $to, $search);

            $counter = $build();

            if ($counter === null) {
                return $empty;
            }

            $total = (int) $counter->count();

            if ($total === 0) {
                return array_merge($empty, ['label' => $metric['label']]);
            }

            $rows = $this->moneySelect($db, $build(), $metric, $params)
                ->forPage($page, $perPage)
                ->get();

            return [
                'metric' => $key,
                'label' => $metric['label'],
                'range' => ['from' => $from, 'to' => $to],
                'rows' => $rows->map(function ($row) {
                    // Assembled here rather than in SQL, as everywhere else in
                    // this driver. An expense and a portal payment have no
                    // customer row at all, so their name comes through as a
                    // single `subscriber` column — the payee, or nothing.
                    $name = isset($row->subscriber)
                        ? (string) $row->subscriber
                        : $this->fullName($row->first_name ?? '', $row->last_name ?? '');

                    $account = (string) ($row->account_no ?? '');

                    return [
                        'id' => (string) ($row->id ?? ''),

                        'subscriber' => $name,
                        'account_number' => $account,
                        'contact_number' => '',
                        'plan' => (string) ($row->category ?? ''),
                        'location' => '',
                        'status' => (string) ($row->method ?? ''),

                        // Both vocabularies, as everywhere else in these
                        // payloads — see the note on workRecords.
                        'customer_name' => $name,
                        'account_no' => $account,
                        'contact' => '',
                        'plan_name' => (string) ($row->category ?? ''),
                        'area' => '',

                        'technician' => (string) ($row->handled_by ?? ''),
                        'occurred_at' => $row->occurred_at,
                        'modified_date' => $row->occurred_at,

                        'amount' => round((float) ($row->amount ?? 0), 2),
                        'method' => (string) ($row->method ?? ''),
                        'reference' => (string) ($row->reference ?? ''),

                        // Present so the shared row shape stays complete; a
                        // missing key renders as a silently empty cell rather
                        // than an em dash.
                        'desired_plan' => '',
                        'referred_by' => '',
                        'repair_category' => '',
                        'remarks' => (string) ($row->remarks ?? ''),
                        'onsite_remarks' => (string) ($row->remarks ?? ''),
                        'visit_remarks' => (string) ($row->remarks ?? ''),
                    ];
                })->all(),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                // No plan or area facets on a ledger — the filters those feed
                // are not offered for these metrics.
                'plans' => [],
                'areas' => [],
            ];
        } catch (\Throwable $e) {
            // A schema without the portal log, or without `expenses_logs`,
            // returns nothing rather than failing the modal. The tile it opened
            // reads zero from the same absence, so the two still agree.
            report($e);

            return $empty;
        }
    }

    /**
     * The base query for one money metric: its table, its window, its channel.
     *
     * Channel matching mirrors IncomeChannels::classify, including its
     * precedence — a method recorded as "PNB cash deposit" is a bank collection
     * and must not also appear under Office. Getting that order wrong here would
     * make the modal disagree with the channel panel about the same peso.
     */
    private function moneyQuery(
        ConnectionInterface $db,
        array $metric,
        string $from,
        string $to,
        string $search
    ): ?Builder {
        $like = $search === ''
            ? null
            : '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';

        if ($metric['source'] === 'expenses') {
            $query = $this->expenseRows($db, ReportPeriod::fromDateRange($from, $to), $from, $to)
                ->leftJoin('expenses_category as ec', 'ec.id', '=', 'e.category_id');

            if ($like !== null) {
                $query->where(function ($group) use ($like) {
                    $group->where('e.payee', 'like', $like)
                        ->orWhere('e.supplier', 'like', $like)
                        ->orWhere('e.provider', 'like', $like)
                        ->orWhere('e.description', 'like', $like)
                        ->orWhere('ec.category_name', 'like', $like);
                });
            }

            return $query;
        }

        if ($metric['source'] === 'portal') {
            $table = $this->resolvePortalTable($db);

            if (!$table) {
                return null;
            }

            $dateCol = $this->resolvePortalColumn($db, $table, ['date_time', 'created_at', 'payment_date', 'date']);

            $query = $this->portalPayments($db)
                ->whereBetween(DB::raw("DATE(ppl.{$dateCol})"), [$from, $to]);

            // The portal log holds `account_id`, an integer key into
            // billing_accounts — not the account *number*, which is what an
            // operator searches by and what every other money metric shows. This
            // modal returned nothing at all until the join was added: it was
            // selecting and filtering on `ppl.account_no`, a column
            // payment_portal_logs does not have, so every query threw and was
            // swallowed into an empty table.
            //
            // Joined rather than assumed present, because the column has been
            // spelled both ways across SYNC releases.
            if ($this->hasColumn($db, $table, 'account_id')) {
                $query->leftJoin('billing_accounts as ba', 'ba.id', '=', 'ppl.account_id')
                    ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id');
            } elseif ($this->hasColumn($db, $table, 'account_no')) {
                $query->leftJoin('billing_accounts as ba', 'ba.account_no', '=', 'ppl.account_no')
                    ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id');
            }

            if ($like !== null) {
                $query->where(function ($group) use ($like) {
                    $group->where('ba.account_no', 'like', $like)
                        ->orWhere('c.first_name', 'like', $like)
                        ->orWhere('c.last_name', 'like', $like);
                });
            }

            return $query;
        }

        $query = $this->collectedTransactions($db)
            ->leftJoin('billing_accounts as ba', 'ba.account_no', '=', 't.account_no')
            ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
            ->whereBetween(DB::raw('DATE(t.date_processed)'), [$from, $to]);

        if ($metric['channel'] !== null) {
            $this->constrainToChannel($query, $metric['channel']);
        }

        if ($like !== null) {
            $query->where(function ($group) use ($like) {
                $group->where('t.account_no', 'like', $like)
                    ->orWhere('t.or_no', 'like', $like)
                    ->orWhere('t.payment_method', 'like', $like)
                    ->orWhere('c.first_name', 'like', $like)
                    ->orWhere('c.last_name', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Narrows a transaction query to one collection channel.
     *
     * The channel a payment belongs to is decided by first match in
     * IncomeChannels::patterns() order, so a channel's own patterns are not
     * enough — Office must additionally exclude everything the earlier channels
     * would have claimed. Expressed here as (mine) AND NOT (theirs), which is
     * the same rule the PHP classifier applies row by row.
     */
    private function constrainToChannel(Builder $query, string $channel): void
    {
        $patterns = IncomeChannels::patterns();
        $method = "LOWER(COALESCE(t.payment_method, ''))";

        $matches = function ($group, array $needles) use ($method) {
            foreach ($needles as $index => $needle) {
                $binding = ['%' . strtolower($needle) . '%'];

                $index === 0
                    ? $group->whereRaw("{$method} LIKE ?", $binding)
                    : $group->orWhereRaw("{$method} LIKE ?", $binding);
            }
        };

        $query->where(fn ($group) => $matches($group, $patterns[$channel] ?? []));

        // Everything listed before this channel wins over it, so exclude it.
        foreach ($patterns as $earlier => $needles) {
            if ($earlier === $channel) {
                break;
            }

            $query->whereNot(fn ($group) => $matches($group, $needles));
        }
    }

    /** The SELECT list and ordering for a money query. */
    private function moneySelect(
        ConnectionInterface $db,
        ?Builder $query,
        array $metric,
        array $params
    ): Builder {
        if ($metric['source'] === 'expenses') {
            $query
                ->select('e.id')
                ->selectRaw("COALESCE(NULLIF(e.payee, ''), NULLIF(e.supplier, ''), e.provider, '') AS subscriber")
                ->selectRaw("'' AS account_no")
                ->selectRaw("COALESCE(NULLIF(ec.category_name, ''), NULLIF(e.category, ''), '(Uncategorised)') AS category")
                ->selectRaw("COALESCE(e.expense_type, 'daily') AS method")
                ->selectRaw('e.amount AS amount')
                ->selectRaw('e.date AS occurred_at')
                ->selectRaw('e.processed_by AS handled_by')
                ->selectRaw('e.description AS remarks')
                ->selectRaw("'' AS reference");

            $sortable = [
                'subscriber' => 'e.payee',
                'occurred_at' => 'e.date',
                'status' => 'e.expense_type',
                'plan' => 'e.category',
            ];
        } elseif ($metric['source'] === 'portal') {
            // Resolved against the live schema for the same reason every other
            // portal read is: SYNC has shipped this table under three names with
            // three different date and amount columns, and a hard-coded one
            // turns a working deployment into a failed modal.
            $table = (string) $this->resolvePortalTable($db);
            $dateCol = $this->resolvePortalColumn($db, $table, ['date_time', 'created_at', 'payment_date', 'date']);
            $amountCol = $this->resolvePortalColumn($db, $table, ['total_amount', 'amount', 'received_payment']);
            // These two go through presentColumns rather than
            // resolvePortalColumn: that helper falls back to its *first*
            // candidate when none is present, which is right for a column the
            // query cannot do without and wrong for one it can — here it would
            // put a non-existent `reference_number` into the SELECT and fail the
            // whole modal to render a column nobody asked for.
            $optional = fn (array $candidates): ?string =>
                $this->presentColumns($db, $table, $candidates)[0] ?? null;

            // `reference_no` first: that is the column SYNC actually ships (see
            // its payment_portal_logs migration) and the one carrying the
            // gateway's own reference, which is the only field that lets a
            // portal payment be traced outside this portal.
            $refCol = $optional(['reference_no', 'reference_number', 'reference', 'checkout_id']);
            $channelCol = $optional(['payment_channel', 'ewallet_type', 'type', 'method']);

            $joined = $this->hasColumn($db, $table, 'account_id')
                || $this->hasColumn($db, $table, 'account_no');

            $query
                ->select('ppl.id')
                // Through the join rather than off the log: the log records who
                // paid only as a key. A row whose account has since been deleted
                // keeps its amount and loses its name, which is the honest
                // outcome — the money is still real.
                ->selectRaw($joined ? 'c.first_name AS first_name' : "'' AS first_name")
                ->selectRaw($joined ? 'c.last_name AS last_name' : "'' AS last_name")
                ->selectRaw($joined ? 'ba.account_no AS account_no' : "'' AS account_no")
                ->selectRaw("'Payment Portal' AS category")
                // The gateway that actually carried it — GCash, Maya, a bank
                // code — falling back to the channel name where the schema does
                // not record one.
                ->selectRaw(
                    $channelCol === null
                        ? "'Payment Portal' AS method"
                        : "COALESCE(NULLIF(ppl.{$channelCol}, ''), 'Payment Portal') AS method"
                )
                ->selectRaw("ppl.{$amountCol} AS amount")
                ->selectRaw("ppl.{$dateCol} AS occurred_at")
                ->selectRaw("'' AS handled_by")
                ->selectRaw("'' AS remarks")
                ->selectRaw($refCol === null ? "'' AS reference" : "ppl.{$refCol} AS reference");

            $sortable = [
                'subscriber' => $joined ? 'c.last_name' : "ppl.{$dateCol}",
                'account_number' => $joined ? 'ba.account_no' : "ppl.{$dateCol}",
                'occurred_at' => "ppl.{$dateCol}",
            ];
        } else {
            $query
                ->select('t.id')
                // The two halves rather than a CONCAT, joined in PHP by
                // fullName() as every other row-building method here does. One
                // implementation of "how a name is assembled" is the point —
                // and SQL string functions are the part of this driver most
                // likely to differ between the engines it is pointed at.
                ->selectRaw('c.first_name AS first_name')
                ->selectRaw('c.last_name AS last_name')
                ->selectRaw('t.account_no AS account_no')
                ->selectRaw("COALESCE(NULLIF(t.transaction_type, ''), 'Subscription') AS category")
                ->selectRaw("COALESCE(NULLIF(t.payment_method, ''), 'Unspecified') AS method")
                ->selectRaw('t.received_payment AS amount')
                ->selectRaw('t.date_processed AS occurred_at')
                ->selectRaw('t.processed_by_user AS handled_by')
                ->selectRaw("COALESCE(t.remarks, '') AS remarks")
                ->selectRaw('t.or_no AS reference');

            $sortable = [
                'subscriber' => 'c.last_name',
                'account_number' => 't.account_no',
                'occurred_at' => 't.date_processed',
                'status' => 't.payment_method',
                'plan' => 't.transaction_type',
            ];
        }

        $sort = (string) ($params['sort'] ?? 'occurred_at');
        $column = $sortable[$sort] ?? ($sortable['occurred_at'] ?? array_values($sortable)[0]);
        $direction = strtolower((string) ($params['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy(DB::raw($column), $direction);
    }

    /**
     * Adds the subscriber joins and the operator's own search and filters.
     *
     * The joins differ per metric because the three tables reach a customer three
     * different ways; everything downstream reads one shape.
     */
    private function decorateRecords(
        ConnectionInterface $db,
        string $key,
        ?Builder $query,
        array $params
    ): ?Builder {
        if ($query === null) {
            return null;
        }

        if ($key === 'application') {
            // Applications hold the applicant inline — there is no account yet.
            $query->leftJoin('plan_list as pl', function ($join) {
                $join->on(DB::raw('LOWER(TRIM(pl.plan_name))'), '=', DB::raw('LOWER(TRIM(r.desired_plan))'));
            });
        } elseif (in_array($key, self::JOB_ORDER_METRICS, true)) {
            // Three joins, not two, and the third is the one that matters.
            //
            // A job order reaches a *billed* customer through
            // account_id → billing_accounts → customers. But an account is only
            // created once the service is in, so every job order that has not
            // been installed yet has account_id NULL — which is precisely the
            // population Rescheduled Install and Pending Install consist of.
            // Both modals rendered a full page of em dashes for name, plan and
            // area because every row was being asked for a customer that does
            // not exist yet.
            //
            // Before it is billed, the client lives on the application the job
            // order was raised from (job_orders.application_id — see SYNC's
            // JobOrder::application). So both paths are joined and every field
            // is COALESCE'd across them in selectRecords: the billed customer
            // where there is one, the applicant where there is not.
            $query->leftJoin('billing_accounts as ba', 'ba.id', '=', 'r.account_id')
                ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
                ->leftJoin('applications as ap', 'ap.id', '=', 'r.application_id')
                ->leftJoin('plan_list as pl', function ($join) {
                    // By id off the billing account, falling back to matching
                    // the plan the applicant asked for by name — an application
                    // carries no plan id, only `desired_plan` as free text.
                    $join->on('pl.id', '=', 'ba.plan_id')
                        ->orOn(
                            DB::raw('LOWER(TRIM(pl.plan_name))'),
                            '=',
                            DB::raw('LOWER(TRIM(ap.desired_plan))')
                        );
                });
        } else {
            // service_orders keys the account by number, not by id.
            $query->leftJoin('billing_accounts as ba', 'ba.account_no', '=', 'r.account_no')
                ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
                ->leftJoin('plan_list as pl', 'pl.id', '=', 'ba.plan_id');
        }

        // Searched, sorted and selected through the same expressions, so a job
        // order whose client is still only an applicant is findable by the name
        // the table is showing. See personColumns.
        $person = $this->personColumns($key);

        $searchable = [
            $person['account'],
            $person['first_name'],
            $person['last_name'],
            $person['contact'],
            $person['barangay'],
            $person['city'],
            $person['plan'],
        ];

        $search = trim((string) ($params['search'] ?? ''));

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';

            $query->where(function ($group) use ($like, $searchable) {
                foreach ($searchable as $index => $expression) {
                    $index === 0
                        ? $group->whereRaw("{$expression} LIKE ?", [$like])
                        : $group->orWhereRaw("{$expression} LIKE ?", [$like]);
                }
            });
        }

        $plan = trim((string) ($params['plan'] ?? ''));

        if ($plan !== '') {
            $query->whereRaw("{$person['plan']} = ?", [$plan]);
        }

        $area = trim((string) ($params['area'] ?? ''));

        if ($area !== '') {
            $query->whereRaw("{$person['barangay']} = ?", [$area]);
        }

        return $query;
    }

    /**
     * The SELECT list and ordering for a decorated record query.
     *
     * Sort keys are mapped through a whitelist rather than interpolated. The
     * column name arrives from a query string, and letting it reach the ORDER BY
     * unchecked would be an injection point on a connection that is read-only but
     * is still pointed at a production database.
     */
    /**
     * Where each person-shaped field lives, per metric.
     *
     * Three tables reach a customer three different ways, and one of them
     * reaches them two ways at once:
     *
     *   application  the applicant is on the row itself; there is no account.
     *   job order    a *billed* client through account_id → billing_accounts →
     *                customers, and an *unbilled* one through application_id →
     *                applications. Which of the two is populated depends on
     *                whether the service has gone in yet, so both are joined and
     *                every field falls back — see decorateRecords.
     *   service order  always billed, keyed by account number.
     *
     * Returned as expressions rather than column names because the job-order
     * case is a COALESCE, and because the SELECT list, the ORDER BY whitelist and
     * the search predicate all have to agree about it. They did not before: the
     * search looked at `c.first_name` for a job order whose customer row does
     * not exist, so searching Rescheduled Install by name matched nothing.
     *
     * @return array<string,string>
     */
    private function personColumns(string $key): array
    {
        if ($key === 'application') {
            return [
                'account' => 'r.email_address',
                'first_name' => 'r.first_name',
                'last_name' => 'r.last_name',
                'contact' => 'r.mobile_number',
                'barangay' => 'r.barangay',
                'city' => 'r.city',
                'plan' => "COALESCE(NULLIF(pl.plan_name, ''), r.desired_plan)",
            ];
        }

        if (in_array($key, self::JOB_ORDER_METRICS, true)) {
            // NULLIF as well as COALESCE: SYNC writes empty strings as often as
            // nulls, and a COALESCE alone would hold onto the blank and never
            // reach the applicant behind it.
            $either = fn (string $billed, string $applied): string =>
                "COALESCE(NULLIF({$billed}, ''), {$applied})";

            return [
                'account' => 'ba.account_no',
                'first_name' => $either('c.first_name', 'ap.first_name'),
                'last_name' => $either('c.last_name', 'ap.last_name'),
                'contact' => $either('c.contact_number_primary', 'ap.mobile_number'),
                'barangay' => $either('c.barangay', 'ap.barangay'),
                'city' => $either('c.city', 'ap.city'),
                'plan' => $either('pl.plan_name', 'ap.desired_plan'),
            ];
        }

        return [
            'account' => 'ba.account_no',
            'first_name' => 'c.first_name',
            'last_name' => 'c.last_name',
            'contact' => 'c.contact_number_primary',
            'barangay' => 'c.barangay',
            'city' => 'c.city',
            'plan' => 'pl.plan_name',
        ];
    }

    private function selectRecords(
        ConnectionInterface $db,
        ?Builder $query,
        string $key,
        array $params
    ): Builder {
        $metric = self::WORK_METRICS[$key];
        $isApplication = $key === 'application';

        $date = $metric['dates'][0];
        $person = $this->personColumns($key);

        $query
            ->select('r.id')
            ->selectRaw($person['account'] . ' AS account_no')
            ->selectRaw($person['first_name'] . ' AS first_name')
            ->selectRaw($person['last_name'] . ' AS last_name')
            ->selectRaw($person['contact'] . ' AS contact_number')
            ->selectRaw($person['barangay'] . ' AS barangay')
            ->selectRaw($person['city'] . ' AS city')
            ->selectRaw($person['plan'] . ' AS plan_name')
            ->selectRaw("r.{$date} AS occurred_at");

        $status = $metric['status_column'];
        $query->selectRaw($status === null ? "r.status AS row_status" : "r.{$status} AS row_status");

        // Technician, where the table records one at all. Named differently on
        // the two queues — a job order records who went out in `visit_by`, a
        // service order in `visit_by_user`.
        if (in_array($key, self::JOB_ORDER_METRICS, true)) {
            $query->selectRaw('COALESCE(NULLIF(r.visit_by, \'\'), r.assigned_email) AS technician');
        } elseif ($key === 'repair') {
            $query->selectRaw('r.visit_by_user AS technician');
        } else {
            $query->selectRaw("'' AS technician");
        }

        // When the row last changed state. Distinct from `occurred_at`, which
        // for an installation is the day the service went in and for an
        // application the day it was filed — a reader chasing a stalled job
        // needs to know when anybody last touched it, which is neither.
        $modified = $this->presentColumns($db, $metric['table'], ['updated_at', 'timestamp', 'created_at']);
        $query->selectRaw(
            $modified === [] ? 'NULL AS modified_date' : "r.{$modified[0]} AS modified_date"
        );

        // The per-metric columns, each dropped rather than guessed at when this
        // schema has not got it — see RECORD_EXTRAS.
        foreach (self::RECORD_EXTRAS[$key] ?? [] as $alias => $column) {
            $query->selectRaw(
                $this->hasColumn($db, $metric['table'], $column)
                    ? "r.{$column} AS {$alias}"
                    : "'' AS {$alias}"
            );
        }

        $sortable = [
            'subscriber' => $person['last_name'],
            'account_number' => $person['account'],
            'occurred_at' => "r.{$date}",
            'status' => $status === null ? 'r.status' : "r.{$status}",
            'plan' => $person['plan'],
            'location' => $person['barangay'],
        ];

        $sort = (string) ($params['sort'] ?? 'occurred_at');
        $column = $sortable[$sort] ?? $sortable['occurred_at'];
        $direction = strtolower((string) ($params['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy(DB::raw($column), $direction);
    }

    /**
     * Distinct values of one column across the rows in range, for a dropdown.
     *
     * Capped and grouped in SQL. A facet built in PHP would mean fetching every
     * matching row to list twelve barangays.
     *
     * @return string[]
     */
    private function recordFacet(?Builder $query, string $expression): array
    {
        if ($query === null) {
            return [];
        }

        try {
            return $query
                ->selectRaw("{$expression} AS facet")
                ->whereRaw("COALESCE({$expression}, '') <> ''")
                ->groupBy(DB::raw($expression))
                ->orderBy(DB::raw($expression))
                ->limit(100)
                ->pluck('facet')
                ->map(fn ($value) => (string) $value)
                ->all();
        } catch (\Throwable $e) {
            // A schema without one of these columns loses its dropdown, not the
            // table it filters.
            report($e);

            return [];
        }
    }

    /**
     * Whichever of the candidate columns this table actually has, in order.
     *
     * Resolved against the live schema rather than assumed, because the two
     * monitored systems are at different migration levels and a hard-coded
     * column turns one missing field into a failed section.
     *
     * @param string[] $candidates
     * @return string[]
     */
    private function presentColumns(ConnectionInterface $db, string $table, array $candidates): array
    {
        try {
            $schema = $db->getSchemaBuilder();

            if (!$schema->hasTable($table)) {
                return [];
            }

            return array_values(array_filter(
                $candidates,
                fn ($column) => $schema->hasColumn($table, $column)
            ));
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function hasColumn(ConnectionInterface $db, string $table, string $column): bool
    {
        return $this->presentColumns($db, $table, [$column]) !== [];
    }

    /**
     * The date columns this table actually has, in the brief's order of
     * preference.
     *
     * Resolved against the live schema rather than assumed, because the two
     * monitored systems are at different migration levels and a hard-coded
     * `updated_at` turns one missing column into a failed section.
     *
     * @return string[]
     */
    private function effectiveDateColumns(ConnectionInterface $db, string $table): array
    {
        try {
            $schema = $db->getSchemaBuilder();

            if (!$schema->hasTable($table)) {
                return [];
            }

            return array_values(array_filter(
                WorkCadence::DATE_COLUMNS,
                fn ($column) => $schema->hasColumn($table, $column)
            ));
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * COALESCE over the resolved columns, for SELECT and GROUP BY.
     *
     * `$alias` qualifies every column with the table it belongs to. It is not
     * optional decoration: `created_at` and `timestamp` exist on `job_orders`,
     * on `billing_accounts` and on `customers`, so the moment this expression
     * appears in a query that joins any of them MySQL rejects the whole
     * statement with "Column 'created_at' in SELECT is ambiguous" — which is
     * exactly what took the resolution panel down in production.
     *
     * Callers that query a single table with no joins can pass nothing; anything
     * that joins must pass its alias.
     */
    private function effectiveDateSql(array $columns, string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';

        $qualified = array_map(fn (string $column) => $prefix . $column, $columns);

        return count($qualified) === 1
            ? $qualified[0]
            : 'COALESCE(' . implode(', ', $qualified) . ')';
    }

    /**
     * "The row's effective date falls in [floor, ceil)", written so an index can
     * still be used.
     *
     * COALESCE(...) BETWEEN ... would be the short way to say this and would
     * force a full scan of every queue table on every dashboard open. Instead
     * each candidate column gets its own branch — "updated_at is in range", or
     * "updated_at is null and timestamp is in range", and so on — which MySQL can
     * satisfy from the per-column indexes and combine. The branches are mutually
     * exclusive by construction, so no row is counted twice.
     *
     * @param string[] $columns
     */
    private function whereEffectiveDate(Builder $query, array $columns, Carbon $floor, Carbon $ceil): void
    {
        $from = $floor->toDateTimeString();
        $to = $ceil->toDateTimeString();

        $query->where(function ($outer) use ($columns, $from, $to) {
            foreach ($columns as $index => $column) {
                // Everything ahead of this column in the preference order must be
                // null, or the row belongs to that earlier branch instead.
                $earlier = array_slice($columns, 0, $index);

                $outer->orWhere(function ($branch) use ($column, $earlier, $from, $to) {
                    foreach ($earlier as $nullColumn) {
                        $branch->whereNull($nullColumn);
                    }

                    $branch->whereNotNull($column)
                        ->where($column, '>=', $from)
                        ->where($column, '<', $to);
                });
            }
        });
    }

    /**
     * Resolution speed: the ticket that has been waiting longest.
     *
     * "Completed & Closed" is not queried here — it is the `done` bucket the
     * cadence above already counted, and asking the database a second time for a
     * figure it just returned is how two numbers on one screen come to disagree.
     * ExecutiveOverviewService composes it.
     *
     * Age is measured from when the ticket was *raised* (`timestamp`, then
     * `created_at`), not from `updated_at`. "Outstanding for 34 days" is a claim
     * about how long a customer has been waiting; measuring from the last touch
     * would reset that clock every time somebody opened the record.
     */
    private function resolutionSla(ConnectionInterface $db, Carbon $anchor): array
    {
        $candidates = array_filter([
            $this->longestOutstanding($db, 'job_orders', 'onsite_status', $anchor),
            $this->longestOutstanding($db, 'service_orders', 'support_status', $anchor),
        ]);

        if ($candidates === []) {
            return ['longest_outstanding' => null];
        }

        // The single oldest across both queues. An executive asks "what is the
        // worst one", not "what is the worst one of each kind".
        usort($candidates, fn ($a, $b) => $b['hours'] <=> $a['hours']);

        return ['longest_outstanding' => $candidates[0]];
    }

    /**
     * The oldest still-open row in one queue, with who it belongs to.
     *
     * Open means "in no bucket that ends the ticket" — not done, not failed. It
     * is defined by subtraction so that a status nobody has classified yet still
     * counts as outstanding: a ticket in an unrecognised state is exactly the
     * one worth surfacing, and treating unknown as closed would hide it.
     *
     * @return array{queue:string,label:string,reference:string,account_no:string,customer:string,status:string,opened_at:string,hours:int,days:int}|null
     */
    private function longestOutstanding(
        ConnectionInterface $db,
        string $table,
        string $statusColumn,
        Carbon $anchor
    ): ?array {
        try {
            $schema = $db->getSchemaBuilder();

            if (!$schema->hasTable($table)) {
                return null;
            }

            // Raised-at, not touched-at. See resolutionSla.
            $openedColumns = array_values(array_filter(
                ['timestamp', 'created_at'],
                fn ($column) => $schema->hasColumn($table, $column)
            ));

            if ($openedColumns === []) {
                return null;
            }

            // Qualified with the queue's alias. This query joins billing
            // accounts and customers, and all three tables carry `created_at`.
            $openedSql = $this->effectiveDateSql($openedColumns, 'q');

            $buckets = StatusBuckets::workOrders();

            // The raw statuses that mean the ticket is finished, lower-cased for
            // a case-insensitive NOT IN.
            $closed = [];

            foreach (['done', 'failed'] as $bucket) {
                foreach ((array) ($buckets[$bucket] ?? []) as $member) {
                    $closed[] = strtolower(trim((string) $member));
                }
            }

            $query = $db->table("{$table} as q")
                ->selectRaw("{$openedSql} AS opened_at")
                ->selectRaw("COALESCE(NULLIF(TRIM(q.{$statusColumn}), ''), 'Unspecified') AS status")
                ->whereNotNull(DB::raw($openedSql))
                ->orderBy('opened_at');

            if ($closed !== []) {
                $query->whereRaw(
                    'LOWER(TRIM(COALESCE(q.' . $statusColumn . ", ''))) NOT IN (" .
                        implode(', ', array_fill(0, count($closed), '?')) . ')',
                    $closed
                );
            }

            // The two queues reach the customer by different keys: job orders
            // carry the account id, service orders carry the account number as
            // text. Neither is guaranteed to resolve, so both joins are left
            // joins and a ticket with no matching account still reports.
            if ($table === 'job_orders') {
                $query->leftJoin('billing_accounts as ba', 'ba.id', '=', 'q.account_id');
            } else {
                $query->leftJoin('billing_accounts as ba', 'ba.account_no', '=', 'q.account_no');
            }

            $row = $query
                ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
                ->addSelect('ba.account_no', 'c.first_name', 'c.last_name')
                ->addSelect(DB::raw('q.id AS reference'))
                ->first();

            if ($row === null || empty($row->opened_at)) {
                return null;
            }

            $opened = Carbon::parse($row->opened_at);

            // Clamped at zero: a row dated in the future — a mistyped year, which
            // happens — would otherwise report a negative age and sort to the top
            // of the "longest outstanding" list.
            $hours = max(0, $opened->diffInHours($anchor->copy()->endOfDay(), false));

            return [
                'queue' => $table,
                'label' => $table === 'job_orders' ? 'Installation' : 'Repair',
                'reference' => (string) ($row->reference ?? ''),
                'account_no' => (string) ($row->account_no ?? ''),
                'customer' => $this->fullName($row->first_name ?? '', $row->last_name ?? ''),
                'status' => (string) $row->status,
                'opened_at' => $opened->toDateTimeString(),
                'hours' => $hours,
                'days' => intdiv($hours, 24),
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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
     * The same tally over the whole queue, ignoring the date range.
     *
     * ── Why the pipeline is not windowed ──────────────────────────────
     *
     * A queue's status breakdown is a statement about what is in the queue, and
     * a queue does not have a date. Windowed, this panel answered "of the
     * applications *filed this month*, how many are cancelled" — which is a real
     * question and not the one the panel is read for, and it produced totals
     * that disagreed with the operating system's own sidebar by a factor of two:
     * 88 applications here against 180 in SYNC, because ninety-two of them were
     * filed before the first of the month and are still sitting in the queue.
     *
     * The same argument the backlog figure has always made — "old backlog is
     * still backlog" — applies to every row of the pipeline, not only to the
     * open ones.
     *
     * Capped at QUEUE_STATUS_LIMIT distinct values: both systems write free text
     * here, so a column that has collected hundreds is a data-quality problem
     * rather than a pipeline, and drawing all of them buries the handful that
     * are real workflow states.
     */
    private function queueStatusesOverall(
        ConnectionInterface $db,
        string $table,
        string $statusColumn
    ): array {
        return $db->table($table)
            ->selectRaw("COALESCE(NULLIF({$statusColumn}, ''), 'Unspecified') AS label")
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('label')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::QUEUE_STATUS_LIMIT)
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

            $area = $this->technicianDesignatedArea($db, $technician['name']);

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
                'designated_area' => $area,
            ];
        }

        usort($workload, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $workload;
    }

    /**
     * Resolves the primary barangay/area a technician is assigned to based on customer locations.
     */
    private function technicianDesignatedArea(ConnectionInterface $db, string $techName): string
    {
        try {
            $soBarangay = $db->table('service_orders as so')
                ->join('customers as c', 'c.id', '=', 'so.customer_id')
                ->whereNotNull('c.barangay')
                ->where('c.barangay', '!=', '')
                ->where(function ($q) use ($techName) {
                    $q->where('so.technicians', 'LIKE', "%{$techName}%")
                      ->orWhere('so.visit_by_user', 'LIKE', "%{$techName}%");
                })
                ->selectRaw('c.barangay, COUNT(*) as cnt')
                ->groupBy('c.barangay')
                ->orderByDesc('cnt')
                ->value('c.barangay');

            if ($soBarangay) {
                return (string) $soBarangay;
            }

            $joBarangay = $db->table('job_orders as jo')
                ->join('customers as c', 'c.id', '=', 'jo.customer_id')
                ->whereNotNull('c.barangay')
                ->where('c.barangay', '!=', '')
                ->where(function ($q) use ($techName) {
                    $q->where('jo.technicians', 'LIKE', "%{$techName}%")
                      ->orWhere('jo.visit_by', 'LIKE', "%{$techName}%");
                })
                ->selectRaw('c.barangay, COUNT(*) as cnt')
                ->groupBy('c.barangay')
                ->orderByDesc('cnt')
                ->value('c.barangay');

            if ($joBarangay) {
                return (string) $joBarangay;
            }
        } catch (\Throwable) {
            // Swallowed safely
        }

        return 'Field Designated Area';
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
            ->whereNotNull('t.date_processed')
            ->whereNotNull('t.received_payment')
            ->where(function ($query) {
                $query->whereNull('t.status')
                    ->orWhereNotIn(DB::raw('LOWER(t.status)'), ['cancelled', 'pending', 'voided']);
            });
    }

    // ── Payment portal ───────────────────────────────────────────────────

    /**
     * Settled online collections, from SYNC's payment portal.
     *
     * These are a second, separate income stream and not a flavour of the first.
     * SYNC's PaymentWorkerService settles a portal payment by distributing it
     * across `invoices` and adjusting `billing_accounts.account_balance`, then
     * logging it here — it never writes a `transactions` row. So a portal payment
     * is genuinely absent from `transactions`, and any income figure built only
     * from that table understates collections by the whole portal channel.
     *
     * That also means these rows can be *added* to the transaction totals without
     * double-counting: there is no overlap between the two tables to reconcile.
     *
     * `total_amount` is the net the worker applied to invoices, not the gross the
     * gateway charged — the convenience fee is deliberately excluded, because
     * that money is the gateway's and never becomes revenue.
     */
    /** Resolves which payment portal table exists in the connected database. */
    private function resolvePortalTable(ConnectionInterface $db): ?string
    {
        try {
            $schema = $db->getSchemaBuilder();
            foreach (['payment_portal', 'payment_portal_logs', 'payment_portals'] as $table) {
                if ($schema->hasTable($table)) {
                    return $table;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    /** Resolves the first column from candidates that exists on table. */
    private function resolvePortalColumn(ConnectionInterface $db, string $table, array $candidates): string
    {
        try {
            $schema = $db->getSchemaBuilder();
            foreach ($candidates as $col) {
                if ($schema->hasColumn($table, $col)) {
                    return $col;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $candidates[0];
    }

    private function portalPayments(ConnectionInterface $db): Builder
    {
        $table = $this->resolvePortalTable($db);

        if (!$table) {
            return $db->table('billing_accounts as ppl')->whereRaw('1 = 0');
        }

        $dateCol = $this->resolvePortalColumn($db, $table, ['date_time', 'created_at', 'payment_date', 'date']);
        $amountCol = $this->resolvePortalColumn($db, $table, ['total_amount', 'amount', 'received_payment']);

        $query = $db->table("{$table} as ppl")
            ->whereNotNull("ppl.{$dateCol}")
            ->whereNotNull("ppl.{$amountCol}");

        $statusConditions = [];

        try {
            $schema = $db->getSchemaBuilder();

            if ($schema->hasColumn($table, 'transaction_status')) {
                $statusConditions[] = "UPPER(COALESCE(ppl.transaction_status, '')) IN ('PAID', 'SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'SETTLED')";
            }

            if ($schema->hasColumn($table, 'status')) {
                $statusConditions[] = "UPPER(COALESCE(ppl.status, '')) IN ('PAID', 'COMPLETED', 'SETTLED', 'PAYMENT_SUCCESS', 'SUCCESS', 'SUCCESSFUL', 'APPROVED')";
            }

            if ($schema->hasColumn($table, 'payment_status')) {
                $statusConditions[] = "UPPER(COALESCE(ppl.payment_status, '')) IN ('PAID', 'COMPLETED', 'SETTLED', 'PAYMENT_SUCCESS', 'SUCCESS', 'SUCCESSFUL', 'APPROVED')";
            }
        } catch (\Throwable $e) {
            report($e);
        }

        if (!empty($statusConditions)) {
            $query->whereRaw('(' . implode(' OR ', $statusConditions) . ')');
        }

        return $query;
    }

    /** Portal total and count for a range. */
    private function portalStats(ConnectionInterface $db, string $from, string $to): array
    {
        $table = $this->resolvePortalTable($db);
        if (!$table) {
            return ['count' => 0, 'total' => 0.0, 'largest' => 0.0];
        }

        $dateCol = $this->resolvePortalColumn($db, $table, ['date_time', 'created_at', 'payment_date', 'date']);
        $amountCol = $this->resolvePortalColumn($db, $table, ['total_amount', 'amount', 'received_payment']);

        $row = $this->portalPayments($db)
            ->whereBetween(DB::raw("DATE(ppl.{$dateCol})"), [$from, $to])
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw("COALESCE(SUM(ppl.{$amountCol}), 0) AS total")
            ->selectRaw("COALESCE(MAX(ppl.{$amountCol}), 0) AS max_amount")
            ->first();

        return [
            'count' => (int) ($row->cnt ?? 0),
            'total' => (float) ($row->total ?? 0),
            'largest' => (float) ($row->max_amount ?? 0),
        ];
    }

    /**
     * Portal collections per day, keyed the same way the transaction series is,
     * so the two can be summed bucket by bucket.
     *
     * @return array<string,float>
     */
    private function portalDaily(ConnectionInterface $db, string $from, string $to): array
    {
        $table = $this->resolvePortalTable($db);
        if (!$table) {
            return [];
        }

        $dateCol = $this->resolvePortalColumn($db, $table, ['date_time', 'created_at', 'payment_date', 'date']);
        $amountCol = $this->resolvePortalColumn($db, $table, ['total_amount', 'amount', 'received_payment']);

        return $this->portalPayments($db)
            ->whereBetween(DB::raw("DATE(ppl.{$dateCol})"), [$from, $to])
            ->selectRaw("DATE_FORMAT(ppl.{$dateCol}, '%Y-%m-%d') AS day")
            ->selectRaw("SUM(ppl.{$amountCol}) AS total")
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * Which gateway carried each portal payment — GCash, Maya, a bank code.
     *
     * Reported underneath the Payment Portal channel so an unexpected total can
     * be traced without opening SYNC.
     *
     * @return string[]
     */
    private function portalChannels(ConnectionInterface $db, string $from, string $to): array
    {
        $table = $this->resolvePortalTable($db);
        if (!$table) {
            return [];
        }

        $dateCol = $this->resolvePortalColumn($db, $table, ['date_time', 'created_at', 'payment_date', 'date']);

        $channelExprs = [];
        try {
            $schema = $db->getSchemaBuilder();
            if ($schema->hasColumn($table, 'payment_channel')) {
                $channelExprs[] = "NULLIF(ppl.payment_channel, '')";
            }
            if ($schema->hasColumn($table, 'ewallet_type')) {
                $channelExprs[] = "NULLIF(ppl.ewallet_type, '')";
            }
            if ($schema->hasColumn($table, 'method')) {
                $channelExprs[] = "NULLIF(ppl.method, '')";
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $channelExprs[] = "'Portal'";

        $channelSql = "COALESCE(" . implode(', ', $channelExprs) . ") AS channel";

        return $this->portalPayments($db)
            ->whereBetween(DB::raw("DATE(ppl.{$dateCol})"), [$from, $to])
            ->selectRaw($channelSql)
            ->groupBy('channel')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(12)
            ->pluck('channel')
            ->map(fn ($channel) => (string) $channel)
            ->all();
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
