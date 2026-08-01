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
