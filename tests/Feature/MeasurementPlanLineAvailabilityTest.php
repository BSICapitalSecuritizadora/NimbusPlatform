<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Schemas\MeasurementForm;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use App\Services\OperationNextMeasurementResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Opções do seletor "Medição do cronograma" exatamente como o formulário as
 * monta, sem renderizar a tela.
 *
 * @return array<int, string>
 */
function planLineAvailabilityOptions(MeasurementPlanSet $planSet, ?MeasurementAsset $asset = null): array
{
    $probe = new class extends MeasurementForm
    {
        /**
         * @return array<int, string>
         */
        public static function schedules(mixed $planSetId, ?MeasurementAsset $asset): array
        {
            return parent::scheduleOptionsForPlanSet($planSetId, $asset);
        }
    };

    return $probe::schedules($planSet->getKey(), $asset);
}

/**
 * Medição recusada antes da guarda do P1-01 que já recebeu pagamento: estado
 * legado, que o fluxo atual não produz mais, montado direto no banco.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function planLineAvailabilityPaidRefusal(array $scenario, string $month): Measurement
{
    $line = $scenario['lines'][$month];
    $refused = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => $line->measurement_date->toDateString(),
        'status' => 'rejected',
        'filename' => null,
        'storage_path' => null,
    ]);
    $path = "nimbus_docs/measurements/assets/paid-refusal-{$refused->id}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.7 recusada com pagamento {$refused->id}");
    $refused->assets()->create([
        'plan_set_id' => $scenario['planSet']->id,
        'plan_line_id' => $line->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);
    MeasurementPayment::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'measurement_id' => $refused->id,
        'plan_set_id' => $scenario['planSet']->id,
    ]);

    return $refused;
}

it('offers only the schedule lines the Engineering can still accept', function () {
    $scenario = Scenario::plan(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09']);
    Scenario::measured($scenario, '2026-05', 10);
    Scenario::measurement($scenario, '2026-06');
    $refused = Scenario::measured($scenario, '2026-07', 5);
    Scenario::returnToEngineering($scenario, $refused);
    app(MeasurementWorkflow::class)->reject($refused->fresh(), $scenario['actor'], 'Medição recusada pela Engenharia.');
    planLineAvailabilityPaidRefusal($scenario, '2026-09');
    $this->actingAs($scenario['actor']);

    expect($refused->fresh()->status)->toBe('rejected')
        ->and(array_keys(planLineAvailabilityOptions($scenario['planSet'])))->toBe([
            $scenario['lines']['2026-07']->id,
            $scenario['lines']['2026-08']->id,
        ]);
});

it('keeps listing the line already saved in the file being edited', function () {
    $scenario = Scenario::plan();
    $open = Scenario::measurement($scenario, '2026-06');

    // A linha já é ocupada pela medição aberta: um segundo envio para ela é
    // recusado (`line_claim_key` é única) e não deixa nada gravado.
    expect(fn () => DB::transaction(fn () => Scenario::measurement($scenario, '2026-06')))->toThrow(
        MeasurementWorkflowException::class,
        "já está ocupada pela medição #{$open->id}",
    );

    // A corrida das duas abas anterior à ocupação única deixou duas medições
    // com arquivo na mesma linha, e a migration ficou com a ocupação da mais
    // antiga. O estado legado, que o envio não produz mais, é montado direto
    // no banco: a outra medição continua aberta na linha, sem ocupação.
    $duplicate = Scenario::measurement($scenario, '2026-07');
    DB::table('measurement_assets')->where('measurement_id', $duplicate->id)->update([
        'plan_line_id' => $scenario['lines']['2026-06']->id,
        'line_claim_key' => null,
    ]);
    DB::table('measurements')->where('id', $duplicate->id)->update(['reference_month' => '2026-06-01']);
    $asset = $open->assets()->sole();
    $this->actingAs($scenario['actor']);

    expect(array_keys(planLineAvailabilityOptions($scenario['planSet'], $asset)))->toBe([
        $scenario['lines']['2026-05']->id,
        $scenario['lines']['2026-06']->id,
        $scenario['lines']['2026-07']->id,
    ])
        ->and(array_keys(planLineAvailabilityOptions($scenario['planSet'])))->toBe([
            $scenario['lines']['2026-05']->id,
            $scenario['lines']['2026-07']->id,
        ]);
});

it('does not let the files of the measurement being edited hold its own schedule line', function () {
    $scenario = Scenario::plan();
    $open = Scenario::measurement($scenario, '2026-06');
    $other = Scenario::measurement($scenario, '2026-07');
    $available = fn (?int $measurementId): bool => MeasurementPlanLine::query()
        ->whereKey($scenario['lines']['2026-06']->id)
        ->availableForMeasurement($measurementId)
        ->exists();

    expect($available(null))->toBeFalse()
        ->and($available($other->id))->toBeFalse()
        ->and($available($open->id))->toBeTrue();
});

it('keeps offering a competence covered by the initial physical progress for a month without progress', function () {
    $scenario = Scenario::plan(['2026-05', '2026-06'], initialPercent: '35.00', referenceDate: '2026-05-31');
    $this->actingAs($scenario['actor']);

    expect(array_keys(planLineAvailabilityOptions($scenario['planSet'])))->toBe([
        $scenario['lines']['2026-05']->id,
        $scenario['lines']['2026-06']->id,
    ]);
});

it('refuses on submit a schedule line consumed after the form was opened', function () {
    $scenario = Scenario::plan();
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $scenario['operation']->id]);
    $key = array_key_first($component->get('data.assets'));

    Scenario::measured($scenario, '2026-05', 10);

    $component->fillForm([
        'reference_month' => '2026-05-01',
        'assets' => [$key => [
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-05']->id,
            'storage_path' => [UploadedFile::fake()->createWithContent('medicao.pdf', '%PDF-1.7 competência já medida')],
        ]],
    ])->call('create')
        ->assertHasFormErrors(["assets.{$key}.plan_line_id"]);

    expect($scenario['operation']->measurements()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toHaveCount(1);
});

it('keeps the saved line selectable and hides lines held by other measurements when editing', function () {
    $scenario = Scenario::plan();
    $open = Scenario::measurement($scenario, '2026-06');
    Scenario::measurement($scenario, '2026-07');
    $asset = $open->assets()->sole();
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $open->getRouteKey()]);
    $key = array_key_first($page->get('data.assets'));
    $select = $page->instance()->getSchema('form')->getComponentByStatePath("assets.{$key}.plan_line_id");

    expect($select)->toBeInstanceOf(Select::class)
        ->and(array_keys($select->getOptions()))->toBe([
            $scenario['lines']['2026-05']->id,
            $scenario['lines']['2026-06']->id,
        ]);

    $page->call('save')->assertHasNoFormErrors();

    expect($asset->fresh()->plan_line_id)->toBe($scenario['lines']['2026-06']->id);
});

/**
 * A cláusula "presa a pagamento" só protege enquanto o arquivo pago continua
 * na linha. A recusada legada com pagamento ainda abre no Editar, e trocar a
 * linha do arquivo liberava a competência paga para uma nova medição.
 */
it('keeps a refused paid measurement on its schedule line, so the paid competence is not offered again', function () {
    $scenario = Scenario::plan();
    $refused = planLineAvailabilityPaidRefusal($scenario, '2026-05');
    $refused->reviews()->create(['stage' => 1, 'status' => 'rejected']);
    $asset = $refused->assets()->sole();
    $paidLine = $scenario['lines']['2026-05'];
    $this->actingAs($scenario['actor']);

    expect(fn () => $asset->fresh()->update(['plan_line_id' => $scenario['lines']['2026-07']->id]))->toThrow(
        MeasurementWorkflowException::class,
        'A obra e a linha do cronograma de uma medição com pagamento registrado não podem ser alteradas: o pagamento continua vinculado a elas.',
    );

    Livewire::test(EditMeasurement::class, ['record' => $refused->getRouteKey()])
        ->set("data.assets.record-{$asset->id}.plan_line_id", $scenario['lines']['2026-07']->id)
        ->call('save');

    expect($asset->fresh()->plan_line_id)->toBe($paidLine->id)
        ->and(MeasurementPlanLine::query()->whereKey($paidLine->id)->availableForMeasurement()->exists())->toBeFalse()
        ->and(array_keys(planLineAvailabilityOptions($scenario['planSet'])))->not->toContain($paidLine->id);
});

it('reprocesses a refused competence once and keeps the previous approval in the history', function () {
    $scenario = Scenario::plan();
    $line = $scenario['lines']['2026-05'];
    $first = Scenario::measured($scenario, '2026-05', 10);
    Scenario::returnToEngineering($scenario, $first);
    app(MeasurementWorkflow::class)->reject($first->fresh(), $scenario['actor'], 'Medição recusada pela Engenharia.');

    $second = Scenario::measurement($scenario, '2026-05');
    Scenario::approveEngineering($scenario, $second, 4);

    $progress = Scenario::progress($scenario);
    $lineHistory = Activity::query()
        ->where('log_name', 'measurements')
        ->where('subject_type', $line->getMorphClass())
        ->where('subject_id', $line->id)
        ->latest('id')
        ->first();
    $invalidation = Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_id', $first->id)
        ->where('description', 'measurement_engineering_snapshot_invalidated')
        ->latest('id')
        ->first();
    $this->actingAs($scenario['actor']);

    expect($progress->currentPercent())->toBe('4.00')
        ->and($progress->contributions)->toHaveCount(1)
        ->and($progress->contributions[0]->measurementId)->toBe($second->id)
        ->and($progress->lineClaimant($line->id))->toBe($second->id)
        ->and($line->fresh()->measurement_id)->toBe($second->id)
        ->and($line->fresh()->realized_monthly_percent)->toBe('4.00')
        ->and($lineHistory?->attribute_changes['old']['measurement_id'] ?? null)->toBe($first->id)
        ->and($lineHistory?->attribute_changes['old']['realized_monthly_percent'] ?? null)->toBe('10.00')
        ->and(data_get($invalidation?->properties, 'engineering_snapshot.plan_sets.0.plan_line_id'))->toBe($line->id)
        ->and($first->assets()->sole()->plan_line_id)->toBe($line->id)
        ->and($first->fresh()->reviewForStage(1)?->status)->toBe('rejected')
        ->and(app(OperationNextMeasurementResolver::class)
            ->addNextMeasurementDate($scenario['operation']->newQuery())
            ->findOrFail($scenario['operation']->id)
            ->next_pending_measurement_at?->format('m/Y'))->toBe('06/2026')
        ->and(array_keys(planLineAvailabilityOptions($scenario['planSet'])))->not->toContain($line->id);
});
