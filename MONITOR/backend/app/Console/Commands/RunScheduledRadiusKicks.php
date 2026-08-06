<?php

namespace App\Console\Commands;

use App\Jobs\ScheduleRadiusSessionKickJob;
use App\Models\MikrotikKick;
use App\Services\MikrotikRadiusService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fires the re-authorisations an operator scheduled for a named time.
 *
 * The counterpart to `mikrotik:drain-kicks`, which handles the other kind: kicks
 * queued for "the next maintenance window" rather than for 2pm on Thursday. The
 * two are separate commands because they answer to different clocks and a single
 * one would have to hold a named time until the window, which is exactly what
 * naming a time asks it not to do.
 *
 * ── Why the scheduler drives this rather than a delayed job ───────────
 *
 * The obvious implementation is `dispatch($job)->delay($when)` at the moment the
 * operator presses Schedule. It is also a live hazard: MONITOR ships with
 * QUEUE_CONNECTION=sync by default, and on the sync driver a delayed dispatch
 * runs *immediately*. Scheduling a disconnection for next Tuesday would cut
 * everyone off while the operator was still looking at the form.
 *
 * Driving it from the scheduler removes the question. This command finds what is
 * due and dispatches it; on `sync` that runs it inline, on a real queue it hands
 * it to a worker, and the behaviour is correct either way with nothing to
 * configure.
 *
 * Running every minute is cheap — the query is one indexed lookup against
 * (mode, status, scheduled_for) and returns nothing almost every time.
 *
 *     php artisan mikrotik:run-scheduled
 *     php artisan mikrotik:run-scheduled --limit=10
 */
class RunScheduledRadiusKicks extends Command
{
    protected $signature = 'mikrotik:run-scheduled
        {--limit=25 : Scheduled kicks to dispatch in one run.}';

    protected $description = 'Perform RADIUS re-authorisations scheduled for a named time (GMT+8)';

    public function handle(): int
    {
        $due = MikrotikKick::query()
            ->where('mode', MikrotikKick::MODE_AT)
            ->where('status', MikrotikKick::STATUS_PENDING)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $manila = Carbon::now(MikrotikRadiusService::TIMEZONE)->format('H:i');

        $this->info("Dispatching {$due->count()} scheduled re-authorisation(s) — {$manila} Manila time.");

        foreach ($due as $id) {
            // No claim here. The job claims the row itself, in one conditional
            // statement, which is the only place that guarantee can live: this
            // command may overlap with itself, with the drain command, or with a
            // worker replaying a message, and all four paths converge on the
            // same UPDATE. Claiming here as well would add a second lock that
            // could disagree with the first.
            ScheduleRadiusSessionKickJob::dispatch((int) $id);

            $this->line("  queued kick #{$id}");
        }

        return self::SUCCESS;
    }
}
