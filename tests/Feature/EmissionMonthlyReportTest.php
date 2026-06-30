<?php

use App\DTOs\ConstructionProgressData;
use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use App\Filament\Resources\EmissionMonthlyReportNotes\Pages\ListEmissionMonthlyReportNotes;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\EmissionMonthlyReportNote;
use App\Models\EmissionPuEvent;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\Negotiation;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Models\User;
use App\Services\ConstructionProgressProvider;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('generates the monthly report PDF for an emission with data', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'current_pu' => '1011.03301900',
        'issued_quantity' => 10000,
        'integralized_quantity' => 10000,
    ]);

    Fund::factory()->create(['emission_id' => $emission->id]);

    Expense::factory()->create([
        'emission_id' => $emission->id,
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-05-01',
    ]);

    Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-05-01',
    ]);

    SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-05-01',
    ]);

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('generates the report even when monthly data is missing', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('builds an enriched calendar section from PU events', function () {
    $emission = Emission::factory()->create();

    EmissionPuEvent::factory()->for($emission)->create([
        'event_type' => 'amortization',
        'amortization_type' => 'percentage',
        'amortization_value' => '0.05',
        'original_date' => '2026-05-10',
        'effective_date' => '2026-05-12',
        'sequence' => 1,
    ]);

    EmissionPuEvent::factory()->for($emission)->create([
        'event_type' => 'interest_payment',
        'amortization_type' => 'none',
        'amortization_value' => null,
        'original_date' => '2026-06-10',
        'effective_date' => '2026-06-10',
        'sequence' => 2,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    $highlight = collect($data['calendar']['highlight']);

    expect($data['calendar']['has_data'])->toBeTrue()
        ->and($data['calendar']['has_upcoming'])->toBeTrue()
        ->and($highlight->firstWhere('label', 'Amortização')['value'])->toBe('5,00%')
        ->and($highlight->firstWhere('label', 'Tipo de evento')['value'])->toBe('Amortização')
        ->and($highlight->firstWhere('label', 'Situação')['value'])->toContain('Reagendado')
        ->and($data['calendar']['upcoming'])->toHaveCount(2);
});

it('keeps the calendar section graceful when there are no PU events', function () {
    $emission = Emission::factory()->create();

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['calendar']['has_data'])->toBeFalse()
        ->and($data['calendar'])->toHaveKey('empty_message');
});

it('includes only visible notes for the emission and reference month', function () {
    $emission = Emission::factory()->create();
    $otherEmission = Emission::factory()->create();

    EmissionMonthlyReportNote::factory()->for($emission)->create([
        'reference_month' => '2026-05-01',
        'title' => 'Nota de Maio',
        'content' => 'Comentário visível do período.',
        'is_visible_on_report' => true,
    ]);

    EmissionMonthlyReportNote::factory()->for($emission)->hidden()->create([
        'reference_month' => '2026-05-01',
    ]);

    EmissionMonthlyReportNote::factory()->for($emission)->create([
        'reference_month' => '2026-04-01',
    ]);

    EmissionMonthlyReportNote::factory()->for($otherEmission)->create([
        'reference_month' => '2026-05-01',
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['notes']['has_data'])->toBeTrue()
        ->and($data['notes']['rows'])->toHaveCount(1)
        ->and($data['notes']['rows'][0]['title'])->toBe('Nota de Maio');
});

it('shows a friendly message when there are no notes for the period', function () {
    $emission = Emission::factory()->create();

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['notes']['has_data'])->toBeFalse()
        ->and($data['notes']['empty_message'])->toBe('Nenhum comentário cadastrado para este período.');
});

it('generates the PDF including a registered note', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    EmissionMonthlyReportNote::factory()->for($emission)->create([
        'reference_month' => '2026-05-01',
        'content' => 'Observação relevante do mês.',
    ]);

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('renders the notes content in the report template', function () {
    $emission = Emission::factory()->create();

    EmissionMonthlyReportNote::factory()->for($emission)->create([
        'reference_month' => '2026-05-01',
        'title' => 'Destaque do mês',
        'content' => 'Conteúdo da nota exibido no PDF.',
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-05-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Destaque do mês')
        ->and($html)->toContain('Conteúdo da nota exibido no PDF.');
});

it('renders a friendly empty message in the notes section when there are none', function () {
    $emission = Emission::factory()->create();

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-05-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Nenhum comentário cadastrado para este período.');
});

it('lets admins manage report notes through the Filament resource', function () {
    $this->actingAs(makeAdminUser());

    expect(EmissionMonthlyReportNoteResource::canViewAny())->toBeTrue()
        ->and(EmissionMonthlyReportNoteResource::canCreate())->toBeTrue();

    Livewire::test(ListEmissionMonthlyReportNotes::class)->assertOk();
});

it('builds the monthly analysis (paid vs unpaid) from receivable data', function () {
    $emission = Emission::factory()->create();

    Receivable::factory()->for($emission)->create([
        'reference_month' => '2026-05-01',
        'expected_interest_amount' => 1000,
        'expected_amortization_amount' => 0,
        'received_installment_interest_amount' => 600,
        'received_installment_amortization_amount' => 0,
        'received_prepayment_interest_amount' => 50,
        'received_prepayment_amortization_amount' => 0,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['analise_mes']['has_data'])->toBeTrue()
        ->and($data['analise_mes']['paid_percent'])->toBe(60.0)
        ->and($data['analise_mes']['unpaid_percent'])->toBe(40.0)
        ->and($data['analise_mes']['paid_percent_label'])->toBe('60,00%');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Análise do Mês — Recebíveis')
        ->and($html)->toContain('60,00%')
        ->and($html)->toContain('R$ 600,00');
});

it('shows a friendly message in the analysis section when receivables are missing', function () {
    $emission = Emission::factory()->create();

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['analise_mes']['has_data'])->toBeFalse()
        ->and($data['analise_mes']['empty_message'])->toBe('Dados ainda não consolidados para este período.');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Dados ainda não consolidados para este período.');
});

it('builds construction progress when measurement data is available', function () {
    $this->app->bind(ConstructionProgressProvider::class, fn (): ConstructionProgressProvider => new class implements ConstructionProgressProvider
    {
        public function forEmission(Emission $emission, CarbonInterface $referenceMonth, ?Construction $construction = null): ?ConstructionProgressData
        {
            return new ConstructionProgressData(
                planName: 'Plano Padrão',
                plannedMonthlyPercent: 5.0,
                plannedCumulativePercent: 40.0,
                realizedMonthlyPercent: 4.5,
                realizedCumulativePercent: 38.0,
                diffPercent: -2.0,
                trend: 'Abaixo',
                measurementDate: CarbonImmutable::parse('2026-05-20'),
            );
        }
    });

    $emission = Emission::factory()->create();
    Construction::factory()->for($emission)->create(['development_name' => 'Residencial Aurora']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['construction']['has_progress'])->toBeTrue()
        ->and($data['construction']['progress'][0]['realized_cumulative'])->toBe('38,00%')
        ->and($data['construction']['progress'][0]['bar_percent'])->toBe(38.0)
        ->and($data['construction']['progress'][0]['trend'])->toBe('Abaixo');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Evolução da Obra (%)')
        ->and($html)->toContain('Residencial Aurora')
        ->and($html)->toContain('38,00%');
});

it('renders the PDF without breaking when construction progress bars are present', function () {
    $this->actingAs(makeAdminUser());

    $this->app->bind(ConstructionProgressProvider::class, fn (): ConstructionProgressProvider => new class implements ConstructionProgressProvider
    {
        public function forEmission(Emission $emission, CarbonInterface $referenceMonth, ?Construction $construction = null): ?ConstructionProgressData
        {
            return new ConstructionProgressData(
                planName: 'Plano Padrão',
                plannedMonthlyPercent: 5.0,
                plannedCumulativePercent: 40.0,
                realizedMonthlyPercent: 4.5,
                realizedCumulativePercent: 38.0,
                diffPercent: -2.0,
                trend: 'Abaixo',
                measurementDate: CarbonImmutable::parse('2026-05-20'),
            );
        }
    });

    $emission = Emission::factory()->create();
    Construction::factory()->for($emission)->create(['development_name' => 'Residencial Aurora']);

    foreach (['2026-03-01', '2026-04-01', '2026-05-01'] as $month) {
        Receivable::factory()->for($emission)->create([
            'reference_month' => $month,
            'expected_interest_amount' => 1000,
            'received_installment_interest_amount' => 700,
        ]);
    }

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('keeps construction section graceful and lists linked developments without progress', function () {
    $emission = Emission::factory()->create();
    Construction::factory()->for($emission)->create([
        'development_name' => 'Residencial Sem Medição',
        'city' => 'São Paulo',
        'state' => 'SP',
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['construction']['has_progress'])->toBeFalse()
        ->and($data['construction']['has_constructions'])->toBeTrue()
        ->and($data['construction']['constructions'][0]['name'])->toBe('Residencial Sem Medição')
        ->and($data['construction_history']['has_data'])->toBeFalse();

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Dados de evolução da obra ainda não consolidados para este período.')
        ->and($html)->toContain('Residencial Sem Medição')
        ->and($html)->not->toContain('Histórico de Evolução da Obra');
});

it('builds a receivables history series across competences', function () {
    $emission = Emission::factory()->create();

    foreach (['2026-03-01', '2026-04-01', '2026-05-01'] as $month) {
        Receivable::factory()->for($emission)->create([
            'reference_month' => $month,
            'expected_interest_amount' => 1000,
            'expected_amortization_amount' => 0,
            'received_installment_interest_amount' => 800,
            'received_installment_amortization_amount' => 0,
            'overdue_up_to_30_days_amount' => 100,
        ]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['receivables_history']['has_data'])->toBeTrue()
        ->and($data['receivables_history']['rows'])->toHaveCount(3)
        ->and($data['receivables_history']['rows'][0]['competencia'])->toBe('03/2026')
        ->and($data['receivables_history']['rows'][2]['received_percent'])->toBe('80,00%');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Histórico de Recebíveis e Inadimplência')
        ->and($html)->toContain('03/2026');
});

it('omits the receivables history when there is a single competence', function () {
    $emission = Emission::factory()->create();

    Receivable::factory()->for($emission)->create(['reference_month' => '2026-05-01']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['receivables_history']['has_data'])->toBeFalse();

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->not->toContain('Histórico de Recebíveis e Inadimplência');
});

it('builds a construction history series from monthly measurements', function () {
    $this->app->bind(ConstructionProgressProvider::class, fn (): ConstructionProgressProvider => new class implements ConstructionProgressProvider
    {
        public function forEmission(Emission $emission, CarbonInterface $referenceMonth, ?Construction $construction = null): ?ConstructionProgressData
        {
            $month = CarbonImmutable::parse($referenceMonth->toDateString());

            return new ConstructionProgressData(
                planName: 'Plano Padrão',
                plannedMonthlyPercent: 5.0,
                plannedCumulativePercent: 40.0,
                realizedMonthlyPercent: 4.0,
                realizedCumulativePercent: 35.0,
                diffPercent: -5.0,
                trend: 'Abaixo',
                measurementDate: $month->addDays(10),
            );
        }
    });

    $emission = Emission::factory()->create();
    Construction::factory()->for($emission)->create(['development_name' => 'Residencial Aurora']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['construction_history']['has_data'])->toBeTrue()
        ->and($data['construction_history']['series'][0]['name'])->toBe('Residencial Aurora')
        ->and(count($data['construction_history']['series'][0]['points']))->toBeGreaterThanOrEqual(2)
        ->and($data['construction_history']['series'][0]['points'][0]['realized_cumulative'])->toBe('35,00%');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Histórico de Evolução da Obra');
});

it('builds a units history series across competences', function () {
    $emission = Emission::factory()->create();

    foreach ([['2026-03-01', 10, 5, 3, 1], ['2026-04-01', 8, 6, 4, 1], ['2026-05-01', 6, 7, 5, 1]] as [$month, $stock, $financed, $paid, $exchanged]) {
        SalesBoard::factory()->for($emission)->create([
            'reference_month' => $month,
            'stock_units' => $stock,
            'financed_units' => $financed,
            'paid_units' => $paid,
            'exchanged_units' => $exchanged,
        ]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['units_history']['has_data'])->toBeTrue()
        ->and($data['units_history']['rows'])->toHaveCount(3)
        ->and($data['units_history']['rows'][0]['competencia'])->toBe('03/2026')
        ->and($data['units_history']['rows'][2]['total'])->toBe('19');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Histórico de Unidades')
        ->and($html)->toContain('03/2026');
});

it('omits the units history when there is a single competence', function () {
    $emission = Emission::factory()->create();
    SalesBoard::factory()->for($emission)->create(['reference_month' => '2026-05-01']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['units_history']['has_data'])->toBeFalse();

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->not->toContain('Histórico de Unidades');
});

it('builds a negotiations history series across competences', function () {
    $emission = Emission::factory()->create();

    foreach ([['2026-03-01', 5, 1], ['2026-04-01', 7, 2], ['2026-05-01', 4, 0]] as [$month, $sales, $cancellations]) {
        Negotiation::factory()->for($emission)->create([
            'reference_month' => $month,
            'sales' => $sales,
            'cancellations' => $cancellations,
        ]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['negotiations_history']['has_data'])->toBeTrue()
        ->and($data['negotiations_history']['rows'])->toHaveCount(3)
        ->and($data['negotiations_history']['rows'][1]['net'])->toBe('5');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Histórico de Negociações');
});

it('omits the negotiations history when there is a single competence', function () {
    $emission = Emission::factory()->create();
    Negotiation::factory()->for($emission)->create(['reference_month' => '2026-05-01']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['negotiations_history']['has_data'])->toBeFalse();

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->not->toContain('Histórico de Negociações');
});

it('adds proportional bars to the delinquency bands', function () {
    $emission = Emission::factory()->create();

    Receivable::factory()->for($emission)->create([
        'reference_month' => '2026-05-01',
        'overdue_up_to_30_days_amount' => 300,
        'overdue_31_to_60_days_amount' => 100,
        'overdue_61_to_90_days_amount' => 0,
        'overdue_91_to_120_days_amount' => 0,
        'overdue_121_to_150_days_amount' => 0,
        'overdue_151_to_180_days_amount' => 0,
        'overdue_181_to_360_days_amount' => 0,
        'overdue_over_360_days_amount' => 0,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['delinquency']['has_data'])->toBeTrue()
        ->and($data['delinquency']['rows'][0]['bar_percent'])->toBe(75.0)
        ->and($data['delinquency']['rows'][1]['bar_percent'])->toBe(25.0);

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Distribuição')
        ->and($html)->toContain('mini-fill');
});

it('adds composition and variation to the units history', function () {
    $emission = Emission::factory()->create();

    foreach ([['2026-04-01', 10, 5, 3, 1], ['2026-05-01', 6, 7, 5, 1]] as [$month, $stock, $financed, $paid, $exchanged]) {
        SalesBoard::factory()->for($emission)->create([
            'reference_month' => $month,
            'stock_units' => $stock,
            'financed_units' => $financed,
            'paid_units' => $paid,
            'exchanged_units' => $exchanged,
        ]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    $rows = $data['units_history']['rows'];

    expect($rows[0]['variation'])->toBe('—')
        ->and($rows[1]['variation'])->toBe('0')
        ->and($rows[0]['composition'])->not->toBe([])
        ->and($rows[1]['composition'][0]['class'])->toBe('seg-1');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Composição');
});

it('builds an expenses history series from expense occurrences', function () {
    $emission = Emission::factory()->create();
    $expense = Expense::factory()->for($emission)->create();

    foreach (['2026-03-15' => 1000, '2026-04-15' => 1500, '2026-05-15' => 1200] as $due => $amount) {
        ExpenseHistory::create([
            'expense_id' => $expense->id,
            'amount' => $amount,
            'due_date' => $due,
        ]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['expenses_history']['has_data'])->toBeTrue()
        ->and($data['expenses_history']['rows'])->toHaveCount(3)
        ->and($data['expenses_history']['rows'][0]['competencia'])->toBe('03/2026')
        ->and($data['expenses_history']['rows'][1]['total'])->toBe('R$ 1.500,00')
        ->and($data['expenses_history']['rows'][1]['variation'])->toBe('+R$ 500,00');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Histórico de Despesas');
});

it('omits the expenses history when there is a single competence', function () {
    $emission = Emission::factory()->create();
    $expense = Expense::factory()->for($emission)->create();
    ExpenseHistory::create(['expense_id' => $expense->id, 'amount' => 1000, 'due_date' => '2026-05-15']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['expenses_history']['has_data'])->toBeFalse();

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->not->toContain('Histórico de Despesas');
});

it('builds a consolidated multi-month report', function () {
    $emission = Emission::factory()->create();

    Receivable::factory()->for($emission)->create([
        'reference_month' => '2026-04-01',
        'expected_interest_amount' => 1000,
        'received_installment_interest_amount' => 800,
    ]);
    Receivable::factory()->for($emission)->create([
        'reference_month' => '2026-05-01',
        'expected_interest_amount' => 1000,
        'received_installment_interest_amount' => 900,
    ]);

    $data = app(EmissionMonthlyReportService::class)->buildConsolidated(
        $emission,
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-05-01'),
    );

    expect($data['months'])->toHaveCount(2)
        ->and($data['months'][0]['label'])->toBe('Abril de 2026')
        ->and($data['meta']['period_label'])->toBe('Abril de 2026 a Maio de 2026');

    $html = view('pdf.emission-monthly-report-consolidated', $data)->render();

    expect($html)->toContain('Relatório Mensal Consolidado')
        ->and($html)->toContain('Competência: Abril de 2026')
        ->and($html)->toContain('Competência: Maio de 2026');
});

it('generates the consolidated PDF via the route even without data', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-04',
        'reference_month_end' => '2026-06',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('falls back to the single-month report when no end month is provided', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('renders the reports page with the generation form', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(\App\Filament\Pages\Reports::class)
        ->assertOk()
        ->assertSee('Relatório Mensal por Emissão');
});

it('builds the Resumo da Operação saldo devedor from the PU history at the data-base times the integralized quantity', function () {
    $emission = Emission::factory()->create([
        'integralized_quantity' => 10000,
    ]);

    $emission->puHistories()->create(['date' => '2026-06-15', 'unit_value' => '1000.000000']);
    $emission->puHistories()->create(['date' => '2026-06-30', 'unit_value' => '1011.033019']);
    $emission->puHistories()->create(['date' => '2026-07-05', 'unit_value' => '1020.000000']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-06-01'));

    expect($data['header']['debt_position'])->toBe('30/06/2026')
        ->and($data['header']['current_pu'])->toBe('R$ 1.011,03301900')
        ->and($data['header']['debt_balance'])->toBe('R$ 10.110.330,19');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('<td class="label">Saldo Devedor</td>');
});

it('uses the last available PU on or before the data-base when there is none exactly on the last day', function () {
    $emission = Emission::factory()->create([
        'integralized_quantity' => 5000,
    ]);

    $emission->puHistories()->create(['date' => '2026-06-20', 'unit_value' => '900.000000']);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-06-01'));

    expect($data['header']['current_pu'])->toBe('R$ 900,00000000')
        ->and($data['header']['debt_balance'])->toBe('R$ 4.500.000,00');
});

it('takes the próximo evento from the payment schedule (Cronograma de Pagamentos)', function () {
    $emission = Emission::factory()->create();

    $emission->payments()->create([
        'payment_date' => '2026-06-09',
        'premium_value' => 0,
        'interest_value' => 0,
        'amortization_value' => 0,
        'extra_amortization_value' => 0,
    ]);
    $emission->payments()->create([
        'payment_date' => '2026-07-09',
        'premium_value' => 0,
        'interest_value' => 0,
        'amortization_value' => 0,
        'extra_amortization_value' => 0,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-06-01'));

    expect($data['header']['next_event'])->toBe('09/06/2026');
});

it('keeps the Resumo da Operação graceful without PU, integralized quantity or payment schedule', function () {
    $emission = Emission::factory()->create([
        'integralized_quantity' => 0,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-06-01'));

    expect($data['header']['debt_balance'])->toBe('Não informado')
        ->and($data['header']['current_pu'])->toBe('Não informado')
        ->and($data['header']['next_event'])->toBe('Nenhum evento cadastrado');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('<td class="label">Saldo Devedor</td>');
});

it('forbids users without the reports.view permission', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('commercial-representative');
    $this->actingAs($user);

    $emission = Emission::factory()->create();

    $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]))->assertForbidden();
});
