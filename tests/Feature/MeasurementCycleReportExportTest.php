<?php

use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\DTOs\Measurements\MeasurementCycleReportRow;
use App\Enums\AccessPermission;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementCycleReportExportService;
use App\Services\MeasurementCycleReportingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function p3b2ExportActor(bool $canExport = true): User
{
    $permissions = [
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ];

    if ($canExport) {
        $permissions[] = AccessPermission::MeasurementsCycleReportsExport->value;
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

function p3b2ExportRow(int $measurementId = 10, string $operationLabel = 'Operação segura'): MeasurementCycleReportRow
{
    return new MeasurementCycleReportRow(
        measurementId: $measurementId,
        measurementLabel: 'Medição '.$measurementId,
        operationId: 20,
        operationLabel: $operationLabel,
        emissionId: 30,
        emissionLabel: 'Emissão autorizada',
        referenceMonth: '2026-08-01',
        stage: 2,
        sequence: 1,
        enteredAt: CarbonImmutable::parse('2026-08-01 09:00:00'),
        exitedAt: CarbonImmutable::parse('2026-08-01 11:00:00'),
        exitReason: MeasurementStageExitReason::Approved,
        calendarDuration: 7200,
        pausedDuration: 900,
        activeDuration: 6300,
        actorId: 40,
        actorName: '@Actor exportável',
        responsibility: MeasurementResponsibility::ManagementReviewer,
        expectedResponsibleId: 41,
        expectedResponsibleName: '+Responsável esperado',
        delegated: true,
        delegatorId: 42,
        delegatorName: '-Delegante',
        adminOverride: false,
        completeness: MeasurementHistoryCompleteness::Complete,
        missingReasons: [],
    );
}

/** @param list<MeasurementCycleReportRow> $rows */
function p3b2ExportService(array $rows): MeasurementCycleReportExportService
{
    $reporting = Mockery::mock(MeasurementCycleReportingService::class);
    $reporting->shouldReceive('stageVisitRows')
        ->andReturnUsing(function () use ($rows): Generator {
            foreach ($rows as $row) {
                yield $row;
            }
        });

    return new MeasurementCycleReportExportService($reporting);
}

/** @return Collection<int, array<string, mixed>> */
function p3b2ReadExport(BinaryFileResponse $response, string $format): Collection
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

/** @param array<string, mixed> $properties */
function p3b2ExportActivity(
    Measurement $measurement,
    User $actor,
    string $description,
    string $at,
    int $revision,
    array $properties,
): void {
    Activity::query()->create([
        'log_name' => 'measurement_workflow',
        'description' => $description,
        'subject_type' => $measurement->getMorphClass(),
        'subject_id' => $measurement->getKey(),
        'causer_type' => $actor->getMorphClass(),
        'causer_id' => $actor->getKey(),
        'properties' => array_merge([
            'operation_id' => $measurement->operation_id,
            'measurement_id' => $measurement->getKey(),
            'actual_actor_user_id' => $actor->getKey(),
            'expected_responsible_user_id' => $actor->getKey(),
            'delegated' => false,
            'delegation_id' => null,
            'delegator_user_id' => null,
            'delegation_scope' => null,
            'admin_override' => false,
            'workflow_revision' => $revision,
        ], $properties),
        'created_at' => CarbonImmutable::parse($at),
        'updated_at' => CarbonImmutable::parse($at),
    ]);
}

function p3b2ExportCanonicalMeasurement(User $responsible, string $filename): Measurement
{
    $operation = Operation::factory()->create([
        'responsible_user_id' => $responsible->getKey(),
        'stage2_reviewer_user_id' => $responsible->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'filename' => $filename,
        'status' => 'in_review',
        'current_stage' => 2,
    ]);
    p3b2ExportActivity($measurement, $responsible, 'measurement_submitted', '2026-08-01 09:00:00', 1, [
        'stage' => 1,
        'from_status' => 'pending',
        'to_status' => 'in_review',
        'responsibility' => 'responsible_user_id',
    ]);
    p3b2ExportActivity($measurement, $responsible, 'measurement_stage_approved', '2026-08-01 10:00:00', 2, [
        'stage' => 1,
        'from_status' => 'in_review',
        'to_status' => 'in_review',
        'responsibility' => 'responsible_user_id',
    ]);

    return $measurement;
}

it('rejects export without the independent export permission', function () {
    $actor = p3b2ExportActor(canExport: false);

    expect(fn () => p3b2ExportService([])->download(
        $actor,
        new MeasurementCycleReportFilters,
        'csv',
    ))->toThrow(HttpException::class);
});

it('grants the new export permission only to the standard administrative and editor roles', function () {
    $customRole = Role::create(['name' => 'custom-cycle-viewer']);
    $customUser = User::factory()->create();
    $customUser->assignRole($customRole);

    expect(Role::findByName('super-admin')->hasPermissionTo(AccessPermission::MeasurementsCycleReportsExport->value))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo(AccessPermission::MeasurementsCycleReportsExport->value))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo(AccessPermission::MeasurementsCycleReportsExport->value))->toBeTrue()
        ->and($customUser->can(AccessPermission::MeasurementsCycleReportsExport->value))->toBeFalse();
});

it('exports exactly the canonical filtered population after reintersecting current visibility', function () {
    $actor = p3b2ExportActor();
    $hiddenActor = p3b2ExportActor();
    $visible = p3b2ExportCanonicalMeasurement($actor, 'visivel.pdf');
    $hidden = p3b2ExportCanonicalMeasurement($hiddenActor, 'oculta.pdf');
    $filters = MeasurementCycleReportFilters::fromArray([
        'decision_type' => MeasurementStageExitReason::Approved->value,
        'completeness' => MeasurementHistoryCompleteness::Complete->value,
    ]);
    $reporting = app(MeasurementCycleReportingService::class);
    $screenPopulation = collect($reporting->stageVisitRows($actor, $filters));

    $rows = p3b2ReadExport(
        app(MeasurementCycleReportExportService::class)->download($actor, $filters, 'csv'),
        'csv',
    );

    expect($rows)->toHaveCount($screenPopulation->count())->toHaveCount(1)
        ->and($rows->first()['Medição'])->toBe($visible->filename)
        ->and($rows->pluck('Medição'))->not->toContain($hidden->filename);
});

it('supports CSV and XLSX with formula neutralization numeric durations and typed dates', function (string $format) {
    $actor = p3b2ExportActor();
    $row = p3b2ReadExport(
        p3b2ExportService([p3b2ExportRow(operationLabel: '=HYPERLINK("https://invalid")')])
            ->download($actor, new MeasurementCycleReportFilters, $format),
        $format,
    )->first();

    expect($row['Operação'])->toStartWith("'=")
        ->and($row['Actor'])->toStartWith("'@")
        ->and($row['Responsável esperado'])->toStartWith("'+")
        ->and($row['Delegante'])->toStartWith("'-")
        ->and((int) $row['Duração calendário (segundos)'])->toBe(7200)
        ->and((int) $row['Tempo em pausa (segundos)'])->toBe(900)
        ->and((int) $row['Duração líquida (segundos)'])->toBe(6300)
        ->and($row['Cobertura'])->toBe('complete');

    if ($format === 'xlsx') {
        expect($row['Competência'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($row['Entrada'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($row['Saída'])->toBeInstanceOf(DateTimeInterface::class);
    }
})->with(['csv', 'xlsx']);

it('streams a multi chunk sized population without duplication or loss', function () {
    $actor = p3b2ExportActor();
    $expectedIds = range(1, 205);
    $rows = array_map(fn (int $id): MeasurementCycleReportRow => p3b2ExportRow($id), $expectedIds);

    $exported = p3b2ReadExport(
        p3b2ExportService($rows)->download($actor, new MeasurementCycleReportFilters, 'csv'),
        'csv',
    );

    expect($exported)->toHaveCount(205)
        ->and($exported->pluck('Medição')->unique())->toHaveCount(205)
        ->and($exported->first()['Medição'])->toBe('Medição 1')
        ->and($exported->last()['Medição'])->toBe('Medição 205');
});

it('audits sanitized export context without path hash raw metadata or financial aggregates', function () {
    $actor = p3b2ExportActor();
    $filters = MeasurementCycleReportFilters::fromArray([
        'period_from' => '2026-08-01',
        'stage' => 2,
        'completeness' => 'complete',
    ]);
    $response = p3b2ExportService([p3b2ExportRow()])->download($actor, $filters, 'xlsx');
    $activity = Activity::query()
        ->where('log_name', 'measurement_cycle_report_exports')
        ->where('description', 'measurement_cycle_report_exported')
        ->firstOrFail();
    unlink($response->getFile()->getPathname());

    expect($activity->properties->get('actor_id'))->toBe($actor->getKey())
        ->and($activity->properties->get('stage_visit_count'))->toBe(1)
        ->and($activity->properties->get('measurement_count'))->toBe(1)
        ->and($activity->properties->get('filters'))->toMatchArray([
            'period_from' => '2026-08-01',
            'stage' => 2,
            'completeness' => 'complete',
        ])
        ->and($activity->properties->keys())->not->toContain(
            'path',
            'storage_path',
            'sha256',
            'amount',
            'raw_filters',
        );
});

it('uses private no store responses and removes a partial temporary file when generation fails', function () {
    $actor = p3b2ExportActor();
    $success = p3b2ExportService([p3b2ExportRow()])->download(
        $actor,
        new MeasurementCycleReportFilters,
        'csv',
    );

    $cacheControl = $success->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store', 'private')
        ->and($cacheControl)->not->toContain('public')
        ->and($success->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    unlink($success->getFile()->getPathname());

    $reporting = Mockery::mock(MeasurementCycleReportingService::class);
    $reporting->shouldReceive('stageVisitRows')->andReturnUsing(function (): Generator {
        yield p3b2ExportRow();
        throw new RuntimeException('Falha controlada durante streaming.');
    });

    expect(fn () => (new MeasurementCycleReportExportService($reporting))->download(
        $actor,
        new MeasurementCycleReportFilters,
        'xlsx',
    ))->toThrow(RuntimeException::class);

    expect(Storage::disk('local')->allFiles('exports/measurement-cycle-reports'))->toBe([]);
});
