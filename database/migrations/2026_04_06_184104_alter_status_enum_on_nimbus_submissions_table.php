<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE nimbus_submissions
                MODIFY status ENUM('PENDING', 'UNDER_REVIEW', 'NEEDS_CORRECTION', 'COMPLETED', 'REJECTED')
                NOT NULL DEFAULT 'PENDING'
            ");

            return;
        }

        Schema::table('nimbus_submissions', function (Blueprint $table) {
            $table->string('status', 50)->default('PENDING')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                UPDATE nimbus_submissions
                SET status = 'UNDER_REVIEW'
                WHERE status = 'NEEDS_CORRECTION'
            ");

            DB::statement("
                ALTER TABLE nimbus_submissions
                MODIFY status ENUM('PENDING', 'UNDER_REVIEW', 'COMPLETED', 'REJECTED')
                NOT NULL DEFAULT 'PENDING'
            ");

            return;
        }

        Schema::table('nimbus_submissions', function (Blueprint $table) {
            $table->string('status', 50)->default('PENDING')->change();
        });
    }
};
