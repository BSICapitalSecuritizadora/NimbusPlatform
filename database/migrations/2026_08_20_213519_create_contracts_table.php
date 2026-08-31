<?php

use App\Enums\ContractStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The commercial relationship between a client and a construction unit.
     *
     * This is the only link between the two: neither side points at the other,
     * so a unit can be sold, distratada and resold while every past contract
     * stays untouched. Nothing about installments lives here -- that is a module
     * of its own, built on top of this one.
     *
     * The emission is reached through the construction, so it is not stored.
     * The construction is: it is derived from the unit and kept in sync by the
     * model, and it is what makes the real uniqueness rule -- one code per
     * development -- enforceable by the database instead of only by the
     * application.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->date('sale_date')->nullable();
            $table->decimal('sale_value', 15, 2);
            $table->string('status', 20)->default(ContractStatus::Active->value);
            $table->date('cancellation_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            /**
             * Holds the unit id only while the contract still occupies it, so a
             * unique index expresses "at most one live contract per unit"
             * without forbidding the historical ones. Written by the database,
             * which is what makes it safe under concurrent imports.
             *
             * The statuses are spelled out instead of read from
             * {@see ContractStatus::occupiesUnit()} so this migration keeps
             * producing the same column forever. Adding a status that holds the
             * unit means writing a migration that rewrites the expression --
             * ContractManagementTest fails until that happens.
             */
            $table->unsignedBigInteger('occupied_unit_lock')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('ativo', 'quitado', 'permutado') AND deleted_at IS NULL THEN construction_unit_id END");

            $table->unique('occupied_unit_lock');
            $table->unique(['construction_id', 'code']);
            $table->index(['construction_id', 'status']);
            $table->index(['construction_unit_id', 'sale_date']);
            $table->index(['client_id', 'sale_date']);
            $table->index(['status', 'sale_date']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
