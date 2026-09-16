<?php

namespace Tests\Support;

use App\Enums\AccessPermission;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class MeasurementReceiptEvidenceScenario
{
    public static function file(string $name = 'comprovante TED.pdf', string $content = '%PDF-1.7 pagamento controlado'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public static function approveCurrentReceipt(MeasurementPayment $payment, User $reviewer): void
    {
        app(MeasurementReceiptEvidenceService::class)->review(
            $payment->fresh()->currentReceiptEvidence,
            $reviewer,
            MeasurementReceiptReviewStatus::Approved,
            true,
        );
    }

    /** @return array{actor: User, operation: Operation, measurement: Measurement, payment: MeasurementPayment} */
    public static function open(): array
    {
        config()->set('filesystems.private_disk', 'local');
        $actor = User::factory()->withTwoFactor()->create();
        $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.receipts', 'measurements.finalize', 'measurements.review', 'measurements.pay', AccessPermission::MeasurementsCycleReportsView->value]);
        $operation = Operation::factory()->create([
            'status' => 'active',
            'assigned_user_id' => $actor->id,
            'responsible_user_id' => $actor->id,
            'stage2_reviewer_user_id' => $actor->id,
            'stage3_reviewer_user_id' => $actor->id,
            'payment_manager_user_id' => $actor->id,
            'payment_receipt_uploader_user_id' => $actor->id,
            'payment_finalizer_user_id' => $actor->id,
        ]);
        $planSet = MeasurementPlanSet::factory()->default()->create([
            'operation_id' => $operation->id,
            'construction_fund_amount' => '10114801.60',
            'initial_incurred_amount' => 0,
        ]);
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id,
            'plan_set_id' => $planSet->id,
            'sequence_number' => 1,
            'measurement_date' => '2026-05-01',
            'initial_realized_cumulative_percent' => 0,
            'realized_monthly_percent' => 0,
            'realized_cumulative_percent' => 0,
        ]);
        $measurement = Measurement::factory()->create([
            'operation_id' => $operation->id,
            'reference_month' => '2026-05-01',
            'storage_path' => null,
            'filename' => null,
            'status' => 'pending',
            'current_stage' => 1,
            'uploaded_by' => $actor->id,
        ]);
        $path = 'nimbus_docs/measurements/assets/controlled-'.$measurement->id.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.7 medição controlada');
        $measurement->assets()->create(['plan_set_id' => $planSet->id, 'plan_line_id' => $line->id, 'storage_path' => $path, 'storage_disk' => 'local']);
        $workflow = app(MeasurementWorkflow::class);
        $workflow->startReview($measurement, $actor);
        $workflow->approve($measurement->fresh(), $actor, engineeringProgress: [$planSet->id => 10]);
        $workflow->approve($measurement->fresh(), $actor);
        $workflow->approve($measurement->fresh(), $actor);
        $payment = $workflow->registerPayment($measurement->fresh(), $actor, ['plan_set_id' => $planSet->id, 'pay_date' => '2026-05-20', 'amount' => '1011480.16', 'method' => 'TED']);
        $workflow->approve($measurement->fresh(), $actor);

        $measurement->refresh();
        $payment->refresh();

        return compact('actor', 'operation', 'measurement', 'payment');
    }

    /** @return array{actor: User, operation: Operation, measurement: Measurement, payment: MeasurementPayment} */
    public static function legacy(bool $finalized = true): array
    {
        $scenario = self::open();
        $payment = $scenario['payment'];
        $path = 'nimbus_docs/measurements/receipts/legacy-'.$payment->id.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.7 manual de logotipos controlado');
        DB::table('measurement_payments')->where('id', $payment->id)->update([
            'receipt_disk' => 'local', 'receipt_path' => $path,
            'receipt_sha256' => hash('sha256', '%PDF-1.7 manual de logotipos controlado'),
            'receipt_mime_type' => 'application/pdf', 'receipt_size' => strlen('%PDF-1.7 manual de logotipos controlado'),
            'receipt_uploaded_by' => $scenario['actor']->id, 'receipt_uploaded_at' => '2026-09-07 12:30:00',
        ]);

        if ($finalized) {
            $scenario['measurement']->forceFill(['status' => 'finalized', 'current_stage' => 5, 'analyzed_at' => '2026-09-08 15:00:00', 'analyzed_by' => $scenario['actor']->id])->save();
            $scenario['measurement']->reviews()->updateOrCreate(['stage' => 5], ['status' => 'approved', 'reviewer_user_id' => $scenario['actor']->id, 'reviewed_at' => '2026-09-08 15:00:00']);
            activity('measurement_workflow')->performedOn($scenario['measurement'])->causedBy($scenario['actor'])
                ->withProperties(['stage' => 5, 'from_status' => 'approved', 'to_status' => 'finalized', 'operation_id' => $scenario['operation']->id, 'measurement_id' => $scenario['measurement']->id, 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $scenario['actor']->id, 'workflow_revision' => $scenario['measurement']->workflow_revision, 'admin_override' => false, 'delegated' => false])
                ->createdAt(Carbon::parse('2026-09-08 15:00:00'))->log('measurement_finalized');
        }

        self::backfill();
        $scenario['payment']->refresh();
        $scenario['measurement']->refresh();

        return $scenario;
    }

    public static function backfill(): void
    {
        $migration = require database_path('migrations/2026_09_16_172932_create_measurement_payment_receipt_evidences_table.php');
        $migration->up();
    }
}
