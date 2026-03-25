<?php

use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalProject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('recalculates derived project cost fields on create and update', function () {
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
        'cost_incurred' => '720.000,00',
        'cost_to_incur' => '1.680.000,00',
        'cost_total' => '1,00',
        'work_stage_percentage' => '99,99',
    ]);

    $project->refresh();

    expect((float) $project->cost_incurred)->toBe(720000.0)
        ->and((float) $project->cost_to_incur)->toBe(1680000.0)
        ->and((float) $project->cost_total)->toBe(2400000.0)
        ->and((float) $project->work_stage_percentage)->toBe(30.0);

    $project->update([
        'cost_incurred' => 0,
        'cost_to_incur' => 0,
        'cost_total' => 100,
        'work_stage_percentage' => 50,
    ]);

    $project->refresh();

    expect((float) $project->cost_total)->toBe(0.0)
        ->and((float) $project->work_stage_percentage)->toBe(0.0);
});
