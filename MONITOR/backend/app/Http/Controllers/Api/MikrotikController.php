<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MikrotikKick;
use App\Services\MikrotikRadiusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * MikroTik RADIUS: User Manager, from the monitoring portal.
 *
 * This is the only controller in MONITOR that changes something outside MONITOR.
 * Everything else here reads branch databases and writes, at most, to its own
 * settings tables. That difference shapes the whole file:
 *
 *   - the module is restricted to executive roles on top of its permission, the
 *     same second gate the Group Overview carries — see the route file;
 *   - reads need the module permission; every write needs a second, separate
 *     grant, so "can look at the RADIUS groups" is not the same as "can cut a
 *     thousand people off";
 *   - every write is audited before it is attempted and again with its result,
 *     because the audit trail is the only record that survives a router being
 *     replaced;
 *   - rate limits are parsed and normalised rather than forwarded. RouterOS
 *     accepts a great deal of syntax, and a value that is merely *accepted* can
 *     still throttle a region to dial-up — see
 *     MikrotikRadiusService::parseRateLimit.
 *
 * Reads report which servers answered and which did not, rather than presenting
 * a partial fleet as the whole one.
 *
 * The arithmetic and the router calls live in MikrotikRadiusService; this file
 * is validation, HTTP shapes and error translation. A bad rate limit is a 422
 * with the message the operator needs, not a 500.
 */
class MikrotikController extends Controller
{
    public function __construct(private MikrotikRadiusService $radius)
    {
    }

    /** Everything the tabbed screen needs in one round trip. */
    public function index(Request $request)
    {
        if (!$this->radius->configured()) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'configured' => false,
                    'message' => 'No RADIUS server is configured. Add one under Settings → RADIUS API.',
                    'servers' => [],
                    'groups' => $this->emptyBlock(),
                    'profiles' => $this->emptyBlock(),
                    'limitations' => $this->emptyBlock(),
                    'attributes' => $this->emptyBlock(),
                    'sessions' => $this->emptyBlock(),
                    'sessions_by_group' => [],
                    'queued' => [],
                ],
            ]);
        }

        try {
            $data = $this->radius->overview();

            AuditLog::record(
                $request,
                'viewed',
                'section',
                'mikrotik-radius',
                'MikroTik RADIUS opened'
            );

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Throwable $e) {
            return $this->fail($e, 'Unable to read the RADIUS servers.');
        }
    }

    /**
     * Users, searched and paged separately — the list runs to thousands.
     *
     * Each row carries its live session state and the caller ID actually
     * connected, which is what makes this a support tool rather than a list of
     * names. See MikrotikRadiusService::users for why that costs one request
     * rather than one per row.
     */
    public function users(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->radius->users(
                    trim((string) ($validated['search'] ?? '')),
                    (int) ($validated['limit'] ?? 250)
                ),
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'Unable to read the user list.');
        }
    }

    /**
     * Reads a rate limit without applying it.
     *
     * The screen calls this as the operator types, so "250mb" can be shown
     * resolving to "250M/250M" before anything is saved. Worth an endpoint of
     * its own rather than a copy of the parser in TypeScript: two
     * implementations of this conversion would eventually disagree, and the one
     * that disagreed silently would be the one that set the speed.
     *
     * Read-only, so it sits behind the module permission rather than the write
     * grant — someone allowed to look at the groups may work out what a value
     * means without being allowed to set it.
     */
    public function previewRateLimit(Request $request)
    {
        $validated = $request->validate([
            'rate_limit' => ['required', 'string', 'max:64'],
        ]);

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->radius->parseRateLimit($validated['rate_limit']),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e, 'rate_limit');
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
     * whether to disconnect them.
     */
    public function updateGroup(Request $request, string $group)
    {
        $validated = $request->validate([
            // Deliberately permissive here and strict in the service. The
            // parser understands "250mb", "250 Mbps" and "250M/50M" and produces
            // a message naming the fix for anything else; a regex at this layer
            // could only reject with "the format is invalid".
            'rate_limit' => ['nullable', 'string', 'max:64'],
            // Pool names are RouterOS identifiers — "pool-nat444" and the like.
            'framed_pool' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->radius->updateGroup($request, $group, $validated),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e, 'rate_limit');
        } catch (Throwable $e) {
            return $this->fail($e, 'The change could not be applied.');
        }
    }

    /**
     * Moves one user into another group.
     *
     * Behind the same grant as a rate-limit change, and for the same reason:
     * moving a subscriber from PLAN-A to Restricted is a change to what they are
     * paying for.
     */
    public function moveUser(Request $request, string $username)
    {
        $validated = $request->validate([
            'group' => ['required', 'string', 'max:64'],
        ]);

        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->radius->moveUser($request, $username, $validated['group']),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e, 'group');
        } catch (Throwable $e) {
            return $this->fail($e, 'The user could not be moved.');
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
        $target = $this->validateTarget($request);

        try {
            $result = $this->radius->kickNow($request, $target['usernames'], $target['group']);

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => $result['failed'] > 0
                    ? "Disconnected {$result['killed']} session(s); {$result['failed']} could not be reached."
                    : "Disconnected {$result['killed']} session(s).",
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'The sessions could not be terminated.');
        }
    }

    /**
     * Schedules the same termination for a wall-clock time in GMT+8.
     *
     * The time is read in Asia/Manila whatever the server runs in — see
     * MikrotikRadiusService::schedule — and the response echoes back the instant
     * it resolved to, in that zone, so the operator can check it before walking
     * away from a screen that will disconnect people while they are not looking.
     */
    public function scheduleKick(Request $request)
    {
        $target = $this->validateTarget($request);

        $validated = $request->validate([
            // Accepted as text rather than a date rule so the service owns the
            // timezone. A `date_format` here would validate against the server's
            // clock, which is the exact confusion this feature exists to remove.
            'at' => ['required', 'string', 'max:32'],
        ]);

        try {
            $kick = $this->radius->schedule(
                $request,
                $target['usernames'],
                $target['group'],
                $validated['at'],
                $request->input('reason')
            );

            $when = $kick->scheduled_for?->copy()->setTimezone(MikrotikRadiusService::TIMEZONE);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $kick->id,
                    'target' => $kick->targetLabel(),
                    'scheduled_for' => $when?->toDateTimeString(),
                    'timezone' => MikrotikRadiusService::TIMEZONE,
                ],
                'message' => sprintf(
                    'Re-authorisation scheduled for %s (GMT+8). It can be cancelled until it runs.',
                    $when?->format('D j M Y, H:i') ?? 'the chosen time'
                ),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e, 'at');
        } catch (Throwable $e) {
            return $this->fail($e, 'The re-authorisation could not be scheduled.');
        }
    }

    /** Queues the same termination for the next maintenance window. */
    public function kickLater(Request $request)
    {
        $target = $this->validateTarget($request);

        try {
            $kick = $this->radius->scheduleForWindow(
                $request,
                $target['usernames'],
                $target['group'],
                $request->input('reason')
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $kick->id,
                    'target' => $kick->targetLabel(),
                    'scheduled_for' => $kick->scheduled_for?->toDateTimeString(),
                ],
                'message' => "Queued for {$kick->scheduled_for?->format('D j M, H:i')}.",
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'The kick could not be queued.');
        }
    }

    /** Cancels a queued kick that has not fired yet. */
    public function cancelKick(Request $request, MikrotikKick $kick)
    {
        // Conditional on the row still being pending, inside the service, so two
        // operators cancelling at once cannot both be told they succeeded.
        if (!$this->radius->cancel($request, $kick)) {
            return response()->json([
                'status' => 'error',
                'message' => "This kick is already {$kick->fresh()?->status}.",
            ], 422);
        }

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

    /** A block shaped like a real one, for the unconfigured response. */
    private function emptyBlock(): array
    {
        return ['reachable' => false, 'errors' => [], 'rows' => []];
    }

    /**
     * A value the operator can fix, reported as one.
     *
     * 422 with the field named, so the form highlights the input rather than the
     * page showing a red banner about a server error. The parser's messages are
     * written for the person typing and are passed through verbatim — including
     * in production, unlike fail() below, because none of them reveal anything
     * about the infrastructure.
     */
    private function invalid(InvalidArgumentException $e, string $field)
    {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'errors' => [$field => [$e->getMessage()]],
        ], 422);
    }

    private function fail(Throwable $e, string $message)
    {
        Log::error('MikroTik RADIUS failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'status' => 'error',
            // The router's own error text can name hosts and credentials, so it
            // is only returned with debug on.
            'message' => config('app.debug') ? $e->getMessage() : $message,
        ], 500);
    }
}
