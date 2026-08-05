<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One queued session termination, waiting for the maintenance window.
 *
 * See the migration for why the queue exists at all. This model adds the two
 * pieces of behaviour that must not be duplicated at call sites: what the next
 * window is, and whether now is inside it.
 */
class MikrotikKick extends Model
{
    protected $table = 'mikrotik_kick_queue';

    protected $fillable = [
        'target_group',
        'target_usernames',
        'reason',
        'requested_by',
        'requested_by_name',
        'status',
        'scheduled_for',
        'executed_at',
        'sessions_killed',
        'sessions_failed',
        'result_note',
    ];

    protected $casts = [
        'target_usernames' => 'array',
        'scheduled_for' => 'datetime',
        'executed_at' => 'datetime',
        'sessions_killed' => 'integer',
        'sessions_failed' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The start of the next maintenance window.
     *
     * Today's window if it has not started yet, tomorrow's if it has — so a kick
     * queued at 03:00, *inside* a 01:00–05:00 window, is scheduled for tomorrow
     * rather than for two hours ago. Queueing something into the past would make
     * "later" mean "in a moment", which is precisely what the operator chose not
     * to do when they picked Later over Now.
     */
    public static function nextWindow(?Carbon $from = null): Carbon
    {
        $now = $from ?? Carbon::now();
        $start = (string) config('mikrotik.maintenance_window.start', '01:00');

        [$hour, $minute] = array_pad(array_map('intval', explode(':', $start)), 2, 0);

        $candidate = $now->copy()->setTime($hour, $minute);

        return $candidate->isAfter($now) ? $candidate : $candidate->addDay();
    }

    /**
     * Whether now falls inside the maintenance window.
     *
     * Handles a window that crosses midnight — 23:00 to 04:00 is the common
     * choice — by testing the union of the two spans rather than a single
     * between(), which would be empty for every such window and quietly stop the
     * queue from ever draining.
     */
    public static function inWindow(?Carbon $at = null): bool
    {
        $now = $at ?? Carbon::now();

        $start = (string) config('mikrotik.maintenance_window.start', '01:00');
        $end = (string) config('mikrotik.maintenance_window.end', '05:00');

        $minutes = fn (string $time): int => (function (array $parts): int {
            return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
        })(array_pad(explode(':', $time), 2, '0'));

        $startMinutes = $minutes($start);
        $endMinutes = $minutes($end);
        $nowMinutes = $now->hour * 60 + $now->minute;

        return $startMinutes <= $endMinutes
            ? $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes
            : $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }

    /** A one-line description of what this row will disconnect. */
    public function targetLabel(): string
    {
        if ($this->target_group) {
            return "group {$this->target_group}";
        }

        $users = (array) ($this->target_usernames ?? []);

        return $users === []
            ? 'nothing'
            : count($users) . ' ' . (count($users) === 1 ? 'user' : 'users');
    }
}
