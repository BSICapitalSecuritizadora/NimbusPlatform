<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            // Canonical parts are strings so leading zeros survive; every
            // column below is NOT NULL, which keeps the composite unique key
            // deterministic (no NULL row can slip past it in MySQL).
            $table->string('agency_number', 10);
            $table->string('agency_digit', 2);
            $table->string('account_number', 20);
            $table->string('account_digit', 2);
            $table->string('display_agency', 20)->nullable();
            $table->string('display_account', 30)->nullable();
            // Descriptive only: holder_context stays OUT of the unique key
            // because it is optional, and a NULL inside a MySQL unique key
            // would allow duplicate physical accounts.
            $table->string('holder_context')->nullable();
            // Conceptual home of the Conta Azul mapping. Funds keep their own
            // identifier for operations during Phase 1A; nothing is copied.
            $table->string('conta_azul_account_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['bank_id', 'agency_number', 'agency_digit', 'account_number', 'account_digit'],
                'bank_accounts_canonical_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
