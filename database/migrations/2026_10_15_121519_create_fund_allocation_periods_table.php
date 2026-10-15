<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allocation-period aggregate: one row per physical application and
     * reporting competence. The period is the lock anchor for atomic
     * approval — `lockForUpdate()` on this row serializes every approval,
     * submission and correction for the whole distribution.
     *
     * `effective_revision_id` is a single nullable column on purpose: the
     * "never two conflicting effective revisions" invariant is structural,
     * not a convention. `open_revision_id` likewise allows at most one
     * draft or pending-review revision per period. The foreign keys to
     * `fund_allocation_revisions` are added by the revisions migration,
     * which runs after this one.
     */
    public function up(): void
    {
        Schema::create('fund_allocation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_application_id')->constrained()->restrictOnDelete();
            // Reporting competence, always normalized to the first day of the month.
            $table->date('competence');
            // Current approved revision, if any. Set only by approval, under lock.
            $table->unsignedBigInteger('effective_revision_id')->nullable()->unique();
            // Current draft or pending-review revision, if any.
            $table->unsignedBigInteger('open_revision_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(
                ['bank_application_id', 'competence'],
                'allocation_periods_app_competence_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fund_allocation_periods');
    }
};
