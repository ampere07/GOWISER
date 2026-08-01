<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use App\Services\Reports\ReportPeriod;
use App\Services\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The five operational sections: subscriber analytics, financial, operations,
 * tech and employee.
 *
 * Read-only and executive-scoped like the rest of the monitoring API; the
 * 'executive' middleware rejects anything that is not a GET.
 *
 * Every parameter is validated and normalised here rather than in a driver, so a
 * malformed query string produces a 422 with a usable message instead of a SQL
 * error, and the drivers can trust what they are handed.
 */
class ReportingController extends Controller
{
    /** Overdue-ledger buckets. '' means no filter. */
    private const OVERDUE_BUCKETS = ['', '7', '8_30', '30'];

    public function __construct(
        private SourceRegistry $sources,
        private ReportingService $reporting
    ) {
    }

    /** Who the subscribers are, and which of them are a problem. */
    public function subscriberAnalytics(Request $request)
    {
        return $this->section($request, 'subscriber_analytics', fn (string $source) =>
            $this->reporting->subscriberAnalytics($source, $this->params($request)));
    }

    /** Money in, money out, and everything behind both. */
    public function financial(Request $request)
    {
        return $this->section($request, 'financial', fn (string $source) =>
            $this->reporting->financial($source, $this->params($request)));
    }

    /** The field-work queues, their backlog, and how fast they clear. */
    public function operations(Request $request)
    {
        return $this->section($request, 'operations', fn (string $source) =>
            $this->reporting->operations($source, $this->params($request)));
    }

    /** Technician roster, workload and last known field position. */
    public function tech(Request $request)
    {
        return $this->section($request, 'tech', fn (string $source) =>
            $this->reporting->tech($source, $this->params($request)));
    }

    /** Staff and what they produced. */
    public function employee(Request $request)
    {
        return $this->section($request, 'employee', fn (string $source) =>
            $this->reporting->employee($source, $this->params($request)));
    }

    /**
     * Line-level data behind the printable reports.
     *
     * The range is required rather than defaulted: a printed report states its
     * own period, and quietly printing month-to-date because a parameter was
     * dropped would produce a signed document covering the wrong dates.
     */
    public function printable(Request $request)
    {
        $from = $this->date($request->query('date_from'));
        $to = $this->date($request->query('date_to'));

        if ($from === null || $to === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'date_from and date_to are required, in YYYY-MM-DD format.',
            ], 422);
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $this->section($request, 'financial', fn (string $source) => $this->reporting->printable(
            $source,
            $from,
            $to,
            $this->branch($request)
        ));
    }

    /**
     * Selectable branches, so the frontend can build its filter.
     *
     * Empty in aggregate mode: a branch id belongs to one database and means
     * nothing across several, so the frontend hides the control.
     */
    public function branches(Request $request)
    {
        try {
            if ($this->wantsEveryDatabase($request->query('source'))) {
                return response()->json([
                    'status' => 'success',
                    'source' => ReportingService::ALL_SOURCES,
                    'source_label' => 'All databases',
                    'data' => ['branches' => []],
                ]);
            }

            $source = $this->sources->resolveKey($request->query('source'));

            return $this->success($source, ['branches' => $this->reporting->branches($source)]);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e);
        } catch (\Throwable $e) {
            return $this->failure($e, $request);
        }
    }

    /**
     * Which sections each source can serve, and which sources answer each
     * section.
     *
     * The frontend needs both: the first to hide a section nothing can serve,
     * the second because the sections do not all live in one database — the money
     * is in NETMANAGER and the technicians are in GOWISER.
     */
    public function capabilities()
    {
        $sources = [];

        foreach ($this->sources->all() as $key => $source) {
            $sources[] = [
                'key' => $key,
                'label' => $source['label'],
                'capabilities' => $this->reporting->capabilities($key),
            ];
        }

        $sections = [];

        foreach (ReportingService::SECTIONS as $section) {
            $sections[$section] = $this->reporting->sourcesFor($section);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['sources' => $sources, 'sections' => $sections],
        ]);
    }

    /** Whether the caller asked for every database rather than one. */
    private function wantsEveryDatabase($source): bool
    {
        return is_string($source) && strtolower(trim($source)) === ReportingService::ALL_SOURCES;
    }

    // ── Parameter normalisation ──────────────────────────────────────────

    /**
     * Every parameter any section takes, normalised once.
     *
     * One shared shape rather than per-section sets: the frontend keeps a single
     * filter bar across all five, and a parameter a section ignores costs nothing
     * while a parameter it silently drops is a bug someone has to find.
     */
    private function params(Request $request): array
    {
        return [
            'date_from' => $this->date($request->query('date_from')),
            'date_to' => $this->date($request->query('date_to')),
            'branch' => $this->branch($request),
            'as_of' => $this->date($request->query('as_of')),

            // Financial: trend granularity and the branch-collections window.
            'period' => ReportPeriod::normalise($request->query('period'), 'monthly'),
            'branch_period' => ReportPeriod::normalise($request->query('branch_period'), 'monthly'),
            'branch_year' => $this->year($request->query('branch_year')),

            // Subscriber analytics: barangay geography filters.
            'geo_region' => $this->text($request->query('geo_region')),
            'geo_province' => $this->text($request->query('geo_province')),
            'geo_municipality' => $this->text($request->query('geo_municipality')),

            // Subscriber analytics: the overdue ledger.
            'overdue_search' => $this->text($request->query('overdue_search'), 120),
            'overdue_plan_id' => max(0, (int) $request->query('overdue_plan_id', 0)),
            'overdue_bucket' => $this->overdueBucket($request->query('overdue_bucket')),
            'overdue_page' => max(1, (int) $request->query('overdue_page', 1)),
        ];
    }

    private function branch(Request $request): ?string
    {
        $branch = $request->query('branch');

        if ($branch === null || $branch === '' || $branch === 'all') {
            return null;
        }

        return (string) (int) $branch;
    }

    private function date($value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))
            ? trim($value)
            : null;
    }

    /**
     * A plausible year only. Anything else falls back to the current one rather
     * than producing an empty report the user cannot explain.
     */
    private function year($value): int
    {
        $year = (int) $value;

        return ($year >= 2000 && $year <= 2100) ? $year : (int) now()->format('Y');
    }

    private function text($value, int $max = 80): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    private function overdueBucket($value): string
    {
        $bucket = is_string($value) ? trim($value) : '';

        return in_array($bucket, self::OVERDUE_BUCKETS, true) ? $bucket : '';
    }

    // ── Response handling ────────────────────────────────────────────────

    /**
     * Resolves which source answers this section, runs the callback, and turns
     * anything thrown into JSON rather than an HTML stack trace the dashboard
     * cannot render.
     *
     * The source is resolved *for the section*, so a caller pointed at a system
     * that cannot serve it gets the one that can. The response names the source
     * that actually answered, which is what lets the UI say so.
     */
    private function section(Request $request, string $section, callable $callback)
    {
        try {
            $requested = $request->query('source');

            // "all" is a filter, not a source: run the section against every
            // database that can serve it and merge. The merged payload names
            // which databases answered, so a total is never quietly short.
            if ($this->wantsEveryDatabase($requested)) {
                return response()->json([
                    'status' => 'success',
                    'source' => ReportingService::ALL_SOURCES,
                    'source_label' => 'All databases',
                    'requested_source' => ReportingService::ALL_SOURCES,
                    'section' => $section,
                    'data' => $this->reporting->aggregate($section, $this->params($request)),
                ]);
            }

            $source = $this->reporting->resolveSourceFor($section, $requested);

            return $this->success($source, $callback($source), [
                'requested_source' => $this->sources->resolveKey($requested),
                'section' => $section,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e);
        } catch (\Throwable $e) {
            return $this->failure($e, $request);
        }
    }

    private function success(string $source, $data, array $extra = [])
    {
        return response()->json(array_merge([
            'status' => 'success',
            'source' => $source,
            'source_label' => $this->sources->label($source),
            'data' => $data,
        ], $extra));
    }

    private function invalid(InvalidArgumentException $e)
    {
        // Unknown source, or a section no configured system can answer.
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 422);
    }

    private function failure(\Throwable $e, Request $request)
    {
        Log::error('Reporting query failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'endpoint' => $request->path(),
        ]);

        return response()->json([
            'status' => 'error',
            // Driver messages name hosts, ports and table names. Useful in
            // development, not something to hand a browser in production.
            'message' => config('app.debug')
                ? $e->getMessage()
                : 'Unable to reach the monitored database.',
        ], 500);
    }
}
