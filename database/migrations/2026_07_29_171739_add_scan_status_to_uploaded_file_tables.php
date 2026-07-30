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
        Schema::table('proposal_files', function (Blueprint $table) {
            $table->string('scan_status', 20)->default('pending')->index()->after('checksum');
        });

        Schema::table('nimbus_submission_files', function (Blueprint $table) {
            $table->string('scan_status', 20)->default('pending')->index()->after('checksum');
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->string('scan_status', 20)->default('pending')->index()->after('resume_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposal_files', function (Blueprint $table) {
            $table->dropColumn('scan_status');
        });

        Schema::table('nimbus_submission_files', function (Blueprint $table) {
            $table->dropColumn('scan_status');
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn('scan_status');
        });
    }
};
