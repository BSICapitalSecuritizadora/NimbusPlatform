<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allocation lines: one row per Fund inside a revision. The emission is
     * frozen on the line at draft time so historical reports reconstruct
     * from (competence, effective revision) without depending on the Fund's
     * current associations.
     */
    public function up(): void
    {
        Schema::create('fund_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_allocation_revision_id')->constrained()->cascadeOnDelete();
            // Restrict: a Fund with allocation history is never deleted.
            $table->foreignId('fund_id')->constrained()->restrictOnDelete();
            // Emission identity frozen at line creation (historical stability).
            $table->foreignId('emission_id')->constrained()->restrictOnDelete();
            // Zero-value lines are explicit and valid (recorded as 0.00).
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            // No duplicate Fund line within one revision.
            $table->unique(
                ['fund_allocation_revision_id', 'fund_id'],
                'allocation_lines_revision_fund_unique'
            );
            $table->index(
                ['fund_allocation_revision_id', 'emission_id'],
                'allocation_lines_revision_emission_index'
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `fund_allocation_lines` ADD CONSTRAINT `allocation_lines_amount_non_negative` CHECK (`amount` >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fund_allocation_lines');
    }
};
