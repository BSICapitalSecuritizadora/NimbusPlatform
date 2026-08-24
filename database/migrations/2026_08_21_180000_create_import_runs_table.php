<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per reconciliation actually confirmed.
     *
     * The imports stopped being a first-time cadastro and became a monthly
     * conciliation that updates financial records, so "who ran which file, when,
     * and what did it move" has to be answerable months later without reading
     * the activity log line by line.
     *
     * Deliberately a summary, not a copy of the spreadsheet. The per-record trail
     * already exists on each contract and installment through their activity log;
     * this is the index over those, sized to be worth keeping forever.
     *
     * Modelled on `business_calendar_import_runs`, which solves the same problem
     * for the calendar imports.
     */
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();

            /** Which import produced it: 'contracts' or 'contract-installments'. */
            $table->string('type', 40);

            $table->string('file_name');

            /**
             * Content hash of the file. Two runs of the same position share it,
             * which is what lets someone see that a re-import was the same file
             * rather than a new one that happened to change nothing.
             */
            $table->string('checksum', 64)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /**
             * Scope of the run, when it was launched from inside one contract.
             * Null for a full-portfolio import.
             */
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('records_analyzed')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_unchanged')->default(0);
            $table->unsignedInteger('records_critical')->default(0);

            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
