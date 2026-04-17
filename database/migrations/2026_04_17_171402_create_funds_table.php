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
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fund_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('fund_name_id')->constrained()->restrictOnDelete();
            $table->foreignId('fund_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->string('account');
            $table->timestamps();

            $table->unique(['emission_id', 'fund_application_id', 'account'], 'funds_emission_application_account_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
};
