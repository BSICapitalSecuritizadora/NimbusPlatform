<?php

use App\Exceptions\Bradesco\BradescoBusinessDataException;
use App\Models\BankAccount;
use App\Services\Bradesco\BradescoStatementInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->inspector = app(BradescoStatementInspector::class);
    $this->account = BankAccount::factory()->create([
        'agency_number' => '3995',
        'agency_digit' => '1',
        'account_number' => '75557',
        'account_digit' => '5',
    ]);
});

function extratoPayload(): array
{
    return json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Bradesco/extrato_ok.json')),
        true
    );
}

it('inspects statement scope with counts and observed window', function () {
    $report = $this->inspector->inspect(
        extratoPayload(), $this->account, '2026-10-16T10:00:00+00:00', '2026-10-16T10:00:01+00:00'
    );

    expect($report->complete)->toBeTrue()
        ->and($report->totalMovements)->toBe(5)
        ->and($report->windowStart)->toBe('30/09/2024')
        ->and($report->windowEnd)->toBe('17/01/2025')
        ->and($report->sections)->toHaveKeys(['extratoUltimosLancamentos', 'extratoPorPeriodo'])
        ->and($report->sections['extratoPorPeriodo']['movimentos_retornados'])->toBe(3)
        ->and($report->requestedAt)->toBe('2026-10-16T10:00:00+00:00')
        ->and($report->capturedAt)->toBe('2026-10-16T10:00:01+00:00');
});

it('states that movements are account data, not application valuations', function () {
    $report = $this->inspector->inspect(extratoPayload(), $this->account, 't', 't');

    expect(implode(' ', $report->warnings))->toContain('não são saldos de produtos')
        ->and(implode(' ', $report->warnings))->toContain('nunca alimentam alocações');
});

it('flags undocumented movement types as data issues', function () {
    $payload = extratoPayload();
    $payload['extratoPorPeriodo']['lstLancamentoMensal'][0]['tipoLancamento'] = '77';

    $report = $this->inspector->inspect($payload, $this->account, 't', 't');

    expect($report->complete)->toBeFalse()
        ->and(implode(' ', $report->completenessIssues))->toContain('tipoLancamento não documentado [77]');
});

it('rejects statements echoed for another account', function () {
    $payload = extratoPayload();
    $payload['extratoPorPeriodo']['numeroConta'] = '99999';

    expect(fn () => $this->inspector->inspect($payload, $this->account, 't', 't'))
        ->toThrow(BradescoBusinessDataException::class, 'outra conta');
});

it('rejects payloads without any documented statement section', function () {
    expect(fn () => $this->inspector->inspect(['saldoCC' => []], $this->account, 't', 't'))
        ->toThrow(BradescoBusinessDataException::class, 'nenhuma seção de extrato');
});
