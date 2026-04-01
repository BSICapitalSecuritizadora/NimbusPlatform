<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $proposalProjectRenames = [
            'company_name' => 'development_name',
            'site' => 'website_url',
            'value_requested' => 'requested_amount',
            'cep' => 'zip_code',
            'logradouro' => 'street',
            'numero' => 'address_number',
            'complemento' => 'address_complement',
            'bairro' => 'neighborhood',
            'cidade' => 'city',
            'estado' => 'state',
            'units_exchanged' => 'exchanged_units',
            'units_paid' => 'paid_units',
            'units_unpaid' => 'unpaid_units',
            'units_stock' => 'stock_units',
            'cost_incurred' => 'incurred_cost',
            'cost_total' => 'total_cost',
            'value_paid' => 'paid_sales_value',
            'value_unpaid' => 'unpaid_sales_value',
            'value_stock' => 'stock_sales_value',
            'value_total_sale' => 'gross_sales_value',
            'value_received' => 'received_value',
            'value_post_keys' => 'value_after_keys',
        ];

        foreach ($proposalProjectRenames as $from => $to) {
            if (Schema::hasColumn('proposal_projects', $from) && ! Schema::hasColumn('proposal_projects', $to)) {
                Schema::table('proposal_projects', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }

        $projectUnitTypeRenames = [
            'useful_area' => 'usable_area',
            'price_per_m2' => 'price_per_square_meter',
        ];

        foreach ($projectUnitTypeRenames as $from => $to) {
            if (Schema::hasColumn('project_unit_types', $from) && ! Schema::hasColumn('project_unit_types', $to)) {
                Schema::table('project_unit_types', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $proposalProjectRenames = [
            'development_name' => 'company_name',
            'website_url' => 'site',
            'requested_amount' => 'value_requested',
            'zip_code' => 'cep',
            'street' => 'logradouro',
            'address_number' => 'numero',
            'address_complement' => 'complemento',
            'neighborhood' => 'bairro',
            'city' => 'cidade',
            'state' => 'estado',
            'exchanged_units' => 'units_exchanged',
            'paid_units' => 'units_paid',
            'unpaid_units' => 'units_unpaid',
            'stock_units' => 'units_stock',
            'incurred_cost' => 'cost_incurred',
            'total_cost' => 'cost_total',
            'paid_sales_value' => 'value_paid',
            'unpaid_sales_value' => 'value_unpaid',
            'stock_sales_value' => 'value_stock',
            'gross_sales_value' => 'value_total_sale',
            'received_value' => 'value_received',
            'value_after_keys' => 'value_post_keys',
        ];

        foreach ($proposalProjectRenames as $from => $to) {
            if (Schema::hasColumn('proposal_projects', $from) && ! Schema::hasColumn('proposal_projects', $to)) {
                Schema::table('proposal_projects', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }

        $projectUnitTypeRenames = [
            'usable_area' => 'useful_area',
            'price_per_square_meter' => 'price_per_m2',
        ];

        foreach ($projectUnitTypeRenames as $from => $to) {
            if (Schema::hasColumn('project_unit_types', $from) && ! Schema::hasColumn('project_unit_types', $to)) {
                Schema::table('project_unit_types', function (Blueprint $table) use ($from, $to) {
                    $table->renameColumn($from, $to);
                });
            }
        }
    }
};
