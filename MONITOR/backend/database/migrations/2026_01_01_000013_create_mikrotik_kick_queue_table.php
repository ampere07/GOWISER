<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions queued to be terminated during the next maintenance window.
 *
 * "Kick Sessions Now" cuts people off immediately; this table is what "Kick
 * Sessions Later" writes instead. A row is a standing instruction — disconnect
 * everyone in this group so the rate limit they were just given actually takes
 * effect — held until `mikrotik:drain-kicks` runs inside the window.
 *
 * It records who asked and why. A customer ringing to ask why their connection
 * dropped at 2am deserves an answer better than "something on the router", and
 * the audit log alone cannot give it: that records the request, not whether it
 * eventually fired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_kick_queue', function (Blueprint $table) {
            $table->id();

            // What to disconnect. A group empties every session in it; a
            // username list targets specific subscribers. Exactly one is set,
            // which the controller enforces — a row with neither would be an
            // instruction to disconnect nothing, and a row with both is
            // ambiguous about which wins.
            $table->string('target_group')->nullable();
            $table->json('target_usernames')->nullable();

            $table->string('reason')->nullable();

            // Who queued it. Kept as a name as well as an id so the trail
            // survives the user being deleted — the question "who ordered this"
            // must stay answerable.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();

            // pending → done | failed | cancelled. Indexed with the schedule
            // because the drain command's only query is "pending, due now", and
            // that runs every few minutes against a table that only grows.
            $table->string('status')->default('pending');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->unsignedInteger('sessions_killed')->default(0);
            $table->unsignedInteger('sessions_failed')->default(0);
            $table->text('result_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_kick_queue');
    }
};
