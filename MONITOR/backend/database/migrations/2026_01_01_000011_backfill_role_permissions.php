<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants existing roles the permission ids this release introduced.
 *
 * The seeder deliberately does not touch a role that already exists — a
 * deployment's own tuning must survive a deploy — but that leaves every role
 * created before this release without the new module, widget and action ids.
 * The visible symptom is a portal where User Management, Settings and the
 * executive overview simply do not appear, because the ids that unlock them are
 * not in anybody's list.
 *
 * Two rules, both additive. Nothing is ever removed here: a permission an
 * operator deliberately withheld must stay withheld, and a migration that
 * silently narrowed access would be far worse than one that failed to widen it.
 *
 *  1. A role whose name matches a shipped preset is unioned with that preset.
 *
 *  2. Any role already holding 'databases' is treated as this deployment's
 *     administrator and additionally granted user, role, audit and settings
 *     management. That inference is safe in exactly one direction: 'databases'
 *     is the permission that exposes the credentials for every monitored
 *     database, so a role holding it is already the most privileged one present.
 *     Without this rule an installation whose admin role is named something
 *     other than "Super Admin" would have nobody able to reach the new
 *     administration screens — including the screen for granting them.
 *
 * The legacy module ids (overview, revenue, financials, consolidated) are left
 * on any role that carries them. They address pages this release removed, so
 * they resolve to nothing and are harmless; stripping them would be a
 * destructive edit for no benefit.
 */
return new class extends Migration
{
    /** What an administrator needs in order to administer. */
    private const ADMIN_GRANTS = [
        Permissions::MODULE_USERS,
        Permissions::MODULE_ROLES,
        Permissions::MODULE_AUDIT,
        Permissions::MODULE_SETTINGS,
        Permissions::ACTION_USERS_MANAGE,
        Permissions::ACTION_ROLES_MANAGE,
        Permissions::ACTION_AUDIT_VIEW,
        Permissions::ACTION_SETTINGS_MANAGE,
    ];

    public function up()
    {
        foreach (DB::table('roles')->get() as $role) {
            $current = json_decode((string) $role->permissions, true);
            $current = is_array($current) ? $current : [];

            $granted = $current;

            // 1. Union with the shipped preset of the same name.
            $preset = Permissions::preset($role->role_name);

            if ($preset !== []) {
                $granted = array_merge($granted, $preset);
            }

            // 2. Whoever can already manage databases can manage the portal.
            if (in_array(Permissions::MODULE_DATABASES, $current, true)) {
                $granted = array_merge($granted, self::ADMIN_GRANTS);
            }

            $granted = array_values(array_unique($granted));

            // sort() so a diff of this column between environments is readable;
            // the order carries no meaning anywhere that consumes it.
            sort($granted);

            if ($granted === $current) {
                continue;
            }

            DB::table('roles')
                ->where('id', $role->id)
                ->update(['permissions' => json_encode($granted)]);
        }
    }

    /**
     * Deliberately irreversible.
     *
     * Rolling back would mean guessing which of a role's permissions this
     * migration added and which an operator granted afterwards, and guessing
     * wrong locks someone out of their own portal. Narrowing a role is a
     * decision for the Roles screen, where it is audited.
     */
    public function down()
    {
        // No-op.
    }
};
