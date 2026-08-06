<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user display preferences.
 *
 * Currently one thing: how often the Group Overview and MikroTik RADIUS screens
 * re-read themselves. It belongs to the person, not to the portal — a NOC
 * operator watching a wall display wants thirty seconds and an executive opening
 * the dashboard twice a day wants it off, and neither should be able to impose
 * their choice on the other, which a global app_setting would do.
 *
 * ── Why the server and not localStorage ───────────────────────────────
 *
 * localStorage would have been less work and is wrong for this. The refresh
 * interval decides how much load the portal puts on eight monitored databases
 * and, on the MikroTik screen, on routers that are simultaneously serving live
 * authentication. That is an operational setting with a cost attached, it is
 * configured on the Settings page beside settings that are already server-side,
 * and it must survive the browser it was set in — otherwise every new machine
 * silently reverts to the default and nobody notices the load creeping back.
 *
 * A JSON column rather than a table: these are a handful of scalars read on
 * every /me call and never queried across users. A `user_preferences` table
 * would be a join on the hottest endpoint in the app to fetch two integers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable rather than defaulted: "this user has never chosen" and
            // "this user chose the defaults" are different, and only the first
            // should follow a future change to what the defaults are.
            $table->json('preferences')->nullable()->after('permission_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
