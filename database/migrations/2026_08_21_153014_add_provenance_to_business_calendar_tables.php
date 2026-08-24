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
        Schema::table('business_holidays', function (Blueprint $table) {
            $table->foreignId('business_calendar_year_id')->nullable()->after('id')->constrained('business_calendar_years')->nullOnDelete();
            $table->string('data_origin', 30)->nullable()->after('source');
            $table->boolean('source_is_official')->nullable()->after('data_origin');
            $table->text('source_document')->nullable()->after('source_file');
            $table->string('source_revision', 100)->nullable()->after('source_document');
            $table->string('checksum', 64)->nullable()->after('source_revision');
            $table->foreignId('import_run_id')->nullable()->after('checksum')->constrained('business_calendar_import_runs')->nullOnDelete();
            $table->foreignId('last_seen_import_run_id')->nullable()->after('import_run_id')->constrained('business_calendar_import_runs')->nullOnDelete();
            $table->timestamp('removed_detected_at')->nullable()->after('last_seen_import_run_id');
        });

        Schema::table('business_calendar_dates', function (Blueprint $table) {
            $table->foreignId('business_calendar_year_id')->nullable()->after('id')->constrained('business_calendar_years')->nullOnDelete();
            $table->string('data_origin', 30)->nullable()->after('description');
            $table->string('source', 80)->nullable()->after('data_origin');
            $table->boolean('source_is_official')->nullable()->after('source');
            $table->text('source_document')->nullable()->after('source_is_official');
            $table->string('source_revision', 100)->nullable()->after('source_document');
            $table->unsignedBigInteger('revision')->nullable()->after('source_revision');
            $table->foreignId('import_run_id')->nullable()->after('revision')->constrained('business_calendar_import_runs')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_calendar_dates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_calendar_year_id');
            $table->dropConstrainedForeignId('import_run_id');
            $table->dropColumn([
                'data_origin',
                'source',
                'source_is_official',
                'source_document',
                'source_revision',
                'revision',
            ]);
        });

        Schema::table('business_holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_calendar_year_id');
            $table->dropConstrainedForeignId('import_run_id');
            $table->dropConstrainedForeignId('last_seen_import_run_id');
            $table->dropColumn([
                'data_origin',
                'source_is_official',
                'source_document',
                'source_revision',
                'checksum',
                'removed_detected_at',
            ]);
        });
    }
};
