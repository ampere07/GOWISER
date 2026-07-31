<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportDispatch;
use App\Services\GoogleDriveService;
use App\Services\ReportDataset;
use App\Services\ReportDispatchService;
use App\Services\ReportPdfService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function index()
    {
        $reports = Report::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data'    => $reports,
        ]);
    }

    /**
     * Schedule metadata for the front-end form, so the UI never has to hard-code
     * which fields a given schedule requires.
     */
    public function options()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'report_types' => ReportDataset::reportTypes(),
                'schedules'    => array_map(fn ($schedule) => [
                    'value'    => $schedule,
                    'requires' => Report::SCHEDULE_REQUIREMENTS[$schedule],
                ], Report::schedules()),
                'weekdays' => Report::WEEKDAYS,
            ],
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validated = $this->validatePayload($request);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Please correct the highlighted fields.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $report = new Report($validated);
        $report->created_at = now();
        $report->save();

        // The report definition is saved at this point. PDF rendering and the
        // Drive upload are best-effort follow-ups: the previous version returned
        // HTTP 500 when either failed, so the UI showed "failed to create" for a
        // report that had in fact been created, and users created duplicates.
        $artifact = $this->buildArtifact($report);

        return response()->json([
            'success'  => true,
            'message'  => $artifact['ok']
                ? 'Report created and PDF generated successfully.'
                : 'Report created, but the PDF could not be generated: ' . $artifact['message'],
            'warning'  => $artifact['ok'] ? null : $artifact['message'],
            'data'     => $report->fresh(),
            'artifact' => $artifact,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $report = Report::find($id);
        if (!$report) {
            return response()->json(['success' => false, 'message' => 'Report not found.'], 404);
        }

        try {
            $validated = $this->validatePayload($request);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Please correct the highlighted fields.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $report->fill($validated)->save();

        return response()->json([
            'success' => true,
            'message' => 'Report updated successfully.',
            'data'    => $report->fresh(),
        ]);
    }

    public function destroy(int $id)
    {
        $report = Report::find($id);
        if (!$report) {
            return response()->json(['success' => false, 'message' => 'Report not found.'], 404);
        }

        $report->delete();

        return response()->json(['success' => true, 'message' => 'Report deleted.']);
    }

    /** Re-render the PDF for an existing report and refresh its Drive link. */
    public function regenerate(int $id)
    {
        $report = Report::find($id);
        if (!$report) {
            return response()->json(['success' => false, 'message' => 'Report not found.'], 404);
        }

        $artifact = $this->buildArtifact($report);

        return response()->json([
            'success'  => $artifact['ok'],
            'message'  => $artifact['ok']
                ? 'PDF regenerated successfully.'
                : 'PDF generation failed: ' . $artifact['message'],
            'data'     => $report->fresh(),
            'artifact' => $artifact,
        ], $artifact['ok'] ? 200 : 500);
    }

    /**
     * Generate and email the report immediately.
     *
     * Recorded as a 'manual' occurrence so it neither consumes nor collides with
     * the report's next scheduled send.
     */
    public function sendNow(int $id, ReportDispatchService $dispatcher)
    {
        $report = Report::find($id);
        if (!$report) {
            return response()->json(['success' => false, 'message' => 'Report not found.'], 404);
        }

        if ($report->recipients() === []) {
            return response()->json([
                'success' => false,
                'message' => 'This report has no valid recipient email address.',
            ], 422);
        }

        $result = $dispatcher->dispatch(
            $report,
            Carbon::now(config('reports.timezone', 'Asia/Manila')),
            'manual'
        );

        $ok = in_array($result['status'], ['queued', 'partial'], true);

        return response()->json([
            'success'   => $ok,
            'message'   => $result['message'],
            'queued'    => $result['queued'],
            'status'    => $result['status'],
            'data'      => $report->fresh(),
        ], $ok ? 200 : 500);
    }

    /** Recent dispatch history, for auditing scheduled delivery. */
    public function dispatches(Request $request, int $id)
    {
        if (!Report::whereKey($id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Report not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => ReportDispatch::where('report_id', $id)
                ->orderByDesc('id')
                ->limit(min(100, max(1, (int) $request->query('limit', 25))))
                ->get(),
        ]);
    }

    /** Stream the PDF inline without touching Drive — used for previewing. */
    public function preview(int $id)
    {
        $report = Report::find($id);
        if (!$report) {
            return response()->json(['success' => false, 'message' => 'Report not found.'], 404);
        }

        try {
            $path = (new ReportPdfService())->generate($report);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'PDF generation failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ])->deleteFileAfterSend(true);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Validation that mirrors the form's dynamic rules.
     *
     * `day`, `report_weekday` and `report_month` are required only when the
     * chosen schedule actually uses them — the old rules demanded a day-of-month
     * for every schedule, including "Every Day".
     */
    private function validatePayload(Request $request): array
    {
        $schedule = Report::normalizeSchedule($request->input('report_schedule'));

        $needsDay     = $schedule !== null && Report::requiresField($schedule, 'day');
        $needsWeekday = $schedule !== null && Report::requiresField($schedule, 'weekday');
        $needsMonth   = $schedule !== null && Report::requiresField($schedule, 'month');

        $validated = $request->validate([
            'report_name'     => ['required', 'string', 'max:255'],
            'report_type'     => ['required', 'string', 'max:100', Rule::in(ReportDataset::reportTypes())],
            'report_schedule' => ['required', 'string', 'max:100', Rule::in(Report::schedules())],
            'report_time'     => ['required', 'date_format:H:i,H:i:s'],
            'day'             => [$needsDay ? 'required' : 'nullable', 'integer', 'min:1', 'max:31'],
            'report_weekday'  => [$needsWeekday ? 'required' : 'nullable', 'string', Rule::in(Report::WEEKDAYS)],
            'report_month'    => [$needsMonth ? 'required' : 'nullable', 'integer', 'min:1', 'max:12'],
            'send_to'         => ['required', 'string', 'max:255'],
            'date_range'      => ['required', 'string', 'max:100'],
            'created_by'      => ['nullable', 'string', 'max:255'],
            'is_active'       => ['nullable', 'boolean'],
        ], [
            'day.required'            => 'Day of the month is required for this schedule.',
            'report_weekday.required' => 'Please choose which weekday this report should run on.',
            'report_month.required'   => 'Please choose which month this report should run in.',
            'report_type.in'          => 'That report type is not supported.',
            'report_schedule.in'      => 'That schedule is not supported.',
            'report_time.date_format' => 'Report time must be a valid time (HH:MM).',
        ]);

        // Recipients: at least one address must be usable.
        $probe = new Report(['send_to' => $validated['send_to']]);
        if ($probe->recipients() === []) {
            throw ValidationException::withMessages([
                'send_to' => ['Enter at least one valid email address.'],
            ]);
        }
        if ($invalid = $probe->invalidRecipients()) {
            throw ValidationException::withMessages([
                'send_to' => ['These addresses are not valid: ' . implode(', ', $invalid)],
            ]);
        }

        // Date range must parse into a real ordered range, or every generated
        // report silently falls back to "all time".
        [$from, $to] = ReportDataset::parseDateRange($validated['date_range']);
        if ($from === null || $to === null) {
            throw ValidationException::withMessages([
                'date_range' => ['Date range must look like "YYYY-MM-DD to YYYY-MM-DD".'],
            ]);
        }
        $validated['date_range'] = "{$from} to {$to}";

        // Null out fields the chosen schedule does not use, so a stale value left
        // over from another schedule cannot influence when the report fires.
        if (!$needsDay) {
            $validated['day'] = null;
        }
        if (!$needsWeekday) {
            $validated['report_weekday'] = null;
        }
        if (!$needsMonth) {
            $validated['report_month'] = null;
        }

        $validated['report_schedule'] = $schedule;
        $validated['report_time']     = Carbon::parse($validated['report_time'])->format('H:i:s');

        return $validated;
    }

    /**
     * Render the PDF and push it to Drive.
     *
     * Never throws: the caller has already persisted the report and reports the
     * outcome as a warning rather than a failure.
     *
     * @return array{ok: bool, message: string, file_url: ?string, bytes: ?int}
     */
    private function buildArtifact(Report $report): array
    {
        $tempPath = null;

        try {
            $tempPath = (new ReportPdfService())->generate($report);
            $bytes    = @filesize($tempPath) ?: null;

            $fileUrl = null;
            try {
                $drive    = resolve(GoogleDriveService::class);
                $folderId = $drive->findFolder('Reports') ?? $drive->createFolder('Reports');
                $fileUrl  = $drive->uploadFile($tempPath, $folderId, basename($tempPath), 'application/pdf');
            } catch (\Throwable $e) {
                Log::warning('Report PDF generated but the Drive upload failed', [
                    'report_id' => $report->id,
                    'error'     => $e->getMessage(),
                ]);

                return [
                    'ok'       => false,
                    'message'  => 'the PDF was generated but could not be uploaded to Google Drive ('
                                . $e->getMessage() . ')',
                    'file_url' => null,
                    'bytes'    => $bytes,
                ];
            }

            if ($fileUrl) {
                $report->forceFill(['file_url' => $fileUrl])->save();
            }

            return [
                'ok'       => true,
                'message'  => 'PDF generated and uploaded.',
                'file_url' => $fileUrl,
                'bytes'    => $bytes,
            ];
        } catch (\Throwable $e) {
            Log::error('Report PDF generation failed', [
                'report_id'   => $report->id,
                'report_type' => $report->report_type,
                'error'       => $e->getMessage(),
                'file'        => $e->getFile() . ':' . $e->getLine(),
            ]);

            return [
                'ok'       => false,
                'message'  => $e->getMessage(),
                'file_url' => null,
                'bytes'    => null,
            ];
        } finally {
            if ($tempPath && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
