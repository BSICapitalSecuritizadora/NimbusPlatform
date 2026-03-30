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
        Schema::create('nimbus_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_portal_user_id')->constrained('nimbus_portal_users')->cascadeOnDelete();
            $table->string('reference_code', 64);
            $table->string('submission_type', 50)->default('REGISTRATION');
            $table->string('title', 190);
            $table->text('message')->nullable();

            // PJ Data
            $table->string('responsible_name', 190)->nullable();
            $table->string('company_cnpj', 18)->nullable();
            $table->string('company_name', 190)->nullable();
            $table->string('main_activity', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website', 255)->nullable();
            $table->decimal('net_worth', 15, 2)->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->boolean('is_us_person')->default(false);
            $table->boolean('is_pep')->default(false);
            $table->json('shareholder_data')->nullable();

            // Registrant
            $table->string('registrant_name', 190)->nullable();
            $table->string('registrant_position', 100)->nullable();
            $table->string('registrant_rg', 20)->nullable();
            $table->string('registrant_cpf', 14)->nullable();

            // Status and meta
            $table->enum('status', ['PENDING', 'UNDER_REVIEW', 'COMPLETED', 'REJECTED'])->default('PENDING');
            $table->string('created_ip', 45)->nullable();
            $table->string('created_user_agent', 255)->nullable();

            $table->timestamp('submitted_at')->useCurrent();
            $table->dateTime('status_updated_at')->nullable();
            $table->foreignId('status_updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('nimbus_submission_shareholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_submission_id')->constrained('nimbus_submissions')->cascadeOnDelete();
            $table->string('name', 190);
            $table->string('document_rg', 20)->nullable();
            $table->string('document_cnpj', 18)->nullable();
            $table->decimal('percentage', 5, 2);
            $table->timestamps();
        });

        Schema::create('nimbus_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_submission_id')->constrained('nimbus_submissions')->cascadeOnDelete();
            $table->enum('document_type', ['BALANCE_SHEET', 'DRE', 'POLICIES', 'CNPJ_CARD', 'POWER_OF_ATTORNEY', 'MINUTES', 'ARTICLES_OF_INCORPORATION', 'BYLAWS', 'OTHER'])->default('OTHER');
            $table->enum('origin', ['USER', 'ADMIN'])->default('USER');
            $table->boolean('visible_to_user')->default(false);
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path', 255);
            $table->string('checksum', 128)->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('nimbus_submission_file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_submission_file_id')->constrained('nimbus_submission_files', 'id', 'nimbus_file_vers_file_id_fk')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('storage_path', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 100)->nullable();
            $table->string('checksum', 128)->nullable();
            $table->enum('uploaded_by_type', ['ADMIN', 'PORTAL_USER']);
            $table->unsignedBigInteger('uploaded_by_id');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('nimbus_submission_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_submission_id')->constrained('nimbus_submissions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // Changed admin_user_id to user_id
            $table->enum('visibility', ['USER_VISIBLE', 'ADMIN_ONLY'])->default('USER_VISIBLE');
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('nimbus_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('color', 7)->default('#666666');
            $table->timestamps();
        });

        Schema::create('nimbus_submission_tags', function (Blueprint $table) {
            $table->foreignId('nimbus_submission_id')->constrained('nimbus_submissions')->cascadeOnDelete();
            $table->foreignId('nimbus_tag_id')->constrained('nimbus_tags')->cascadeOnDelete();
            $table->primary(['nimbus_submission_id', 'nimbus_tag_id']);
            $table->timestamps();
        });

        Schema::create('nimbus_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('body');
            $table->enum('level', ['info', 'success', 'warning', 'danger'])->default('info');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('nimbus_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_portal_user_id')->constrained('nimbus_portal_users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('file_path', 500);
            $table->string('file_original_name', 255);
            $table->unsignedInteger('file_size');
            $table->string('file_mime', 120);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nimbus_documents');
        Schema::dropIfExists('nimbus_announcements');
        Schema::dropIfExists('nimbus_submission_tags');
        Schema::dropIfExists('nimbus_tags');
        Schema::dropIfExists('nimbus_submission_notes');
        Schema::dropIfExists('nimbus_submission_file_versions');
        Schema::dropIfExists('nimbus_submission_files');
        Schema::dropIfExists('nimbus_submission_shareholders');
        Schema::dropIfExists('nimbus_submissions');
    }
};
