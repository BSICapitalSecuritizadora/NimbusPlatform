<?php

use App\Enums\ContractInstallmentStatus;
use App\Models\ContractInstallment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment schedule of a contract.
     *
     * The contract is the only thing an installment points at. Client, unit,
     * development and emission are all reachable through it, so none of them is
     * stored here -- which is what lets the contract grow a second buyer later
     * without a single column of this table changing.
     *
     * No `status` column on purpose: the state of an installment is a function
     * of its own dates and values plus today's date, so storing it would make it
     * wrong the morning after. See {@see ContractInstallmentStatus}.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('contract_installments', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();

            /**
             * Text, not an integer: the operators number installments "001" and
             * also "ENTRADA", "CHAVES", "INTERMEDIÁRIA 01". Leading zeros are
             * part of the identification and have to survive a round trip.
             *
             * This is the presentation value, stored exactly as it was typed.
             */
            $table->string('number', 50);

            /**
             * The identity of the number inside the contract: trimmed, inner
             * whitespace collapsed, uppercased, accents preserved. Written from
             * `number` by the model on every save -- see
             * {@see ContractInstallment::normalizeNumberForComparison()}.
             *
             * A column rather than an expression on `number`, and computed in
             * PHP rather than by a generated column, because case folding is not
             * portable: MySQL would compare "Entrada" and "entrada" as equal
             * through its collation while SQLite would not, and `UPPER()` is
             * accent-aware in one and ASCII-only in the other. Deciding identity
             * in PHP is what makes the test suite and production agree.
             *
             * Wider than `number`: uppercasing can lengthen a string (ß becomes
             * SS), so the derived value needs headroom the original does not.
             */
            $numberNormalized = $table->string('number_normalized', 100);

            /**
             * Binary collation, and this is the other half of the portability
             * fix. Normalizing in PHP settles the casing, but the unique index
             * still compares under the column's collation, and the database
             * default here is utf8mb4_unicode_ci -- which is accent-insensitive
             * as well, so MySQL would refuse "INTERMEDIARIA 01" as a duplicate
             * of "INTERMEDIÁRIA 01" while SQLite accepted it.
             *
             * Comparing byte for byte makes both engines agree, and keeps the
             * domain rule where it was decided: accents distinguish one
             * identification from another. SQLite already compares TEXT as
             * BINARY, so it needs nothing -- and would reject a MySQL collation
             * name outright.
             */
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $numberNormalized->collation('utf8mb4_bin');
            }

            $table->date('due_date');
            $table->decimal('expected_value', 15, 2);

            /**
             * Both null until the installment is received, and both filled once
             * it is -- never one without the other. No ceiling on `paid_value`:
             * juros, multa and correção monetária routinely push a receipt above
             * what was originally due.
             */
            $table->date('payment_date')->nullable();
            $table->decimal('paid_value', 15, 2)->nullable();

            /**
             * The installment left the contractual flow. It is not a receivable,
             * not overdue and not a future receipt -- but it is still history,
             * so it is never erased.
             */
            $table->date('cancellation_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /**
             * Holds the contract id only while the row is live, so the unique
             * index below reads as "one number per contract" among the live
             * installments while leaving the deleted ones alone.
             *
             * A plain unique on (contract_id, number_normalized) would cover
             * the soft deleted rows too, and a spreadsheet imported by mistake,
             * deleted and imported again would collide with its own corpse.
             * Written by the database, so concurrent imports cannot race past
             * it.
             */
            $table->unsignedBigInteger('active_contract_id')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN contract_id END');

            // Named explicitly: the generated name would be 65 characters and
            // MySQL refuses identifiers past 64.
            $table->unique(['active_contract_id', 'number_normalized'], 'contract_installments_number_unique');

            /** The schedule of one contract, in the order it is always read. */
            $table->index(['contract_id', 'due_date']);

            /**
             * Global overdue and future-receipt queries. `cancellation_date`
             * rides along as the second column instead of getting an index of
             * its own: it is almost entirely null, so on its own it would filter
             * nothing.
             */
            $table->index(['due_date', 'cancellation_date']);

            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_installments');
    }
};
