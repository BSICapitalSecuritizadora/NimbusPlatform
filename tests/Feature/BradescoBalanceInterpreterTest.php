<?php

use App\DTOs\Bradesco\BradescoBalanceReport;
use App\Exceptions\Bradesco\BradescoAuthenticationException;
use App\Exceptions\Bradesco\BradescoBusinessDataException;
use App\Exceptions\Bradesco\BradescoNetworkException;
use App\Models\BankAccount;
use App\Services\Bradesco\BradescoBalanceInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->interpreter = app(BradescoBalanceInterpreter::class);
    $this->account = BankAccount::factory()->create([
        'agency_number' => '3995',
        'agency_digit' => '1',
        'account_number' => '75557',
        'account_digit' => '5',
    ]);
});

function bradescoFixture(string $name): array
{
    return json_decode(
        (string) file_get_contents(base_path("tests/Fixtures/Bradesco/{$name}")),
        true
    );
}

function interpretOk(): BradescoBalanceReport
{
    return test()->interpreter->interpret(
        bradescoFixture('saldo_ok.json'),
        test()->account,
        2,
        '2026-10-16T10:00:00+00:00',
        '2026-10-16T10:00:01+00:00',
    );
}

it('normalizes multiple products with exact signed amounts', function () {
    $report = interpretOk();

    expect($report->complete)->toBeTrue()
        ->and($report->tipoOperacao)->toBe(2)
        ->and($report->rows)->toHaveCount(9);

    $byCode = [];

    foreach ($report->rows as $row) {
        $byCode[] = [$row->section, $row->codigoProduto, $row->signedCents, $row->sinalSaldo];
    }

    expect($byCode)->toContain(['saldoAplicacaoCC', '19', 25000, '+'])
        ->and($byCode)->toContain(['saldoAplicacaoCC', '19', 100000, '+'])
        ->and($byCode)->toContain(['saldoAplicacaoCC', '00030', -1050025, '-']);
});

it('keeps composition lines out of the application scope', function () {
    $report = interpretOk();

    $composition = array_filter($report->rows, fn ($row) => $row->section !== 'saldoAplicacaoCC');
    $applications = array_filter($report->rows, fn ($row) => $row->isApplicationProduct);

    expect($composition)->each(fn ($row) => $row->isApplicationProduct->toBeFalse())
        ->and(array_keys($report->groupedByProduct))->toEqualCanonicalizing(['19', '30', '977'])
        ->and(array_column(array_map(fn ($row) => [$row->codigoProduto], $applications), 0))
        ->not->toContain('999')
        ->not->toContain('995')
        ->not->toContain('984');
});

it('classifies balance rows without treating identificadorSaldo as an application', function () {
    $report = interpretOk();

    $investFacil = $report->groupedByProduct['19'];

    expect($investFacil)->toHaveCount(2)
        ->and($investFacil[0]->identificadorSaldo)->toBe('1')
        ->and($investFacil[0]->identificadorMeaning)->toContain('BAIXA AUTOMÁTICA')
        ->and($investFacil[1]->identificadorSaldo)->toBe('2')
        ->and($investFacil[1]->identificadorMeaning)->toContain('P/RESGATE')
        ->and($report->warnings)->toContain(
            'Produto [19] retornou 2 linha(s) em 2 classificações distintas; o manual não elege a utilizável e nada foi somado.'
        );
});

it('exposes no consolidated balance and stays financially unresolved', function () {
    $report = interpretOk();

    expect($report->financialMapping)->toBe(BradescoBalanceReport::MAPPING_UNRESOLVED)
        ->and($report->financialMappingReason)->toContain('não podem ser somadas')
        ->and($report->toArray())->not->toHaveKeys(['total', 'consolidated', 'usable_balance', 'saldo_total']);
});

it('flags blank lines as evidence instead of zero balances', function () {
    $report = interpretOk();

    $blanks = array_values(array_filter(
        $report->rows,
        fn ($row) => in_array('blank_line', $row->flags, true)
    ));

    expect($blanks)->toHaveCount(2)
        ->and($blanks[0]->signedCents)->toBeNull()
        ->and($blanks[0]->amountDecimal)->toBeNull();
});

it('flags missing values, signs and classifications without inventing them', function () {
    $payload = bradescoFixture('saldo_ok.json');
    $payload['saldoAplicacaoCC']['lstLancamentosSaldos'][] = [
        'nomeProduto' => 'PRODUTO SEM DADOS',
        'nomeProdutoResumido' => 'SEM DADOS',
        'codigoProduto' => 99999,
        'identificadorSaldo' => '',
        'dataLancamentoDb2' => '',
        'valorLancamento' => '',
        'sinalSaldo' => '',
    ];
    $payload['saldoAplicacaoCC']['quantidadeLancamentos'] = '5';

    $report = $this->interpreter->interpret($payload, $this->account, null, '2026-10-16T10:00:00+00:00', '2026-10-16T10:00:01+00:00');
    $last = $report->rows[count($report->rows) - 1];

    expect($last->signedCents)->toBeNull()
        ->and($last->flags)->toContain('missing_value')
        ->and($last->flags)->toContain('missing_sign')
        ->and($last->flags)->toContain('missing_classification');
});

it('flags malformed values and unknown signs as data issues', function () {
    $payload = bradescoFixture('saldo_ok.json');
    $payload['saldoAplicacaoCC']['lstLancamentosSaldos'][0]['valorLancamento'] = '250.00';
    $payload['saldoAplicacaoCC']['lstLancamentosSaldos'][1]['sinalSaldo'] = 'D';

    $report = $this->interpreter->interpret($payload, $this->account, null, '2026-10-16T10:00:00+00:00', '2026-10-16T10:00:01+00:00');

    $first = $report->rows[5];
    $second = $report->rows[6];

    expect($first->signedCents)->toBeNull()
        ->and($first->flags)->toContain('malformed_value')
        ->and($second->signedCents)->toBeNull()
        ->and($second->flags)->toContain('unknown_sign')
        ->and($report->completenessIssues)->not->toBe([]);
});

it('reports partial and inconsistent responses without throwing them away', function () {
    $report = $this->interpreter->interpret(
        bradescoFixture('saldo_incomplete.json'),
        $this->account,
        1,
        '2026-10-16T10:00:00+00:00',
        '2026-10-16T10:00:01+00:00',
    );

    expect($report->complete)->toBeFalse()
        ->and($report->completenessIssues)->toHaveCount(2)
        ->and(implode(' ', $report->completenessIssues))->toContain('codigoRetorno 01')
        ->and(implode(' ', $report->completenessIssues))->toContain('declara 3, mas 1 linha(s)');
});

it('rejects answers echoed for another account', function () {
    $payload = bradescoFixture('saldo_ok.json');
    $payload['saldoCC']['numeroConta'] = '99999';

    expect(fn () => $this->interpreter->interpret($payload, $this->account, null, '2026-10-16T10:00:00+00:00', '2026-10-16T10:00:01+00:00'))
        ->toThrow(BradescoBusinessDataException::class, 'outra conta');
});

it('maps backend return codes to distinct error classes', function () {
    $base = bradescoFixture('saldo_ok.json');

    $auth = $base;
    $auth['saldoAplicacaoCC']['codigoRetorno'] = '04';

    $business = $base;
    $business['saldoAplicacaoCC']['codigoRetorno'] = '08';

    $cloud = $base;
    $cloud['saldoAplicacaoCC']['codigoRetorno'] = '24';

    $unknown = $base;
    $unknown['saldoAplicacaoCC']['codigoRetorno'] = '99';

    expect(fn () => $this->interpreter->interpret($auth, $this->account, null, 't', 't'))
        ->toThrow(BradescoAuthenticationException::class)
        ->and(fn () => $this->interpreter->interpret($business, $this->account, null, 't', 't'))
        ->toThrow(BradescoBusinessDataException::class)
        ->and(fn () => $this->interpreter->interpret($cloud, $this->account, null, 't', 't'))
        ->toThrow(BradescoNetworkException::class)
        ->and(fn () => $this->interpreter->interpret($unknown, $this->account, null, 't', 't'))
        ->toThrow(BradescoBusinessDataException::class, 'não documentado');
});

it('rejects payloads without any documented section', function () {
    expect(fn () => $this->interpreter->interpret(['foo' => []], $this->account, null, 't', 't'))
        ->toThrow(BradescoBusinessDataException::class, 'nenhuma seção');
});

it('records request and capture timestamps on the report', function () {
    $report = interpretOk();

    expect($report->requestedAt)->toBe('2026-10-16T10:00:00+00:00')
        ->and($report->capturedAt)->toBe('2026-10-16T10:00:01+00:00');
});
