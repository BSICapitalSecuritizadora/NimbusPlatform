<?php

use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\Fund;
use App\Services\ContaAzulClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('maps every Conta Azul category to an expense category option', function () {
    $mappedCategories = array_unique(array_values(config('conta-azul.category_map')));

    expect(array_diff($mappedCategories, array_keys(Expense::CATEGORY_OPTIONS)))->toBe([]);
});

it('syncs the SISTEMA category as Sistema', function () {
    Http::preventStrayRequests();

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'fund-acc-uuid-sistema',
    ]);

    (new SyncContaAzulExpensesJob)->upsertExpense($fund, [
        'id' => 'bill-uuid-sistema',
        'status' => 'PENDING',
        'total' => 1200.00,
        'pago' => 0,
        'nao_pago' => 1200.00,
        'data_vencimento' => '2026-10-20',
        'categorias' => [
            ['id' => 'cat-uuid-sistema', 'nome' => 'SISTEMA'],
        ],
    ], app(ContaAzulClient::class));

    $expense = Expense::query()->where('emission_id', $emission->id)->sole();

    expect($expense->category)->toBe('Sistema')
        ->and($expense->histories()->where('conta_azul_bill_id', 'bill-uuid-sistema')->exists())->toBeTrue();
});
