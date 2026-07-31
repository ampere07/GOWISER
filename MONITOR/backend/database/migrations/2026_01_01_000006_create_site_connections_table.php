<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per monitored site database.
 *
 * Credentials live here rather than in .env so a site can be added from the
 * admin screen without a deploy. The password is encrypted with APP_KEY —
 * losing that key means re-entering passwords, which is the correct tradeoff
 * against storing them in plaintext.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('site_connections', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // slug used in ?source=
            $table->string('label');
            $table->string('profile_key')->index();   // -> schema_profiles.key

            $table->string('driver')->default('mysql');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(3306);
            $table->string('database');
            $table->string('username');
            $table->text('password')->nullable();     // encrypted at rest
            $table->string('timezone')->default('+08:00');

            // Per-site deviations from the profile, deep-merged over it.
            $table->json('overrides')->nullable();

            // For a shared database where sites are separated by a column
            // rather than by their own schema: {"column":"organization_id","value":3}
            $table->json('scope')->nullable();

            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            // Result of the last connectivity check, shown in the admin list.
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('site_connections');
    }
};
