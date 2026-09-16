<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('measurement_payment_receipt_evidences')) {
            Schema::create('measurement_payment_receipt_evidences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('measurement_payment_id')->constrained('measurement_payments', indexName: 'receipt_evidence_payment_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->foreignId('supersedes_id')->nullable()->unique('receipt_evidence_predecessor_unique')
                    ->constrained('measurement_payment_receipt_evidences', indexName: 'receipt_evidence_predecessor_fk')->restrictOnDelete();
                $table->string('storage_disk')->nullable();
                $table->string('storage_path', 500);
                $table->string('original_filename')->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->char('sha256', 64)->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users', indexName: 'receipt_evidence_uploader_fk')->restrictOnDelete();
                $table->timestamp('uploaded_at')->nullable()->index('receipt_evidence_uploaded_index');
                $table->string('review_status', 32)->default('pending')->index('receipt_evidence_review_index');
                $table->foreignId('reviewer_user_id')->nullable()->constrained('users', indexName: 'receipt_evidence_reviewer_fk')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('correction_reason')->nullable();
                $table->boolean('is_post_finalization')->default(false);
                $table->timestamps();
                $table->unique(['measurement_payment_id', 'version'], 'receipt_evidence_payment_version_unique');
            });
        }

        DB::table('measurement_payments')
            ->whereNotNull('receipt_path')->where('receipt_path', '!=', '')
            ->orderBy('id')->chunkById(200, function ($payments): void {
                foreach ($payments as $payment) {
                    DB::transaction(function () use ($payment): void {
                        $measurement = DB::table('measurements')->where('id', $payment->measurement_id)->lockForUpdate()->first();
                        $currentPayment = DB::table('measurement_payments')->where('id', $payment->id)->lockForUpdate()->first();

                        if ($measurement === null || $currentPayment === null || blank($currentPayment->receipt_path)
                            || DB::table('measurement_payment_receipt_evidences')->where('measurement_payment_id', $payment->id)->exists()) {
                            return;
                        }

                        DB::table('measurement_payment_receipt_evidences')->insert([
                            'measurement_payment_id' => $currentPayment->id,
                            'version' => 1,
                            'storage_disk' => $currentPayment->receipt_disk,
                            'storage_path' => $currentPayment->receipt_path,
                            'original_filename' => null,
                            'mime_type' => $currentPayment->receipt_mime_type,
                            'size' => $currentPayment->receipt_size,
                            'sha256' => $currentPayment->receipt_sha256,
                            'uploaded_by' => $currentPayment->receipt_uploaded_by,
                            'uploaded_at' => $currentPayment->receipt_uploaded_at,
                            'review_status' => $measurement->status === 'finalized' ? 'legacy_unreviewed' : 'pending',
                            'is_post_finalization' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
                }
            });
    }

    /**
     * Evidências e decisões não podem ser descartadas por rollback. Correções de
     * schema devem ser feitas por uma migration posterior que preserve os dados.
     */
    public function down(): void
    {
        throw new RuntimeException('Esta migration preserva evidências permanentemente e não admite rollback destrutivo.');
    }
};
