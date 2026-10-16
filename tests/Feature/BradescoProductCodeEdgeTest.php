<?php

use App\DTOs\Bradesco\BradescoBalanceReport;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Services\Bradesco\BradescoApplicationMatcher;
use App\Services\Bradesco\BradescoBalanceInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

function bradescoSingleProductPayload(mixed $codigoProduto, string $identificadorSaldo): array
{
    return [
        'saldoAplicacaoCC' => [
            'codigoRetorno' => '00',
            'mensagem' => 'OK',
            'quantidadeLancamentos' => '1',
            'lstLancamentosSaldos' => [
                [
                    'nomeProduto' => 'PRODUTO DE BORDA',
                    'nomeProdutoResumido' => 'BORDA',
                    'codigoProduto' => $codigoProduto,
                    'identificadorSaldo' => $identificadorSaldo,
                    'dataLancamentoDb2' => '',
                    'valorLancamento' => '100,00',
                    'sinalSaldo' => '+',
                ],
            ],
        ],
    ];
}

function bradescoSingleProductReport(BankAccount $account, mixed $codigoProduto, string $identificadorSaldo): BradescoBalanceReport
{
    return app(BradescoBalanceInterpreter::class)->interpret(
        bradescoSingleProductPayload($codigoProduto, $identificadorSaldo),
        $account,
        null,
        '2026-10-16T10:00:00+00:00',
        '2026-10-16T10:00:01+00:00'
    );
}

it('recognizes a non-numeric product code returned verbatim', function () {
    $application = BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => 'ABC',
    ]);

    $match = $this->matcher->match(
        $this->account,
        bradescoSingleProductReport($this->account, 'ABC', '2')
    );

    expect($match->recognized)->toHaveCount(1)
        ->and($match->recognized[0]['bank_application_id'])->toBe($application->id)
        ->and($match->recognized[0]['codigo_produto'])->toBe('ABC')
        ->and($match->unregistered)->toBe([])
        ->and($match->absentFromResponse)->toBe([]);
});

it('reports an unregistered non-numeric code once without crashing', function () {
    $match = $this->matcher->match(
        $this->account,
        bradescoSingleProductReport($this->account, 'ABC', '2')
    );

    expect(collect($match->unregistered)->pluck('codigo_produto')->all())->toBe(['ABC'])
        ->and($match->recognized)->toBe([])
        ->and($match->absentFromResponse)->toBe([]);
});

it('treats product codes as case-sensitive', function () {
    BankApplication::factory()->create([
        'bank_account_id' => $this->account->id,
        'codigo_produto' => 'abc',
    ]);

    $match = $this->matcher->match(
        $this->account,
        bradescoSingleProductReport($this->account, 'ABC', '2')
    );

    expect($match->recognized)->toBe([])
        ->and(collect($match->unregistered)->pluck('codigo_produto')->all())->toBe(['ABC'])
        ->and(collect($match->absentFromResponse)->pluck('codigo_produto')->all())->toBe(['abc']);
});

it('flags an unknown identificadorSaldo value without inventing a meaning', function () {
    $report = bradescoSingleProductReport($this->account, '19', '9');
    $row = $report->rows[0];

    expect($row->flags)->toContain('unknown_classification')
        ->and($row->identificadorMeaning)->toBeNull()
        ->and($row->signedCents)->toBe(10000)
        ->and($row->amountDecimal)->toBe('100.00')
        ->and($report->complete)->toBeTrue()
        ->and($report->financialMapping)->toBe(BradescoBalanceReport::MAPPING_UNRESOLVED);

    $match = $this->matcher->match($this->account, $report);

    expect(collect($match->unregistered)->pluck('codigo_produto')->all())->toBe(['19'])
        ->and($match->unregistered[0]['classifications'])->toBe(['9']);
});
