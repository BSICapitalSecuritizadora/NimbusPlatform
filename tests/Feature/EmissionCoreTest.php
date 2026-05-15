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
        'draft' => 'Em Elaboração',
        'default' => 'Default',
        'active' => 'Ativa',
        'closed' => 'Finalizada',
    ])->and(Emission::ISSUER_SITUATION_OPTIONS)->toBe([
        'Recuperação Judicial' => 'Recuperação Judicial',
        'Inadimplente' => 'Inadimplente',
        'Adimplente' => 'Adimplente',
        'Falência' => 'Falência',
    ])->and(Emission::FORM_OPTIONS)->toBe([
        'Nominativa e escritural' => 'Nominativa e escritural',
        'Nominativa' => 'Nominativa',
        'Escritural' => 'Escritural',
        'Cartular' => 'Cartular',
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
        ->and($emission->offer_type)->toBe('CVM 160')
        ->and($emission->bsi_code)->toBe(sprintf('BSI-%s-%04d', $emission->created_at?->format('Y'), $emission->id))
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
        'issuer_situation' => 'Adimplente',
        'issuer' => 'BSI Capital',
        'settlement_bank' => 'Banco Exemplo',
        'registrar' => 'Escriturador Exemplo',
        'fiduciary_regime' => 'Sim',
        'issue_date' => '2026-01-15',
        'maturity_date' => '2031-01-15',
        'monetary_update_period' => 'Mensal',
        'series' => '001',
        'emission_number' => '010',
        'issued_quantity' => 1200,
        'monetary_update_months' => '12',
        'interest_payment_frequency' => 'Anual',
        'offer_type' => 'CVM 160',
        'concentration' => 'Pulverizado',
        'issued_price' => 1234.56,
        'amortization_frequency' => 'Bullet',
        'integralized_quantity' => 1000,
        'trustee_agent' => 'Agente XYZ',
        'debtor' => 'Devedor ABC',
        'law_firm' => 'Advocacia Exemplo',
        'remuneration_indexer' => 'CDI',
        'remuneration_rate' => 2.00,
        'prepayment_possibility' => true,
        'registered_with_cvm' => 'Sim',
        'form_type' => 'Escritural',
        'segment' => 'Real Estate',
        'issued_volume' => 1481472.9,
        'corporate_purpose' => 'Aquisição de recebíveis.',
        'subscription_and_integralization_terms' => 'Integralização em moeda corrente.',
        'amortization_payment_schedule' => 'Todo último dia útil.',
        'remuneration_payment_schedule' => 'Todo dia 15.',
        'use_of_proceeds' => 'Expansão das atividades.',
        'repactuation' => 'Não aplicável.',
        'optional_early_redemption' => 'Conforme escritura.',
        'early_amortization' => 'Conforme eventos de liquidez.',
        'remuneration_calculation' => 'Base 252.',
        'guarantee_fund' => 'Sim',
        'expense_fund' => 'Não',
        'reserve_fund' => 'Sim',
        'works_fund' => 'Não',
        'property_description' => 'Imóveis de lastro da operação.',
        'segregated_estate' => 'Constituído.',
        'guarantees_description' => 'Cessão fiduciária.',
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
        ->and($emission->bsi_code)->toBe(sprintf('BSI-%s-%04d', $emission->created_at?->format('Y'), $emission->id))
        ->and($emission->formatted_remuneration)->toBe('CDI + 2,00% a.a.')
        ->and($emission->only([
            'name',
            'type',
            'if_code',
            'isin_code',
            'status',
            'issuer_situation',
            'issuer',
            'settlement_bank',
            'registrar',
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
            'law_firm',
            'remuneration_indexer',
            'remuneration_rate',
            'remuneration',
            'registered_with_cvm',
            'form_type',
            'segment',
            'corporate_purpose',
            'subscription_and_integralization_terms',
            'amortization_payment_schedule',
            'remuneration_payment_schedule',
            'use_of_proceeds',
            'repactuation',
            'optional_early_redemption',
            'early_amortization',
            'remuneration_calculation',
            'guarantee_fund',
            'expense_fund',
            'reserve_fund',
            'works_fund',
            'property_description',
            'segregated_estate',
            'guarantees_description',
            'description',
        ]))->toMatchArray([
            'name' => 'Operacao Exemplo CRI 001',
            'type' => 'CRI',
            'if_code' => 'IF-EXEMPLO-001',
            'isin_code' => 'BRBSICAPITAL01',
            'status' => 'active',
            'issuer_situation' => 'Adimplente',
            'issuer' => 'BSI Capital',
            'settlement_bank' => 'Banco Exemplo',
            'registrar' => 'Escriturador Exemplo',
            'fiduciary_regime' => 'Sim',
            'monetary_update_period' => 'Mensal',
            'series' => '001',
            'emission_number' => '010',
            'issued_quantity' => 1200,
            'monetary_update_months' => '12',
            'interest_payment_frequency' => 'Anual',
            'offer_type' => 'CVM 160',
            'concentration' => 'Pulverizado',
            'amortization_frequency' => 'Bullet',
            'integralized_quantity' => 1000,
            'trustee_agent' => 'Agente XYZ',
            'debtor' => 'Devedor ABC',
            'law_firm' => 'Advocacia Exemplo',
            'remuneration_indexer' => 'CDI',
            'remuneration_rate' => '2.00',
            'remuneration' => 'CDI + 2,00% a.a.',
            'registered_with_cvm' => 'Sim',
            'form_type' => 'Escritural',
            'segment' => 'Real Estate',
            'corporate_purpose' => 'Aquisição de recebíveis.',
            'subscription_and_integralization_terms' => 'Integralização em moeda corrente.',
            'amortization_payment_schedule' => 'Todo último dia útil.',
            'remuneration_payment_schedule' => 'Todo dia 15.',
            'use_of_proceeds' => 'Expansão das atividades.',
            'repactuation' => 'Não aplicável.',
            'optional_early_redemption' => 'Conforme escritura.',
            'early_amortization' => 'Conforme eventos de liquidez.',
            'remuneration_calculation' => 'Base 252.',
            'guarantee_fund' => 'Sim',
            'expense_fund' => 'Não',
            'reserve_fund' => 'Sim',
            'works_fund' => 'Não',
            'property_description' => 'Imóveis de lastro da operação.',
            'segregated_estate' => 'Constituído.',
            'guarantees_description' => 'Cessão fiduciária.',
            'description' => 'Emissao de exemplo para validar o core v1.0.',
        ]);
});

it('keeps formatted remuneration available for legacy records', function () {
    $emission = Emission::query()->create([
        'name' => 'Operacao Legada 001',
        'type' => 'CRI',
        'remuneration' => 'IPCA + 7,50% a.a.',
    ]);

    expect($emission->formatted_remuneration)->toBe('IPCA + 7,50% a.a.');
});
