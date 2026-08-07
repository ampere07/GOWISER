<?php

namespace App\Services;

use App\Services\Reports\ReportPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The Executive Group Overview: one daily flash screen, every company.
 *
 * Four blocks, in the order the layout reads them:
 *
 *   1. Daily      — today's income, expenses and net, plus the three collection
 *                   channels and the two rate figures derived from them
 *   2. Monthly    — the same shape month-to-date, with the year-to-date trio
 *                   carried inside it
 *   3. Subscribers— the billing-status headcount, and what the field force
 *                   applied for, installed and repaired today and this month
 *   4. Plans      — active subscribers per plan, plans that carry none omitted
 *
 * Every figure is a bare number. This screen is read at a glance and quoted out
 * loud; a value field carrying its own unit or a qualifier ("₱12,300 (7 days)")
 * is a sentence, and a wall of sentences is not a flash report. The units and
 * the arithmetic live in the labels and captions around the numbers instead.
 *
 * ── Why three financial windows rather than one ───────────────────────
 *
 * The layout states Daily, Monthly and Yearly side by side, so all three are
 * true at once and none of them can follow a date picker. Each is therefore its
 * own section run over its own fixed window, composed from the existing section
 * payloads rather than from new SQL — if this view queried the databases itself
 * it would eventually disagree with the modules it summarises, and a board
 * meeting would be spent reconciling two of our own numbers.
 *
 * The three runs are not three fan-outs in practice: ReportingService caches per
 * parameter bucket, and the monthly and yearly windows change once a day at
 * most, so the second viewer of the morning pays for the daily window alone.
 *
 * A section that cannot be reached degrades to null rather than to zero, and the
 * gap is named in `unavailable`. Zero and "we could not ask" are different
 * claims, and only one of them belongs in front of an executive.
 */
class ExecutiveOverviewService
{
    /** Sections composed for the subscriber and work blocks. */
    private const SECTIONS = ['subscriber_analytics', 'operations'];

    /** Sections the work-stream widgets need, and nothing else. */
    private const WORK_SECTIONS = ['operations'];

    public function __construct(private ReportingService $reporting)
    {
    }

    public function build(array $params): array
    {
        $anchor = ReportPeriod::anchor($params['as_of'] ?? null);

        $timeframe = in_array($params['timeframe'] ?? null, self::TIMEFRAMES, true)
            ? (string) $params['timeframe']
            : 'daily';

        $windows = $this->windows(
            $anchor,
            $timeframe,
            $params['date_from'] ?? null,
            $params['date_to'] ?? null
        );

        // The three fixed financial windows, and the two anchor-scoped sections.
        // Collected in one pass so a single unreachable database is named once
        // rather than four times.
        $unavailable = [];

        $finance = [];

        foreach ($windows as $key => $window) {
            $finance[$key] = $this->window('financial', $params, $window, $unavailable, $key);
        }

        // Subscriber counts are current state and ignore the range; the work
        // metrics inside `operations` are driven by it. Both come from one pass
        // over the selected window rather than two, so the drill-down a card
        // opens is scoped to exactly the window the card was counted in.
        [$sections, $sectionGaps] = $this->sections(
            self::SECTIONS,
            $this->scoped($params, $windows['selected'])
        );

        $unavailable += $sectionGaps;

        $subscribers = $sections['subscriber_analytics'] ?? null;
        $operations = $sections['operations'] ?? null;

        return [
            'as_of' => $anchor->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'timeframe' => $timeframe,
            'windows' => $windows,

            'daily' => $this->daily($finance['selected'], $windows['selected']),
            'monthly' => $this->monthly(
                $finance['monthly'],
                $finance['yearly'],
                $windows['monthly'],
                $windows['yearly']
            ),
            'subscribers' => $this->subscribers($subscribers, $operations),
            'plans' => $this->plans($subscribers),

            'databases' => $this->databases(array_merge($finance, $sections)),
            'unavailable' => $unavailable,
        ];
    }

    /**
     * One work stream for its own date range.
     *
     * Retained for the Applications / Job Orders / Service Orders endpoint, which
     * carries an independent range and is served separately so moving it does not
     * re-run the financial fan-out behind the rest of the page. It runs through
     * ReportingService like everything else, so several callers sitting on the
     * same range share one cached section rather than issuing several fan-outs.
     */
    public function workStreamsFor(array $params): array
    {
        $window = ReportPeriod::dashboardWindow('daily', $params['as_of'] ?? null);
        $params['date_from'] = $params['date_from'] ?: $window['from'];
        $params['date_to'] = $params['date_to'] ?: $window['to'];

        [$sections, $unavailable] = $this->sections(self::WORK_SECTIONS, $params);

        $operations = $sections['operations'] ?? null;

        return [
            'range' => ['from' => $params['date_from'], 'to' => $params['date_to']],
            'range_label' => $this->rangeLabel($params['date_from'], $params['date_to']),
            'streams' => $operations === null
                ? ['available' => false]
                : [
                    'available' => true,
                    'applications' => $operations['work_streams']['applications'] ?? [],
                    'job_orders' => $operations['work_streams']['job_orders'] ?? [],
                    'service_orders' => $operations['work_streams']['service_orders'] ?? [],
                    'timeline' => $operations['work_timeline'] ?? [],
                    'cadence' => $operations['work_cadence'] ?? [],
                ],
            'unavailable' => $unavailable,
        ];
    }

    /** Timeframes the global toolbar offers. */
    public const TIMEFRAMES = ['daily', 'weekly', 'monthly', 'yearly', 'custom'];

    /**
     * The three windows this screen reports over.
     *
     * The first — `selected` — follows the global date toolbar. The other two are
     * fixed comparatives and deliberately do not move: a tile labelled "Total
     * Income (Monthly)" showing a yearly figure because somebody pressed a pill
     * is not a smaller error than showing the wrong month, it is a lie about what
     * the label means. So the toolbar drives the range section and the metrics
     * that carry a target date, and the month-to-date and year-to-date blocks
     * stay what they say they are.
     *
     * Month and year are *to date*, not whole calendar periods. A month-to-date
     * total sitting beside a running one is a figure an executive can act on; a
     * whole-month window would report the same number all afternoon and then jump
     * on the 1st, and a whole-year one would spend eleven months claiming a total
     * that had not happened yet.
     *
     * @return array<string,array{key:string,label:string,from:string,to:string,label_long:string}>
     */
    private function windows(Carbon $anchor, string $timeframe, ?string $from, ?string $to): array
    {
        $day = $anchor->copy()->startOfDay();
        $selected = $this->selectedWindow($day, $timeframe, $from, $to);

        return [
            'selected' => $selected,
            'monthly' => [
                'key' => 'monthly',
                'label' => 'Monthly',
                'from' => $day->copy()->startOfMonth()->toDateString(),
                'to' => $day->toDateString(),
                'label_long' => $day->format('F Y') . ' to date',
            ],
            'yearly' => [
                'key' => 'yearly',
                'label' => 'Yearly',
                'from' => $day->copy()->startOfYear()->toDateString(),
                'to' => $day->toDateString(),
                'label_long' => $day->format('Y') . ' to date',
            ],
        ];
    }

    /**
     * The window the global toolbar is asking for.
     *
     * Weekly is the last seven days ending today rather than the calendar week,
     * matching ReportPeriod::dashboardWindow and therefore the rest of the
     * portal. Two definitions of "this week" on one system is how two people
     * quote different numbers off the same button.
     *
     * A custom range with the ends the wrong way round is swapped rather than
     * rejected: the operator plainly meant the span between the two dates, and a
     * validation error for a date picker they dragged backwards is friction with
     * no safety value. An unparseable custom range falls back to today, which is
     * the safest window to be wrong about.
     *
     * @return array{key:string,label:string,from:string,to:string,label_long:string}
     */
    private function selectedWindow(Carbon $day, string $timeframe, ?string $from, ?string $to): array
    {
        if ($timeframe === 'custom') {
            $start = ReportPeriod::parse($from) ?? $day->copy();
            $end = ReportPeriod::parse($to) ?? $start->copy();

            if ($end->lessThan($start)) {
                [$start, $end] = [$end, $start];
            }

            return [
                'key' => 'custom',
                'label' => 'Custom Range',
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'label_long' => $this->rangeLabel($start->toDateString(), $end->toDateString()),
            ];
        }

        switch ($timeframe) {
            case 'weekly':
                $start = $day->copy()->subDays(6);
                $label = 'Last 7 days';
                break;
            case 'monthly':
                $start = $day->copy()->startOfMonth();
                $label = $day->format('F Y') . ' to date';
                break;
            case 'yearly':
                $start = $day->copy()->startOfYear();
                $label = $day->format('Y') . ' to date';
                break;
            default:
                $timeframe = 'daily';
                $start = $day->copy();
                $label = $day->format('M d, Y');
        }

        return [
            'key' => $timeframe,
            'label' => ucfirst($timeframe),
            'from' => $start->toDateString(),
            'to' => $day->toDateString(),
            'label_long' => $label,
        ];
    }

    /** The section parameters, scoped to one window. */
    private function scoped(array $params, array $window): array
    {
        return array_merge($params, [
            'date_from' => $window['from'],
            'date_to' => $window['to'],
        ]);
    }

    /**
     * One section over one window, collecting the failure rather than raising it.
     *
     * @param array<string,string> $unavailable written by reference
     */
    private function window(
        string $section,
        array $params,
        array $window,
        array &$unavailable,
        string $key
    ): ?array {
        try {
            return $this->reporting->aggregate($section, $this->scoped($params, $window));
        } catch (\Throwable $e) {
            Log::warning('Executive overview window unavailable', [
                'section' => $section,
                'window' => $key,
                'range' => [$window['from'], $window['to']],
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $unavailable[$section . ':' . $key] = config('app.debug')
                ? $e->getMessage()
                : 'This data could not be reached.';

            return null;
        }
    }

    /**
     * Runs the named sections, collecting failures rather than propagating them.
     *
     * One unreachable section must not blank the whole summary — the subscriber
     * half is still worth showing when the operations database is down.
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
                Log::warning('Executive overview section unavailable', [
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

    // ── 1. Daily ──────────────────────────────────────────────────────────

    /**
     * Today's money: the headline trio, the three channels, and the two rates.
     *
     * Total income is the sum of the three channels finance reconciles against —
     * Office Collection, PNB and Xendit — rather than the headline `kpi.income`.
     * The two differ by the residue: collections whose payment method matched
     * none of the configured channel patterns. That residue is carried as
     * `unmatched` rather than folded into the total, because it is the signal
     * that a new payment method appeared, and a headline that quietly absorbs it
     * hides exactly the thing finance needs to see.
     *
     * The last two tiles are deliberately *not* today's figures and are not
     * derived from this window at all — see rates(). They are stated here because
     * the layout puts them here, and their divisors are named in the captions the
     * frontend renders beside them.
     */
    private function daily(?array $financial, array $window): array
    {
        if ($financial === null) {
            return ['available' => false] + $this->windowMeta($window);
        }

        $channels = $this->channels($financial);
        $expenses = $this->expenses($financial);
        $rates = $this->rates($financial);

        return [
            'available' => true,

            'income' => $channels['total'],
            'expenses' => $expenses,
            'net' => round($channels['total'] - $expenses, 2),
            'monthly_projected_sales' => $rates['projected_monthly'],

            'office_collection' => $channels['cash'],
            'pnb' => $channels['pnb'],
            'xendit' => $channels['portal'],
            'daily_sales_average' => $rates['daily_average'],

            // Reported beside the total rather than inside it. Silent on screen
            // until it is non-zero, which is the only time it is worth a line.
            'unmatched' => $channels['unmatched'],
            'opex' => $financial['opex_capex']['opex']['total'] ?? null,
            'capex' => $financial['opex_capex']['capex']['total'] ?? null,
        ] + $this->windowMeta($window);
    }

    // ── 2. Monthly (and the yearly trio it carries) ───────────────────────

    private function monthly(
        ?array $financial,
        ?array $yearly,
        array $window,
        array $yearWindow
    ): array {
        $year = $this->yearly($yearly, $yearWindow);

        if ($financial === null) {
            return ['available' => false, 'yearly' => $year] + $this->windowMeta($window);
        }

        $channels = $this->channels($financial);
        $expenses = $this->expenses($financial);
        $rates = $this->rates($financial);

        return [
            'available' => true,

            'total_income' => $channels['total'],
            'total_expenses' => $expenses,
            'net_income' => round($channels['total'] - $expenses, 2),
            'weekly_sales_average' => $rates['weekly_average'],

            'total_cash' => $channels['cash'],
            'total_pnb' => $channels['pnb'],
            'total_xendit' => $channels['portal'],

            'yearly' => $year,

            'unmatched' => $channels['unmatched'],
            'opex' => $financial['opex_capex']['opex']['total'] ?? null,
            'capex' => $financial['opex_capex']['capex']['total'] ?? null,
        ] + $this->windowMeta($window);
    }

    private function yearly(?array $financial, array $window): array
    {
        if ($financial === null) {
            return ['available' => false] + $this->windowMeta($window);
        }

        $channels = $this->channels($financial);
        $expenses = $this->expenses($financial);

        return [
            'available' => true,
            'income' => $channels['total'],
            'expenses' => $expenses,
            'net' => round($channels['total'] - $expenses, 2),
        ] + $this->windowMeta($window);
    }

    // ── 3. Subscribers ────────────────────────────────────────────────────

    /**
     * The billing-status headcount, and the field force's day and month.
     *
     * `Rescheduled Install` and `Pending Install` are job-order *states* rather
     * than volumes, and are counted all-time: they answer "how much work is
     * waiting", and any window reports a rescheduled queue as empty every
     * morning before the first visit is moved while hiding every install that
     * has been stuck for months — which is the population somebody opens those
     * two tiles to find. The rows around them are volumes and are scoped to
     * their own labels — Daily to today, Monthly to the month. See
     * GowiserReportsDriver::WORK_METRICS and its `all_time` flag.
     *
     * `work_available` is false rather than nine zeros when no monitored schema
     * models these queues at all. NETMANAGER has one installations queue and no
     * applications, and reporting zero applications for a system that has no
     * concept of them is a claim rather than a measurement.
     */
    private function subscribers(?array $subscribers, ?array $operations): array
    {
        $status = $subscribers['status'] ?? [];

        // The driver's purpose-built block, not the generic cadence. The cadence
        // dates every queue on one COALESCE'd "effective date" — right for "what
        // moved recently", wrong for all six labels here. See
        // GowiserReportsDriver::executiveWorkload for the three specific figures
        // that were wrong and why.
        $work = $operations['executive_workload'] ?? [];
        $tracked = (bool) ($work['tracked'] ?? false);

        // Null rather than zero when no monitored schema models these queues at
        // all. NETMANAGER has one installations table and no applications, and
        // five confident zeros for a system with no concept of them is a claim
        // rather than a measurement.
        $count = function (string $key) use ($work, $tracked): ?int {
            if ($work === [] || !$tracked) {
                return null;
            }

            return (int) ($work[$key] ?? 0);
        };

        return [
            'available' => $subscribers !== null,
            'work_available' => $tracked,

            'active' => $subscribers === null ? null : (int) ($status['active'] ?? 0),
            'vip' => $subscribers === null ? null : (int) ($status['vip'] ?? 0),
            'inactive' => $subscribers === null ? null : (int) ($status['inactive'] ?? 0),
            'pullout' => $subscribers === null ? null : (int) ($status['pullout'] ?? 0),

            // All five follow the global date toolbar, and each is counted on the
            // status and target-date column its label means — see
            // GowiserReportsDriver::WORK_METRICS.
            'application' => $count('application'),
            'installed' => $count('installed'),
            'repair' => $count('repair'),
            // Renamed from "Schedule": it counts job orders whose onsite status
            // is Reschedule, and calling that "Schedule" read as the opposite —
            // work that had been booked in rather than work that had slipped.
            'reschedule' => $count('reschedule'),
            'pending' => $count('pending'),
        ];
    }

    // ── 4. Subscriber plans ───────────────────────────────────────────────

    /**
     * Active subscribers per plan.
     *
     * Taken from the section's `plans` block, which counts accounts on an Active
     * billing status only — a plan's headline number on this screen is how many
     * people are paying for it today, not how many rows ever pointed at it.
     *
     * Plans carrying nobody are dropped rather than rendered as zero cards. A
     * retired plan with no subscribers is not a fact worth a tile on a flash
     * screen, and a row of zeros pushes the plans that do matter off the fold.
     */
    private function plans(?array $subscribers): array
    {
        if ($subscribers === null) {
            return ['available' => false, 'rows' => [], 'total' => 0];
        }

        $rows = [];

        foreach ($subscribers['plans'] ?? [] as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $count = (int) ($row['count'] ?? 0);

            if ($label === '' || $count <= 0) {
                continue;
            }

            $rows[] = ['label' => $label, 'count' => $count];
        }

        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'available' => true,
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'count')),
        ];
    }

    // ── Shared arithmetic ─────────────────────────────────────────────────

    /**
     * The three reconciled channels and their sum, from one section payload.
     *
     * `total` is Cash + PNB + Portal, not the headline KPI. A source that reports
     * collections but no methods at all has no channel breakdown to sum, and
     * falls back to the KPI rather than reporting three zeros and a zero total.
     *
     * @return array{cash:float,pnb:float,portal:float,total:float,unmatched:float}
     */
    private function channels(array $financial): array
    {
        $totals = ['cash' => 0.0, 'pnb' => 0.0, 'portal' => 0.0];
        $all = 0.0;
        $seen = false;

        foreach ($financial['income_channels'] ?? [] as $channel) {
            $key = (string) ($channel['key'] ?? '');
            $total = (float) ($channel['total'] ?? 0);

            $all += $total;
            $seen = true;

            if (array_key_exists($key, $totals)) {
                $totals[$key] = $total;
            }
        }

        $reconciled = $totals['cash'] + $totals['pnb'] + $totals['portal'];

        if (!$seen) {
            $reconciled = (float) ($financial['kpi']['income'] ?? 0);
            $all = $reconciled;
        }

        return [
            'cash' => round($totals['cash'], 2),
            'pnb' => round($totals['pnb'], 2),
            'portal' => round($totals['portal'], 2),
            'total' => round($reconciled, 2),
            'unmatched' => round($all - $reconciled, 2),
        ];
    }

    /**
     * OpEx plus CapEx for the window.
     *
     * Neither the SYNC platform fee nor the hosting fee is in here. Both are
     * fixed monthly charges that exist in no ledger, and there is no
     * range-independent way to net a monthly charge against a day's or a year's
     * income — so they stay out of every derived total on this screen, and Total
     * Expenses stays reconcilable against the Financial module.
     */
    private function expenses(array $financial): float
    {
        return round(
            (float) ($financial['opex_capex']['opex']['total'] ?? 0)
            + (float) ($financial['opex_capex']['capex']['total'] ?? 0),
            2
        );
    }

    /**
     * The three rate figures, all anchored on today rather than on the window.
     *
     * Identical in every one of the three financial payloads, because the driver
     * computes them from the anchor — see GowiserReportsDriver::rollingIncome.
     * Read from whichever payload is in hand:
     *
     *   projected_monthly  (month-to-date ÷ days elapsed) × days in month
     *   daily_average      the last seven days ÷ 7
     *   weekly_average     the last seven days, which is the daily rate × 7
     *
     * The last pair is the one the old dashboard got backwards: it labelled the
     * daily rate "Weekly Sales Average", which understated the figure sevenfold
     * to anyone reading the label. The driver's key names are kept as they are —
     * the Financial module reads the same fields — and mapped to the right tiles
     * here.
     *
     * @return array{projected_monthly:?float,daily_average:?float,weekly_average:?float}
     */
    private function rates(array $financial): array
    {
        $rolling = $financial['rolling'] ?? [];

        return [
            'projected_monthly' => $rolling['projected_monthly'] ?? null,
            'daily_average' => $rolling['weekly_average'] ?? null,
            'weekly_average' => $rolling['week_income'] ?? null,
        ];
    }

    /** @return array{range:array{from:string,to:string},range_label:string} */
    private function windowMeta(array $window): array
    {
        return [
            'range' => ['from' => $window['from'], 'to' => $window['to']],
            'range_label' => $window['label_long'],
        ];
    }

    /**
     * Which databases answered, and which did not.
     *
     * Taken from whichever payload reported the most complete picture. A summary
     * built on six of eight branches is not wrong, but it must not be read as
     * eight — so the shortfall travels with the figures.
     *
     * @param array<string,?array> $payloads
     */
    private function databases(array $payloads): array
    {
        $best = null;

        foreach ($payloads as $payload) {
            $aggregate = is_array($payload) ? ($payload['aggregate'] ?? null) : null;

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
