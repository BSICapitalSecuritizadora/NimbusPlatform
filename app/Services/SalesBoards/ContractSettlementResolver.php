<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ContractSettlementSummary;
use App\Enums\ContractSettlementState;
use App\Models\ContractInstallment;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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

        $installmentsByContract = $this->loadInstallments($contractIds);

        $resolved = [];

        foreach ($days as $day) {
            $summaries = [];

            foreach ($contractIds as $contractId) {
                $summaries[$contractId] = $this->summarise(
                    $contractId,
                    $installmentsByContract->get($contractId, collect()),
                    $day,
                );
            }

            $resolved[$day->toDateString()] = $summaries;
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, ContractInstallment>  $installments
     */
    private function summarise(int $contractId, Collection $installments, CarbonImmutable $date): ContractSettlementSummary
    {
        $valid = $installments->filter(fn (ContractInstallment $installment): bool => $this->isValidOn($installment, $date));

        if ($valid->isEmpty()) {
            return new ContractSettlementSummary(
                contractId: $contractId,
                positionDate: $date,
                state: ContractSettlementState::Undetermined,
                validInstallments: 0,
                paidInstallments: 0,
            );
        }

        $paid = $valid->filter(fn (ContractInstallment $installment): bool => $this->isPaidOn($installment, $date));

        return new ContractSettlementSummary(
            contractId: $contractId,
            positionDate: $date,
            state: $paid->count() === $valid->count()
                ? ContractSettlementState::Settled
                : ContractSettlementState::Outstanding,
            validInstallments: $valid->count(),
            paidInstallments: $paid->count(),
        );
    }

    /**
     * Existia como obrigação na data.
     */
    private function isValidOn(ContractInstallment $installment, CarbonImmutable $date): bool
    {
        $cancellationDate = $installment->cancellation_date?->toDateString();

        return ($cancellationDate === null) || ($cancellationDate > $date->toDateString());
    }

    /**
     * Integralmente recebida na data. Comparação em centavos inteiros: um
     * centavo de diferença decide entre quitado e em aberto, e `float` perde
     * exatamente esse centavo.
     */
    private function isPaidOn(ContractInstallment $installment, CarbonImmutable $date): bool
    {
        $paymentDate = $installment->payment_date?->toDateString();

        if (($paymentDate === null) || ($paymentDate > $date->toDateString())) {
            return false;
        }

        $paid = IntegerMoney::cents($installment->paid_value);
        $expected = IntegerMoney::cents($installment->expected_value) ?? 0;

        return ($paid !== null) && ($paid >= $expected);
    }

    /**
     * Todas as parcelas dos contratos, numa consulta.
     *
     * Sem filtro de data no SQL de propósito: as fronteiras de validade e de
     * pagamento são decididas em PHP, o que evita a divergência de comparação de
     * datas entre SQLite e MySQL e permite responder várias datas com uma
     * leitura só. O escopo já está limitado pelos contratos do empreendimento.
     *
     * Parcelas apagadas ficam de fora pelo soft delete do próprio model.
     *
     * @param  list<int>  $contractIds
     * @return Collection<int, Collection<int, ContractInstallment>>
     */
    private function loadInstallments(array $contractIds): Collection
    {
        return ContractInstallment::query()
            ->whereIn('contract_id', $contractIds)
            ->get(['id', 'contract_id', 'expected_value', 'paid_value', 'payment_date', 'cancellation_date', 'deleted_at'])
            ->groupBy(fn (ContractInstallment $installment): int => (int) $installment->contract_id);
    }

    private function normalizeDate(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }
}
