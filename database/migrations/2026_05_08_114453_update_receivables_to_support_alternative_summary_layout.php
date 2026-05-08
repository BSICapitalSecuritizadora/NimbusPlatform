<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receivables')) {
            return;
        }

        Schema::table('receivables', function (Blueprint $table) {
            $table->string('portfolio_id', 255)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('receivables')) {
            return;
        }

        Schema::table('receivables', function (Blueprint $table) {
            $table->unsignedBigInteger('portfolio_id')->change();
        });
    }
};
