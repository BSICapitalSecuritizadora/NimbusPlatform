<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nimbus_portal_users', function (Blueprint $table) {
            // Blind indexes for exact-match lookups (HMAC-SHA256, domain-separated).
            // Nullable initially for staged backfill; populated by backfill command.
            $table->string('document_number_hash', 64)->nullable()->after('document_number');
            $table->string('phone_number_hash', 64)->nullable()->after('phone_number');
        });

        // Unique on normalized document hash (exact CPF match). Allows multiple NULLs.
        try {
            Schema::table('nimbus_portal_users', function (Blueprint $table) {
                $table->unique('document_number_hash', 'nimbus_portal_users_doc_hash_unique');
            });
        } catch (Throwable $e) {
            // Index may already exist on rerun.
        }

        try {
            Schema::table('nimbus_portal_users', function (Blueprint $table) {
                $table->index('phone_number_hash', 'nimbus_portal_users_phone_hash_index');
            });
        } catch (Throwable $e) {
        }

        // Drop legacy unique on plaintext document_number so encrypted column can hold
        // ciphertext (unique moves to hash). Safe to ignore if already dropped.
        try {
            Schema::table('nimbus_portal_users', function (Blueprint $table) {
                $table->dropUnique(['document_number']);
            });
        } catch (Throwable $e) {
            // Already dropped or driver does not support.
        }

        // Expand columns to TEXT to hold Laravel encrypted payload (base64 + IV + MAC, ~300+ chars).
        // Keep nullable. Use DB statement for MySQL to avoid doctrine/dbal requirement;
        // for sqlite (tests) change() is sufficient.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE nimbus_portal_users MODIFY document_number TEXT NULL');
            DB::statement('ALTER TABLE nimbus_portal_users MODIFY phone_number TEXT NULL');
        } else {
            Schema::table('nimbus_portal_users', function (Blueprint $table) {
                $table->text('document_number')->nullable()->change();
                $table->text('phone_number')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE nimbus_portal_users MODIFY document_number VARCHAR(50) NULL');
            DB::statement('ALTER TABLE nimbus_portal_users MODIFY phone_number VARCHAR(50) NULL');
        } else {
            Schema::table('nimbus_portal_users', function (Blueprint $table) {
                $table->string('document_number', 50)->nullable()->change();
                $table->string('phone_number', 50)->nullable()->change();
            });
        }

        Schema::table('nimbus_portal_users', function (Blueprint $table) {
            try {
                $table->dropUnique('nimbus_portal_users_doc_hash_unique');
            } catch (Throwable $e) {
            }

            try {
                $table->dropIndex('nimbus_portal_users_phone_hash_index');
            } catch (Throwable $e) {
            }

            // Restore legacy unique if data permits (best-effort).
            try {
                $table->unique('document_number');
            } catch (Throwable $e) {
            }
        });

        Schema::table('nimbus_portal_users', function (Blueprint $table) {
            $table->dropColumn(['document_number_hash', 'phone_number_hash']);
        });
    }
};
