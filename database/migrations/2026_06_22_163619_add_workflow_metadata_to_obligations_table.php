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
        Schema::table('obligations', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('notes');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable()->after('completed_by');

            $table->timestamp('submitted_for_review_at')->nullable()->after('completion_notes');
            $table->foreignId('submitted_for_review_by')->nullable()->after('submitted_for_review_at')->constrained('users')->nullOnDelete();
            $table->text('review_submission_notes')->nullable()->after('submitted_for_review_by');

            $table->timestamp('not_applicable_at')->nullable()->after('review_submission_notes');
            $table->foreignId('not_applicable_by')->nullable()->after('not_applicable_at')->constrained('users')->nullOnDelete();
            $table->text('not_applicable_reason')->nullable()->after('not_applicable_by');

            $table->timestamp('reopened_at')->nullable()->after('not_applicable_reason');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable()->after('reopened_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obligations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['reopened_at', 'reopen_reason']);

            $table->dropConstrainedForeignId('not_applicable_by');
            $table->dropColumn(['not_applicable_at', 'not_applicable_reason']);

            $table->dropConstrainedForeignId('submitted_for_review_by');
            $table->dropColumn(['submitted_for_review_at', 'review_submission_notes']);

            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['completed_at', 'completion_notes']);
        });
    }
};
