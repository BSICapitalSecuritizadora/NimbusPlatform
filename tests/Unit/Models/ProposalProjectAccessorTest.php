<?php

use App\Models\ProposalProject;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('formats financial and schedule attributes for presentation without touching the database', function () {
    $project = new ProposalProject([
        'requested_amount' => 1234567.89,
        'land_market_value' => 950000.45,
        'incurred_cost' => 1000000.0,
        'cost_to_incur' => 3000000.0,
        'total_cost' => 4000000.0,
        'paid_sales_value' => 900000.0,
        'unpaid_sales_value' => 1500000.5,
        'stock_sales_value' => 2500000.75,
        'gross_sales_value' => 4900001.25,
        'received_value' => 100000.1,
        'value_until_keys' => 200000.2,
        'value_after_keys' => 300000.3,
        'sales_percentage' => 38.89,
        'work_stage_percentage' => 25.0,
    ]);

    $project->launch_date = Carbon::parse('2026-04-01');
    $project->sales_launch_date = Carbon::parse('2026-05-01');
    $project->construction_start_date = Carbon::parse('2026-06-01');
    $project->delivery_forecast_date = Carbon::parse('2028-02-01');

    expect($project->formatted_requested_amount)->toBe('1.234.567,89')
        ->and($project->formatted_land_market_value)->toBe('950.000,45')
        ->and($project->formatted_total_cost)->toBe('4.000.000,00')
        ->and($project->formatted_gross_sales_value)->toBe('4.900.001,25')
        ->and($project->formatted_payment_flow_total)->toBe('600.000,60')
        ->and($project->formatted_sales_percentage)->toBe('38,89%')
        ->and($project->formatted_work_stage_percentage)->toBe('25,00%')
        ->and($project->launch_month)->toBe('2026-04')
        ->and($project->formatted_launch_month)->toBe('04/2026')
        ->and($project->formatted_sales_launch_month)->toBe('05/2026')
        ->and($project->formatted_construction_start_month)->toBe('06/2026')
        ->and($project->formatted_delivery_forecast_month)->toBe('02/2028');
});
