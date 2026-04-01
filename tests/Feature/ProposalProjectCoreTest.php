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
        'requested_amount' => '26500000.00',
        'land_market_value' => '1850000.00',
        'land_area' => '2689.00',
        'remaining_months' => '53',
        'unpaid_units' => 74,
        'paid_units' => 0,
        'exchanged_units' => 146,
        'stock_units' => 309,
        'units_total' => 1,
        'sales_percentage' => 1,
        'incurred_cost' => '720000.00',
        'cost_to_incur' => '1680000.00',
        'total_cost' => '1.00',
        'work_stage_percentage' => '99.99',
        'paid_sales_value' => '1234567.89',
        'unpaid_sales_value' => '234567.89',
        'stock_sales_value' => '345678.90',
        'received_value' => '456789.01',
        'value_until_keys' => '567890.12',
        'value_after_keys' => '678901.23',
        'gross_sales_value' => '7654321.98',
    ]);

    $project->refresh();

    expect((int) $project->unpaid_units)->toBe(74)
        ->and((float) $project->requested_amount)->toBe(26500000.0)
        ->and((float) $project->land_market_value)->toBe(1850000.0)
        ->and((float) $project->land_area)->toBe(2689.0)
        ->and((int) $project->remaining_months)->toBe(53)
        ->and((int) $project->paid_units)->toBe(0)
        ->and((int) $project->exchanged_units)->toBe(146)
        ->and((int) $project->stock_units)->toBe(309)
        ->and((int) $project->units_total)->toBe(529)
        ->and((float) $project->sales_percentage)->toBe(19.32)
        ->and((float) $project->incurred_cost)->toBe(720000.0)
        ->and((float) $project->cost_to_incur)->toBe(1680000.0)
        ->and((float) $project->total_cost)->toBe(2400000.0)
        ->and((float) $project->paid_sales_value)->toBe(1234567.89)
        ->and((float) $project->unpaid_sales_value)->toBe(234567.89)
        ->and((float) $project->stock_sales_value)->toBe(345678.9)
        ->and((float) $project->received_value)->toBe(456789.01)
        ->and((float) $project->value_until_keys)->toBe(567890.12)
        ->and((float) $project->value_after_keys)->toBe(678901.23)
        ->and((float) $project->gross_sales_value)->toBe(1814814.68)
        ->and(ProposalProject::calculateSalesValuesTotal(
            $project->paid_sales_value,
            $project->unpaid_sales_value,
            $project->stock_sales_value,
        ))->toBe(1814814.68)
        ->and(ProposalProject::calculatePaymentFlowTotal(
            $project->received_value,
            $project->value_until_keys,
            $project->value_after_keys,
        ))->toBe(1703580.36)
        ->and((float) $project->work_stage_percentage)->toBe(30.0);

    $project->update([
        'unpaid_units' => 5,
        'paid_units' => 5,
        'exchanged_units' => 10,
        'stock_units' => 0,
        'units_total' => 99,
        'sales_percentage' => 99,
        'incurred_cost' => 0,
        'cost_to_incur' => 0,
        'total_cost' => 100,
        'work_stage_percentage' => 50,
        'paid_sales_value' => '10000.00',
        'unpaid_sales_value' => '20000.50',
        'stock_sales_value' => '30000.75',
        'received_value' => '40100.25',
        'value_until_keys' => '50200.50',
        'value_after_keys' => '60300.75',
        'gross_sales_value' => '70400.99',
    ]);

    $project->refresh();

    expect((int) $project->units_total)->toBe(20)
        ->and((float) $project->sales_percentage)->toBe(100.0)
        ->and((float) $project->total_cost)->toBe(0.0)
        ->and((float) $project->paid_sales_value)->toBe(10000.0)
        ->and((float) $project->unpaid_sales_value)->toBe(20000.5)
        ->and((float) $project->stock_sales_value)->toBe(30000.75)
        ->and((float) $project->received_value)->toBe(40100.25)
        ->and((float) $project->value_until_keys)->toBe(50200.5)
        ->and((float) $project->value_after_keys)->toBe(60300.75)
        ->and((float) $project->gross_sales_value)->toBe(60001.25)
        ->and(ProposalProject::calculateSalesValuesTotal(
            $project->paid_sales_value,
            $project->unpaid_sales_value,
            $project->stock_sales_value,
        ))->toBe(60001.25)
        ->and(ProposalProject::calculatePaymentFlowTotal(
            $project->received_value,
            $project->value_until_keys,
            $project->value_after_keys,
        ))->toBe(150601.5)
        ->and((float) $project->work_stage_percentage)->toBe(0.0);
});

it('allows multiple named projects in the same proposal', function () {
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

    $proposal->projects()->create([
        'name' => 'Torre Madrid',
    ]);

    $proposal->projects()->create([
        'name' => 'Torre Manchester',
    ]);

    expect($proposal->projects()->count())->toBe(2)
        ->and($proposal->projects()->pluck('name')->all())->toBe([
            'Torre Madrid',
            'Torre Manchester',
        ]);
});
