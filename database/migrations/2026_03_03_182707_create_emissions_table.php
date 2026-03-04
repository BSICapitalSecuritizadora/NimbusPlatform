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

            $table->string('title');
            $table->string('series')->nullable();
            $table->string('code')->nullable();
            $table->string('isin')->nullable();
            $table->string('remuneration')->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('status')->default('draft');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emissions');
    }
};