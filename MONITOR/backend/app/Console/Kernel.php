<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * Only cache warming. MONITOR still writes nothing to the monitored systems —
     * the jobs that generate billing, sync RADIUS and send notices belong to
     * GOWISER and must run in exactly one place. This one only reads them and
     * stores the answers in MONITOR's own cache.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        /*
         * Pre-computes the sections so the first viewer does not pay for a
         * fan-out across every database.
         *
         * The interval has to be shorter than monitor.cache_ttl or this always
         * warms an entry that has already expired, which is all cost and no
         * benefit. The default TTL is 60s, so this runs every minute; raise the
         * TTL if a minute of fan-out per section is too much load, and lengthen
         * this to match.
         *
         * withoutOverlapping matters more than usual here: a pass that takes
         * longer than the interval would otherwise stack runs and multiply the
         * query load on the very databases it is trying to spare.
         */
        $schedule->command('reporting:warm --quiet-log')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground()
            // A warmed cache is an optimisation; its output is not worth a mail
            // or a log line every minute. Failures still reach the log via the
            // command itself.
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::warning('Scheduled reporting:warm failed.');
            });

        /*
         * Performs the session kicks that were queued for the maintenance
         * window.
         *
         * Frequent but not eager: the command exits immediately outside the
         * configured window (see MikrotikKick::inWindow), so this schedule costs
         * nothing for most of the day and needs no second place where the window
         * is defined.
         *
         * withoutOverlapping because the work is disconnecting real sessions. Two
         * concurrent runs claiming the same queue row would kick the same
         * subscribers twice — the command claims each row before acting for the
         * same reason, and the two guards are cheap next to that outcome.
         */
        $schedule->command('mikrotik:drain-kicks')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled mikrotik:drain-kicks failed.');
            });

        /*
         * Performs the re-authorisations an operator scheduled for a named time
         * in Asia/Manila.
         *
         * Every minute rather than every fifteen, because this one is answering
         * to a time somebody typed: a quarter-hour schedule would make "14:00"
         * mean "somewhere in the next fifteen minutes", and a maintenance notice
         * sent to subscribers would be wrong by up to that much.
         *
         * The query is a single indexed lookup that returns nothing almost every
         * time — see RunScheduledRadiusKicks for why the scheduler drives this
         * rather than a delayed dispatch.
         *
         * withoutOverlapping for the same reason as the drain above: the work is
         * disconnecting real sessions. The job claims each row before acting, so
         * an overlap could not double-kick anyone even without this, but two
         * guards are cheap next to that outcome.
         */
        $schedule->command('mikrotik:run-scheduled')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled mikrotik:run-scheduled failed.');
            });
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
