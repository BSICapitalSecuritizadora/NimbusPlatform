<?php

namespace App\Services\Bradesco;

use App\DTOs\Bradesco\BradescoStatementReport;
use App\Exceptions\Bradesco\BradescoAuthenticationException;
use App\Exceptions\Bradesco\BradescoBusinessDataException;
use App\Exceptions\Bradesco\BradescoNetworkException;
use App\Models\BankAccount;

/**
 * Inspects a Bradesco statement response for scope and completeness.
 *
 * Manual v2.2.2 splits movements into dedicated sections:
 * `extratoUltimosLancamentos` (D-1 plus D0 "Lançamentos Dia"),
 * `extratoLancamentosFuturos`, and `extratoPorPeriodo` with
 * `lstLancamentoMensal` (history D-2 backwards, up to 10 years, up to
 * 5.000 transactions per request).
 *
 * Every movement here is bank-account data. The inspector describes
 * counts and windows and explicitly refuses the crossings the domain
 * forbids: movements are not product balances, not historical
 * application valuations, and not allocation inputs.
 */
final class BradescoStatementInspector
{
    /**
     * @var array<string, string>
     */
    private const TIPO_LANCAMENTO = [
        '01' => 'Saldo anterior C/C',
        '02' => 'Últimos lançamentos',
        '04' => 'Lançamentos futuros',
        '05' => 'Saldo anterior poupança',
        '09' => 'Lançamentos do dia',
    ];

    public function __construct(private readonly BradescoAccountIdentity $identity) {}

    /**
     * @param  array<string, mixed>  $payload  Decoded JSON of the statement endpoint.
     *
     * @throws BradescoBusinessDataException|BradescoAuthenticationException|BradescoNetworkException
     */
    public function inspect(
        array $payload,
        BankAccount $bankAccount,
        string $requestedAt,
        string $capturedAt,
    ): BradescoStatementReport {
        $sections = [];
        $issues = [];
        $warnings = [
            'Extrato é dado da conta bancária: movimentos não são saldos de produtos, não valem como histórico de aplicações e nunca alimentam alocações mensais.',
        ];

        $known = ['extratoUltimosLancamentos', 'extratoLancamentosFuturos', 'extratoPorPeriodo'];
        $found = false;
        $total = 0;
        $dates = [];

        foreach ($known as $name) {
            if (! array_key_exists($name, $payload)) {
                continue;
            }

            $found = true;
            $section = $payload[$name];

            if (! is_array($section)) {
                throw BradescoBusinessDataException::invalidPayload("seção [{$name}] fora do formato de objeto");
            }

            $meta = $this->sectionMeta($name, $section, $bankAccount, $issues);
            $movements = $this->sectionMovements($name, $section, $issues, $dates);
            $meta['movimentos_retornados'] = count($movements);
            $total += count($movements);
            $sections[$name] = $meta;
        }

        if (! $found) {
            throw BradescoBusinessDataException::invalidPayload('nenhuma seção de extrato encontrada');
        }

        foreach (array_keys($payload) as $name) {
            if (is_string($name) && ! in_array($name, $known, true)) {
                $warnings[] = "Seção não documentada [{$name}] ignorada.";
            }
        }

        // Displayed as received (DD/MM/AAAA), ordered chronologically: a
        // lexicographic sort of day-first dates would scramble the window.
        usort($dates, fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);
        $ordered = array_column($dates, 'display');

        return new BradescoStatementReport(
            bankAccountId: (int) $bankAccount->getKey(),
            maskedAccount: $this->identity->maskedLabel($bankAccount),
            sections: $sections,
            totalMovements: $total,
            windowStart: $ordered[0] ?? null,
            windowEnd: $ordered === [] ? null : $ordered[count($ordered) - 1],
            complete: $issues === [],
            completenessIssues: $issues,
            warnings: $warnings,
            requestedAt: $requestedAt,
            capturedAt: $capturedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function sectionMeta(string $name, array $section, BankAccount $bankAccount, array &$issues): array
    {
        $codigo = array_key_exists('codigoRetorno', $section) ? trim((string) $section['codigoRetorno']) : null;
        $mensagem = array_key_exists('mensagem', $section) ? trim((string) $section['mensagem']) : null;

        if ($codigo === null || $codigo === '') {
            $issues[] = "Seção [{$name}] sem codigoRetorno: impossível afirmar sucesso pelo contrato.";
        } else {
            match ($codigo) {
                '00' => null,
                '01' => $issues[] = "Seção [{$name}] parcial: codigoRetorno 01 indica mais registros e o manual não documenta paginação.",
                '04' => throw BradescoAuthenticationException::backend((string) $mensagem),
                '08' => throw BradescoBusinessDataException::backend((string) $mensagem),
                '24' => throw BradescoNetworkException::cloudError((string) $mensagem),
                default => throw BradescoBusinessDataException::unknownReturnCode($codigo),
            };
        }

        if (array_key_exists('numeroConta', $section) || array_key_exists('digitoConta', $section)) {
            $verdict = $this->identity->verifyResponseSection($bankAccount, $section);

            if ($verdict === 'mismatch') {
                throw BradescoBusinessDataException::invalidPayload("seção [{$name}] refere-se a outra conta (numeroConta/digitoConta divergentes)");
            }
        }

        $declared = array_key_exists('quantidadeLancamentos', $section)
            ? trim((string) $section['quantidadeLancamentos'])
            : null;

        return [
            'codigo_retorno' => $codigo,
            'mensagem' => $mensagem,
            'quantidade_declarada' => $declared,
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<string>  $issues
     * @param  list<array{display: string, sort: string}>  $dates
     * @return list<array<string, mixed>>
     */
    private function sectionMovements(string $name, array $section, array &$issues, array &$dates): array
    {
        if ($name === 'extratoPorPeriodo') {
            $list = $section['lstLancamentoMensal'] ?? null;

            if ($list === null) {
                $issues[] = 'Seção [extratoPorPeriodo] sem lstLancamentoMensal.';

                return [];
            }

            if (! is_array($list)) {
                $issues[] = 'Seção [extratoPorPeriodo] com lstLancamentoMensal fora do formato de lista.';

                return [];
            }

            return $this->collectMovements($name, $list, $section, $issues, $dates);
        }

        $lista = $section['listaLancamentos'] ?? null;

        if ($lista === null) {
            $issues[] = "Seção [{$name}] sem listaLancamentos.";

            return [];
        }

        if (! is_array($lista)) {
            $issues[] = "Seção [{$name}] com listaLancamentos fora do formato de objeto.";

            return [];
        }

        $movements = [];

        foreach ($lista as $group => $entries) {
            if (! is_array($entries)) {
                $issues[] = "Seção [{$name}] grupo [{$group}] fora do formato de lista; ignorado.";

                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    $issues[] = "Seção [{$name}] grupo [{$group}] com lançamento fora do formato; ignorado.";

                    continue;
                }

                $movements[] = $entry;
                $this->collectDate($entry, $dates);
            }
        }

        $this->checkDeclaredCount($name, $section, count($movements), $issues);

        return $movements;
    }

    /**
     * @param  list<mixed>  $list
     * @param  array<string, mixed>  $section
     * @param  list<string>  $issues
     * @param  list<array{display: string, sort: string}>  $dates
     * @return list<array<string, mixed>>
     */
    private function collectMovements(string $name, array $list, array $section, array &$issues, array &$dates): array
    {
        $movements = [];

        foreach ($list as $index => $entry) {
            if (! is_array($entry)) {
                $issues[] = "Seção [{$name}] lançamento {$index} fora do formato de objeto; ignorado.";

                continue;
            }

            if (array_key_exists('tipoLancamento', $entry)) {
                $tipo = trim((string) $entry['tipoLancamento']);

                if ($tipo !== '' && ! array_key_exists($tipo, self::TIPO_LANCAMENTO)) {
                    $issues[] = "Seção [{$name}] lançamento {$index} com tipoLancamento não documentado [{$tipo}].";
                }
            }

            $movements[] = $entry;
            $this->collectDate($entry, $dates);
        }

        $this->checkDeclaredCount($name, $section, count($movements), $issues);

        return $movements;
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<string>  $issues
     */
    private function checkDeclaredCount(string $name, array $section, int $actual, array &$issues): void
    {
        $declared = array_key_exists('quantidadeLancamentos', $section)
            ? trim((string) $section['quantidadeLancamentos'])
            : null;

        if ($declared === null || $declared === '') {
            $issues[] = "Seção [{$name}] sem quantidadeLancamentos: integridade da lista não verificável.";
        } elseif (((int) $declared) !== $actual) {
            $issues[] = "Seção [{$name}] inconsistente: quantidadeLancamentos declara {$declared}, mas {$actual} movimento(s) retornados.";
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array{display: string, sort: string}>  $dates
     */
    private function collectDate(array $entry, array &$dates): void
    {
        if (! array_key_exists('dataLancamento', $entry)) {
            return;
        }

        $date = trim((string) $entry['dataLancamento']);

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches) === 1) {
            $dates[] = ['display' => $date, 'sort' => "{$matches[3]}{$matches[2]}{$matches[1]}"];
        }
    }
}
