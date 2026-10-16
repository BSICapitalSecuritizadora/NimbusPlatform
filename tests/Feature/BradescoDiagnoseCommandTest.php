<?php

use App\Models\BankAccount;
use App\Models\BankApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = BankAccount::factory()->create([
        'agency_number' => '3995',
        'agency_digit' => '1',
        'account_number' => '75557',
        'account_digit' => '5',
    ]);
});

it('diagnoses a balance fixture with masked identifiers', function () {
    BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '19',
    ]);

    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--fixture' => base_path('tests/Fixtures/Bradesco/saldo_ok.json'),
        '--tipo-operacao' => '2',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('SOMENTE LEITURA')
        ->expectsOutputToContain('***57-5')
        ->expectsOutputToContain('INVEST FACIL BR')
        ->expectsOutputToContain('Classificação')
        ->expectsOutputToContain('reconhecida')
        ->expectsOutputToContain('NÃO somados')
        ->expectsOutputToContain('UNRESOLVED')
        ->doesntExpectOutputToContain('75557')
        ->doesntExpectOutputToContain('CONTA AMBIENTE TU');
});

it('emits structured json without secrets or client names', function () {
    $exitCode = Artisan::call('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--fixture' => base_path('tests/Fixtures/Bradesco/saldo_ok.json'),
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    $decoded = json_decode($output, true);

    expect($decoded['manual'])->toBe('2.2.2')
        ->and($decoded['balance']['bank_account_id'])->toBe($this->account->id)
        ->and($decoded['balance']['financial_mapping'])->toBe('unresolved')
        ->and($decoded['balance']['grouped_by_product'])->toHaveKeys(['19', '30', '977'])
        ->and($decoded['matching']['unregistered'])->not->toBe([])
        ->and($output)->not->toContain('75557')
        ->and($output)->not->toContain('CONTA AMBIENTE TU')
        ->and($output)->not->toContain('token');
});

it('diagnoses a statement fixture as account data', function () {
    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--fixture' => base_path('tests/Fixtures/Bradesco/extrato_ok.json'),
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('extratoPorPeriodo')
        ->expectsOutputToContain('30/09/2024')
        ->expectsOutputToContain('não são saldos de produtos');
});

it('flags incomplete fixtures instead of failing', function () {
    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--fixture' => base_path('tests/Fixtures/Bradesco/saldo_incomplete.json'),
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Completa: NÃO')
        ->expectsOutputToContain('codigoRetorno 01');
});

it('rejects ambiguous fixtures and bad targets', function () {
    $mixed = temporaryTestFilePath('bradesco-mixed', 'json');
    file_put_contents($mixed, json_encode(['saldoCC' => [], 'extratoPorPeriodo' => []]));

    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--fixture' => $mixed,
    ])->assertFailed();

    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => 999999,
        '--fixture' => base_path('tests/Fixtures/Bradesco/saldo_ok.json'),
    ])->assertFailed();

    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--fixture' => base_path('tests/Fixtures/Bradesco/saldo_ok.json'),
        '--tipo-operacao' => '3',
    ])->assertFailed();

    unlink($mixed);
});

it('keeps live execution fenced behind confirmation and config gates', function () {
    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--live' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('--confirm-live');

    $this->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $this->account->id,
        '--live' => true,
        '--confirm-live' => true,
        '--source-note' => 'separate authorization #1',
    ])->assertFailed();
});
