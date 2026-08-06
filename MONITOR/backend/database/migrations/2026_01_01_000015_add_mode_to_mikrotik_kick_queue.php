<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a queued kick decides it is due.
 *
 * There are now two answers and they are not interchangeable:
 *
 *   window  the original behaviour — wait for the next maintenance window and
 *           let `mikrotik:drain-kicks` perform it. The operator is saying "not
 *           during business hours", not naming a time.
 *   at      the operator named a wall-clock time in Asia/Manila.
 *           `mikrotik:run-scheduled` performs it at that time, whatever the
 *           maintenance window says.
 *
 * Without this column the drain command would treat a kick scheduled for 2pm as
 * a window kick and hold it until 1am, which is the opposite of what naming a
 * time means.
 *
 * Existing rows default to `window`, which is what every one of them was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_kick_queue', function (Blueprint $table) {
            $table->string('mode', 16)->default('window')->after('status');

            // The offset the operator typed against. Stored so a row can be read
            // back years later without assuming the server's timezone never
            // changed — the timestamp is absolute, this says what was meant.
            $table->string('scheduled_timezone', 64)->nullable()->after('scheduled_for');

            $table->index(['mode', 'status', 'scheduled_for'], 'kick_mode_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_kick_queue', function (Blueprint $table) {
            $table->dropIndex('kick_mode_due_index');
            $table->dropColumn(['mode', 'scheduled_timezone']);
        });
    }
};
