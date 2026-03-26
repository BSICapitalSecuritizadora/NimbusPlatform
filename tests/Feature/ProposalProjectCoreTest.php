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
        'value_paid' => '1.234.567,89',
        'value_unpaid' => '234.567,89',
        'value_stock' => '345.678,90',
        'value_received' => '456.789,01',
        'value_until_keys' => '567.890,12',
        'value_post_keys' => '678.901,23',
        'value_total_sale' => '7.654.321,98',
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
        ->and((float) $project->value_paid)->toBe(1234567.89)
        ->and((float) $project->value_unpaid)->toBe(234567.89)
        ->and((float) $project->value_stock)->toBe(345678.9)
        ->and((float) $project->value_received)->toBe(456789.01)
        ->and((float) $project->value_until_keys)->toBe(567890.12)
        ->and((float) $project->value_post_keys)->toBe(678901.23)
        ->and((float) $project->value_total_sale)->toBe(7654321.98)
        ->and(ProposalProject::calculatePaymentFlowTotal(
            $project->value_received,
            $project->value_until_keys,
            $project->value_post_keys,
        ))->toBe(1703580.36)
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
        'value_paid' => '10.000,00',
        'value_unpaid' => '20.000,50',
        'value_stock' => '30.000,75',
        'value_received' => '40.100,25',
        'value_until_keys' => '50.200,50',
        'value_post_keys' => '60.300,75',
        'value_total_sale' => '70.400,99',
    ]);

    $project->refresh();

    expect((int) $project->units_total)->toBe(20)
        ->and((float) $project->sales_percentage)->toBe(100.0)
        ->and((float) $project->cost_total)->toBe(0.0)
        ->and((float) $project->value_paid)->toBe(10000.0)
        ->and((float) $project->value_unpaid)->toBe(20000.5)
        ->and((float) $project->value_stock)->toBe(30000.75)
        ->and((float) $project->value_received)->toBe(40100.25)
        ->and((float) $project->value_until_keys)->toBe(50200.5)
        ->and((float) $project->value_post_keys)->toBe(60300.75)
        ->and((float) $project->value_total_sale)->toBe(70400.99)
        ->and(ProposalProject::calculatePaymentFlowTotal(
            $project->value_received,
            $project->value_until_keys,
            $project->value_post_keys,
        ))->toBe(150601.5)
        ->and((float) $project->work_stage_percentage)->toBe(0.0);
});
