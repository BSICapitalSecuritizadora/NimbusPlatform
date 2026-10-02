<?php

use App\DTOs\ConstructionProgressData;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Enums\SalesBoardSource;
use App\Filament\Pages\Reports;
use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use App\Filament\Resources\EmissionMonthlyReportNotes\Pages\CreateEmissionMonthlyReportNote;
use App\Filament\Resources\EmissionMonthlyReportNotes\Pages\ListEmissionMonthlyReportNotes;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\EmissionMonthlyReportNote;
use App\Models\EmissionPuEvent;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\Negotiation;
use App\Models\PuHistory;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Models\User;
use App\Services\ConstructionProgressProvider;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use App\Services\Guarantees\EmissionOperationalDataset;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use App\Services\Reports\EmissionMonthlyReportService;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Dompdf\Frame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

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

it('justifies the notes body in the report template', function () {
    $emission = Emission::factory()->create();

    EmissionMonthlyReportNote::factory()->for($emission)->create([
        'reference_month' => '2026-05-01',
        'content' => 'Conteúdo da nota exibido no PDF.',
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-05-01'));
    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toMatch('/\.note-body\s*\{[^}]*text-align:\s*justify[^}]*\}/');
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

it('shows a contextual empty state when there are no report notes', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(ListEmissionMonthlyReportNotes::class)
        ->assertOk()
        ->assertSee('Gestão das observações complementares utilizadas na composição dos relatórios das emissões.')
        ->assertSee('Nenhuma nota explicativa cadastrada')
        ->assertSee('Cadastre a primeira nota explicativa para organizar observações complementares por emissão e competência.')
        ->assertDontSee('Nenhuma nota corresponde aos filtros selecionados');
});

it('distinguishes no results from an empty base and offers to clear filters', function () {
    $this->actingAs(makeAdminUser());

    EmissionMonthlyReportNote::factory()->for(Emission::factory())->create([
        'title' => 'Nota de Maio',
    ]);

    Livewire::test(ListEmissionMonthlyReportNotes::class)
        ->assertSee('Buscar por título ou emissão...')
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhuma nota corresponde aos filtros selecionados')
        ->assertSee('Limpar filtros')
        ->assertDontSee('Nenhuma nota explicativa cadastrada');
});

it('presents the report visibility as a scannable badge', function () {
    $this->actingAs(makeAdminUser());

    EmissionMonthlyReportNote::factory()->for(Emission::factory())->create([
        'title' => 'Nota visível',
        'is_visible_on_report' => true,
    ]);

    EmissionMonthlyReportNote::factory()->for(Emission::factory())->hidden()->create([
        'title' => 'Nota oculta',
    ]);

    Livewire::test(ListEmissionMonthlyReportNotes::class)
        ->assertSee('Incluída')
        ->assertSee('Oculta');
});

it('renders the create form as an editorial flow with a publication section', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateEmissionMonthlyReportNote::class)
        ->assertOk()
        ->assertSee('Registre observações complementares vinculadas à emissão e à competência do relatório.')
        ->assertSee('Identificação')
        ->assertSee('Conteúdo da Nota')
        ->assertSee('Publicação')
        ->assertSee('A nota será incluída no relatório da competência selecionada.');
});

it('adapts the publication helper to the toggle state', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateEmissionMonthlyReportNote::class)
        ->assertSee('A nota será incluída no relatório da competência selecionada.')
        ->set('data.is_visible_on_report', false)
        ->assertSee('A nota permanecerá apenas como registro interno, sem exibição no PDF.');
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
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    foreach ([['2026-03-01', 10, 5, 3, 1], ['2026-04-01', 8, 6, 4, 1], ['2026-05-01', 6, 7, 5, 1]] as [$month, $stock, $financed, $paid, $exchanged]) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
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

it('sums every construction of the emission in the units panel', function () {
    // GF-01: the panel used to be built from a single SalesBoard picked by
    // `first()`, so an emission with more than one development reported only
    // one of them. 18 + 180 has to read 198 -- never 18, never 180.
    $emission = Emission::factory()->create();
    $smaller = Construction::factory()->create(['emission_id' => $emission->id]);
    $larger = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $smaller)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 10,
        'financed_units' => 5,
        'paid_units' => 2,
        'exchanged_units' => 1,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $larger)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 100,
        'financed_units' => 50,
        'paid_units' => 20,
        'exchanged_units' => 10,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    $rows = collect($data['units']['rows']);

    expect($data['units']['has_data'])->toBeTrue()
        ->and($rows->firstWhere('label', 'Total')['value'])->toBe('198')
        ->and($rows->firstWhere('label', 'Estoque')['value'])->toBe('110')
        ->and($data['units']['coverage']['constructions_expected'])->toBe(2)
        ->and($data['units']['coverage']['constructions_covered'])->toBe(2);
});

it('carries a stale construction forward into the units panel', function () {
    // GF-02: the development that did not send a board in July still holds the
    // position it sent in May. Dropping it would make the emission look like it
    // had lost 100 units without a single sale.
    $emission = Emission::factory()->create();
    $updated = Construction::factory()->create(['emission_id' => $emission->id]);
    $stale = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $updated)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $stale)->create([
        'reference_month' => '2026-05-01',
        'stock_units' => 100,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    $rows = collect($data['units']['rows']);
    $historyRows = collect($data['units_history']['rows']);

    expect($rows->firstWhere('label', 'Total')['value'])->toBe('120')
        ->and($data['units']['coverage']['carried_forward'])->toBeTrue()
        ->and($data['units']['coverage']['reference_month_by_construction'][$stale->id])->toBe('2026-05-01')
        ->and($historyRows->firstWhere('competencia', '05/2026')['total'])->toBe('100')
        ->and($historyRows->firstWhere('competencia', '07/2026')['total'])->toBe('120');
});

it('reads the same consolidated position in the panel, in the history and in the guarantees', function () {
    $emission = Emission::factory()->create();
    $updated = Construction::factory()->create(['emission_id' => $emission->id]);
    $stale = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $updated)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => 2_000_000,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $stale)->create([
        'reference_month' => '2026-05-01',
        'stock_units' => 100,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => 10_000_000,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    $guaranteeBoards = (new EmissionOperationalDataset($emission))->salesBoardsForMonth('2026-07-01');

    $panelTotal = collect($data['units']['rows'])->firstWhere('label', 'Total')['value'];
    $historyTotal = collect($data['units_history']['rows'])->firstWhere('competencia', '07/2026')['total'];

    expect($panelTotal)->toBe('120')
        ->and($historyTotal)->toBe('120')
        ->and((int) $guaranteeBoards->sum('total_units'))->toBe(120)
        ->and((float) $guaranteeBoards->sum('stock_value'))->toBe(12_000_000.0);
});

it('does not count a construction that only got its first board after the competence', function () {
    $emission = Emission::factory()->create();
    $positioned = Construction::factory()->create(['emission_id' => $emission->id]);
    $laterConstruction = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $positioned)->create([
        'reference_month' => '2026-05-01',
        'stock_units' => 7,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $laterConstruction)->create([
        'reference_month' => '2026-09-01',
        'stock_units' => 500,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-06-01'));

    expect(collect($data['units']['rows'])->firstWhere('label', 'Total')['value'])->toBe('7')
        ->and($data['units']['coverage']['constructions_expected'])->toBe(1)
        ->and($data['units']['coverage']['constructions_covered'])->toBe(1)
        ->and($data['units']['coverage']['missing_construction_ids'])->toBe([]);
});

it('reports the units panel as empty when no construction has a position yet', function () {
    $emission = Emission::factory()->create();
    Construction::factory()->create(['emission_id' => $emission->id]);

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['units']['has_data'])->toBeFalse()
        ->and($data['units'])->not->toHaveKey('rows');
});

it('renders the coverage of a partial and carried-forward position in the PDF', function () {
    // The panel sums every development with its last known position. Without
    // the coverage next to the sum, a development stuck in 12/2025 and another
    // that never sent a board would print as a complete 07/2026 position.
    $emission = Emission::factory()->create();
    $updated = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);
    $stale = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Beta']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Gama']);

    foreach ([[$updated, '2026-06-01', 10], [$updated, '2026-07-01', 9], [$stale, '2025-12-01', 50]] as [$construction, $month, $stock]) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
            'reference_month' => $month,
            'stock_units' => $stock,
            'financed_units' => 0,
            'paid_units' => 0,
            'exchanged_units' => 0,
        ]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['units']['coverage_summary']['label'])->toBe('2 de 3 empreendimentos com posição')
        ->and($data['units']['coverage_summary']['complete'])->toBeFalse()
        ->and($data['units']['coverage_summary']['carried_forward'])->toBe([['name' => 'Residencial Beta', 'month' => '12/2025']])
        ->and($data['units']['coverage_summary']['missing'])->toBe(['Residencial Gama']);

    $html = view('pdf.emission-monthly-report', $data)->render();
    $unitsPanel = str($html)->after('Unidades / Quadro de Vendas')->before('Histórico de Unidades')->toString();

    expect($unitsPanel)->toContain('Cobertura')
        ->and($unitsPanel)->toContain('2 de 3 empreendimentos com posição')
        ->and($unitsPanel)->toContain('Posição parcial ou transportada.')
        ->and($unitsPanel)->toContain('Residencial Beta: última posição conhecida, quadro de 12/2025.')
        ->and($unitsPanel)->toContain('Sem quadro de vendas, fora da soma: Residencial Gama.')
        ->and($unitsPanel)->toContain('Posição por empreendimento')
        ->and($unitsPanel)->toContain('Última posição conhecida')
        ->and($unitsPanel)->toContain('Quadro da competência')
        ->and($unitsPanel)->toContain('Sem quadro de vendas</td>');
});

it('flags an automated competence still waiting for publication in the PDF', function () {
    // Two cycles delivered to Management, only one approved: the board of the
    // second development does not exist yet, and the report must say so.
    $emission = Emission::factory()->create([
        'status' => 'active',
        'sales_board_source' => SalesBoardSource::Automated,
        'sales_board_automation_start_reference_month' => '2026-07-01',
    ]);

    $published = ManagementReviewFixture::submittedCycleOn($emission, '1');
    $pending = ManagementReviewFixture::submittedCycleOn($emission, '2');

    ManagementReviewFixture::approve(ManagementReviewFixture::open($published['cycle']));

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission->fresh(), CarbonImmutable::parse('2026-07-01'));

    $html = view('pdf.emission-monthly-report', $data)->render();
    $pendingName = $pending['construction']->development_name;

    expect($data['units']['coverage_summary']['label'])->toBe('1 de 2 empreendimentos com posição')
        ->and($data['units']['coverage_summary']['awaiting_publication'])->toBeTrue()
        ->and($data['units']['coverage_summary']['awaiting'])->toBe([$pendingName])
        ->and($data['units']['coverage_summary']['cancelled'])->toBe([])
        ->and($html)->toContain('Posição parcial ou transportada.')
        ->and($html)->toContain('Competência produzida pelo ciclo mensal automatizado, ainda não publicada para: '.e($pendingName).'.');
});

it('labels a cancelled automated competence as cancelled and keeps the carried-forward position', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle'];
    $construction = $scenario['construction'];
    $emission = Emission::query()->findOrFail($cycle->emission_id);

    // Última posição conhecida, anterior ao início da automação.
    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2025-12-01',
        'stock_units' => 3,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    app(SalesBoardCycleCancellationService::class)->cancel(
        $cycle->fresh(),
        GovernanceFixture::approver(),
        'Competência refeita fora do ciclo por decisão da diretoria.',
    );

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission->fresh(), CarbonImmutable::parse($cycle->reference_month->toDateString()));

    $html = view('pdf.emission-monthly-report', $data)->render();
    $panel = str($html)->after('Unidades / Quadro de Vendas')->before('Posição por empreendimento')->squish()->toString();
    $name = e($construction->development_name);

    expect($data['units']['coverage_summary']['awaiting_publication'])->toBeFalse()
        ->and($data['units']['coverage_summary']['awaiting'])->toBe([])
        ->and($data['units']['coverage_summary']['cancelled'])->toBe([$construction->development_name])
        ->and($html)->not->toContain('ainda não publicada')
        ->and($panel)->toContain('Competência cancelada pela Gestão, sem quadro publicado neste mês: '.$name.'.')
        ->and($panel)->toContain($name.': última posição conhecida, quadro de 12/2025.')
        // O relatório vai para fora: o motivo interno do cancelamento não sai nele.
        ->and($html)->not->toContain('refeita fora do ciclo');
});

it('states a complete coverage without the partial position alert', function () {
    $emission = Emission::factory()->create();

    foreach (['Residencial Alfa', 'Residencial Beta'] as $name) {
        $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => $name]);

        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-07-01']);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($data['units']['coverage_summary']['complete'])->toBeTrue()
        ->and($html)->toContain('2 de 2 empreendimentos com posição')
        ->and($html)->not->toContain('Posição parcial ou transportada.')
        ->and($html)->toContain('Residencial Alfa')
        ->and($html)->toContain('07/2026');
});

it('shows the coverage of every competence in the units history', function () {
    $emission = Emission::factory()->create();
    $updated = Construction::factory()->create(['emission_id' => $emission->id]);
    $stale = Construction::factory()->create(['emission_id' => $emission->id]);
    Construction::factory()->create(['emission_id' => $emission->id]);

    foreach ([[$updated, '2026-06-01'], [$updated, '2026-07-01'], [$stale, '2025-12-01']] as [$construction, $month]) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => $month]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    $html = view('pdf.emission-monthly-report', $data)->render();
    $history = str($html)->after('Histórico de Unidades')->before('Negociações do Mês')->toString();

    // 12/2025: only the stale development had a board (the updated one is not
    // expected yet, the third never had one). 06 and 07/2026: two of three,
    // one of them carried forward from 12/2025.
    expect($history)->toContain('<th class="num">Cobertura</th>')
        ->and($history)->toContain('<td class="num">1/2</td>')
        ->and(substr_count($history, '<td class="num">2/3*</td>'))->toBe(2)
        ->and($history)->toContain('* indica ao menos um empreendimento com a última posição conhecida');
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
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    foreach ([['2026-04-01', 10, 5, 3, 1], ['2026-05-01', 6, 7, 5, 1]] as [$month, $stock, $financed, $paid, $exchanged]) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
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

    Livewire::test(Reports::class)
        ->assertOk()
        ->assertSee('Geração do relatório institucional mensal das emissões.')
        ->assertSee('Relatório mensal por emissão')
        ->assertSeeHtml('bsi-reports-section')
        ->assertSee('Competência inicial')
        ->assertSee('Competência final')
        ->assertSee('aria-describedby="report-generation-help"', false)
        ->assertSee('disabled', false)
        ->assertDontSee('href="#"', false);
});

it('summarizes the monthly report once emission and competence are selected', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'CRI Alto Bellevue',
        'if_code' => null,
        'isin_code' => null,
    ]);

    Livewire::test(Reports::class)
        ->set('emissionId', $emission->id)
        ->set('referenceMonth', '2026-08')
        ->assertSee('Relatório mensal')
        ->assertSee('CRI Alto Bellevue · Agosto de 2026')
        ->assertSee('target="_blank"', false);
});

it('summarizes a consolidated report when a final competence is selected', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'CRI Alto Bellevue',
        'if_code' => null,
        'isin_code' => null,
    ]);

    Livewire::test(Reports::class)
        ->set('emissionId', $emission->id)
        ->set('referenceMonth', '2026-01')
        ->set('referenceMonthEnd', '2026-08')
        ->assertSee('Relatório consolidado')
        ->assertSee('CRI Alto Bellevue · Janeiro de 2026 a Agosto de 2026');
});

it('blocks generation and explains when the final competence precedes the initial one', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'if_code' => null,
        'isin_code' => null,
    ]);

    Livewire::test(Reports::class)
        ->set('emissionId', $emission->id)
        ->set('referenceMonth', '2026-08')
        ->set('referenceMonthEnd', '2026-06')
        ->assertSee('A competência final deve ser igual ou posterior à competência inicial.')
        ->assertDontSee('target="_blank"', false);
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

/**
 * Garantia de estoque exigindo 120% do saldo devedor (PU 8.000 × 1.000 cotas)
 * sobre o Residencial Alfa, que só tem o quadro de julho: agosto sai com a
 * posição transportada.
 *
 * @return array{0: Emission, 1: Construction}
 */
function reportStockGuaranteeEmission(): array
{
    $emission = Emission::factory()->create(['issued_quantity' => 1000000, 'status' => 'active']);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Alfa',
    ]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-06-01',
        'quantity' => 1000,
        'unit_value' => 1,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);

    foreach (['2026-07-31', '2026-08-31'] as $date) {
        PuHistory::query()->create(['emission_id' => $emission->id, 'date' => $date, 'unit_value' => 8000]);
    }

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'stock_value' => 10_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->requiringPercentage(1.2)
        ->create([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    return [$emission, $construction];
}

/**
 * O texto de uma seção do PDF, sem marcação e com os espaços normalizados. Cada
 * tag vira um espaço, para que rótulo e valor de células vizinhas não colem.
 */
function reportPdfSectionText(string $html, string $title, string $nextTitle): string
{
    $section = str($html)
        ->after('<div class="section-title">'.$title.'</div>')
        ->before('<div class="section-title">'.$nextTitle.'</div>')
        ->toString();

    return str((string) preg_replace('/<[^>]+>/', ' ', $section))->squish()->toString();
}

it('renders the consolidated guarantees with the outdated and confirmed partial marks', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    $this->travelTo(Carbon::parse('2026-09-15 10:00:00', 'UTC'));
    [$emission, $construction] = reportStockGuaranteeEmission();

    app(GuaranteeSnapshotWriter::class)->close(
        $emission,
        '2026-08-01',
        makeAdminUser(),
        app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission, '2026-08-01')->salesBoardGapsFingerprint(),
    );

    // 13:00 UTC = 10:00 em Brasília: o quadro de agosto chega depois do fechamento.
    $this->travelTo(Carbon::parse('2026-09-16 13:00:00', 'UTC'));
    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-08-01',
        'stock_units' => 12,
        'stock_value' => 6_000_000,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-08-01'));
    $section = reportPdfSectionText(view('pdf.emission-monthly-report', $data)->render(), 'Garantias e Cobertura', 'Contas Vinculadas');

    expect($data['guarantees']['consolidated'])->toBeTrue()
        ->and($data['guarantees']['outdated'])->toBeTrue()
        ->and($data['guarantees']['partial_coverage_confirmed'])->toBeTrue()
        ->and($section)->not->toContain('Apuração preliminar.')
        ->and($section)->toContain('Competência desatualizada. Quadro de Vendas registrado em 16/09/2026 10:00, depois da apuração. O número fechado continua valendo até a competência ser reaberta e apurada de novo.')
        ->and($section)->toContain('Posição parcial do Quadro de Vendas nas garantias de estoque. Residencial Alfa: última posição conhecida (07/2026). Fechamento confirmado com a posição parcial.')
        // O número é o fechado, não o que o quadro novo daria.
        ->and($section)->toContain('Valor elegível R$ 10.000.000,00')
        ->and($section)->toContain('Competência de garantias fechada em 15/09/2026 07:00 (horário de Brasília).');

    // O consolidado traz a seção em cada competência: julho, ainda aberto, como preliminar.
    $consolidated = app(EmissionMonthlyReportService::class)->buildConsolidated(
        $emission->fresh(),
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-08-01'),
    );
    $consolidatedHtml = view('pdf.emission-monthly-report-consolidated', $consolidated)->render();

    expect(substr_count($consolidatedHtml, '<div class="section-title">Garantias e Cobertura</div>'))->toBe(2)
        ->and(substr_count($consolidatedHtml, 'Apuração preliminar.'))->toBe(1)
        ->and(substr_count($consolidatedHtml, 'Competência desatualizada.'))->toBe(1);
});

it('labels the guarantees of a competence that is not closed as preliminary', function () {
    $this->travelTo(Carbon::parse('2026-09-15 10:00:00', 'UTC'));
    [$emission] = reportStockGuaranteeEmission();

    // Atualizada e não fechada continua sendo apuração preliminar.
    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-08-01', makeAdminUser());

    $data = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-08-01'));
    $section = reportPdfSectionText(view('pdf.emission-monthly-report', $data)->render(), 'Garantias e Cobertura', 'Contas Vinculadas');

    expect($data['guarantees']['consolidated'])->toBeFalse()
        ->and($data['guarantees']['items'][0]['value_status'])->toBe('partial')
        ->and($section)->toContain('Apuração preliminar. A competência de garantias ainda não foi fechada: os valores abaixo são a apuração com as fontes vigentes na geração deste relatório e podem mudar até o fechamento.')
        ->and($section)->toContain('Quadro de vendas (parcial)')
        ->and($section)->toContain('Apuração do mês com as fontes vigentes na geração deste relatório.')
        ->and($section)->not->toContain('Competência de garantias fechada em');
});

/**
 * Os textos do PDF como o dompdf os desenhou: cada quebra de linha divide o nó
 * de texto, e uma palavra partida aparece em dois pedaços. Lidos durante o
 * desenho, porque o dompdf descarta a árvore de cada página depois dele.
 *
 * @param  array<string, mixed>  $data
 * @return list<string>
 */
function reportPdfRenderedTexts(array $data): array
{
    $texts = [];

    $dompdf = Pdf::loadView('pdf.emission-monthly-report', $data)->getDomPDF();
    $dompdf->setCallbacks([[
        'event' => 'begin_frame',
        'f' => function (Frame $frame) use (&$texts): void {
            if ($frame->get_node()->nodeName === '#text' && trim((string) $frame->get_node()->nodeValue) !== '') {
                $texts[] = trim((string) $frame->get_node()->nodeValue);
            }
        },
    ]]);
    $dompdf->render();

    return $texts;
}

/**
 * A célula do nome da garantia usava `word-break: break-word`, que o dompdf lê
 * como `overflow-wrap: anywhere`: a largura mínima da coluna caía para um
 * caractere, a tabela a estreitava e "Recebíveis" saía partido em duas linhas.
 */
it('keeps the guarantee names whole in the guarantees table of the PDF', function () {
    $this->travelTo(Carbon::parse('2026-09-15 10:00:00', 'UTC'));
    [$emission] = reportStockGuaranteeEmission();

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Receivables)
        ->create([
            'emission_id' => $emission->id,
            'name' => 'Recebíveis',
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-08-01'));

    $pieces = collect(reportPdfRenderedTexts($data))
        ->filter(fn (string $text): bool => str_starts_with($text, 'Recebív'))
        ->unique()
        ->values()
        ->all();

    expect(collect($data['guarantees']['items'])->pluck('name')->all())->toContain('Recebíveis')
        ->and($pieces)->toBe(['Recebíveis']);
});

it('states the absence of guarantees instead of zeros', function () {
    $emission = Emission::factory()->create(['status' => 'active']);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-08-01'));
    $section = reportPdfSectionText(view('pdf.emission-monthly-report', $data)->render(), 'Garantias e Cobertura', 'Contas Vinculadas');

    expect($data['guarantees']['has_data'])->toBeFalse()
        ->and($section)->toBe('Nenhuma garantia cadastrada para a emissão.');
});

it('keeps the totals of a closed competence whose guarantee positions were not recorded', function () {
    $emission = Emission::factory()->create(['status' => 'active']);

    // Fechamento com os totais, mas sem a posição de cada garantia gravada.
    GuaranteeSnapshot::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-08-01',
        'total_eligible_value' => 12_000_000,
        'active_guarantees_count' => 1,
        'closed_at' => '2026-09-15 10:00:00',
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-08-01'));
    $section = reportPdfSectionText(view('pdf.emission-monthly-report', $data)->render(), 'Garantias e Cobertura', 'Contas Vinculadas');

    expect($data['guarantees']['has_data'])->toBeTrue()
        ->and($data['guarantees']['items'])->toBe([])
        ->and($section)->toContain('Valor elegível R$ 12.000.000,00')
        ->and($section)->not->toContain('Nenhuma garantia cadastrada para a emissão.');
});

it('flags a closed competence recorded without sales board coverage', function () {
    $this->travelTo(Carbon::parse('2026-09-15 10:00:00', 'UTC'));
    [$emission, $construction] = reportStockGuaranteeEmission();
    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-08-01']);

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', makeAdminUser());

    // Fechamento gravado antes de a origem do quadro passar a ser registrada.
    DB::table('guarantee_snapshots')->where('emission_id', $emission->id)->update(['sales_board_coverage' => null]);

    $data = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-08-01'));
    $section = reportPdfSectionText(view('pdf.emission-monthly-report', $data)->render(), 'Garantias e Cobertura', 'Contas Vinculadas');

    expect($data['guarantees']['sales_board_coverage_unknown'])->toBeTrue()
        ->and($section)->toContain('Origem do Quadro de Vendas não registrada. A competência foi fechada antes de o sistema registrar de qual quadro saiu o estoque das garantias: não é possível indicar se a posição era a do próprio mês.');

    // Sem garantia de estoque, a origem do quadro não tinha o que registrar.
    $withoutStock = Emission::factory()->create(['status' => 'active']);
    $quotas = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $withoutStock->id, 'legal_status' => GuaranteeLegalStatus::Active]);
    app(GuaranteeSnapshotWriter::class)->recordManualValue($quotas, '08/2026', 1_000_000, makeAdminUser());
    app(GuaranteeSnapshotWriter::class)->close($withoutStock, '2026-08-01', makeAdminUser());

    $plain = app(EmissionMonthlyReportService::class)->build($withoutStock->fresh(), CarbonImmutable::parse('2026-08-01'));

    expect($plain['guarantees']['consolidated'])->toBeTrue()
        ->and($plain['guarantees']['sales_board_coverage_unknown'])->toBeFalse()
        ->and(view('pdf.emission-monthly-report', $plain)->render())->not->toContain('Origem do Quadro de Vendas não registrada.');
});

it('prints the generation instant in the business timezone', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    // 01:30 UTC de 01/10 = 22:30 de 30/09 em Brasília.
    $this->travelTo(Carbon::parse('2026-10-01 01:30:00', 'UTC'));
    $emission = Emission::factory()->create(['status' => 'active']);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-08-01'));
    $consolidated = app(EmissionMonthlyReportService::class)->buildConsolidated(
        $emission,
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-08-01'),
    );

    expect($data['meta']['generated_at'])->toBe('30/09/2026 22:30')
        ->and($consolidated['meta']['generated_at'])->toBe('30/09/2026 22:30')
        ->and(view('pdf.emission-monthly-report', $data)->render())->toContain('Documento gerado automaticamente pela plataforma em 30/09/2026 22:30.');
});

it('defaults the PDF route to the previous business month', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    $this->actingAs(makeAdminUser());

    // 22:30 de 30/09 em Brasília, já outubro no relógio da aplicação.
    $this->travelTo(Carbon::parse('2026-10-01 01:30:00', 'UTC'));
    $emission = Emission::factory()->create(['status' => 'active']);

    $response = $this->get(route('admin.emissions.monthly-report.pdf', ['emission' => $emission->id]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('relatorio-mensal-emissao-'.$emission->id.'-2026-08.pdf');

    // A competência com o dia continua aceita.
    $withDay = $this->get(route('admin.emissions.monthly-report.pdf', ['emission' => $emission->id, 'reference_month' => '2026-05-15']));

    $withDay->assertOk();
    expect($withDay->headers->get('content-disposition'))->toContain('relatorio-mensal-emissao-'.$emission->id.'-2026-05.pdf');
});

it('refuses an unreadable competence in the PDF route with 422', function (string $parameter, string $value) {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create(['status' => 'active']);

    $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
        $parameter => $value,
    ]))->assertStatus(422);
})->with([
    'texto livre' => ['reference_month', 'next month'],
    'mês inexistente' => ['reference_month', '2026-13'],
    'dia inexistente' => ['reference_month', '2026-02-31'],
    'formato da tela' => ['reference_month', '07/2026'],
    'competência final ilegível' => ['reference_month_end', 'xx'],
]);

it('opens the reports page on the previous business month, also after 21h of the last day', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    $this->actingAs(makeAdminUser());

    $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

    expect(Livewire::test(Reports::class)->get('referenceMonth'))->toBe('2026-08');

    // 01:30 UTC de 01/10 = 22:30 de 30/09 em Brasília: setembro ainda não acabou.
    $this->travelTo(Carbon::parse('2026-10-01 01:30:00', 'UTC'));

    expect(Livewire::test(Reports::class)->get('referenceMonth'))->toBe('2026-08');
});
