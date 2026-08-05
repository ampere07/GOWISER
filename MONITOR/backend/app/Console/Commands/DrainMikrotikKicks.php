<?php

namespace App\Console\Commands;

use App\Models\MikrotikKick;
use App\Services\Mikrotik\UserManagerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Performs the session kicks that "Kick Sessions Later" queued.
 *
 * The queue exists so an operator can change a group's rate limit during the
 * working day without disconnecting everyone on it mid-call. The new limit is
 * live immediately for anyone connecting fresh; this command is what eventually
 * moves the people who were already online.
 *
 * Scheduled every fifteen minutes. It exits immediately unless the clock is
 * inside the configured maintenance window, so the schedule can be frequent
 * without the command being eager — see MikrotikKick::inWindow, which also
 * handles a window that crosses midnight.
 *
 *     php artisan mikrotik:drain-kicks           # runs only inside the window
 *     php artisan mikrotik:drain-kicks --force   # runs now, whatever the time
 *
 * --force exists because a maintenance window that has been missed for three
 * days should be drainable by hand, and because the alternative — an operator
 * disabling the window in config to get one job done and forgetting to put it
 * back — is worse.
 */
class DrainMikrotikKicks extends Command
{
    protected $signature = 'mikrotik:drain-kicks
        {--force : Run outside the maintenance window.}
        {--limit=50 : Queue entries to process in one run.}';

    protected $description = 'Terminate RADIUS sessions queued for the maintenance window';

    public function handle(UserManagerClient $client): int
    {
        if (!$client->configured()) {
            $this->warn('No RADIUS server is configured — nothing to drain.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !MikrotikKick::inWindow()) {
            // Not a failure. This runs on a frequent schedule and is *expected*
            // to do nothing most of the time; exiting non-zero would fill the
            // cron log with alarms about correct behaviour.
            $this->line('Outside the maintenance window — nothing done.');

            return self::SUCCESS;
        }

        $due = MikrotikKick::query()
            ->where('status', MikrotikKick::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($due->isEmpty()) {
            $this->info('Queue is empty.');

            return self::SUCCESS;
        }

        $this->info("Draining {$due->count()} queued kick(s)…");

        $failures = 0;

        foreach ($due as $kick) {
            // Claimed before the network call, not after. Two overlapping runs —
            // a slow one still going when the schedule fires again — would
            // otherwise both pick up the same row and disconnect the same people
            // twice. The status update is the lock.
            $claimed = MikrotikKick::query()
                ->whereKey($kick->getKey())
                ->where('status', MikrotikKick::STATUS_PENDING)
                ->update(['status' => 'running']);

            if ($claimed === 0) {
                continue;
            }

            try {
                $result = $client->kick(
                    (array) ($kick->target_usernames ?? []),
                    $kick->target_group
                );

                $kick->update([
                    // A kick that could not reach any server is a failure, not a
                    // success that happened to disconnect nobody — otherwise the
                    // instruction is silently discarded during an outage.
                    'status' => $result['failed'] > 0 || ($result['attempted'] === 0 && $result['errors'] !== [])
                        ? MikrotikKick::STATUS_FAILED
                        : MikrotikKick::STATUS_DONE,
                    'executed_at' => now(),
                    'sessions_killed' => $result['killed'],
                    'sessions_failed' => $result['failed'],
                    'result_note' => $result['errors'] === []
                        ? null
                        : implode(' | ', array_slice($result['errors'], 0, 5)),
                ]);

                $this->line(sprintf(
                    '  #%d %s — killed %d, failed %d',
                    $kick->id,
                    $kick->targetLabel(),
                    $result['killed'],
                    $result['failed']
                ));

                Log::warning('Queued Mikrotik kick executed', [
                    'kick_id' => $kick->id,
                    'target' => $kick->targetLabel(),
                    'requested_by' => $kick->requested_by_name,
                    'result' => $result,
                ]);

                if ($result['failed'] > 0) {
                    $failures++;
                }
            } catch (Throwable $e) {
                $failures++;

                $kick->update([
                    'status' => MikrotikKick::STATUS_FAILED,
                    'executed_at' => now(),
                    'result_note' => $e->getMessage(),
                ]);

                $this->error("  #{$kick->id}: {$e->getMessage()}");

                Log::error('Queued Mikrotik kick failed', [
                    'kick_id' => $kick->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
