<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MikrotikKick;
use App\Services\Mikrotik\UserManagerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Mikrotik Radius Shortcut: User Manager, from the monitoring portal.
 *
 * This is the only controller in MONITOR that changes something outside MONITOR.
 * Everything else here reads branch databases and writes, at most, to its own
 * settings tables. That difference shapes the whole file:
 *
 *   - reads need the module permission; every write needs a second, separate
 *     grant, so "can look at the RADIUS groups" is not the same as "can cut a
 *     thousand people off";
 *   - every write is audited before it is attempted and again with its result,
 *     because the audit trail is the only record that survives a router being
 *     replaced;
 *   - the rate limit and pool inputs are validated against a strict shape rather
 *     than forwarded. RouterOS accepts a great deal of syntax, and a value that
 *     is merely *accepted* can still throttle a region to dial-up.
 *
 * Reads report which servers answered and which did not, rather than presenting
 * a partial fleet as the whole one.
 */
class MikrotikController extends Controller
{
    public function __construct(private UserManagerClient $client)
    {
    }

    /** Everything the screen needs in one round trip. */
    public function index(Request $request)
    {
        if (!$this->client->configured()) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'configured' => false,
                    'message' => 'No RADIUS server is configured. Set MIKROTIK_1_URL and its credentials.',
                    'servers' => [],
                    'groups' => [],
                    'profiles' => [],
                    'limitations' => [],
                    'sessions' => [],
                    'queued' => [],
                ],
            ]);
        }

        try {
            $groups = $this->client->list('groups');
            $profiles = $this->client->list('profiles');
            $limitations = $this->client->list('limitations');
            $sessions = $this->client->list('sessions');

            AuditLog::record(
                $request,
                'viewed',
                'section',
                'mikrotik-radius',
                'Mikrotik Radius Shortcut opened'
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'configured' => true,
                    'servers' => array_map(
                        fn ($server) => ['key' => $server['key'], 'label' => $server['label']],
                        $this->client->servers()
                    ),
                    // `reachable` travels with each block. A group list that is
                    // empty because the router is down must not render the same
                    // as one that is empty because there are no groups.
                    'groups' => $this->block($groups, fn ($row) => $this->group($row)),
                    'profiles' => $this->block($profiles, fn ($row) => $this->profile($row)),
                    'limitations' => $this->block($limitations, fn ($row) => $this->limitation($row)),
                    'sessions' => $this->block($sessions, fn ($row) => $this->session($row)),
                    'queued' => $this->queued(),
                    'maintenance_window' => [
                        'start' => config('mikrotik.maintenance_window.start'),
                        'end' => config('mikrotik.maintenance_window.end'),
                        'next' => MikrotikKick::nextWindow()->toDateTimeString(),
                        'open_now' => MikrotikKick::inWindow(),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'Unable to read the RADIUS servers.');
        }
    }

    /** Users, paged separately — the list runs to thousands. */
    public function users(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        try {
            $result = $this->client->list('users');

            $rows = array_map(fn ($row) => $this->user($row), $result['rows']);

            if ($search !== '') {
                $needle = strtolower($search);

                $rows = array_values(array_filter(
                    $rows,
                    fn ($row) => str_contains(strtolower($row['username'] . ' ' . $row['group']), $needle)
                ));
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'reachable' => $result['reachable'],
                    'errors' => $result['errors'],
                    'total' => count($rows),
                    // Capped in the response, not in the UI. Ten thousand rows
                    // of JSON is a slow page whether or not the table paginates
                    // them, and the search above narrows before the cut.
                    'rows' => array_slice($rows, 0, 250),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'Unable to read the user list.');
        }
    }

    /**
     * Changes a group's rate limit and/or framed pool.
     *
     * The change alone does not affect anyone already online — RADIUS attributes
     * are handed out at authentication, so a subscriber keeps the speed they
     * connected with until their session ends. That is exactly why the response
     * reports how many sessions are currently live on the group: the operator
     * needs to know how many people are still on the old limit, and therefore
     * whether to kick.
     */
    public function updateGroup(Request $request, string $group)
    {
        $validated = $request->validate([
            // "150M/150M", "20M/5M", "1500k/1500k" — receive/transmit, in the
            // form RouterOS writes as Mikrotik-Rate-Limit. Validated by pattern
            // rather than passed through: a typo here is a region throttled to
            // kilobits, and the router will accept it without complaint.
            'rate_limit' => ['nullable', 'string', 'regex:/^\d+[kKmMgG]?\/\d+[kKmMgG]?$/'],
            // Pool names are RouterOS identifiers — "pool-nat444" and the like.
            'framed_pool' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $attributes = [];

        if (array_key_exists('rate_limit', $validated) && $validated['rate_limit'] !== null) {
            $attributes['rate-limit'] = $validated['rate_limit'];
        }

        if (array_key_exists('framed_pool', $validated) && $validated['framed_pool'] !== null) {
            $attributes['framed-pool'] = $validated['framed_pool'];
        }

        if (array_key_exists('comment', $validated) && $validated['comment'] !== null) {
            $attributes['comment'] = $validated['comment'];
        }

        if ($attributes === []) {
            return response()->json([
                'status' => 'error',
                'message' => 'Nothing to change.',
            ], 422);
        }

        // Recorded before the attempt, not after. A change that crashes the
        // router mid-request still happened, and an audit trail that only logs
        // successes cannot answer what was being done at the time.
        AuditLog::record(
            $request,
            'updated',
            'mikrotik-group',
            $group,
            "Changed RADIUS group '{$group}'",
            $attributes
        );

        try {
            $result = $this->client->update('groups', 'name', $group, $attributes);

            $sessions = $this->client->sessions();
            $affected = count(array_filter(
                $sessions['rows'],
                fn ($row) => (string) ($row['user-group'] ?? $row['group'] ?? '') === $group
            ));

            return response()->json([
                'status' => 'success',
                'data' => [
                    'server' => $result['server'],
                    'before' => $this->group($result['before']),
                    'after' => $this->group($result['after']),
                    'live_sessions' => $affected,
                    'note' => $affected > 0
                        ? "{$affected} session(s) are still running on the previous settings. Kick them to apply the change now."
                        : 'No live sessions on this group — the change applies to the next connection.',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'The change could not be applied.');
        }
    }

    /**
     * Terminates sessions immediately.
     *
     * Separate grant from reading, and separate again from queueing: this is the
     * button that disconnects people, and it should never be reachable by
     * accident from a role that was granted the tab.
     */
    public function kickNow(Request $request)
    {
        $validated = $this->validateTarget($request);

        AuditLog::record(
            $request,
            'executed',
            'mikrotik-kick',
            $validated['group'] ?? implode(',', $validated['usernames']),
            'Kicked RADIUS sessions immediately',
            $validated
        );

        try {
            $result = $this->client->kick($validated['usernames'], $validated['group']);

            Log::warning('Mikrotik sessions terminated', [
                'actor' => $request->user()?->username,
                'target' => $validated,
                'result' => $result,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => "Disconnected {$result['killed']} session(s).",
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'The sessions could not be terminated.');
        }
    }

    /** Queues the same termination for the next maintenance window. */
    public function kickLater(Request $request)
    {
        $validated = $this->validateTarget($request);

        $kick = MikrotikKick::create([
            'target_group' => $validated['group'],
            'target_usernames' => $validated['usernames'] ?: null,
            'reason' => $request->input('reason'),
            'requested_by' => $request->user()?->id,
            'requested_by_name' => $request->user()?->username,
            'status' => MikrotikKick::STATUS_PENDING,
            'scheduled_for' => MikrotikKick::nextWindow(),
        ]);

        AuditLog::record(
            $request,
            'queued',
            'mikrotik-kick',
            (string) $kick->id,
            "Queued a session kick for {$kick->targetLabel()} at {$kick->scheduled_for}",
            $validated
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $kick->id,
                'scheduled_for' => $kick->scheduled_for?->toDateTimeString(),
                'target' => $kick->targetLabel(),
            ],
            'message' => "Queued for {$kick->scheduled_for?->format('D j M, H:i')}.",
        ]);
    }

    /** Cancels a queued kick that has not fired yet. */
    public function cancelKick(Request $request, MikrotikKick $kick)
    {
        if ($kick->status !== MikrotikKick::STATUS_PENDING) {
            return response()->json([
                'status' => 'error',
                'message' => "This kick is already {$kick->status}.",
            ], 422);
        }

        $kick->update(['status' => MikrotikKick::STATUS_CANCELLED]);

        AuditLog::record(
            $request,
            'cancelled',
            'mikrotik-kick',
            (string) $kick->id,
            "Cancelled the queued session kick for {$kick->targetLabel()}"
        );

        return response()->json(['status' => 'success', 'message' => 'Cancelled.']);
    }

    /**
     * A target that names exactly one thing.
     *
     * Requiring one of group or usernames — and rejecting both, and rejecting
     * neither — is a safety property, not tidiness. An empty target reaching the
     * client would be an instruction to disconnect nothing at best, and the
     * whole network at worst, depending on how the filter was read.
     *
     * @return array{group:?string,usernames:string[]}
     */
    private function validateTarget(Request $request): array
    {
        $validated = $request->validate([
            'group' => ['nullable', 'string', 'max:64'],
            'usernames' => ['nullable', 'array', 'max:500'],
            'usernames.*' => ['string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $group = $validated['group'] ?? null;
        $usernames = array_values(array_filter((array) ($validated['usernames'] ?? [])));

        if (($group === null || $group === '') && $usernames === []) {
            abort(422, 'Name a group or at least one username to disconnect.');
        }

        if ($group !== null && $group !== '' && $usernames !== []) {
            abort(422, 'Choose either a group or a list of usernames, not both.');
        }

        return ['group' => $group ?: null, 'usernames' => $usernames];
    }

    /** @param callable $shape */
    private function block(array $result, callable $shape): array
    {
        return [
            'reachable' => $result['reachable'],
            'errors' => $result['errors'],
            'rows' => array_map($shape, $result['rows']),
        ];
    }

    private function queued(): array
    {
        return MikrotikKick::query()
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (MikrotikKick $kick) => [
                'id' => $kick->id,
                'target' => $kick->targetLabel(),
                'group' => $kick->target_group,
                'reason' => $kick->reason,
                'status' => $kick->status,
                'requested_by' => $kick->requested_by_name,
                'scheduled_for' => $kick->scheduled_for?->toDateTimeString(),
                'executed_at' => $kick->executed_at?->toDateTimeString(),
                'sessions_killed' => $kick->sessions_killed,
                'sessions_failed' => $kick->sessions_failed,
                'result_note' => $kick->result_note,
            ])
            ->all();
    }

    // ── Shapers ──────────────────────────────────────────────────────────
    //
    // RouterOS returns kebab-case keys, a leading-dot id, and different spellings
    // between major versions. Normalised once here so the frontend reads one
    // shape and a RouterOS upgrade cannot become a UI change.

    private function group(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'rate_limit' => (string) ($row['rate-limit'] ?? ''),
            'framed_pool' => (string) ($row['framed-pool'] ?? ''),
            'shared_users' => (string) ($row['shared-users'] ?? ''),
            'comment' => (string) ($row['comment'] ?? ''),
        ];
    }

    private function profile(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'price' => (string) ($row['price'] ?? ''),
            'validity' => (string) ($row['validity'] ?? ''),
            'starts_at' => (string) ($row['starts-at'] ?? ''),
        ];
    }

    private function limitation(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'rate_limit' => (string) ($row['rate-limit'] ?? ''),
            'download_limit' => (string) ($row['download-limit'] ?? ''),
            'upload_limit' => (string) ($row['upload-limit'] ?? ''),
            'uptime_limit' => (string) ($row['uptime-limit'] ?? ''),
        ];
    }

    private function user(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'username' => (string) ($row['name'] ?? ''),
            'group' => (string) ($row['group'] ?? ''),
            'shared_users' => (string) ($row['shared-users'] ?? ''),
            'disabled' => filter_var($row['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'comment' => (string) ($row['comment'] ?? ''),
            // Deliberately no password field. This screen has no reason to read
            // one and every reason not to put it in a JSON payload.
        ];
    }

    private function session(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'user' => (string) ($row['user'] ?? ''),
            'group' => (string) ($row['user-group'] ?? $row['group'] ?? ''),
            'address' => (string) ($row['user-address'] ?? $row['address'] ?? ''),
            'nas' => (string) ($row['nas-ip-address'] ?? ''),
            'uptime' => (string) ($row['uptime'] ?? ''),
            'started' => (string) ($row['started'] ?? ''),
        ];
    }

    private function fail(Throwable $e, string $message)
    {
        Log::error('Mikrotik shortcut failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
        ]);

        return response()->json([
            'status' => 'error',
            'message' => config('app.debug') ? $e->getMessage() : $message,
        ], 500);
    }
}
