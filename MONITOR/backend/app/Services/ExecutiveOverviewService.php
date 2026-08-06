<?php

namespace App\Services;

use App\Services\Reports\ReportPeriod;
use App\Services\Reports\StatusBuckets;
use App\Services\Reports\HostingFee;
use App\Services\Reports\SyncPricing;
use App\Services\Reports\WorkCadence;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The Executive Dashboard: one screen, every company.
 *
 * Composed from the existing section payloads rather than from new SQL. That is
 * the important design decision here — if this view queried the databases itself
 * it would eventually disagree with the modules it summarises, and a board
 * meeting would be spent reconciling two of our own numbers. Every figure below
 * is the same figure the module shows, arrived at by the same code path and
 * through the same cache.
 *
 * A section that cannot be reached degrades to null rather than to zero, and the
 * gap is named in `unavailable`. Zero and "we could not ask" are different
 * claims, and only one of them belongs in front of an executive.
 */
class ExecutiveOverviewService
{
    /** Sections this view composes. */
    private const SECTIONS = ['subscriber_analytics', 'financial', 'operations', 'tech'];

    /** Sections the work-stream widgets need, and nothing else. */
    private const WORK_SECTIONS = ['operations'];

    public function __construct(private ReportingService $reporting)
    {
    }

    public function build(array $params): array
    {
        [$from, $to] = [$params['date_from'] ?? null, $params['date_to'] ?? null];

        // Daily by default, matching the dashboard's own default view. The old
        // monthly window meant the page opened on a range none of its controls
        // were showing.
        $window = ReportPeriod::dashboardWindow('daily', $params['as_of'] ?? null);
        $params['date_from'] = $from ?: $window['from'];
        $params['date_to'] = $to ?: $window['to'];

        [$sections, $unavailable] = $this->sections(self::SECTIONS, $params);

        return [
            'as_of' => ReportPeriod::anchor($params['as_of'] ?? null)->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'range' => ['from' => $params['date_from'], 'to' => $params['date_to']],
            'range_label' => $this->rangeLabel($params['date_from'], $params['date_to']),

            'subscriber_health' => $this->subscriberHealth($sections['subscriber_analytics'] ?? null),
            'subscriber_overview' => $this->subscriberOverview($sections['subscriber_analytics'] ?? null),
            'financial_summary' => $this->financialSummary(
                $sections['financial'] ?? null,
                $sections['subscriber_analytics'] ?? null
            ),
            'work_streams' => $this->workStreams($sections['operations'] ?? null, $params),
            'operations_tech' => $this->operationsTech(
                $sections['operations'] ?? null,
                $sections['tech'] ?? null
            ),

            'databases' => $this->databases($sections),
            'unavailable' => $unavailable,
        ];
    }

    /**
     * One work stream for its own date range.
     *
     * The Applications, Job Orders and Service Orders widgets each carry an
     * independent range, so each asks for its own window rather than the whole
     * dashboard reloading when one of them moves. It runs through
     * ReportingService like everything else, so three widgets sitting on the same
     * range share one cached section rather than issuing three fan-outs.
     */
    public function workStreamsFor(array $params): array
    {
        $window = ReportPeriod::dashboardWindow('daily', $params['as_of'] ?? null);
        $params['date_from'] = $params['date_from'] ?: $window['from'];
        $params['date_to'] = $params['date_to'] ?: $window['to'];

        [$sections, $unavailable] = $this->sections(self::WORK_SECTIONS, $params);

        return [
            'range' => ['from' => $params['date_from'], 'to' => $params['date_to']],
            'range_label' => $this->rangeLabel($params['date_from'], $params['date_to']),
            'streams' => $this->workStreams($sections['operations'] ?? null, $params),
            'unavailable' => $unavailable,
        ];
    }

    /**
     * Runs the named sections, collecting failures rather than propagating them.
     *
     * One unreachable section must not blank the whole summary — the subscriber
     * and operations halves are still worth showing when the finance database is
     * down.
     *
     * @param string[] $names
     * @return array{0:array<string,array>,1:array<string,string>}
     */
    private function sections(array $names, array $params): array
    {
        $sections = [];
        $unavailable = [];

        foreach ($names as $section) {
            try {
                $sections[$section] = $this->reporting->aggregate($section, $params);
            } catch (\Throwable $e) {
                Log::warning('Executive dashboard section unavailable', [
                    'section' => $section,
                    'range' => [$params['date_from'] ?? null, $params['date_to'] ?? null],
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                $unavailable[$section] = config('app.debug')
                    ? $e->getMessage()
                    : 'This data could not be reached.';
            }
        }

        return [$sections, $unavailable];
    }

    /**
     * Active subscribers, net growth and churn rate.
     *
     * Net growth is new-in-range minus disconnections, not simply new accounts:
     * a month that signed 40 and lost 45 grew by minus five, and reporting the
     * 40 alone is the single most flattering way to present a shrinking base.
     */
    private function subscriberHealth(?array $subscribers): array
    {
        if ($subscribers === null) {
            return ['available' => false];
        }

        $active = (int) ($subscribers['kpi']['active'] ?? 0);
        $vip = (int) ($subscribers['kpi']['vip'] ?? 0);
        $disconnected = (int) ($subscribers['kpi']['disconnected'] ?? 0);
        $newInRange = (int) ($subscribers['growth']['new_in_range'] ?? 0);

        $base = $active + $vip;

        // Churn against the base that could have churned — active plus the ones
        // that already did — rather than against the survivors alone, which
        // understates it.
        $churnBase = $base + $disconnected;

        return [
            'available' => true,
            'active_subscribers' => $base,
            'vip_subscribers' => $vip,
            'disconnected' => $disconnected,
            'new_in_range' => $newInRange,
            'net_growth' => $newInRange - $disconnected,
            'churn_rate_pct' => $churnBase > 0 ? round($disconnected / $churnBase * 100, 1) : null,
            'billing_summary' => $subscribers['billing_summary'] ?? null,
            'range_label' => $subscribers['range_label'] ?? '',
        ];
    }

    /**
     * Billing status, network status, expiry runway, plan mix and geography.
     *
     * Network status is taken from the driver's `network` block, which counts
     * online, offline, restricted and disconnected over one row set — see
     * GowiserReportsDriver::networkStatus. The previous version of this method
     * took online and offline from the RADIUS session table and restricted and
     * disconnected from the billing status counts, which are two different
     * populations: the four numbers did not add up to the subscriber base, and
     * management was reading them as though they did.
     *
     * The session breakdown is still carried, as `sessions`, because "what does
     * the network think" remains a fair question — it is just not the same
     * question as "how many subscribers are cut off", and they no longer share a
     * set of tiles.
     */
    private function subscriberOverview(?array $subscribers): array
    {
        if ($subscribers === null) {
            return ['available' => false];
        }

        $status = $subscribers['status'] ?? [];
        $kpi = $subscribers['kpi'] ?? [];
        $network = $subscribers['network'] ?? [];

        return [
            'available' => true,

            'billing' => [
                'active' => (int) ($status['active'] ?? 0),
                'inactive' => (int) ($status['inactive'] ?? 0),
                'vip' => (int) ($status['vip'] ?? 0),
                'pullout' => (int) ($status['pullout'] ?? 0),
                'total' => (int) ($status['total'] ?? 0),
            ],

            // Null when no source could produce it, so the panel says so rather
            // than drawing four confident zeros.
            'network' => $network === [] ? null : [
                'online' => (int) ($network['online'] ?? 0),
                'offline' => (int) ($network['offline'] ?? 0),
                'restricted' => (int) ($network['restricted'] ?? 0),
                'disconnected' => (int) ($network['disconnected'] ?? 0),
                'total' => (int) ($network['total'] ?? 0),
            ],

            // Prepaid accounts whose service period lapses inside the window.
            // Null on a schema that stores no subscription end date — nothing
            // expires there, which is not the same as nothing expiring soon.
            'expiring' => [
                'in_3_days' => $kpi['expiring_3day'] ?? null,
                'in_7_days' => $kpi['expiring_7day'] ?? null,
            ],

            'plan_distribution' => $subscribers['plan_distribution'] ?? [],
            'barangays' => $subscribers['barangays'] ?? [],
            'sessions' => $subscribers['sessions'] ?? [],

            // Subscribers who are not billed. See freeConnections.
            'free_connections' => $this->freeConnections($subscribers),

            // Who the VIP counter above is counting, by name. Passed straight
            // through from the section payload — this view composes section
            // output rather than issuing SQL of its own, so that the list and the
            // counter beside it can never be taken over different populations.
            //
            // Absent on a source with no VIP concept, which the panel reports as
            // "not tracked" rather than as an empty list: no rows and no such
            // thing are different claims.
            'vip_accounts' => $subscribers['vip_accounts'] ?? null,
        ];
    }

    /**
     * Subscribers on a free connection: VIP status, and VIP/free-named plans.
     *
     * Two different populations, reported apart and then together, because they
     * are recorded in two different places and neither alone is the answer:
     *
     *   - a billing *status* of VIP, which is how an account is exempted after
     *     the fact;
     *   - a *plan* named for it — "VIP FREE" and its spelling variants — which is
     *     how an account is set up exempt from the start.
     *
     * They overlap, and the overlap cannot be measured from these two aggregates
     * without a row-level join this view deliberately does not do. So the total
     * is reported as the larger of the two rather than their sum: a sum would
     * double-count every account that is both, and that error runs in the
     * direction of overstating how much revenue is being given away.
     *
     * Plan matching is by pattern against the reconciled plan names (see
     * PlanReconciler), so a plan renamed in the app is picked up without a
     * deploy. The patterns are config — reporting.free_connection_plans.
     */
    private function freeConnections(array $subscribers): array
    {
        $patterns = config('reporting.free_connection_plans');
        $patterns = is_array($patterns) && $patterns !== [] ? $patterns : ['vip', 'free'];

        $vipStatus = (int) ($subscribers['status']['vip'] ?? $subscribers['kpi']['vip'] ?? 0);

        $plans = [];
        $planTotal = 0;

        foreach ($subscribers['plan_distribution'] ?? [] as $row) {
            $label = (string) ($row['label'] ?? '');

            if ($label === '') {
                continue;
            }

            $haystack = strtolower($label);

            foreach ($patterns as $pattern) {
                $needle = strtolower(trim((string) $pattern));

                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $count = (int) ($row['count'] ?? 0);

                    $plans[] = ['label' => $label, 'count' => $count];
                    $planTotal += $count;

                    // One match is enough. A plan called "VIP FREE" matches both
                    // patterns and must still be counted once.
                    break;
                }
            }
        }

        usort($plans, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'vip_status_accounts' => $vipStatus,
            'free_plan_accounts' => $planTotal,
            'plans' => $plans,
            'total' => max($vipStatus, $planTotal),
            'overlaps' => $vipStatus > 0 && $planTotal > 0,
        ];
    }

    /**
     * The three work streams, reported separately.
     *
     * Applications, job orders and service orders are different work done by
     * different queues, and the blended "JO/SO" counter this replaced could not
     * distinguish a month where installations doubled and repairs halved from a
     * flat one.
     *
     * `available` is false rather than three zeros when no monitored schema
     * models these tables — NETMANAGER has one installations queue and no
     * applications at all, and reporting zero applications for a system that has
     * no concept of them is a claim rather than a measurement.
     */
    private function workStreams(?array $operations, array $params): array
    {
        $range = ['from' => $params['date_from'] ?? null, 'to' => $params['date_to'] ?? null];

        if ($operations === null) {
            return ['available' => false, 'range' => $range];
        }

        $streams = $operations['work_streams'] ?? [];
        $cadence = $operations['work_cadence'] ?? [];

        // The two halves are reported independently. `work_streams` is the
        // range-scoped view and NETMANAGER produces none of it — it models one
        // installations queue and no applications at all — but it *does* produce
        // a cadence for that queue. Returning early on an empty `work_streams`
        // used to throw the cadence away with it, so a NETMANAGER-only fleet saw
        // three cards reporting "not tracked" over data that had been fetched.
        if ($streams === [] && $cadence === []) {
            return ['available' => false, 'range' => $range];
        }

        $applications = $streams['applications'] ?? [];
        $buckets = $applications['buckets'] ?? [];

        return [
            'available' => true,
            'range' => $range,
            'range_label' => $operations['range_label'] ?? '',

            'applications' => [
                // The brief's formula, and the raw count beside it. Both are
                // reported because they answer different questions, and labelling
                // either as the other is how two people quote different numbers
                // off the same screen.
                'total' => (int) ($applications['total'] ?? StatusBuckets::applicationTotal($buckets)),
                'count' => (int) ($applications['count'] ?? 0),
                'rescheduled' => (int) ($buckets['rescheduled'] ?? 0),
                'in_progress' => (int) ($buckets['in_progress'] ?? 0),
                'new_apply' => (int) ($buckets['new_apply'] ?? 0),
                'failed' => (int) ($buckets['failed'] ?? 0),
                'other' => (int) ($buckets['other'] ?? 0),
                'statuses' => $applications['statuses'] ?? [],
                'formula' => '(rescheduled + in progress + new apply) − failed',
            ],

            'job_orders' => $this->workOrderStream($streams['job_orders'] ?? [], 'Job Orders'),
            'service_orders' => $this->workOrderStream($streams['service_orders'] ?? [], 'Service Orders'),

            'timeline' => $operations['work_timeline'] ?? [],

            // Today / this week / this month, on the same three queues but
            // independent of the range above. This is what the cards actually
            // render; the range-scoped figures beside it feed the timeline and
            // the pipeline table.
            'cadence' => $cadence,

            'resolution' => $this->resolution($operations),
        ];
    }

    /**
     * Resolution speed: what got closed, and what is still waiting.
     *
     * "Completed & Closed" is composed from the cadence the drivers already
     * returned rather than queried again. Two figures on one screen that are
     * supposed to be the same figure must come from the same rows, or a slow
     * afternoon is enough to make them disagree and the screen stops being
     * trusted.
     *
     * Installations and repairs are added together here — the question is how
     * much work the field force finished, and both kinds count — but each is
     * also reported on its own, because "we closed 40" hides which 40.
     */
    private function resolution(array $operations): array
    {
        $cadence = $operations['work_cadence'] ?? [];
        $completed = [];

        foreach (WorkCadence::WINDOWS as $window) {
            $installed = (int) ($cadence['job_orders'][$window]['done'] ?? 0);
            $repaired = (int) ($cadence['service_orders'][$window]['done'] ?? 0);

            $completed[$window] = [
                'installations' => $installed,
                'repairs' => $repaired,
                'total' => $installed + $repaired,
            ];
        }

        return [
            // False when no monitored schema models these queues at all, so the
            // panel can say "not tracked" instead of reporting a confident zero.
            'available' => $cadence !== [],
            'completed' => $completed,
            'longest_outstanding' => $operations['resolution']['longest_outstanding'] ?? null,
        ];
    }

    /** One work-order stream, flattened for the widget that draws it. */
    private function workOrderStream(array $stream, string $label): array
    {
        $buckets = $stream['buckets'] ?? [];

        return [
            'label' => $stream['label'] ?? $label,
            'count' => (int) ($stream['count'] ?? 0),
            'done' => (int) ($buckets['done'] ?? 0),
            'reschedule' => (int) ($buckets['reschedule'] ?? 0),
            'failed' => (int) ($buckets['failed'] ?? 0),
            'in_progress' => (int) ($buckets['in_progress'] ?? 0),
            'other' => (int) ($buckets['other'] ?? 0),
            'statuses' => $stream['statuses'] ?? [],
        ];
    }

    /**
     * Income by channel, OpEx against CapEx, the SYNC platform fee, and net.
     *
     * Total income is the sum of the three channels finance reconciles against —
     * Cash, PNB and Payment Portal — rather than the headline `kpi.income`. The
     * two differ by the "Other" residue: collections whose payment method matched
     * none of the configured channel patterns. That residue is reported beside
     * the total rather than folded into it, because it is the signal that a new
     * payment method appeared, and a headline that quietly absorbs it hides
     * exactly the thing finance needs to see. `income_all_channels` carries the
     * unreduced figure so the two can be reconciled on screen.
     */
    private function financialSummary(?array $financial, ?array $subscribers): array
    {
        if ($financial === null) {
            return ['available' => false];
        }

        $channels = [];
        $headlineIncome = 0.0;
        $allChannels = 0.0;

        foreach ($financial['income_channels'] ?? [] as $channel) {
            $key = (string) ($channel['key'] ?? '');
            $total = (float) ($channel['total'] ?? 0);

            $channels[$key] = [
                'label' => $channel['label'] ?? $key,
                'total' => $total,
                'count' => (int) ($channel['count'] ?? 0),
                'share_pct' => (float) ($channel['share_pct'] ?? 0),
            ];

            $allChannels += $total;

            if (in_array($key, ['cash', 'pnb', 'portal'], true)) {
                $headlineIncome += $total;
            }
        }

        // No channel breakdown at all — a source that reports collections but not
        // methods — falls back to the headline KPI rather than reporting nothing.
        if ($channels === []) {
            $headlineIncome = (float) ($financial['kpi']['income'] ?? 0);
            $allChannels = $headlineIncome;
        }

        $opex = (float) ($financial['opex_capex']['opex']['total'] ?? 0);
        $capex = (float) ($financial['opex_capex']['capex']['total'] ?? 0);

        // The SYNC platform fee is an expense that exists in no ledger: it is a
        // per-subscriber licence, so it is a headcount times a rate.
        //
        // Both it and the hosting fee are MONTHLY charges, and the page range is
        // not: it can be a day, a week, a month or a year. That makes them the
        // only figures on the page whose period is fixed while everything around
        // them moves, so they are reported as monthly amounts, labelled as such,
        // and deliberately kept out of every derived total:
        //
        //  - not added into Total Expenses, which must stay reconcilable against
        //    the Financial module, and which no expenses ledger has heard of;
        //  - not netted off Net Income, and no "net after platform costs" figure
        //    is produced at all. Subtracting a month's cost from a day's income
        //    is not a smaller number, it is a wrong one — and on a yearly range
        //    it understates the cost twelvefold in the other direction. There is
        //    no range-independent way to net a monthly charge against a
        //    range-scoped income, so the honest thing is to report the charge and
        //    let the reader do the comparison they actually mean.
        $syncPrice = SyncPricing::build(
            isset($subscribers['sync_billable_accounts'])
                ? (int) $subscribers['sync_billable_accounts']
                : null
        );

        $hostingFee = HostingFee::build();

        $totalExpenses = $opex + $capex;
        $net = $headlineIncome - $totalExpenses;

        $rolling = $financial['rolling'] ?? [];

        return [
            'available' => true,

            'total_income' => round($headlineIncome, 2),
            // Everything collected, including methods no channel claimed. Equal
            // to total_income whenever the residue is empty, which is the normal
            // case — a difference is the thing worth noticing.
            'income_all_channels' => round($allChannels, 2),
            'unmatched_income' => round($allChannels - $headlineIncome, 2),
            'channels' => $channels,

            'opex' => round($opex, 2),
            'capex' => round($capex, 2),
            // Monthly charges, whatever the page range is. Reported beside Total
            // Expenses rather than inside it, and netted off nothing — see the
            // note above the SyncPricing::build() call.
            'sync_price' => $syncPrice,
            'hosting_fee' => $hostingFee,
            // OpEx + CapEx, as before. Neither the SYNC fee nor the hosting fee
            // is in here — they are reported beside Total Expenses rather than
            // inside it, keeping the figure reconcilable against the Financial module.
            'total_expenses' => round($totalExpenses, 2),

            'gross' => round($headlineIncome, 2),
            'net' => round($net, 2),
            'margin_pct' => $headlineIncome > 0
                ? round($net / $headlineIncome * 100, 1)
                : null,

            // Anchored on the current month and the last seven days respectively,
            // never on the widget's range — see GowiserReportsDriver::rollingIncome.
            'month_income' => $rolling['month_income'] ?? null,
            'days_elapsed' => $rolling['days_elapsed'] ?? null,
            'days_in_month' => $rolling['days_in_month'] ?? null,
            'daily_average' => $rolling['daily_average'] ?? null,
            'projected_monthly' => $rolling['projected_monthly'] ?? null,

            // `weekly_average` is a *daily* rate — the last seven days' takings
            // divided by seven — and the Executive Dashboard used to render it
            // under the label "Weekly Sales Average", which overstated nothing
            // but understated the figure sevenfold to anyone reading the label.
            // The key keeps its meaning and its name, because the Financial
            // module labels it correctly and reads the same field; the weekly
            // figure it multiplies out to is carried beside it as `week_income`,
            // and the dashboard now shows both with their divisors stated.
            'weekly_average' => $rolling['weekly_average'] ?? null,
            'week_income' => $rolling['week_income'] ?? null,
            'week_days' => $rolling['week_days'] ?? 7,
            'week_from' => $rolling['week_from'] ?? null,
            'week_income' => $rolling['week_income'] ?? null,
            'week_from' => $rolling['week_from'] ?? null,

            'outstanding_payables' => (float) ($financial['payables']['outstanding'] ?? 0),
            'payables_unpaid_count' => (int) ($financial['payables']['totals']['unpaid']['count'] ?? 0),
            'metrics' => $financial['executive_metrics'] ?? null,
            'range_label' => $financial['range_label'] ?? '',
            'by_plan' => $financial['by_plan'] ?? [],
        ];
    }

    /**
     * Field delivery: turnaround, concerns, repair categories and the leaderboard.
     *
     * The derived "system alarms" feed is gone. It was never a monitoring feed —
     * neither source runs one — and a panel of conditions nobody could act on
     * from this screen trained people to skim past it. The underlying figures it
     * was derived from (open work, oldest backlog) are still reported directly,
     * where they can be read as the measurements they are.
     */
    private function operationsTech(?array $operations, ?array $tech): array
    {
        $openWork = 0;
        $oldest = null;
        $turnaround = null;
        $turnaroundUnit = null;

        $reliableFrom = $this->reliableFrom();
        $bounded = false;

        if ($operations !== null) {
            foreach ($operations['queues'] ?? [] as $queue) {
                $openWork += (int) ($queue['backlog']['open'] ?? 0);

                $age = $this->measurableAge($queue['backlog'] ?? [], $reliableFrom, $bounded);

                if ($age !== null) {
                    $oldest = $oldest === null ? $age : max($oldest, $age);
                }
            }

            // Weighted across the reported types, so a category that closed three
            // jobs does not pull the headline as hard as one that closed three
            // hundred.
            [$turnaround, $turnaroundUnit] = $this->averageTurnaround($operations['turnaround_by_type'] ?? []);
        }

        $techniciansLive = null;
        $techniciansTotal = null;
        $topTech = [];

        if ($tech !== null) {
            $locations = $tech['locations'] ?? [];
            $techniciansTotal = count($locations);
            $techniciansLive = count(array_filter($locations, fn ($row) => (bool) ($row['is_live'] ?? false)));

            $workload = $tech['workload'] ?? [];
            usort($workload, fn ($a, $b) => (int) ($b['completed'] ?? 0) - (int) ($a['completed'] ?? 0));
            $topTech = array_slice($workload, 0, 10);
        }

        return [
            'available' => $operations !== null || $tech !== null,
            'open_work' => $operations !== null ? $openWork : null,
            'oldest_open_days' => $oldest,
            'average_turnaround' => $turnaround,
            'turnaround_unit' => $turnaroundUnit,
            'turnaround_by_type' => $operations['turnaround_by_type'] ?? [],
            'technicians_live' => $techniciansLive,
            'technicians_reporting' => $techniciansTotal,
            // Surfaced so the panel can state the floor rather than leaving a
            // reader to wonder why an obviously ancient job reports 40 days.
            'reliable_from' => $reliableFrom?->toDateString(),
            'age_bounded' => $bounded,
            'concerns' => $operations['concerns'] ?? [],
            'repair_categories' => $operations['repair_categories'] ?? [],
            'top_tech' => $topTech,
        ];
    }

    /**
     * The date before which operational timestamps mean nothing.
     *
     * Null disables the floor, which is what an installation with clean history
     * should set. See config/reporting.php for why the default is not null.
     */
    private function reliableFrom(): ?Carbon
    {
        $configured = trim((string) config('reporting.reliable_from', ''));

        return $configured === '' ? null : ReportPeriod::parse($configured);
    }

    /**
     * How long the oldest open item has genuinely been waiting.
     *
     * A row opened before records became reliable is aged from the floor rather
     * than from its own timestamp: the honest claim is "open for at least this
     * long", not a four-figure day count computed against a date that was never
     * real. `$bounded` is set by reference so the caller can say so on screen —
     * silently clamping a number and presenting it as measured would be the same
     * dishonesty in the other direction.
     */
    private function measurableAge(array $backlog, ?Carbon $reliableFrom, bool &$bounded): ?int
    {
        $age = $backlog['oldest_age_days'] ?? null;

        if ($age === null) {
            return null;
        }

        if ($reliableFrom === null) {
            return (int) $age;
        }

        $openedAt = ReportPeriod::parse(substr((string) ($backlog['oldest_opened_at'] ?? ''), 0, 10));

        // No parsable open date, or one at/after the floor: the reported age
        // stands as measured.
        if ($openedAt !== null && $openedAt->greaterThanOrEqualTo($reliableFrom)) {
            return (int) $age;
        }

        $bounded = true;

        return (int) $reliableFrom->diffInDays(Carbon::now()->startOfDay());
    }

    /**
     * One blended turnaround from the per-type figures.
     *
     * Only types sharing the majority unit are blended. The two schemas measure
     * different things — minutes on site against the age of a ticket in hours —
     * and averaging across them would produce a number that is not a duration of
     * anything.
     *
     * @return array{0:float|null,1:string|null}
     */
    private function averageTurnaround(array $byType): array
    {
        $units = [];

        foreach ($byType as $row) {
            $unit = (string) ($row['unit'] ?? 'minutes');
            $units[$unit] = ($units[$unit] ?? 0) + (int) ($row['closed'] ?? 0);
        }

        if ($units === []) {
            return [null, null];
        }

        arsort($units);
        $unit = array_key_first($units);
        $field = $unit === 'hours' ? 'average_hours' : 'average_minutes';

        $weighted = 0.0;
        $closed = 0;

        foreach ($byType as $row) {
            if ((string) ($row['unit'] ?? 'minutes') !== $unit) {
                continue;
            }

            $average = $row[$field] ?? null;
            $count = (int) ($row['closed'] ?? 0);

            if ($average === null || $count === 0) {
                continue;
            }

            $weighted += (float) $average * $count;
            $closed += $count;
        }

        return [$closed > 0 ? round($weighted / $closed, 1) : null, $unit];
    }

    /**
     * Which databases answered, and which did not.
     *
     * Taken from whichever section reported the most complete picture. A summary
     * built on six of eight branches is not wrong, but it must not be read as
     * eight — so the shortfall travels with the figures.
     */
    private function databases(array $sections): array
    {
        $best = null;

        foreach ($sections as $payload) {
            $aggregate = $payload['aggregate'] ?? null;

            if ($aggregate === null) {
                continue;
            }

            if ($best === null || count($aggregate['answered'] ?? []) > count($best['answered'] ?? [])) {
                $best = $aggregate;
            }
        }

        if ($best === null) {
            return ['answered' => [], 'answered_labels' => [], 'failed' => [], 'total' => 0];
        }

        return [
            'answered' => $best['answered'] ?? [],
            'answered_labels' => $best['answered_labels'] ?? [],
            'failed' => $best['failed'] ?? [],
            'total' => (int) ($best['total_databases'] ?? 0),
        ];
    }

    private function rangeLabel(string $from, string $to): string
    {
        $start = ReportPeriod::parse($from);
        $end = ReportPeriod::parse($to);

        if ($start === null || $end === null) {
            return $from . ' – ' . $to;
        }

        if ($start->equalTo($end)) {
            return $start->format('M d, Y');
        }

        return $start->format('M d') . ' – ' . $end->format('M d, Y');
    }
}
