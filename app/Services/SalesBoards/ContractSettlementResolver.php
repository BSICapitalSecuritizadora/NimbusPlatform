<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ContractSettlementSummary;
use App\Enums\ContractSettlementState;
use App\Models\ContractInstallment;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Se cada contrato estava quitado numa data, decidido pelo cronograma.
 *
 * Nunca lê `Contract.status`. O status é a foto de hoje; um contrato hoje
 * marcado como quitado ainda devia parcelas em março, e responder "quitado" para
 * março seria antedatar a quitação.
 *
 * Regras fechadas:
 *
 * - parcela **válida** em D: não apagada e (sem distrato ou distratada depois de
 *   D). Uma parcela cancelada depois de D ainda existia em D;
 * - parcela **paga** em D: `payment_date <= D` e `paid_value >= expected_value`
 *   -- juros e multa empurram o recebimento acima do previsto e isso continua
 *   sendo quitação;
 * - **zero parcelas válidas não é quitação**. É {@see ContractSettlementState::Undetermined}:
 *   um contrato cujo cronograma o Nimbus não conhece não pode ser declarado
 *   quitado por vacuidade.
 *
 * A soma das parcelas não precisa bater com `sale_value` -- isso é indicador de
 * conferência do domínio, não regra de quitação.
 *
 * API de lote: uma consulta para todos os contratos, resolvidos em memória.
 *
 * As parcelas passam uma vez, como linhas cruas, e não ficam retidas: cada uma
 * só incrementa os contadores de "válidas" e "pagas" por contrato em cada data
 * pedida. Hidratar cada parcela como model -- com casts de data e de decimal --
 * custava ~2 KB e dezenas de microssegundos por parcela, e uma obra madura tem
 * dezenas de milhares delas; o resumo só precisa das duas contagens.
 */
class ContractSettlementResolver
{
    /**
     * @param  list<int>  $contractIds
     * @return array<int, ContractSettlementSummary> indexado por `contract_id`
     */
    public function resolveForContracts(array $contractIds, CarbonInterface $date): array
    {
        $day = $this->normalizeDate($date);

        return $this->resolveForContractsAtDates($contractIds, [$day])[$day->toDateString()] ?? [];
    }

    /**
     * As mesmas contas em várias datas, com um único carregamento.
     *
     * É o que permite derivar a quitação da competência: o estado no fim do mês
     * e no dia anterior ao início dele saem da mesma leitura.
     *
     * @param  list<int>  $contractIds
     * @param  list<CarbonInterface>  $dates
     * @return array<string, array<int, ContractSettlementSummary>> `Y-m-d` => contrato => resumo
     */
    public function resolveForContractsAtDates(array $contractIds, array $dates): array
    {
        $contractIds = array_values(array_unique(array_map('intval', $contractIds)));
        $days = collect($dates)
            ->map(fn (CarbonInterface $date): CarbonImmutable => $this->normalizeDate($date))
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        if (($contractIds === []) || $days->isEmpty()) {
            return $days->mapWithKeys(fn (CarbonImmutable $date): array => [$date->toDateString() => []])->all();
        }

        $dayStrings = $days->map(fn (CarbonImmutable $day): string => $day->toDateString())->values()->all();

        [$validCounts, $paidCounts] = $this->countInstallments($contractIds, $dayStrings);

        $resolved = [];

        foreach ($days as $index => $day) {
            $summaries = [];

            foreach ($contractIds as $contractId) {
                $summaries[$contractId] = $this->summarise(
                    $contractId,
                    $validCounts[$index][$contractId] ?? 0,
                    $paidCounts[$index][$contractId] ?? 0,
                    $day,
                );
            }

            $resolved[$day->toDateString()] = $summaries;
        }

        return $resolved;
    }

    private function summarise(int $contractId, int $validInstallments, int $paidInstallments, CarbonImmutable $date): ContractSettlementSummary
    {
        if ($validInstallments === 0) {
            return new ContractSettlementSummary(
                contractId: $contractId,
                positionDate: $date,
                state: ContractSettlementState::Undetermined,
                validInstallments: 0,
                paidInstallments: 0,
            );
        }

        return new ContractSettlementSummary(
            contractId: $contractId,
            positionDate: $date,
            state: $paidInstallments === $validInstallments
                ? ContractSettlementState::Settled
                : ContractSettlementState::Outstanding,
            validInstallments: $validInstallments,
            paidInstallments: $paidInstallments,
        );
    }

    /**
     * Quantas parcelas de cada contrato eram válidas e quantas estavam pagas em
     * cada data, numa consulta e numa passada.
     *
     * Sem filtro de data no SQL de propósito: as fronteiras de validade e de
     * pagamento são decididas em PHP, o que evita a divergência de comparação de
     * datas entre SQLite e MySQL e permite responder várias datas com uma
     * leitura só. O escopo já está limitado pelos contratos do empreendimento.
     *
     * Parcelas apagadas ficam de fora pelo soft delete do próprio model, que
     * `toBase()` preserva.
     *
     * @param  list<int>  $contractIds
     * @param  list<string>  $days  `Y-m-d`, na ordem das datas pedidas
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, int>>} válidas e pagas, por índice da data e contrato
     */
    private function countInstallments(array $contractIds, array $days): array
    {
        $valid = array_fill_keys(array_keys($days), []);
        $paid = $valid;

        $installments = ContractInstallment::query()
            ->toBase()
            ->whereIn('contract_id', $contractIds)
            ->select(['contract_id', 'expected_value', 'paid_value', 'payment_date', 'cancellation_date'])
            ->cursor();

        foreach ($installments as $installment) {
            $contractId = (int) $installment->contract_id;
            $cancellationDate = self::dateString($installment->cancellation_date);
            $paymentDate = self::dateString($installment->payment_date);
            $fullyPaid = ($paymentDate !== null) && self::coversExpected($installment->paid_value, $installment->expected_value);

            foreach ($days as $index => $day) {
                if (! self::isValidOn($cancellationDate, $day)) {
                    continue;
                }

                $valid[$index][$contractId] = ($valid[$index][$contractId] ?? 0) + 1;

                if ($fullyPaid && ($paymentDate <= $day)) {
                    $paid[$index][$contractId] = ($paid[$index][$contractId] ?? 0) + 1;
                }
            }
        }

        return [$valid, $paid];
    }

    /**
     * Existia como obrigação na data.
     */
    private static function isValidOn(?string $cancellationDate, string $day): bool
    {
        return ($cancellationDate === null) || ($cancellationDate > $day);
    }

    /**
     * Integralmente recebida, se houve pagamento. Comparação em centavos
     * inteiros: um centavo de diferença decide entre quitado e em aberto, e
     * `float` perde exatamente esse centavo. A data do pagamento é conferida à
     * parte, contra cada data pedida.
     *
     * O valor cru chega como string no MySQL (`"300000.00"`) e como inteiro ou
     * `float` no SQLite, pela afinidade numérica da coluna;
     * {@see IntegerMoney::cents()} converte as três formas no mesmo centavo que
     * o cast `decimal:2` produziria.
     */
    private static function coversExpected(mixed $paidValue, mixed $expectedValue): bool
    {
        $paid = IntegerMoney::cents($paidValue);
        $expected = IntegerMoney::cents($expectedValue) ?? 0;

        return ($paid !== null) && ($paid >= $expected);
    }

    /**
     * A data crua da coluna, reduzida a `Y-m-d`.
     *
     * O MySQL devolve `2026-07-10`; o SQLite devolve `2026-07-10 00:00:00`
     * quando a linha foi gravada pelo Eloquent e `2026-07-10` quando veio de um
     * `insert()` em lote. Os dez primeiros caracteres são o dia nos três casos,
     * e é o dia que a regra compara.
     */
    private static function dateString(mixed $value): ?string
    {
        if (($value === null) || ($value === '')) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function normalizeDate(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }
}
