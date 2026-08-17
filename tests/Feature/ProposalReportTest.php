<?php

use App\Actions\Proposals\CalculateProjectIndicators;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalProject;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders complete analytical data with all nine indicators', function () {
    [$proposal, $firstProject] = proposalReportContext();
    $firstProject->load(['proposal.company.sectors', 'proposal.contact', 'characteristics.unitTypes', 'indicators']);
    $analysis = app(CalculateProjectIndicators::class)->handle($firstProject);

    $html = view('pdf.project-analytical', [
        'project' => $firstProject,
        'analysis' => $analysis,
    ])->render();

    expect($analysis['indicators'])->toHaveCount(9)
        ->and($html)->toContain('Dados gerais e cronograma')
        ->and($html)->toContain('Características construtivas e tipologias')
        ->and($html)->toContain('Resumo das unidades')
        ->and($html)->toContain('Ticket médio')
        ->and($html)->toContain('Percentual vendido líquido de permutas')
        ->and($html)->toContain('Custos e vendas / VGV')
        ->and($html)->toContain('Fluxo de recebimentos')
        ->and($html)->toContain('Financiamento / Custo de obra')
        ->and($html)->toContain('LTV — cobertura de estoque')
        ->and(substr_count($html, 'classification'))->toBe(0);
});

it('generates protected individual and consolidated pdfs for every project', function () {
    [$proposal, $firstProject] = proposalReportContext();
    $admin = makeAdminUser();

    $this->actingAs($admin)
        ->get(route('admin.projects.report', $firstProject))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($admin)
        ->get(route('admin.projects.analytical', $firstProject))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $response = $this->actingAs($admin)->get(route('admin.proposals.report', $proposal));
    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

it('renders the consolidated report with proposal, contact, and every project', function () {
    [$proposal] = proposalReportContext();
    $proposal->load([
        'company.sectors',
        'contact',
        'representative',
        'projects.characteristics.unitTypes',
        'projects.indicators',
        'files',
    ]);
    $calculator = app(CalculateProjectIndicators::class);
    $projectAnalyses = $proposal->projects
        ->mapWithKeys(fn (ProposalProject $project): array => [$project->id => $calculator->handle($project)]);

    $html = view('pdf.proposal-report', compact('proposal', 'projectAnalyses'))->render();

    expect($html)
        ->toContain('Construtora Relatórios')
        ->toContain('Ana Relatórios')
        ->toContain('Torre Aurora')
        ->toContain('Torre Horizonte')
        ->toContain('Consentimento WhatsApp')
        ->and(substr_count($html, 'Indicadores avançados'))->toBe(2);
});

it('denies report access to unauthenticated users', function () {
    [$proposal, $project] = proposalReportContext();

    $this->get(route('admin.projects.analytical', $project))->assertRedirect();
    $this->get(route('admin.proposals.report', $proposal))->assertRedirect();
});

it('prevents a representative from reading reports assigned to another representative', function () {
    [$proposal, $project] = proposalReportContext();
    $assignedUser = User::factory()->create();
    $assignedUser->assignRole('commercial-representative');
    $assignedRepresentative = ProposalRepresentative::factory()->create(['user_id' => $assignedUser->id]);
    $otherUser = User::factory()->create();
    $otherUser->assignRole('commercial-representative');
    ProposalRepresentative::factory()->create(['user_id' => $otherUser->id]);
    $proposal->forceFill(['assigned_representative_id' => $assignedRepresentative->id])->save();

    $this->actingAs($otherUser)
        ->get(route('admin.projects.analytical', $project))
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->get(route('admin.proposals.report', $proposal))
        ->assertForbidden();
});

/** @return array{Proposal, ProposalProject} */
function proposalReportContext(): array
{
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    $company = ProposalCompany::query()->create([
        'name' => 'Construtora Relatórios',
        'cnpj' => validTestCnpj(fake()->unique()->numberBetween(1000, 9000)),
        'cidade' => 'São Paulo',
        'estado' => 'SP',
    ]);
    $company->sectors()->attach($sector);
    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Ana Relatórios',
        'email' => 'ana-relatorios@example.com',
        'phone_personal' => '(11) 99999-0000',
        'is_whatsapp' => true,
        'whatsapp_contact_consent' => true,
    ]);
    $proposal = Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'status' => ProposalStatus::InReview->value,
    ]);

    foreach (['Torre Aurora', 'Torre Horizonte'] as $index => $name) {
        $project = $proposal->projects()->create([
            'name' => $name,
            'development_name' => "SPE Relatórios {$index}",
            'requested_amount' => 7000000,
            'land_market_value' => 2000000,
            'land_area' => 1000,
            'construction_start_date' => '2026-01-01',
            'delivery_forecast_date' => '2028-12-01',
            'launch_date' => '2025-10-01',
            'sales_launch_date' => '2025-11-01',
            'exchanged_units' => 10,
            'paid_units' => 20,
            'unpaid_units' => 30,
            'stock_units' => 40,
            'incurred_cost' => 3000000,
            'cost_to_incur' => 7000000,
            'paid_sales_value' => 2000000,
            'unpaid_sales_value' => 8000000,
            'stock_sales_value' => 10000000,
            'received_value' => 2000000,
            'value_until_keys' => 3000000,
            'value_after_keys' => 5000000,
        ]);
        $characteristics = $project->characteristics()->create([
            'blocks' => 2, 'floors' => 18, 'typical_floors' => 15, 'units_per_floor' => 4, 'total_units' => 120,
        ]);
        $characteristics->unitTypes()->create([
            'order' => 1, 'total_units' => 100, 'bedrooms' => '2', 'parking_spaces' => '1', 'usable_area' => 80, 'average_price' => 800000, 'price_per_square_meter' => 10000,
        ]);
        $project->indicators()->create([
            'financiamento_custo_obra_ideal' => 70, 'financiamento_custo_obra_limite' => 90,
            'financiamento_vgv_ideal' => 65, 'financiamento_vgv_limite' => 75,
            'custo_obra_vgv_ideal' => 65, 'custo_obra_vgv_limite' => 75,
            'recebiveis_vfcto_ideal' => 100, 'recebiveis_vfcto_limite' => 95,
            'recebiveis_terreno_vfcto_ideal' => 100, 'recebiveis_terreno_vfcto_limite' => 95,
            'vendas_liquido_permutas_ideal' => 30, 'vendas_liquido_permutas_limite' => 25,
            'terreno_vgv_ideal' => 15, 'terreno_vgv_limite' => 20,
            'terreno_custo_obra_ideal' => 25, 'terreno_custo_obra_limite' => 35,
            'ltv_ideal' => 55, 'ltv_limite' => 50,
        ]);
    }

    return [$proposal, $proposal->projects()->oldest('id')->firstOrFail()];
}
