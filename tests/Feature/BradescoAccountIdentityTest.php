<?php

use App\Exceptions\Bradesco\BradescoValidationException;
use App\Models\BankAccount;
use App\Services\Bradesco\BradescoAccountIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->identity = app(BradescoAccountIdentity::class);
});

it('maps the canonical parts to request params without check digits', function () {
    $account = BankAccount::factory()->create([
        'agency_number' => '3995',
        'agency_digit' => '1',
        'account_number' => '75557',
        'account_digit' => '5',
    ]);

    expect($this->identity->requestParams($account))->toBe([
        'agencia' => '3995',
        'conta' => '75557',
    ]);
});

it('preserves leading zeros instead of casting to integers', function () {
    $account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => '4',
        'account_number' => '0012345',
        'account_digit' => '6',
    ]);

    $params = $this->identity->requestParams($account);

    expect($params['agencia'])->toBe('0123')
        ->and($params['conta'])->toBe('0012345');
});

it('rejects canonical parts outside the documented widths instead of reshaping them', function () {
    $longAgency = BankAccount::factory()->make([
        'agency_number' => '12345',
        'account_number' => '75557',
    ]);
    $longAccount = BankAccount::factory()->make([
        'agency_number' => '3995',
        'account_number' => '12345678',
    ]);

    expect(fn () => $this->identity->requestParams($longAgency))
        ->toThrow(BradescoValidationException::class, 'agência')
        ->and(fn () => $this->identity->requestParams($longAccount))
        ->toThrow(BradescoValidationException::class, 'conta');
});

it('verifies the response echo byte for byte', function () {
    $account = BankAccount::factory()->create([
        'account_number' => '75557',
        'account_digit' => '5',
    ]);

    expect($this->identity->verifyResponseSection($account, [
        'numeroConta' => '75557',
        'digitoConta' => '5',
    ]))->toBe('exact');
});

it('reports zero-padded echoes as format variants instead of mismatches', function () {
    $account = BankAccount::factory()->create([
        'account_number' => '75557',
        'account_digit' => '5',
    ]);

    expect($this->identity->verifyResponseSection($account, [
        'numeroConta' => '0075557',
        'digitoConta' => '5',
    ]))->toBe('format_variant');
});

it('rejects echoes from another account or with missing fields', function () {
    $account = BankAccount::factory()->create([
        'account_number' => '75557',
        'account_digit' => '5',
    ]);

    expect($this->identity->verifyResponseSection($account, [
        'numeroConta' => '75558',
        'digitoConta' => '5',
    ]))->toBe('mismatch')
        ->and($this->identity->verifyResponseSection($account, [
            'numeroConta' => '75557',
            'digitoConta' => '6',
        ]))->toBe('mismatch')
        ->and($this->identity->verifyResponseSection($account, []))->toBe('mismatch')
        ->and($this->identity->verifyResponseSection($account, [
            'numeroConta' => '75557',
        ]))->toBe('mismatch');
});

it('accepts only documented statement account types', function () {
    $this->identity->assertSupportedStatementType('cc');
    $this->identity->assertSupportedStatementType('CP');

    expect(fn () => $this->identity->assertSupportedStatementType('11-02'))
        ->toThrow(BradescoValidationException::class, 'somente contas correntes');
});

it('never derives identity from the Conta Azul UUID', function () {
    $account = BankAccount::factory()->create([
        'agency_number' => '3995',
        'account_number' => '75557',
        'conta_azul_account_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    ]);

    $params = $this->identity->requestParams($account);

    expect($params)->toBe(['agencia' => '3995', 'conta' => '75557']);
});

it('masks the account label without full identifiers', function () {
    $account = BankAccount::factory()->create([
        'agency_number' => '3995',
        'agency_digit' => '1',
        'account_number' => '75557',
        'account_digit' => '5',
    ]);

    $label = $this->identity->maskedLabel($account);

    expect($label)->not->toContain('3995')
        ->and($label)->not->toContain('75557')
        ->and($label)->toContain('**95-1')
        ->and($label)->toContain('***57-5');
});
