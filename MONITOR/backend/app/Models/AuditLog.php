<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Append-only trail of configuration changes.
 *
 * MONITOR is otherwise read-only, so the handful of endpoints that *can* write —
 * the Databases configuration page — are exactly the ones worth recording. A
 * database connection change can silently redirect every figure in the portal at
 * a different server, and "who pointed Financial at the wrong database" needs an
 * answer that does not depend on someone remembering.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'actor',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'changes',
        'ip_address',
        'logged_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'logged_at' => 'datetime',
    ];

    /**
     * Records one change.
     *
     * `actor` is denormalised alongside `user_id` on purpose: the trail has to
     * stay readable after the account is deleted, which is precisely when someone
     * goes looking at it.
     *
     * Never throws. A failed audit write must not roll back or block the change
     * the operator asked for — it is reported and the request continues.
     */
    public static function record(
        Request $request,
        string $action,
        string $subjectType,
        $subjectId,
        string $description,
        array $changes = []
    ): void {
        try {
            $user = $request->user();

            static::create([
                'user_id' => $user?->id,
                'actor' => $user?->username ?? 'system',
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId !== null ? (string) $subjectId : null,
                'description' => $description,
                'changes' => $changes ?: null,
                'ip_address' => $request->ip(),
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Field names that must never reach the trail in plaintext.
     *
     * The point of the trail is to show *that* a credential changed, not what it
     * changed to.
     */
    private const REDACTED = ['password'];

    /**
     * Reduces a change set to what is worth recording, with credentials masked.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array{from:mixed,to:mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $was = $before[$field] ?? null;

            if ($was === $value) {
                continue;
            }

            if (in_array($field, self::REDACTED, true)) {
                $changes[$field] = ['from' => '********', 'to' => '********'];

                continue;
            }

            $changes[$field] = ['from' => $was, 'to' => $value];
        }

        return $changes;
    }
}
