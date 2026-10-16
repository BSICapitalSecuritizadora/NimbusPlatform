<?php

use App\DTOs\Bradesco\BradescoBalanceReport;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Services\Bradesco\BradescoApplicationMatcher;
use App\Services\Bradesco\BradescoBalanceInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->matcher = app(BradescoApplicationMatcher::class);
    $this->account = BankAccount::factory()->create([
        'agency_number' => '3995',
        'agency_digit' => '1',
        'account_number' => '75557',
        'account_digit' => '5',
    ]);
});

function bradescoBalanceReportFor(BankAccount $account): BradescoBalanceReport
{
    $payload = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Bradesco/saldo_ok.json')),
        true
    );

    return app(BradescoBalanceInterpreter::class)->interpret(
        $payload, $account, 2, '2026-10-16T10:00:00+00:00', '2026-10-16T10:00:01+00:00'
    );
}

it('recognizes registered products returned by the bank', function () {
    $application = BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '19',
    ]);

    $match = $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));

    expect($match->recognized)->toHaveCount(1)
        ->and($match->recognized[0]['bank_application_id'])->toBe($application->id)
        ->and($match->recognized[0]['codigo_produto'])->toBe('19')
        ->and($match->recognized[0]['row_count'])->toBe(2)
        ->and($match->recognized[0]['classifications'])->toEqualCanonicalizing(['1', '2']);
});

it('reports zero-padding differences as format variants without duplicating', function () {
    $application = BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '30',
    ]);

    $match = $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));
    $variant = collect($match->formatVariants)->firstWhere('bank_application_id', $application->id);

    expect($variant)->not->toBeNull()
        ->and($variant['registered_codigo'])->toBe('30')
        ->and($variant['returned_codigo'])->toBe('00030')
        ->and(BankApplication::query()->where('bank_account_id', $this->account->id)->count())->toBe(1);
});

it('lists bank products that are not registered yet', function () {
    $match = $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));

    expect(collect($match->unregistered)->pluck('codigo_produto')->all())
        ->toEqualCanonicalizing(['19', '00030', '977']);
});

it('lists registered applications missing from the response', function () {
    $application = BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '00450',
    ]);

    $match = $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));

    expect($match->absentFromResponse)->toHaveCount(1)
        ->and($match->absentFromResponse[0]['bank_application_id'])->toBe($application->id)
        ->and($match->absentFromResponse[0]['codigo_produto'])->toBe('00450');
});

it('flags numerically colliding registrations as ambiguous', function () {
    BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '19',
    ]);
    BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '00019',
    ]);

    $match = $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));

    expect($match->ambiguous)->toHaveCount(1)
        ->and($match->ambiguous[0]['registered_codes'])->toBe(['00019', '19'])
        ->and($match->recognized)->toBe([]);
});

it('scopes matching to the diagnosed account only', function () {
    $other = BankAccount::factory()->create();
    BankApplication::factory()->create([
        'bank_account_id' => $other->id,
        'codigo_produto' => '19',
    ]);

    $match = $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));

    expect($match->recognized)->toBe([])
        ->and(collect($match->unregistered)->pluck('codigo_produto')->all())->toContain('19');
});

it('matches a shared application once for all its emissions', function () {
    $application = BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '19',
    ]);
    [$emissionA] = FundAllocationFixture::classification('Operação Alfa');
    [$emissionB] = FundAllocationFixture::classification('Operação Beta');
    FundAllocationFixture::linkedFund($application, $emissionA);
    FundAllocationFixture::linkedFund($application, $emissionB);

    expect($application->isShared())->toBeTrue();

    $match = $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));

    expect($match->recognized)->toHaveCount(1)
        ->and($match->recognized[0]['bank_application_id'])->toBe($application->id);
});

it('reports application rows without any product code', function () {
    $payload = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Bradesco/saldo_ok.json')),
        true
    );
    $payload['saldoAplicacaoCC']['lstLancamentosSaldos'][] = [
        'nomeProduto' => 'SEM CODIGO',
        'valorLancamento' => '10,00',
        'sinalSaldo' => '+',
    ];
    $payload['saldoAplicacaoCC']['quantidadeLancamentos'] = '5';

    $report = app(BradescoBalanceInterpreter::class)->interpret(
        $payload, $this->account, null, 't', 't'
    );
    $match = $this->matcher->match($this->account, $report);

    expect($match->missingIdentifiers)->toHaveCount(1)
        ->and($match->missingIdentifiers[0]['nome_produto'])->toBe('SEM CODIGO');
});

it('never writes while matching', function () {
    BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => '19',
    ]);

    $before = BankApplication::query()->pluck('updated_at', 'id')
        ->map(fn ($date): string => (string) $date)->all();

    $this->matcher->match($this->account, bradescoBalanceReportFor($this->account));

    $after = BankApplication::query()->pluck('updated_at', 'id')
        ->map(fn ($date): string => (string) $date)->all();

    expect($after)->toBe($before)
        ->and(BankApplication::query()->count())->toBe(1);
});
