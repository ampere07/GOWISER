<?php

namespace App\Support;

/**
 * When a service order is a pullout, and when that pullout closes the
 * customer's portal login.
 *
 * `users.active` is the column the sign-in gates on: routes/api.php refuses a
 * row with `active = 0` and answers `status: 'suspended'`. Exactly one act sets
 * it to 0 — a service order saved with a pullout repair category and the visit
 * marked Done — and that rule lived as a bare array literal repeated in four
 * places across two controllers, where the trigger and the re-entry guard could
 * drift apart without anything noticing. It lives here instead.
 *
 * Spelling is not part of the decision. "Pullout", "Pull Out", "for pullout"
 * and "FOR PULL OUT" are the same instruction typed by different people, so the
 * value is folded to lower case and stripped of whitespace before it is
 * compared. Anything else — including a blank category — is not a pullout.
 *
 * GOWISER also accepts the `concern` column. Auto-generated pullout tickets are
 * raised with concern "for pullout" and no repair category, and GOWISER's flow
 * has always completed those as pullouts when the visit is marked Done — so
 * `deactivatesPortalLogin()` checks the repair category OR the concern.
 */
final class PulloutCategory
{
    /**
     * The accepted categories, folded the way `fold()` folds an input:
     * lower case, no whitespace.
     */
    private const CANONICAL = ['pullout', 'forpullout'];

    /** The visit status a pullout has to reach before it counts. */
    private const COMPLETED_VISIT = 'done';

    /** Lower-case and drop all whitespace, so spacing cannot change the meaning. */
    private static function fold(?string $value): string
    {
        return preg_replace('/\s+/u', '', strtolower(trim((string) $value)));
    }

    /** Is this repair category a pullout, however it is spelled? */
    public static function matches(?string $repairCategory): bool
    {
        return in_array(self::fold($repairCategory), self::CANONICAL, true);
    }

    /** Has this visit been completed? */
    public static function visitIsDone(?string $visitStatus): bool
    {
        return self::fold($visitStatus) === self::COMPLETED_VISIT;
    }

    /**
     * The whole rule: a pullout repair category or concern AND a completed visit.
     *
     * Callers pass the values from the SAVED ROW, not from the request — a
     * request that merely claims Done must not disable a login the record never
     * recorded as pulled out, and a request that only sets the category must not
     * inherit a Done left behind by an earlier, unrelated visit.
     */
    public static function deactivatesPortalLogin(?string $repairCategory, ?string $visitStatus, ?string $concern = null): bool
    {
        return (self::matches($repairCategory) || self::matches($concern))
            && self::visitIsDone($visitStatus);
    }
}
