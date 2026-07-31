<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MONITOR's own audit trail.
 *
 * The dashboards are read-only, but the admin surface is not: adding a site,
 * changing a mapping or granting a role all change what executives see. Those
 * actions are recorded here.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor')->nullable();       // username, kept even if the user is deleted
            $table->string('action');                  // created, updated, deleted, login...
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('description')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('logged_at')->useCurrent()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('audit_logs');
    }
};
