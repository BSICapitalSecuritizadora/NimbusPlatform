<?php

use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalProject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('recalculates derived project sales and cost fields on create and update', function () {
    $company = ProposalCompany::query()->create([
        'name' => 'Construtora Exemplo',
        'cnpj' => '12.345.678/0001-90',
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Maria Silva',
        'email' => 'maria@example.com',
    ]);

    $proposal = Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'status' => 'pending',
    ]);

    $project = ProposalProject::query()->create([
        'proposal_id' => $proposal->id,
        'name' => 'Empreendimento Teste',
        'units_unpaid' => 74,
        'units_paid' => 0,
        'units_exchanged' => 146,
        'units_stock' => 309,
        'units_total' => 1,
        'sales_percentage' => 1,
        'cost_incurred' => '720.000,00',
        'cost_to_incur' => '1.680.000,00',
        'cost_total' => '1,00',
        'work_stage_percentage' => '99,99',
    ]);

    $project->refresh();

    expect((int) $project->units_unpaid)->toBe(74)
        ->and((int) $project->units_paid)->toBe(0)
        ->and((int) $project->units_exchanged)->toBe(146)
        ->and((int) $project->units_stock)->toBe(309)
        ->and((int) $project->units_total)->toBe(529)
        ->and((float) $project->sales_percentage)->toBe(19.32)
        ->and((float) $project->cost_incurred)->toBe(720000.0)
        ->and((float) $project->cost_to_incur)->toBe(1680000.0)
        ->and((float) $project->cost_total)->toBe(2400000.0)
        ->and((float) $project->work_stage_percentage)->toBe(30.0);

    $project->update([
        'units_unpaid' => 5,
        'units_paid' => 5,
        'units_exchanged' => 10,
        'units_stock' => 0,
        'units_total' => 99,
        'sales_percentage' => 99,
        'cost_incurred' => 0,
        'cost_to_incur' => 0,
        'cost_total' => 100,
        'work_stage_percentage' => 50,
    ]);

    $project->refresh();

    expect((int) $project->units_total)->toBe(20)
        ->and((float) $project->sales_percentage)->toBe(100.0)
        ->and((float) $project->cost_total)->toBe(0.0)
        ->and((float) $project->work_stage_percentage)->toBe(0.0);
});
