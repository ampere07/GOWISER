<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly paid/unpaid state for a synced recurring expense.
 *
 * This lives in MONITOR's own database, not in the source system, and that is
 * the whole design. The read-only guarantee on monitored databases is enforced
 * at the connection level in SourceRegistry::connection() and must not be
 * weakened to let an executive tick a box — so the tick is recorded here and
 * joined onto the synced expense at read time.
 *
 * The consequence is stated in the UI rather than hidden: the expense rows
 * themselves stay live from the source (sync visibility is preserved), while the
 * settlement state is MONITOR's own record of what finance has confirmed.
 *
 * Keyed by (source, expense reference, period month) because the same recurring
 * expense is payable again every month, and last month's tick must not mark this
 * month's rent as settled.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('expense_payable_status', function (Blueprint $table) {
            $table->id();

            // Which monitored database the expense came from.
            $table->string('source_key', 64)->index();

            // The source's own identifier for the expense, as a string: the two
            // schemas use different column types and one of them has no integer
            // id at all for a category-level payable.
            $table->string('expense_ref', 191);

            // First day of the month this settlement applies to.
            $table->date('period_month');

            $table->boolean('is_paid')->default(false);
            $table->date('paid_on')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();

            $table->unique(['source_key', 'expense_ref', 'period_month'], 'expense_payable_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('expense_payable_status');
    }
};
