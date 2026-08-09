<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ExecutiveOverviewService;
use App\Services\Reports\GowiserReportsDriver;
use App\Services\Reports\StatusMap;
use App\Services\ReportingService;
use App\Support\PayloadMasker;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Module 5 — the consolidated C-suite summary.
 *
 * Two gates, not one. The module permission decides whether the tab exists; the
 * role check decides whether this particular view is appropriate at all. They
 * are separate because this screen puts every company's money, headcount and
 * backlog on one page, and that is not something a custom role should acquire by
 * being handed a module id it was granted for another reason.
 *
 * Every successful open is recorded. This is the single most sensitive view in
 * the portal and the audit question about it — who has been reading the group
 * numbers — is one an executive is entitled to have answered.
 */
class ExecutiveOverviewController extends Controller
{
    public function __construct(
        private ExecutiveOverviewService $overview,
        private ReportingService $reporting
    ) {
    }

    public function show(Request $request)
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $user = $request->user();

        try {
            $data = $this->overview->build($this->params($request));

            AuditLog::record(
                $request,
                'viewed',
                'section',
                'executive_overview',
                'Executive Dashboard opened'
            );

            // The two money blocks still honour the widget permission. An auditor
            // may open this view without being entitled to the figures on it, and
            // masking here rather than in React means they never reach the
            // browser at all.
            //
            // `masked` rather than an absent key: the layout keeps its shape and
            // the panel says "restricted" instead of "no data", which are
            // different claims and send a reader to different places.
            if (!$user->can_(Permissions::WIDGET_EXECUTIVE_FINANCE)) {
                foreach (['daily', 'monthly'] as $block) {
                    $data[$block] = [
                        'available' => false,
                        'masked' => true,
                        'range' => $data[$block]['range'] ?? null,
                        'range_label' => $data[$block]['range_label'] ?? '',
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => PayloadMasker::apply('executive_overview', $data, $user->permissionList()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Executive dashboard failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to build the executive summary.',
            ], 500);
        }
    }

    /**
     * One work-stream window: applications, job orders and service orders.
     *
     * Separate from show() because those three widgets each carry an independent
     * date range, and moving one of them must not re-run the whole dashboard —
     * the financial fan-out behind it is by far the most expensive part of this
     * page and none of it changes when someone puts Service Orders on the month.
     *
     * Not audited. Opening the dashboard is the access event and show() records
     * it; logging a row every time a widget's range moves would bury that.
     */
    public function workStreams(Request $request)
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->overview->workStreamsFor($this->params($request)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Executive work streams failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to build the work-order summary.',
            ], 500);
        }
    }

    /**
     * The subscribers behind one billing-status counter.
     *
     * The drill-down under the Subscriber Analytics grid. Behind the same two
     * gates as the dashboard itself — this is a named list of every customer in
     * a status, which is strictly more sensitive than the count that opened it,
     * and it must not be reachable by a role that cannot open the page.
     *
     * Not audited per request. Opening the dashboard is the access event and
     * show() records it; a row per page of a modal somebody is scrolling would
     * bury it.
     */
    public function subscribers(Request $request)
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $validated = $request->validate([
            // The reported bucket keys, not raw source statuses — see
            // ReportsDriver::subscribersByStatus for why the distinction matters.
            'status' => ['required', 'string', Rule::in(array_keys(StatusMap::BILLING_BUCKETS))],
            // Set when a Subscriber Plan tile was the thing clicked: the list is
            // then that status *within* that plan, which is exactly the
            // population the tile counted.
            'plan' => ['nullable', 'string', 'max:128'],
            'search' => ['nullable', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . ReportingService::MAX_PER_PAGE],
        ]);

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->reporting->subscriberDirectory([
                    'status' => $validated['status'],
                    'plan' => trim((string) ($validated['plan'] ?? '')),
                    'search' => trim((string) ($validated['search'] ?? '')),
                    'page' => (int) ($validated['page'] ?? 1),
                    'per_page' => (int) ($validated['per_page'] ?? 25),
                    'branch' => null,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('Subscriber drill-down failed: ' . $e->getMessage(), [
                'status' => $validated['status'],
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to list those subscribers.',
            ], 500);
        }
    }

    /**
     * The records behind one metric tile.
     *
     * Every metric card on the Group Overview opens this: the same rows the tile
     * counted, searchable, filterable, sortable and paged. Behind the same two
     * gates as the dashboard — a named list of subscribers is strictly more
     * sensitive than the count above it.
     *
     * The window comes from the caller rather than being recomputed here, so the
     * modal is scoped to exactly the range the card was counted in. Recomputing
     * it from the timeframe would be a second interpretation of the same control
     * and the two would drift.
     */
    public function metricRecords(Request $request)
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $validated = $request->validate([
            'metric' => ['required', 'string', Rule::in(GowiserReportsDriver::workMetrics())],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:128'],
            'plan' => ['nullable', 'string', 'max:128'],
            'area' => ['nullable', 'string', 'max:128'],
            // Whitelisted rather than free-form: this reaches an ORDER BY, and
            // the driver maps it through its own list as well. Two gates because
            // the connection is read-only but is still pointed at production.
            'sort' => ['nullable', Rule::in([
                'subscriber', 'account_number', 'occurred_at', 'status', 'plan', 'location',
            ])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . ReportingService::MAX_PER_PAGE],
        ]);

        // The money metrics list transactions, so they follow the same widget
        // permission that masks the two money blocks on the dashboard itself.
        // Without this a role that sees "restricted" where the Income tile
        // should be could still read every payment line by asking for them
        // directly — masking a figure while serving the ledger behind it is not
        // a restriction, it is a longer route to the same data.
        if (GowiserReportsDriver::isMoneyMetric($validated['metric'])
            && !$request->user()->can_(Permissions::WIDGET_EXECUTIVE_FINANCE)) {
            return $this->deny($request, 'role cannot see executive financial figures');
        }

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->reporting->workRecordDirectory([
                    'metric' => $validated['metric'],
                    'date_from' => $validated['date_from'] ?? null,
                    'date_to' => $validated['date_to'] ?? null,
                    'search' => trim((string) ($validated['search'] ?? '')),
                    'plan' => trim((string) ($validated['plan'] ?? '')),
                    'area' => trim((string) ($validated['area'] ?? '')),
                    'sort' => $validated['sort'] ?? 'occurred_at',
                    'direction' => $validated['direction'] ?? 'desc',
                    'page' => (int) ($validated['page'] ?? 1),
                    'per_page' => (int) ($validated['per_page'] ?? 25),
                    'branch' => null,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('Metric drill-down failed: ' . $e->getMessage(), [
                'metric' => $validated['metric'],
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to list those records.',
            ], 500);
        }
    }

    /** The four sections a saved layout may position or style. Whitelist for putLayout(). */
    private const SECTION_KEYS = ['range', 'monthly', 'subscribers', 'plans'];

    /**
     * This user's saved Group Overview grid layout, or null if they have never
     * saved one — the frontend falls back to localStorage, then to the default
     * stacked layout, in that order.
     *
     * Not audited, for the same reason workStreams()/metricRecords() are not:
     * opening the dashboard is the access event show() already records, and a
     * preference read is not a second one.
     */
    public function getLayout(Request $request)
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $preferences = $request->user()->preferences;

        return response()->json([
            'status' => 'success',
            'data' => (is_array($preferences) ? $preferences['executive_overview_layout'] ?? null : null),
        ]);
    }

    /**
     * Saves this user's Group Overview grid layout.
     *
     * Read-modify-write under a row lock, touching only the
     * `executive_overview_layout` key of the `preferences` JSON column — any
     * other key already on the row (including the legacy `overview_refresh`/
     * `mikrotik_refresh` values the column no longer drives, see User::$preferences)
     * survives untouched. A resent identical payload produces the same end
     * state, so this is naturally idempotent: no duplicate rows, nothing orphaned.
     */
    public function putLayout(Request $request)
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $rules = [];

        foreach (self::SECTION_KEYS as $key) {
            $rules["positions.{$key}"] = ['required', 'array'];
            $rules["positions.{$key}.x"] = ['required', 'integer', 'min:0', 'max:1'];
            $rules["positions.{$key}.y"] = ['required', 'integer', 'min:0', 'max:50'];
            $rules["positions.{$key}.w"] = ['required', Rule::in([1, 2])];
            $rules["cardSettings.{$key}"] = ['nullable', 'array'];
            $rules["cardSettings.{$key}.fontSize"] = ['nullable', 'integer', 'min:8', 'max:72'];
            $rules["cardSettings.{$key}.cols"] = ['nullable', 'integer', 'min:1', 'max:5'];
            $rules["cardSettings.{$key}.rows"] = ['nullable', 'integer', 'min:1', 'max:8'];
        }

        // validate() drops any key not named above — that is the whitelist. A
        // fifth section key or an extra field on the payload cannot be smuggled
        // into this user's preferences blob.
        $validated = $request->validate($rules);

        try {
            $saved = DB::transaction(function () use ($request, $validated) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->first();
                $preferences = is_array($user->preferences) ? $user->preferences : [];
                $preferences['executive_overview_layout'] = $validated;
                $user->preferences = $preferences;
                $user->save();

                return $preferences['executive_overview_layout'];
            });

            Log::info('Executive overview layout saved', ['user_id' => $request->user()->id]);

            return response()->json(['status' => 'success', 'data' => $saved]);
        } catch (\Throwable $e) {
            Log::error('Executive overview layout save failed: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Unable to save the layout.',
            ], 500);
        }
    }

    /** The two gates, or null when both pass. */
    private function guard(Request $request)
    {
        $user = $request->user();

        if (!$user->can_(Permissions::MODULE_EXECUTIVE)) {
            return $this->deny($request, 'role lacks the executive-overview module');
        }

        if (!$user->isExecutiveRole()) {
            return $this->deny($request, "role [{$user->roleName()}] is not an executive role");
        }

        return null;
    }

    /** The section parameters, from a request whose dates are already validated. */
    private function params(Request $request): array
    {
        $timeframe = strtolower(trim((string) $request->query('timeframe', 'daily')));

        return [
            // The global date toolbar. An unrecognised value falls back to daily
            // in the service rather than erroring — a stale bookmark carrying a
            // timeframe this build no longer offers should open the dashboard,
            // not a 422.
            'timeframe' => in_array($timeframe, ExecutiveOverviewService::TIMEFRAMES, true)
                ? $timeframe
                : 'daily',
            'date_from' => $this->date($request->query('date_from')),
            'date_to' => $this->date($request->query('date_to')),
            'as_of' => $this->date($request->query('as_of')),
            'branch' => null,
            'period' => 'monthly',
            'branch_period' => 'monthly',
            'branch_year' => (int) now()->format('Y'),
        ];
    }

    private function deny(Request $request, string $reason)
    {
        AuditLog::record(
            $request,
            'denied',
            'section',
            'executive_overview',
            "Blocked access to the executive group overview — {$reason}"
        );

        return response()->json([
            'status' => 'error',
            'message' => 'The executive group overview is restricted to executive roles.',
            'missing' => [Permissions::MODULE_EXECUTIVE],
        ], 403);
    }

    private function date($value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))
            ? trim($value)
            : null;
    }
}
