<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Small key/value store for portal-wide branding.
 *
 * Currently one key — the system logo's stored path. A table rather than a
 * config entry because the logo is uploaded through the UI at runtime, and a
 * value the application writes does not belong in a file that ships in git.
 *
 * Deliberately generic and deliberately tiny: this is for a handful of
 * singleton settings a non-developer changes. Anything with structure, a
 * history, or per-user scope gets its own table rather than being flattened
 * into a string here.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('app_settings');
    }
};
