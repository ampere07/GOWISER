<?php

namespace App\Services;

use App\Services\Reports\ParallelSectionRunner;
use App\Services\Reports\ReportsDriver;
use App\Services\Reports\SectionAggregator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Front door for the five operational sections.
 *
 * Mirrors ExecutiveMetricsService: owns caching, source resolution and the
 * capability check, while the SQL lives in a per-schema driver under
 * App\Services\Reports. Kept as a separate service because these payloads are
 * operational rather than executive, and want their own cache keys and their own
 * capability names.
 */
class ReportingService
{
    /** The sections, in the order the frontend presents them. */
    public const SECTIONS = ['subscriber_analytics', 'financial', 'operations', 'tech', 'employee'];

    /**
     * The source key meaning "every database at once".
     *
     * Reserved in DatabaseConnectionController so no connection can claim it.
     */
    public const ALL_SOURCES = 'all';

    /** Parameters that change a section's answer, and so its cache key. */
    private const CACHE_KEYS = [
        'date_from', 'date_to', 'branch', 'as_of', 'period', 'branch_period', 'branch_year',
        'geo_region', 'geo_province', 'geo_municipality',
        'overdue_search', 'overdue_plan_id', 'overdue_bucket', 'overdue_page',
        // An aggregate request widens the ledger fetch, so it must not share a
        // cache entry with the single-database request for the same page.
        'overdue_fetch_pages',
    ];

    public function __construct(
        private SourceRegistry $sources,
        private SectionAggregator $aggregator,
        private ParallelSectionRunner $runner
    ) {
    }

    /**
     * Runs a section against every database that can serve it and merges the
     * results.
     *
     * A database that fails is reported in the payload rather than aborting the
     * request: with eight branches, one unreachable host must not blank the page —
     * but the total must never be quietly short either, so the merged payload names
     * which databases answered and which did not.
     */
    public function aggregate(string $section, array $params): array
    {
        $sources = $this->sourcesFor($section);

        if ($sources === []) {
            throw new InvalidArgumentException("No database provides a {$section} view.");
        }

        // One database needs no merging, and merging it would relabel it
        // "All databases" — which would be a lie about a single-source figure.
        if (count($sources) === 1) {
            return $this->section($sources[0]['key'], $section, $params);
        }

        $scaled = $this->scaleParams($section, $params);

        $payloads = [];
        $labels = [];
        $failures = [];
        $cold = [];

        // Warm entries are free, so they are taken first and never handed to the
        // runner. With most databases cached, a "cold" fan-out is often one or
        // two — which is why the parallel path has a minimum before it engages.
        foreach ($sources as $source) {
            $key = $source['key'];
            $labels[$key] = $source['label'];

            $cached = $this->cachedSection($key, $section, $scaled);

            if ($cached !== null) {
                $payloads[$key] = $cached;

                continue;
            }

            $cold[] = $key;
        }

        foreach ($this->runCold($cold, $section, $scaled) as $key => $result) {
            if ($result['ok']) {
                $payloads[$key] = $result['payload'];

                continue;
            }

            Log::warning('Aggregate section failed for one database', [
                'section' => $section,
                'source' => $key,
                'error' => $result['error'],
            ]);

            $failures[$key] = config('app.debug')
                ? $result['error']
                : 'This database could not be reached.';
        }

        if ($payloads === []) {
            throw new \RuntimeException('None of the configured databases could be reached.');
        }

        // Restore registry order: the runner returns children as they finish, and
        // a list that reshuffles itself between refreshes is disorienting.
        $ordered = [];

        foreach ($sources as $source) {
            if (isset($payloads[$source['key']])) {
                $ordered[$source['key']] = $payloads[$source['key']];
            }
        }

        return $this->aggregator->merge($section, $ordered, $labels, $failures);
    }

    /**
     * Computes the databases that were not already cached.
     *
     * Parallel when there are enough of them to be worth a process each,
     * otherwise serial. Either way the result shape is identical, so the caller
     * cannot tell which ran — and a parallel failure degrades to serial rather
     * than to an error.
     *
     * @param string[] $cold
     * @return array<string,array{ok:bool,payload?:array,error?:string}>
     */
    private function runCold(array $cold, string $section, array $params): array
    {
        if ($cold === []) {
            return [];
        }

        $results = $this->runner->run($cold, $section, $params);

        if ($results === null) {
            // Runner declined or could not start. Serial fallback.
            $results = [];

            foreach ($cold as $key) {
                try {
                    // section(), not computeSection(): it stores what it computes.
                    // Computing without storing would leave the aggregate path
                    // uncached whenever the runner declines — which is the default
                    // for a small fleet — so every load would pay full price.
                    $results[$key] = ['ok' => true, 'payload' => $this->section($key, $section, $params)];
                } catch (\Throwable $e) {
                    $results[$key] = ['ok' => false, 'error' => $e->getMessage()];
                }
            }

            return $results;
        }

        // The children computed but cannot write to the parent's cache reliably
        // (a different store, a different path), so the parent stores what came
        // back. That also makes these entries serve the single-database views.
        foreach ($results as $key => $result) {
            if ($result['ok']) {
                $this->storeSection($key, $section, $params, $result['payload']);
            }
        }

        return $results;
    }

    /**
     * Widens a paginated request before fanning out.
     *
     * The fleet-wide page P of a worst-first ledger is guaranteed to lie within the
     * union of each database's first P pages — a row cannot rank in the merged
     * page P if it ranked below P*per_page in its own database. So each database is
     * asked for that much, and the merged pool is sliced exactly.
     */
    private function scaleParams(string $section, array $params): array
    {
        if ($section !== 'subscriber_analytics') {
            return $params;
        }

        $page = max(1, (int) ($params['overdue_page'] ?? 1));

        return array_merge($params, ['overdue_fetch_pages' => $page]);
    }

    public function subscriberAnalytics(string $sourceKey, array $params): array
    {
        return $this->section($sourceKey, 'subscriber_analytics', $params);
    }

    public function financial(string $sourceKey, array $params): array
    {
        return $this->section($sourceKey, 'financial', $params);
    }

    public function operations(string $sourceKey, array $params): array
    {
        return $this->section($sourceKey, 'operations', $params);
    }

    public function tech(string $sourceKey, array $params): array
    {
        return $this->section($sourceKey, 'tech', $params);
    }

    public function employee(string $sourceKey, array $params): array
    {
        return $this->section($sourceKey, 'employee', $params);
    }

    /**
     * Line-level data for the printable reports.
     *
     * Deliberately not cached. A printed report is a record someone signs, and
     * handing them a minute-old copy of a ledger that has since changed is a
     * worse failure than the extra query.
     */
    public function printable(string $sourceKey, string $from, string $to, ?string $branch): array
    {
        $driver = $this->driver($sourceKey);

        // The print layouts are the financial ledgers, so they need that
        // capability rather than one of their own.
        $this->assertCapability($sourceKey, $driver, 'financial');

        return $driver->printable($this->sources->connection($sourceKey), $from, $to, $branch);
    }

    /** Branches for a source. Cheap and slow-changing, so cached longer. */
    public function branches(string $sourceKey): array
    {
        return Cache::remember(
            "reporting:{$sourceKey}:branches",
            300,
            fn () => $this->driver($sourceKey)->branches($this->sources->connection($sourceKey))
        );
    }

    public function capabilities(string $sourceKey): array
    {
        try {
            return $this->driver($sourceKey)->capabilities();
        } catch (InvalidArgumentException $e) {
            // A source with no reporting driver simply has none of these
            // sections; that is configuration, not a failure.
            return [];
        }
    }

    public function supports(string $sourceKey, string $section): bool
    {
        return in_array($section, $this->capabilities($sourceKey), true);
    }

    /**
     * Every source that can answer a section, in registry order.
     *
     * Needed because the sections do not all live in one database: NETMANAGER
     * holds the money and GOWISER holds the technicians. Without this the Tech
     * section would be invisible to anyone whose selected source happens to be
     * NETMANAGER, which is the default.
     *
     * @return array<int,array{key:string,label:string}>
     */
    public function sourcesFor(string $section): array
    {
        $matches = [];

        foreach ($this->sources->all() as $key => $source) {
            if ($this->supports($key, $section)) {
                $matches[] = ['key' => $key, 'label' => $source['label']];
            }
        }

        return $matches;
    }

    /**
     * Resolves which source should answer a section.
     *
     * Prefers the caller's choice, but falls back to the first source that can
     * serve the section rather than erroring — so opening Tech while pointed at
     * NETMANAGER shows GOWISER's technicians instead of a dead end. The response
     * always reports which source actually answered, so the substitution is
     * visible rather than silent.
     */
    public function resolveSourceFor(string $section, ?string $requested): string
    {
        $preferred = $this->sources->resolveKey($requested);

        if ($this->supports($preferred, $section)) {
            return $preferred;
        }

        $fallback = $this->sourcesFor($section);

        if ($fallback === []) {
            throw new InvalidArgumentException(
                "No monitored system provides a {$section} view."
            );
        }

        return $fallback[0]['key'];
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function section(string $sourceKey, string $section, array $params): array
    {
        $cached = $this->cachedSection($sourceKey, $section, $params);

        if ($cached !== null) {
            return $cached;
        }

        $payload = $this->computeSection($sourceKey, $section, $params);

        $this->storeSection($sourceKey, $section, $params, $payload);

        return $payload;
    }

    /**
     * Runs the section against its database. No caching.
     *
     * Public because the child process in reporting:section calls exactly this
     * and hands the payload back for the parent to store — a child writing to the
     * cache itself would depend on it sharing the parent's store and path.
     */
    public function computeSection(string $sourceKey, string $section, array $params): array
    {
        $driver = $this->driver($sourceKey);

        $this->assertCapability($sourceKey, $driver, $section);

        $db = $this->sources->connection($sourceKey);

        switch ($section) {
            case 'subscriber_analytics':
                return $driver->subscriberAnalytics($db, $params);
            case 'financial':
                return $driver->financial($db, $params);
            case 'operations':
                return $driver->operations($db, $params);
            case 'tech':
                return $driver->tech($db, $params);
            case 'employee':
                return $driver->employee($db, $params);
            default:
                throw new InvalidArgumentException("Unknown section [{$section}].");
        }
    }

    /** The cached payload, or null on a miss or with caching disabled. */
    private function cachedSection(string $sourceKey, string $section, array $params): ?array
    {
        if ((int) config('monitor.cache_ttl', 60) <= 0) {
            return null;
        }

        $cached = Cache::get($this->cacheKey($sourceKey, $section, $params));

        return is_array($cached) ? $cached : null;
    }

    private function storeSection(string $sourceKey, string $section, array $params, array $payload): void
    {
        $ttl = (int) config('monitor.cache_ttl', 60);

        if ($ttl <= 0) {
            return;
        }

        Cache::put($this->cacheKey($sourceKey, $section, $params), $payload, $ttl);
    }

    /**
     * The cache key for one database's answer to one section.
     *
     * Per database, not per aggregate view — which is what lets a fleet-wide load
     * warm every single-database view at the same time, and lets a mostly-warm
     * fleet re-query only the databases that actually expired.
     */
    private function cacheKey(string $sourceKey, string $section, array $params): string
    {
        return "reporting:{$sourceKey}:" . $this->bucket($section, $params);
    }

    private function driver(string $sourceKey): ReportsDriver
    {
        $name = $this->sources->driverName($sourceKey);
        $class = config("monitor.reporting_drivers.{$name}");

        if (!$class || !class_exists($class)) {
            throw new InvalidArgumentException(
                "The [{$sourceKey}] system does not provide operational reports."
            );
        }

        return app($class);
    }

    private function assertCapability(string $sourceKey, ReportsDriver $driver, string $section): void
    {
        if (!in_array($section, $driver->capabilities(), true)) {
            throw new InvalidArgumentException(
                "The [{$sourceKey}] system does not provide a {$section} view."
            );
        }
    }

    /**
     * A cache key covering every parameter that changes the answer.
     *
     * Built from an explicit whitelist rather than the whole request: an unknown
     * query string parameter must not be able to fill the cache with variants,
     * and a forgotten one must not serve the wrong branch's figures.
     */
    private function bucket(string $section, array $params): string
    {
        $parts = [$section];

        foreach (self::CACHE_KEYS as $key) {
            $parts[] = $key . '=' . (string) ($params[$key] ?? '');
        }

        return $section . ':' . md5(implode('|', $parts));
    }
}
