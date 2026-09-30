<?php

use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Services\SalesBoards\ConstructionUnitExchangeService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * Permuta com a operação em curso: "Registrar permuta extraordinária" e
 * "Encerrar permuta".
 *
 * Decisão da Gestão: motivo obrigatório, autor, auditoria e a permissão de
 * aprovação do Quadro de Vendas.
 */
uses(RefreshDatabase::class);

const EXTRAORDINARY_REASON = 'Permuta acertada com a construtora em aditivo ao contrato de obra.';

function liveUnit(string $status = 'active'): ConstructionUnit
{
    $emission = Emission::factory()->create(['status' => $status]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    return DerivationFixture::unit($construction, '101');
}

function registerExtraordinaryExchange(
    ConstructionUnit $unit,
    string $effectiveFrom = '2026-07-10',
    ?User $actor = null,
    ?int $contractId = null,
): ConstructionUnitExchange {
    return app(ConstructionUnitExchangeService::class)->registerExtraordinary(
        $unit,
        $actor ?? GovernanceFixture::approver(),
        '450000.00',
        CarbonImmutable::parse($effectiveFrom),
        $contractId,
        EXTRAORDINARY_REASON,
    );
}

function endExchange(ConstructionUnitExchange $exchange, string $endedOn, ?User $actor = null): ConstructionUnitExchange
{
    return app(ConstructionUnitExchangeService::class)->end(
        $exchange,
        $actor ?? GovernanceFixture::approver(),
        CarbonImmutable::parse($endedOn),
        'Permuta desfeita: a unidade volta ao estoque da construtora.',
    );
}

it('registers an extraordinary exchange with author, reason and audit trail', function () {
    $unit = liveUnit();
    $approver = GovernanceFixture::approver();

    $exchange = registerExtraordinaryExchange($unit, actor: $approver);

    expect($exchange->kind)->toBe(ConstructionUnitExchangeKind::Extraordinary)
        ->and($exchange->exchange_value)->toBe('450000.00')
        ->and($exchange->effective_from->toDateString())->toBe('2026-07-10')
        ->and($exchange->created_by_id)->toBe($approver->id)
        ->and($exchange->reason)->toBe(EXTRAORDINARY_REASON);

    $trail = Activity::query()->where('subject_type', ConstructionUnitExchange::class)->where('subject_id', $exchange->id)->sole();

    expect($trail->log_name)->toBe('construction_unit_exchanges')
        ->and($trail->attribute_changes['attributes']['kind'])->toBe('extraordinary');
});

it('feeds the derivation: the unit is exchanged while the exchange is in force and not after it ends', function () {
    $unit = liveUnit();
    $construction = $unit->construction;

    $exchange = registerExtraordinaryExchange($unit, '2026-07-10');

    expect(DerivationFixture::lineFor(DerivationFixture::derive($construction, '2026-07-01'), $unit)->classification)
        ->toBe(SalesBoardUnitClassification::Exchanged);

    endExchange($exchange, '2026-08-15');

    expect(DerivationFixture::lineFor(DerivationFixture::derive($construction, '2026-07-01'), $unit)->classification)
        ->toBe(SalesBoardUnitClassification::Exchanged)
        ->and(DerivationFixture::lineFor(DerivationFixture::derive($construction, '2026-08-01'), $unit)->classification)
        ->not->toBe(SalesBoardUnitClassification::Exchanged);
});

it('leaves the draft emission to the baseline declaration', function () {
    expect(fn () => registerExtraordinaryExchange(liveUnit(Emission::STATUS_DRAFT)))
        ->toThrow(ConstructionUnitExchangeException::class, 'ainda está em elaboração');
});

it('requires the sales board approval permission and a reason', function () {
    $unit = liveUnit();

    expect(fn () => registerExtraordinaryExchange($unit, actor: GovernanceFixture::operator()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(ConstructionUnitExchangeService::class)->registerExtraordinary(
        $unit,
        GovernanceFixture::approver(),
        '450000.00',
        CarbonImmutable::parse('2026-07-10'),
        null,
        'curto',
    ))->toThrow(ConstructionUnitExchangeException::class, 'Informe o motivo');

    expect(ConstructionUnitExchange::query()->count())->toBe(0);
});

it('refuses a second exchange in force at the same time, and accepts it once the first ends', function () {
    $unit = liveUnit();
    $baseline = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();

    expect(fn () => registerExtraordinaryExchange($unit, '2026-07-10'))
        ->toThrow(ConstructionUnitExchangeException::class, 'já tem permuta vigente');

    endExchange($baseline, '2026-07-10');

    // Semiaberta: a permuta encerrada em 10/07 não vale em 10/07.
    expect(registerExtraordinaryExchange($unit, '2026-07-10')->kind)->toBe(ConstructionUnitExchangeKind::Extraordinary)
        ->and(ConstructionUnitExchange::query()->count())->toBe(2);
});

it('never reaches a competence already approved and published', function () {
    $unit = liveUnit();
    $construction = $unit->construction;

    SalesBoardCycle::factory()->forConstruction($construction)->referenceMonth('2026-07-01')->create([
        'status' => SalesBoardCycleStatus::Approved,
    ]);

    expect(fn () => registerExtraordinaryExchange($unit, '2026-07-31'))
        ->toThrow(ConstructionUnitExchangeException::class, 'posterior a 31/07/2026');

    $exchange = registerExtraordinaryExchange($unit, '2026-08-01');

    expect(fn () => endExchange($exchange, '2026-07-20'))
        ->toThrow(ConstructionUnitExchangeException::class);
});

it('refuses a contract of another unit', function () {
    $unit = liveUnit();
    $foreign = Contract::factory()->create();

    expect(fn () => registerExtraordinaryExchange($unit, contractId: $foreign->id))
        ->toThrow(ConstructionUnitExchangeException::class, 'não é desta unidade');
});

it('ends an exchange once, after its start, recording who and why', function () {
    $unit = liveUnit();
    $exchange = registerExtraordinaryExchange($unit, '2026-07-10');
    $approver = GovernanceFixture::approver();

    expect(fn () => endExchange($exchange, '2026-07-10', $approver))
        ->toThrow(ConstructionUnitExchangeException::class, 'posterior ao início');

    $ended = endExchange($exchange, '2026-09-01', $approver);

    expect($ended->ended_on->toDateString())->toBe('2026-09-01')
        ->and($ended->ended_by_id)->toBe($approver->id)
        ->and($ended->ended_at)->not->toBeNull()
        ->and($ended->end_reason)->toContain('volta ao estoque');

    expect(fn () => endExchange($exchange, '2026-10-01'))
        ->toThrow(ConstructionUnitExchangeException::class, 'já está encerrada');
});

it('offers both actions on the unit screen, enabled for management only', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $unit = liveUnit();
    $exchange = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();

    $editor = User::factory()->create();
    $editor->givePermissionTo('constructions.update');
    $this->actingAs($editor);

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->assertActionVisible(TestAction::make('registerExtraordinary')->table())
        ->assertActionDisabled(TestAction::make('registerExtraordinary')->table())
        ->assertActionDisabled(TestAction::make('endExchange')->table($exchange))
        ->assertActionHidden(TestAction::make('declareBaseline')->table());

    $this->actingAs(makeAdminUser());

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->callAction(TestAction::make('endExchange')->table($exchange), [
            'ended_on' => '2026-07-01',
            'end_reason' => 'Permuta desfeita em aditivo com a construtora.',
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('registerExtraordinary')->table(), [
            'exchange_value' => '300.000,00',
            'effective_from' => '2026-07-01',
            'reason' => EXTRAORDINARY_REASON,
        ])
        ->assertHasNoActionErrors()
        ->assertActionHidden(TestAction::make('endExchange')->table($exchange));

    expect($exchange->fresh()->ended_on->toDateString())->toBe('2026-07-01')
        ->and(ConstructionUnitExchange::query()->where('kind', ConstructionUnitExchangeKind::Extraordinary)->sole()->exchange_value)
        ->toBe('300000.00');
});
