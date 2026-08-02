<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Reads the audit trail.
 *
 * Read-only and paginated. There is deliberately no endpoint that edits or
 * deletes a row: a trail someone with a login can prune is not a trail, and the
 * one role most likely to want it gone — an administrator covering a mistaken
 * grant — is exactly the one it exists to hold accountable.
 *
 * Retention is a database concern, not an API one. If rows have to be aged out,
 * that belongs in a scheduled job with its own policy, not behind a button.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    /** Action prefixes offered as filters, so the UI does not invent its own list. */
    private const ACTIONS = [
        'viewed', 'exported', 'denied',
        'payable.paid', 'payable.unpaid',
        'user.created', 'user.updated', 'user.deleted', 'role.updated',
        'created', 'updated', 'deleted',
    ];

    public function index(Request $request)
    {
        $query = AuditLog::query()->orderByDesc('logged_at')->orderByDesc('id');

        $action = trim((string) $request->query('action'));

        if ($action !== '' && in_array($action, self::ACTIONS, true)) {
            $query->where('action', $action);
        }

        $actor = trim((string) $request->query('actor'));

        if ($actor !== '') {
            $query->where('actor', 'like', '%' . $actor . '%');
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $date = $request->query($key);

            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $query->whereDate('logged_at', $operator, $date);
            }
        }

        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();

        $rows = $query
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn (AuditLog $row) => [
                'id' => (int) $row->id,
                'actor' => $row->actor ?? 'system',
                'action' => $row->action,
                'subject_type' => $row->subject_type ? class_basename($row->subject_type) : null,
                'subject_id' => $row->subject_id,
                'description' => $row->description,
                'changes' => $row->changes,
                'ip_address' => $row->ip_address,
                'logged_at' => $row->logged_at?->toDateTimeString(),
            ])
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total_pages' => max(1, (int) ceil($total / self::PER_PAGE)),
                'actions' => self::ACTIONS,
            ],
        ]);
    }
}
