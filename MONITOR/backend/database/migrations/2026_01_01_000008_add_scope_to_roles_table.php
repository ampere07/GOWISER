<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles gain two things beyond the section permissions they already carry:
 *
 *  - site_scope: which site connections a role may see. An area manager gets
 *    their own branches; the CFO gets everything. Without this, any login that
 *    can open Financials sees every site's profit and loss.
 *  - is_system: protects the built-in roles from being deleted out from under
 *    the last administrator.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('roles', function (Blueprint $table) {
            // null = every site; ["zamboanga","jolo"] = just those.
            $table->json('site_scope')->nullable()->after('permissions');
            $table->boolean('is_system')->default(false)->after('site_scope');
        });
    }

    public function down()
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['site_scope', 'is_system']);
        });
    }
};
