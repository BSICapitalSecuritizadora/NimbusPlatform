<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emissions', function (Blueprint $table) {
            $table->id();

            // Informações gerais
            $table->string('name');
            $table->string('type')->nullable(); // CR, CRA, CRI
            $table->string('if_code')->nullable();
            $table->string('isin_code')->nullable();
            $table->string('status')->default('draft');

            // Características
            $table->string('issuer')->nullable();
            $table->string('fiduciary_regime')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('monetary_update_period')->nullable();
            $table->string('series')->nullable();
            $table->string('emission_number')->nullable();
            $table->unsignedBigInteger('issued_quantity')->nullable();
            $table->string('monetary_update_months')->nullable();
            $table->string('interest_payment_frequency')->nullable();
            $table->string('offer_type')->nullable();
            $table->string('concentration')->nullable();
            $table->decimal('issued_price', 15, 2)->nullable();
            $table->string('amortization_frequency')->nullable();
            $table->unsignedBigInteger('integralized_quantity')->nullable();
            $table->string('trustee_agent')->nullable();
            $table->string('debtor')->nullable();
            $table->string('remuneration')->nullable();
            $table->boolean('prepayment_possibility')->default(false);
            $table->string('segment')->nullable();
            $table->decimal('issued_volume', 18, 2)->nullable();

            $table->boolean('is_public')->default(false);
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emissions');
    }
};
