<?php

use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementPaymentExportService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    config(['measurements.sla.calendar_code' => null]);

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 5)->create();
    }
});

function p2ExportUser(bool $canExport = true): User
{
    $permissions = ['operations.view', 'measurements.view', 'measurements.pay'];

    if ($canExport) {
        $permissions[] = 'measurements.export';
    }

    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * @param  array<string, mixed>  $measurementAttributes
 */
function p2ExportMeasurement(User $manager, array $measurementAttributes = []): Measurement
{
    $operation = Operation::factory()->create([
        'title' => 'Operação Exportável',
        'payment_manager_user_id' => $manager->getKey(),
    ]);
    $measurement = Measurement::factory()->create(array_merge([
        'operation_id' => $operation->getKey(),
        'reference_month' => '2026-08-01',
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'created_at' => '2026-08-01 09:00:00',
        'updated_at' => '2026-08-25 14:30:00',
    ], $measurementAttributes));
    MeasurementReview::factory()->create([
        'measurement_id' => $measurement->getKey(),
        'stage' => $measurement->current_stage,
        'reviewer_user_id' => $manager->getKey(),
        'status' => 'pending',
        'created_at' => now(),
    ]);

    return $measurement;
}

/** @return Collection<int, array<string, mixed>> */
function p2ReadExport(BinaryFileResponse $response, string $format): Collection
{
    $path = $response->getFile()->getPathname();
    $reader = SimpleExcelReader::create($path, $format);

    if ($format === 'csv') {
        $reader->useDelimiter(';');
    }

    $rows = collect($reader->getRows()->all());
    $reader->close();
    unlink($path);

    return $rows;
}

it('shows export only to users with the explicit permission', function () {
    $authorized = p2ExportUser();
    $unauthorized = p2ExportUser(false);
    p2ExportMeasurement($authorized);
    p2ExportMeasurement($unauthorized);

    $this->actingAs($authorized);
    Livewire\Livewire::test(ListPaymentWorkspace::class)
        ->assertActionVisible('export_payments');

    $this->actingAs($unauthorized);
    Livewire\Livewire::test(ListPaymentWorkspace::class)
        ->assertActionHidden('export_payments');
});

it('rejects export when the actor lacks export permission', function () {
    $actor = p2ExportUser(false);

    expect(fn () => app(MeasurementPaymentExportService::class)->download(
        Measurement::query(),
        $actor,
        'xlsx',
    ))->toThrow(HttpException::class);
});

it('re-intersects the supplied query with the actor visibility scope', function () {
    $actor = p2ExportUser();
    $hiddenManager = p2ExportUser();
    $visible = p2ExportMeasurement($actor);
    $hidden = p2ExportMeasurement($hiddenManager, ['reference_month' => '2026-09-01']);
    MeasurementPayment::factory()->create([
        'operation_id' => $visible->operation_id,
        'measurement_id' => $visible->getKey(),
        'amount' => 1250.75,
        'created_by' => $actor->getKey(),
    ]);
    MeasurementPayment::factory()->create([
        'operation_id' => $hidden->operation_id,
        'measurement_id' => $hidden->getKey(),
        'amount' => 999999,
        'created_by' => $hiddenManager->getKey(),
    ]);

    $rows = p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        Measurement::query(),
        $actor,
        'csv',
    ), 'csv');

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()['Medição'])->toBe($visible->getKey())
        ->and($rows->pluck('Medição')->map(fn ($id): int => (int) $id))->not->toContain($hidden->getKey());
});

it('exports the filtered current view instead of the whole authorized scope', function () {
    $actor = p2ExportUser();
    $august = p2ExportMeasurement($actor, ['reference_month' => '2026-08-01']);
    $september = p2ExportMeasurement($actor, ['reference_month' => '2026-09-01']);

    $query = app(MeasurementOperationalReadModel::class)->paymentQueryFor($actor)
        ->whereDate('reference_month', '2026-08-01');
    $rows = p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        $query,
        $actor,
        'csv',
        ['competence' => '2026-08'],
    ), 'csv');

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()['Medição'])->toBe($august->getKey())
        ->and($rows->pluck('Medição')->map(fn ($id): int => (int) $id))->not->toContain($september->getKey());
});

it('keeps amounts numeric and dates typed in XLSX', function () {
    $actor = p2ExportUser();
    $measurement = p2ExportMeasurement($actor);
    MeasurementPayment::factory()->create([
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'pay_date' => '2026-08-26',
        'amount' => 4321.98,
        'created_by' => $actor->getKey(),
    ]);

    $row = p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)->paymentQueryFor($actor),
        $actor,
        'xlsx',
    ), 'xlsx')->first();

    expect($row['Valor'])->toBeFloat()->toBe(4321.98)
        ->and($row['Data do pagamento'])->toBeInstanceOf(DateTimeInterface::class)
        ->and($row['Competência'])->toBeInstanceOf(DateTimeInterface::class)
        ->and($row['Criado em'])->toBeInstanceOf(DateTimeInterface::class);
});

it('exports current delegation and receipt identities without changing permanent responsibility', function () {
    $manager = p2ExportUser();
    $delegate = p2ExportUser();
    $uploader = p2ExportUser();
    $measurement = p2ExportMeasurement($manager, [
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    $measurement->operation->update(['payment_receipt_uploader_user_id' => $uploader->getKey()]);
    $endsAt = now()->addDays(14);
    ResponsibilityDelegation::factory()->active()->forStage(
        MeasurementWorkflow::STAGE_PAYMENT,
        $measurement->operation,
        MeasurementResponsibility::PaymentManager,
    )->create([
        'delegator_user_id' => $manager->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'ends_at' => $endsAt,
    ]);
    Storage::disk('local')->put('nimbus_docs/measurements/receipts/p2-export.pdf', "%PDF-1.7\nP2\n%%EOF");
    MeasurementPayment::factory()->create([
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'amount' => 900,
        'created_by' => $manager->getKey(),
        'receipt_path' => 'nimbus_docs/measurements/receipts/p2-export.pdf',
        'receipt_disk' => 'local',
        'receipt_uploaded_by' => $uploader->getKey(),
        'receipt_uploaded_at' => now(),
    ]);

    $row = p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)->paymentQueryFor($manager),
        $manager,
        'csv',
    ), 'csv')->first();

    expect($row['Gestor de Pagamento'])->toBe($manager->name)
        ->and($row['Delegações operacionais atuais'])->toContain($delegate->name, $endsAt->format('d/m/Y'))
        ->and($row['Status do comprovante'])->toBe('Anexado')
        ->and($row['Comprovante enviado por'])->toBe($uploader->name);
});

it('exports the formal payment approver when the stage is approved', function () {
    $manager = p2ExportUser();
    $approver = p2ExportUser();
    $measurement = p2ExportMeasurement($manager);
    $measurement->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->update([
        'status' => 'approved',
        'reviewer_user_id' => $approver->getKey(),
        'reviewed_at' => now(),
    ]);
    MeasurementPayment::factory()->create([
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'created_by' => $manager->getKey(),
    ]);

    $row = p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)->paymentQueryFor($manager),
        $manager,
        'csv',
    ), 'csv')->first();

    expect($row['Aprovado por'])->toBe($approver->name);
});

it('excludes storage paths hashes and technical metadata from every format', function (string $format) {
    $actor = p2ExportUser();
    $measurement = p2ExportMeasurement($actor);
    MeasurementPayment::factory()->create([
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'created_by' => $actor->getKey(),
    ]);

    $headers = collect(p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)->paymentQueryFor($actor),
        $actor,
        $format,
    ), $format)->first())->keys();

    expect($headers)->not->toContain(
        'storage_path',
        'receipt_path',
        'sha256',
        'receipt_sha256',
        'storage_disk',
        'receipt_disk',
    );
})->with(['xlsx', 'csv']);

it('sanitizes spreadsheet formula triggers in exported text fields', function (string $format) {
    $actor = p2ExportUser();
    $measurement = p2ExportMeasurement($actor);
    $measurement->operation->forceFill(['title' => '=HYPERLINK("https://invalid")'])->save();

    $row = p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)->paymentQueryFor($actor),
        $actor,
        $format,
    ), $format)->first();

    expect($row['Operação'])->toStartWith("'=");
})->with(['xlsx', 'csv']);

it('crosses multiple lazy export chunks without loading the portfolio at once', function () {
    $actor = p2ExportUser();
    $hiddenManager = p2ExportUser();
    $operation = Operation::factory()->create(['payment_manager_user_id' => $actor->getKey()]);
    $measurements = Measurement::factory()->count(410)->create([
        'operation_id' => $operation->getKey(),
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
    ]);

    foreach ($measurements as $measurement) {
        MeasurementPayment::factory()->create([
            'operation_id' => $operation->getKey(),
            'measurement_id' => $measurement->getKey(),
            'created_by' => $actor->getKey(),
        ]);
    }
    $filteredOut = p2ExportMeasurement($actor, [
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    $hidden = p2ExportMeasurement($hiddenManager);

    $rows = p2ReadExport(app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)
            ->paymentQueryFor($actor)
            ->where('measurements.status', 'awaiting_payment'),
        $actor,
        'csv',
    ), 'csv');
    $exportedMeasurementIds = $rows
        ->pluck('Medição')
        ->map(fn (mixed $id): int => (int) $id);

    expect($rows)->toHaveCount(410)
        ->and($exportedMeasurementIds->unique())->toHaveCount(410)
        ->and($exportedMeasurementIds->first())->toBe((int) $measurements->first()->getKey())
        ->and($exportedMeasurementIds->last())->toBe((int) $measurements->last()->getKey())
        ->and($exportedMeasurementIds)->not->toContain($filteredOut->getKey(), $hidden->getKey());
});

it('audits export generation without persisting its private temporary path', function () {
    $actor = p2ExportUser();
    p2ExportMeasurement($actor);
    $response = app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)->paymentQueryFor($actor),
        $actor,
        'xlsx',
        ['status' => 'awaiting_payment'],
    );
    $activity = Activity::query()->where('description', 'measurement_payments_exported')->firstOrFail();
    unlink($response->getFile()->getPathname());

    expect($activity->properties->get('scope'))->toBe('current_filtered_view')
        ->and($activity->properties->get('measurement_count'))->toBe(1)
        ->and($activity->properties->keys())->not->toContain('path', 'storage_path', 'sha256');
});
