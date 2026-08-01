<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExecutiveMetricsService;
use App\Services\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Read-only rollups for the executive dashboards.
 *
 * Guarded by the 'executive' middleware, which rejects unauthenticated
 * sessions and any request method other than GET.
 */
class MonitorController extends Controller
{
    public function __construct(
        private SourceRegistry $sources,
        private ExecutiveMetricsService $metrics
    ) {
    }

    /**
     * Which databases this deployment is watching. The frontend uses this to
     * build its source switcher, so adding a branch database needs no
     * frontend change.
     */
    public function sources()
    {
        // Capabilities travel with each source so the frontend can hide
        // navigation a given schema cannot serve, instead of offering a page
        // that only fails once opened.
        $sources = array_values(array_map(
            fn ($source) => [
                'key' => $source['key'],
                'label' => $source['label'],
                'capabilities' => $this->safeCapabilities($source['key']),
            ],
            $this->sources->all()
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'sources' => $sources,
                // Null rather than an exception when nothing is configured.
                // resolveKey(null) throws to stop a *report* silently reading
                // some arbitrary database, but this endpoint's whole job is to
                // say what exists, so it has to be able to say "nothing". A
                // fresh install has no connections yet, and throwing here 500s
                // the dashboard shell — including the navigation to the
                // Databases page that is the only way to fix it.
                'default' => $sources === [] ? null : $this->sources->resolveKey(null),
            ],
        ]);
    }

    public function overview(Request $request)
    {
        return $this->respond($request, fn (string $source) => $this->metrics->overview($source));
    }

    public function operations(Request $request)
    {
        return $this->respond($request, fn (string $source) => $this->metrics->operations($source));
    }

    public function revenue(Request $request)
    {
        $months = (int) $request->query('months', 12);

        return $this->respond($request, fn (string $source) => $this->metrics->revenue($source, $months));
    }

    /**
     * Income vs expenses vs net, with the breakdowns behind them.
     *
     * ?period= daily|weekly|monthly|yearly   ?branch= driver-specific id
     */
    public function financials(Request $request)
    {
        $period = (string) $request->query('period', 'monthly');

        $branch = $request->query('branch');
        $branch = ($branch === null || $branch === '' || $branch === 'all') ? null : (string) $branch;

        // Anchor date, so management can look back at a closed month.
        $asOf = $request->query('date');
        $asOf = is_string($asOf) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : null;

        return $this->respond(
            $request,
            fn (string $source) => $this->metrics->financials($source, $period, $branch, $asOf)
        );
    }

    /**
     * Selectable branches for a source, so the frontend can build its filter.
     */
    public function branches(Request $request)
    {
        return $this->respond($request, fn (string $source) => [
            'branches' => $this->metrics->branches($source),
        ]);
    }

    /**
     * Every source side by side, plus group totals.
     */
    public function consolidated()
    {
        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->metrics->consolidated(),
            ]);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * Resolves ?source=, runs the callback, and turns anything thrown into a
     * JSON error rather than an HTML stack trace the dashboard can't render.
     */
    private function respond(Request $request, callable $callback)
    {
        try {
            $source = $this->sources->resolveKey($request->query('source'));

            return response()->json([
                'status' => 'success',
                'source' => $source,
                'source_label' => $this->sources->label($source),
                'data' => $callback($source),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * A source whose database is unreachable must not break the whole source
     * list, so a failure here degrades to "no capabilities" rather than a 500.
     */
    private function safeCapabilities(string $key): array
    {
        try {
            return $this->metrics->capabilities($key);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function failure(\Throwable $e)
    {
        Log::error('Monitor metrics failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
        ]);

        return response()->json([
            'status' => 'error',
            'message' => config('app.debug')
                ? $e->getMessage()
                : 'Unable to reach the monitored database.',
        ], 500);
    }
}
