<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\DTOs\SalesBoards\SalesBoardSourceObservation;
use App\Enums\ContractStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesDiscountPolicy;
use App\Support\Contracts\ContractOccupancy;
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
 * A baixa de unidade segue o mesmo recorte: entra só a vigente na data da
 * posição, e só ela acrescenta campos à linha da unidade.
 *
 * O mesmo recorte vale para os fatos datados de contratos e parcelas. Pagamento
 * e distrato posteriores à data da posição entram como ausentes, e o `status`
 * do contrato entra só no único ponto em que a derivação o consulta -- marcado
 * como permutado enquanto ocupa a unidade. Tudo o que ainda pode mudar a posição
 * naquela data continua dentro: um pagamento que passa de antes para depois do
 * fechamento, ou o contrário, muda o resumo.
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
     * Sete consultas -- unidades, baixas, contratos, parcelas, valores, permutas
     * e políticas --, constantes como as da derivação, e pelo mesmo motivo: uma
     * emissão inteira não pode custar um punhado de consultas por obra.
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

        $retirementsByUnit = $unitIds === []
            ? collect()
            : ConstructionUnitRetirement::query()
                ->whereIn('construction_unit_id', $unitIds)
                ->effectiveOn($positionDate)
                ->orderBy('id')
                ->get(['id', 'construction_unit_id', 'retired_on'])
                ->groupBy(fn (ConstructionUnitRetirement $retirement): int => (int) $retirement->construction_unit_id);

        $contracts = Contract::query()
            ->whereIn('construction_id', $constructionIds)
            ->where('sale_date', '<=', InclusiveDateBound::upperBound($positionDate))
            ->orderBy('id')
            ->get(['id', 'construction_id', 'construction_unit_id', 'code', 'sale_date', 'sale_value', 'cancellation_date', 'status', 'deleted_at']);

        $contractIds = $contracts->map(fn (Contract $contract): int => (int) $contract->getKey())->all();

        $installmentDigests = $contractIds === []
            ? []
            : $this->installmentDigests($contractIds, $positionDate);

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
                retirementsByUnit: $retirementsByUnit,
                contracts: $contracts->filter(fn (Contract $contract): bool => (int) $contract->construction_id === $constructionId),
                installmentDigests: $installmentDigests,
                values: $values,
                exchanges: $exchanges,
                policies: $policies,
                latestSaleDate: $latestSaleDates[$constructionId] ?? null,
                positionDate: $positionDate,
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
     * @param  Collection<int, Collection<int, ConstructionUnitRetirement>>  $retirementsByUnit  as vigentes na data da posição
     * @param  Collection<int, Contract>  $contracts
     * @param  array<int, string>  $installmentDigests  indexado por `contract_id`
     * @param  Collection<int, ConstructionUnitValue>  $values
     * @param  Collection<int, ConstructionUnitExchange>  $exchanges
     * @param  Collection<int, SalesDiscountPolicy>  $policies
     */
    private function observationFor(
        int $constructionId,
        Collection $units,
        Collection $retirementsByUnit,
        Collection $contracts,
        array $installmentDigests,
        Collection $values,
        Collection $exchanges,
        Collection $policies,
        ?CarbonImmutable $latestSaleDate,
        CarbonImmutable $positionDate,
    ): SalesBoardSourceObservation {
        $unitIds = $units->map(fn (ConstructionUnit $unit): int => (int) $unit->getKey())->values()->all();
        $unitIdSet = array_flip($unitIds);

        $unitRows = [];
        foreach ($units as $unit) {
            $unitRows[(int) $unit->getKey()] = CanonicalDigest::row($this->unitFields(
                $unit,
                $retirementsByUnit->get((int) $unit->getKey(), collect()),
            ));
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
        $positionDay = $positionDate->toDateString();

        foreach ($contracts as $contract) {
            $contractId = (int) $contract->getKey();
            $unitId = (int) $contract->construction_unit_id;

            /**
             * O distrato entra só se já tinha acontecido na data da posição: um
             * distrato de setembro não libera a unidade em julho, e a derivação
             * de julho o ignora.
             *
             * `status` é material apesar de não classificar nada: ele é o que
             * denuncia um contrato marcado como permutado sem permuta
             * registrada, e esse achado bloqueia a competência. Mas a derivação
             * só o consulta para um contrato que ocupa a unidade na data, e só
             * pergunta se ele é "permutado". Entra, portanto, essa resposta --
             * e não o status cru: marcar como quitado ou distratado depois do
             * fechamento não muda nada no mês fechado.
             */
            $contractRows[$contractId] = CanonicalDigest::row([
                $contractId,
                $unitId,
                $contract->code,
                $contract->sale_date,
                IntegerMoney::cents($contract->sale_value),
                $this->dayUpTo($contract->cancellation_date?->toDateString(), $positionDay),
                ($contract->status === ContractStatus::Exchanged) && ContractOccupancy::occupiesAt($contract, $positionDate),
            ]);

            $contractIdsByUnit[$unitId][] = $contractId;
            $unitIdByContract[$contractId] = $unitId;
        }

        $scheduleDigests = array_intersect_key($installmentDigests, $contractRows);

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
            installmentDigests: $scheduleDigests,
            unitIdByContract: $unitIdByContract,
            policyRows: $policyRows,
        );
    }

    /**
     * Campos da unidade que entram no fingerprint da fonte.
     *
     * A baixa vigente na data da posição entra -- o id e a data dela --, e só
     * quando existe. A unidade sem baixa mantém exatamente os campos de antes, e
     * com isso o fingerprint de toda versão já congelada continua o mesmo: um
     * marcador de nulo em todas as unidades marcaria como alterada cada
     * competência aberta no deploy, sem que nada na fonte tivesse mudado. É o
     * mesmo raciocínio do fim da política de desconto.
     *
     * O motivo e a reativação futura ficam de fora: não decidem a posição, e
     * entrar com eles daria alarme falso. Uma reativação que alcança a data
     * devolve a linha ao formato de sempre.
     *
     * @param  Collection<int, ConstructionUnitRetirement>  $retirements  as vigentes na data da posição
     * @return list<mixed>
     */
    private function unitFields(ConstructionUnit $unit, Collection $retirements): array
    {
        $fields = [
            (int) $unit->getKey(),
            $unit->block,
            $unit->unit,
            IntegerMoney::cents($unit->base_value),
            $unit->base_value_reference_date,
        ];

        foreach ($retirements as $retirement) {
            $fields[] = (int) $retirement->getKey();
            $fields[] = $retirement->retired_on;
        }

        return $fields;
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

    /**
     * Um resumo por contrato do cronograma como ele estava na data da posição.
     *
     * As parcelas são a fonte que cresce com a obra -- uma obra madura tem
     * dezenas de milhares -- e por isso são lidas em fluxo, como linhas simples,
     * sem virar model: hidratar cada uma custava cerca de 2 KB e dezenas de
     * microssegundos, e a observação de 57.600 parcelas ocupava mais de 100 MB
     * de uma requisição que tem 256. Da parcela não sobra nada além do resumo
     * do contrato dela.
     *
     * A ordem por contrato e depois por `id` é o que permite resumir em fluxo:
     * cada contrato chega inteiro, na mesma ordem de sempre, e o resumo dele
     * fecha antes de o próximo começar. Uma consulta só, como antes, e o
     * soft delete do model continua valendo -- `toBase()` aplica os escopos.
     *
     * `due_date` fica fora: o vencimento não participa de nenhuma decisão da
     * derivação -- a quitação olha pagamento, valor e cancelamento -- e incluí-lo
     * faria um reagendamento de boleto marcar a competência como alterada.
     *
     * @param  list<int>  $contractIds
     * @return array<int, string> resumo indexado por `contract_id`
     */
    private function installmentDigests(array $contractIds, CarbonImmutable $positionDate): array
    {
        $positionDay = $positionDate->toDateString();
        $digests = [];
        $currentContractId = null;
        $schedule = null;

        $rows = ContractInstallment::query()
            ->select(['id', 'contract_id', 'expected_value', 'paid_value', 'discount_value', 'payment_date', 'cancellation_date'])
            ->whereIn('contract_id', $contractIds)
            ->orderBy('contract_id')
            ->orderBy('id')
            ->toBase()
            ->cursor();

        foreach ($rows as $row) {
            $contractId = (int) $row->contract_id;

            if ($contractId !== $currentContractId) {
                if ($schedule !== null) {
                    $digests[$currentContractId] = $schedule->digest();
                }

                $currentContractId = $contractId;
                $schedule = new CanonicalDigest;
            }

            $schedule->append(CanonicalDigest::row($this->installmentFields($row, $contractId, $positionDay)));
        }

        if ($schedule !== null) {
            $digests[$currentContractId] = $schedule->digest();
        }

        return $digests;
    }

    /**
     * Os fatos de uma parcela que a derivação usa na data da posição.
     *
     * Paga em D é `payment_date <= D` com valor suficiente -- o pago mais o
     * desconto registrado; válida em D é sem cancelamento ou cancelada depois de
     * D. Um pagamento ou um cancelamento posterior a D, portanto, responde
     * exatamente como a ausência deles -- e é assim que entra. O valor pago e o
     * desconto acompanham a data: sem pagamento até D, eles não decidem nada.
     *
     * O desconto só entra quando existe e o pagamento é até D. As parcelas sem
     * desconto mantêm os mesmos campos de antes, e com isso o fingerprint de
     * toda versão já congelada continua o mesmo: acrescentar um marcador de nulo
     * a todas elas marcaria como alterada cada competência, sem que nada na fonte
     * tivesse mudado.
     *
     * A regra do distrato posterior do {@see ContractSettlementResolver} lê um
     * fato que pode ser posterior à data -- o distrato -- e por isso não entra
     * aqui: um distrato lançado depois que reescreve uma competência aberta
     * aparece como mudança da posição (o snapshot), não da fonte.
     *
     * @return list<mixed>
     */
    private function installmentFields(object $installment, int $contractId, string $positionDay): array
    {
        $paymentDay = $this->dayUpTo($this->rawDay($installment->payment_date), $positionDay);

        $fields = [
            (int) $installment->id,
            $contractId,
            IntegerMoney::cents($installment->expected_value),
            $paymentDay === null ? null : IntegerMoney::cents($installment->paid_value),
            $paymentDay,
            $this->dayUpTo($this->rawDay($installment->cancellation_date), $positionDay),
        ];

        if (($paymentDay !== null) && ($installment->discount_value !== null)) {
            $fields[] = IntegerMoney::cents($installment->discount_value);
        }

        return $fields;
    }

    /**
     * O dia de uma coluna `date` lida sem model.
     *
     * O SQLite devolve o que o Eloquent gravou, com hora
     * (`2026-07-10 00:00:00`); o MySQL devolve só o dia. O fingerprint precisa
     * do mesmo texto nos dois -- o mesmo `Y-m-d` que o cast `date` produziria.
     */
    private function rawDay(mixed $value): ?string
    {
        if (($value === null) || ($value === '')) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * Um fato datado, se ele já tinha acontecido na data da posição.
     */
    private function dayUpTo(?string $day, string $positionDay): ?string
    {
        return (($day !== null) && ($day <= $positionDay)) ? $day : null;
    }

    private function date(mixed $value): CarbonImmutable
    {
        return $value instanceof CarbonInterface
            ? CarbonImmutable::parse($value->toDateString())
            : CarbonImmutable::parse((string) $value)->startOfDay();
    }
}
