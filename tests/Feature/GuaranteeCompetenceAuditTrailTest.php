<?php

use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\SalesBoard;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\PermissionRegistrar;

/*
 * A trilha das garantias por competência sobrevive à janela descartável do
 * Activitylog: a confirmação do fechamento parcial, o motivo da reabertura e o
 * valor manual são a evidência de um número que já saiu em relatório.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Os models de garantias que gravam trilha, descobertos na pasta e não listados
 * à mão: um model novo que ganhe `LogsActivity` entra na checagem de política
 * sem que alguém precise lembrar de acrescentá-lo aqui.
 *
 * @return list<class-string>
 */
function guaranteeAuditedModels(): array
{
    $models = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Models/Guarantee*.php') ?: [] as $file) {
        $model = 'App\\Models\\'.basename($file, '.php');

        if (class_exists($model) && in_array(LogsActivity::class, class_uses_recursive($model), true)) {
            $models[] = $model;
        }
    }

    sort($models);

    return $models;
}

/**
 * O arquivo da migração que reclassifica a trilha antiga. O nome tem carimbo
 * de data gerado na criação, então ele é achado pelo sufixo.
 */
function guaranteeAuditTrailMigrationPath(): string
{
    $files = glob(database_path('migrations/*_move_guarantee_audit_trail_to_protected_logs.php')) ?: [];

    expect($files)->toHaveCount(1);

    return $files[0];
}

it('files every audited guarantee model under a protected log', function (string $model) {
    expect(config('audit.protected_logs'))->toContain((new $model)->getActivitylogOptions()->logName);
})->with(fn (): array => guaranteeAuditedModels());

it('finds the audited guarantee models by scanning the models folder', function () {
    // Sem esta âncora, uma varredura que voltasse vazia deixaria a checagem de
    // política verde sem conferir model nenhum.
    expect(guaranteeAuditedModels())->toContain(Guarantee::class, GuaranteeSnapshot::class)
        ->and((new Guarantee)->getActivitylogOptions()->logName)->toBe('guarantees')
        ->and((new GuaranteeSnapshot)->getActivitylogOptions()->logName)->toBe('guarantee_competences');
});

it('keeps the closing with partial confirmation, the reopening with its reason and the manual value past the disposable window', function () {
    Carbon::setTestNow('2026-09-15 10:00:00');
    $admin = makeAdminUser();
    $this->actingAs($admin);

    $emission = Emission::factory()->create(['issued_quantity' => 1000000, 'status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-06-01',
        'quantity' => 1000,
        'unit_value' => 1,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-08-31', 'unit_value' => 8000]);

    // Só o quadro de julho: agosto fecha com a posição transportada, confirmada.
    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'stock_value' => 10_000_000,
    ]);

    $stock = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'construction_id' => $construction->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $quotas = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $writer = app(GuaranteeSnapshotWriter::class);

    $writer->recordManualValue($quotas, '08/2026', 2_000_000, $admin);
    $writer->close(
        $emission,
        '2026-08-01',
        $admin,
        app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission->fresh(), '2026-08-01')->salesBoardGapsFingerprint(),
    );
    $writer->reopen($emission, '2026-08-01', $admin, 'Quadro de agosto chegou depois do fechamento.');

    $stock->update(['requirement_percentage' => 1.3]);

    // Um registro genérico da mesma idade: prova que o expurgo rodou de verdade.
    $generic = Activity::query()->create([
        'log_name' => 'default',
        'description' => 'registro genérico',
        'properties' => json_encode([]),
    ]);

    $guaranteeTrail = fn () => Activity::query()->whereIn('subject_type', [Guarantee::class, GuaranteeSnapshot::class]);
    $recorded = $guaranteeTrail()->count();

    DB::table('activity_log')->update([
        'created_at' => now()->subDays((int) config('audit.retention_disposable_days', 365) + 1),
    ]);

    $this->artisan('audit:clean-filtered')->assertExitCode(0);

    $closing = Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_CLOSED)->sole();
    $reopening = Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_REOPENED)->sole();
    $manualValue = Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_VALUE_UPDATED)->sole();
    $ruleChange = Activity::query()
        ->where('subject_type', Guarantee::class)
        ->where('subject_id', $stock->id)
        ->where('description', 'updated')
        ->sole();

    expect(Activity::query()->whereKey($generic->getKey())->exists())->toBeFalse()
        ->and($guaranteeTrail()->count())->toBe($recorded)
        ->and($guaranteeTrail()->where('log_name', 'default')->exists())->toBeFalse()
        ->and($closing->properties['partial_coverage_confirmation']['gaps'][0]['construction_id'])->toBe($construction->id)
        ->and($closing->causer_id)->toBe($admin->id)
        ->and($reopening->properties['reason'])->toBe('Quadro de agosto chegou depois do fechamento.')
        ->and($reopening->causer_id)->toBe($admin->id)
        ->and($manualValue->properties['new_value'])->toEqual(2_000_000)
        ->and($ruleChange->log_name)->toBe('guarantees')
        ->and(data_get($ruleChange->attribute_changes, 'attributes.requirement_percentage'))->not->toBeNull();
});

it('moves the guarantee trail left in the default log to the protected categories, leaves other subjects in default and is idempotent', function () {
    $emission = Emission::factory()->create(['status' => 'active']);
    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);
    $snapshot = GuaranteeSnapshot::factory()->create(['emission_id' => $emission->id, 'reference_month' => '2026-08-01']);

    // Trilha gravada antes de os models declararem a categoria.
    $legacyRow = fn (string $subjectType, int $subjectId): int => DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'updated',
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'properties' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotRow = $legacyRow('App\\Models\\GuaranteeSnapshot', $snapshot->id);
    $guaranteeRow = $legacyRow('App\\Models\\Guarantee', $guarantee->id);
    $otherRow = $legacyRow('App\\Models\\Fund', 1);

    $logNameOf = fn (int $id): ?string => DB::table('activity_log')->where('id', $id)->value('log_name');

    $migration = require guaranteeAuditTrailMigrationPath();
    $migration->up();

    expect($logNameOf($snapshotRow))->toBe('guarantee_competences')
        ->and($logNameOf($guaranteeRow))->toBe('guarantees')
        ->and($logNameOf($otherRow))->toBe('default');

    $before = DB::table('activity_log')->orderBy('id')->get(['id', 'log_name', 'updated_at'])->toArray();

    $migration->up();

    expect(DB::table('activity_log')->orderBy('id')->get(['id', 'log_name', 'updated_at'])->toArray())->toEqual($before);
});
