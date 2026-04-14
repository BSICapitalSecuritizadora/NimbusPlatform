<?php

use App\Models\Emission;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the finalized emission core enums', function () {
    expect(Emission::TYPE_OPTIONS)->toBe([
        'CR' => 'CR',
        'CRA' => 'CRA',
        'CRI' => 'CRI',
    ])->and(Emission::STATUS_OPTIONS)->toBe([
        'draft' => 'Rascunho',
        'default' => 'Default',
        'active' => 'Ativa',
        'closed' => 'Encerrada',
    ]);
});

it('resolves the default emission status label', function () {
    $emission = Emission::query()->create([
        'name' => 'Operacao Default CR 001',
        'type' => 'CR',
        'status' => 'default',
    ]);

    expect($emission->status_label)->toBe('Default');
});

it('stores the finalized emission defaults', function () {
    $emission = Emission::query()->create([
        'name' => 'Operacao Teste CRI 001',
        'type' => 'CRI',
    ]);

    $emission->refresh();

    expect($emission->status)->toBe('draft')
        ->and($emission->prepayment_possibility)->toBeFalse()
        ->and($emission->is_public)->toBeFalse();
});

it('requires a type for emissions', function () {
    expect(fn () => Emission::query()->create([
        'name' => 'Operacao sem tipo',
    ]))->toThrow(QueryException::class);
});

it('supports the finalized emission core fields and casts', function () {
    $emission = Emission::query()->create([
        'name' => 'Operacao Exemplo CRI 001',
        'type' => 'CRI',
        'if_code' => 'IF-EXEMPLO-001',
        'isin_code' => 'BRBSICAPITAL01',
        'status' => 'active',
        'issuer' => 'BSI Capital',
        'fiduciary_regime' => 'Conforme termo de securitizacao',
        'issue_date' => '2026-01-15',
        'maturity_date' => '2031-01-15',
        'monetary_update_period' => 'Mensal',
        'series' => '001',
        'emission_number' => '010',
        'issued_quantity' => 1200,
        'monetary_update_months' => '12',
        'interest_payment_frequency' => 'Mensal',
        'offer_type' => '476',
        'concentration' => 'Pulverizada',
        'issued_price' => 1234.56,
        'amortization_frequency' => 'Semestral',
        'integralized_quantity' => 1000,
        'trustee_agent' => 'Agente XYZ',
        'debtor' => 'Devedor ABC',
        'remuneration' => 'CDI + 2,00% a.a.',
        'prepayment_possibility' => true,
        'segment' => 'Real Estate',
        'issued_volume' => 1481472.9,
        'is_public' => true,
        'description' => 'Emissao de exemplo para validar o core v1.0.',
    ]);

    $emission->refresh();

    expect($emission->issue_date?->toDateString())->toBe('2026-01-15')
        ->and($emission->maturity_date?->toDateString())->toBe('2031-01-15')
        ->and($emission->issued_price)->toBe('1234.56')
        ->and($emission->issued_volume)->toBe('1481472.90')
        ->and($emission->prepayment_possibility)->toBeTrue()
        ->and($emission->is_public)->toBeTrue()
        ->and($emission->status_label)->toBe('Ativa')
        ->and($emission->only([
            'name',
            'type',
            'if_code',
            'isin_code',
            'status',
            'issuer',
            'fiduciary_regime',
            'monetary_update_period',
            'series',
            'emission_number',
            'issued_quantity',
            'monetary_update_months',
            'interest_payment_frequency',
            'offer_type',
            'concentration',
            'amortization_frequency',
            'integralized_quantity',
            'trustee_agent',
            'debtor',
            'remuneration',
            'segment',
            'description',
        ]))->toMatchArray([
            'name' => 'Operacao Exemplo CRI 001',
            'type' => 'CRI',
            'if_code' => 'IF-EXEMPLO-001',
            'isin_code' => 'BRBSICAPITAL01',
            'status' => 'active',
            'issuer' => 'BSI Capital',
            'fiduciary_regime' => 'Conforme termo de securitizacao',
            'monetary_update_period' => 'Mensal',
            'series' => '001',
            'emission_number' => '010',
            'issued_quantity' => 1200,
            'monetary_update_months' => '12',
            'interest_payment_frequency' => 'Mensal',
            'offer_type' => '476',
            'concentration' => 'Pulverizada',
            'amortization_frequency' => 'Semestral',
            'integralized_quantity' => 1000,
            'trustee_agent' => 'Agente XYZ',
            'debtor' => 'Devedor ABC',
            'remuneration' => 'CDI + 2,00% a.a.',
            'segment' => 'Real Estate',
            'description' => 'Emissao de exemplo para validar o core v1.0.',
        ]);
});
