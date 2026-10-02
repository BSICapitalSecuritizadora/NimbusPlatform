<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardAutomationClosureReason;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Filament\Resources\SalesBoardAutomationTargets\Pages\ListSalesBoardAutomationTargets;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardRollouts\Pages\ListSalesBoardRollouts;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardAutomationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * A Emissão liquidada sai do perímetro da automação.
 *
 * Não existe data de liquidação: o status "Liquidada" é o fato operacional, e é
 * reversível. Pelo perímetro, tudo para junto -- a descoberta não cria alvo
 * novo, os abertos são encerrados com motivo próprio, os lembretes param e a
 * Gestão é avisada uma vez -- e volta junto quando a liquidação é desfeita. Os
 * atos humanos continuam: congelar a competência coberta é o caminho para
 * fechar a última de uma operação encerrada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    AutomationFixture::disable();
    Notification::fake();
});

function runLiquidationAutomationOn(string $day): void
{
    test()->travelTo(CarbonImmutable::parse($day.' 13:00:00'));

    app(SalesBoardAutomationService::class)->run(asOf: CarbonImmutable::parse($day));
}

/**
 * Uma Emissão homologada e ativada de verdade, com os dois papéis cobertos e o
 * interruptor global ligado.
 *
 * @return array{emission: Emission, constructions: list<Construction>, people: array{operational: User, management: User}}
 */
function liquidationActivatedEmission(): array
{
    $scenario = RolloutFixture::emission(1, 'L');
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    // Quem abre e prepara não atesta, não aprova e não ativa.
    $opener = GovernanceFixture::operator();
    $approver = GovernanceFixture::approver();
    $homologation = RolloutFixture::open($scenario['emission'], $opener);
    $people = RolloutFixture::recipients($scenario['emission'], $opener);
    RolloutFixture::reviewImpacts($homologation, $approver);
    RolloutFixture::approve($homologation, $approver);
    RolloutFixture::activate($scenario['emission'], $homologation, $approver);
    RolloutFixture::enableGlobalAutomation();

    return [...$scenario, 'people' => $people];
}

/**
 * Uma unidade sem valor: a competência do empreendimento fica bloqueada.
 */
function liquidationBlockConstruction(Construction $construction): void
{
    ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => '01', 'unit' => '950',
        'base_value' => null, 'base_value_reference_date' => null,
    ]);
}

function setLiquidationStatus(Emission $emission, string $status = Emission::STATUS_LIQUIDATED): void
{
    $emission->forceFill(['status' => $status])->save();
}

it('stops discovering competences once the emission is liquidated and closes the open targets with their own reason', function () {
    $scenario = liquidationActivatedEmission();
    liquidationBlockConstruction($scenario['constructions'][0]);

    runLiquidationAutomationOn('2026-09-13');

    expect(SalesBoardAutomationTarget::query()->sole()->status)->toBe(SalesBoardAutomationTargetStatus::Blocked);

    setLiquidationStatus($scenario['emission']);

    runLiquidationAutomationOn('2026-10-13');

    $target = SalesBoardAutomationTarget::query()->sole();

    // 09/2026 venceu em 13/10, mas a Emissão encerrada não tem competência a
    // automatizar: nenhum alvo novo. O de 08/2026 é encerrado, sem autor --
    // foi a automação que parou, não uma pessoa.
    expect($target->reference_month->format('Y-m'))->toBe('2026-08')
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Closed)
        ->and($target->closure_reason)->toBe(SalesBoardAutomationClosureReason::EmissionLiquidated)
        ->and($target->closure_message)->toContain('A Emissão foi liquidada')
        ->and($target->closed_by_user_id)->toBeNull()
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('announces the stop once to the management recipients and never as a scope suspension', function () {
    $scenario = liquidationActivatedEmission();
    liquidationBlockConstruction($scenario['constructions'][0]);
    setLiquidationStatus($scenario['emission']);

    runLiquidationAutomationOn('2026-09-13');
    runLiquidationAutomationOn('2026-09-14');

    $alerts = SalesBoardAutomationAlert::query()
        ->where('alert_type', SalesBoardAutomationAlertType::EmissionLiquidated)
        ->orderBy('channel')
        ->get();

    expect($alerts->pluck('channel')->all())->toBe(['database', 'mail'])
        ->and($alerts->pluck('recipient_user_id')->unique()->values()->all())->toBe([$scenario['people']['management']->id])
        ->and($alerts->pluck('emission_id')->unique()->values()->all())->toBe([$scenario['emission']->id])
        ->and(SalesBoardAutomationAlert::query()->where('alert_type', SalesBoardAutomationAlertType::ScopeSuspended)->count())->toBe(0);
});

it('stops reminding about the cycles of a liquidated emission', function () {
    $scenario = liquidationActivatedEmission();

    runLiquidationAutomationOn('2026-09-13');

    $cycle = SalesBoardCycle::query()->sole();
    $readyAlerts = fn (): int => SalesBoardAutomationAlert::query()
        ->where('alert_type', SalesBoardAutomationAlertType::ReadyForBuilder)
        ->count();

    // Com a política padrão, o ciclo gerado agora é anunciado no mesmo dia.
    expect($cycle->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and($readyAlerts())->toBe(2);

    setLiquidationStatus($scenario['emission']);

    runLiquidationAutomationOn('2026-09-14');

    // Outro dia, outra janela: sem a liquidação o anúncio sairia de novo.
    expect($readyAlerts())->toBe(2)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Generated);
});

it('resumes the automation when the liquidation is undone', function () {
    $scenario = liquidationActivatedEmission();
    liquidationBlockConstruction($scenario['constructions'][0]);

    runLiquidationAutomationOn('2026-09-13');
    setLiquidationStatus($scenario['emission']);
    runLiquidationAutomationOn('2026-10-13');

    expect(SalesBoardAutomationTarget::query()->sole()->status)->toBe(SalesBoardAutomationTargetStatus::Closed);

    setLiquidationStatus($scenario['emission'], 'active');

    runLiquidationAutomationOn('2026-10-14');

    $targets = SalesBoardAutomationTarget::query()->orderBy('reference_month')->get();

    expect($targets->map(fn (SalesBoardAutomationTarget $target): string => $target->reference_month->format('Y-m').'@'.$target->status->value)->all())
        ->toBe(['2026-08@bloqueado', '2026-09@bloqueado']);
});

it('keeps the manual freeze available for a covered competence of a liquidated emission', function () {
    [$construction] = CycleFixture::readyConstruction(status: Emission::STATUS_LIQUIDATED);

    $result = CycleFixture::generate($construction, '2026-07-01');

    expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Generated)
        ->and(SalesBoardCycle::query()->sole()->status)->toBe(SalesBoardCycleStatus::Generated);
});

it('flags a liquidated emission in the freeze action options', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    [$liquidated] = CycleFixture::readyConstruction(status: Emission::STATUS_LIQUIDATED);
    [$operating] = CycleFixture::readyConstruction();

    Livewire::test(ListSalesBoardCycles::class)
        ->mountAction(TestAction::make('generateCycle'))
        ->assertFormFieldExists('construction_id', fn (Select $field): bool => str_ends_with($field->getOptions()[$liquidated->id], ' · Emissão liquidada')
            && ! str_contains($field->getOptions()[$operating->id], 'liquidada'));
});

it('tells on the rollout and automation screens that the automation stopped', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    $scenario = liquidationActivatedEmission();
    setLiquidationStatus($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Esta Emissão está liquidada: a automação parou de gerar competências e de enviar lembretes para ela.')
        ->assertSee('A Emissão está liquidada: a automação parou.')
        ->assertSee('Para registrar o fim do rollout, use “Retornar ao modo legado”.');

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('1 Emissão(ões) liquidada(s) no modo automatizado: a automação parou de gerar competências para ela(s) e encerrou as pendentes.');

    Livewire::test(ListSalesBoardRollouts::class)
        ->assertOk()
        ->assertSee('Automação encerrada (Emissão liquidada)');
});
