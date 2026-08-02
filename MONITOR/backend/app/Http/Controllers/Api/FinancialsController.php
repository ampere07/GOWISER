<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Connector\ConnectionManager;
use App\Services\Connector\Period;
use App\Services\Dashboards\FinancialsDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FinancialsController extends Controller
{
    public function __construct(
        private ConnectionManager $connections,
        private FinancialsDashboard $dashboard
    ) {
    }

    /**
     * ?site= &period=daily|weekly|monthly|yearly &date=YYYY-MM-DD
     */
    public function show(Request $request)
    {
        try {
            $site = $this->resolveSite($request);
            $period = $this->resolvePeriod($request);

            $key = "dash:financials:{$site}:{$period->granularity}:{$period->anchor->toDateString()}";

            return response()->json([
                'status' => 'success',
                'data' => $this->cached($key, fn () => $this->dashboard->build($site, $period)),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /** Every site side by side for the same window. */
    public function group(Request $request)
    {
        try {
            $period = $this->resolvePeriod($request);
            $key = "dash:financials:group:{$period->granularity}:{$period->anchor->toDateString()}";

            return response()->json([
                'status' => 'success',
                'data' => $this->cached($key, fn () => $this->dashboard->group($period)),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * Site scoping is enforced here, not just hidden in the UI — a role
     * restricted to one branch must not be able to read another by editing
     * the query string.
     */
    private function resolveSite(Request $request): string
    {
        $site = $this->connections->resolveKey($request->query('site'));
        $allowed = $request->user()?->allowedSites();

        if ($allowed !== null && !in_array($site, $allowed, true)) {
            throw new InvalidArgumentException('You do not have access to that site.');
        }

        return $site;
    }

    private function resolvePeriod(Request $request): Period
    {
        return Period::make(
            (string) $request->query('period', 'monthly'),
            $request->query('date')
        );
    }

    private function cached(string $key, callable $callback)
    {
        $ttl = (int) config('monitor.cache_ttl', 60);

        return $ttl > 0 ? Cache::remember($key, $ttl, $callback) : $callback();
    }

    private function failure(\Throwable $e)
    {
        Log::error('Financials dashboard failed: ' . $e->getMessage());

        $detail = (config('app.debug') || request()->user()?->isAdmin())
            ? ': ' . $e->getMessage()
            : '';

        return response()->json([
            'status' => 'error',
            'message' => 'Unable to reach the monitored database' . $detail,
        ], 500);
    }
}
