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
        Schema::table('measurements', function (Blueprint $table) {
            $table->string('storage_disk')->nullable()->after('storage_path');
            $table->char('sha256', 64)->nullable()->after('storage_disk')->index();
            $table->unsignedBigInteger('file_size')->nullable()->after('sha256');
            $table->string('mime_type', 100)->nullable()->after('file_size');
        });

        Schema::table('measurement_assets', function (Blueprint $table) {
            $table->string('storage_disk')->nullable()->after('storage_path');
            $table->char('sha256', 64)->nullable()->after('storage_disk')->index();
            $table->string('mime_type', 100)->nullable()->after('size');
        });

        Schema::table('measurement_payments', function (Blueprint $table) {
            $table->string('receipt_disk')->nullable()->after('receipt_path');
            $table->char('receipt_sha256', 64)->nullable()->after('receipt_disk')->index();
            $table->unsignedBigInteger('receipt_size')->nullable()->after('receipt_sha256');
            $table->string('receipt_mime_type', 100)->nullable()->after('receipt_size');
            $table->foreignId('receipt_uploaded_by')
                ->nullable()
                ->after('receipt_uploaded_at')
                ->index()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurement_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receipt_uploaded_by');
            $table->dropColumn([
                'receipt_disk',
                'receipt_sha256',
                'receipt_size',
                'receipt_mime_type',
            ]);
        });

        Schema::table('measurement_assets', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'sha256', 'mime_type']);
        });

        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'sha256', 'file_size', 'mime_type']);
        });
    }
};
