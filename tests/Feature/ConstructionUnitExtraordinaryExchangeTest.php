<?php

use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\ContractStatus;
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
use App\Models\SalesBoardPublication;
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

    // Publicada é a competência que tem publicação, não a que só tem o status.
    $cycle = SalesBoardCycle::factory()->forConstruction($construction)->referenceMonth('2026-07-01')->create([
        'status' => SalesBoardCycleStatus::Approved,
    ]);
    SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $cycle->id]);

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

    // Ver a unidade é o que abre as permutas dela: sem a visualização o
    // RelationManager responde 403, como a própria página.
    $editor = User::factory()->create();
    $editor->givePermissionTo(['constructions.view', 'constructions.update']);
    $this->actingAs($editor);

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->assertActionVisible(TestAction::make('registerExtraordinary')->table())
        ->assertActionDisabled(TestAction::make('registerExtraordinary')->table())
        ->assertActionDisabled(TestAction::make('endExchange')->table($exchange))
        ->assertActionVisible(TestAction::make('substituteExchange')->table($exchange))
        ->assertActionDisabled(TestAction::make('substituteExchange')->table($exchange))
        ->assertActionHidden(TestAction::make('declareBaseline')->table());

    $this->actingAs(makeAdminUser());

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->assertActionEnabled(TestAction::make('substituteExchange')->table($exchange))
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

/**
 * Uma unidade em operação com o contrato de permuta ocupando-a: apontado pela
 * permuta, ou -- na permuta sem contrato -- só marcado como permutado.
 *
 * @return array{0: ConstructionUnit, 1: Contract, 2: ConstructionUnitExchange}
 */
function unitWithExchangeContract(bool $exchangeNamesContract = true, string $contractSaleDate = '2026-02-01'): array
{
    $unit = liveUnit();
    $contract = DerivationFixture::contract($unit, $contractSaleDate, '450000.00', status: ContractStatus::Exchanged);

    $factory = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-02-01')->worth('450000.00');

    return [$unit, $contract, ($exchangeNamesContract ? $factory->forContract($contract) : $factory)->create()];
}

const EXCHANGE_END_REASON = 'Permuta desfeita em aditivo com a construtora.';

describe('encerrar permuta com contrato de permuta', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    });

    it('cancels the exchange contract on the same date, with the reason, author and trail', function () {
        [$unit, $contract, $exchange] = unitWithExchangeContract();
        $approver = GovernanceFixture::approver();

        $ended = app(ConstructionUnitExchangeService::class)->end($exchange, $approver, CarbonImmutable::parse('2026-08-15'), EXCHANGE_END_REASON, $contract->id);

        $contract->refresh();

        expect($ended->ended_on->toDateString())->toBe('2026-08-15')
            ->and($contract->status)->toBe(ContractStatus::Cancelled)
            ->and($contract->cancellation_date->toDateString())->toBe('2026-08-15');

        $exchangeTrail = Activity::query()
            ->where('subject_type', ConstructionUnitExchange::class)
            ->where('subject_id', $exchange->id)
            ->where('event', 'updated')
            ->sole();

        $contractTrail = Activity::query()
            ->where('log_name', 'contracts')
            ->where('subject_type', Contract::class)
            ->where('subject_id', $contract->id)
            ->where('event', '!=', 'created')
            ->orderBy('id')
            ->get();

        $manual = $contractTrail->firstWhere('event', ConstructionUnitExchangeService::EXCHANGE_END_CANCELLATION_EVENT);

        expect($contractTrail->pluck('event')->all())->toBe(['updated', 'distrato_por_encerramento_de_permuta'])
            ->and($contractTrail->first()->attribute_changes['attributes'])->toMatchArray(['status' => 'distratado'])
            ->and($manual->description)->toBe('Distrato pelo encerramento da permuta')
            ->and($manual->properties['motivo'])->toBe(EXCHANGE_END_REASON)
            ->and($manual->properties['construction_unit_exchange_id'])->toBe($exchange->id)
            ->and((int) $manual->causer_id)->toBe($approver->id)
            ->and($exchangeTrail->batch_uuid)->not->toBeNull()
            ->and($contractTrail->pluck('batch_uuid')->unique()->all())->toBe([$exchangeTrail->batch_uuid]);

        // No mês do encerramento a unidade volta ao estoque, sem bloqueio e sem
        // "distrato do mês": a permuta não foi venda, e desfazê-la não é distrato.
        $august = DerivationFixture::derive($unit->construction, '2026-08-01');

        expect(DerivationFixture::lineFor($august, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock)
            ->and($august->hasBlockingIssue())->toBeFalse()
            ->and($august->movements->cancellationsCount())->toBe(0)
            ->and($august->movements->salesCount())->toBe(0);
    });

    it('cancels the exchanged occupant of an exchange without a contract', function () {
        [, $contract, $exchange] = unitWithExchangeContract(exchangeNamesContract: false);

        expect(app(ConstructionUnitExchangeService::class)->exchangeContractFor($exchange, CarbonImmutable::parse('2026-08-15'))?->id)->toBe($contract->id);

        endExchangeConfirming($exchange, '2026-08-15', $contract->id);

        expect($contract->fresh()->status)->toBe(ContractStatus::Cancelled)
            ->and($contract->fresh()->cancellation_date->toDateString())->toBe('2026-08-15');
    });

    it('does not touch a sale contract that occupied a unit with an exchange without a contract', function () {
        $unit = liveUnit();
        $exchange = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-02-01')->create();
        $sale = DerivationFixture::contract($unit, '2026-03-01', '600000.00');

        expect(app(ConstructionUnitExchangeService::class)->exchangeContractFor($exchange, CarbonImmutable::parse('2026-08-15')))->toBeNull();

        endExchangeConfirming($exchange, '2026-08-15', null);

        expect($exchange->fresh()->ended_on->toDateString())->toBe('2026-08-15')
            ->and($sale->fresh()->status)->toBe(ContractStatus::Active)
            ->and($sale->fresh()->cancellation_date)->toBeNull();
    });

    it('refuses to end without confirming the contract cancellation, writing nothing', function (string $confirmation) {
        [$unit, $contract, $exchange] = unitWithExchangeContract();
        $other = DerivationFixture::contract(DerivationFixture::unit($unit->construction, '102'), '2026-02-01');

        expect(fn () => endExchangeConfirming($exchange, '2026-08-15', $confirmation === 'sem confirmação' ? null : $other->id))
            ->toThrow(ConstructionUnitExchangeException::class, sprintf('distrata o contrato de permuta %s', $contract->code));

        expect($exchange->fresh()->ended_on)->toBeNull()
            ->and($contract->fresh()->status)->toBe(ContractStatus::Exchanged)
            ->and($contract->fresh()->cancellation_date)->toBeNull()
            ->and(Activity::query()->where('event', ConstructionUnitExchangeService::EXCHANGE_END_CANCELLATION_EVENT)->exists())->toBeFalse();
    })->with(['sem confirmação', 'outro contrato']);

    it('refuses a future end date when it would cancel a contract', function () {
        [, $contract, $exchange] = unitWithExchangeContract();

        expect(fn () => endExchangeConfirming($exchange, '2026-09-25', $contract->id))
            ->toThrow(ConstructionUnitExchangeException::class, 'distrato é fato');

        expect($exchange->fresh()->ended_on)->toBeNull()
            ->and($contract->fresh()->status)->toBe(ContractStatus::Exchanged);

        // O par: sem contrato a distratar, o encerramento futuro continua aceito.
        $withoutContract = ConstructionUnitExchange::factory()->forUnit(liveUnit())->effectiveFrom('2026-02-01')->create();

        expect(endExchangeConfirming($withoutContract, '2026-09-25', null)->ended_on->toDateString())->toBe('2026-09-25');
    });

    it('refuses when the exchange contract starts after the end or already has a later distrato', function (string $case) {
        [, $contract, $exchange] = unitWithExchangeContract(contractSaleDate: $case === 'começa depois' ? '2026-09-01' : '2026-02-01');

        if ($case === 'distrato posterior') {
            $contract->forceFill(['status' => ContractStatus::Cancelled, 'cancellation_date' => '2026-09-10'])->save();
        }

        expect(fn () => endExchangeConfirming($exchange, '2026-08-15', $contract->id))
            ->toThrow(ConstructionUnitExchangeException::class, $case === 'começa depois' ? 'só começa em 01/09/2026' : 'já tem distrato lançado em 10/09/2026');

        expect($exchange->fresh()->ended_on)->toBeNull();
    })->with(['começa depois', 'distrato posterior']);

    it('ends the exchange from the unit screen confirming the contract shown', function () {
        [$unit, $contract, $exchange] = unitWithExchangeContract();
        $this->actingAs(makeAdminUserWithSeededRoles());

        Livewire::test(ConstructionUnitExchangesRelationManager::class, [
            'ownerRecord' => $unit,
            'pageClass' => ViewConstructionUnit::class,
        ])
            ->mountAction(TestAction::make('endExchange')->table($exchange))
            ->setActionData(['ended_on' => '2026-08-15'])
            ->assertMountedActionModalSee(sprintf('O contrato de permuta %s será distratado em 15/08/2026', $contract->code))
            ->setActionData(['confirm_contract_cancellation' => true, 'end_reason' => EXCHANGE_END_REASON])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Permuta encerrada.');

        expect($exchange->fresh()->ended_on->toDateString())->toBe('2026-08-15')
            ->and($contract->fresh()->status)->toBe(ContractStatus::Cancelled);
    });

    it('requires the confirmation on the screen before ending an exchange with a contract', function () {
        [$unit, $contract, $exchange] = unitWithExchangeContract();
        $this->actingAs(makeAdminUserWithSeededRoles());

        Livewire::test(ConstructionUnitExchangesRelationManager::class, [
            'ownerRecord' => $unit,
            'pageClass' => ViewConstructionUnit::class,
        ])
            ->callAction(TestAction::make('endExchange')->table($exchange), [
                'ended_on' => '2026-08-15',
                'end_reason' => EXCHANGE_END_REASON,
            ])
            ->assertHasActionErrors(['confirm_contract_cancellation']);

        expect($exchange->fresh()->ended_on)->toBeNull()
            ->and($contract->fresh()->status)->toBe(ContractStatus::Exchanged);
    });
});

/**
 * Encerra a permuta pelo serviço, como a Gestão, confirmando o contrato dado.
 */
function endExchangeConfirming(ConstructionUnitExchange $exchange, string $endedOn, ?int $confirmedContractId): ConstructionUnitExchange
{
    return app(ConstructionUnitExchangeService::class)->end(
        $exchange,
        GovernanceFixture::approver(),
        CarbonImmutable::parse($endedOn),
        EXCHANGE_END_REASON,
        $confirmedContractId,
    );
}

function makeAdminUserWithSeededRoles(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RolesAndPermissionsSeeder::class);

    return makeAdminUser();
}

describe('substituir permuta', function () {
    it('substitutes an exchange keeping its contract untouched', function () {
        [$unit, $contract, $exchange] = unitWithExchangeContract();
        $approver = GovernanceFixture::approver();

        $new = app(ConstructionUnitExchangeService::class)->substitute($exchange, $approver, CarbonImmutable::parse('2026-08-01'), '500000.00', 'Valor da permuta corrigido conforme o aditivo.');

        expect($new->kind)->toBe(ConstructionUnitExchangeKind::Extraordinary)
            ->and($new->contract_id)->toBe($contract->id)
            ->and($new->exchange_value)->toBe('500000.00')
            ->and($new->effective_from->toDateString())->toBe('2026-08-01')
            ->and($new->created_by_id)->toBe($approver->id)
            ->and($exchange->fresh()->ended_on->toDateString())->toBe('2026-08-01')
            ->and($exchange->fresh()->ended_by_id)->toBe($approver->id)
            ->and($contract->fresh()->status)->toBe(ContractStatus::Exchanged)
            ->and($contract->fresh()->cancellation_date)->toBeNull();

        $july = DerivationFixture::lineFor(DerivationFixture::derive($unit->construction, '2026-07-01'), $unit);
        $august = DerivationFixture::lineFor(DerivationFixture::derive($unit->construction, '2026-08-01'), $unit);

        expect($july->exchangeValueCents)->toBe(45_000_000)
            ->and($august->classification)->toBe(SalesBoardUnitClassification::Exchanged)
            ->and($august->exchangeValueCents)->toBe(50_000_000)
            ->and($august->exchangeId)->toBe($new->id);
    });

    it('substitutes an exchange from its very start, leaving the old one never in force', function () {
        $unit = liveUnit();
        $old = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-02-01')->worth('0.00')->create();

        $new = app(ConstructionUnitExchangeService::class)->substitute($old, GovernanceFixture::approver(), CarbonImmutable::parse('2026-02-01'), '450000.00', 'Permuta registrada sem valor por engano.');

        expect($old->fresh()->ended_on->toDateString())->toBe('2026-02-01')
            ->and($old->fresh()->isEffectiveOn(CarbonImmutable::parse('2026-02-01')))->toBeFalse()
            ->and($new->isEffectiveOn(CarbonImmutable::parse('2026-02-01')))->toBeTrue();

        $position = DerivationFixture::derive($unit->construction, '2026-03-01');

        expect(DerivationFixture::lineFor($position, $unit)->exchangeValueCents)->toBe(45_000_000)
            ->and(DerivationFixture::issueCodes($position))->not->toContain('EXCHANGE_VALUE_MISSING');
    });

    it('substitutes a baseline exchange while the emission is in draft with the construction permission', function () {
        $unit = liveUnit(Emission::STATUS_DRAFT);
        $old = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('450000.00')->create();

        $editor = User::factory()->create();
        $editor->givePermissionTo('constructions.update');

        $new = app(ConstructionUnitExchangeService::class)->substitute($old, $editor, CarbonImmutable::parse('2026-01-01'), '460000.00', 'Valor acordado na estruturação corrigido.');

        expect($new->kind)->toBe(ConstructionUnitExchangeKind::Baseline)
            ->and($new->created_by_id)->toBe($editor->id);

        // Sem a permissão de editar empreendimentos, a posição inicial não muda
        // -- nem pela Gestão.
        expect(fn () => app(ConstructionUnitExchangeService::class)->substitute($new, GovernanceFixture::approver(), CarbonImmutable::parse('2026-01-01'), '470000.00', 'Outra correção do valor acordado.'))
            ->toThrow(AuthorizationException::class);

        expect(ConstructionUnitExchange::query()->count())->toBe(2);
    });

    it('refuses a substitution before the start of the current exchange or reaching a published competence', function () {
        $unit = liveUnit();
        $exchange = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-03-01')->create();

        expect(fn () => app(ConstructionUnitExchangeService::class)->substitute($exchange, GovernanceFixture::approver(), CarbonImmutable::parse('2026-02-28'), '450000.00', EXTRAORDINARY_REASON))
            ->toThrow(ConstructionUnitExchangeException::class, 'igual ou posterior ao início da permuta atual, 01/03/2026');

        $published = SalesBoardCycle::factory()->forConstruction($unit->construction)->referenceMonth('2026-07-01')->create([
            'status' => SalesBoardCycleStatus::Approved,
        ]);
        SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $published->id]);

        expect(fn () => app(ConstructionUnitExchangeService::class)->substitute($exchange, GovernanceFixture::approver(), CarbonImmutable::parse('2026-07-31'), '450000.00', EXTRAORDINARY_REASON))
            ->toThrow(ConstructionUnitExchangeException::class, 'posterior a 31/07/2026');

        expect($exchange->fresh()->ended_on)->toBeNull()
            ->and(ConstructionUnitExchange::query()->count())->toBe(1);
    });
});

it('refuses a zero exchange value in the service and in the forms', function (string $action) {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $unit = liveUnit($action === 'declarar' ? Emission::STATUS_DRAFT : 'active');
    $current = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();
    $admin = makeAdminUser();
    $service = app(ConstructionUnitExchangeService::class);

    $callService = fn () => match ($action) {
        'registrar' => $service->registerExtraordinary($unit, $admin, '0.00', CarbonImmutable::parse('2026-07-10'), null, EXTRAORDINARY_REASON),
        'substituir' => $service->substitute($current, $admin, CarbonImmutable::parse('2026-07-10'), '0.00', EXTRAORDINARY_REASON),
        'declarar' => $service->declareBaseline($unit, $admin, '0.00', CarbonImmutable::parse('2026-07-10'), null, EXTRAORDINARY_REASON),
    };

    expect($callService)->toThrow(ConstructionUnitExchangeException::class, 'Informe um valor de permuta maior que zero');

    $this->actingAs($admin);

    [$name, $record, $data] = match ($action) {
        'registrar' => ['registerExtraordinary', null, ['exchange_value' => '0,00', 'effective_from' => '2026-07-10', 'reason' => EXTRAORDINARY_REASON]],
        'substituir' => ['substituteExchange', $current, ['exchange_value' => '0,00', 'effective_from' => '2026-07-10', 'reason' => EXTRAORDINARY_REASON]],
        'declarar' => ['declareBaseline', null, ['exchange_value' => '0,00', 'effective_from' => '2026-07-10', 'reason' => EXTRAORDINARY_REASON]],
    };

    $component = Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->callAction($record === null ? TestAction::make($name)->table() : TestAction::make($name)->table($record), $data)
        ->assertHasActionErrors(['exchange_value' => 'min']);

    expect(collect($component->errors()->all()))->toContain('O valor da permuta precisa ser maior que zero.')
        ->and(ConstructionUnitExchange::query()->count())->toBe(1);
})->with(['registrar', 'substituir', 'declarar']);

it('refuses a forged mount of substitute for a user without the approval permission', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $unit = liveUnit();
    $exchange = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();

    $editor = User::factory()->create();
    $editor->givePermissionTo(['constructions.view', 'constructions.update']);
    $this->actingAs($editor);

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->call('mountAction', 'substituteExchange', [], ['table' => true, 'recordKey' => (string) $exchange->getKey()])
        ->assertSet('mountedActions', [])
        ->set('mountedActions', [[
            'name' => 'substituteExchange',
            'arguments' => [],
            'context' => ['table' => true, 'recordKey' => (string) $exchange->getKey()],
            'data' => ['exchange_value' => '500.000,00', 'effective_from' => '2026-07-10', 'reason' => EXTRAORDINARY_REASON],
        ]])
        ->call('callMountedAction');

    expect($exchange->fresh()->ended_on)->toBeNull()
        ->and(ConstructionUnitExchange::query()->count())->toBe(1)
        ->and(fn () => app(ConstructionUnitExchangeService::class)->substitute($exchange, $editor, CarbonImmutable::parse('2026-07-10'), '500000.00', EXTRAORDINARY_REASON))
        ->toThrow(AuthorizationException::class);
});

/**
 * O piso de 1990 em toda escrita de permuta. O seletor de data aceita um ano de
 * quatro dígitos digitado na caixa de ano -- "0026", ou o deslize "1026" --, e a
 * derivação bloqueia a permuta vigente que começa antes do piso: sem o piso na
 * entrada, a permuta, que não se edita, prenderia as competências seguintes.
 */
describe('piso de 1990 nas datas de permuta', function () {
    beforeEach(function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    });

    it('refuses a date before 1990 in the service, writing nothing', function (string $action, string $date) {
        $unit = liveUnit($action === 'declarar' ? Emission::STATUS_DRAFT : 'active');
        $admin = makeAdminUser();
        $service = app(ConstructionUnitExchangeService::class);
        $current = $action === 'declarar'
            ? null
            : ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();

        $write = fn () => match ($action) {
            'declarar' => $service->declareBaseline($unit, $admin, '450000.00', CarbonImmutable::parse($date), null, EXTRAORDINARY_REASON),
            'registrar' => $service->registerExtraordinary($unit, $admin, '450000.00', CarbonImmutable::parse($date), null, EXTRAORDINARY_REASON),
            'encerrar' => $service->end($current, $admin, CarbonImmutable::parse($date), EXCHANGE_END_REASON),
            'substituir' => $service->substitute($current, $admin, CarbonImmutable::parse($date), '450000.00', EXTRAORDINARY_REASON),
        };

        expect($write)->toThrow(
            ConstructionUnitExchangeException::class,
            sprintf('A data %s é anterior a 01/01/1990: confira o ano.', CarbonImmutable::parse($date)->format('d/m/Y')),
        );

        expect(ConstructionUnitExchange::query()->count())->toBe($current === null ? 0 : 1)
            ->and($current?->fresh()->ended_on)->toBeNull();
    })->with([
        'declarar' => ['declarar', '0026-03-01'],
        'registrar' => ['registrar', '1989-12-31'],
        'encerrar' => ['encerrar', '1026-08-15'],
        'substituir' => ['substituir', '1989-12-31'],
    ]);

    it('accepts the first day of 1990', function () {
        $exchange = registerExtraordinaryExchange(liveUnit(), '1990-01-01');

        expect($exchange->effective_from->toDateString())->toBe('1990-01-01');
    });

    it('refuses a date before 1990 in every form of the unit screen', function (string $action) {
        $unit = liveUnit($action === 'declarar' ? Emission::STATUS_DRAFT : 'active');
        $current = $action === 'declarar' || $action === 'registrar'
            ? null
            : ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();

        $this->actingAs(makeAdminUser());

        [$name, $data, $field, $message] = match ($action) {
            'declarar' => ['declareBaseline', ['exchange_value' => '450.000,00', 'effective_from' => '1989-12-31', 'reason' => EXTRAORDINARY_REASON], 'effective_from', 'A vigência não pode ser anterior a 01/01/1990: confira o ano.'],
            'registrar' => ['registerExtraordinary', ['exchange_value' => '450.000,00', 'effective_from' => '1989-12-31', 'reason' => EXTRAORDINARY_REASON], 'effective_from', 'A vigência não pode ser anterior a 01/01/1990: confira o ano.'],
            'encerrar' => ['endExchange', ['ended_on' => '1989-12-31', 'end_reason' => EXCHANGE_END_REASON], 'ended_on', 'A data do encerramento não pode ser anterior a 01/01/1990: confira o ano.'],
            'substituir' => ['substituteExchange', ['exchange_value' => '450.000,00', 'effective_from' => '1989-12-31', 'reason' => EXTRAORDINARY_REASON], 'effective_from', 'A nova vigência não pode ser anterior a 01/01/1990: confira o ano.'],
        };

        $component = Livewire::test(ConstructionUnitExchangesRelationManager::class, [
            'ownerRecord' => $unit,
            'pageClass' => ViewConstructionUnit::class,
        ])
            ->callAction($current === null ? TestAction::make($name)->table() : TestAction::make($name)->table($current), $data)
            ->assertHasActionErrors([$field => 'after_or_equal']);

        expect(collect($component->errors()->all()))->toContain($message)
            ->and(ConstructionUnitExchange::query()->count())->toBe($current === null ? 0 : 1);
    })->with(['declarar', 'registrar', 'encerrar', 'substituir']);
});

/**
 * A permuta gravada antes do piso, com o ano errado, se corrige por "Substituir
 * permuta": encerrá-la na data nova deixaria o início falso valendo nos meses
 * do meio, bloqueados. Ela deixa de valer onde a posição publicada permite -- no
 * próprio início, ou depois da última competência publicada.
 */
describe('substituir permuta com início anterior a 1990', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    });

    it('corrects the start of a legacy exchange from the very beginning when nothing is published', function () {
        $unit = liveUnit();
        $contract = DerivationFixture::contract($unit, '2026-03-01', '450000.00', status: ContractStatus::Exchanged);
        $legacy = ConstructionUnitExchange::factory()->forUnit($unit)->forContract($contract)->effectiveFrom('0026-03-01')->worth('450000.00')->create();

        expect(DerivationFixture::issueCodes(DerivationFixture::derive($unit->construction, '2026-02-01')))->toContain('SOURCE_DATE_BEFORE_1990')
            ->and(ConstructionUnitExchangeService::startsImplausibly($legacy))->toBeTrue();

        $new = app(ConstructionUnitExchangeService::class)->substitute($legacy, GovernanceFixture::approver(), CarbonImmutable::parse('2026-03-01'), '450000.00', 'Ano da vigência digitado errado no cadastro.');

        expect($legacy->fresh()->ended_on->toDateString())->toBe('0026-03-01')
            ->and($legacy->fresh()->isEffectiveOn(CarbonImmutable::parse('2026-02-15')))->toBeFalse()
            ->and($new->effective_from->toDateString())->toBe('2026-03-01')
            ->and($new->contract_id)->toBe($contract->id)
            ->and($new->kind)->toBe(ConstructionUnitExchangeKind::Extraordinary)
            ->and(DerivationFixture::issueCodes(DerivationFixture::derive($unit->construction, '2026-02-01')))->not->toContain('SOURCE_DATE_BEFORE_1990')
            ->and(DerivationFixture::lineFor(DerivationFixture::derive($unit->construction, '2026-03-01'), $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
            ->and(DerivationFixture::issueCodes(DerivationFixture::derive($unit->construction, '2026-03-01')))->not->toContain('SOURCE_DATE_BEFORE_1990');
    });

    it('keeps the published position and corrects only the open competences', function () {
        $unit = liveUnit();
        $legacy = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('0026-03-01')->worth('450000.00')->create();

        $published = SalesBoardCycle::factory()->forConstruction($unit->construction)->referenceMonth('2026-07-01')->create([
            'status' => SalesBoardCycleStatus::Approved,
        ]);
        SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $published->id]);

        expect(fn () => app(ConstructionUnitExchangeService::class)->substitute($legacy, GovernanceFixture::approver(), CarbonImmutable::parse('2026-07-15'), '450000.00', 'Ano da vigência digitado errado no cadastro.'))
            ->toThrow(ConstructionUnitExchangeException::class, 'posterior a 31/07/2026');

        $new = app(ConstructionUnitExchangeService::class)->substitute($legacy, GovernanceFixture::approver(), CarbonImmutable::parse('2026-08-10'), '450000.00', 'Ano da vigência digitado errado no cadastro.');

        // Dentro da posição publicada a permuta vale como valeu; nas competências
        // em aberto, só a nova.
        expect($legacy->fresh()->ended_on->toDateString())->toBe('2026-08-01')
            ->and($legacy->fresh()->isEffectiveOn(CarbonImmutable::parse('2026-07-31')))->toBeTrue()
            ->and($new->effective_from->toDateString())->toBe('2026-08-10')
            ->and(DerivationFixture::issueCodes(DerivationFixture::derive($unit->construction, '2026-08-01')))->not->toContain('SOURCE_DATE_BEFORE_1990');
    });

    it('explains the correction in the substitution modal', function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);

        $unit = liveUnit();
        $legacy = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('0026-03-01')->worth('450000.00')->create();

        $this->actingAs(makeAdminUser());

        Livewire::test(ConstructionUnitExchangesRelationManager::class, [
            'ownerRecord' => $unit,
            'pageClass' => ViewConstructionUnit::class,
        ])
            ->mountAction(TestAction::make('substituteExchange')->table($legacy))
            ->assertMountedActionModalSee('A vigência atual começa em 01/03/0026, antes de 01/01/1990')
            ->assertMountedActionModalSee('a permuta atual deixa de valer desde o início')
            ->setActionData(['exchange_value' => '450.000,00', 'effective_from' => '2026-03-01', 'reason' => 'Ano da vigência digitado errado no cadastro.'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Permuta substituída.');

        expect($legacy->fresh()->ended_on->toDateString())->toBe('0026-03-01');
    });
});

/**
 * A permuta encerrada pelo caminho antigo -- só o fim da vigência, sem o
 * distrato do contrato de permuta, que continua ocupando a unidade.
 *
 * @return array{0: ConstructionUnit, 1: Contract, 2: ConstructionUnitExchange}
 */
function endedExchangeKeepingItsContract(bool $exchangeNamesContract = true): array
{
    [$unit, $contract, $exchange] = unitWithExchangeContract($exchangeNamesContract);

    $exchange->forceFill(['ended_on' => '2026-08-15', 'end_reason' => EXCHANGE_END_REASON])->save();

    return [$unit, $contract, $exchange->fresh()];
}

/**
 * A permuta encerrada antes de o encerramento distratar o contrato de permuta:
 * o contrato continuou ocupando a unidade como permutado, toda competência
 * seguinte bloqueia, e "Encerrar" de novo é recusado. "Distratar contrato de
 * permuta" grava o distrato que o encerramento gravaria hoje.
 */
describe('distratar contrato de permuta encerrada', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    });

    it('cancels the exchange contract of an ended exchange on its end date, with reason and trail, and unblocks the competence', function (bool $exchangeNamesContract) {
        [$unit, $contract, $exchange] = endedExchangeKeepingItsContract($exchangeNamesContract);
        $approver = GovernanceFixture::approver();
        $service = app(ConstructionUnitExchangeService::class);

        expect(DerivationFixture::issueCodes(DerivationFixture::derive($unit->construction, '2026-08-01')))->toContain('EXCHANGE_SOURCE_MISSING')
            ->and(fn () => endExchangeConfirming($exchange, '2026-08-20', $contract->id))->toThrow(ConstructionUnitExchangeException::class, 'já está encerrada')
            ->and($service->endedExchangeContract($exchange)?->id)->toBe($contract->id);

        $cancelled = $service->cancelEndedExchangeContract($exchange, $approver, 'Permuta desfeita em agosto; o contrato ficou sem distrato.', $contract->id);

        expect($cancelled->status)->toBe(ContractStatus::Cancelled)
            ->and($cancelled->cancellation_date->toDateString())->toBe('2026-08-15')
            ->and($service->endedExchangeContract($exchange->fresh()))->toBeNull();

        $manual = Activity::query()->where('event', ConstructionUnitExchangeService::ENDED_EXCHANGE_CANCELLATION_EVENT)->sole();
        $update = Activity::query()->where('log_name', 'contracts')->where('subject_id', $contract->id)->where('event', 'updated')->sole();

        expect($manual->log_name)->toBe('contracts')
            ->and($manual->description)->toBe('Distrato do contrato de permuta encerrada')
            ->and($manual->properties['motivo'])->toBe('Permuta desfeita em agosto; o contrato ficou sem distrato.')
            ->and($manual->properties['construction_unit_exchange_id'])->toBe($exchange->id)
            ->and((int) $manual->causer_id)->toBe($approver->id)
            ->and($manual->batch_uuid)->not->toBeNull()
            ->and($update->batch_uuid)->toBe($manual->batch_uuid);

        $august = DerivationFixture::derive($unit->construction, '2026-08-01');

        expect(DerivationFixture::lineFor($august, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock)
            ->and($august->hasBlockingIssue())->toBeFalse()
            ->and($august->movements->cancellationsCount())->toBe(0);
    })->with([
        'permuta que aponta o contrato' => [true],
        'permuta sem contrato, com o ocupante permutado' => [false],
    ]);

    it('refuses without the confirmation, without management, for an exchange in force or a substituted one', function () {
        [$unit, $contract, $exchange] = endedExchangeKeepingItsContract();
        $service = app(ConstructionUnitExchangeService::class);
        $reason = 'Permuta desfeita em agosto; o contrato ficou sem distrato.';

        expect(fn () => $service->cancelEndedExchangeContract($exchange, GovernanceFixture::approver(), $reason, null))
            ->toThrow(ConstructionUnitExchangeException::class, sprintf('é do contrato de permuta %s, e esse distrato não foi confirmado', $contract->code))
            ->and(fn () => $service->cancelEndedExchangeContract($exchange, GovernanceFixture::operator(), $reason, $contract->id))
            ->toThrow(AuthorizationException::class);

        $inForce = ConstructionUnitExchange::factory()->forUnit(liveUnit())->effectiveFrom('2026-02-01')->create();

        expect(fn () => $service->cancelEndedExchangeContract($inForce, GovernanceFixture::approver(), $reason, null))
            ->toThrow(ConstructionUnitExchangeException::class, 'ainda está vigente');

        // Substituída: o contrato segue com a permuta que a sucedeu.
        [, $substitutedContract, $substituted] = unitWithExchangeContract();
        app(ConstructionUnitExchangeService::class)->substitute($substituted, GovernanceFixture::approver(), CarbonImmutable::parse('2026-08-01'), '500000.00', 'Valor da permuta corrigido conforme o aditivo.');

        expect($service->endedExchangeContract($substituted->fresh()))->toBeNull()
            ->and(fn () => $service->cancelEndedExchangeContract($substituted->fresh(), GovernanceFixture::approver(), $reason, $substitutedContract->id))
            ->toThrow(ConstructionUnitExchangeException::class, 'Nenhum contrato de permuta continua ocupando a unidade');

        expect($contract->fresh()->status)->toBe(ContractStatus::Exchanged)
            ->and($substitutedContract->fresh()->status)->toBe(ContractStatus::Exchanged)
            ->and(Activity::query()->where('event', ConstructionUnitExchangeService::ENDED_EXCHANGE_CANCELLATION_EVENT)->exists())->toBeFalse();
    });

    it('sends the exchange ended inside a published competence to the contract, writing nothing', function () {
        [$unit, $contract, $exchange] = unitWithExchangeContract();
        $exchange->forceFill(['ended_on' => '2026-07-15', 'end_reason' => EXCHANGE_END_REASON])->save();

        $published = SalesBoardCycle::factory()->forConstruction($unit->construction)->referenceMonth('2026-07-01')->create([
            'status' => SalesBoardCycleStatus::Approved,
        ]);
        SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $published->id]);

        expect(fn () => app(ConstructionUnitExchangeService::class)->cancelEndedExchangeContract($exchange->fresh(), GovernanceFixture::approver(), 'Permuta desfeita em julho; o contrato ficou sem distrato.', $contract->id))
            ->toThrow(ConstructionUnitExchangeException::class, 'A permuta foi encerrada em 15/07/2026, dentro de competência já publicada (até 31/07/2026)');

        expect($contract->fresh()->status)->toBe(ContractStatus::Exchanged)
            ->and($contract->fresh()->cancellation_date)->toBeNull();
    });

    it('offers the action on the ended exchange, enabled for management only', function () {
        [$unit, $contract, $exchange] = endedExchangeKeepingItsContract();
        $other = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-09-01')->worth('300000.00')->create();

        $editor = User::factory()->create();
        $editor->givePermissionTo(['constructions.view', 'constructions.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($editor->fresh());

        Livewire::test(ConstructionUnitExchangesRelationManager::class, [
            'ownerRecord' => $unit,
            'pageClass' => ViewConstructionUnit::class,
        ])
            ->assertActionVisible(TestAction::make('cancelEndedExchangeContract')->table($exchange))
            ->assertActionDisabled(TestAction::make('cancelEndedExchangeContract')->table($exchange))
            ->assertActionHidden(TestAction::make('cancelEndedExchangeContract')->table($other));

        $this->actingAs(makeAdminUserWithSeededRoles());

        Livewire::test(ConstructionUnitExchangesRelationManager::class, [
            'ownerRecord' => $unit,
            'pageClass' => ViewConstructionUnit::class,
        ])
            ->mountAction(TestAction::make('cancelEndedExchangeContract')->table($exchange))
            ->assertMountedActionModalSee(sprintf('O contrato de permuta %s será distratado em 15/08/2026', $contract->code))
            ->setActionData(['confirm_contract_cancellation' => true, 'reason' => 'Permuta desfeita em agosto; o contrato ficou sem distrato.'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Contrato de permuta distratado.');

        expect($contract->fresh()->status)->toBe(ContractStatus::Cancelled)
            ->and($contract->fresh()->cancellation_date->toDateString())->toBe('2026-08-15');
    });
});
