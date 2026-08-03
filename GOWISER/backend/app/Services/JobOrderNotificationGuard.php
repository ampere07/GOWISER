<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a job order is allowed to appear in a JO-number notification.
 *
 * A JO notification names the job order by number and tells the reader the work
 * is finished. That claim is only true once BOTH halves of the job order have
 * landed:
 *
 *   visit_status    the technician's visit on the linked application. While it
 *                   is still Pending nobody has confirmed the install happened,
 *                   so announcing a completed JO is announcing something that
 *                   has not been observed.
 *
 *   billing_status  the job order's own billing state. JobOrderController@store
 *                   defaults it to 'Pending', and a JO that has not been billed
 *                   is not finished from the business's side — the notification
 *                   drives follow-up work that would then be done against an
 *                   account with no charge on it.
 *
 * Either one pending suppresses the notification. Suppression is logged with the
 * JO id and the reason, because "the notification never arrived" is otherwise
 * indistinguishable from "the job order was never done", and the two lead
 * support down completely different paths.
 *
 * Read-only and side-effect free apart from the log line, so a caller may run it
 * as many times as it likes — the notification feeds are polled endpoints and
 * re-evaluate the same job orders on every request.
 */
class JobOrderNotificationGuard
{
    /**
     * Status values that mean "not settled yet".
     *
     * Compared case-insensitively after trimming: the column is free-text
     * varchar written by several different forms, and production data carries
     * 'Pending', 'pending' and ' Pending ' interchangeably. Blank and NULL are
     * deliberately NOT treated as pending — a job order that predates the
     * billing_status column has no value at all, and suppressing every one of
     * those would silence the feed for all historical work.
     */
    private const PENDING_STATES = ['pending'];

    public const REASON_VISIT_PENDING = 'visit_status is pending';
    public const REASON_BILLING_PENDING = 'billing_status is pending';

    /**
     * Job order ids that must NOT be notified, mapped to why.
     *
     * One query for the whole batch rather than one per job order: these feeds
     * render a page of notifications at a time, and a per-row check would be a
     * round trip per notification on an endpoint the header polls.
     *
     * The visit joined is the most recent one for the application. A job order's
     * application can be visited more than once (a failed first visit, then a
     * successful revisit) and it is the latest attempt that describes the
     * current state — an old Pending row must not suppress a job order whose
     * revisit completed.
     *
     * @param  iterable<int|string>  $jobOrderIds
     * @return array<int,string>  [job_order_id => reason]
     */
    public function suppressed(iterable $jobOrderIds): array
    {
        $ids = $this->normaliseIds($jobOrderIds);

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('job_orders as jo')
            ->leftJoin('application_visits as av', function ($join) {
                // Correlated on the indexed application_id, so this stays a
                // keyed lookup per job order rather than a scan of the visits
                // table. MAX(id) rather than MAX(timestamp): timestamp is
                // nullable here and id is monotonic, so it is the reliable
                // "latest row" for this table.
                $join->on('av.id', '=', DB::raw(
                    '(SELECT MAX(av2.id) FROM application_visits av2'
                    . ' WHERE av2.application_id = jo.application_id)'
                ));
            })
            ->whereIn('jo.id', $ids)
            ->select('jo.id', 'jo.billing_status', 'av.visit_status')
            ->get();

        $suppressed = [];

        foreach ($rows as $row) {
            $reason = $this->reasonFor($row->visit_status, $row->billing_status);

            if ($reason === null) {
                continue;
            }

            $suppressed[(int) $row->id] = $reason;

            $this->logSuppressed($row->id, $reason, [
                'visit_status' => $row->visit_status,
                'billing_status' => $row->billing_status,
            ]);
        }

        return $suppressed;
    }

    /**
     * Whether one job order may be notified.
     *
     * For the single-dispatch callers (a push notification about one JO). Batch
     * callers should use suppressed() instead so the check costs one query for
     * the whole page.
     */
    public function mayNotify($jobOrderId): bool
    {
        return $this->suppressed([$jobOrderId]) === [];
    }

    /**
     * The suppression reason for a pair of statuses the caller already holds.
     *
     * Pure — no query, no log. Exists so a feed that is already joining the
     * visit row for its own SELECT can reuse this rule instead of paying for a
     * second round trip to re-read statuses it has in hand. Callers that
     * suppress on the strength of this must call logSuppressed() themselves;
     * the two are split because deciding and announcing are different acts and
     * only the caller knows whether it actually dropped the row.
     *
     * @return string|null  null when the job order may be notified
     */
    public function reasonFor($visitStatus, $billingStatus): ?string
    {
        $reasons = [];

        if ($this->isPending($visitStatus)) {
            $reasons[] = self::REASON_VISIT_PENDING;
        }

        if ($this->isPending($billingStatus)) {
            $reasons[] = self::REASON_BILLING_PENDING;
        }

        return $reasons === [] ? null : implode(' and ', $reasons);
    }

    /**
     * Records that a JO number notification was withheld.
     *
     * Logged at info rather than warning: suppression is the rule working, not
     * a fault. It is logged at all because a notification that never arrives is
     * otherwise indistinguishable from a job order that was never done.
     */
    public function logSuppressed($jobOrderId, string $reason, array $context = []): void
    {
        Log::info('JO number notification suppressed', array_merge([
            'job_order_id' => (int) $jobOrderId,
            'reason' => $reason,
        ], $context));
    }

    /**
     * Drops the suppressed entries from a notification collection.
     *
     * Takes the id out of each entry through a callback rather than assuming a
     * key name, because the two feeds that use this shape their rows
     * differently — one has already mapped to the response array, the other is
     * still holding raw query rows.
     *
     * @template T
     * @param  \Illuminate\Support\Collection<int,T>  $notifications
     * @param  callable(T):(int|string|null)  $idOf
     * @return \Illuminate\Support\Collection<int,T>
     */
    public function filter($notifications, callable $idOf)
    {
        $ids = $notifications->map($idOf)->filter()->all();

        $suppressed = $this->suppressed($ids);

        if ($suppressed === []) {
            return $notifications;
        }

        return $notifications
            ->reject(fn ($entry) => isset($suppressed[(int) $idOf($entry)]))
            ->values();
    }

    /**
     * Only positive integer ids reach the query.
     *
     * The callers pass ids straight out of request payloads and query results,
     * so a null or a non-numeric string is possible; letting one through would
     * widen the whereIn rather than narrowing it.
     *
     * @return array<int,int>
     */
    private function normaliseIds(iterable $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }

    private function isPending($status): bool
    {
        if ($status === null) {
            return false;
        }

        return in_array(strtolower(trim((string) $status)), self::PENDING_STATES, true);
    }
}
