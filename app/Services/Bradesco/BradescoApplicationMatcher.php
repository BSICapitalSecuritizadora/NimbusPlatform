<?php

namespace App\Services\Bradesco;

use App\DTOs\Bradesco\BradescoBalanceReport;
use App\DTOs\Bradesco\BradescoBalanceRow;
use App\DTOs\Bradesco\BradescoMatchReport;
use App\Models\BankAccount;
use App\Models\BankApplication;
use Illuminate\Support\Carbon;

/**
 * Read-only matching between bank-returned products and registered
 * BankApplications.
 *
 * Only `saldoAplicacaoCC` rows participate: composition lines from
 * `saldoCC`/`saldoCP` are account views, never applications. Matching
 * is scoped to the single physical account under diagnosis.
 *
 * Outcomes per bank product: `recognized` (byte-identical code),
 * `format_variant` (numerically equal, textually different — a single
 * record must be kept, never a second one), `unregistered` (bank
 * returned, nothing registered), `ambiguous` (several registered rows
 * collide numerically and need a human merge). Registered applications
 * missing from the answer land in `absent_from_response`; rows without
 * any product code land in `missing_identifiers`.
 *
 * This service only reads. It creates, deletes and reassigns nothing.
 */
final class BradescoApplicationMatcher
{
    public function __construct(private readonly BradescoAccountIdentity $identity) {}

    public function match(BankAccount $bankAccount, BradescoBalanceReport $report): BradescoMatchReport
    {
        $registered = BankApplication::query()
            ->where('bank_account_id', $bankAccount->getKey())
            ->orderBy('codigo_produto')
            ->orderBy('id')
            ->get(['id', 'bank_account_id', 'codigo_produto', 'nome_produto', 'status']);

        /** @var array<string, list<BankApplication>> $byNumeric */
        $byNumeric = [];

        foreach ($registered as $application) {
            $byNumeric[$this->numericKey((string) $application->codigo_produto)][] = $application;
        }

        $recognized = [];
        $formatVariants = [];
        $unregistered = [];
        $ambiguous = [];
        $missingIdentifiers = [];
        $seenNumeric = [];

        foreach ($report->groupedByProduct as $numericKey => $rows) {
            // The interpreter groups malformed (non-numeric) codes under
            // their raw text while registered codes always key through
            // numericKey(); normalizing here keeps both sides on the
            // same key without touching stored values.
            $key = $this->numericKey((string) $numericKey);
            $seenNumeric[$key] = true;

            $first = $rows[0];
            $candidates = $byNumeric[$key] ?? [];

            if ($candidates === []) {
                $unregistered[] = [
                    'codigo_produto' => $first->codigoProduto,
                    'codigo_produto_raw' => $first->codigoProdutoRaw,
                    'nome_produto' => $first->nomeProduto,
                    'row_count' => count($rows),
                    'classifications' => $this->classificationsOf($rows),
                ];

                continue;
            }

            $exact = null;

            foreach ($candidates as $candidate) {
                if ((string) $candidate->codigo_produto === (string) $first->codigoProduto) {
                    $exact = $candidate;

                    break;
                }
            }

            if (count($candidates) > 1 && $exact === null) {
                $ambiguous[] = [
                    'codigo_produto' => $first->codigoProduto,
                    'codigo_produto_raw' => $first->codigoProdutoRaw,
                    'nome_produto' => $first->nomeProduto,
                    'bank_application_ids' => array_map(fn (BankApplication $candidate): int => (int) $candidate->getKey(), $candidates),
                    'registered_codes' => array_map(fn (BankApplication $candidate): string => (string) $candidate->codigo_produto, $candidates),
                    'reason' => 'Vários registros colidem numericamente com o produto retornado; mesclar manualmente antes de qualquer vínculo.',
                ];

                continue;
            }

            if ($exact instanceof BankApplication && count($candidates) > 1) {
                $ambiguous[] = [
                    'codigo_produto' => $first->codigoProduto,
                    'codigo_produto_raw' => $first->codigoProdutoRaw,
                    'nome_produto' => $first->nomeProduto,
                    'bank_application_ids' => array_map(fn (BankApplication $candidate): int => (int) $candidate->getKey(), $candidates),
                    'registered_codes' => array_map(fn (BankApplication $candidate): string => (string) $candidate->codigo_produto, $candidates),
                    'reason' => 'Há registro exato, mas outro(s) registro(s) colide(m) numericamente; mesclar manualmente.',
                ];

                continue;
            }

            if ($exact instanceof BankApplication) {
                $recognized[] = [
                    'bank_application_id' => (int) $exact->getKey(),
                    'codigo_produto' => (string) $exact->codigo_produto,
                    'nome_produto' => $exact->nome_produto,
                    'status' => $exact->status,
                    'row_count' => count($rows),
                    'classifications' => $this->classificationsOf($rows),
                ];

                continue;
            }

            $single = $candidates[0];

            $formatVariants[] = [
                'bank_application_id' => (int) $single->getKey(),
                'registered_codigo' => (string) $single->codigo_produto,
                'returned_codigo' => $first->codigoProduto,
                'nome_produto' => $single->nome_produto,
                'status' => $single->status,
                'row_count' => count($rows),
                'classifications' => $this->classificationsOf($rows),
                'reason' => 'Códigos numericamente iguais com formatação distinta; manter um único registro.',
            ];
        }

        $absent = [];

        foreach ($registered as $application) {
            if (! array_key_exists($this->numericKey((string) $application->codigo_produto), $seenNumeric)) {
                $absent[] = [
                    'bank_application_id' => (int) $application->getKey(),
                    'codigo_produto' => (string) $application->codigo_produto,
                    'nome_produto' => $application->nome_produto,
                    'status' => $application->status,
                ];
            }
        }

        foreach ($report->rows as $row) {
            if ($row->isApplicationProduct && $row->codigoProduto === null) {
                $missingIdentifiers[] = [
                    'section' => $row->section,
                    'nome_produto' => $row->nomeProduto,
                    'valor_raw' => $row->valorRaw,
                    'sinal_saldo' => $row->sinalSaldo,
                    'flags' => $row->flags,
                ];
            }
        }

        return new BradescoMatchReport(
            bankAccountId: (int) $bankAccount->getKey(),
            maskedAccount: $this->identity->maskedLabel($bankAccount),
            recognized: $recognized,
            formatVariants: $formatVariants,
            unregistered: $unregistered,
            absentFromResponse: $absent,
            ambiguous: $ambiguous,
            missingIdentifiers: $missingIdentifiers,
            evaluatedAt: Carbon::now('UTC')->toIso8601String(),
        );
    }

    /**
     * @param  list<BradescoBalanceRow>  $rows
     * @return list<string>
     */
    private function classificationsOf(array $rows): array
    {
        $classifications = [];

        foreach ($rows as $row) {
            $classifications[] = $row->identificadorSaldo ?? '(sem classificação)';
        }

        return array_values(array_unique($classifications));
    }

    private function numericKey(string $code): string
    {
        $trimmed = trim($code);

        if (preg_match('/^\d+$/', $trimmed) !== 1) {
            return "raw:{$trimmed}";
        }

        $stripped = ltrim($trimmed, '0');

        return $stripped === '' ? '0' : $stripped;
    }
}
