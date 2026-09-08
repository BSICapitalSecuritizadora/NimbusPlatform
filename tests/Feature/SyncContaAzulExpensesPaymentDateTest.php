<?php

use App\Enums\ExpensePaymentStatus;
use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\ContaAzulToken;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Services\ContaAzulClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-04');
    config()->set('conta-azul.category_map', [
        'Taxa de Gestão' => 'Gestão',
        'Tarifas DOC / TED' => 'Tarifas DOC / TED',
    ]);

    ContaAzulToken::query()->create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addDay(),
    ]);
});

it('audits that resolveBillPaymentDate returns null for search endpoint payload because it lacks baixas', function () {
    $job = new SyncContaAzulExpensesJob;

    // Real payload received from /v1/financeiro/eventos-financeiros/contas-a-pagar/buscar
    $realBuscarBill = [
        'id' => 'ae4e9de0-5e66-41c3-a0ad-1fe98336c17c',
        'status' => 'ACQUITTED',
        'total' => 8103.55,
        'descricao' => 'DESPESA OPERACIONAL',
        'data_vencimento' => '2026-06-10',
        'status_traduzido' => 'RECEBIDO',
        'nao_pago' => 0,
        'pago' => 8103.55,
        'data_criacao' => '2026-06-01T16:14:29.939405',
        'data_alteracao' => '2026-06-01T16:14:29.939405',
        'data_competencia' => '2026-06-10',
        'categorias' => [
            ['id' => '282576eb-91c2-4df3-bfed-fd2af1a00a6d', 'nome' => 'Taxa de Gestão'],
        ],
        'centros_de_custo' => [],
        'fornecedor' => ['id' => null, 'nome' => null],
    ];

    // Search payload does NOT contain data_pagamento, payment_date, data_baixa, or baixas
    expect($job->resolveBillPaymentDate($realBuscarBill))->toBeNull();
});

it('correctly fetches and persists real payment_date from parcelas endpoint when search payload has no date', function () {
    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'fund-acc-uuid-1',
    ]);

    $billId = 'bill-uuid-8103-55';

    // Mock official installment endpoint with realistic Conta Azul response
    Http::fake([
        'https://api-v2.contaazul.com/v1/financeiro/eventos-financeiros/parcelas/'.$billId => Http::response([
            'id' => $billId,
            'status' => 'QUITADO',
            'data_vencimento' => '2026-06-10',
            'data_pagamento_previsto' => '2026-06-10',
            'baixas' => [
                [
                    'id' => 'baixa-uuid-999',
                    'data_pagamento' => '2026-06-15', // Paid 5 days AFTER due date
                    'valor_composicao' => [
                        'valor_liquido' => 8103.55,
                    ],
                ],
            ],
        ], 200),
    ]);

    $job = new SyncContaAzulExpensesJob;
    $client = app(ContaAzulClient::class);

    $billFromSearch = [
        'id' => $billId,
        'status' => 'ACQUITTED',
        'status_traduzido' => 'RECEBIDO',
        'total' => 8103.55,
        'pago' => 8103.55,
        'nao_pago' => 0.0,
        'data_vencimento' => '2026-06-10',
        'data_competencia' => '2026-06-10',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $billFromSearch, $client);

    $history = ExpenseHistory::where('conta_azul_bill_id', $billId)->first();

    expect($history)->not->toBeNull()
        ->and((float) $history->amount)->toEqual(8103.55)
        ->and((float) $history->paid_amount)->toEqual(8103.55)
        ->and($history->status)->toBe(ExpensePaymentStatus::Paid->value)
        ->and($history->due_date->toDateString())->toBe('2026-06-10')
        ->and($history->payment_date->toDateString())->toBe('2026-06-15')
        ->and($history->payment_date->toDateString())->not->toBe($history->due_date->toDateString());
});

it('reconciles existing legacy records having payment_date NULL idempotently without creating duplicates', function () {
    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'fund-acc-uuid-2',
    ]);

    $billId = 'bill-uuid-legacy-reconcile';

    // Simulate existing legacy record with status = paid and payment_date = NULL
    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'category' => 'Gestão',
    ]);
    $existing = $expense->histories()->create([
        'conta_azul_bill_id' => $billId,
        'amount' => 5000.00,
        'paid_amount' => 5000.00,
        'due_date' => '2026-07-10',
        'payment_date' => null,
        'status' => ExpensePaymentStatus::Paid->value,
    ]);

    Http::fake([
        'https://api-v2.contaazul.com/v1/financeiro/eventos-financeiros/parcelas/'.$billId => Http::response([
            'id' => $billId,
            'status' => 'QUITADO',
            'data_vencimento' => '2026-07-10',
            'baixas' => [
                [
                    'id' => 'baixa-reconcile-1',
                    'data_pagamento' => '2026-07-12',
                    'valor_composicao' => ['valor_liquido' => 5000.00],
                ],
            ],
        ], 200),
    ]);

    $job = new SyncContaAzulExpensesJob;
    $client = app(ContaAzulClient::class);

    $billFromSearch = [
        'id' => $billId,
        'status' => 'ACQUITTED',
        'status_traduzido' => 'RECEBIDO',
        'total' => 5000.00,
        'pago' => 5000.00,
        'nao_pago' => 0.0,
        'data_vencimento' => '2026-07-10',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $billFromSearch, $client);

    // Assert NO duplicate was created
    expect(ExpenseHistory::where('conta_azul_bill_id', $billId)->count())->toBe(1);

    $reloaded = $existing->fresh();
    expect($reloaded->payment_date)->not->toBeNull()
        ->and($reloaded->payment_date->toDateString())->toBe('2026-07-12')
        ->and($reloaded->due_date->toDateString())->toBe('2026-07-10')
        ->and($reloaded->status)->toBe('paid');
});

it('avoids N+1 HTTP calls on subsequent sync when payment_date is already known and payment unchanged', function () {
    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'fund-acc-uuid-3',
    ]);

    $billId = 'bill-uuid-cached-date';

    // Existing record already has payment_date populated
    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'category' => 'Gestão',
    ]);
    $expense->histories()->create([
        'conta_azul_bill_id' => $billId,
        'amount' => 2000.00,
        'paid_amount' => 2000.00,
        'due_date' => '2026-08-01',
        'payment_date' => '2026-08-03',
        'status' => ExpensePaymentStatus::Paid->value,
    ]);

    // Track HTTP requests
    Http::fake();

    $job = new SyncContaAzulExpensesJob;
    $client = app(ContaAzulClient::class);

    $billFromSearch = [
        'id' => $billId,
        'status' => 'ACQUITTED',
        'status_traduzido' => 'RECEBIDO',
        'total' => 2000.00,
        'pago' => 2000.00,
        'nao_pago' => 0.0,
        'data_vencimento' => '2026-08-01',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $billFromSearch, $client);

    // No HTTP request to /parcelas should be made because payment_date is already known
    Http::assertNothingSent();

    $history = ExpenseHistory::where('conta_azul_bill_id', $billId)->first();
    expect($history->payment_date->toDateString())->toBe('2026-08-03');
});

it('keeps payment_date as null and never falls back to due_date if parcelas endpoint has no baixas', function () {
    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'fund-acc-uuid-4',
    ]);

    $billId = 'bill-uuid-no-baixas';

    // Parcelas endpoint returns no baixas
    Http::fake([
        'https://api-v2.contaazul.com/v1/financeiro/eventos-financeiros/parcelas/'.$billId => Http::response([
            'id' => $billId,
            'status' => 'QUITADO',
            'data_vencimento' => '2026-06-10',
            'baixas' => [],
        ], 200),
    ]);

    $job = new SyncContaAzulExpensesJob;
    $client = app(ContaAzulClient::class);

    $billFromSearch = [
        'id' => $billId,
        'status' => 'ACQUITTED',
        'status_traduzido' => 'RECEBIDO',
        'total' => 1500.00,
        'pago' => 1500.00,
        'nao_pago' => 0.0,
        'data_vencimento' => '2026-06-10',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $billFromSearch, $client);

    $history = ExpenseHistory::where('conta_azul_bill_id', $billId)->first();

    // MUST be NULL, NEVER falling back to due_date
    expect($history)->not->toBeNull()
        ->and($history->status)->toBe('paid')
        ->and($history->due_date->toDateString())->toBe('2026-06-10')
        ->and($history->payment_date)->toBeNull();
});
