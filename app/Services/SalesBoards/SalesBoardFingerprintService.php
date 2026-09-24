<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\DTOs\SalesBoards\SalesBoardSourceObservation;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesDiscountPolicy;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\CanonicalDigest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Observa a fonte material de uma competência e a reduz a fingerprints.
 *
 * Duas perguntas diferentes, dois resumos. O da **fonte** diz se algum fato
 * material mudou desde que a versão foi congelada; o do **snapshot** diz se a
 * posição derivada desses fatos mudou. Elas não são a mesma pergunta: uma
 * correção de parcela num contrato já distratado altera a fonte sem mover um
 * único número do Quadro, e é justamente essa distinção que vai permitir, nas
 * fases seguintes, tratar uma mudança inócua de forma diferente de uma que
 * desfaz a conferência da construtora.
 *
 * A observação é uma leitura própria, e não um subproduto da derivação, porque o
 * recorte é diferente: a derivação carrega todas as permutas da unidade e filtra
 * a vigente em memória, enquanto aqui só entram as vigentes na data da posição
 * -- uma permuta programada para depois não é fonte desta competência e não pode
 * torná-la obsoleta. O mesmo vale para tabelas de preço e políticas com vigência
 * posterior. O custo é um segundo conjunto de leituras, constante no número de
 * empreendimentos.
 *
 * Nada aqui recalcula regra de negócio: a observação não decide classificação,
 * ocupação, quitação nem conformidade. Ela apenas registra, de forma canônica, o
 * que existia.
 */
class SalesBoardFingerprintService
{
    public function observeForConstruction(Construction $construction, CarbonInterface $referenceMonth): SalesBoardSourceObservation
    {
        $constructionId = (int) $construction->getKey();

        return $this->observeForConstructions([$construction], $referenceMonth)[$constructionId];
    }

    /**
     * Observa vários empreendimentos com um carregamento só.
     *
     * Seis consultas, como a derivação, e pelo mesmo motivo: uma emissão inteira
     * não pode custar um punhado de consultas por obra.
     *
     * @param  iterable<Construction>  $constructions
     * @return array<int, SalesBoardSourceObservation> indexado por `construction_id`
     */
    public function observeForConstructions(iterable $constructions, CarbonInterface $referenceMonth): array
    {
        $month = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $positionDate = $month->endOfMonth()->startOfDay();

        /** @var Collection<int, Construction> $constructions */
        $constructions = collect($constructions)->keyBy(fn (Construction $construction): int => (int) $construction->getKey());

        if ($constructions->isEmpty()) {
            return [];
        }

        $constructionIds = $constructions->keys()->map(fn (mixed $id): int => (int) $id)->all();

        $units = ConstructionUnit::query()
            ->whereIn('construction_id', $constructionIds)
            ->orderBy('id')
            ->get(['id', 'construction_id', 'block', 'unit', 'base_value', 'base_value_reference_date']);

        $unitIds = $units->map(fn (ConstructionUnit $unit): int => (int) $unit->getKey())->all();

        $contracts = Contract::query()
            ->whereIn('construction_id', $constructionIds)
            ->where('sale_date', '<=', InclusiveDateBound::upperBound($positionDate))
            ->orderBy('id')
            ->get(['id', 'construction_id', 'construction_unit_id', 'code', 'sale_date', 'sale_value', 'cancellation_date', 'status']);

        $contractIds = $contracts->map(fn (Contract $contract): int => (int) $contract->getKey())->all();

        $installments = $contractIds === []
            ? collect()
            : ContractInstallment::query()
                ->whereIn('contract_id', $contractIds)
                ->orderBy('id')
                ->get(['id', 'contract_id', 'expected_value', 'paid_value', 'payment_date', 'cancellation_date']);

        $values = $unitIds === []
            ? collect()
            : ConstructionUnitValue::query()
                ->whereIn('construction_unit_id', $unitIds)
                ->where('effective_from', '<=', InclusiveDateBound::upperBound($positionDate))
                ->orderBy('id')
                ->get(['id', 'construction_unit_id', 'value', 'effective_from']);

        $exchanges = $unitIds === []
            ? collect()
            : ConstructionUnitExchange::query()
                ->whereIn('construction_unit_id', $unitIds)
                ->effectiveOn($positionDate)
                ->orderBy('id')
                ->get(['id', 'construction_unit_id', 'contract_id', 'exchange_value', 'effective_from', 'ended_on', 'kind']);

        $latestSaleDates = $this->latestSaleDatePerConstruction($contracts, $month, $positionDate);
        $policies = $this->loadPolicies($latestSaleDates);

        $observations = [];

        foreach ($constructionIds as $constructionId) {
            $observations[$constructionId] = $this->observationFor(
                constructionId: $constructionId,
                units: $units->filter(fn (ConstructionUnit $unit): bool => (int) $unit->construction_id === $constructionId),
                contracts: $contracts->filter(fn (Contract $contract): bool => (int) $contract->construction_id === $constructionId),
                installments: $installments,
                values: $values,
                exchanges: $exchanges,
                policies: $policies,
                latestSaleDate: $latestSaleDates[$constructionId] ?? null,
            );
        }

        return $observations;
    }

    public function snapshotFingerprint(SalesBoardSnapshot $snapshot): string
    {
        return $snapshot->fingerprint();
    }

    /**
     * Monta a observação de um empreendimento a partir das fontes já carregadas.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, ContractInstallment>  $installments
     * @param  Collection<int, ConstructionUnitValue>  $values
     * @param  Collection<int, ConstructionUnitExchange>  $exchanges
     * @param  Collection<int, SalesDiscountPolicy>  $policies
     */
    private function observationFor(
        int $constructionId,
        Collection $units,
        Collection $contracts,
        Collection $installments,
        Collection $values,
        Collection $exchanges,
        Collection $policies,
        ?CarbonImmutable $latestSaleDate,
    ): SalesBoardSourceObservation {
        $unitIds = $units->map(fn (ConstructionUnit $unit): int => (int) $unit->getKey())->values()->all();
        $unitIdSet = array_flip($unitIds);

        $unitRows = [];
        foreach ($units as $unit) {
            $unitRows[(int) $unit->getKey()] = CanonicalDigest::row([
                (int) $unit->getKey(),
                $unit->block,
                $unit->unit,
                IntegerMoney::cents($unit->base_value),
                $unit->base_value_reference_date,
            ]);
        }

        $valueRows = [];
        foreach ($values as $value) {
            $unitId = (int) $value->construction_unit_id;

            if (! isset($unitIdSet[$unitId])) {
                continue;
            }

            $valueRows[$unitId][] = CanonicalDigest::row([
                (int) $value->getKey(),
                $unitId,
                IntegerMoney::cents($value->value),
                $value->effective_from,
            ]);
        }

        $exchangeRows = [];
        foreach ($exchanges as $exchange) {
            $unitId = (int) $exchange->construction_unit_id;

            if (! isset($unitIdSet[$unitId])) {
                continue;
            }

            $exchangeRows[$unitId][] = CanonicalDigest::row([
                (int) $exchange->getKey(),
                $unitId,
                $exchange->contract_id === null ? null : (int) $exchange->contract_id,
                IntegerMoney::cents($exchange->exchange_value),
                $exchange->effective_from,
                $exchange->ended_on,
                $exchange->kind,
            ]);
        }

        $contractRows = [];
        $contractIdsByUnit = [];
        $unitIdByContract = [];

        foreach ($contracts as $contract) {
            $contractId = (int) $contract->getKey();
            $unitId = (int) $contract->construction_unit_id;

            /**
             * `status` é material apesar de não classificar nada: ele é o que
             * denuncia um contrato marcado como permutado sem permuta
             * registrada, e esse achado bloqueia a competência. Uma fonte que
             * passa a bloquear precisa ser detectada.
             */
            $contractRows[$contractId] = CanonicalDigest::row([
                $contractId,
                $unitId,
                $contract->code,
                $contract->sale_date,
                IntegerMoney::cents($contract->sale_value),
                $contract->cancellation_date,
                $contract->status,
            ]);

            $contractIdsByUnit[$unitId][] = $contractId;
            $unitIdByContract[$contractId] = $unitId;
        }

        $installmentRows = [];
        foreach ($installments as $installment) {
            $contractId = (int) $installment->contract_id;

            if (! isset($contractRows[$contractId])) {
                continue;
            }

            /**
             * `due_date` fica fora: o vencimento não participa de nenhuma
             * decisão da derivação -- a quitação olha pagamento, valor e
             * cancelamento -- e incluí-lo faria um reagendamento de boleto
             * marcar a competência como alterada.
             */
            $installmentRows[$contractId][] = CanonicalDigest::row([
                (int) $installment->getKey(),
                $contractId,
                IntegerMoney::cents($installment->expected_value),
                IntegerMoney::cents($installment->paid_value),
                $installment->payment_date,
                $installment->cancellation_date,
            ]);
        }

        $policyRows = $latestSaleDate === null ? [] : $policies
            ->filter(fn (SalesDiscountPolicy $policy): bool => ((int) $policy->construction_id === $constructionId)
                && ($this->date($policy->effective_from)->toDateString() <= $latestSaleDate->toDateString()))
            ->map(fn (SalesDiscountPolicy $policy): string => CanonicalDigest::row($this->policyFields($policy, $constructionId)))
            ->values()
            ->all();

        return new SalesBoardSourceObservation(
            constructionId: $constructionId,
            unitRows: $unitRows,
            valueRows: $valueRows,
            exchangeRows: $exchangeRows,
            contractRows: $contractRows,
            contractIdsByUnit: $contractIdsByUnit,
            installmentRows: $installmentRows,
            unitIdByContract: $unitIdByContract,
            policyRows: $policyRows,
        );
    }

    /**
     * Data da venda mais recente da competência, por empreendimento.
     *
     * É o que delimita quais políticas são fonte desta competência. Sem venda no
     * mês, nenhuma política é consultada pela derivação -- e, portanto, nenhuma
     * pode torná-la obsoleta.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, CarbonImmutable>
     */
    private function latestSaleDatePerConstruction(Collection $contracts, CarbonImmutable $month, CarbonImmutable $positionDate): array
    {
        $latest = [];

        foreach ($contracts as $contract) {
            $saleDate = $contract->sale_date?->toDateString();

            if (($saleDate === null) || ($saleDate < $month->toDateString()) || ($saleDate > $positionDate->toDateString())) {
                continue;
            }

            $constructionId = (int) $contract->construction_id;
            $current = $latest[$constructionId] ?? null;

            if (($current === null) || ($saleDate > $current->toDateString())) {
                $latest[$constructionId] = CarbonImmutable::parse($saleDate);
            }
        }

        return $latest;
    }

    /**
     * @param  array<int, CarbonImmutable>  $latestSaleDates
     * @return Collection<int, SalesDiscountPolicy>
     */
    private function loadPolicies(array $latestSaleDates): Collection
    {
        if ($latestSaleDates === []) {
            return collect();
        }

        $bound = collect($latestSaleDates)->sortDesc()->first();

        return SalesDiscountPolicy::query()
            ->whereIn('construction_id', array_keys($latestSaleDates))
            ->where('effective_from', '<=', InclusiveDateBound::upperBound($bound))
            ->orderBy('id')
            ->get(['id', 'construction_id', 'maximum_discount_percent', 'effective_from', 'effective_until']);
    }

    /**
     * Campos da política que entram no fingerprint da fonte.
     *
     * O fim só entra quando existe. As linhas anteriores ao fim explícito não
     * têm fim, e mantê-las com os mesmos campos de antes preserva o fingerprint
     * das bases já congeladas: acrescentar um marcador de nulo a todas elas
     * marcaria como alterada toda competência com política, sem que nada na
     * fonte tivesse mudado.
     *
     * @return list<mixed>
     */
    private function policyFields(SalesDiscountPolicy $policy, int $constructionId): array
    {
        $fields = [
            (int) $policy->getKey(),
            $constructionId,
            IntegerMoney::basisPoints($policy->maximum_discount_percent),
            $policy->effective_from,
        ];

        if ($policy->effective_until !== null) {
            $fields[] = $policy->effective_until;
        }

        return $fields;
    }

    private function date(mixed $value): CarbonImmutable
    {
        return $value instanceof CarbonInterface
            ? CarbonImmutable::parse($value->toDateString())
            : CarbonImmutable::parse((string) $value)->startOfDay();
    }
}
