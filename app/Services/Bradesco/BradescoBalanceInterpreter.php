<?php

namespace App\Services\Bradesco;

use App\DTOs\Bradesco\BradescoBalanceReport;
use App\DTOs\Bradesco\BradescoBalanceRow;
use App\Exceptions\Bradesco\BradescoAuthenticationException;
use App\Exceptions\Bradesco\BradescoBusinessDataException;
use App\Exceptions\Bradesco\BradescoNetworkException;
use App\Models\BankAccount;
use App\Support\Bradesco\BradescoMoney;
use InvalidArgumentException;

/**
 * Normalizes a Bradesco balance response into classified evidence.
 *
 * Manual v2.2.2 shapes the rules:
 *
 * - Only `saldoAplicacaoCC.lstLancamentosSaldos` rows are investment
 *   products. `saldoCC`/`saldoCP` rows are account-composition views
 *   (999 DISPONIVEL, 995 TOTAL DE RECURSOS, 984 SALDO TOTAL, ...) and
 *   must never match a BankApplication.
 * - `codigoProduto` identifies the product; `identificadorSaldo`
 *   (0/1/2/3) classifies the row within the product and is never a
 *   second application.
 * - `sinalSaldo` '+'/'-' carries the sign; blank value or sign means
 *   unknown, never zero.
 * - `dataLancamentoDb2` is a print-literal indicator (A-E, 1-4, blank),
 *   not a date; it is surfaced verbatim.
 *
 * The interpreter never sums, nets or consolidates rows: the manual
 * designates no authoritative usable-balance classification, so the
 * financial mapping is always reported UNRESOLVED.
 */
final class BradescoBalanceInterpreter
{
    /**
     * @var array<string, string>
     */
    private const IDENTIFICADOR_MEANINGS = [
        '0' => 'Diversos (sem complemento no nome)',
        '1' => 'Saldo livre/carência com baixa automática (C/BAIXA AUTOMÁTICA)',
        '2' => 'Saldo livre sem baixa automática (P/RESGATE)',
        '3' => 'Saldo em carência sem baixa automática (CARÊNCIA)',
    ];

    private const BLANK_LINE_PRODUCT = '977';

    public function __construct(private readonly BradescoAccountIdentity $identity) {}

    /**
     * @param  array<string, mixed>  $payload  Decoded JSON of the balance endpoint.
     *
     * @throws BradescoBusinessDataException|BradescoAuthenticationException|BradescoNetworkException
     */
    public function interpret(
        array $payload,
        BankAccount $bankAccount,
        ?int $tipoOperacao,
        string $requestedAt,
        string $capturedAt,
    ): BradescoBalanceReport {
        $sections = [];
        $rows = [];
        $issues = [];
        $warnings = [];

        $known = ['saldoCC', 'saldoCP', 'saldoAplicacaoCC'];
        $found = false;

        foreach ($known as $name) {
            if (! array_key_exists($name, $payload)) {
                continue;
            }

            $found = true;
            $section = $payload[$name];

            if (! is_array($section)) {
                throw BradescoBusinessDataException::invalidPayload("seção [{$name}] fora do formato de objeto");
            }

            $sections[$name] = $this->sectionMeta($name, $section, $bankAccount, $issues, $warnings);
            $isApplication = $name === 'saldoAplicacaoCC';

            foreach ($this->sectionRows($name, $section, $issues) as $row) {
                $rows[] = $this->normalizeRow($name, $row, $isApplication, $issues);
            }
        }

        if (! $found) {
            throw BradescoBusinessDataException::invalidPayload('nenhuma seção de saldo (saldoCC, saldoCP, saldoAplicacaoCC) encontrada');
        }

        foreach (array_keys($payload) as $name) {
            if (is_string($name) && ! in_array($name, $known, true)) {
                $warnings[] = "Seção não documentada [{$name}] ignorada; somente saldoCC, saldoCP e saldoAplicacaoCC constam no manual v2.2.2.";
            }
        }

        $grouped = $this->groupByProduct($rows, $warnings);

        return new BradescoBalanceReport(
            bankAccountId: (int) $bankAccount->getKey(),
            maskedAccount: $this->identity->maskedLabel($bankAccount),
            tipoOperacao: $tipoOperacao,
            sections: $sections,
            rows: $rows,
            groupedByProduct: $grouped,
            complete: $issues === [],
            completenessIssues: $issues,
            financialMapping: BradescoBalanceReport::MAPPING_UNRESOLVED,
            financialMappingReason: 'O manual v2.2.2 não designa qual registro (codigoProduto, identificadorSaldo) é o saldo utilizável do produto; linhas da mesma aplicação em classificações distintas não podem ser somadas e nenhum total foi calculado.',
            warnings: $warnings,
            requestedAt: $requestedAt,
            capturedAt: $capturedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function sectionMeta(string $name, array $section, BankAccount $bankAccount, array &$issues, array &$warnings): array
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

            if ($verdict === 'format_variant') {
                $warnings[] = "Seção [{$name}] confirma a conta com formatação distinta (zeros à esquerda); nenhuma conta duplicada foi criada.";
            }
        }

        $declared = array_key_exists('quantidadeLancamentos', $section)
            ? trim((string) $section['quantidadeLancamentos'])
            : null;

        $actual = is_array($section['lstLancamentosSaldos'] ?? null)
            ? count($section['lstLancamentosSaldos'])
            : null;

        if ($declared === null || $declared === '') {
            $issues[] = "Seção [{$name}] sem quantidadeLancamentos: integridade da lista não verificável.";
        } elseif ($actual !== null && ((int) $declared) !== $actual) {
            $issues[] = "Seção [{$name}] inconsistente: quantidadeLancamentos declara {$declared}, mas {$actual} linha(s) retornadas.";
        }

        if (array_key_exists('lstLancamentosSaldos', $section) && ! is_array($section['lstLancamentosSaldos'])) {
            $issues[] = "Seção [{$name}] com lstLancamentosSaldos fora do formato de lista.";
        }

        return [
            'codigo_retorno' => $codigo,
            'mensagem' => $mensagem,
            'quantidade_declarada' => $declared,
            'quantidade_retornada' => $actual,
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<string>  $issues
     * @return list<array<string, mixed>>
     */
    private function sectionRows(string $name, array $section, array &$issues): array
    {
        $list = $section['lstLancamentosSaldos'] ?? [];

        if (! is_array($list)) {
            return [];
        }

        $rows = [];

        foreach ($list as $index => $row) {
            if (! is_array($row)) {
                $issues[] = "Seção [{$name}] linha {$index} fora do formato de objeto; ignorada.";

                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $issues
     */
    private function normalizeRow(string $section, array $row, bool $isApplication, array &$issues): BradescoBalanceRow
    {
        $flags = [];

        $codigoRaw = array_key_exists('codigoProduto', $row) ? (string) $row['codigoProduto'] : '';
        $codigo = trim($codigoRaw);
        $codigo = $codigo === '' ? null : $codigo;

        if ($codigo === null) {
            $flags[] = 'missing_codigo';
        } elseif (! preg_match('/^\d+$/', $codigo)) {
            $flags[] = 'malformed_codigo';
        }

        $identificador = array_key_exists('identificadorSaldo', $row) ? trim((string) $row['identificadorSaldo']) : '';
        $identificador = $identificador === '' ? null : $identificador;
        $meaning = null;

        if ($identificador === null) {
            $flags[] = 'missing_classification';
        } elseif (array_key_exists($identificador, self::IDENTIFICADOR_MEANINGS)) {
            $meaning = self::IDENTIFICADOR_MEANINGS[$identificador];
        } else {
            $flags[] = 'unknown_classification';
        }

        $valor = array_key_exists('valorLancamento', $row) ? trim((string) $row['valorLancamento']) : '';
        $valor = $valor === '' ? null : $valor;
        $sinal = array_key_exists('sinalSaldo', $row) ? trim((string) $row['sinalSaldo']) : '';
        $sinal = $sinal === '' ? null : $sinal;

        if ($this->isBlankLine($codigo, $valor, $sinal, $identificador)) {
            $flags[] = 'blank_line';
        }

        if ($valor === null) {
            $flags[] = 'missing_value';
        }

        if ($sinal === null) {
            $flags[] = 'missing_sign';
        } elseif ($sinal !== '+' && $sinal !== '-') {
            $flags[] = 'unknown_sign';
        }

        $cents = null;
        $decimal = null;

        if ($valor !== null && $sinal !== null && in_array($sinal, ['+', '-'], true) && ! in_array('blank_line', $flags, true)) {
            try {
                $cents = BradescoMoney::toSignedCents($valor, $sinal);
                $decimal = BradescoMoney::fromCents($cents);
            } catch (InvalidArgumentException) {
                $flags[] = 'malformed_value';
            }
        }

        if (in_array('malformed_value', $flags, true) || in_array('unknown_sign', $flags, true)) {
            $issues[] = "Seção [{$section}] produto [{$codigoRaw}] com valor ou sinal fora do contrato; linha preservada como evidência, sem leitura financeira.";
        }

        return new BradescoBalanceRow(
            section: $section,
            codigoProduto: $codigo,
            codigoProdutoRaw: $codigoRaw,
            nomeProduto: $this->orNull($row['nomeProduto'] ?? null),
            nomeProdutoResumido: $this->orNull($row['nomeProdutoResumido'] ?? null),
            identificadorSaldo: $identificador,
            identificadorMeaning: $meaning,
            dataLancamentoDb2: $this->orNull($row['dataLancamentoDb2'] ?? null),
            valorRaw: $valor,
            sinalSaldo: $sinal,
            signedCents: $cents,
            amountDecimal: $decimal,
            isApplicationProduct: $isApplication,
            flags: $flags,
            raw: $row,
        );
    }

    private function isBlankLine(?string $codigo, ?string $valor, ?string $sinal, ?string $identificador): bool
    {
        if ($codigo !== null && ltrim($codigo, '0') === self::BLANK_LINE_PRODUCT) {
            return true;
        }

        return $valor === null && $sinal === null && $identificador === null;
    }

    /**
     * @param  list<BradescoBalanceRow>  $rows
     * @param  list<string>  $warnings
     * @return array<string, list<BradescoBalanceRow>>
     */
    private function groupByProduct(array $rows, array &$warnings): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            if (! $row->isApplicationProduct || $row->codigoProduto === null) {
                continue;
            }

            $key = preg_match('/^\d+$/', $row->codigoProduto) === 1
                ? ltrim($row->codigoProduto, '0') === '' ? '0' : ltrim($row->codigoProduto, '0')
                : $row->codigoProduto;

            $grouped[$key][] = $row;
        }

        foreach ($grouped as $product => $productRows) {
            $classifications = [];

            foreach ($productRows as $productRow) {
                $classifications[$productRow->identificadorSaldo ?? '(sem classificação)'] = true;
            }

            if (count($classifications) > 1) {
                $warnings[] = "Produto [{$product}] retornou ".count($productRows).' linha(s) em '.count($classifications).' classificações distintas; o manual não elege a utilizável e nada foi somado.';
            }
        }

        return $grouped;
    }

    private function orNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
