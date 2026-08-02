<?php

namespace App\Services;

use App\Services\Reports\ReportPeriod;
use Illuminate\Support\Facades\Log;

/**
 * The consolidated C-suite summary: one screen, every company.
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

    public function __construct(private ReportingService $reporting)
    {
    }

    public function build(array $params): array
    {
        [$from, $to] = [$params['date_from'] ?? null, $params['date_to'] ?? null];

        $window = ReportPeriod::dashboardWindow('monthly', $params['as_of'] ?? null);
        $params['date_from'] = $from ?: $window['from'];
        $params['date_to'] = $to ?: $window['to'];

        $sections = [];
        $unavailable = [];

        foreach (self::SECTIONS as $section) {
            try {
                $sections[$section] = $this->reporting->aggregate($section, $params);
            } catch (\Throwable $e) {
                // One unreachable section must not blank the whole summary — the
                // subscriber and operations halves are still worth showing when
                // the finance database is down.
                Log::warning('Executive overview section unavailable', [
                    'section' => $section,
                    'error' => $e->getMessage(),
                ]);

                $unavailable[$section] = config('app.debug')
                    ? $e->getMessage()
                    : 'This data could not be reached.';
            }
        }

        return [
            'as_of' => ReportPeriod::anchor($params['as_of'] ?? null)->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'range' => ['from' => $params['date_from'], 'to' => $params['date_to']],
            'range_label' => $this->rangeLabel($params['date_from'], $params['date_to']),

            'subscriber_health' => $this->subscriberHealth($sections['subscriber_analytics'] ?? null),
            'financial_summary' => $this->financialSummary($sections['financial'] ?? null),
            'operations_tech' => $this->operationsTech(
                $sections['operations'] ?? null,
                $sections['tech'] ?? null
            ),

            'databases' => $this->databases($sections),
            'unavailable' => $unavailable,
        ];
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

    /** Income by channel, OpEx against CapEx, and what is still owed. */
    private function financialSummary(?array $financial): array
    {
        if ($financial === null) {
            return ['available' => false];
        }

        $channels = [];

        foreach ($financial['income_channels'] ?? [] as $channel) {
            $channels[$channel['key']] = [
                'label' => $channel['label'],
                'total' => (float) $channel['total'],
                'count' => (int) $channel['count'],
                'share_pct' => (float) $channel['share_pct'],
            ];
        }

        return [
            'available' => true,
            'total_income' => (float) ($financial['kpi']['income'] ?? 0),
            'channels' => $channels,
            'opex' => (float) ($financial['opex_capex']['opex']['total'] ?? 0),
            'capex' => (float) ($financial['opex_capex']['capex']['total'] ?? 0),
            'net' => (float) ($financial['kpi']['net'] ?? 0),
            'margin_pct' => $financial['kpi']['margin_pct'] ?? null,
            'outstanding_payables' => (float) ($financial['payables']['outstanding'] ?? 0),
            'payables_unpaid_count' => (int) ($financial['payables']['totals']['unpaid']['count'] ?? 0),
            'metrics' => $financial['executive_metrics'] ?? null,
            'range_label' => $financial['range_label'] ?? '',
        ];
    }

    /**
     * Average resolution turnaround, and what is currently alarming.
     *
     * "Active system alarms" is not a monitoring feed — neither source runs one —
     * so it is derived from the conditions an executive would actually be paged
     * about: backlog that has aged past a threshold, and technician devices that
     * have gone quiet. Each alarm names its own basis so nobody reads it as an
     * SNMP trap.
     */
    private function operationsTech(?array $operations, ?array $tech): array
    {
        $alarms = [];

        $openWork = 0;
        $oldest = null;
        $turnaround = null;
        $turnaroundUnit = null;

        if ($operations !== null) {
            foreach ($operations['queues'] ?? [] as $queue) {
                $openWork += (int) ($queue['backlog']['open'] ?? 0);

                $age = $queue['backlog']['oldest_age_days'] ?? null;

                if ($age !== null) {
                    $oldest = $oldest === null ? (int) $age : max($oldest, (int) $age);
                }
            }

            // Weighted across the reported types, so a category that closed three
            // jobs does not pull the headline as hard as one that closed three
            // hundred.
            [$turnaround, $turnaroundUnit] = $this->averageTurnaround($operations['turnaround_by_type'] ?? []);

            if ($oldest !== null && $oldest > 30) {
                $alarms[] = [
                    'key' => 'aged-backlog',
                    'severity' => 'critical',
                    'label' => 'Aged backlog',
                    'detail' => "Oldest open job has been waiting {$oldest} days",
                ];
            }

            if ($openWork > 100) {
                $alarms[] = [
                    'key' => 'backlog-volume',
                    'severity' => 'warning',
                    'label' => 'Backlog volume',
                    'detail' => number_format($openWork) . ' jobs still open across every queue',
                ];
            }
        }

        $techniciansLive = null;
        $techniciansTotal = null;

        if ($tech !== null) {
            $locations = $tech['locations'] ?? [];
            $techniciansTotal = count($locations);
            $techniciansLive = count(array_filter($locations, fn ($row) => (bool) ($row['is_live'] ?? false)));

            if ($techniciansTotal > 0 && $techniciansLive === 0) {
                $alarms[] = [
                    'key' => 'no-field-devices',
                    'severity' => 'warning',
                    'label' => 'No technician devices reporting',
                    'detail' => "None of {$techniciansTotal} devices has reported a recent position",
                ];
            }

            $unattributed = (int) ($tech['unattributed']['job_orders'] ?? 0)
                + (int) ($tech['unattributed']['service_orders'] ?? 0);

            if ($unattributed > 0) {
                $alarms[] = [
                    'key' => 'unattributed-work',
                    'severity' => 'info',
                    'label' => 'Unattributed work',
                    'detail' => number_format($unattributed) . ' jobs have nobody recorded against them',
                ];
            }
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
            'alarms' => $alarms,
            'alarm_count' => count($alarms),
        ];
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
