<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user access overrides, on top of the role.
 *
 * Roles cover the shape of a job; overrides cover the exception — the operations
 * lead who also signs off payables, the analyst who must not see one branch's
 * revenue. Without this, every exception becomes a new role, and a portal with
 * eleven near-identical roles is one nobody can audit.
 *
 * Shape: {"grant": ["action.payables.toggle"], "deny": ["widget.financial.revenue"]}
 *
 * Deny wins over grant, and over the role. That ordering is what makes an
 * override usable as a restriction rather than only as an extension.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permission_overrides')->nullable()->after('role_id');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permission_overrides');
        });
    }
};
