<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_histories', function (Blueprint $table): void {
            $table->string('status', 30)->nullable()->after('payment_date');
            $table->decimal('paid_amount', 15, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('expense_histories', function (Blueprint $table): void {
            $table->dropColumn(['status', 'paid_amount']);
        });
    }
};
