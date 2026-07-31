<?php

namespace App\Services\Dashboards;

use App\Services\Connector\ConnectionManager;
use App\Services\Connector\MetricEngine;
use App\Services\Connector\Period;

/**
 * The executive money view, composed entirely from canonical datasets.
 *
 * Nothing here knows a table name. A site that maps `payments` and `expenses`
 * gets the full picture; one that maps only `payments` still gets collections,
 * with the net figures marked unavailable rather than shown as zero.
 */
class FinancialsDashboard
{
    public function __construct(
        private ConnectionManager $connections,
        private MetricEngine $engine
    ) {
    }

    public function build(string $site, Period $period): array
    {
        $hasPayments = $this->engine->supports($site, 'payments');
        $hasExpenses = $this->engine->supports($site, 'expenses');

        if (!$hasPayments) {
            throw new \RuntimeException('This site does not map any payment data.');
        }

        $income = $this->engine->total($site, 'payments', $period);
        $expenses = $hasExpenses
            ? $this->engine->total($site, 'expenses', $period)
            : ['value' => 0.0, 'count' => 0];

        $incomeMove = $this->engine->compare($site, 'payments', $period);

        $net = $hasExpenses ? round($income['value'] - $expenses['value'], 2) : null;
        $margin = ($hasExpenses && $income['value'] > 0)
            ? round($net / $income['value'] * 100, 1)
            : null;

        return [
            'site' => $site,
            'site_label' => $this->connections->site($site)->label,
            'period' => $period->toArray(),
            'has_expenses' => $hasExpenses,

            'kpi' => [
                'income' => $income['value'],
                'income_count' => $income['count'],
                'income_previous' => $incomeMove['previous'],
                'income_change_pct' => $incomeMove['change_pct'],
                'expenses' => $expenses['value'],
                'expenses_count' => $expenses['count'],
                'net' => $net,
                'margin_pct' => $margin,
                'receivables' => $this->receivables($site),
            ],

            'series' => $this->series($site, $period, $hasExpenses),
            'by_method' => $this->engine->breakdown($site, 'payments', 'method', $period, ['limit' => 10]),
            'by_type' => $this->engine->breakdown($site, 'payments', 'type', $period, ['limit' => 10]),
            'by_expense_category' => $hasExpenses
                ? $this->engine->breakdown($site, 'expenses', 'category', $period, ['limit' => 12])
                : [],
            'top_collectors' => $this->engine->breakdown($site, 'payments', 'processed_by', $period, ['limit' => 8]),
        ];
    }

    /**
     * Income, expenses and net on one timeline. The two datasets are bucketed
     * independently then aligned, because a period can have expenses and no
     * collections — and that period must still appear, or a loss disappears
     * from the chart.
     */
    private function series(string $site, Period $period, bool $hasExpenses): array
    {
        $income = collect($this->engine->series($site, 'payments', $period))->keyBy('period');

        $expenses = $hasExpenses
            ? collect($this->engine->series($site, 'expenses', $period))->keyBy('period')
            : collect();

        $buckets = $income->keys()->merge($expenses->keys())->unique()->sort()->values();

        return $buckets->map(function ($bucket) use ($income, $expenses, $hasExpenses) {
            $inc = (float) ($income->get($bucket)['value'] ?? 0);
            $exp = (float) ($expenses->get($bucket)['value'] ?? 0);

            return [
                'period' => (string) $bucket,
                'label' => (string) ($income->get($bucket)['label'] ?? $expenses->get($bucket)['label'] ?? $bucket),
                'income' => round($inc, 2),
                'expenses' => round($exp, 2),
                'net' => $hasExpenses ? round($inc - $exp, 2) : null,
            ];
        })->all();
    }

    /**
     * Outstanding balances are a snapshot, not a period — asking "receivables
     * in March" is meaningless, so no period is applied.
     */
    private function receivables(string $site): ?array
    {
        if (!$this->engine->supports($site, 'receivables')) {
            return null;
        }

        $total = $this->engine->total($site, 'receivables', null, [
            'filters' => [['field' => 'balance', 'op' => 'gt', 'value' => 0]],
        ]);

        return [
            'total' => $total['value'],
            'accounts' => $total['count'],
        ];
    }

    /**
     * Every site side by side for the same window — the group P&L. Sites that
     * cannot answer are reported, not silently dropped from the totals.
     */
    public function group(Period $period): array
    {
        $rows = [];
        $totals = ['income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];

        foreach ($this->connections->sites() as $key => $site) {
            if (!$this->engine->supports($key, 'payments')) {
                $rows[] = [
                    'site' => $key,
                    'label' => $site->label,
                    'ok' => false,
                    'error' => 'No payment data mapped.',
                ];

                continue;
            }

            try {
                $income = $this->engine->total($key, 'payments', $period);
                $hasExpenses = $this->engine->supports($key, 'expenses');
                $expenses = $hasExpenses
                    ? $this->engine->total($key, 'expenses', $period)
                    : ['value' => 0.0, 'count' => 0];

                $net = round($income['value'] - $expenses['value'], 2);

                $rows[] = [
                    'site' => $key,
                    'label' => $site->label,
                    'ok' => true,
                    'income' => $income['value'],
                    'expenses' => $expenses['value'],
                    'net' => $hasExpenses ? $net : null,
                    'has_expenses' => $hasExpenses,
                ];

                $totals['income'] += $income['value'];
                $totals['expenses'] += $expenses['value'];
                $totals['net'] += $net;
            } catch (\Throwable $e) {
                report($e);

                $rows[] = [
                    'site' => $key,
                    'label' => $site->label,
                    'ok' => false,
                    'error' => config('app.debug') ? $e->getMessage() : 'This site could not be reached.',
                ];
            }
        }

        return [
            'period' => $period->toArray(),
            'sites' => $rows,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
        ];
    }
}
