<?php

use App\DTOs\ConstructionProgressData;
use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use App\Filament\Resources\EmissionMonthlyReportNotes\Pages\ListEmissionMonthlyReportNotes;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\EmissionMonthlyReportNote;
use App\Models\EmissionPuEvent;
use App\Models\Expense;
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
        ->and($data['construction']['constructions'][0]['name'])->toBe('Residencial Sem Medição');

    $html = view('pdf.emission-monthly-report', $data)->render();

    expect($html)->toContain('Dados de evolução da obra ainda não consolidados para este período.')
        ->and($html)->toContain('Residencial Sem Medição');
});

it('renders the reports page with the generation form', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(\App\Filament\Pages\Reports::class)
        ->assertOk()
        ->assertSee('Relatório Mensal por Emissão');
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
