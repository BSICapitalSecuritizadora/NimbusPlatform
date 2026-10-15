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
        Schema::create('bank_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            // Bank-reported product code, stored exactly as reported so
            // leading zeros survive. Unrelated to fund_application_id.
            $table->string('codigo_produto', 60);
            $table->string('nome_produto')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();

            $table->unique(['bank_account_id', 'codigo_produto'], 'bank_applications_account_product_unique');
            // Supports the funds(bank_account_id, bank_application_id)
            // composite foreign key, which needs an index whose leftmost
            // columns cover the referenced pair.
            $table->unique(['bank_account_id', 'id'], 'bank_applications_account_pk_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_applications');
    }
};
