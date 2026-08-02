<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\ExecutiveOverviewService;
use App\Support\PayloadMasker;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    public function __construct(private ExecutiveOverviewService $overview)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();

        if (!$user->can_(Permissions::MODULE_EXECUTIVE)) {
            return $this->deny($request, 'role lacks the executive-overview module');
        }

        if (!$user->isExecutiveRole()) {
            return $this->deny($request, "role [{$user->roleName()}] is not an executive role");
        }

        try {
            $data = $this->overview->build([
                'date_from' => $this->date($request->query('date_from')),
                'date_to' => $this->date($request->query('date_to')),
                'as_of' => $this->date($request->query('as_of')),
                'branch' => null,
                'period' => 'monthly',
                'branch_period' => 'monthly',
                'branch_year' => (int) now()->format('Y'),
            ]);

            AuditLog::record(
                $request,
                'viewed',
                'section',
                'executive_overview',
                'Executive group overview opened'
            );

            // The financial half still honours the widget permission. An auditor
            // may open this view without being entitled to the money on it, and
            // masking here rather than in React means the figures never reach the
            // browser at all.
            $data['financial_summary'] = $user->can_(Permissions::WIDGET_EXECUTIVE_FINANCE)
                ? $data['financial_summary']
                : ['available' => false, 'masked' => true];

            return response()->json([
                'status' => 'success',
                'data' => PayloadMasker::apply('executive_overview', $data, $user->permissionList()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Executive overview failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to build the executive summary.',
            ], 500);
        }
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
