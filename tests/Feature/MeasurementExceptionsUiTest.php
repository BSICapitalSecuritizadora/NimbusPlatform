<?php

use App\Enums\AccessPermission;
use App\Enums\MeasurementOperationalExceptionType;
use App\Filament\Resources\MeasurementExceptions\Pages\ListMeasurementExceptions;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\SlaConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-31 12:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 5)->create();
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function moeUiUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo([
        AccessPermission::OperationsView->value,
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsExceptionsView->value,
        AccessPermission::MeasurementsReview->value,
        AccessPermission::MeasurementsPay->value,
        AccessPermission::MeasurementsReceipts->value,
        AccessPermission::MeasurementsFinalize->value,
    ]);

    return $user;
}

function moeUiOperation(User $viewer, array $attributes = []): Operation
{
    return Operation::factory()->create(array_merge([
        'assigned_user_id' => $viewer->getKey(),
        'responsible_user_id' => $viewer->getKey(),
        'stage2_reviewer_user_id' => $viewer->getKey(),
        'stage3_reviewer_user_id' => $viewer->getKey(),
        'payment_manager_user_id' => $viewer->getKey(),
        'payment_receipt_uploader_user_id' => $viewer->getKey(),
        'payment_finalizer_user_id' => $viewer->getKey(),
    ], $attributes));
}

function moeUiMeasurement(Operation $operation, array $attributes = []): Measurement
{
    $measurement = Measurement::factory()->create(array_merge([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
        'reference_month' => '2026-08-01',
    ], $attributes));

    $measurement->reviews()->create([
        'stage' => $measurement->current_stage,
        'status' => 'pending',
        'created_at' => now()->subDay(),
    ]);

    return $measurement;
}

it('renders the operational exceptions page with institutional cards and month pickers', function (): void {
    $actor = moeUiUser();
    $this->actingAs($actor);

    Livewire::test(ListMeasurementExceptions::class)
        ->assertOk()
        ->assertDontSeeHtml('type="date"')
        ->assertSeeHtml('bsi-month-picker')
        ->assertSeeHtml('id="exceptionCompetenceFrom"')
        ->assertSeeHtml('id="exceptionCompetenceTo"')
        ->assertSeeHtml('moe-card')
        ->assertSeeHtml('moe-kpis-grid')
        ->assertSee('Resumo da população filtrada')
        ->assertSee('Filtros')
        ->assertSee('Exceções identificadas')
        ->assertSee('Competência inicial')
        ->assertSee('Competência final')
        ->assertSee('Limpar filtros');
});

it('displays the compact KPI matrix with total and every exception type', function (): void {
    $actor = moeUiUser();
    $operation = moeUiOperation($actor, ['responsible_user_id' => null]);
    moeUiMeasurement($operation);
    $this->actingAs($actor);

    Livewire::test(ListMeasurementExceptions::class)
        ->assertOk()
        ->assertSeeHtml('moe-kpi-total')
        ->assertSeeHtml('moe-kpi-value--total')
        ->assertSee('Total')
        ->assertSee('Responsável da etapa ausente')
        ->assertSee('Gestor de pagamento ausente')
        ->assertSee('Uploader de comprovante ausente')
        ->assertSee('Finalizador ausente')
        ->assertSee('SLA não configurado')
        ->assertSee('Configuração de SLA inválida');
});

it('normalizes month picker YYYY-MM values to inclusive competence range', function (): void {
    $actor = moeUiUser();
    $emission = Emission::factory()->create(['name' => 'Emissão Alpha']);
    $operation = moeUiOperation($actor, [
        'emission_id' => $emission->getKey(),
        'responsible_user_id' => null,
    ]);

    // August measurement (matching)
    $matching = moeUiMeasurement($operation, ['reference_month' => '2026-08-15']);
    // July measurement (out of range)
    moeUiMeasurement($operation, ['reference_month' => '2026-07-15']);

    $this->actingAs($actor);

    $component = Livewire::test(ListMeasurementExceptions::class)
        ->set('competenceFrom', '2026-08')
        ->set('competenceTo', '2026-08');

    $result = $component->instance()->result();

    expect($result->total)->toBe(1)
        ->and($result->items[0]->measurement->is($matching))->toBeTrue();
});

it('renders the refined compact empty state when no exceptions match', function (): void {
    $actor = moeUiUser();
    $operation = moeUiOperation($actor);
    moeUiMeasurement($operation); // no exceptions
    $this->actingAs($actor);

    Livewire::test(ListMeasurementExceptions::class)
        ->assertOk()
        ->assertSeeHtml('moe-empty-state')
        ->assertSee('Nenhuma exceção operacional encontrada')
        ->assertSee('Os filtros atuais não identificaram impedimentos estruturais');
});

it('clears exception drill down when clicking total or calling clearExceptionFilter', function (): void {
    $actor = moeUiUser();
    $operation = moeUiOperation($actor, ['responsible_user_id' => null]);
    moeUiMeasurement($operation);
    $this->actingAs($actor);

    Livewire::test(ListMeasurementExceptions::class)
        ->call('filterByException', MeasurementOperationalExceptionType::MissingCurrentStageResponsible->value)
        ->assertSet('exceptionType', MeasurementOperationalExceptionType::MissingCurrentStageResponsible->value)
        ->call('clearExceptionFilter')
        ->assertSet('exceptionType', '');
});
