<?php

use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetAnalysis;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Enums\ContractStatus;
use App\Enums\ReconciliationOutcome;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\User;
use App\Services\SalesBoards\ConstructionUnitExchangeService;
use App\Support\SalesBoards\UnitRetirementConflict;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * A unidade baixada não é ocupada por contrato nem por permuta no período da
 * baixa. A recusa vem onde o dado nasce -- formulário do contrato, importação de
 * contratos e permuta --, com a mesma frase; a derivação continua sendo a última
 * defesa, com o bloqueador da unidade baixada ocupada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
});

/**
 * @return array{emission: Emission, construction: Construction, unit: ConstructionUnit, client: Client}
 */
function retiredUnitScenario(?string $reactivatedOn = null): array
{
    [$emission, $construction] = unitEmissionAndConstruction(status: 'active');

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);
    $retirement = ConstructionUnitRetirement::factory()->forUnit($unit)->retiredOn('2026-08-10');

    ($reactivatedOn === null ? $retirement : $retirement->reactivatedOn($reactivatedOn))->create();

    return [
        'emission' => $emission,
        'construction' => $construction,
        'unit' => $unit,
        'client' => Client::factory()->create(['name' => 'João da Silva', 'document' => '52998224725']),
    ];
}

/**
 * @param  array{emission: Emission, construction: Construction, unit: ConstructionUnit, client: Client}  $scenario
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function retiredUnitContractForm(array $scenario, array $overrides = []): array
{
    return [
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $scenario['construction']->id,
        'construction_unit_id' => $scenario['unit']->id,
        'client_ids' => [$scenario['client']->id],
        'code' => 'CVC-00900',
        'sale_date' => '2026-08-15',
        'sale_value' => '850.000,00',
        'status' => ContractStatus::Active->value,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, string>>  $rows  por coluna da planilha
 */
function retiredUnitContractAnalysis(array $rows): ContractSpreadsheetAnalysis
{
    $path = temporaryTestFilePath('retired-unit-contracts');
    $headers = ContractSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_map(fn (string $header): string => $row[$header] ?? '', array_combine($headers, $headers)));
    }

    $writer->close();

    return app(AnalyzeContractSpreadsheet::class)->handle($path);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function retiredUnitContractRow(array $overrides = []): array
{
    return [
        ContractSpreadsheetColumns::EMISSION => 'CRI Conviva',
        ContractSpreadsheetColumns::CONSTRUCTION => 'Conviva Camboinhas',
        ContractSpreadsheetColumns::BLOCK => '01',
        ContractSpreadsheetColumns::UNIT => '305',
        ContractSpreadsheetColumns::DOCUMENT => '52998224725',
        ContractSpreadsheetColumns::CODE => 'CVC-00900',
        ContractSpreadsheetColumns::SALE_DATE => '15/08/2026',
        ContractSpreadsheetColumns::SALE_VALUE => '850000.00',
        ContractSpreadsheetColumns::STATUS => 'Ativo',
        ContractSpreadsheetColumns::CANCELLATION_DATE => '',
        ...$overrides,
    ];
}

describe('formulário do contrato', function () {
    it('refuses a sale inside the retirement with the shared message, and accepts it from the reactivation on', function (?string $reactivatedOn, string $saleDate, ?string $expected) {
        $this->actingAs(makeAdminUser());
        $scenario = retiredUnitScenario($reactivatedOn);

        $page = Livewire::test(CreateContract::class)
            ->fillForm(retiredUnitContractForm($scenario, ['sale_date' => $saleDate]))
            ->call('create');

        if ($expected === null) {
            $page->assertHasNoFormErrors();

            expect(Contract::query()->sole()->sale_date->toDateString())->toBe($saleDate);

            return;
        }

        $page->assertHasFormErrors(['sale_date']);

        expect($page->errors()->get('data.sale_date'))->toContain($expected)
            ->and(Contract::query()->count())->toBe(0);
    })->with([
        'baixa aberta' => [null, '2026-08-15', (new UnitRetirementConflict(CarbonImmutable::parse('2026-08-10'), null))->describe('Bloco 01 - Unidade 305')],
        'período encerrado que contém a data' => ['2026-09-01', '2026-08-15', (new UnitRetirementConflict(CarbonImmutable::parse('2026-08-10'), CarbonImmutable::parse('2026-09-01')))->describe('Bloco 01 - Unidade 305')],
        'venda a partir da reativação' => ['2026-09-01', '2026-09-01', null],
    ]);

    it('marks the retired unit in the select without hiding it', function () {
        $this->actingAs(makeAdminUser());
        $scenario = retiredUnitScenario();

        Livewire::test(CreateContract::class)
            ->fillForm([
                'emission_id' => $scenario['emission']->id,
                'construction_id' => $scenario['construction']->id,
            ])
            ->assertFormFieldExists('construction_unit_id', fn (Select $field): bool => ($field->getOptions()[$scenario['unit']->id] ?? null)
                === 'Bloco 01 - Unidade 305 · baixada desde 10/08/2026');
    });

    it('refuses an active sale that started before the retirement and crosses it, and keeps an old distratado contract editable', function () {
        $this->actingAs(makeAdminUser());
        $scenario = retiredUnitScenario();

        Livewire::test(CreateContract::class)
            ->fillForm(retiredUnitContractForm($scenario, ['sale_date' => '2026-05-10']))
            ->call('create')
            ->assertHasFormErrors(['sale_date']);

        $old = Contract::factory()->forUnit($scenario['unit'])->forClient($scenario['client'])->create([
            'code' => 'CVC-00100',
            'sale_date' => '2026-03-10',
            'cancellation_date' => '2026-06-01',
            'status' => ContractStatus::Cancelled,
        ]);

        Livewire::test(EditContract::class, ['record' => $old->getRouteKey()])
            ->fillForm(['sale_value' => '820.000,00'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect((float) $old->fresh()->sale_value)->toBe(820000.0);
    });
});

/**
 * Restaurar é ocupar a unidade de novo. A baixa ignora o contrato excluído, que
 * não ocupa, e a sequência excluir, baixar e restaurar poria um contrato vigente
 * sobre a baixa sem a conferência na restauração.
 */
describe('restauração do contrato', function () {
    it('refuses to restore a contract that would occupy the unit during the retirement, telling why on the record', function () {
        $scenario = retiredUnitScenario();
        $contract = Contract::factory()->forUnit($scenario['unit'])->forClient($scenario['client'])->create([
            'code' => 'CVC-00200',
            'sale_date' => '2026-06-01',
            'status' => ContractStatus::Active,
        ]);
        $contract->delete();

        $this->actingAs(makeAdminUser());

        $message = 'Este contrato não pode ser restaurado: ele voltaria a ocupar a unidade no período da baixa. '
            .(new UnitRetirementConflict(CarbonImmutable::parse('2026-08-10'), null))->describe('Bloco 01 - Unidade 305');

        expect(ContractResource::canRestore($contract->fresh()))->toBeFalse()
            ->and(ContractResource::getRestoreAuthorizationResponse($contract->fresh())->message())->toBe($message);

        Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
            ->assertActionVisible('restore')
            ->assertActionDisabled('restore')
            ->assertActionExists('restore', fn (Action $action): bool => $action->getTooltip() === $message)
            ->callAction('restore');

        Livewire::test(ListContracts::class)
            ->filterTable('trashed', true)
            ->assertTableActionHidden('restore', $contract->fresh());

        expect($contract->fresh()->trashed())->toBeTrue();
    });

    it('restores a contract whose period ended before the retirement', function () {
        $scenario = retiredUnitScenario();
        $contract = Contract::factory()->forUnit($scenario['unit'])->forClient($scenario['client'])->create([
            'code' => 'CVC-00201',
            'sale_date' => '2026-03-10',
            'cancellation_date' => '2026-06-01',
            'status' => ContractStatus::Cancelled,
        ]);
        $contract->delete();

        $this->actingAs(makeAdminUser());

        Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
            ->assertActionEnabled('restore')
            ->callAction('restore')
            ->assertHasNoActionErrors();

        expect($contract->fresh()->trashed())->toBeFalse();
    });
});

describe('importação de contratos', function () {
    it('turns a new contract on a retired unit into a conflict, keeping the other lines importable', function () {
        $scenario = retiredUnitScenario();
        ConstructionUnit::factory()->forConstruction($scenario['construction'])->create(['block' => '01', 'unit' => '402']);

        $analysis = retiredUnitContractAnalysis([
            retiredUnitContractRow(),
            retiredUnitContractRow([ContractSpreadsheetColumns::UNIT => '402', ContractSpreadsheetColumns::CODE => 'CVC-00901']),
        ]);

        $retired = $analysis->collect()->firstWhere('code', 'CVC-00900');
        $free = $analysis->collect()->firstWhere('code', 'CVC-00901');

        expect($retired['outcome'])->toBe(ReconciliationOutcome::Conflict)
            ->and($retired['line'])->toBe(2)
            ->and($retired['message'])->toBe((new UnitRetirementConflict(CarbonImmutable::parse('2026-08-10'), null))->describe('01 - 305'))
            ->and($free['outcome'])->toBe(ReconciliationOutcome::New)
            ->and($analysis->canImport())->toBeFalse();
    });

    it('refuses the change that pushes a distrato into the retirement, and leaves the unchanged line alone', function () {
        $scenario = retiredUnitScenario();

        $contract = Contract::factory()->forUnit($scenario['unit'])->forClient($scenario['client'])->create([
            'code' => 'CVC-00100',
            'sale_date' => '2026-03-10',
            'sale_value' => '850000.00',
            'cancellation_date' => '2026-06-01',
            'status' => ContractStatus::Cancelled,
        ]);

        $unchanged = retiredUnitContractAnalysis([retiredUnitContractRow([
            ContractSpreadsheetColumns::CODE => 'CVC-00100',
            ContractSpreadsheetColumns::SALE_DATE => '10/03/2026',
            ContractSpreadsheetColumns::STATUS => 'Distratado',
            ContractSpreadsheetColumns::CANCELLATION_DATE => '01/06/2026',
        ])]);

        $pushed = retiredUnitContractAnalysis([retiredUnitContractRow([
            ContractSpreadsheetColumns::CODE => 'CVC-00100',
            ContractSpreadsheetColumns::SALE_DATE => '10/03/2026',
            ContractSpreadsheetColumns::STATUS => 'Distratado',
            ContractSpreadsheetColumns::CANCELLATION_DATE => '20/08/2026',
        ])]);

        expect($unchanged->collect()->sole()['outcome'])->toBe(ReconciliationOutcome::Unchanged)
            ->and($pushed->collect()->sole()['outcome'])->toBe(ReconciliationOutcome::Conflict)
            ->and($pushed->collect()->sole()['message'])->toContain('está baixada a partir de 10/08/2026')
            ->and($contract->fresh()->cancellation_date->toDateString())->toBe('2026-06-01');
    });
});

describe('permuta', function () {
    it('refuses an extraordinary exchange over the retirement and accepts it from the reactivation on', function () {
        $scenario = retiredUnitScenario('2026-09-01');
        $register = fn (string $from): ConstructionUnitExchange => app(ConstructionUnitExchangeService::class)->registerExtraordinary(
            $scenario['unit'],
            GovernanceFixture::approver(),
            '450000.00',
            CarbonImmutable::parse($from),
            null,
            'Permuta acertada com a construtora em aditivo ao contrato de obra.',
        );

        expect(fn () => $register('2026-07-01'))
            ->toThrow(ConstructionUnitExchangeException::class, 'A unidade está baixada a partir de 10/08/2026 até a reativação em 01/09/2026');

        expect($register('2026-09-01')->effective_from->toDateString())->toBe('2026-09-01');
    });

    it('refuses an extraordinary exchange while the retirement is open', function () {
        $scenario = retiredUnitScenario();

        expect(fn () => app(ConstructionUnitExchangeService::class)->registerExtraordinary(
            $scenario['unit'],
            GovernanceFixture::approver(),
            '450000.00',
            CarbonImmutable::parse('2026-09-10'),
            null,
            'Permuta acertada com a construtora em aditivo ao contrato de obra.',
        ))->toThrow(ConstructionUnitExchangeException::class, (new UnitRetirementConflict(CarbonImmutable::parse('2026-08-10'), null))->describe(null));

        expect(ConstructionUnitExchange::query()->count())->toBe(0);
    });

    it('refuses to declare the initial exchange of a retired unit, telling why and recording nothing', function () {
        $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
        $construction = Construction::factory()->create(['emission_id' => $emission->id]);
        $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '101']);
        ConstructionUnitRetirement::factory()->forUnit($unit)->retiredOn('2026-01-01')->create();

        $editor = User::factory()->create();
        $editor->givePermissionTo(['constructions.view', 'constructions.update']);
        $this->actingAs($editor);

        $page = Livewire::test(ConstructionUnitExchangesRelationManager::class, [
            'ownerRecord' => $unit,
            'pageClass' => ViewConstructionUnit::class,
        ])->callAction(TestAction::make('declareBaseline')->table(), [
            'exchange_value' => '450.000,00',
            'effective_from' => '2026-02-01',
            'reason' => 'Permuta acordada na estruturação da operação com a construtora.',
        ]);

        // O corpo é lido antes do assertNotified, que consome a sessão.
        $body = (string) (collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
            ->firstWhere('title', 'Permuta não declarada.')['body'] ?? '');

        $page->assertNotified('Permuta não declarada.');

        expect($body)->toBe((new UnitRetirementConflict(CarbonImmutable::parse('2026-01-01'), null))->describe(null))
            ->and(ConstructionUnitExchange::query()->count())->toBe(0);
    });
});
