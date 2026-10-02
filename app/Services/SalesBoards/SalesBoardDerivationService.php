<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ContractScheduleFacts;
use App\DTOs\SalesBoards\ContractSettlementSummary;
use App\DTOs\SalesBoards\ResolvedSalesDiscountPolicy;
use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\DTOs\SalesBoards\SalesBoardCancellationMovement;
use App\DTOs\SalesBoards\SalesBoardDerivedLine;
use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\DTOs\SalesBoards\SalesBoardIssue;
use App\DTOs\SalesBoards\SalesBoardLateFacts;
use App\DTOs\SalesBoards\SalesBoardMovements;
use App\DTOs\SalesBoards\SalesBoardPriorLine;
use App\DTOs\SalesBoards\SalesBoardPriorPosition;
use App\DTOs\SalesBoards\SalesBoardSaleMovement;
use App\DTOs\SalesBoards\SalesBoardSettlementMovement;
use App\Enums\ContractSettlementState;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardUnitClassification;
use App\Enums\SalesPriceConformityStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\Contract;
use App\Support\BusinessTime;
use App\Support\Contracts\ContractOccupancy;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\ExchangeContractRecognizer;
use App\Support\SalesBoards\SalesBoardPlausibility;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
 * Nenhuma classificação nem quitação depende de `Contract.status`: o status é a
 * foto de hoje, e a pergunta é sempre sobre uma data passada. O status entra
 * como sinal de inconsistência -- permuta sem fonte, permuta sem contrato
 * ocupada por uma venda, quitação que o cronograma não explica, venda datada no
 * futuro, distratado sem a data do distrato -- e para reconhecer o contrato de
 * permuta, que não é venda.
 *
 * Além da classificação, a derivação confere a plausibilidade do que decide a
 * posição, com os limiares de {@see SalesBoardPlausibility}: valor de venda a
 * uma ordem de grandeza da tabela, parcela a dez vezes o valor da venda e data
 * anterior a 1990 bloqueiam; o ágio duvidoso, a parcela atípica, o pagamento
 * abaixo do previsto que segura a quitação e o pagamento com data futura
 * avisam. As checagens reaproveitam os contratos, as parcelas (no
 * {@see ContractSettlementResolver}) e os valores de referência já carregados.
 *
 * A unidade baixada ({@see ConstructionUnitRetirement}) sai das linhas a partir
 * da competência que contém a data da baixa -- e volta na que contém a
 * reativação --, sem apagar nada: a competência em que a presença muda recebe o
 * aviso, e a baixada que continua ocupada vira linha indeterminada, nunca some.
 * A baixa entra na classificação num ponto só ({@see self::classifyUnitAt()}).
 *
 * Também lê a posição congelada da competência anterior -- a âncora de
 * {@see SalesBoardPriorPositionResolver} --, porque os movimentos são o que
 * aconteceu depois dela. A janela dos movimentos vai do fim da âncora ao fim da
 * competência (o mês, quando a âncora é M-1; mais os meses cancelados entre as
 * duas, que esta competência absorve com timing "de competência sem posição").
 * Sem âncora, a janela é o mês -- ou, quando a cadeia de canceladas termina
 * num mês sem ciclo, começa no primeiro dia da cancelada mais antiga, que esta
 * competência absorve do mesmo jeito: nenhuma competência cancelada fica sem
 * dono. E a fonte viva é classificada na data da âncora, pela mesma regra de
 * toda linha: o que mudou ali sem ter sido congelado -- venda, distrato ou
 * quitação lançados depois, ou a venda publicada com valor ou data revistos --
 * vira movimento extemporâneo desta competência ({@see self::lateFacts()}).
 * Sem âncora não há posição congelada a comparar, e não há extemporâneos. Os
 * achados desses movimentos avisam, nunca bloqueiam: um problema num mês
 * fechado não segura a apuração do mês corrente.
 *
 * Limitações aceitas: venda e distrato datados dentro da mesma competência
 * fechada e lançados depois não mudam posição nenhuma e não viram movimento (a
 * trilha fica no Activitylog de contratos); a quitação extemporânea não tem
 * data, porque o motor não apura o dia; estorno de quitação, data de venda
 * movida, permuta e inclusão de unidade não têm fato datado, e aparecem só no
 * aviso e na "Ponte com a competência anterior".
 *
 * Oito leituras sem âncora e nove com ela: unidades, baixas, contratos,
 * permutas, ciclos anteriores, parcelas, valores de unidade, políticas e, havendo
 * âncora, as linhas congeladas dela.
 */
class SalesBoardDerivationService
{
    /**
     * Data depois de qualquer parcela registrada: o estado do cronograma com
     * tudo o que já foi lançado. Só serve para decidir se o status "quitado"
     * tem explicação em algum pagamento, nunca para classificar uma linha.
     */
    private const SCHEDULE_HORIZON = '9999-12-31';

    public function __construct(
        private readonly UnitValueResolver $unitValueResolver,
        private readonly SalesDiscountPolicyResolver $salesDiscountPolicyResolver,
        private readonly ContractSettlementResolver $contractSettlementResolver,
        private readonly SalesPriceConformityEvaluator $salesPriceConformityEvaluator,
        private readonly SalesBoardPriorPositionResolver $priorPositionResolver,
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
     * {@see self::deriveForConstruction()} num laço custaria as sete consultas
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

        /** @var Collection<int, ConstructionUnit> $allUnits */
        $allUnits = $unitsByConstruction->flatten(1);

        $retirementsByUnit = $this->loadRetirements($allUnits, $positionDate);

        [$contractsByConstruction, $futureDatedByConstruction] = $this->loadContracts($constructionIds, $allUnits, $positionDate);

        /** @var Collection<int, Contract> $allContracts */
        $allContracts = $contractsByConstruction->flatten(1);

        $exchangesByUnit = $this->loadExchanges($allUnits);

        $chain = $this->priorPositionResolver->chainsFor($constructionIds, $month);
        $anchors = $chain->anchors;

        $windowStarts = $constructions->keys()->mapWithKeys(fn (mixed $constructionId): array => [
            (int) $constructionId => $chain->windowStart((int) $constructionId, $month),
        ]);

        /**
         * A quitação é lida no fechamento, na véspera do mês, no horizonte, no
         * fim de cada âncora e na véspera de cada janela -- a que começa antes
         * do mês absorve competências canceladas, com âncora ou sem ela. Com a
         * âncora em M-1, o fim dela e a véspera da janela já são a véspera do
         * mês; sem nada a absorver, também.
         */
        $anchorDates = collect($anchors)
            ->map(fn (SalesBoardPriorPosition $anchor): CarbonImmutable => $anchor->positionDate)
            ->all();

        $windowEves = $windowStarts
            ->map(fn (CarbonImmutable $windowStart): CarbonImmutable => $windowStart->subDay())
            ->unique(fn (CarbonImmutable $eve): string => $eve->toDateString())
            ->values()
            ->all();

        $schedule = $this->contractSettlementResolver->resolveScheduleAtDates(
            $allContracts->map(fn (Contract $contract): int => (int) $contract->getKey())->unique()->values()->all(),
            [$positionDate, $month->subDay(), ...array_values($anchorDates), ...$windowEves, CarbonImmutable::parse(self::SCHEDULE_HORIZON)],
            $this->distratoDates($allContracts),
        );

        $settlements = $schedule->summaries;

        $salesInWindowByConstruction = $contractsByConstruction->map(
            fn (Collection $contracts, int|string $constructionId): Collection => $this->salesInWindow(
                $contracts,
                $windowStarts->get((int) $constructionId, $month),
                $positionDate,
            ),
        );

        $lateFactsByConstruction = [];

        foreach ($anchors as $constructionId => $anchor) {
            $construction = $constructions->get($constructionId);

            if (! $construction instanceof Construction) {
                continue;
            }

            $lateFactsByConstruction[$constructionId] = $this->lateFacts(
                construction: $construction,
                anchor: $anchor,
                units: $unitsByConstruction->get($constructionId, collect()),
                contracts: $contractsByConstruction->get($constructionId, collect()),
                exchangesByUnit: $exchangesByUnit,
                retirementsByUnit: $retirementsByUnit,
                settlementsAtAnchor: $settlements[$anchor->positionDate->toDateString()] ?? [],
            );
        }

        $unitValues = $this->resolveUnitValues($allUnits, $allContracts, $positionDate);
        $policies = $this->resolvePolicies($salesInWindowByConstruction, $lateFactsByConstruction);

        $positions = [];

        foreach ($constructions as $constructionId => $construction) {
            $positions[(int) $constructionId] = $this->composePosition(
                construction: $construction,
                units: $unitsByConstruction->get((int) $constructionId, collect()),
                contracts: $contractsByConstruction->get((int) $constructionId, collect()),
                futureDated: $futureDatedByConstruction->get((int) $constructionId, collect()),
                salesInWindow: $salesInWindowByConstruction->get((int) $constructionId, collect()),
                exchangesByUnit: $exchangesByUnit,
                retirementsByUnit: $retirementsByUnit,
                settlements: $settlements,
                scheduleFacts: $schedule->facts,
                unitValues: $unitValues,
                policies: $policies,
                month: $month,
                positionDate: $positionDate,
                anchor: $anchors[(int) $constructionId] ?? null,
                lateFacts: $lateFactsByConstruction[(int) $constructionId] ?? SalesBoardLateFacts::none(),
                windowStart: $windowStarts->get((int) $constructionId, $month),
                absorbedCancelledMonths: $chain->unanchoredCancelledMonthsOf((int) $constructionId),
            );
        }

        return $positions;
    }

    /**
     * A data do distrato de cada contrato que tem, para a regra do distrato
     * posterior do {@see ContractSettlementResolver}. Sai dos contratos já
     * carregados, sem consulta.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, string> contrato => `Y-m-d`
     */
    private function distratoDates(Collection $contracts): array
    {
        $dates = [];

        foreach ($contracts as $contract) {
            if ($contract->cancellation_date !== null) {
                $dates[(int) $contract->getKey()] = $contract->cancellation_date->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Monta a posição de um empreendimento a partir das fontes já carregadas.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, Contract>  $futureDated
     * @param  Collection<int, Contract>  $salesInWindow
     * @param  Collection<int, Collection<int, ConstructionUnitExchange>>  $exchangesByUnit
     * @param  Collection<int, Collection<int, ConstructionUnitRetirement>>  $retirementsByUnit
     * @param  array<string, array<int, ContractSettlementSummary>>  $settlements
     * @param  array<int, ContractScheduleFacts>  $scheduleFacts
     * @param  array<string, ResolvedUnitValue>  $unitValues
     * @param  array<string, ResolvedSalesDiscountPolicy>  $policies
     * @param  list<CarbonImmutable>  $absorbedCancelledMonths  as canceladas absorvidas sem âncora
     */
    private function composePosition(
        Construction $construction,
        Collection $units,
        Collection $contracts,
        Collection $futureDated,
        Collection $salesInWindow,
        Collection $exchangesByUnit,
        Collection $retirementsByUnit,
        array $settlements,
        array $scheduleFacts,
        array $unitValues,
        array $policies,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
        ?SalesBoardPriorPosition $anchor,
        SalesBoardLateFacts $lateFacts,
        CarbonImmutable $windowStart,
        array $absorbedCancelledMonths = [],
    ): SalesBoardDerivedPosition {
        $occupancyByUnit = $this->occupancyByUnit($contracts, $positionDate);

        /**
         * A presença da unidade é comparada com a véspera da janela: o fim da
         * âncora, ou a véspera da cancelada mais antiga que esta competência
         * absorve. Com a âncora em M-1 é o fim do mês anterior, como sempre.
         * Comparar com o fim de M-1 quando M-1 foi cancelada perderia a baixa
         * (ou a reativação) datada nela: ela já valeria nas duas pontas, e
         * nenhuma competência receberia o aviso que a ponte usa para explicar a
         * saída ou a volta da unidade.
         */
        $previousClose = $windowStart->subDay();

        $lines = [];
        $issues = [];

        foreach ($units as $unit) {
            $unitId = (int) $unit->getKey();
            $retirements = $retirementsByUnit->get($unitId, collect());

            $line = $this->classifyUnitAt(
                unit: $unit,
                construction: $construction,
                date: $positionDate,
                occupying: $occupancyByUnit[$unitId] ?? collect(),
                exchanges: $exchangesByUnit->get($unitId, collect()),
                retirements: $retirements,
                settlements: $settlements[$positionDate->toDateString()] ?? [],
                unitValues: $unitValues,
            );

            if ($line !== null) {
                $lines[] = $line;
                $issues = [...$issues, ...$line->issues];
            }

            $presenceChange = $this->presenceChangeIssue($unit, $retirements, $line, $positionDate, $previousClose);

            if ($presenceChange !== null) {
                $issues[] = $presenceChange;
            }
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

        /** @var Collection<int, SalesBoardDerivedLine> $linesByUnit */
        $linesByUnit = collect($lines)->keyBy(fn (SalesBoardDerivedLine $line): int => $line->constructionUnitId);

        $movements = $this->deriveMovements(
            construction: $construction,
            contracts: $contracts,
            units: $units,
            linesByUnit: $linesByUnit,
            salesInWindow: $this->withoutExchangeContracts($salesInWindow, $linesByUnit, $exchanges),
            exchanges: $exchanges,
            settlements: $settlements,
            month: $month,
            positionDate: $positionDate,
            unitValues: $unitValues,
            policies: $policies,
            windowStart: $windowStart,
            anchor: $anchor,
            lateFacts: $lateFacts,
        );

        $issues = [
            ...$issues,
            ...$lateFacts->issues,
            ...$this->movementIssues($movements),
            ...$this->exchangeSourceIssues($contracts, $exchanges, $positionDate),
            ...$this->constructionMismatchIssues($construction, $units, $contracts, $linesByUnit, $month, $positionDate),
            ...$this->settlementStatusIssues($contracts, $linesByUnit, $settlements, $positionDate),
            ...$this->futureSaleIssues($futureDated),
            ...$this->saleScaleIssues($contracts, $linesByUnit, $movements, $unitValues, $positionDate),
            ...$this->scheduleIssues($contracts, $linesByUnit, $settlements, $scheduleFacts, $positionDate),
            ...$this->contractDateIssues($contracts),
        ];

        return SalesBoardDerivedPosition::fromLines(
            constructionId: (int) $construction->getKey(),
            constructionName: $construction->development_name,
            referenceMonth: $month,
            positionDate: $positionDate,
            lines: $lines,
            movements: $movements,
            issues: array_values($issues),
            priorPosition: $anchor,
            absorbedCancelledMonths: $absorbedCancelledMonths,
        );
    }

    /**
     * A classificação de uma unidade numa data, já com a baixa -- o único
     * ponto em que a baixa entra na classificação, para que toda pergunta "o
     * que esta unidade era nesta data" a respeite.
     *
     * Sem baixa vigente na data, a linha de {@see self::deriveLine()}. Baixada e
     * livre, nenhuma linha: a unidade não compõe o Quadro -- nem unidades, nem
     * estoque, nem valor. Baixada e ocupada por contrato ou permuta, a linha
     * indeterminada com o bloqueador: sumir com ela sumiria com uma venda. É a
     * última defesa para a ocupação que chegou por fora da tela -- insert em
     * lote, carga antiga ou escrita direta.
     *
     * @param  Collection<int, Contract>  $occupying
     * @param  Collection<int, ConstructionUnitExchange>  $exchanges
     * @param  Collection<int, ConstructionUnitRetirement>  $retirements
     * @param  array<int, ContractSettlementSummary>  $settlements
     * @param  array<string, ResolvedUnitValue>  $unitValues
     * @return SalesBoardDerivedLine|null nula quando a unidade está baixada e livre na data
     */
    private function classifyUnitAt(
        ConstructionUnit $unit,
        Construction $construction,
        CarbonImmutable $date,
        Collection $occupying,
        Collection $exchanges,
        Collection $retirements,
        array $settlements,
        array $unitValues,
    ): ?SalesBoardDerivedLine {
        $retirement = self::retirementEffectiveOn($retirements, $date);

        if ($retirement === null) {
            return $this->deriveLine(
                unit: $unit,
                construction: $construction,
                positionDate: $date,
                occupying: $occupying,
                exchanges: $exchanges,
                settlements: $settlements,
                unitValues: $unitValues,
            );
        }

        /** @var Contract|null $contract */
        $contract = $occupying->first();
        $exchange = $exchanges->first(fn (ConstructionUnitExchange $exchange): bool => $exchange->isEffectiveOn($date));

        if (($contract === null) && ($exchange === null)) {
            return null;
        }

        $retiredSince = $retirement->retired_on->format('d/m/Y');

        return $this->undeterminedLine(
            unit: $unit,
            contract: $contract,
            issues: [new SalesBoardIssue(
                code: SalesBoardIssueCode::RetiredUnitInUse,
                message: $contract !== null
                    ? sprintf('A unidade está baixada desde %s, mas o contrato %s a ocupa em %s.', $retiredSince, (string) $contract->code, $date->format('d/m/Y'))
                    : sprintf('A unidade está baixada desde %s, mas tem permuta vigente em %s.', $retiredSince, $date->format('d/m/Y')),
                constructionUnitId: (int) $unit->getKey(),
                contractId: $contract === null ? null : (int) $contract->getKey(),
                contractCode: $contract?->code,
            )],
            unitValues: $unitValues,
            positionDate: $date,
        );
    }

    /**
     * O aviso da competência em que a presença da unidade muda pela baixa.
     *
     * A comparação é com a véspera da janela dos movimentos -- o fim do mês
     * anterior; quando esta competência absorve canceladas, o fim da âncora ou,
     * sem âncora, a véspera da cancelada mais antiga --, e não com a data da
     * baixa: o aviso aparece uma vez, na competência em que a unidade sai (ou
     * volta), e não se repete nas seguintes. A baixa anulada não vale em data
     * nenhuma e não produz aviso. A unidade baixada que continua ocupada já tem
     * o bloqueador e não recebe aviso de saída -- ela não saiu.
     *
     * Sem o motivo da baixa: ele é registro interno da Gestão, e o aviso chega
     * à construtora.
     *
     * @param  Collection<int, ConstructionUnitRetirement>  $retirements
     */
    private function presenceChangeIssue(
        ConstructionUnit $unit,
        Collection $retirements,
        ?SalesBoardDerivedLine $line,
        CarbonImmutable $positionDate,
        CarbonImmutable $previousClose,
    ): ?SalesBoardIssue {
        $now = self::retirementEffectiveOn($retirements, $positionDate);
        $before = self::retirementEffectiveOn($retirements, $previousClose);

        if (($now !== null) && ($before === null) && ($line === null)) {
            return new SalesBoardIssue(
                code: SalesBoardIssueCode::UnitRetired,
                message: sprintf(
                    'A unidade %s foi baixada a partir de %s e deixa de compor o Quadro nesta competência.',
                    $unit->display_name,
                    $now->retired_on->format('d/m/Y'),
                ),
                constructionUnitId: (int) $unit->getKey(),
            );
        }

        if (($now === null) && ($before !== null)) {
            return new SalesBoardIssue(
                code: SalesBoardIssueCode::UnitReactivated,
                message: sprintf(
                    'A unidade %s foi reativada a partir de %s e volta a compor o Quadro nesta competência.',
                    $unit->display_name,
                    $before->reactivated_on?->format('d/m/Y') ?? '—',
                ),
                constructionUnitId: (int) $unit->getKey(),
            );
        }

        return null;
    }

    /**
     * @param  Collection<int, ConstructionUnitRetirement>  $retirements
     */
    private static function retirementEffectiveOn(Collection $retirements, CarbonImmutable $date): ?ConstructionUnitRetirement
    {
        return $retirements->first(fn (ConstructionUnitRetirement $retirement): bool => $retirement->isEffectiveOn($date));
    }

    /**
     * Classifica uma unidade na data.
     *
     * A ordem importa: ocupação ambígua e obra divergente, depois a permuta,
     * porque uma unidade permutada não é estoque nem venda financiada mesmo
     * quando existe contrato ligado a ela. Depois o estoque, o ocupante
     * distratado sem a data do distrato, e só então a quitação.
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
             *
             * A permuta sem contrato vinculado (a inicial costuma nascer assim)
             * só convive com um ocupante que seja contrato de permuta. Uma venda
             * comum ocupando a unidade é o mesmo conflito, e absorvê-la no
             * "permutado" faria a venda sumir do financiado sem aviso.
             */
            $conflict = match (true) {
                $contract === null => null,
                ($exchange->contract_id !== null) && ((int) $exchange->contract_id !== (int) $contract->getKey()) => sprintf(
                    'A permuta vigente aponta para outro contrato (%s) e a unidade está ocupada pelo contrato %s.',
                    (string) $exchange->contract_id,
                    (string) $contract->code,
                ),
                ($exchange->contract_id === null) && ($contract->status !== ContractStatus::Exchanged) => sprintf(
                    'A permuta vigente não tem contrato vinculado e a unidade está ocupada pelo contrato %s, que não está marcado como permutado.',
                    (string) $contract->code,
                ),
                default => null,
            };

            if ($conflict !== null) {
                $issues[] = new SalesBoardIssue(
                    code: SalesBoardIssueCode::ExchangeOccupancyConflict,
                    message: $conflict,
                    constructionUnitId: $unitId,
                    contractId: (int) $contract->getKey(),
                    contractCode: $contract->code,
                );

                return $this->undeterminedLine($unit, $contract, $issues, $unitValues, $positionDate);
            }

            $issues = [...$issues, ...$this->exchangeLineIssues($exchange, $unitId, $positionDate)];

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
                        'A unidade está em estoque em %s e não tem valor de referência conhecido nessa data (ausente ou registrado como zero).',
                        $positionDate->format('d/m/Y'),
                    ),
                    constructionUnitId: $unitId,
                );
            } elseif (SalesBoardPlausibility::isBeforeMinimumYear($referenceValue->effectiveFromDate())) {
                $issues[] = new SalesBoardIssue(
                    code: SalesBoardIssueCode::SourceDateBefore1990,
                    message: sprintf(
                        'O valor de referência da unidade em estoque (%s) vale desde %s, data anterior a 1990: confira a vigência do valor.',
                        mb_strtolower((string) $referenceValue->source->label()),
                        self::displayDay($referenceValue->effectiveFromDate()),
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

        /**
         * Distratado sem a data do distrato: sem ela não há como saber desde
         * quando a unidade está livre, e o contrato continua ocupando pela regra
         * temporal. É ausência de dado -- a linha fica indeterminada em vez de
         * seguir classificada por uma ocupação que o status desmente.
         */
        if (($contract->status === ContractStatus::Cancelled) && ($contract->cancellation_date === null)) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::CancelledContractWithoutDate,
                message: sprintf(
                    'O contrato %s está distratado, mas não tem a data do distrato: o Quadro não sabe desde quando a unidade está livre.',
                    (string) $contract->code,
                ),
                constructionUnitId: $unitId,
                contractId: (int) $contract->getKey(),
                contractCode: $contract->code,
            );

            return $this->undeterminedLine($unit, $contract, $issues, $unitValues, $positionDate);
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
            exchangeValueCents: $exchange === null ? null : self::exchangeValueCents($exchange),
            exchangeEffectiveFrom: $exchange?->effective_from === null ? null : CarbonImmutable::parse($exchange->effective_from->toDateString()),
            exchangeEndedOn: $exchange?->ended_on === null ? null : CarbonImmutable::parse($exchange->ended_on->toDateString()),
            exchangeKind: $exchange?->kind,
            issues: array_values($issues),
        );
    }

    /**
     * O valor da permuta, ou `null` quando ela não tem valor.
     *
     * Zero é o marcador de "sem valor" da planilha e do cadastro, como no valor
     * de unidade: somado, publicaria o permutado a menor com cara de número
     * completo. Nulo, o balde fica indisponível e a posição, incompleta.
     */
    private static function exchangeValueCents(ConstructionUnitExchange $exchange): ?int
    {
        $cents = IntegerMoney::cents($exchange->exchange_value);

        return (($cents === null) || ($cents <= 0)) ? null : $cents;
    }

    /**
     * O que a permuta vigente da linha permutada tem de errado: valor ausente
     * (zero) ou início anterior a 1990. Os dois bloqueiam, e a linha continua
     * permutada -- a classificação está certa, o número dela é que não existe.
     *
     * @return list<SalesBoardIssue>
     */
    private function exchangeLineIssues(ConstructionUnitExchange $exchange, int $unitId, CarbonImmutable $positionDate): array
    {
        $issues = [];

        if (self::exchangeValueCents($exchange) === null) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::ExchangeValueMissing,
                message: sprintf(
                    'A permuta vigente em %s está registrada sem valor (R$ 0,00): permuta sem valor não compõe o Quadro de Vendas.',
                    $positionDate->format('d/m/Y'),
                ),
                constructionUnitId: $unitId,
                contractId: $exchange->contract_id === null ? null : (int) $exchange->contract_id,
            );
        }

        $start = $exchange->effective_from?->toDateString();

        if (SalesBoardPlausibility::isBeforeMinimumYear($start)) {
            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::SourceDateBefore1990,
                message: sprintf(
                    'A permuta vigente da unidade começa em %s, data anterior a 1990: confira o ano da vigência.',
                    self::displayDay($start),
                ),
                constructionUnitId: $unitId,
            );
        }

        return $issues;
    }

    /**
     * Um dia `Y-m-d` em `d/m/Y`, sem passar pelo `Carbon`: o ano "0026" que se
     * quer mostrar é exatamente o que um parse tolerante poderia reinterpretar.
     */
    private static function displayDay(?string $day): string
    {
        if (($day === null) || (preg_match('/^(\d{1,4})-(\d{1,2})-(\d{1,2})/', $day, $matches) !== 1)) {
            return (string) $day;
        }

        return sprintf('%02d/%02d/%04d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
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
     * As baixas das unidades que já tinham começado na data da posição -- as
     * únicas que podem valer nela ou no fim do mês anterior --, agrupadas por
     * unidade. A baixa que começa depois não é fonte desta competência.
     *
     * As unidades continuam todas carregadas: as baixadas seguem dando bloco e
     * unidade aos movimentos do mês, e o valor de referência às vendas
     * anteriores à baixa.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @return Collection<int, Collection<int, ConstructionUnitRetirement>>
     */
    private function loadRetirements(Collection $units, CarbonImmutable $positionDate): Collection
    {
        if ($units->isEmpty()) {
            return collect();
        }

        return ConstructionUnitRetirement::query()
            ->whereIn('construction_unit_id', $units->map(fn (ConstructionUnit $unit): int => (int) $unit->getKey())->all())
            ->where('retired_on', '<=', InclusiveDateBound::upperBound($positionDate))
            ->orderBy('retired_on')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ConstructionUnitRetirement $retirement): int => (int) $retirement->construction_unit_id);
    }

    /**
     * Contratos dos empreendimentos vendidos até a data da posição, e à parte os
     * que estão datados no futuro.
     *
     * Uma venda posterior à data não compõe a posição nem os movimentos do mês,
     * e um distrato posterior pertence a outra competência -- mas o contrato
     * dele precisa estar aqui, e está: o distrato é sempre posterior à venda.
     *
     * A carga é pelo empreendimento que o contrato aponta **e** pelas unidades
     * do empreendimento. `contracts.construction_id` é uma cópia do empreendimento
     * da unidade, refeita só quando o próprio contrato é salvo; uma unidade
     * trocada de empreendimento deixaria o contrato preso ao antigo, e carregar
     * só pela cópia transformaria em silêncio a unidade vendida em estoque no
     * novo e sumiria com o contrato no antigo. Com as duas chaves, o contrato
     * chega aos dois lados e a divergência vira achado (ver
     * {@see self::constructionMismatchIssues()}).
     *
     * A segunda parte são os contratos que seguram a unidade pelo status e têm
     * a data da venda depois de hoje e da data da posição: nenhuma competência
     * os conta como ocupantes ainda, e sem eles a unidade apareceria como
     * estoque sem nenhum aviso. Vêm na mesma consulta -- são raros e não mudam
     * o número de leituras.
     *
     * @param  list<int>  $constructionIds
     * @param  Collection<int, ConstructionUnit>  $units
     * @return array{0: Collection<int, Collection<int, Contract>>, 1: Collection<int, Collection<int, Contract>>}
     */
    private function loadContracts(array $constructionIds, Collection $units, CarbonImmutable $positionDate): array
    {
        $unitIds = $units->map(fn (ConstructionUnit $unit): int => (int) $unit->getKey())->values()->all();
        $today = CarbonImmutable::parse(BusinessTime::dateString());
        $futureBound = $today->greaterThan($positionDate) ? $today : $positionDate;

        $contracts = Contract::query()
            ->where(function (Builder $query) use ($constructionIds, $unitIds): void {
                $query->whereIn('construction_id', $constructionIds)
                    ->orWhereIn('construction_unit_id', $unitIds);
            })
            ->where(function (Builder $query) use ($positionDate, $futureBound): void {
                $query->where('sale_date', '<=', InclusiveDateBound::upperBound($positionDate))
                    ->orWhere(function (Builder $query) use ($futureBound): void {
                        $query->where('sale_date', '>', InclusiveDateBound::upperBound($futureBound))
                            ->whereIn('status', ContractStatus::occupyingValues());
                    });
            })
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get();

        $constructionOfUnit = $units
            ->mapWithKeys(fn (ConstructionUnit $unit): array => [(int) $unit->getKey() => (int) $unit->construction_id])
            ->all();

        [$sold, $futureDated] = $contracts->partition(
            fn (Contract $contract): bool => ($contract->sale_date !== null)
                && ($contract->sale_date->toDateString() <= $positionDate->toDateString()),
        );

        return [
            $this->groupByConstruction($sold, $constructionIds, $constructionOfUnit),
            $this->groupByConstruction($futureDated, $constructionIds, $constructionOfUnit),
        ];
    }

    /**
     * Um contrato pertence ao empreendimento que ele aponta e ao da unidade
     * dele. No caso normal os dois coincidem e ele cai num grupo só; quando
     * divergem, aparece nos dois.
     *
     * @param  Collection<int, Contract>  $contracts
     * @param  list<int>  $constructionIds
     * @param  array<int, int>  $constructionOfUnit
     * @return Collection<int, Collection<int, Contract>>
     */
    private function groupByConstruction(Collection $contracts, array $constructionIds, array $constructionOfUnit): Collection
    {
        $requested = array_flip($constructionIds);
        $groups = [];

        foreach ($contracts as $contract) {
            $owners = [];
            $pointedConstructionId = (int) $contract->construction_id;

            if (isset($requested[$pointedConstructionId])) {
                $owners[$pointedConstructionId] = true;
            }

            $unitConstructionId = $constructionOfUnit[(int) $contract->construction_unit_id] ?? null;

            if ($unitConstructionId !== null) {
                $owners[$unitConstructionId] = true;
            }

            foreach (array_keys($owners) as $owner) {
                $groups[$owner][] = $contract;
            }
        }

        return collect($groups)->map(fn (array $group): Collection => collect($group));
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
     * As vendas datadas na janela dos movimentos: o mês e, quando a âncora pula
     * competências canceladas, os meses delas.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return Collection<int, Contract>
     */
    private function salesInWindow(Collection $contracts, CarbonImmutable $windowStart, CarbonImmutable $positionDate): Collection
    {
        return $contracts->filter(function (Contract $contract) use ($windowStart, $positionDate): bool {
            $saleDate = $contract->sale_date?->toDateString();

            return ($saleDate !== null)
                && ($saleDate >= $windowStart->toDateString())
                && ($saleDate <= $positionDate->toDateString());
        })->values();
    }

    /**
     * As vendas da janela sem os contratos de permuta.
     *
     * Unidade permutada não é venda (ver {@see self::deriveLine()}), e o
     * contrato que formaliza a permuta também não: avaliá-lo contra a política
     * comercial produziria não conformidade -- ou bloqueio por falta de
     * política -- de algo que nunca teve preço de tabela. O reconhecimento é o
     * de {@see ExchangeContractRecognizer::isExchangeSale()}, o mesmo de todos
     * os leitores.
     *
     * @param  Collection<int, Contract>  $salesInWindow
     * @param  Collection<int, SalesBoardDerivedLine>  $linesByUnit
     * @param  Collection<int, Collection<int, ConstructionUnitExchange>>  $exchanges
     * @return Collection<int, Contract>
     */
    private function withoutExchangeContracts(Collection $salesInWindow, Collection $linesByUnit, Collection $exchanges): Collection
    {
        return $salesInWindow->reject(function (Contract $contract) use ($linesByUnit, $exchanges): bool {
            $unitId = (int) $contract->construction_unit_id;

            return ExchangeContractRecognizer::isExchangeSale($contract, $exchanges->get($unitId, collect()), $linesByUnit->get($unitId));
        })->values();
    }

    /**
     * O distrato do contrato de permuta não é distrato do mês.
     *
     * Desfazer a permuta distrata o contrato dela (a Gestão faz isso ao encerrar
     * a permuta), e contá-lo em "Distratos do mês" daria à unidade um distrato
     * sem a venda correspondente -- a venda do contrato de permuta já fica de
     * fora. A publicação só projeta baldes, então nenhum número publicado muda.
     *
     * @param  Collection<int, Collection<int, ConstructionUnitExchange>>  $exchanges
     */
    private function isExchangeContractCancellation(Contract $contract, Collection $exchanges): bool
    {
        return ExchangeContractRecognizer::isExchangeCancellation(
            $contract,
            $exchanges->get((int) $contract->construction_unit_id, collect()),
        );
    }

    /**
     * Valores de referência de que a derivação precisa, num carregamento só.
     *
     * Duas perguntas diferentes caem aqui: quanto cada unidade vale na data da
     * posição, e quanto valia a unidade de cada contrato vendido **na data
     * daquela venda** -- a conformidade das vendas da competência e a escala do
     * valor de todo contrato que ocupa uma linha. A segunda tem uma data por
     * venda, e é por isso que a resolução é por par (unidade, data) em vez de por
     * data única. O carregamento continua um só: as datas de venda nunca passam
     * da data da posição.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $soldContracts
     * @return array<string, ResolvedUnitValue>
     */
    private function resolveUnitValues(
        Collection $units,
        Collection $soldContracts,
        CarbonImmutable $positionDate,
    ): array {
        $requests = $units
            ->map(fn (ConstructionUnit $unit): array => [
                'unit_id' => (int) $unit->getKey(),
                'date' => $positionDate,
            ])
            ->values()
            ->all();

        foreach ($soldContracts as $contract) {
            if ($contract->sale_date === null) {
                continue;
            }

            $requests[] = [
                'unit_id' => (int) $contract->construction_unit_id,
                'date' => CarbonImmutable::parse($contract->sale_date->toDateString()),
            ];
        }

        /**
         * Valor zerado não é valor conhecido: na prática é o marcador de "sem
         * preço" de uma planilha. Tratado como valor, somaria zero ao estoque
         * e daria piso zero a toda venda -- conformidade e estoque inventados.
         * Aqui ele vira a ausência que é, e cai nos mesmos achados.
         */
        return array_map(
            static fn (ResolvedUnitValue $value): ResolvedUnitValue => ($value->valueCents === 0)
                ? ResolvedUnitValue::absent($value->constructionUnitId, $value->positionDate)
                : $value,
            $this->unitValueResolver->forUnitDates($units, $requests),
        );
    }

    /**
     * Política comercial vigente em cada par (empreendimento, data de venda).
     *
     * Uma consulta para a emissão inteira: as vendas da competência podem ter
     * datas diferentes e empreendimentos diferentes, e a política pode ter
     * mudado entre elas. As vendas extemporâneas e as revisões de venda entram
     * na mesma leitura, cada uma pela data dela.
     *
     * @param  Collection<int, Collection<int, Contract>>  $salesInWindowByConstruction
     * @param  array<int, SalesBoardLateFacts>  $lateFactsByConstruction
     * @return array<string, ResolvedSalesDiscountPolicy>
     */
    private function resolvePolicies(Collection $salesInWindowByConstruction, array $lateFactsByConstruction): array
    {
        $requests = [];

        $request = static function (int $constructionId, Contract $contract) use (&$requests): void {
            if ($contract->sale_date === null) {
                return;
            }

            $requests[] = [
                'construction_id' => $constructionId,
                'date' => CarbonImmutable::parse($contract->sale_date->toDateString()),
            ];
        };

        foreach ($salesInWindowByConstruction as $constructionId => $sales) {
            foreach ($sales as $contract) {
                $request((int) $constructionId, $contract);
            }
        }

        foreach ($lateFactsByConstruction as $constructionId => $lateFacts) {
            foreach ($lateFacts->sales as $sale) {
                $request((int) $constructionId, $sale['contract']);
            }
        }

        return $this->salesDiscountPolicyResolver->forConstructionDates($requests);
    }

    /**
     * Os movimentos da competência: os da janela -- o mês e os meses cancelados
     * antes dele -- e os fatos atrasados apurados contra a âncora.
     *
     * A janela vai do dia seguinte ao fim da âncora ao fim do mês; sem âncora,
     * do primeiro dia da cancelada mais antiga que esta competência absorve. Com
     * a âncora em M-1 (ou sem âncora e sem cancelada antes) ela é exatamente o
     * mês, e os movimentos são os de sempre, sem timing. O que cai antes do mês
     * -- só quando há competências canceladas absorvidas -- leva o timing "de
     * competência sem posição" e segue as regras do mês, inclusive os bloqueios
     * de venda. A exclusão do distrato de contrato de permuta vale para a
     * janela inteira.
     *
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, SalesBoardDerivedLine>  $linesByUnit
     * @param  Collection<int, Contract>  $salesInWindow
     * @param  Collection<int, Collection<int, ConstructionUnitExchange>>  $exchanges
     * @param  array<string, array<int, ContractSettlementSummary>>  $settlements
     * @param  array<string, ResolvedUnitValue>  $unitValues
     * @param  array<string, ResolvedSalesDiscountPolicy>  $policies
     */
    private function deriveMovements(
        Construction $construction,
        Collection $contracts,
        Collection $units,
        Collection $linesByUnit,
        Collection $salesInWindow,
        Collection $exchanges,
        array $settlements,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
        array $unitValues,
        array $policies,
        CarbonImmutable $windowStart,
        ?SalesBoardPriorPosition $anchor,
        SalesBoardLateFacts $lateFacts,
    ): SalesBoardMovements {
        $unitsById = $units->keyBy(fn (ConstructionUnit $unit): int => (int) $unit->getKey());
        $monthStart = $month->toDateString();
        $beforeWindow = $windowStart->subDay();

        $timingInWindow = static fn (?string $day): ?SalesBoardMovementTiming => (($day !== null) && ($day < $monthStart))
            ? SalesBoardMovementTiming::WithoutPosition
            : null;

        $sales = [
            ...$salesInWindow
                ->map(fn (Contract $contract): SalesBoardSaleMovement => $this->saleMovement(
                    $contract,
                    $timingInWindow($contract->sale_date?->toDateString()),
                    $unitsById,
                    $unitValues,
                    $policies,
                    $construction,
                ))
                ->all(),
            ...array_map(
                fn (array $sale): SalesBoardSaleMovement => $this->saleMovement(
                    $sale['contract'],
                    $sale['timing'],
                    $unitsById,
                    $unitValues,
                    $policies,
                    $construction,
                ),
                $lateFacts->sales,
            ),
        ];

        $cancellations = [
            ...$contracts
                ->filter(function (Contract $contract) use ($windowStart, $positionDate, $exchanges): bool {
                    $cancellationDate = $contract->cancellation_date?->toDateString();

                    return ($cancellationDate !== null)
                        && ($cancellationDate >= $windowStart->toDateString())
                        && ($cancellationDate <= $positionDate->toDateString())
                        && ! $this->isExchangeContractCancellation($contract, $exchanges);
                })
                ->map(fn (Contract $contract): SalesBoardCancellationMovement => $this->cancellationMovement(
                    $contract,
                    $timingInWindow($contract->cancellation_date?->toDateString()),
                    $unitsById,
                ))
                ->all(),
            ...array_map(
                fn (Contract $contract): SalesBoardCancellationMovement => $this->cancellationMovement(
                    $contract,
                    SalesBoardMovementTiming::Extemporaneous,
                    $unitsById,
                ),
                $lateFacts->cancellations,
            ),
        ];

        $settlementsAtClose = $settlements[$positionDate->toDateString()] ?? [];
        $settlementsBefore = $settlements[$beforeWindow->toDateString()] ?? [];
        $settlementsAtMonthEve = $settlements[$month->subDay()->toDateString()] ?? [];
        $settlementsAtAnchor = $anchor === null ? [] : ($settlements[$anchor->positionDate->toDateString()] ?? []);

        /**
         * Quitação é de quem continua com a unidade no fechamento. O distrato
         * costuma cancelar as parcelas em aberto, e o que sobra -- só as pagas
         * -- o resolvedor lê como quitado; sem a ocupação, a competência
         * congelaria ao mesmo tempo o distrato e uma quitação que não houve. O
         * contrato de uma linha permutada também fica de fora: permuta não é
         * venda, e a quitação dela não é quitação de venda.
         *
         * A quitação da janela é a do contrato quitado no fechamento e não
         * quitado na véspera da janela. A que já valia na véspera do mês -- só
         * possível com a janela estendida -- aconteceu numa competência
         * cancelada e leva o timing dela.
         */
        $settlementMovements = [
            ...$contracts
                ->filter(function (Contract $contract) use ($settlementsAtClose, $settlementsBefore, $linesByUnit, $positionDate): bool {
                    if (! ContractOccupancy::occupiesAt($contract, $positionDate)) {
                        return false;
                    }

                    if ($linesByUnit->get((int) $contract->construction_unit_id)?->classification === SalesBoardUnitClassification::Exchanged) {
                        return false;
                    }

                    $contractId = (int) $contract->getKey();
                    $closed = $settlementsAtClose[$contractId] ?? null;
                    $before = $settlementsBefore[$contractId] ?? null;

                    return ($closed !== null)
                        && $closed->isSettled()
                        && (($before === null) || ($before->state !== ContractSettlementState::Settled));
                })
                ->map(fn (Contract $contract): SalesBoardSettlementMovement => $this->settlementMovement(
                    $contract,
                    (($settlementsAtMonthEve[(int) $contract->getKey()] ?? null)?->isSettled() ?? false)
                        ? SalesBoardMovementTiming::WithoutPosition
                        : null,
                    $unitsById,
                    $settlementsAtClose,
                ))
                ->all(),
            ...array_map(
                fn (Contract $contract): SalesBoardSettlementMovement => $this->settlementMovement(
                    $contract,
                    SalesBoardMovementTiming::Extemporaneous,
                    $unitsById,
                    $settlementsAtAnchor,
                ),
                $lateFacts->settlements,
            ),
        ];

        return new SalesBoardMovements(
            sales: array_values($sales),
            settlements: array_values($settlementMovements),
            cancellations: array_values($cancellations),
        );
    }

    /**
     * Uma venda, com a conformidade da política e da tabela vigentes na data
     * dela -- a da janela, a extemporânea e a revisão passam pelo mesmo
     * avaliador.
     *
     * @param  Collection<int, ConstructionUnit>  $unitsById
     * @param  array<string, ResolvedUnitValue>  $unitValues
     * @param  array<string, ResolvedSalesDiscountPolicy>  $policies
     */
    private function saleMovement(
        Contract $contract,
        ?SalesBoardMovementTiming $timing,
        Collection $unitsById,
        array $unitValues,
        array $policies,
        Construction $construction,
    ): SalesBoardSaleMovement {
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
            timing: $timing,
        );
    }

    /**
     * @param  Collection<int, ConstructionUnit>  $unitsById
     */
    private function cancellationMovement(Contract $contract, ?SalesBoardMovementTiming $timing, Collection $unitsById): SalesBoardCancellationMovement
    {
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
            timing: $timing,
        );
    }

    /**
     * @param  Collection<int, ConstructionUnit>  $unitsById
     * @param  array<int, ContractSettlementSummary>  $summaries  o resumo da data em que a quitação é afirmada
     */
    private function settlementMovement(
        Contract $contract,
        ?SalesBoardMovementTiming $timing,
        Collection $unitsById,
        array $summaries,
    ): SalesBoardSettlementMovement {
        $unitId = (int) $contract->construction_unit_id;
        $unit = $unitsById->get($unitId);
        $summary = $summaries[(int) $contract->getKey()] ?? null;

        return new SalesBoardSettlementMovement(
            contractId: (int) $contract->getKey(),
            contractCode: $contract->code,
            constructionUnitId: $unitId,
            block: $unit?->block,
            unit: $unit?->unit,
            saleDate: $contract->sale_date === null ? null : CarbonImmutable::parse($contract->sale_date->toDateString()),
            saleValueCents: IntegerMoney::cents($contract->sale_value),
            installments: $summary?->validInstallments ?? 0,
            timing: $timing,
        );
    }

    /**
     * Os fatos atrasados: a fonte viva classificada na data da âncora, contra a
     * linha que a âncora congelou -- por unidade e por contrato, pela mesma regra
     * de toda linha ({@see self::classifyAt()}). Não há regra nova de
     * classificação.
     *
     * - contrato que ocupa a unidade no fim da âncora, que não é contrato de
     *   permuta e que a âncora não conhecia: venda extemporânea, com a
     *   conformidade da data da venda; já quitado ali, também quitação
     *   extemporânea. A detecção é por contrato, e não por balde, para que a
     *   venda abaixo do piso não escape quando a unidade fica ambígua na data;
     * - contrato da linha financiada ou quitada que não ocupa mais a unidade no
     *   fim da âncora: distrato extemporâneo, se tem data de distrato até ali e
     *   não é distrato de contrato de permuta; sem isso, reclassificação sem
     *   movimento;
     * - o mesmo contrato, financiado na âncora e quitado agora na data dela:
     *   quitação extemporânea. Quitado lá e não mais aqui (estorno):
     *   reclassificação sem movimento. Valor ou data da venda diferentes dos
     *   congelados: revisão de venda publicada, com a conformidade reavaliada;
     * - linha permutada nunca gera venda, distrato nem revisão.
     *
     * O que muda a unidade sem fato datado -- estorno, data de venda movida,
     * permuta, inclusão ou saída do inventário -- vira o aviso
     * UNEXPLAINED_RECLASSIFICATION, menos a presença explicada por baixa ou
     * reativação, que já tem aviso próprio.
     *
     * Não há colisão com a unique (versão, tipo, contrato): um contrato tem uma
     * data de venda e uma de distrato, a quitação extemporânea exige o contrato
     * quitado no fim da âncora, e a da janela, não quitado ali.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, Collection<int, ConstructionUnitExchange>>  $exchangesByUnit
     * @param  Collection<int, Collection<int, ConstructionUnitRetirement>>  $retirementsByUnit
     * @param  array<int, ContractSettlementSummary>  $settlementsAtAnchor
     */
    private function lateFacts(
        Construction $construction,
        SalesBoardPriorPosition $anchor,
        Collection $units,
        Collection $contracts,
        Collection $exchangesByUnit,
        Collection $retirementsByUnit,
        array $settlementsAtAnchor,
    ): SalesBoardLateFacts {
        $anchorDate = $anchor->positionDate;
        $occupancyAtAnchor = $this->occupancyByUnit($contracts, $anchorDate);
        $contractsById = $contracts->keyBy(fn (Contract $contract): int => (int) $contract->getKey());

        $sales = [];
        $cancellations = [];
        $settlements = [];
        $issues = [];
        $inventory = [];

        foreach ($units as $unit) {
            $unitId = (int) $unit->getKey();
            $inventory[$unitId] = true;

            $prior = $anchor->line($unitId);
            $exchanges = $exchangesByUnit->get($unitId, collect());
            $retirements = $retirementsByUnit->get($unitId, collect());
            $occupying = $occupancyAtAnchor[$unitId] ?? collect();

            $live = $this->classifyAt($unit, $construction, $anchorDate, $occupying, $exchanges, $retirements, $settlementsAtAnchor);
            $liveExchanged = $live?->classification === SalesBoardUnitClassification::Exchanged;
            $expected = $prior?->classification;

            if (($prior !== null) && $prior->holdsSale()) {
                $priorContract = $contractsById->get((int) $prior->contractId);
                $stillOccupies = ($priorContract !== null)
                    && ((int) $priorContract->construction_unit_id === $unitId)
                    && ContractOccupancy::occupiesAt($priorContract, $anchorDate);

                if ($stillOccupies && ! $liveExchanged) {
                    if (self::saleWasRevised($priorContract, $prior)) {
                        $sales[] = ['contract' => $priorContract, 'timing' => SalesBoardMovementTiming::SaleRevision];
                    }

                    if (($prior->classification === SalesBoardUnitClassification::Financed)
                        && ($live?->contractId === $prior->contractId)
                        && ($live->classification === SalesBoardUnitClassification::Settled)) {
                        $settlements[] = $priorContract;
                        $expected = SalesBoardUnitClassification::Settled;
                    }
                } elseif (! $stillOccupies
                    && ($priorContract !== null)
                    && ($priorContract->cancellation_date !== null)
                    && ($priorContract->cancellation_date->toDateString() <= $anchorDate->toDateString())
                    && ! ExchangeContractRecognizer::isExchangeCancellation($priorContract, $exchanges)) {
                    $cancellations[] = $priorContract;
                    $expected = SalesBoardUnitClassification::Stock;
                }
            }

            if (! $liveExchanged) {
                foreach ($occupying as $contract) {
                    $contractId = (int) $contract->getKey();

                    if (($prior?->contractId === $contractId)
                        || ExchangeContractRecognizer::isExchangeSale($contract, $exchanges, $live)) {
                        continue;
                    }

                    $sales[] = ['contract' => $contract, 'timing' => SalesBoardMovementTiming::Extemporaneous];

                    $settledAtAnchor = ($settlementsAtAnchor[$contractId] ?? null)?->isSettled() ?? false;

                    if ($settledAtAnchor) {
                        $settlements[] = $contract;
                    }

                    $expected = $settledAtAnchor ? SalesBoardUnitClassification::Settled : SalesBoardUnitClassification::Financed;
                }
            }

            $issue = $this->unexplainedReclassification($unit, $prior, $live, $expected, $retirements, $anchor);

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        foreach ($anchor->lines as $unitId => $prior) {
            if (isset($inventory[$unitId])) {
                continue;
            }

            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::UnexplainedReclassification,
                message: sprintf(
                    'A unidade %s constava da posição de %s (%s) e não está mais no inventário do empreendimento.',
                    $prior->displayName() === '' ? '#'.$unitId : $prior->displayName(),
                    $anchor->label(),
                    mb_strtolower($prior->classification->label()),
                ),
                constructionUnitId: $unitId,
                contractId: $prior->contractId,
                contractCode: $prior->contractCode,
            );
        }

        return new SalesBoardLateFacts($sales, $cancellations, $settlements, $issues);
    }

    /**
     * A classificação viva de uma unidade numa data passada -- o fim da âncora
     * --, pela regra de toda linha ({@see self::classifyUnitAt()}, que já
     * respeita a baixa), sobre as fontes já carregadas. Sem valores de
     * referência: o que se compara com a posição congelada é a classificação e
     * o contrato, e os achados da linha ficam de fora -- quem responde por eles
     * é a competência daquela data.
     *
     * @param  Collection<int, Contract>  $occupying
     * @param  Collection<int, ConstructionUnitExchange>  $exchanges
     * @param  Collection<int, ConstructionUnitRetirement>  $retirements
     * @param  array<int, ContractSettlementSummary>  $settlements
     */
    private function classifyAt(
        ConstructionUnit $unit,
        Construction $construction,
        CarbonImmutable $date,
        Collection $occupying,
        Collection $exchanges,
        Collection $retirements,
        array $settlements,
    ): ?SalesBoardDerivedLine {
        return $this->classifyUnitAt(
            unit: $unit,
            construction: $construction,
            date: $date,
            occupying: $occupying,
            exchanges: $exchanges,
            retirements: $retirements,
            settlements: $settlements,
            unitValues: [],
        );
    }

    /**
     * O valor ou a data da venda mudaram desde que a âncora a congelou.
     */
    private static function saleWasRevised(Contract $contract, SalesBoardPriorLine $prior): bool
    {
        return (IntegerMoney::cents($contract->sale_value) !== $prior->saleValueCents)
            || ($contract->sale_date?->toDateString() !== $prior->saleDate);
    }

    /**
     * A unidade que, na data da âncora, não é o que a âncora congelou -- depois
     * de aplicados os fatos atrasados que explicam a diferença.
     *
     * A presença que muda pela baixa não entra: a unidade baixada e livre não
     * tem linha (e a baixa tem aviso próprio), e a que voltou ao inventário pela
     * reativação também não. A inclusão de unidade depois da âncora entra -- não
     * há fato datado que a explique.
     *
     * @param  Collection<int, ConstructionUnitRetirement>  $retirements
     */
    private function unexplainedReclassification(
        ConstructionUnit $unit,
        ?SalesBoardPriorLine $prior,
        ?SalesBoardDerivedLine $live,
        ?SalesBoardUnitClassification $expected,
        Collection $retirements,
        SalesBoardPriorPosition $anchor,
    ): ?SalesBoardIssue {
        $label = trim(sprintf('%s / %s', (string) $unit->block, (string) $unit->unit), ' /');

        if (($prior === null) && ($live === null)) {
            return null;
        }

        if ($prior === null) {
            $touchedByRetirement = $retirements->contains(
                fn (ConstructionUnitRetirement $retirement): bool => $retirement->retired_on->toDateString() <= $anchor->positionDate->toDateString(),
            );

            return $touchedByRetirement ? null : new SalesBoardIssue(
                code: SalesBoardIssueCode::UnexplainedReclassification,
                message: sprintf(
                    'A unidade %s não constava da posição de %s: foi incluída no inventário depois dela, e nessa data seria %s.',
                    $label,
                    $anchor->label(),
                    mb_strtolower($live->classification->label()),
                ),
                constructionUnitId: (int) $unit->getKey(),
                contractId: $live->contractId,
                contractCode: $live->contractCode,
            );
        }

        if (($live === null) || ($expected === $live->classification)) {
            return null;
        }

        return new SalesBoardIssue(
            code: SalesBoardIssueCode::UnexplainedReclassification,
            message: sprintf(
                'A unidade %s estava como %s na posição de %s e hoje seria %s nessa mesma data, sem venda, distrato ou quitação datados que expliquem a diferença.',
                $label,
                mb_strtolower($prior->classification->label()),
                $anchor->label(),
                mb_strtolower($live->classification->label()),
            ),
            constructionUnitId: (int) $unit->getKey(),
            contractId: $live->contractId ?? $prior->contractId,
            contractCode: $live->contractCode ?? $prior->contractCode,
        );
    }

    /**
     * Achados das vendas da competência.
     *
     * Uma venda fora da política é aviso, não bloqueador: ela foi apurada
     * justamente porque a derivação funcionou. Já uma venda que não pôde ser
     * avaliada por falta de valor ou de política é ausência de dado, e essa
     * bloqueia -- como a referência que a avaliou com vigência anterior a 1990,
     * que é dado errado com cara de dado.
     *
     * Vale para as vendas do mês e para as de competência sem posição, que
     * seguem as regras do mês. A venda extemporânea e a revisão de venda
     * publicada têm achados próprios ({@see self::lateSaleIssue()}), que só
     * avisam: a pendência da Gestão é que segura a publicação.
     *
     * @return list<SalesBoardIssue>
     */
    private function movementIssues(SalesBoardMovements $movements): array
    {
        $issues = [];

        foreach ($movements->sales as $sale) {
            if ($sale->isLate()) {
                $issue = $this->lateSaleIssue($sale);

                if ($issue !== null) {
                    $issues[] = $issue;
                }

                continue;
            }

            $referenceFrom = $sale->unitReferenceEffectiveFrom?->toDateString();

            if (SalesBoardPlausibility::isBeforeMinimumYear($referenceFrom)) {
                $issues[] = new SalesBoardIssue(
                    code: SalesBoardIssueCode::SourceDateBefore1990,
                    message: sprintf(
                        'O valor de referência usado na venda %s vale desde %s, data anterior a 1990: confira a vigência do valor da unidade.',
                        (string) $sale->contractCode,
                        self::displayDay($referenceFrom),
                    ),
                    constructionUnitId: $sale->constructionUnitId,
                    contractId: $sale->contractId,
                    contractCode: $sale->contractCode,
                );
            }

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
     * O achado de uma venda extemporânea ou revista: aviso, nunca bloqueio.
     *
     * Fora da política, LATE_SALE_NON_CONFORM; sem conformidade determinável --
     * ou avaliada contra uma referência com vigência anterior a 1990, que não
     * sustenta veredito --, LATE_SALE_UNDETERMINED. A pendência da Gestão é
     * materializada pelo movimento congelado, como a da venda do mês.
     */
    private function lateSaleIssue(SalesBoardSaleMovement $sale): ?SalesBoardIssue
    {
        $kind = $sale->timing === SalesBoardMovementTiming::SaleRevision
            ? 'A venda publicada %s, revista depois da publicação,'
            : 'A venda extemporânea %s, de %s,';
        $subject = sprintf($kind, (string) $sale->contractCode, $sale->saleDate->format('d/m/Y'));
        $referenceFrom = $sale->unitReferenceEffectiveFrom?->toDateString();

        $message = match (true) {
            $sale->conformity->status === SalesPriceConformityStatus::NonConform => sprintf(
                '%s ficou abaixo do preço mínimo autorizado na data da venda (%s contra %s).',
                $subject,
                IntegerMoney::format((int) $sale->conformity->saleValueCents),
                IntegerMoney::format((int) $sale->conformity->minimumAuthorizedValueCents),
            ),
            $sale->conformity->status === SalesPriceConformityStatus::Undetermined => sprintf(
                '%s não pôde ser avaliada: %s',
                $subject,
                (string) $sale->conformity->reasonWhenUndetermined,
            ),
            SalesBoardPlausibility::isBeforeMinimumYear($referenceFrom) => sprintf(
                '%s foi avaliada contra um valor de referência que vale desde %s, data anterior a 1990: confira a vigência do valor da unidade.',
                $subject,
                self::displayDay($referenceFrom),
            ),
            default => null,
        };

        if ($message === null) {
            return null;
        }

        return new SalesBoardIssue(
            code: $sale->conformity->status === SalesPriceConformityStatus::NonConform
                ? SalesBoardIssueCode::LateSaleNonConform
                : SalesBoardIssueCode::LateSaleUndetermined,
            message: $message,
            constructionUnitId: $sale->constructionUnitId,
            contractId: $sale->contractId,
            contractCode: $sale->contractCode,
        );
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

    /**
     * Contrato que aponta para um empreendimento e cuja unidade está em outro.
     *
     * A linha da unidade já denuncia o ocupante divergente (ver
     * {@see self::deriveLine()}). O que sobra aparece aqui: do lado do
     * empreendimento para o qual o contrato aponta, a unidade não existe e o
     * contrato sumiria das linhas sem aviso; do lado da unidade, um contrato que
     * não a ocupa no fechamento mas vendeu ou distratou dentro do mês mexeria
     * nos movimentos sem ninguém ver. Só entra o contrato que pesa nesta
     * competência: ocupante na data da posição, ou com venda ou distrato no mês.
     *
     * "A unidade é deste empreendimento" vem do cadastro -- todas as unidades,
     * inclusive as baixadas --, e não da existência de linha: a unidade baixada
     * não tem linha, e a venda e o distrato dela anteriores à baixa, no mesmo
     * mês, seriam acusados como de outro empreendimento.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, SalesBoardDerivedLine>  $linesByUnit
     * @return list<SalesBoardIssue>
     */
    private function constructionMismatchIssues(
        Construction $construction,
        Collection $units,
        Collection $contracts,
        Collection $linesByUnit,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
    ): array {
        $constructionId = (int) $construction->getKey();
        $unitIdsHere = $units->mapWithKeys(fn (ConstructionUnit $unit): array => [(int) $unit->getKey() => true])->all();
        $issues = [];

        foreach ($contracts as $contract) {
            $contractId = (int) $contract->getKey();
            $unitId = (int) $contract->construction_unit_id;
            $line = $linesByUnit->get($unitId);

            $pointsHere = (int) $contract->construction_id === $constructionId;
            $unitIsHere = isset($unitIdsHere[$unitId]);

            if ($pointsHere === $unitIsHere) {
                continue;
            }

            if ($line?->contractId === $contractId) {
                continue;
            }

            if (! $this->weighsOnCompetence($contract, $month, $positionDate)) {
                continue;
            }

            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::UnitConstructionMismatch,
                message: $pointsHere
                    ? sprintf('O contrato %s aponta para este empreendimento, mas a unidade dele está cadastrada em outro.', (string) $contract->code)
                    : sprintf('O contrato %s é de uma unidade deste empreendimento, mas aponta para outro empreendimento.', (string) $contract->code),
                constructionUnitId: $unitId,
                contractId: $contractId,
                contractCode: $contract->code,
            );
        }

        return $issues;
    }

    private function weighsOnCompetence(Contract $contract, CarbonImmutable $month, CarbonImmutable $positionDate): bool
    {
        if (ContractOccupancy::occupiesAt($contract, $positionDate)) {
            return true;
        }

        $within = static fn (?string $date): bool => ($date !== null)
            && ($date >= $month->toDateString())
            && ($date <= $positionDate->toDateString());

        return $within($contract->sale_date?->toDateString())
            || $within($contract->cancellation_date?->toDateString());
    }

    /**
     * Status do contrato que contradiz a quitação apurada pelo cronograma.
     *
     * Aviso, nunca bloqueio, e nunca muda a classificação: o Quadro segue as
     * parcelas. Mas "quitado" na fonte com o contrato financiado no Quadro é o
     * rastro de uma quitação antecipada com desconto que não foi registrado na
     * parcela (pago abaixo do previsto), de uma renegociação cujas parcelas
     * antigas nunca foram canceladas, ou de um distrato ainda não lançado, e sem
     * o aviso o contrato migraria de balde em silêncio.
     *
     * Os dois sentidos, com a assimetria que o status impõe:
     *
     * - marcado como quitado, financiado no fechamento, e nenhum pagamento
     *   registrado depois completa a quitação. Um contrato quitado em agosto
     *   continua financiado em julho sem nada de errado -- por isso a pergunta
     *   vai também ao cronograma inteiro;
     * - marcado como ativo e quitado no fechamento. Quitação apurada numa data
     *   não se desfaz depois, então o status está atrasado.
     *
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, SalesBoardDerivedLine>  $linesByUnit
     * @param  array<string, array<int, ContractSettlementSummary>>  $settlements
     * @return list<SalesBoardIssue>
     */
    private function settlementStatusIssues(Collection $contracts, Collection $linesByUnit, array $settlements, CarbonImmutable $positionDate): array
    {
        $contractsById = $contracts->keyBy(fn (Contract $contract): int => (int) $contract->getKey());
        $horizon = $settlements[self::SCHEDULE_HORIZON] ?? [];
        $issues = [];

        foreach ($linesByUnit as $line) {
            $contract = $line->contractId === null ? null : $contractsById->get($line->contractId);

            if ($contract === null) {
                continue;
            }

            $message = match (true) {
                ($line->classification === SalesBoardUnitClassification::Financed)
                    && ($contract->status === ContractStatus::Settled)
                    && ! (($horizon[$line->contractId] ?? null)?->isSettled() ?? false) => sprintf(
                        'O contrato %s está marcado como quitado, mas o cronograma de parcelas não o quita em %s (%d de %d parcelas válidas pagas) nem com os pagamentos registrados depois.',
                        (string) $contract->code,
                        $positionDate->format('d/m/Y'),
                        (int) $line->settlementInstallmentsPaid,
                        (int) $line->settlementInstallmentsTotal,
                    ),
                ($line->classification === SalesBoardUnitClassification::Settled)
                    && ($contract->status === ContractStatus::Active) => sprintf(
                        'O contrato %s está marcado como ativo, mas o cronograma de parcelas o dá como quitado em %s (%d de %d parcelas pagas).',
                        (string) $contract->code,
                        $positionDate->format('d/m/Y'),
                        (int) $line->settlementInstallmentsPaid,
                        (int) $line->settlementInstallmentsTotal,
                    ),
                default => null,
            };

            if ($message === null) {
                continue;
            }

            $issues[] = new SalesBoardIssue(
                code: SalesBoardIssueCode::SettlementStatusDivergence,
                message: $message,
                constructionUnitId: $line->constructionUnitId,
                contractId: (int) $contract->getKey(),
                contractCode: $contract->code,
            );
        }

        return $issues;
    }

    /**
     * Contrato que segura a unidade pelo status com a data da venda no futuro.
     *
     * Nenhuma competência o conta como ocupante até a data chegar, então a
     * unidade aparece como estoque enquanto o status diz que ela tem dono -- o
     * rastro típico de um ano digitado errado na carga. Aviso, porque o número
     * do Quadro é coerente com o dado gravado; o que está errado é o dado.
     *
     * @param  Collection<int, Contract>  $futureDated
     * @return list<SalesBoardIssue>
     */
    private function futureSaleIssues(Collection $futureDated): array
    {
        return $futureDated
            ->map(fn (Contract $contract): SalesBoardIssue => new SalesBoardIssue(
                code: SalesBoardIssueCode::FutureSaleDate,
                message: sprintf(
                    'O contrato %s está como %s, mas a data da venda (%s) é futura: até lá o Quadro não o conta como ocupante da unidade.',
                    (string) $contract->code,
                    mb_strtolower($contract->status->label()),
                    $contract->sale_date->format('d/m/Y'),
                ),
                constructionUnitId: (int) $contract->construction_unit_id,
                contractId: (int) $contract->getKey(),
                contractCode: $contract->code,
            ))
            ->values()
            ->all();
    }

    /**
     * Valor da venda fora da escala do valor de referência da unidade, e a venda
     * da competência bem acima dele.
     *
     * A escala vale para o contrato de toda linha financiada, quitada ou
     * indeterminada e para as vendas da competência: um valor a uma ordem de
     * grandeza da tabela (×10, ÷10, o ×1000 do parser antigo, o placeholder de
     * R$ 1,00 na referência) não é preço, é dado lido ou digitado errado, e
     * publicá-lo como posição completa seria pior que não publicar. A referência
     * é a da data da venda e, na falta dela, a da data da posição; sem
     * referência, ou com referência zero, nada é avaliado. A linha permutada fica
     * de fora -- o contrato de permuta não tem preço de tabela.
     *
     * O ágio duvidoso (o dobro da tabela ou mais) só avisa, e só para a venda da
     * competência, contra a tabela da data da venda: o lado do desconto já é a
     * conformidade com a política, e uma venda antiga já foi avaliada no mês
     * dela. Um contrato aparece no máximo uma vez em cada código.
     *
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, SalesBoardDerivedLine>  $linesByUnit
     * @param  array<string, ResolvedUnitValue>  $unitValues
     * @return list<SalesBoardIssue>
     */
    private function saleScaleIssues(
        Collection $contracts,
        Collection $linesByUnit,
        SalesBoardMovements $movements,
        array $unitValues,
        CarbonImmutable $positionDate,
    ): array {
        $contractsById = $contracts->keyBy(fn (Contract $contract): int => (int) $contract->getKey());
        $candidates = [];

        foreach ($linesByUnit as $line) {
            if (($line->contractId !== null) && ($line->classification !== SalesBoardUnitClassification::Exchanged)) {
                $candidates[$line->contractId] ??= $line->constructionUnitId;
            }
        }

        /**
         * A venda extemporânea e a revisão não entram como movimento: o achado
         * delas é aviso ({@see self::lateSaleIssue()}). O contrato delas que
         * ainda ocupa a unidade continua conferido como o de toda linha.
         */
        foreach ($movements->sales as $sale) {
            if (! $sale->isLate()) {
                $candidates[$sale->contractId] ??= $sale->constructionUnitId;
            }
        }

        $issues = [];
        $outOfScale = [];

        foreach ($candidates as $contractId => $unitId) {
            $contract = $contractsById->get($contractId);
            $issue = $contract === null ? null : $this->saleOutOfScaleIssue($contract, $unitId, $unitValues, $positionDate);

            if ($issue !== null) {
                $issues[] = $issue;
                $outOfScale[$contractId] = true;
            }
        }

        foreach ($movements->sales as $sale) {
            if (isset($outOfScale[$sale->contractId]) || $sale->isLate()) {
                continue;
            }

            $issue = $this->atypicalSaleIssue($sale);

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, ResolvedUnitValue>  $unitValues
     */
    private function saleOutOfScaleIssue(Contract $contract, int $unitId, array $unitValues, CarbonImmutable $positionDate): ?SalesBoardIssue
    {
        $saleCents = IntegerMoney::cents($contract->sale_value);
        $reference = $this->referenceForSale($unitId, $contract->sale_date?->toDateString(), $unitValues, $positionDate);

        if (($saleCents === null) || ($reference === null) || ! SalesBoardPlausibility::isOutOfScale($saleCents, (int) $reference->valueCents)) {
            return null;
        }

        return new SalesBoardIssue(
            code: SalesBoardIssueCode::SaleValueOutOfScale,
            message: sprintf(
                'O valor da venda do contrato %s (R$ %s) é %s valor de referência da unidade em %s (R$ %s): um dos dois foi lido ou digitado errado.',
                (string) $contract->code,
                IntegerMoney::format($saleCents),
                SalesBoardPlausibility::ratioLabel($saleCents, (int) $reference->valueCents),
                $reference->positionDate->format('d/m/Y'),
                IntegerMoney::format((int) $reference->valueCents),
            ),
            constructionUnitId: $unitId,
            contractId: (int) $contract->getKey(),
            contractCode: $contract->code,
        );
    }

    private function atypicalSaleIssue(SalesBoardSaleMovement $sale): ?SalesBoardIssue
    {
        $reference = $sale->unitReferenceValueCents;
        $saleCents = $sale->saleValueCents;

        if (($reference === null) || ($reference <= 0) || ($saleCents === null)
            || ! SalesBoardPlausibility::isAtypicalPremium($saleCents, $reference)
            || SalesBoardPlausibility::isOutOfScale($saleCents, $reference)) {
            return null;
        }

        return new SalesBoardIssue(
            code: SalesBoardIssueCode::SaleValueAtypical,
            message: sprintf(
                'A venda %s saiu por R$ %s, %s valor de referência da unidade na data da venda (R$ %s). Confira se o valor da venda e o da tabela estão certos.',
                (string) $sale->contractCode,
                IntegerMoney::format($saleCents),
                SalesBoardPlausibility::ratioLabel($saleCents, $reference),
                IntegerMoney::format($reference),
            ),
            constructionUnitId: $sale->constructionUnitId,
            contractId: $sale->contractId,
            contractCode: $sale->contractCode,
        );
    }

    /**
     * O valor de referência contra o qual a venda é medida: o da data da venda,
     * e na falta dele o da data da posição. Zero não é referência. A escolha é a
     * de {@see SalesBoardPlausibility::saleScaleReference()}, a mesma que a
     * importação de contratos usa para recusar a venda fora de escala.
     *
     * @param  array<string, ResolvedUnitValue>  $unitValues
     */
    private function referenceForSale(int $unitId, ?string $saleDay, array $unitValues, CarbonImmutable $positionDate): ?ResolvedUnitValue
    {
        return SalesBoardPlausibility::saleScaleReference(
            $saleDay === null ? null : ($unitValues[$unitId.'@'.$saleDay] ?? null),
            $unitValues[$unitId.'@'.$positionDate->toDateString()] ?? null,
        );
    }

    /**
     * O que o cronograma dos contratos que compõem a posição tem de implausível
     * ou de decisivo.
     *
     * Só os contratos das linhas financiadas, quitadas e indeterminadas -- os que
     * decidem a posição. Com a mesma leitura das parcelas que decidiu a quitação,
     * sem consulta a mais:
     *
     * - o pagamento abaixo do previsto que segura a quitação (aviso): toda
     *   parcela válida já teve pagamento e alguma ficou abaixo do previsto sem
     *   desconto registrado. É o "financiado para sempre" de quem quitou com
     *   desconto, e a saída é registrar o desconto;
     * - parcela a dez vezes o valor da venda ou mais, prevista ou paga (bloqueio),
     *   ou a duas vezes ou mais, ou paga com cem vezes o previsto (aviso): o
     *   ×1000 de uma leitura errada não é parcela;
     * - pagamento ou cancelamento de parcela anterior a 1990 (bloqueio): decidem
     *   validade e quitação, e o ano está errado. O vencimento fica de fora,
     *   porque não decide nada;
     * - pagamento com data depois de hoje no calendário de negócio (aviso).
     *
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, SalesBoardDerivedLine>  $linesByUnit
     * @param  array<string, array<int, ContractSettlementSummary>>  $settlements
     * @param  array<int, ContractScheduleFacts>  $scheduleFacts
     * @return list<SalesBoardIssue>
     */
    private function scheduleIssues(
        Collection $contracts,
        Collection $linesByUnit,
        array $settlements,
        array $scheduleFacts,
        CarbonImmutable $positionDate,
    ): array {
        $contractsById = $contracts->keyBy(fn (Contract $contract): int => (int) $contract->getKey());
        $atClose = $settlements[$positionDate->toDateString()] ?? [];
        $relevant = [
            SalesBoardUnitClassification::Financed,
            SalesBoardUnitClassification::Settled,
            SalesBoardUnitClassification::Undetermined,
        ];
        $issues = [];
        $seen = [];

        foreach ($linesByUnit as $line) {
            if (($line->contractId === null) || isset($seen[$line->contractId]) || ! in_array($line->classification, $relevant, true)) {
                continue;
            }

            $contract = $contractsById->get($line->contractId);

            if ($contract === null) {
                continue;
            }

            $seen[$line->contractId] = true;
            $summary = $atClose[$line->contractId] ?? null;

            if (($summary !== null) && ($line->classification === SalesBoardUnitClassification::Financed) && $summary->isHeldOnlyByShortfall()) {
                $issues[] = $this->contractIssue(
                    SalesBoardIssueCode::UnderpaidInstallments,
                    sprintf(
                        'O contrato %s tem %d parcela(s) paga(s) abaixo do previsto até %s, R$ %s a menos no total, e por isso continua financiado. Se houve desconto na baixa, registre-o nas parcelas.',
                        (string) $contract->code,
                        $summary->underpaidInstallments(),
                        $positionDate->format('d/m/Y'),
                        IntegerMoney::format($summary->shortfallCents),
                    ),
                    $contract,
                    $line->constructionUnitId,
                );
            }

            $valueIssue = $summary === null ? null : $this->installmentValueIssue($contract, $summary, $line->constructionUnitId);

            if ($valueIssue !== null) {
                $issues[] = $valueIssue;
            }

            $facts = $scheduleFacts[$line->contractId] ?? null;

            if ($facts?->hasDatesBefore1990() ?? false) {
                $issues[] = $this->contractIssue(
                    SalesBoardIssueCode::SourceDateBefore1990,
                    sprintf(
                        'A parcela %s do contrato %s tem data de %s em %s, anterior a 1990%s: confira o ano.',
                        (string) $facts->firstDateBefore1990Number,
                        (string) $contract->code,
                        $facts->firstDateBefore1990Field === 'cancellation' ? 'cancelamento' : 'pagamento',
                        self::displayDay($facts->firstDateBefore1990),
                        $facts->datesBefore1990 > 1 ? sprintf(' (%d datas assim no cronograma)', $facts->datesBefore1990) : '',
                    ),
                    $contract,
                    $line->constructionUnitId,
                );
            }

            if ($facts?->hasPaymentsAfterToday() ?? false) {
                $issues[] = $this->contractIssue(
                    SalesBoardIssueCode::PaymentDateInFuture,
                    sprintf(
                        'A parcela %s do contrato %s está com pagamento em %s, data que ainda não chegou%s: um recebimento só é registrado depois de acontecer.',
                        (string) $facts->firstPaymentAfterTodayNumber,
                        (string) $contract->code,
                        self::displayDay($facts->firstPaymentAfterToday),
                        $facts->paymentsAfterToday > 1 ? sprintf(' (%d parcelas assim)', $facts->paymentsAfterToday) : '',
                    ),
                    $contract,
                    $line->constructionUnitId,
                );
            }
        }

        return $issues;
    }

    /**
     * A parcela válida fora de escala diante do valor da venda (bloqueio) ou
     * atípica (aviso). No máximo um achado por contrato: o mais grave, com a
     * parcela que o explica.
     */
    private function installmentValueIssue(Contract $contract, ContractSettlementSummary $summary, int $unitId): ?SalesBoardIssue
    {
        $saleCents = IntegerMoney::cents($contract->sale_value);

        if (($saleCents === null) || ($saleCents <= 0)) {
            return null;
        }

        $expected = $summary->maxExpectedCents;
        $paid = $summary->maxPaidCents;

        $describe = fn (string $number, string $what, int $cents): string => sprintf(
            'A parcela %s do contrato %s %s R$ %s, %s valor da venda (R$ %s)',
            $number,
            (string) $contract->code,
            $what,
            IntegerMoney::format($cents),
            SalesBoardPlausibility::ratioLabel($cents, $saleCents),
            IntegerMoney::format($saleCents),
        );

        if (($expected !== null) && SalesBoardPlausibility::exceedsScaleOf($expected, $saleCents)) {
            return $this->contractIssue(
                SalesBoardIssueCode::InstallmentValueOutOfScale,
                $describe((string) $summary->maxExpectedNumber, 'tem valor previsto de', $expected).': confira a leitura do valor.',
                $contract,
                $unitId,
            );
        }

        if (($paid !== null) && SalesBoardPlausibility::exceedsScaleOf($paid, $saleCents)) {
            return $this->contractIssue(
                SalesBoardIssueCode::InstallmentValueOutOfScale,
                $describe((string) $summary->maxPaidNumber, 'foi paga com', $paid).': confira a leitura do valor.',
                $contract,
                $unitId,
            );
        }

        $atypical = match (true) {
            ($expected !== null) && SalesBoardPlausibility::isAtLeastTwiceOf($expected, $saleCents) => $describe((string) $summary->maxExpectedNumber, 'tem valor previsto de', $expected).'.',
            ($paid !== null) && SalesBoardPlausibility::isAtLeastTwiceOf($paid, $saleCents) => $describe((string) $summary->maxPaidNumber, 'foi paga com', $paid).'.',
            $summary->paidFarAboveExpectedNumber !== null => sprintf(
                'A parcela %s do contrato %s foi paga com cem vezes o valor previsto ou mais: confira se o valor pago foi lido certo.',
                $summary->paidFarAboveExpectedNumber,
                (string) $contract->code,
            ),
            default => null,
        };

        return $atypical === null
            ? null
            : $this->contractIssue(SalesBoardIssueCode::InstallmentValueAtypical, $atypical, $contract, $unitId);
    }

    /**
     * Datas de venda e de distrato anteriores a 1990 em qualquer contrato
     * carregado -- o ano digitado errado (0026) decide ocupação e movimentos, e
     * a reimportação não o corrige sozinha.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return list<SalesBoardIssue>
     */
    private function contractDateIssues(Collection $contracts): array
    {
        $issues = [];

        foreach ($contracts as $contract) {
            foreach (['venda' => $contract->sale_date, 'distrato' => $contract->cancellation_date] as $label => $date) {
                $day = $date?->toDateString();

                if (! SalesBoardPlausibility::isBeforeMinimumYear($day)) {
                    continue;
                }

                $issues[] = $this->contractIssue(
                    SalesBoardIssueCode::SourceDateBefore1990,
                    sprintf(
                        'O contrato %s tem data de %s em %s, anterior a 1990: confira o ano.',
                        (string) $contract->code,
                        $label,
                        self::displayDay($day),
                    ),
                    $contract,
                    (int) $contract->construction_unit_id,
                );
            }
        }

        return $issues;
    }

    private function contractIssue(SalesBoardIssueCode $code, string $message, Contract $contract, int $unitId): SalesBoardIssue
    {
        return new SalesBoardIssue(
            code: $code,
            message: $message,
            constructionUnitId: $unitId,
            contractId: (int) $contract->getKey(),
            contractCode: $contract->code,
        );
    }
}
