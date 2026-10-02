<?php

use App\Enums\ContractStatus;
use App\Enums\SalesBoardIssueCode;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Services\SalesBoards\SalesBoardBuilderReviewWorkspaceBuilder;
use App\Services\SalesBoards\SalesBoardManagementReviewWorkspaceBuilder;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * Os avisos da apuração congelados com a versão e com a homologação, e as telas
 * que os mostram: a notificação de gerar e de verificar, a tela do ciclo, a
 * Validação da construtora (só o que é dela), a Análise da Gestão e o rollout.
 *
 * Vários avisos dependem do status de hoje e não se reconstroem a partir de
 * linhas e movimentos; por isso são gravados com a versão, e as telas os leem de
 * lá.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * Julho com dois avisos e nenhum bloqueio: um contrato ainda marcado como ativo
 * que o cronograma já quitou, e uma venda do mês pelo dobro da tabela ou mais.
 *
 * @return array{construction: Construction, units: list<ConstructionUnit>, settled: Contract, atypical: Contract}
 */
function warningsScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $settled = DerivationFixture::contract($units[0], '2026-02-10', '500000.00');
    DerivationFixture::installment($settled, '001', '2026-03-10', '500000.00', '2026-03-09', '500000.00');

    $atypical = DerivationFixture::contract($units[1], '2026-07-10', '1200000.00');
    DerivationFixture::installment($atypical, '001', '2026-12-10', '1200000.00');

    return ['construction' => $construction, 'units' => $units, 'settled' => $settled, 'atypical' => $atypical];
}

/**
 * As notificações enviadas, com título e corpo como texto, lidas sem consumir a
 * sessão -- `assertNotified()` as consome, e por isso vem depois.
 *
 * @return list<array{title: string, body: string}>
 */
function warningsNotifications(): array
{
    return collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->map(fn (array $notification): array => [
            'title' => (string) ($notification['title'] ?? ''),
            'body' => (string) ($notification['body'] ?? ''),
        ])
        ->values()
        ->all();
}

it('freezes the warnings of the version with the unit label, and an empty list when there are none', function () {
    $scenario = warningsScenario();

    $warnings = CycleFixture::currentBaseline(CycleFixture::generate($scenario['construction'])->cycle)->frozenWarnings();

    expect(array_column($warnings, 'code'))->toBe([
        SalesBoardIssueCode::SaleValueAtypical->value,
        SalesBoardIssueCode::SettlementStatusDivergence->value,
    ])
        ->and(array_keys($warnings[0]))->toBe(['code', 'message', 'construction_unit_id', 'unit_label', 'contract_id', 'contract_code'])
        ->and($warnings[0])->toMatchArray([
            'construction_unit_id' => $scenario['units'][1]->id,
            'unit_label' => '01 / 102',
            'contract_id' => $scenario['atypical']->id,
            'contract_code' => $scenario['atypical']->code,
        ])
        ->and($warnings[1])->toMatchArray([
            'unit_label' => '01 / 101',
            'contract_id' => $scenario['settled']->id,
        ])
        ->and($warnings[1]['message'])->toContain('marcado como ativo');

    // Versão nova sem aviso grava a lista vazia: "nenhum aviso", e não "não
    // registrados".
    [$clean] = CycleFixture::readyConstruction(1);

    expect(CycleFixture::currentBaseline(CycleFixture::generate($clean)->cycle)->frozenWarnings())->toBe([]);
});

it('freezes the warnings of each recalculated version apart from the previous one', function () {
    $scenario = warningsScenario();
    $cycle = CycleFixture::generate($scenario['construction'])->cycle;
    $first = CycleFixture::currentBaseline($cycle);

    // A venda é corrigida para o valor de tabela, com a parcela: o aviso de ágio
    // some na V2.
    $scenario['atypical']->update(['sale_value' => '500000.00']);
    $scenario['atypical']->installments()->sole()->update(['expected_value' => '500000.00']);

    $result = CycleFixture::recalculate($cycle, 'Valor da venda corrigido.');

    expect($result->createdNewVersion())->toBeTrue()
        ->and(array_column($result->baseline->fresh()->frozenWarnings(), 'code'))->toBe([SalesBoardIssueCode::SettlementStatusDivergence->value])
        ->and(array_column($first->fresh()->frozenWarnings(), 'code'))->toBe([
            SalesBoardIssueCode::SaleValueAtypical->value,
            SalesBoardIssueCode::SettlementStatusDivergence->value,
        ]);
});

it('lists the warnings in the notification of the generated cycle', function () {
    $scenario = warningsScenario();

    $page = Livewire::test(ListSalesBoardCycles::class)
        ->callAction(TestAction::make('generateCycle'), [
            'construction_id' => $scenario['construction']->id,
            'reference_month' => '2026-07-01',
        ]);

    $body = collect(warningsNotifications())->firstWhere('title', 'Ciclo gerado')['body'] ?? '';

    expect($body)->toContain('versão V1 congelada')
        ->toContain('A apuração registrou avisos que não impedem o congelamento')
        ->toContain('Venda muito acima do valor de referência')
        ->toContain('SALE_VALUE_ATYPICAL · 1')
        ->toContain('Status do contrato diverge da quitação apurada pelo cronograma')
        ->toContain('confira se o valor da venda e o valor de referência da unidade estão certos');

    $page->assertNotified('Ciclo gerado');
});

it('shows the frozen warnings on the cycle page with label, hint and unit', function () {
    $scenario = warningsScenario();
    $cycle = CycleFixture::generate($scenario['construction'])->cycle;

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertOk()
        ->assertSee('Avisos da apuração')
        ->assertSee('Sinais que a apuração registrou nesta versão sem impedir o congelamento')
        ->assertSee('Venda muito acima do valor de referência')
        ->assertSee('SALE_VALUE_ATYPICAL')
        ->assertSee('Não impede a apuração: confira se o valor da venda e o valor de referência da unidade estão certos.')
        ->assertSee('01 / 102 · '.$scenario['atypical']->code)
        ->assertSee('Status do contrato diverge da quitação apurada pelo cronograma');
});

it('says the warnings were not recorded for a version frozen before them', function () {
    $scenario = warningsScenario();
    $cycle = CycleFixture::generate($scenario['construction'])->cycle;

    DB::table('sales_board_cycle_baselines')->where('id', $cycle->current_baseline_id)->update(['warnings' => null]);

    expect(SalesBoardCycleBaseline::query()->findOrFail($cycle->current_baseline_id)->frozenWarnings())->toBeNull();

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertOk()
        ->assertSee('Avisos não registrados nesta versão')
        ->assertDontSee('Venda muito acima do valor de referência');
});

it('adds the warnings of the current source to the verification notice', function () {
    $scenario = warningsScenario();
    $cycle = CycleFixture::generate($scenario['construction'])->cycle;

    $page = Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->callAction('checkStale');

    $body = collect(warningsNotifications())->firstWhere('title', 'Sem alterações')['body'] ?? '';

    expect($body)->toContain('Avisos da fonte atual (não impedem a apuração):')
        ->toContain('Venda muito acima do valor de referência')
        ->toContain('SETTLEMENT_STATUS_DIVERGENCE · 1');

    $page->assertNotified('Sem alterações');
});

it('shows the builder only the warnings about its own data, without codes', function () {
    // A venda do mês sai abaixo do mínimo autorizado (SALE_NON_CONFORM, política
    // interna) e o contrato quitado continua marcado como ativo
    // (SETTLEMENT_STATUS_DIVERGENCE, dado da construtora).
    $scenario = BuilderReviewFixture::generatedCycleWithNonConformSale();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    expect(array_column(CycleFixture::currentBaseline($scenario['cycle'])->frozenWarnings(), 'code'))
        ->toBe([SalesBoardIssueCode::SaleNonConform->value, SalesBoardIssueCode::SettlementStatusDivergence->value]);

    $workspace = app(SalesBoardBuilderReviewWorkspaceBuilder::class)->build($review->fresh());

    expect(array_column($workspace->warnings, 'code'))->toBe([SalesBoardIssueCode::SettlementStatusDivergence->value])
        ->and($workspace->warnings[0]['hint'])->toBeNull();

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Pontos para conferir nesta posição')
        ->assertSee('A apuração sinalizou os pontos abaixo sem impedir o quadro.')
        ->assertSee('Status do contrato diverge da quitação apurada pelo cronograma')
        ->assertDontSee('SETTLEMENT_STATUS_DIVERGENCE')
        ->assertDontSee('Venda fora da política comercial vigente')
        ->assertDontSee('SALE_NON_CONFORM')
        ->assertDontSee('Não impede a apuração: o Quadro segue o cronograma de parcelas');
});

it('shows the frozen warnings to the management and counts them in the approval confirmation', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review->fresh());

    expect(array_column($workspace->warnings, 'code'))->toBe([SalesBoardIssueCode::SettlementStatusDivergence->value])
        ->and($workspace->warningCount())->toBe(1);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Avisos da apuração')
        ->assertSee('Status do contrato diverge da quitação apurada pelo cronograma')
        ->assertSee('SETTLEMENT_STATUS_DIVERGENCE')
        ->mountAction('approve')
        ->assertMountedActionModalSee('Esta versão tem 1 aviso(s) da apuração: confira o painel antes de aprovar.');
});

it('leaves out of the management panel the sales already listed as non conformities', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review->fresh());

    expect(array_column($workspace->warnings, 'code'))->toBe([SalesBoardIssueCode::SettlementStatusDivergence->value]);
});

it('records the warnings of each homologated construction outside the assessment hash', function () {
    $scenario = RolloutFixture::emission(1, 'W');
    $construction = $scenario['constructions'][0];
    $unit = ConstructionUnit::query()->where('construction_id', $construction->id)->orderBy('id')->firstOrFail();

    $contract = DerivationFixture::contract($unit, '2026-02-10', '500000.00', status: ContractStatus::Settled);
    DerivationFixture::installment($contract, '001', '2026-03-10', '500000.00', '2026-03-09', '500000.00');

    $operator = GovernanceFixture::operator();
    $homologation = RolloutFixture::open($scenario['emission'], $operator);
    $row = SalesBoardRolloutHomologationConstruction::query()->sole();

    app(SalesBoardRolloutHomologationService::class)->acceptDifference($row, 'Sem posição legada: conferido com a operação.', $operator);

    $hash = $homologation->fresh()->assessment_hash;

    expect($row->fresh()->frozenWarnings())->toBe([]);

    // Só o status muda: a fonte material e a posição continuam as mesmas.
    $contract->update(['status' => ContractStatus::Active]);

    app(SalesBoardRolloutHomologationService::class)->reassess($homologation->fresh(), $operator);

    $reassessed = $row->fresh();

    expect($homologation->fresh()->assessment_hash)->toBe($hash)
        ->and($reassessed->accepted_difference)->toBeTrue()
        ->and(array_column($reassessed->frozenWarnings(), 'code'))->toBe([SalesBoardIssueCode::SettlementStatusDivergence->value])
        ->and($reassessed->frozenWarnings()[0]['contract_id'])->toBe($contract->id);
});

it('shows the warnings of each construction on the rollout page', function () {
    $scenario = RolloutFixture::emission(2, 'R');
    $unit = ConstructionUnit::query()->where('construction_id', $scenario['constructions'][0]->id)->orderBy('id')->firstOrFail();

    $contract = DerivationFixture::contract($unit, '2026-02-10', '500000.00');
    DerivationFixture::installment($contract, '001', '2026-03-10', '500000.00', '2026-03-09', '500000.00');

    RolloutFixture::open($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Avisos da apuração na competência de comparação (não impedem a homologação)')
        ->assertSee('Status do contrato diverge da quitação apurada pelo cronograma')
        ->assertSee('SETTLEMENT_STATUS_DIVERGENCE');

    // A linha avaliada antes de os avisos serem gravados pede reavaliação.
    SalesBoardRolloutHomologationConstruction::query()->update(['warnings' => null]);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Reavalie para registrar os avisos.');
});
