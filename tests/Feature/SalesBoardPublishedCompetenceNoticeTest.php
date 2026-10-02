<?php

use App\Actions\ConstructionUnitValues\AnalyzeUnitValueSpreadsheet;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetColumns;
use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitValuesRelationManager;
use App\Filament\Resources\ContractInstallments\Pages\EditContractInstallment;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;

/**
 * O aviso de entrada quando o fato digitado ou importado tem data em
 * competência já registrada no Quadro de Vendas: nos formulários de contrato,
 * parcela e valor de unidade, e por linha nas conferências das importações. Só
 * avisa -- o fato atrasado tem caminho governado --, e diz qual caminho.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

const NOTICE_EXTEMPORANEOUS = 'A posição publicada não muda: o fato entra como movimento extemporâneo em 08/2026 e passa pela validação da construtora e pela Gestão. Para corrigir a posição publicada de 07/2026, a Gestão pode usar “Retificar competência”.';

const NOTICE_MANUAL = 'Altera fato da competência 07/2026, registrada manualmente no Quadro de Vendas: revise o quadro dessa competência em “Nova Atualização”, informando o motivo.';

/**
 * Julho publicado, com os nomes que as planilhas usam e um comprador
 * cadastrado.
 *
 * @return array{construction: Construction, units: list<ConstructionUnit>, financed: Contract, july: SalesBoardCycle, client: Client}
 */
function noticePublishedScenario(): array
{
    $scenario = ExtemporaneousFixture::publishedJuly();
    $scenario['construction']->emission->forceFill(['name' => 'CRI Conviva'])->save();
    $scenario['construction']->forceFill(['development_name' => 'Conviva Camboinhas'])->save();
    $scenario['client'] = Client::factory()->create(['name' => 'João da Silva', 'document' => '52998224725']);
    $scenario['financed']->clients()->attach($scenario['client']->id);

    return $scenario;
}

/**
 * Um empreendimento legado com o quadro de julho digitado à mão.
 */
function noticeManualConstruction(): Construction
{
    [$emission, $construction] = unitEmissionAndConstruction('CRI Manual', 'Residencial Manual', 'active');
    DerivationFixture::unit($construction, '101');

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-07-01']);

    return $construction;
}

/**
 * As notificações da requisição, lidas antes de `assertNotified` -- que as
 * consome.
 *
 * @return Collection<int, array<string, mixed>>
 */
function noticeSessionNotifications(): Collection
{
    return collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);
}

/**
 * @param  list<string>  $headers
 * @param  list<array<string, string>>  $rows  por coluna da planilha
 */
function noticeSpreadsheet(string $name, array $headers, array $rows): string
{
    $path = temporaryTestFilePath($name);
    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_map(fn (string $header): string => $row[$header] ?? '', array_combine($headers, $headers)));
    }

    $writer->close();

    return $path;
}

it('warns in the contract form about a sale in a published competence, saves it and leaves a persistent notification', function () {
    $scenario = noticePublishedScenario();
    $this->actingAs(makeAdminUser());

    $page = Livewire::test(CreateContract::class)
        ->fillForm([
            'emission_id' => $scenario['construction']->emission_id,
            'construction_id' => $scenario['construction']->id,
            'construction_unit_id' => $scenario['units'][1]->id,
            'client_ids' => [$scenario['client']->id],
            'code' => 'CVC-00900',
            'sale_date' => '2026-08-05',
            'sale_value' => '480.000,00',
            'status' => ContractStatus::Active->value,
        ])
        // Agosto não foi publicado: nada a avisar.
        ->assertDontSee('Data em competência já publicada')
        ->fillForm(['sale_date' => '2026-07-18'])
        ->assertSee('Data em competência já publicada')
        ->assertSee('A venda de 18/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). '.NOTICE_EXTEMPORANEOUS)
        ->call('create')
        ->assertHasNoFormErrors();

    $notice = noticeSessionNotifications()->firstWhere('title', 'Fato em competência já registrada no Quadro de Vendas');

    expect(Contract::query()->where('code', 'CVC-00900')->sole()->sale_date->toDateString())->toBe('2026-07-18')
        ->and($notice['body'])->toBe('A venda de 18/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). '.NOTICE_EXTEMPORANEOUS)
        ->and($notice['duration'])->toBe('persistent')
        ->and($notice['status'])->toBe('warning');

    $page->assertNotified('Fato em competência já registrada no Quadro de Vendas');
});

it('warns about a new sale value and a distrato of a contract in a published position', function () {
    $scenario = noticePublishedScenario();
    $this->actingAs(makeAdminUser());

    Livewire::test(EditContract::class, ['record' => $scenario['financed']->getRouteKey()])
        ->assertDontSee('Data em competência já publicada')
        ->fillForm(['sale_value' => '650.000,00'])
        ->assertSee('O valor da venda de 10/03/2026 alcança competência já publicada no Quadro de Vendas (07/2026). A posição publicada não muda: a venda entra como revisão de venda publicada em 08/2026')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(noticeSessionNotifications()->pluck('body')->implode(' '))
        ->toContain('a venda entra como revisão de venda publicada em 08/2026');

    Livewire::test(EditContract::class, ['record' => $scenario['financed']->getRouteKey()])
        ->fillForm([
            'status' => ContractStatus::Cancelled->value,
            'cancellation_date' => '2026-07-25',
        ])
        ->assertSee('O distrato de 25/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). '.NOTICE_EXTEMPORANEOUS);
});

it('warns about a payment and an installment cancellation in a published competence', function () {
    $scenario = noticePublishedScenario();
    $this->actingAs(makeAdminUser());
    $open = $scenario['financed']->installments()->where('number', '002')->sole();

    Livewire::test(EditContractInstallment::class, ['record' => $open->getRouteKey()])
        ->assertDontSee('Data em competência já publicada')
        ->fillForm(['payment_date' => '2026-07-20', 'paid_value' => '300.000,00'])
        ->assertSee('O pagamento de 20/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). '.NOTICE_EXTEMPORANEOUS)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($open->fresh()->payment_date->toDateString())->toBe('2026-07-20')
        ->and(noticeSessionNotifications()->pluck('body')->implode(' '))->toContain('O pagamento de 20/07/2026 cai em competência já publicada');

    Livewire::test(EditContractInstallment::class, ['record' => $open->getRouteKey()])
        ->fillForm(['cancellation_date' => '2026-07-28'])
        ->assertSee('O cancelamento de parcela de 28/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026).');
});

it('warns about a unit value effective in a published competence', function () {
    $scenario = noticePublishedScenario();
    $this->actingAs(makeAdminUser());

    Livewire::test(ConstructionUnitValuesRelationManager::class, ['ownerRecord' => $scenario['units'][2], 'pageClass' => ViewConstructionUnit::class])
        ->mountAction(TestAction::make('updateValue')->table())
        ->fillForm(['value' => '520.000,00', 'effective_from' => '2026-07-15', 'reason' => 'Reajuste da tabela de julho.'])
        ->assertMountedActionModalSee('O valor da unidade com vigência a partir de 15/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). A posição publicada não muda: o valor novo entra na próxima competência a ser publicada (08/2026).')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(noticeSessionNotifications()->pluck('body')->implode(' '))
        ->toContain('o valor novo entra na próxima competência a ser publicada (08/2026)');
});

it('tells a competence under rectification and a manual board apart', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    ExtemporaneousFixture::rectify($scenario['july']);

    $rectifying = RegisteredCompetenceIndex::forConstructions([$scenario['construction']->id]);

    expect($rectifying->noticeFor($scenario['construction']->id, RegisteredCompetenceIndex::SUBJECT_SALE, '2026-07-18'))
        ->toBe('A competência 07/2026 está em retificação: o fato entra na versão retificada se ela for recalculada antes da aprovação; depois disso, entra como extemporâneo em 08/2026.')
        ->and($rectifying->lastPublishedMonth($scenario['construction']->id))->toBe('2026-07');

    $manual = noticeManualConstruction();
    $client = Client::factory()->create();
    $this->actingAs(makeAdminUser());

    expect(RegisteredCompetenceIndex::forConstructions([$manual->id])->noticeFor($manual->id, RegisteredCompetenceIndex::SUBJECT_SALE, '2026-07-18'))
        ->toBe(NOTICE_MANUAL);

    Livewire::test(CreateContract::class)
        ->fillForm([
            'emission_id' => $manual->emission_id,
            'construction_id' => $manual->id,
            'construction_unit_id' => $manual->units()->sole()->id,
            'client_ids' => [$client->id],
            'code' => 'MAN-00001',
            'sale_date' => '2026-07-18',
            'sale_value' => '480.000,00',
            'status' => ContractStatus::Active->value,
        ])
        ->assertSee('Data em competência já registrada')
        ->assertSee(NOTICE_MANUAL);
});

/**
 * Só a última competência publicada pode estar em retificação, e ela alcança
 * também o fato datado num mês anterior: recalculada antes da aprovação, a
 * versão retificada o recebe como extemporâneo. O aviso diz isso, em vez de
 * mandar o fato para a competência seguinte.
 */
it('points a fact dated before the competence under rectification to the rectified version', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);
    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    ExtemporaneousFixture::publish($july);

    $units[2]->forceFill(['base_value' => '520000.00'])->save();
    ExtemporaneousFixture::rectify($july);

    $notice = RegisteredCompetenceIndex::forConstructions([$construction->id])
        ->noticeFor($construction->id, RegisteredCompetenceIndex::SUBJECT_SALE, '2026-06-20');

    expect($notice)->toBe('A venda de 20/06/2026 alcança a competência 07/2026, que está em retificação: o fato entra na versão retificada de 07/2026 se ela for recalculada antes da aprovação; depois disso, entra como extemporâneo em 08/2026.');

    $sale = ExtemporaneousFixture::sale($units[0], '2026-06-20');
    CycleFixture::recalculate($july, 'Venda de junho lançada durante a retificação.');

    expect(ExtemporaneousFixture::movements($july, SalesBoardMovementType::Sale, SalesBoardMovementTiming::Extemporaneous)->pluck('contract_id')->all())
        ->toBe([$sale->id]);
});

it('carries the notice in the rows of the three imports without holding the confirmation', function () {
    $scenario = noticePublishedScenario();

    $contracts = app(AnalyzeContractSpreadsheet::class)->handle(noticeSpreadsheet('notice-contracts', ContractSpreadsheetColumns::headers(), [[
        ContractSpreadsheetColumns::EMISSION => 'CRI Conviva',
        ContractSpreadsheetColumns::CONSTRUCTION => 'Conviva Camboinhas',
        ContractSpreadsheetColumns::BLOCK => '01',
        ContractSpreadsheetColumns::UNIT => '102',
        ContractSpreadsheetColumns::DOCUMENT => '52998224725',
        ContractSpreadsheetColumns::CODE => 'CVC-00900',
        ContractSpreadsheetColumns::SALE_DATE => '18/07/2026',
        ContractSpreadsheetColumns::SALE_VALUE => '480000.00',
        ContractSpreadsheetColumns::STATUS => 'Ativo',
    ]]));

    $installmentPath = noticeSpreadsheet('notice-installments', ContractInstallmentSpreadsheetColumns::headers(), [[
        ContractInstallmentSpreadsheetColumns::EMISSION => 'CRI Conviva',
        ContractInstallmentSpreadsheetColumns::CONSTRUCTION => 'Conviva Camboinhas',
        ContractInstallmentSpreadsheetColumns::CONTRACT => (string) $scenario['financed']->code,
        ContractInstallmentSpreadsheetColumns::NUMBER => '002',
        ContractInstallmentSpreadsheetColumns::DUE_DATE => '10/12/2026',
        ContractInstallmentSpreadsheetColumns::EXPECTED_VALUE => '300000.00',
        ContractInstallmentSpreadsheetColumns::PAYMENT_DATE => '20/07/2026',
        ContractInstallmentSpreadsheetColumns::PAID_VALUE => '300000.00',
    ]]);
    $installments = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($installmentPath);
    $installmentRows = collect(iterator_to_array(app(AnalyzeContractInstallmentSpreadsheet::class)->open($installmentPath)->rows(), false));

    $unitValues = app(AnalyzeUnitValueSpreadsheet::class)->handle(noticeSpreadsheet('notice-unit-values', UnitValueSpreadsheetColumns::headers(), [[
        UnitValueSpreadsheetColumns::EMISSION => 'CRI Conviva',
        UnitValueSpreadsheetColumns::CONSTRUCTION => 'Conviva Camboinhas',
        UnitValueSpreadsheetColumns::BLOCK => '01',
        UnitValueSpreadsheetColumns::UNIT => '103',
        UnitValueSpreadsheetColumns::VALUE => '520.000,00',
        UnitValueSpreadsheetColumns::EFFECTIVE_FROM => '15/07/2026',
        UnitValueSpreadsheetColumns::REASON => 'Reajuste',
    ]]));

    expect($contracts->collect()->sole()['registered_competence_notice'])
        ->toBe('A venda de 18/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). '.NOTICE_EXTEMPORANEOUS)
        ->and($contracts->canImport())->toBeTrue()
        ->and($installmentRows->sole()['registered_competence_notice'])
        ->toBe('O pagamento de 20/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). '.NOTICE_EXTEMPORANEOUS)
        ->and($installments->canImport())->toBeTrue()
        ->and($unitValues->collect()->sole()['registered_competence_notice'])
        ->toBe('O valor da unidade com vigência a partir de 15/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). A posição publicada não muda: o valor novo entra na próxima competência a ser publicada (08/2026). Para corrigir a posição publicada de 07/2026, a Gestão pode usar “Retificar competência”.')
        ->and($unitValues->canImport())->toBeTrue();

    noticeManualConstruction();

    $manual = app(AnalyzeUnitValueSpreadsheet::class)->handle(noticeSpreadsheet('notice-manual-unit-values', UnitValueSpreadsheetColumns::headers(), [[
        UnitValueSpreadsheetColumns::EMISSION => 'CRI Manual',
        UnitValueSpreadsheetColumns::CONSTRUCTION => 'Residencial Manual',
        UnitValueSpreadsheetColumns::BLOCK => '01',
        UnitValueSpreadsheetColumns::UNIT => '101',
        UnitValueSpreadsheetColumns::VALUE => '520.000,00',
        UnitValueSpreadsheetColumns::EFFECTIVE_FROM => '15/07/2026',
        UnitValueSpreadsheetColumns::REASON => 'Reajuste',
    ]]));

    expect($manual->collect()->sole()['registered_competence_notice'])->toBe(NOTICE_MANUAL)
        ->and($manual->canImport())->toBeTrue();
});

/**
 * O cancelamento em lote das parcelas ausentes alcança obras diferentes de uma
 * vez: cada aviso diz de que empreendimento é.
 */
it('names each development when the cancellation of absent installments reaches registered competences of more than one', function () {
    $manual = noticeManualConstruction();

    [$otherEmission, $other] = unitEmissionAndConstruction('CRI Outra', 'Residencial Outro', 'active');
    SalesBoard::factory()->forEmissionAndConstruction($otherEmission, $other)->create(['reference_month' => '2026-07-01']);

    expect(SourceEntryCompetenceNotice::forAbsentInstallmentCancellation([$manual->id, $other->id], '2026-07-18'))
        ->toBe('Residencial Manual: '.NOTICE_MANUAL.' Residencial Outro: '.NOTICE_MANUAL)
        ->and(SourceEntryCompetenceNotice::forAbsentInstallmentCancellation([$manual->id], '2026-07-18'))->toBe(NOTICE_MANUAL)
        // Antes das competências registradas, ou sem data, nada a avisar.
        ->and(SourceEntryCompetenceNotice::forAbsentInstallmentCancellation([$manual->id, $other->id], '2026-08-03'))->toBeNull()
        ->and(SourceEntryCompetenceNotice::forAbsentInstallmentCancellation([$manual->id, $other->id], null))->toBeNull()
        ->and(SourceEntryCompetenceNotice::forAbsentInstallmentCancellation([], '2026-07-18'))->toBeNull();
});
