<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the Superadmin role and gives one account access to the portal again.
     *
     * WHY A COLUMN AND NOT A PERMISSION LIST. A superadmin has to keep working as sections are
     * added, and MONITOR has no role editor — permissions are edited straight in the database.
     * A role whose access was a stored list would silently miss every future section until
     * someone wrote another migration. `is_superadmin` is resolved at read time against the
     * section map in App\Support\Permissions, so it stays complete on its own.
     *
     * WHY NOT KEY ON THE ROLE NAME. A name can be renamed, copied, or typed into a new row.
     * None of those should hand out unrestricted access to every figure in the company.
     *
     * BOOTSTRAP. The `users.*` permissions are new, so no existing role holds them and the Users
     * page would otherwise be reachable by nobody — including whoever runs this. One account is
     * therefore promoted, and only under a strict condition: no user already holds a superadmin
     * role. It picks the lowest id, which is the account this installation was set up with.
     * If there are no users at all it does nothing; the role still exists to be assigned later.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'is_superadmin')) {
                $table->boolean('is_superadmin')->default(false)->after('is_system');
            }
        });

        $now = now();

        $existing = DB::table('roles')->where('role_name', 'Superadmin')->first();

        if ($existing) {
            // Idempotent: a re-run flags the role rather than creating a second one.
            DB::table('roles')->where('id', $existing->id)->update([
                'is_superadmin' => true,
                'is_system' => true,
                'updated_at' => $now,
            ]);

            $roleId = $existing->id;
        } else {
            $roleId = DB::table('roles')->insertGetId([
                'role_name' => 'Superadmin',
                'description' => 'Unrestricted access to every section, including user administration.',
                // Empty on purpose. Access comes from is_superadmin; a stored list here would be
                // a second source of truth that drifts out of date.
                'permissions' => json_encode([]),
                'site_scope' => null,
                'is_system' => true,
                'is_superadmin' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $superadminRoleIds = DB::table('roles')->where('is_superadmin', true)->pluck('id')->all();

        $alreadyHasOne = DB::table('users')
            ->whereIn('role_id', $superadminRoleIds)
            ->exists();

        if ($alreadyHasOne) {
            return;
        }

        $firstUser = DB::table('users')->orderBy('id')->first();

        if (!$firstUser) {
            return;
        }

        DB::table('users')->where('id', $firstUser->id)->update([
            'role_id' => $roleId,
            'updated_at' => $now,
        ]);

        // Logged loudly: this migration changed who can see everything, and that should be
        // discoverable afterwards without reading migration files.
        Log::warning('Superadmin role granted during migration', [
            'user_id' => $firstUser->id,
            'username' => $firstUser->username ?? null,
            'role_id' => $roleId,
            'reason' => 'No account held a superadmin role; promoted the lowest-id user to bootstrap access.',
        ]);
    }

    /**
     * Only removes what this migration added.
     *
     * The role itself is left in place: users may have been assigned to it since, and deleting it
     * would orphan them onto no role at all. Dropping the column is enough to disable the
     * unrestricted behaviour.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'is_superadmin')) {
                $table->dropColumn('is_superadmin');
            }
        });
    }
};
