<?php

namespace App\Services\Reports;

/**
 * Merges the same section from several databases into one payload.
 *
 * Every branch runs its own database with the same table structure, so "all
 * databases" means running the section per database and combining the results.
 * How to combine depends entirely on what the figure *is*, and getting that
 * wrong produces numbers that look plausible and are not:
 *
 *   sums        money and counts — income, expenses, subscriber statuses
 *   re-ranked   league tables — plan mix, barangay ranking, expense categories.
 *               Summed by label, then re-sorted and re-capped, because the
 *               fleet-wide top ten is not the concatenation of ten top-tens.
 *   weighted    averages — an average of averages is wrong unless weighted by
 *               how many rows each average covered.
 *   tagged      rows about one person or account — technicians, staff, overdue
 *               accounts. These are not summable; they are concatenated and each
 *               keeps a `source` so it stays attributable to its branch.
 *
 * Partial results are reported rather than hidden: if one of eight databases is
 * unreachable, the caller is told which, so a total is never quietly short.
 */
class SectionAggregator
{
    /**
     * @param array<string,array> $payloads section payload keyed by source key
     * @param array<string,string> $labels source key => display label
     * @param array<string,string> $failures source key => error message
     */
    public function merge(
        string $section,
        array $payloads,
        array $labels,
        array $failures = []
    ): array {
        $merged = $this->mergeSection($section, $payloads, $labels);

        // Always present, always accurate, whether or not anything failed.
        $merged['aggregate'] = [
            'is_aggregate' => true,
            'section' => $section,
            'answered' => array_keys($payloads),
            'answered_labels' => array_values(array_intersect_key($labels, $payloads)),
            'failed' => array_map(
                fn (string $key, string $error) => [
                    'key' => $key,
                    'label' => $labels[$key] ?? $key,
                    'error' => $error,
                ],
                array_keys($failures),
                $failures
            ),
            'total_databases' => count($payloads) + count($failures),
        ];

        return $merged;
    }

    private function mergeSection(string $section, array $payloads, array $labels): array
    {
        switch ($section) {
            case 'subscriber_analytics':
                return $this->subscriberAnalytics($payloads, $labels);
            case 'financial':
                return $this->financial($payloads, $labels);
            case 'operations':
                return $this->operations($payloads, $labels);
            case 'tech':
                return $this->tech($payloads, $labels);
            default:
                return $this->employee($payloads, $labels);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SUBSCRIBER ANALYTICS
    // ═════════════════════════════════════════════════════════════════════

    private function subscriberAnalytics(array $payloads, array $labels): array
    {
        $first = $this->first($payloads);

        return array_merge($this->envelope($payloads, $labels), [
            'kpi' => $this->sumFields($payloads, 'kpi', [
                'total', 'active', 'vip', 'restricted', 'disconnected',
                'expiring_3day', 'expiring_7day', 'new_30day',
            ]),
            'billing_summary' => $this->sumFields($payloads, 'billing_summary', [
                'active', 'vip', 'inactive', 'pullout', 'total',
            ]),
            'status' => $this->mergeStatusCounts($payloads),
            // Plans and sessions count subscribers; they carry no money, so
            // countOnly keeps a meaningless `total: 0` out of the payload.
            'plans' => $this->rank($payloads, 'plans', 'count', null, null, true),
            'barangays' => $this->mergeBarangays($payloads),
            'growth' => [
                'new_in_range' => $this->sumPath($payloads, ['growth', 'new_in_range']),
                'expected_mrc' => $this->sumPath($payloads, ['growth', 'expected_mrc']),
            ],
            'overdue' => $this->mergeOverdue($payloads, $labels),
            'sessions' => $this->rank($payloads, 'sessions', 'count', null, null, true),
        ]);
    }

    /**
     * Status counts, including statuses only some databases use.
     *
     * A branch on a newer release may have a status the others do not; dropping
     * it would make the totals disagree with the sum of the parts.
     */
    private function mergeStatusCounts(array $payloads): array
    {
        $byStatus = [];

        foreach ($payloads as $payload) {
            foreach ($payload['status']['by_status'] ?? [] as $label => $count) {
                $byStatus[$label] = ($byStatus[$label] ?? 0) + (int) $count;
            }
        }

        arsort($byStatus);

        return array_merge(
            $this->sumFields($payloads, 'status', [
                'total', 'active', 'vip', 'restricted', 'disconnected',
                'inactive', 'pullout', 'excluded',
            ]),
            ['by_status' => $byStatus]
        );
    }

    /**
     * Every barangay across the fleet.
     *
     * Keyed on barangay *and* municipality: "San Roque" exists in many towns, and
     * merging them across branches would invent a place that does not exist.
     *
     * No longer trimmed after merging. Each database now contributes its complete
     * list rather than its own top ten, so the merged result is already the whole
     * picture — and re-capping it would reintroduce exactly the truncation this
     * table was rebuilt to remove.
     */
    private function mergeBarangays(array $payloads): array
    {
        $columns = ['total', 'active', 'vip', 'inactive', 'pullout', 'restricted', 'disconnected'];
        $rows = [];

        foreach ($payloads as $payload) {
            foreach ($payload['barangays'] ?? [] as $row) {
                $key = strtolower(trim($row['barangay'] ?? '')) . '|' . strtolower(trim($row['municipality'] ?? ''));

                if (!isset($rows[$key])) {
                    $rows[$key] = array_merge(
                        [
                            'barangay' => $row['barangay'] ?? '',
                            'municipality' => $row['municipality'] ?? '',
                            'province' => $row['province'] ?? '',
                        ],
                        array_fill_keys($columns, 0)
                    );
                }

                foreach ($columns as $column) {
                    $rows[$key][$column] += (int) ($row[$column] ?? 0);
                }
            }
        }

        // Alphabetical, matching what each driver returns: the table sorts itself
        // client-side, and imposing a ranking here would fight that.
        usort($rows, fn ($a, $b) => strcasecmp($a['barangay'], $b['barangay']));

        return array_values($rows);
    }

    /**
     * The overdue ledger across every database, worst first.
     *
     * Correctness note. Each database was asked for `page * per_page` rows from
     * its own worst-first ordering, so the fleet-wide page P is guaranteed to lie
     * within the union of those — the Pth page of the merged set cannot contain a
     * row that ranked below position P*per_page in its own database. Slicing the
     * merged pool is therefore exact, not an approximation.
     *
     * Rows carry `source_label` so an account stays attributable to the branch
     * that has to chase it.
     */
    private function mergeOverdue(array $payloads, array $labels): array
    {
        $rows = [];
        $total = 0;
        $perPage = 25;
        $page = 1;
        $plans = [];
        $bucketKind = 'days';
        $filters = ['search' => '', 'plan_id' => 0, 'bucket' => ''];

        foreach ($payloads as $key => $payload) {
            $ledger = $payload['overdue'] ?? [];

            $total += (int) ($ledger['total'] ?? 0);
            $perPage = (int) ($ledger['per_page'] ?? $perPage);
            $page = (int) ($ledger['page'] ?? $page);
            $filters = $ledger['filters'] ?? $filters;
            $bucketKind = $ledger['bucket_kind'] ?? $bucketKind;

            foreach ($ledger['rows'] ?? [] as $row) {
                $row['source'] = $key;
                $row['source_label'] = $labels[$key] ?? $key;
                $rows[] = $row;
            }

            // Plan filter options are per-database ids, which cannot be applied
            // across databases — so the aggregate view offers none. The frontend
            // hides the control when the list is empty.
            foreach ($ledger['plans'] ?? [] as $plan) {
                $plans[$plan['label']] = $plan;
            }
        }

        // Worst first: days overdue where the schema tracks it, otherwise the
        // amount owed. Both are "how bad is this account".
        usort($rows, function ($a, $b) {
            $left = $a['days_overdue'] ?? null;
            $right = $b['days_overdue'] ?? null;

            if ($left !== null && $right !== null) {
                return $right <=> $left;
            }

            return ($b['mrc'] ?? 0) <=> ($a['mrc'] ?? 0);
        });

        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $totalPages);

        return [
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'filters' => $filters,
            // Deliberately empty: a plan id means nothing across databases.
            'plans' => [],
            'bucket_kind' => $bucketKind,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  FINANCIAL
    // ═════════════════════════════════════════════════════════════════════

    private function financial(array $payloads, array $labels): array
    {
        $kpi = $this->sumFields($payloads, 'kpi', [
            'income', 'income_count', 'largest_payment',
            'office_income', 'office_count', 'portal_income', 'portal_count',
            'expenses', 'expenses_count', 'net', 'expected_mrc',
        ]);

        // Derived figures must be recomputed from the merged totals, never summed.
        // Adding eight margins together, or eight collection rates, is meaningless.
        $kpi['average_payment'] = $kpi['income_count'] > 0
            ? round($kpi['income'] / $kpi['income_count'], 2)
            : 0.0;
        $kpi['margin_pct'] = $kpi['income'] > 0
            ? round($kpi['net'] / $kpi['income'] * 100, 1)
            : null;
        $kpi['collection_rate'] = $kpi['expected_mrc'] > 0
            ? min(999.0, round($kpi['income'] / $kpi['expected_mrc'] * 100, 1))
            : 0.0;
        $kpi['office_by_type'] = $this->rank($payloads, null, 'total', null, fn ($payload) => $payload['kpi']['office_by_type'] ?? []);

        $first = $this->first($payloads);

        return array_merge($this->envelope($payloads, $labels), [
            'expense_period' => $first['expense_period'] ?? 'daily',
            'supports_expenses' => true,
            'kpi' => $kpi,
            'series' => $this->mergeTrend($payloads, fn ($payload) => $payload['series'] ?? []),
            'trend' => [
                'period' => $first['trend']['period'] ?? 'monthly',
                'points' => $this->mergeTrend($payloads, fn ($payload) => $payload['trend']['points'] ?? []),
            ],
            'by_plan' => $this->rank($payloads, 'by_plan', 'total'),
            'by_method' => $this->rank($payloads, 'by_method', 'total'),
            'by_expense_type' => $this->rank($payloads, 'by_expense_type', 'total'),
            'payment_notes' => $this->rank($payloads, 'payment_notes', 'total'),

            'income_channels' => $this->mergeChannels($payloads),
            'executive_metrics' => $this->mergeExecutiveMetrics($payloads, $kpi),
            'opex_capex' => $this->mergeOpexCapex($payloads),
            'payables' => $this->mergePayables($payloads, $labels),

            // Each database's branches, tagged so two branches with the same name
            // in different databases stay distinct. Shares are recomputed against
            // the fleet total.
            'by_branch' => $this->mergeBranches($payloads, $labels),

            'periods' => $this->mergePeriods($payloads),
        ]);
    }

    /**
     * Cash / PNB / Xendit across every database.
     *
     * Matched on the channel key rather than the label so the three named
     * channels stay in a fixed order and always appear, even at zero — a channel
     * that vanishes from a three-column summary reads as a loading failure rather
     * than as no collections.
     */
    private function mergeChannels(array $payloads): array
    {
        $channels = [];

        foreach ($payloads as $payload) {
            foreach ($payload['income_channels'] ?? [] as $row) {
                $key = (string) ($row['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                if (!isset($channels[$key])) {
                    $channels[$key] = [
                        'key' => $key,
                        'label' => $row['label'] ?? $key,
                        'count' => 0,
                        'total' => 0.0,
                        'methods' => [],
                    ];
                }

                $channels[$key]['count'] += (int) ($row['count'] ?? 0);
                $channels[$key]['total'] += (float) ($row['total'] ?? 0);
                $channels[$key]['methods'] = array_unique(array_merge(
                    $channels[$key]['methods'],
                    $row['methods'] ?? []
                ));
            }
        }

        $grand = array_sum(array_column($channels, 'total'));

        // Share is recomputed against the fleet total; summing per-database
        // percentages would produce something over 100.
        return array_values(array_map(function (array $channel) use ($grand) {
            $channel['total'] = $this->tidy($channel['total']);
            $channel['share_pct'] = $grand > 0 ? round($channel['total'] / $grand * 100, 1) : 0.0;
            $channel['methods'] = array_values($channel['methods']);

            return $channel;
        }, $channels));
    }

    /**
     * The four executive measures, recomputed from merged totals.
     *
     * Never averaged across databases: the mean of eight ARPUs is not the ARPU of
     * the combined base unless every base is the same size, and it never is. Each
     * ratio is rebuilt from the summed numerator and summed denominator, and only
     * the absolute figures — prospective revenue, churn loss — are summed directly.
     */
    private function mergeExecutiveMetrics(array $payloads, array $kpi): array
    {
        $prospective = 0.0;
        $churnLoss = 0.0;
        $atRisk = 0;
        $factor = null;
        $rangeLabel = '';

        foreach ($payloads as $payload) {
            $metrics = $payload['executive_metrics'] ?? [];

            $prospective += (float) ($metrics['prospective_revenue']['value'] ?? 0);
            $churnLoss += (float) ($metrics['projected_churn_loss']['value'] ?? 0);
            $atRisk += (int) ($metrics['projected_churn_loss']['at_risk_accounts'] ?? 0);
            $factor ??= $metrics['projected_churn_loss']['at_risk_factor'] ?? null;
            $rangeLabel = $rangeLabel !== '' ? $rangeLabel : ($payload['range_label'] ?? '');
        }

        $activeSubs = 0;

        foreach ($payloads as $payload) {
            $activeSubs += (int) ($payload['kpi']['active'] ?? 0);
        }

        $collected = (float) ($kpi['income'] ?? 0);

        return [
            'prospective_revenue' => [
                'label' => 'Prospective Revenue',
                'value' => $this->tidy($prospective),
                'basis' => 'Active base at plan price, one month, every database',
            ],
            'arpu' => [
                'label' => 'ARPU',
                'value' => $activeSubs > 0 ? round($prospective / $activeSubs, 2) : null,
                'basis' => $activeSubs > 0
                    ? 'Combined expected MRC ÷ ' . number_format($activeSubs) . ' active subscribers'
                    : 'No active subscribers to divide by',
            ],
            'collection_efficiency' => [
                'label' => 'Collection Efficiency',
                'value' => $prospective > 0
                    ? min(999.0, round($collected / $prospective * 100, 1))
                    : null,
                'basis' => $prospective > 0
                    ? 'Combined collections in ' . $rangeLabel . ' ÷ combined expected MRC'
                    : 'No billable base in this scope',
            ],
            'projected_churn_loss' => [
                'label' => 'Projected Churn Loss',
                'value' => $this->tidy($churnLoss),
                'basis' => number_format($atRisk) . ' disconnected × '
                    . round((float) ($factor ?? 0) * 100) . '% assumed not to return, per month',
                'at_risk_accounts' => $atRisk,
                'at_risk_factor' => $factor,
            ],
        ];
    }

    /** OpEx and CapEx totals, with each side's type breakdown merged and re-ranked. */
    private function mergeOpexCapex(array $payloads): array
    {
        $merged = [];

        foreach (['opex', 'capex'] as $nature) {
            $total = 0.0;
            $count = 0;
            $rows = [];

            foreach ($payloads as $payload) {
                $block = $payload['opex_capex'][$nature] ?? [];

                $total += (float) ($block['total'] ?? 0);
                $count += (int) ($block['count'] ?? 0);

                foreach ($block['rows'] ?? [] as $row) {
                    $label = (string) ($row['label'] ?? '');

                    if (!isset($rows[$label])) {
                        $rows[$label] = ['label' => $label, 'count' => 0, 'total' => 0.0];
                    }

                    $rows[$label]['count'] += (int) ($row['count'] ?? 0);
                    $rows[$label]['total'] += (float) ($row['total'] ?? 0);
                }
            }

            usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

            $merged[$nature] = [
                'label' => $nature === 'opex' ? 'Operating Expenses' : 'Capital Expenditures',
                'total' => $this->tidy($total),
                'count' => $count,
                'rows' => array_map(
                    fn (array $row) => array_merge($row, ['total' => $this->tidy($row['total'])]),
                    array_values($rows)
                ),
            ];
        }

        $grand = $merged['opex']['total'] + $merged['capex']['total'];

        foreach (['opex', 'capex'] as $nature) {
            $merged[$nature]['share_pct'] = $grand > 0
                ? round($merged[$nature]['total'] / $grand * 100, 1)
                : 0.0;
        }

        $merged['total'] = $this->tidy($grand);

        return $merged;
    }

    /**
     * Payables across every database, kept as separate rows.
     *
     * Deliberately not summed by label. Rent in one branch and rent in another
     * are two obligations settled by two people, and merging them into one line
     * would leave a single tick claiming both were paid. Each row therefore keeps
     * its source, and its ref is namespaced by source so the toggle addresses the
     * right one.
     */
    private function mergePayables(array $payloads, array $labels): array
    {
        $rows = [];
        $totals = [
            'recurring' => ['count' => 0, 'amount' => 0.0],
            'non_recurring' => ['count' => 0, 'amount' => 0.0],
            'paid' => ['count' => 0, 'amount' => 0.0],
            'unpaid' => ['count' => 0, 'amount' => 0.0],
        ];

        $month = '';
        $monthLabel = '';

        foreach ($payloads as $key => $payload) {
            $ledger = $payload['payables'] ?? [];

            $month = $month !== '' ? $month : ($ledger['month'] ?? '');
            $monthLabel = $monthLabel !== '' ? $monthLabel : ($ledger['month_label'] ?? '');

            foreach ($ledger['rows'] ?? [] as $row) {
                $row['source'] = $key;
                $row['source_label'] = $labels[$key] ?? $key;
                $rows[] = $row;
            }

            foreach ($totals as $bucket => $_) {
                $totals[$bucket]['count'] += (int) ($ledger['totals'][$bucket]['count'] ?? 0);
                $totals[$bucket]['amount'] += (float) ($ledger['totals'][$bucket]['amount'] ?? 0);
            }
        }

        usort($rows, function (array $a, array $b) {
            if (($a['is_paid'] ?? false) !== ($b['is_paid'] ?? false)) {
                return ($a['is_paid'] ?? false) ? 1 : -1;
            }

            return ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0);
        });

        foreach ($totals as $bucket => $total) {
            $totals[$bucket]['amount'] = $this->tidy($total['amount']);
        }

        return [
            'month' => $month,
            'month_label' => $monthLabel,
            'source' => null,
            'rows' => $rows,
            'totals' => $totals,
            'outstanding' => $totals['unpaid']['amount'],
            'settlement_scope' => 'monitor',
        ];
    }

    /** Income/expenses/net timelines, summed on the date bucket. */
    private function mergeTrend(array $payloads, callable $extract): array
    {
        $buckets = [];

        foreach ($payloads as $payload) {
            foreach ($extract($payload) as $point) {
                $key = (string) ($point['period'] ?? '');

                if ($key === '') {
                    continue;
                }

                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'period' => $key,
                        'label' => $point['label'] ?? $key,
                        'income' => 0.0,
                        'expenses' => 0.0,
                        'net' => 0.0,
                    ];
                }

                $buckets[$key]['income'] += (float) ($point['income'] ?? 0);
                $buckets[$key]['expenses'] += (float) ($point['expenses'] ?? 0);
            }
        }

        ksort($buckets);

        // Net recomputed from the merged sides rather than summed, so it can never
        // drift from income - expenses.
        return array_values(array_map(function (array $bucket) {
            $bucket['income'] = round($bucket['income'], 2);
            $bucket['expenses'] = round($bucket['expenses'], 2);
            $bucket['net'] = round($bucket['income'] - $bucket['expenses'], 2);

            return $bucket;
        }, $buckets));
    }

    /** Per-branch collections from every database, shares recomputed fleet-wide. */
    private function mergeBranches(array $payloads, array $labels): array
    {
        $rows = [];
        $years = [];
        $first = $this->first($payloads);

        foreach ($payloads as $key => $payload) {
            $block = $payload['by_branch'] ?? [];

            foreach ($block['years'] ?? [] as $year) {
                $years[(int) $year] = true;
            }

            foreach ($block['rows'] ?? [] as $row) {
                $row['id'] = $key . ':' . ($row['id'] ?? '');
                $row['label'] = ($labels[$key] ?? $key) . ' — ' . ($row['label'] ?? '');
                $row['source'] = $key;
                $rows[] = $row;
            }
        }

        $total = array_sum(array_column($rows, 'collection'));

        $rows = array_map(function (array $row) use ($total) {
            $row['share_pct'] = $total > 0 ? round(((float) $row['collection']) / $total * 100, 1) : 0.0;

            return $row;
        }, $rows);

        usort($rows, fn ($a, $b) => $b['collection'] <=> $a['collection']);

        krsort($years);

        return [
            'period' => $first['by_branch']['period'] ?? 'monthly',
            'year' => $first['by_branch']['year'] ?? (int) now()->format('Y'),
            'label' => $first['by_branch']['label'] ?? '',
            'rows' => $rows,
            'years' => array_keys($years),
        ];
    }

    /** The four horizons, summed across databases. */
    private function mergePeriods(array $payloads): array
    {
        $periods = [];

        foreach ($payloads as $payload) {
            foreach ($payload['periods'] ?? [] as $period) {
                $key = (string) ($period['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                if (!isset($periods[$key])) {
                    $periods[$key] = array_merge($period, [
                        'income' => 0.0,
                        'payment_count' => 0,
                        'expenses' => 0.0,
                        'expenses_count' => 0,
                        'net' => 0.0,
                        'ratio_pct' => null,
                    ]);
                }

                $periods[$key]['income'] += (float) ($period['income'] ?? 0);
                $periods[$key]['payment_count'] += (int) ($period['payment_count'] ?? 0);
                $periods[$key]['expenses'] += (float) ($period['expenses'] ?? 0);
                $periods[$key]['expenses_count'] += (int) ($period['expenses_count'] ?? 0);
            }
        }

        return array_values(array_map(function (array $period) {
            $period['income'] = round($period['income'], 2);
            $period['expenses'] = round($period['expenses'], 2);
            $period['net'] = round($period['income'] - $period['expenses'], 2);
            $period['ratio_pct'] = $period['income'] > 0
                ? round(abs($period['net']) / $period['income'] * 100, 1)
                : null;

            return $period;
        }, $periods));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  OPERATIONS
    // ═════════════════════════════════════════════════════════════════════

    private function operations(array $payloads, array $labels): array
    {
        $first = $this->first($payloads);

        return array_merge($this->envelope($payloads, $labels), [
            'queues' => $this->mergeQueues($payloads),
            'series' => $this->mergeWorkSeries($payloads),
            'turnaround' => $this->mergeTurnaround($payloads),
            'turnaround_by_type' => $this->mergeTurnaroundByType($payloads),
            'recent' => $this->mergeRecent(
                $payloads,
                $labels,
                fn ($payload) => $payload['recent'] ?? [],
                'opened_at'
            ),
            'has_service_orders' => $this->anyTrue($payloads, 'has_service_orders'),
            'concerns' => $this->rank($payloads, 'concerns', 'count', 10, null, true),
            'repair_categories' => $this->rank($payloads, 'repair_categories', 'count', 10, null, true),
        ]);
    }

    /**
     * Turnaround per work-order type across every database.
     *
     * Averages are weighted by the number of jobs each database closed, for the
     * same reason weightedTurnaround exists: a branch that closed three jobs must
     * not pull the fleet average as hard as one that closed three hundred.
     *
     * Types are matched on label *and* unit. GOWISER measures minutes on site and
     * NETMANAGER measures the age of a ticket in hours, and averaging those
     * together would produce a number that is not a duration of anything.
     */
    private function mergeTurnaroundByType(array $payloads): array
    {
        $types = [];

        foreach ($payloads as $payload) {
            foreach ($payload['turnaround_by_type'] ?? [] as $row) {
                $unit = (string) ($row['unit'] ?? 'minutes');
                $key = strtolower(trim((string) ($row['label'] ?? ''))) . '|' . $unit;

                if ($key === '|' . $unit) {
                    continue;
                }

                if (!isset($types[$key])) {
                    $types[$key] = [
                        'label' => $row['label'] ?? '',
                        'group' => $row['group'] ?? null,
                        'unit' => $unit,
                        'closed' => 0,
                        'weighted' => 0.0,
                        'longest' => null,
                    ];
                }

                $field = $unit === 'hours' ? 'average_hours' : 'average_minutes';
                $longestField = $unit === 'hours' ? 'longest_hours' : 'longest_minutes';

                $closed = (int) ($row['closed'] ?? 0);
                $average = $row[$field] ?? null;
                $longest = $row[$longestField] ?? null;

                $types[$key]['closed'] += $closed;

                if ($average !== null) {
                    $types[$key]['weighted'] += (float) $average * $closed;
                }

                // Longest is the worst case anywhere, so a max rather than a sum.
                if ($longest !== null) {
                    $types[$key]['longest'] = $types[$key]['longest'] === null
                        ? (int) $longest
                        : max($types[$key]['longest'], (int) $longest);
                }
            }
        }

        $rows = [];

        foreach ($types as $type) {
            $average = $type['closed'] > 0 ? round($type['weighted'] / $type['closed'], 1) : null;
            $unit = $type['unit'];

            $rows[] = [
                'label' => $type['label'],
                'group' => $type['group'],
                'unit' => $unit,
                'closed' => $type['closed'],
                $unit === 'hours' ? 'average_hours' : 'average_minutes' => $average,
                $unit === 'hours' ? 'longest_hours' : 'longest_minutes' => $type['longest'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['closed'] <=> $a['closed']);

        return $rows;
    }

    /**
     * Work queues, matched on their key so Job Orders from eight databases
     * become one Job Orders queue.
     *
     * The oldest open item is the *max* age across databases, not a sum — the
     * question is "how long has anything been waiting", and that is the worst
     * case anywhere.
     */
    private function mergeQueues(array $payloads): array
    {
        $queues = [];

        foreach ($payloads as $payload) {
            foreach ($payload['queues'] ?? [] as $queue) {
                $key = (string) ($queue['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                if (!isset($queues[$key])) {
                    $queues[$key] = [
                        'key' => $key,
                        'label' => $queue['label'] ?? $key,
                        'statuses' => [],
                        'backlog' => ['open' => 0, 'oldest_opened_at' => null, 'oldest_age_days' => null],
                    ];
                }

                foreach ($queue['statuses'] ?? [] as $status) {
                    $label = (string) ($status['label'] ?? '');
                    $queues[$key]['statuses'][$label] =
                        ($queues[$key]['statuses'][$label] ?? 0) + (int) ($status['count'] ?? 0);
                }

                $backlog = $queue['backlog'] ?? [];
                $queues[$key]['backlog']['open'] += (int) ($backlog['open'] ?? 0);

                $age = $backlog['oldest_age_days'] ?? null;
                $current = $queues[$key]['backlog']['oldest_age_days'];

                if ($age !== null && ($current === null || $age > $current)) {
                    $queues[$key]['backlog']['oldest_age_days'] = (int) $age;
                    $queues[$key]['backlog']['oldest_opened_at'] = $backlog['oldest_opened_at'] ?? null;
                }
            }
        }

        return array_values(array_map(function (array $queue) {
            arsort($queue['statuses']);

            $queue['statuses'] = array_map(
                fn ($label, $count) => ['label' => $label, 'count' => $count],
                array_keys($queue['statuses']),
                $queue['statuses']
            );

            return $queue;
        }, $queues));
    }

    /** Opened and closed counts, summed on the date bucket. */
    private function mergeWorkSeries(array $payloads): array
    {
        $buckets = [];

        foreach ($payloads as $payload) {
            foreach ($payload['series'] ?? [] as $point) {
                $key = (string) ($point['period'] ?? '');

                if ($key === '') {
                    continue;
                }

                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'period' => $key,
                        'label' => $point['label'] ?? $key,
                        'opened' => 0,
                        'closed' => 0,
                    ];
                }

                $buckets[$key]['opened'] += (int) ($point['opened'] ?? 0);
                $buckets[$key]['closed'] += (int) ($point['closed'] ?? 0);
            }
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * Turnaround across databases.
     *
     * Averages are weighted by how many items each covered — a branch that closed
     * two jobs must not pull the fleet average as hard as one that closed two
     * hundred. Longest is the max, since it is already an extreme.
     */
    private function mergeTurnaround(array $payloads)
    {
        $first = $this->first($payloads);
        $split = isset($first['turnaround']['job_orders']);

        if (!$split) {
            return $this->weightedTurnaround(array_map(
                fn ($payload) => $payload['turnaround'] ?? [],
                $payloads
            ));
        }

        return [
            'job_orders' => $this->weightedTurnaround(array_map(
                fn ($payload) => $payload['turnaround']['job_orders'] ?? [],
                $payloads
            )),
            'service_orders' => $this->weightedTurnaround(array_map(
                fn ($payload) => $payload['turnaround']['service_orders'] ?? [],
                $payloads
            )),
        ];
    }

    private function weightedTurnaround(array $blocks): array
    {
        $closed = 0;
        $result = ['closed' => 0];

        foreach (['average_hours' => 'longest_hours', 'average_minutes' => 'longest_minutes'] as $avgKey => $maxKey) {
            $weightedSum = 0.0;
            $weight = 0;
            $longest = null;
            $present = false;

            foreach ($blocks as $block) {
                if (!array_key_exists($avgKey, $block)) {
                    continue;
                }

                $present = true;
                $count = (int) ($block['closed'] ?? 0);
                $average = $block[$avgKey] ?? null;

                if ($average !== null && $count > 0) {
                    $weightedSum += ((float) $average) * $count;
                    $weight += $count;
                }

                $max = $block[$maxKey] ?? null;

                if ($max !== null && ($longest === null || $max > $longest)) {
                    $longest = $max;
                }
            }

            if (!$present) {
                continue;
            }

            $result[$avgKey] = $weight > 0 ? round($weightedSum / $weight, 1) : null;
            $result[$maxKey] = $longest !== null ? (int) $longest : null;
        }

        foreach ($blocks as $block) {
            $closed += (int) ($block['closed'] ?? 0);
        }

        $result['closed'] = $closed;

        return $result;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  TECH
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Technicians are people at a branch, not a quantity.
     *
     * Rosters, workloads and positions are concatenated with a source tag rather
     * than merged by name: two branches can employ someone with the same name, and
     * collapsing them would attribute one person's jobs to another.
     */
    private function tech(array $payloads, array $labels): array
    {
        $roster = [];
        $workload = [];
        $locations = [];
        $unattributed = ['job_orders' => 0, 'service_orders' => 0];

        foreach ($payloads as $key => $payload) {
            $label = $labels[$key] ?? $key;

            foreach ($payload['roster'] ?? [] as $row) {
                $roster[] = array_merge($row, [
                    'id' => $key . ':' . ($row['id'] ?? ''),
                    'source' => $key,
                    'source_label' => $label,
                ]);
            }

            foreach ($payload['workload'] ?? [] as $row) {
                $workload[] = array_merge($row, [
                    'id' => $key . ':' . ($row['id'] ?? ''),
                    'source' => $key,
                    'source_label' => $label,
                ]);
            }

            foreach ($payload['locations'] ?? [] as $row) {
                $locations[] = array_merge($row, [
                    'user_id' => $key . ':' . ($row['user_id'] ?? ''),
                    'source' => $key,
                    'source_label' => $label,
                ]);
            }

            $unattributed['job_orders'] += (int) ($payload['unattributed']['job_orders'] ?? 0);
            $unattributed['service_orders'] += (int) ($payload['unattributed']['service_orders'] ?? 0);
        }

        usort($workload, fn ($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
        usort($roster, fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        // Live devices first, then most recently seen.
        usort($locations, function ($a, $b) {
            if (($a['is_live'] ?? false) !== ($b['is_live'] ?? false)) {
                return ($b['is_live'] ?? false) <=> ($a['is_live'] ?? false);
            }

            return ($a['minutes_ago'] ?? PHP_INT_MAX) <=> ($b['minutes_ago'] ?? PHP_INT_MAX);
        });

        return array_merge($this->envelope($payloads, $labels), [
            'roster' => $roster,
            'roster_count' => count($roster),
            'workload' => $workload,
            'locations' => $locations,
            'unattributed' => $unattributed,
            'turnaround' => $this->mergeTurnaround($payloads),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  EMPLOYEE
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Staff are people at a branch, so rosters and per-person figures are
     * concatenated and tagged. Role counts and the payee ledger *are* summable.
     */
    private function employee(array $payloads, array $labels): array
    {
        $roster = [];
        $collections = [];
        $fieldWork = [];

        foreach ($payloads as $key => $payload) {
            $label = $labels[$key] ?? $key;

            foreach ($payload['roster'] ?? [] as $row) {
                $roster[] = array_merge($row, [
                    'id' => $key . ':' . ($row['id'] ?? ''),
                    // Staff have no branch column in a single-company schema, so
                    // the database name is the only branch attribution there is.
                    'branch' => $row['branch'] ?: $label,
                    'source' => $key,
                    'source_label' => $label,
                ]);
            }

            foreach ($payload['collections'] ?? [] as $row) {
                $collections[] = array_merge($row, ['source' => $key, 'source_label' => $label]);
            }

            foreach ($payload['field_work'] ?? [] as $row) {
                $fieldWork[] = array_merge($row, ['source' => $key, 'source_label' => $label]);
            }
        }

        usort($collections, fn ($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
        usort($fieldWork, fn ($a, $b) => ($b['assigned'] ?? 0) <=> ($a['assigned'] ?? 0));
        usort($roster, fn ($a, $b) => strcmp((string) ($a['role'] ?? ''), (string) ($b['role'] ?? ''))
            ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return array_merge($this->envelope($payloads, $labels), [
            'roster' => $roster,
            'by_role' => $this->mergeRoles($payloads),
            'collections' => $collections,
            'field_work' => $fieldWork,
            'payees' => $this->rank($payloads, 'payees', 'total'),
            'supports_payees' => $this->anyTrue($payloads, 'supports_payees'),
        ]);
    }

    private function mergeRoles(array $payloads): array
    {
        $roles = [];

        foreach ($payloads as $payload) {
            foreach ($payload['by_role'] ?? [] as $row) {
                $label = (string) ($row['label'] ?? '');

                if (!isset($roles[$label])) {
                    $roles[$label] = ['label' => $label, 'count' => 0, 'active' => 0];
                }

                $roles[$label]['count'] += (int) ($row['count'] ?? 0);
                $roles[$label]['active'] += (int) ($row['active'] ?? 0);
            }
        }

        usort($roles, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_values($roles);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SHARED
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Fields shared by every section payload.
     *
     * The date range is taken from the first database rather than merged: every
     * database was asked for the same range, so they all agree.
     */
    private function envelope(array $payloads, array $labels): array
    {
        $first = $this->first($payloads);

        return [
            'as_of' => $first['as_of'] ?? now()->toDateString(),
            'generated_at' => now()->toDateTimeString(),
            'range' => $first['range'] ?? null,
            'range_label' => $first['range_label'] ?? '',
            'branch' => null,
            'branch_label' => count($payloads) === 1
                ? ($labels[array_key_first($payloads)] ?? 'All databases')
                : 'All databases',
        ];
    }

    private function first(array $payloads): array
    {
        return $payloads === [] ? [] : reset($payloads);
    }

    /**
     * Sums the named numeric fields of one block across databases.
     *
     * A field that is null in *every* database stays null — "not tracked" must
     * not become zero. A field null in only some is treated as zero there, which
     * is the right reading when other databases do track it.
     */
    private function sumFields(array $payloads, ?string $block, array $fields): array
    {
        $totals = [];

        foreach ($fields as $field) {
            $sum = 0.0;
            $seen = false;
            $present = false;

            foreach ($payloads as $payload) {
                $data = $block === null ? $payload : ($payload[$block] ?? []);

                if (!array_key_exists($field, $data)) {
                    continue;
                }

                $present = true;
                $value = $data[$field];

                if ($value === null) {
                    continue;
                }

                $seen = true;
                $sum += (float) $value;
            }

            if (!$present) {
                continue;
            }

            $totals[$field] = $seen ? $this->tidy($sum) : null;
        }

        return $totals;
    }

    private function sumPath(array $payloads, array $path)
    {
        $sum = 0.0;
        $seen = false;

        foreach ($payloads as $payload) {
            $value = $payload;

            foreach ($path as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }

            if ($value === null) {
                continue;
            }

            $seen = true;
            $sum += (float) $value;
        }

        return $seen ? $this->tidy($sum) : null;
    }

    /** Whole numbers stay integers; money keeps two decimals. */
    private function tidy(float $value)
    {
        return $value === floor($value) && abs($value) < PHP_INT_MAX
            ? (int) $value
            : round($value, 2);
    }

    /**
     * Merges a labelled list by label, re-sorts by the given measure, and
     * re-caps.
     *
     * The re-cap matters: each database contributed its own top ten, so the
     * merged list is longer than ten and the fleet-wide top ten is a different set
     * of rows from any one database's.
     */
    private function rank(
        array $payloads,
        ?string $block,
        string $measure,
        ?int $limit = null,
        ?callable $extract = null,
        bool $countOnly = false
    ): array {
        $rows = [];

        foreach ($payloads as $payload) {
            $list = $extract ? $extract($payload) : ($payload[$block] ?? []);

            foreach ($list ?? [] as $row) {
                $label = (string) ($row['label'] ?? '');

                if ($label === '') {
                    continue;
                }

                if (!isset($rows[$label])) {
                    $rows[$label] = ['label' => $label, 'count' => 0];

                    if (!$countOnly) {
                        $rows[$label]['total'] = 0.0;
                    }
                }

                $rows[$label]['count'] += (int) ($row['count'] ?? 0);

                if (!$countOnly) {
                    $rows[$label]['total'] += (float) ($row['total'] ?? 0);
                }
            }
        }

        $rows = array_map(function (array $row) {
            if (isset($row['total'])) {
                $row['total'] = round($row['total'], 2);
            }

            return $row;
        }, $rows);

        usort($rows, fn ($a, $b) => ($b[$measure] ?? 0) <=> ($a[$measure] ?? 0));

        $rows = array_values($rows);

        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * Recent-activity feeds, interleaved by date and tagged with their database.
     *
     * Capped at the same ten a single database shows: this is a glance at what
     * just happened, and eighty rows is not that.
     */
    private function mergeRecent(
        array $payloads,
        array $labels,
        callable $extract,
        string $dateField,
        int $limit = 15
    ): array {
        $rows = [];

        foreach ($payloads as $key => $payload) {
            foreach ($extract($payload) as $row) {
                $rows[] = array_merge($row, [
                    'id' => $key . ':' . ($row['id'] ?? ''),
                    'source' => $key,
                    'source_label' => $labels[$key] ?? $key,
                ]);
            }
        }

        usort($rows, fn ($a, $b) => strcmp(
            (string) ($b[$dateField] ?? ''),
            (string) ($a[$dateField] ?? '')
        ));

        return array_slice($rows, 0, $limit);
    }

    private function anyTrue(array $payloads, string $field): bool
    {
        foreach ($payloads as $payload) {
            if (!empty($payload[$field])) {
                return true;
            }
        }

        return false;
    }
}
