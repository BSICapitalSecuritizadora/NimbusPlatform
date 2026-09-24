<?php

use App\Filament\Resources\Receivables\Pages\ViewReceivable;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeReceivableUiScenario(?SalesBoard &$createdSalesBoard = null): Receivable
{
    $emission = Emission::factory()->create([
        'name' => 'CRI Alto Bellevue',
        'bsi_code' => 'BSI-2026-0042',
    ]);

    Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Alto Bellevue Residencial',
    ]);

    $receivable = Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-08-01',
        'portfolio_id' => 'CARTEIRA-BELLEVUE-01',
        'active_contracts_count' => 142,
        'expected_interest_amount' => 150000.00,
        'expected_amortization_amount' => 850000.00,
        'received_installment_interest_amount' => 140000.00,
        'received_installment_amortization_amount' => 820000.00,
        'received_prepayment_interest_amount' => 5000.00,
        'received_prepayment_amortization_amount' => 45000.00,
        'received_default_interest_amount' => 2000.00,
        'received_default_amortization_amount' => 18000.00,
        'received_interest_and_penalty_amount' => 1500.00,
        'total_outstanding_balance_amount' => 18940000.00,
        'performing_balance_post_event_amount' => 18120000.00,
        'non_performing_balance_post_event_amount' => 820000.00,
        'performing_balance_pre_event_amount' => 18200000.00,
        'non_performing_balance_pre_event_amount' => 900000.00,
        'linked_credits_current_amount' => 17500000.00,
        'total_default_balance_amount' => 820000.00,
        'monthly_default_balance_amount' => 65000.00,
        'total_prepayment_amount' => 50000.00,
        'guarantees_value_amount' => 24500000.00,
        'portfolio_ltv_ratio' => 0.584000,
        'sale_ltv_ratio' => 0.621000,
        'top_five_debtors_concentration_ratio' => 0.145000,
        'portfolio_duration_years' => 3.250000,
        'portfolio_duration_months' => 39.000000,
        'average_rate_details' => "IPCA + 8.50% a.a.\nINCC-DI + 7.20% a.a.",
        // Aging / Faixas de vencimento
        'linked_credits_up_to_30_days_amount' => 950000.00,
        'linked_credits_31_to_60_days_amount' => 960000.00,
        'linked_credits_61_to_90_days_amount' => 940000.00,
        'linked_credits_91_to_120_days_amount' => 930000.00,
        'linked_credits_121_to_150_days_amount' => 920000.00,
        'linked_credits_151_to_180_days_amount' => 910000.00,
        'linked_credits_181_to_360_days_amount' => 5400000.00,
        'linked_credits_over_360_days_amount' => 6490000.00,
        'overdue_up_to_30_days_amount' => 32000.00,
        'overdue_31_to_60_days_amount' => 18000.00,
        'overdue_61_to_90_days_amount' => 12000.00,
        'overdue_91_to_120_days_amount' => 3000.00,
        'overdue_121_to_150_days_amount' => 0.00,
        'overdue_151_to_180_days_amount' => 0.00,
        'overdue_181_to_360_days_amount' => 0.00,
        'overdue_over_360_days_amount' => 0.00,
        'prepaid_up_to_30_days_amount' => 50000.00,
        'prepaid_31_to_60_days_amount' => 0.00,
        'prepaid_61_to_90_days_amount' => 0.00,
        'prepaid_91_to_120_days_amount' => 0.00,
        'prepaid_121_to_150_days_amount' => 0.00,
        'prepaid_151_to_180_days_amount' => 0.00,
        'prepaid_181_to_360_days_amount' => 0.00,
        'prepaid_over_360_days_amount' => 0.00,
    ]);

    return $receivable->fresh();
}

function makeReceivableAdmin(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

it('renders the executive view page for receivables with the expected header, context and edit action', function () {
    $this->actingAs(makeReceivableAdmin());
    $receivable = makeReceivableUiScenario();

    Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Visualizar Resumo de Recebíveis')
        ->assertSee('Alto Bellevue Residencial')
        ->assertSee('Competência 08/2026')
        ->assertSee('BSI-2026-0042')
        ->assertSee('CARTEIRA-BELLEVUE-01')
        ->assertActionExists('edit')
        ->assertActionHasLabel('edit', 'Editar');
});

it('displays the executive kpi banner with formatted financial numbers, responsive 3x2 grid, and tabular-nums', function () {
    $this->actingAs(makeReceivableAdmin());
    $receivable = makeReceivableUiScenario();

    $response = Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Saldo Devedor Total')
        ->assertSee('18.940.000,00')
        ->assertSee('Total Recebido')
        ->assertSee('1.031.500,00') // 140k + 820k + 5k + 45k + 2k + 18k + 1.5k
        ->assertSee('Inadimplência Geral')
        ->assertSee('820.000,00')
        ->assertSee('58,40%') // LTV Carteira
        ->assertSee('142') // Contratos ativos
        ->assertSee('18.120.000,00') // Adimplente pós-evento
        ->assertSee('Adimplente')
        ->assertSee('Atenção');

    $html = $response->html();
    expect($html)->toContain('grid-cols-1 md:grid-cols-2 xl:grid-cols-3')
        ->and($html)->toContain('gap-px bg-white/10');
});

it('renders the cash flow matrix with expected, received and realized totals', function () {
    $this->actingAs(makeReceivableAdmin());
    $receivable = makeReceivableUiScenario();

    Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Recebíveis — Fluxo do Mês')
        ->assertSee('Esperado no mês')
        ->assertSee('150.000,00') // juros esperado
        ->assertSee('850.000,00') // amortização esperada
        ->assertSee('1.000.000,00') // total esperado
        ->assertSee('Recebido de parcelas do mês')
        ->assertSee('Antecipação e quitações')
        ->assertSee('Recuperação de inadimplência')
        ->assertSee('Juros de mora e encargos contratuais')
        ->assertSee('TOTAL REALIZADO NO MÊS')
        ->assertSee('1.031.500,00');
});

it('renders the analytical projections table with all 8 chronological horizons and column subtotals', function () {
    $this->actingAs(makeReceivableAdmin());
    $receivable = makeReceivableUiScenario();

    Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Projeções Futuras e Faixas de Vencimento')
        ->assertSee('Até 30 dias')
        ->assertSee('31 a 60 dias')
        ->assertSee('61 a 90 dias')
        ->assertSee('91 a 120 dias')
        ->assertSee('121 a 150 dias')
        ->assertSee('151 a 180 dias')
        ->assertSee('181 a 360 dias')
        ->assertSee('Acima de 360 dias')
        ->assertSee('09/2026') // M+1
        ->assertSee('10/2026') // M+2
        ->assertSee('TOTAL DAS FAIXAS DE VENCIMENTO');
});

it('renders financial position balances and risk metrics as read-only infolist entries without disabled inputs', function () {
    $this->actingAs(makeReceivableAdmin());
    $receivable = makeReceivableUiScenario();

    $response = Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Posição Financeira')
        ->assertSee('Indicadores e Métricas de Risco')
        ->assertSee('LTV Carteira')
        ->assertSee('LTV Venda')
        ->assertSee('62,10%')
        ->assertSee('Concentração 5 Maiores')
        ->assertSee('14,50%')
        ->assertSee('3,25 anos')
        ->assertSee('39,00 meses')
        ->assertSee('Taxa Média da Carteira')
        ->assertSee('IPCA + 8.50% a.a.');

    // Certifica-se de que não estamos usando input desabilitado para exibição dos valores
    $html = $response->html();
    expect($html)->not->toContain('<input type="text" disabled')
        ->and($html)->not->toContain('readonly="readonly" name="total_outstanding_balance_amount"');
});

it('conditionally displays sales and stock when a sales board is present for the reference month and hides it when absent', function () {
    $this->actingAs(makeReceivableAdmin());
    $receivable = makeReceivableUiScenario();

    // 1. Sem quadro de vendas para a competência -> seção de Vendas e Estoque oculta
    Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSee('TOTAL DO EMPREENDIMENTO (VGV)');

    // 2. Com quadro de vendas cadastrado para a mesma competência -> exibe Vendas e Estoque
    $construction = $receivable->emission->constructions->first();
    SalesBoard::factory()->create([
        'emission_id' => $receivable->emission_id,
        'construction_id' => $construction?->id,
        'reference_month' => $receivable->reference_month,
        'total_units' => 100,
        'stock_units' => 25,
        'financed_units' => 60,
        'paid_units' => 15,
        'exchanged_units' => 0,
        'stock_value' => 12500000.00,
        'financed_value' => 30000000.00,
        'paid_value' => 7500000.00,
        'exchanged_value' => 0.00,
    ]);

    Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Vendas e Estoque')
        ->assertSee('TOTAL DO EMPREENDIMENTO (VGV)')
        ->assertSee('Financiadas (Carteira Ativa)')
        ->assertSee('Estoque Disponível')
        ->assertSee('50.000.000,00'); // VGV total (12.5m + 30m + 7.5m)
});
