<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile says how one *product's* database answers the canonical questions
 * this dashboard asks — which table holds payments, which column is the
 * amount, which rows to ignore.
 *
 * Authored once per product (SYNC, the legacy NetManager), then reused by
 * every site running it. Onboarding another SYNC site needs no profile work.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('schema_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('description')->nullable();

            // {"datasets": {"payments": {"table": ..., "fields": {...},
            //  "filters": [...], "joins": [...]}, ...}}
            $table->json('definition');

            // System profiles ship with the app and are protected from
            // deletion; an operator can still clone or override them.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('schema_profiles');
    }
};
