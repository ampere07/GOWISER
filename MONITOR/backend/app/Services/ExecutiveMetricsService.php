<?php

namespace App\Services;

use App\Services\Metrics\MetricsDriver;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Front door for every dashboard figure.
 *
 * Owns caching, source resolution and error containment; the actual SQL lives
 * in a per-schema driver under App\Services\Metrics. Results are cached (see
 * config/monitor.cache_ttl) because a dashboard left open on a wall screen
 * would otherwise hammer a production database once per poll interval, per
 * viewer.
 */
class ExecutiveMetricsService
{
    public function __construct(private SourceRegistry $sources)
    {
    }

    public function overview(string $sourceKey): array
    {
        return $this->remember($sourceKey, 'overview', 'overview',
            fn (MetricsDriver $driver, $db) => $driver->overview($db));
    }

    public function operations(string $sourceKey): array
    {
        return $this->remember($sourceKey, 'operations', 'operations',
            fn (MetricsDriver $driver, $db) => $driver->operations($db));
    }

    public function revenue(string $sourceKey, int $months = 12): array
    {
        $months = max(1, min($months, 36));

        return $this->remember($sourceKey, 'revenue', "revenue:{$months}",
            fn (MetricsDriver $driver, $db) => $driver->revenue($db, $months));
    }

    /**
     * Income, expenses, net and the breakdowns behind them.
     *
     * @param string|null $branch driver-specific branch id; null means all
     */
    public function financials(
        string $sourceKey,
        string $period = 'monthly',
        ?string $branch = null,
        ?string $asOf = null
    ): array {
        $bucket = 'financials:' . $period . ':' . ($branch ?? 'all') . ':' . ($asOf ?? 'today');

        return $this->remember($sourceKey, 'financials', $bucket,
            fn (MetricsDriver $driver, $db) => $driver->financials($db, $period, $branch, $asOf));
    }

    /**
     * Branches within a source. Cheap and rarely changing, so cached longer.
     */
    public function branches(string $sourceKey): array
    {
        return Cache::remember(
            "monitor:{$sourceKey}:branches",
            300,
            fn () => $this->driver($sourceKey)->branches($this->sources->connection($sourceKey))
        );
    }

    public function capabilities(string $sourceKey): array
    {
        return $this->driver($sourceKey)->capabilities();
    }

    /**
     * The same headline block for every source that can produce one, plus
     * group totals. Sources without an overview (financial-only schemas) are
     * listed as unsupported rather than silently dropped.
     */
    public function consolidated(): array
    {
        $rows = [];
        $totals = [
            'total_accounts' => 0,
            'online' => 0,
            'receivables' => 0.0,
            'revenue_mtd' => 0.0,
            'applications_mtd' => 0,
        ];

        foreach ($this->sources->all() as $key => $source) {
            if (!in_array('overview', $this->capabilities($key), true)) {
                $rows[] = [
                    'source' => $key,
                    'label' => $source['label'],
                    'reachable' => true,
                    'supported' => false,
                    'error' => 'This system reports financials only.',
                    'overview' => null,
                ];

                continue;
            }

            try {
                $overview = $this->overview($key);

                $rows[] = [
                    'source' => $key,
                    'label' => $source['label'],
                    'reachable' => true,
                    'supported' => true,
                    'overview' => $overview,
                ];

                $totals['total_accounts'] += $overview['total_accounts'];
                $totals['online'] += $overview['sessions']['online'] ?? 0;
                $totals['receivables'] += $overview['receivables'];
                $totals['revenue_mtd'] += $overview['revenue_mtd'];
                $totals['applications_mtd'] += $overview['applications_mtd'];
            } catch (\Throwable $e) {
                // One unreachable branch database must not blank the whole
                // consolidated view — report it as down and carry on.
                report($e);

                $rows[] = [
                    'source' => $key,
                    'label' => $source['label'],
                    'reachable' => false,
                    'supported' => true,
                    // The raw driver message names hosts and ports. Useful in
                    // development, not something to hand to a browser in
                    // production.
                    'error' => config('app.debug')
                        ? $e->getMessage()
                        : 'This database could not be reached.',
                    'overview' => null,
                ];
            }
        }

        $totals['receivables'] = round($totals['receivables'], 2);
        $totals['revenue_mtd'] = round($totals['revenue_mtd'], 2);

        return [
            'sources' => $rows,
            'totals' => $totals,
        ];
    }

    private function driver(string $sourceKey): MetricsDriver
    {
        $name = $this->sources->driverName($sourceKey);
        $class = config("monitor.drivers.{$name}");

        if (!$class || !class_exists($class)) {
            throw new InvalidArgumentException("No metrics driver registered for [{$name}].");
        }

        return app($class);
    }

    /**
     * Runs $callback against the source's driver and connection, cached per
     * source. Refuses up front if the schema cannot answer this question, so
     * the caller gets a clear 422 instead of a SQL error about a missing table.
     */
    private function remember(string $sourceKey, string $capability, string $bucket, callable $callback): array
    {
        $driver = $this->driver($sourceKey);

        if (!in_array($capability, $driver->capabilities(), true)) {
            throw new InvalidArgumentException(
                "The [{$sourceKey}] system does not provide a {$capability} view."
            );
        }

        $ttl = (int) config('monitor.cache_ttl', 60);
        $run = fn () => $callback($driver, $this->sources->connection($sourceKey));

        if ($ttl <= 0) {
            return $run();
        }

        return Cache::remember("monitor:{$sourceKey}:{$bucket}", $ttl, $run);
    }
}
