<?php

namespace App\Jobs;

use App\Models\MikrotikKick;
use App\Services\Mikrotik\UserManagerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Performs one scheduled RADIUS re-authorisation.
 *
 * An operator changed a group's rate limit, chose a time in Asia/Manila rather
 * than "now", and this is what eventually disconnects the sessions still running
 * on the old settings so they come back on the new ones.
 *
 * ── Idempotency, which is the whole design ────────────────────────────
 *
 * This job disconnects paying customers. Running it twice is not a duplicate
 * database row, it is a second outage — so "at most once" matters more here than
 * anywhere else in MONITOR, and the guarantee cannot rest on the queue driver
 * delivering exactly once, because no queue driver does.
 *
 * The guard is a claim: a single conditional UPDATE moves the row from `pending`
 * to `running`, and only the caller whose UPDATE affected a row proceeds. Every
 * other path — a retry, an overlapping scheduler tick, the maintenance-window
 * drain sweeping up a stranded row, a second worker picking up a duplicated
 * message — finds zero rows affected and returns without touching the router.
 * The database does the mutual exclusion, so no two processes need to agree on
 * anything.
 *
 * The same claim is what makes a *cancelled* kick genuinely cancelled: cancel()
 * moves the row out of `pending`, and this job then declines to run it even if
 * the message was already in flight.
 *
 * ── Why it does not retry ─────────────────────────────────────────────
 *
 * `$tries = 1`. A retry would have to re-claim a row this attempt already moved
 * to `running`, which it cannot — so a retry could only ever be a no-op that
 * looks like a success. Failures are recorded on the row and left for a human,
 * because "the router did not answer when we tried to cut four hundred people
 * off" is a decision, not something to quietly try again.
 */
class ScheduleRadiusSessionKickJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** See the class note: a retry can only ever be a no-op. */
    public int $tries = 1;

    /** Long enough for a large group across two servers, short enough to notice. */
    public int $timeout = 600;

    /**
     * The queue row's id rather than the model.
     *
     * A serialised model would carry a snapshot of the row as it was when the
     * job was dispatched, and the one thing this job must read fresh is the
     * status — a kick cancelled after dispatch has to be seen as cancelled.
     */
    public function __construct(public int $kickId)
    {
    }

    public function handle(UserManagerClient $client): void
    {
        $kick = MikrotikKick::find($this->kickId);

        if ($kick === null) {
            // Deleted between scheduling and firing. Nothing to disconnect and
            // nothing to record — the row that would have recorded it is gone.
            return;
        }

        // The claim. One statement, and its return value is the lock: exactly
        // one caller can move this row out of `pending`.
        $claimed = MikrotikKick::query()
            ->whereKey($kick->getKey())
            ->where('status', MikrotikKick::STATUS_PENDING)
            ->update(['status' => MikrotikKick::STATUS_RUNNING]);

        if ($claimed === 0) {
            Log::info('Scheduled RADIUS kick skipped — already claimed or cancelled', [
                'kick_id' => $this->kickId,
                'status' => $kick->status,
            ]);

            return;
        }

        if (!$client->configured()) {
            $this->record($kick, MikrotikKick::STATUS_FAILED, [
                'killed' => 0,
                'failed' => 0,
                'errors' => ['No RADIUS server is configured.'],
            ]);

            return;
        }

        try {
            $result = $client->kick(
                (array) ($kick->target_usernames ?? []),
                $kick->target_group
            );

            // A kick that reached no server is a failure, not a success that
            // happened to disconnect nobody. Recording it as done would silently
            // discard the instruction during an outage — and the operator would
            // believe the new rate limit had been applied.
            $stranded = $result['attempted'] === 0 && $result['errors'] !== [];

            $this->record(
                $kick,
                $result['failed'] > 0 || $stranded
                    ? MikrotikKick::STATUS_FAILED
                    : MikrotikKick::STATUS_DONE,
                $result
            );

            Log::warning('Scheduled RADIUS re-authorisation executed', [
                'kick_id' => $kick->id,
                'target' => $kick->targetLabel(),
                'requested_by' => $kick->requested_by_name,
                'scheduled_for' => $kick->scheduled_for?->toDateTimeString(),
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            $this->record($kick, MikrotikKick::STATUS_FAILED, [
                'killed' => 0,
                'failed' => 0,
                'errors' => [$e->getMessage()],
            ]);

            Log::error('Scheduled RADIUS re-authorisation failed: ' . $e->getMessage(), [
                'kick_id' => $kick->id,
                'target' => $kick->targetLabel(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Deliberately not re-thrown. The outcome is already recorded on the
            // row where an operator will look for it; letting it bubble would
            // mark the job failed as well and invite a retry that cannot run.
        }
    }

    /**
     * Writes the outcome back.
     *
     * In a transaction with nothing else, which looks redundant and is not: this
     * runs after a network call of unbounded duration, and the wrapper is what
     * guarantees the row is never left half-updated — a `running` status with an
     * `executed_at` and no counts is unreadable to whoever finds it.
     *
     * @param array{killed:int,failed:int,errors:array<int,string>} $result
     */
    private function record(MikrotikKick $kick, string $status, array $result): void
    {
        DB::transaction(function () use ($kick, $status, $result) {
            $kick->forceFill([
                'status' => $status,
                'executed_at' => now(),
                'sessions_killed' => $result['killed'] ?? 0,
                'sessions_failed' => $result['failed'] ?? 0,
                'result_note' => ($result['errors'] ?? []) === []
                    ? null
                    // Capped: a fleet-wide failure produces one error per
                    // session, and a text column full of the same message is
                    // less use than five of them and a count.
                    : implode(' | ', array_slice($result['errors'], 0, 5)),
            ])->save();
        });
    }

    /**
     * Called when the queue gives up on the job itself.
     *
     * The row would otherwise sit at `running` forever, which reads as "still
     * working" to both the screen and the drain command's claim — so it would
     * never be retried and never be reported.
     */
    public function failed(Throwable $e): void
    {
        MikrotikKick::query()
            ->whereKey($this->kickId)
            ->where('status', MikrotikKick::STATUS_RUNNING)
            ->update([
                'status' => MikrotikKick::STATUS_FAILED,
                'executed_at' => now(),
                'result_note' => 'The job did not complete: ' . $e->getMessage(),
            ]);

        Log::error('ScheduleRadiusSessionKickJob failed outright', [
            'kick_id' => $this->kickId,
            'error' => $e->getMessage(),
        ]);
    }
}
