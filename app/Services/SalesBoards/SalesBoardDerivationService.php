<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ContractSettlementSummary;
use App\DTOs\SalesBoards\ResolvedSalesDiscountPolicy;
use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\DTOs\SalesBoards\SalesBoardCancellationMovement;
use App\DTOs\SalesBoards\SalesBoardDerivedLine;
use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\DTOs\SalesBoards\SalesBoardIssue;
use App\DTOs\SalesBoards\SalesBoardMovements;
use App\DTOs\SalesBoards\SalesBoardSaleMovement;
use App\DTOs\SalesBoards\SalesBoardSettlementMovement;
use App\Enums\ContractSettlementState;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardUnitClassification;
use App\Enums\SalesPriceConformityStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Support\Contracts\ContractOccupancy;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reconstrói a composição do Quadro de Vendas de um empreendimento numa data.
 *
 * Só lê. Não escreve `SalesBoard`, não escreve ciclo, não escreve nada -- a
 * Fase C é que vai congelar o que este motor prova.
 *
 * O caminho é sempre o mesmo: uma linha por unidade, classificada com o que
 * valia **naquela data**, e os totais como agregação dessas linhas. Somar por
 * fora -- contar contratos ativos aqui, unidades ali -- é o que produz um total
 * que não fecha e não se consegue abrir.
 *
 * Nada depende de `Contract.status`: o status é a foto de hoje, e a pergunta é
 * sempre sobre uma data passada. O status entra apenas como sinal de
 * inconsistência no readiness.
 */
class SalesBoardDerivationService
{
    public function __construct(
        private readonly UnitValueResolver $unitValueResolver,
        private readonly SalesDiscountPolicyResolver $salesDiscountPolicyResolver,
        private readonly ContractSettlementResolver $contractSettlementResolver,
        private readonly SalesPriceConformityEvaluator $salesPriceConformityEvaluator,
    ) {}

    /**
     * Posição do empreendimento na competência.
     *
     * Competência 07/2026 significa a posição em 31/07/2026 -- o fechamento do
     * mês, nunca "hoje".
     */
    public function deriveForConstruction(Construction $construction, CarbonInterface $referenceMonth): SalesBoardDerivedPosition
    {
        $constructionId = (int) $construction->getKey();

        return $this->deriveForConstructions([$construction], $referenceMonth)[$constructionId];
    }

    /**
     * A mesma derivação para vários empreendimentos, com um carregamento só.
     *
     * Existe porque derivar a carteira de uma emissão chamando
     * {@see self::deriveForConstruction()} num laço custaria as seis consultas
     * da derivação vezes o número de empreendimentos. Aqui as fontes são
     * carregadas por conjunto de `construction_id` e particionadas em memória,
     * então o número de consultas é o mesmo para um empreendimento e para vinte.
     *
     * **Não é uma segunda implementação da regra.** A derivação de um
     * empreendimento passa por este método -- é o caminho único, e a versão de um
     * só é o caso de N igual a um. Um motor paralelo "otimizado" acabaria
     * respondendo diferente do original em algum canto, e o canto seria
     * financeiro.
     *
     * @param  iterable<Construction>  $constructions
     * @return array<int, SalesBoardDerivedPosition> indexado por `construction_id`
     */
    public function deriveForConstructions(iterable $constructions, CarbonInterface $referenceMonth): array
    {
        $month = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $positionDate = $month->endOfMonth()->startOfDay();

        /** @var Collection<int, Construction> $constructions */
        $constructions = collect($constructions)->keyBy(fn (Construction $construction): int => (int) $construction->getKey());

        if ($constructions->isEmpty()) {
            return [];
        }

        $constructionIds = $constructions->keys()->map(fn (mixed $id): int => (int) $id)->all();

        $unitsByConstruction = $this->loadUnits($constructionIds);
        $contractsByConstruction = $this->loadContracts($constructionIds, $positionDate);

        /** @var Collection<int, ConstructionUnit> $allUnits */
        $allUnits = $unitsByConstruction->flatten(1);
        /** @var Collection<int, Contract> $allContracts */
        $allContracts = $contractsByConstruction->flatten(1);

        $exchangesByUnit = $this->loadExchanges($allUnits);

        $settlements = $this->contractSettlementResolver->resolveForContractsAtDates(
            $allContracts->map(fn (Contract $contract): int => (int) $contract->getKey())->unique()->values()->all(),
            [$positionDate, $month->subDay()],
        );

        $salesInMonthByConstruction = $contractsByConstruction->map(
            fn (Collection $contracts): Collection => $this->salesInMonth($contracts, $month, $positionDate),
        );

        $unitValues = $this->resolveUnitValues($allUnits, $salesInMonthByConstruction->flatten(1), $positionDate);
        $policies = $this->resolvePolicies($salesInMonthByConstruction);

        $positions = [];

        foreach ($constructions as $constructionId => $construction) {
            $positions[(int) $constructionId] = $this->composePosition(
                construction: $construction,
                units: $unitsByConstruction->get((int) $constructionId, collect()),
                contracts: $contractsByConstruction->get((int) $constructionId, collect()),
                salesInMonth: $salesInMonthByConstruction->get((int) $constructionId, collect()),
                exchangesByUnit: $exchangesByUnit,
                settlements: $settlements,
                unitValues: $unitValues,
                policies: $policies,
                month: $month,
                positionDate: $positionDate,
            );
        }

        return $positions;
    }

    /**
     * Monta a posição de um empreendimento a partir das fontes já carregadas.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, Contract>  $salesInMonth
     * @param  Collection<int, Collection<int, ConstructionUnitExchange>>  $exchangesByUnit
     * @param  array<string, array<int, ContractSettlementSummary>>  $settlements
     * @param  array<string, ResolvedUnitValue>  $unitValues
     * @param  array<string, ResolvedSalesDiscountPolicy>  $policies
     */
    private function composePosition(
        Construction $construction,
        Collection $units,
        Collection $contracts,
        Collection $salesInMonth,
        Collection $exchangesByUnit,
        array $settlements,
        array $unitValues,
        array $policies,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
    ): SalesBoardDerivedPosition {
        $occupancyByUnit = $this->occupancyByUnit($contracts, $positionDate);

        $lines = [];
        $issues = [];

        foreach ($units as $unit) {
            $line = $this->deriveLine(
                unit: $unit,
                construction: $construction,
                positionDate: $positionDate,
                occupying: $occupancyByUnit[(int) $unit->getKey()] ?? collect(),
                exchanges: $exchangesByUnit->get((int) $unit->getKey(), collect()),
                settlements: $settlements[$positionDate->toDateString()] ?? [],
                unitValues: $unitValues,
            );

            $lines[] = $line;
            $issues = [...$issues, ...$line->issues];
        }

        if ($units->isEmpty()) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::NoConstructionUnits,
                message: 'O empreendimento não possui unidades cadastradas, então não há inventário a compor.',
            );
        }

        $exchanges = $units->mapWithKeys(fn (ConstructionUnit $unit): array => [
            (int) $unit->getKey() => $exchangesByUnit->get((int) $unit->getKey(), collect()),
        ]);

        $movements = $this->deriveMovements(
            construction: $construction,
            contracts: $contracts,
            units: $units,
            salesInMonth: $salesInMonth,
            settlements: $settlements,
            month: $month,
            positionDate: $positionDate,
            unitValues: $unitValues,
            policies: $policies,
        );

        $issues = [...$issues, ...$this->movementIssues($movements), ...$this->exchangeSourceIssues($contracts, $exchanges, $positionDate)];

        return SalesBoardDerivedPosition::fromLines(
            constructionId: (int) $construction->getKey(),
            constructionName: $construction->development_name,
            referenceMonth: $month,
            positionDate: $positionDate,
            lines: $lines,
            movements: $movements,
            issues: array_values($issues),
        );
    }

    /**
     * Classifica uma unidade na data.
     *
     * A ordem importa: permuta primeiro, porque uma unidade permutada não é
     * estoque nem venda financiada mesmo quando existe contrato ligado a ela.
     * Depois a ocupação, e só então a quitação.
     *
     * @param  Collection<int, Contract>  $occupying
     * @param  Collection<int, ConstructionUnitExchange>  $exchanges
     * @param  array<int, ContractSettlementSummary>  $settlements
     * @param  array<string, ResolvedUnitValue>  $unitValues
     */
    private function deriveLine(
        ConstructionUnit $unit,
        Construction $construction,
        CarbonImmutable $positionDate,
        Collection $occupying,
        Collection $exchanges,
        array $settlements,
        array $unitValues,
    ): SalesBoardDerivedLine {
        $unitId = (int) $unit->getKey();
        $issues = [];

        $effectiveExchanges = $exchanges->filter(
            fn (ConstructionUnitExchange $exchange): bool => $exchange->isEffectiveOn($positionDate),
        )->values();

        $contract = $occupying->first();

        if ($occupying->count() > 1) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::AmbiguousOccupancy,
                message: sprintf(
                    'A unidade tem %d contratos ocupando-a em %s (%s).',
                    $occupying->count(),
                    $positionDate->format('d/m/Y'),
                    $occupying->map(fn (Contract $contract): string => (string) $contract->code)->implode(', '),
                ),
                constructionUnitId: $unitId,
            );

            return $this->undeterminedLine($unit, null, $issues, $unitValues, $positionDate);
        }

        if (($contract !== null) && ((int) $contract->construction_id !== (int) $construction->getKey())) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::UnitConstructionMismatch,
                message: 'O contrato ocupante aponta para um empreendimento diferente do da unidade.',
                constructionUnitId: $unitId,
                contractId: (int) $contract->getKey(),
                contractCode: $contract->code,
            );

            return $this->undeterminedLine($unit, $contract, $issues, $unitValues, $positionDate);
        }

        if ($effectiveExchanges->count() > 1) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::AmbiguousExchange,
                message: sprintf(
                    'A unidade tem %d permutas vigentes em %s.',
                    $effectiveExchanges->count(),
                    $positionDate->format('d/m/Y'),
                ),
                constructionUnitId: $unitId,
            );

            return $this->undeterminedLine($unit, $contract, $issues, $unitValues, $positionDate);
        }

        /** @var ConstructionUnitExchange|null $exchange */
        $exchange = $effectiveExchanges->first();

        if ($exchange !== null) {
            /**
             * A permuta responde pela unidade, mas não pode contradizer quem
             * está com ela. Se um terceiro contrato ocupa a unidade, o conflito
             * é real e aparece -- escolher um dos dois esconderia o problema.
             */
            if (($contract !== null)
                && ($exchange->contract_id !== null)
                && ((int) $exchange->contract_id !== (int) $contract->getKey())) {
                $issues[] = new SalesBoardIssue(
                    code: SalesBoardIssueCode::ExchangeOccupancyConflict,
                    message: sprintf(
                        'A permuta vigente aponta para outro contrato (%s) e a unidade está ocupada pelo contrato %s.',
                        (string) $exchange->contract_id,
                        (string) $contract->code,
                    ),
                    constructionUnitId: $unitId,
                    contractId: (int) $contract->getKey(),
                    contractCode: $contract->code,
                );

                return $this->undeterminedLine($unit, $contract, $issues, $unitValues, $positionDate);
            }

            return $this->line(
                unit: $unit,
                classification: SalesBoardUnitClassification::Exchanged,
                contract: $contract,
                settlement: null,
                exchange: $exchange,
                issues: $issues,
                unitValues: $unitValues,
                positionDate: $positionDate,
            );
        }

        if ($contract === null) {
            $referenceValue = $unitValues[$unitId.'@'.$positionDate->toDateString()] ?? null;

            if (($referenceValue === null) || $referenceValue->isAbsent()) {
                $issues[] = new SalesBoardIssue(
                    code: SalesBoardIssueCode::UnitValueMissing,
                    message: sprintf(
                        'A unidade está em estoque em %s e não tem valor de referência conhecido nessa data.',
                        $positionDate->format('d/m/Y'),
                    ),
                    constructionUnitId: $unitId,
                );
            }

            return $this->line(
                unit: $unit,
                classification: SalesBoardUnitClassification::Stock,
                contract: null,
                settlement: null,
                exchange: null,
                issues: $issues,
                unitValues: $unitValues,
                positionDate: $positionDate,
            );
        }

        $settlement = $settlements[(int) $contract->getKey()] ?? null;

        if (($settlement === null) || $settlement->isUndetermined()) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::SettlementUndetermined,
                message: sprintf(
                    'O contrato %s não tem cronograma de parcelas válido em %s, então não é possível decidir se estava quitado.',
                    (string) $contract->code,
                    $positionDate->format('d/m/Y'),
                ),
                constructionUnitId: $unitId,
                contractId: (int) $contract->getKey(),
                contractCode: $contract->code,
            );

            return $this->undeterminedLine($unit, $contract, $issues, $unitValues, $positionDate, $settlement);
        }

        return $this->line(
            unit: $unit,
            classification: $settlement->isSettled()
                ? SalesBoardUnitClassification::Settled
                : SalesBoardUnitClassification::Financed,
            contract: $contract,
            settlement: $settlement,
            exchange: null,
            issues: $issues,
            unitValues: $unitValues,
            positionDate: $positionDate,
        );
    }

    /**
     * @param  list<SalesBoardIssue>  $issues
     * @param  array<string, ResolvedUnitValue>  $unitValues
     */
    private function undeterminedLine(
        ConstructionUnit $unit,
        ?Contract $contract,
        array $issues,
        array $unitValues,
        CarbonImmutable $positionDate,
        ?ContractSettlementSummary $settlement = null,
    ): SalesBoardDerivedLine {
        return $this->line(
            unit: $unit,
            classification: SalesBoardUnitClassification::Undetermined,
            contract: $contract,
            settlement: $settlement,
            exchange: null,
            issues: $issues,
            unitValues: $unitValues,
            positionDate: $positionDate,
        );
    }

    /**
     * @param  list<SalesBoardIssue>  $issues
     * @param  array<string, ResolvedUnitValue>  $unitValues
     */
    private function line(
        ConstructionUnit $unit,
        SalesBoardUnitClassification $classification,
        ?Contract $contract,
        ?ContractSettlementSummary $settlement,
        ?ConstructionUnitExchange $exchange,
        array $issues,
        array $unitValues,
        CarbonImmutable $positionDate,
    ): SalesBoardDerivedLine {
        $unitId = (int) $unit->getKey();
        $referenceValue = $unitValues[$unitId.'@'.$positionDate->toDateString()] ?? null;

        return new SalesBoardDerivedLine(
            constructionUnitId: $unitId,
            block: $unit->block,
            unit: $unit->unit,
            classification: $classification,
            contractId: $contract === null ? null : (int) $contract->getKey(),
            contractCode: $contract?->code,
            contractSaleDate: $contract?->sale_date === null ? null : CarbonImmutable::parse($contract->sale_date->toDateString()),
            contractSaleValueCents: $contract === null ? null : IntegerMoney::cents($contract->sale_value),
            unitReferenceValueCents: $referenceValue?->valueCents,
            unitReferenceValueSource: $referenceValue?->source,
            unitReferenceEffectiveFrom: $referenceValue?->effectiveFrom,
            settlementState: $settlement?->state,
            settlementInstallmentsTotal: $settlement?->validInstallments,
            settlementInstallmentsPaid: $settlement?->paidInstallments,
            exchangeId: $exchange === null ? null : (int) $exchange->getKey(),
            exchangeValueCents: $exchange === null ? null : IntegerMoney::cents($exchange->exchange_value),
            exchangeEffectiveFrom: $exchange?->effective_from === null ? null : CarbonImmutable::parse($exchange->effective_from->toDateString()),
            exchangeEndedOn: $exchange?->ended_on === null ? null : CarbonImmutable::parse($exchange->ended_on->toDateString()),
            exchangeKind: $exchange?->kind,
            issues: array_values($issues),
        );
    }

    /**
     * Unidades dos empreendimentos, agrupadas.
     *
     * A ordenação é a mesma de sempre e vem antes do agrupamento, então a ordem
     * das linhas dentro de cada empreendimento não muda por o carregamento ter
     * virado lote.
     *
     * @param  list<int>  $constructionIds
     * @return Collection<int, Collection<int, ConstructionUnit>>
     */
    private function loadUnits(array $constructionIds): Collection
    {
        return ConstructionUnit::query()
            ->whereIn('construction_id', $constructionIds)
            ->orderBy('block')
            ->orderBy('unit')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ConstructionUnit $unit): int => (int) $unit->construction_id);
    }

    /**
     * Contratos do empreendimento vendidos até a data da posição.
     *
     * Uma venda posterior à data não compõe a posição nem os movimentos do mês,
     * e um distrato posterior pertence a outra competência -- mas o contrato
     * dele precisa estar aqui, e está: o distrato é sempre posterior à venda.
     *
     * @param  list<int>  $constructionIds
     * @return Collection<int, Collection<int, Contract>>
     */
    private function loadContracts(array $constructionIds, CarbonImmutable $positionDate): Collection
    {
        return Contract::query()
            ->whereIn('construction_id', $constructionIds)
            ->where('sale_date', '<=', InclusiveDateBound::upperBound($positionDate))
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Contract $contract): int => (int) $contract->construction_id);
    }

    /**
     * @param  Collection<int, ConstructionUnit>  $units
     * @return Collection<int, Collection<int, ConstructionUnitExchange>>
     */
    private function loadExchanges(Collection $units): Collection
    {
        if ($units->isEmpty()) {
            return collect();
        }

        return ConstructionUnitExchange::query()
            ->whereIn('construction_unit_id', $units->map(fn (ConstructionUnit $unit): int => (int) $unit->getKey())->all())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ConstructionUnitExchange $exchange): int => (int) $exchange->construction_unit_id);
    }

    /**
     * Contratos que seguravam cada unidade na data.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, Collection<int, Contract>>
     */
    private function occupancyByUnit(Collection $contracts, CarbonImmutable $positionDate): array
    {
        $occupancy = [];

        foreach ($contracts as $contract) {
            if (! ContractOccupancy::occupiesAt($contract, $positionDate)) {
                continue;
            }

            $occupancy[(int) $contract->construction_unit_id][] = $contract;
        }

        return array_map(static fn (array $group): Collection => collect($group), $occupancy);
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return Collection<int, Contract>
     */
    private function salesInMonth(Collection $contracts, CarbonImmutable $month, CarbonImmutable $positionDate): Collection
    {
        return $contracts->filter(function (Contract $contract) use ($month, $positionDate): bool {
            $saleDate = $contract->sale_date?->toDateString();

            return ($saleDate !== null)
                && ($saleDate >= $month->toDateString())
                && ($saleDate <= $positionDate->toDateString());
        })->values();
    }

    /**
     * Valores de referência de que a derivação precisa, num carregamento só.
     *
     * Duas perguntas diferentes caem aqui: quanto cada unidade vale na data da
     * posição, e quanto valia a unidade de cada venda **na data daquela venda**.
     * A segunda tem uma data por venda, e é por isso que a resolução é por par
     * (unidade, data) em vez de por data única.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $salesInMonth
     * @return array<string, ResolvedUnitValue>
     */
    private function resolveUnitValues(
        Collection $units,
        Collection $salesInMonth,
        CarbonImmutable $positionDate,
    ): array {
        $requests = $units
            ->map(fn (ConstructionUnit $unit): array => [
                'unit_id' => (int) $unit->getKey(),
                'date' => $positionDate,
            ])
            ->values()
            ->all();

        foreach ($salesInMonth as $contract) {
            if ($contract->sale_date === null) {
                continue;
            }

            $requests[] = [
                'unit_id' => (int) $contract->construction_unit_id,
                'date' => CarbonImmutable::parse($contract->sale_date->toDateString()),
            ];
        }

        return $this->unitValueResolver->forUnitDates($units, $requests);
    }

    /**
     * Política comercial vigente em cada par (empreendimento, data de venda).
     *
     * Uma consulta para a emissão inteira: as vendas da competência podem ter
     * datas diferentes e empreendimentos diferentes, e a política pode ter
     * mudado entre elas.
     *
     * @param  Collection<int, Collection<int, Contract>>  $salesInMonthByConstruction
     * @return array<string, ResolvedSalesDiscountPolicy>
     */
    private function resolvePolicies(Collection $salesInMonthByConstruction): array
    {
        $requests = [];

        foreach ($salesInMonthByConstruction as $constructionId => $sales) {
            foreach ($sales as $contract) {
                if ($contract->sale_date === null) {
                    continue;
                }

                $requests[] = [
                    'construction_id' => (int) $constructionId,
                    'date' => CarbonImmutable::parse($contract->sale_date->toDateString()),
                ];
            }
        }

        return $this->salesDiscountPolicyResolver->forConstructionDates($requests);
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $salesInMonth
     * @param  array<string, array<int, ContractSettlementSummary>>  $settlements
     * @param  array<string, ResolvedUnitValue>  $unitValues
     * @param  array<string, ResolvedSalesDiscountPolicy>  $policies
     */
    private function deriveMovements(
        Construction $construction,
        Collection $contracts,
        Collection $units,
        Collection $salesInMonth,
        array $settlements,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
        array $unitValues,
        array $policies,
    ): SalesBoardMovements {
        $unitsById = $units->keyBy(fn (ConstructionUnit $unit): int => (int) $unit->getKey());

        $sales = $salesInMonth->map(function (Contract $contract) use ($unitsById, $unitValues, $policies, $construction): SalesBoardSaleMovement {
            $unitId = (int) $contract->construction_unit_id;
            $saleDate = CarbonImmutable::parse($contract->sale_date->toDateString());
            $unit = $unitsById->get($unitId);

            $referenceValue = $unitValues[$unitId.'@'.$saleDate->toDateString()]
                ?? ResolvedUnitValue::absent($unitId, $saleDate);
            $policy = $policies[$construction->getKey().'@'.$saleDate->toDateString()]
                ?? ResolvedSalesDiscountPolicy::absent((int) $construction->getKey(), $saleDate);

            $conformity = $this->salesPriceConformityEvaluator->evaluate(
                saleDate: $saleDate,
                saleValue: $contract->sale_value,
                unitValue: $referenceValue,
                policy: $policy,
            );

            return new SalesBoardSaleMovement(
                contractId: (int) $contract->getKey(),
                contractCode: $contract->code,
                constructionUnitId: $unitId,
                block: $unit?->block,
                unit: $unit?->unit,
                saleDate: $saleDate,
                saleValueCents: IntegerMoney::cents($contract->sale_value),
                conformity: $conformity,
                unitReferenceValueCents: $referenceValue->valueCents,
                unitReferenceValueSource: $referenceValue->source,
                unitReferenceEffectiveFrom: $referenceValue->effectiveFrom,
                salesDiscountPolicyId: $policy->policy === null ? null : (int) $policy->policy->getKey(),
            );
        })->all();

        $cancellations = $contracts
            ->filter(function (Contract $contract) use ($month, $positionDate): bool {
                $cancellationDate = $contract->cancellation_date?->toDateString();

                return ($cancellationDate !== null)
                    && ($cancellationDate >= $month->toDateString())
                    && ($cancellationDate <= $positionDate->toDateString());
            })
            ->map(function (Contract $contract) use ($unitsById): SalesBoardCancellationMovement {
                $unitId = (int) $contract->construction_unit_id;
                $unit = $unitsById->get($unitId);

                return new SalesBoardCancellationMovement(
                    contractId: (int) $contract->getKey(),
                    contractCode: $contract->code,
                    constructionUnitId: $unitId,
                    block: $unit?->block,
                    unit: $unit?->unit,
                    saleDate: $contract->sale_date === null ? null : CarbonImmutable::parse($contract->sale_date->toDateString()),
                    saleValueCents: IntegerMoney::cents($contract->sale_value),
                    cancellationDate: CarbonImmutable::parse($contract->cancellation_date->toDateString()),
                );
            })
            ->values()
            ->all();

        $settlementsAtClose = $settlements[$positionDate->toDateString()] ?? [];
        $settlementsBefore = $settlements[$month->subDay()->toDateString()] ?? [];

        $settlementMovements = $contracts
            ->filter(function (Contract $contract) use ($settlementsAtClose, $settlementsBefore): bool {
                $contractId = (int) $contract->getKey();
                $closed = $settlementsAtClose[$contractId] ?? null;
                $before = $settlementsBefore[$contractId] ?? null;

                return ($closed !== null)
                    && $closed->isSettled()
                    && (($before === null) || ($before->state !== ContractSettlementState::Settled));
            })
            ->map(function (Contract $contract) use ($unitsById, $settlementsAtClose): SalesBoardSettlementMovement {
                $unitId = (int) $contract->construction_unit_id;
                $unit = $unitsById->get($unitId);
                $summary = $settlementsAtClose[(int) $contract->getKey()] ?? null;

                return new SalesBoardSettlementMovement(
                    contractId: (int) $contract->getKey(),
                    contractCode: $contract->code,
                    constructionUnitId: $unitId,
                    block: $unit?->block,
                    unit: $unit?->unit,
                    saleDate: $contract->sale_date === null ? null : CarbonImmutable::parse($contract->sale_date->toDateString()),
                    saleValueCents: IntegerMoney::cents($contract->sale_value),
                    installments: $summary?->validInstallments ?? 0,
                );
            })
            ->values()
            ->all();

        return new SalesBoardMovements(
            sales: array_values($sales),
            settlements: $settlementMovements,
            cancellations: $cancellations,
        );
    }

    /**
     * Achados das vendas da competência.
     *
     * Uma venda fora da política é aviso, não bloqueador: ela foi apurada
     * justamente porque a derivação funcionou. Já uma venda que não pôde ser
     * avaliada por falta de valor ou de política é ausência de dado, e essa
     * bloqueia.
     *
     * @return list<SalesBoardIssue>
     */
    private function movementIssues(SalesBoardMovements $movements): array
    {
        $issues = [];

        foreach ($movements->sales as $sale) {
            $status = $sale->conformity->status;

            if ($status === SalesPriceConformityStatus::NonConform) {
                $issues[] = new SalesBoardIssue(
                    code: SalesBoardIssueCode::SaleNonConform,
                    message: sprintf(
                        'A venda %s ficou abaixo do preço mínimo autorizado (%s contra %s).',
                        (string) $sale->contractCode,
                        IntegerMoney::format((int) $sale->conformity->saleValueCents),
                        IntegerMoney::format((int) $sale->conformity->minimumAuthorizedValueCents),
                    ),
                    constructionUnitId: $sale->constructionUnitId,
                    contractId: $sale->contractId,
                    contractCode: $sale->contractCode,
                );

                continue;
            }

            if ($status !== SalesPriceConformityStatus::Undetermined) {
                continue;
            }

            $issues[] = new SalesBoardIssue(
                code: $sale->conformity->referenceValueCents === null
                    ? SalesBoardIssueCode::SaleUnitValueMissing
                    : SalesBoardIssueCode::SaleDiscountPolicyMissing,
                message: sprintf(
                    'A venda %s não pôde ser avaliada: %s',
                    (string) $sale->contractCode,
                    (string) $sale->conformity->reasonWhenUndetermined,
                ),
                constructionUnitId: $sale->constructionUnitId,
                contractId: $sale->contractId,
                contractCode: $sale->contractCode,
            );
        }

        return $issues;
    }

    /**
     * Contrato marcado como permutado hoje, sem nenhuma permuta registrada.
     *
     * O status não é fonte histórica e nunca classifica -- mas ele denuncia uma
     * permuta que existe no mundo e não existe no Nimbus, e isso precisa ser
     * declarado antes da automação, não adivinhado por backfill.
     *
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, Collection<int, ConstructionUnitExchange>>  $exchanges
     * @return list<SalesBoardIssue>
     */
    private function exchangeSourceIssues(Collection $contracts, Collection $exchanges, CarbonImmutable $positionDate): array
    {
        $issues = [];

        foreach ($contracts as $contract) {
            if ($contract->status !== ContractStatus::Exchanged) {
                continue;
            }

            if (! ContractOccupancy::occupiesAt($contract, $positionDate)) {
                continue;
            }

            $unitId = (int) $contract->construction_unit_id;

            $hasSource = $exchanges->get($unitId, collect())
                ->contains(fn (ConstructionUnitExchange $exchange): bool => $exchange->isEffectiveOn($positionDate));

            if ($hasSource) {
                continue;
            }

            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::ExchangeSourceMissing,
                message: sprintf(
                    'O contrato %s está marcado como permutado, mas a unidade não tem permuta registrada vigente em %s.',
                    (string) $contract->code,
                    $positionDate->format('d/m/Y'),
                ),
                constructionUnitId: $unitId,
                contractId: (int) $contract->getKey(),
                contractCode: $contract->code,
            );
        }

        return $issues;
    }
}
