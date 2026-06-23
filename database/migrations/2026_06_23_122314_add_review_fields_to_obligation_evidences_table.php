<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_PENDING = 'pending';

    private const STATUS_APPROVED = 'approved';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('obligation_evidences', function (Blueprint $table): void {
            $table->string('status')->default(self::STATUS_PENDING)->after('description');
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->text('rejection_reason')->nullable()->after('review_notes');

            $table->index('status');
            $table->index('reviewed_at');
        });

        DB::table('obligation_evidences')->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'rejection_reason' => null,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obligation_evidences', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['reviewed_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'status',
                'reviewed_at',
                'review_notes',
                'rejection_reason',
            ]);
        });
    }
};
