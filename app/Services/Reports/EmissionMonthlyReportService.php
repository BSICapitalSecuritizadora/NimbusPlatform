<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Domain\PuCalculator\DTOs\PuReading;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\DTOs\ConstructionProgressData;
use App\DTOs\Guarantees\GuaranteePositionData;
use App\DTOs\SalesBoards\ConstructionSalesPosition;
use App\DTOs\SalesBoards\EmissionSalesPosition;
use App\DTOs\SalesBoards\SalesBoardPublicationGaps;
use App\Enums\GuaranteeType;
use App\Enums\LegalInstrumentFieldKey;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\EmissionMonthlyReportNote;
use App\Models\EmissionPuEvent;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\GuaranteeSnapshot;
use App\Models\LegalInstrument;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Receivable;
use App\Services\ConstructionProgressProvider;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use App\Services\SalesBoards\SalesBoardPositionReader;
use App\Services\SalesBoards\SalesBoardPublicationGapClassifier;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consolida (apenas leitura) os dados do relatório mensal de uma emissão.
 *
 * V1: foco em informações textuais e tabelas já disponíveis no sistema.
 * Não executa cálculos financeiros novos nem grava nada — apenas lê o que
 * já está cadastrado e formata para exibição no template PDF.
 *
 * Seções dependentes de gráfico (Análise do Mês, Evolução da Obra) e o módulo
 * de Comentários e Notas ficam previstos para a V2 (ver template Blade).
 */
class EmissionMonthlyReportService
{
    private const NOT_INFORMED = 'Não informado';

    private const NOT_AVAILABLE = 'Não disponível';

    private const NO_DATA = 'Sem informações cadastradas para o período.';

    private const NOT_CONSOLIDATED = 'Dados ainda não consolidados para este período.';

    private const NO_SCHEDULED_EVENT = 'Nenhum evento cadastrado';

    private const NO_GUARANTEES = 'Nenhuma garantia cadastrada para a emissão.';

    /**
     * Competências exibidas no histórico de unidades.
     */
    private const UNITS_HISTORY_LIMIT = 6;

    public function __construct(
        private readonly ConstructionProgressProvider $constructionProgressProvider,
        private readonly EmissionGuaranteeCoverageEngine $guaranteeCoverageEngine,
        private readonly ContractNegotiationEvents $contractNegotiationEvents,
        private readonly CompetenceNegotiationEvents $competenceNegotiationEvents,
        private readonly SalesBoardPositionReader $salesBoardPositionReader,
        private readonly EmissionPuReader $puReader,
        private readonly SalesBoardPublicationGapClassifier $publicationGapClassifier,
    ) {}

    /**
     * Bloco de garantias do relatório (§48 do escopo), a seção "Garantias e
     * Cobertura" do PDF, logo depois do saldo devedor.
     *
     * O relatório consome o resultado do módulo — não recalcula nada. A
     * competência **fechada** tem prioridade sobre a apuração ao vivo: é o
     * número que foi consolidado naquele mês, e reapurar poderia devolver outro
     * depois de uma correção retroativa em recebíveis, estoque ou curva de PU.
     * As marcas da competência fechada chegam a quem lê: desatualizada (pelo
     * Quadro de Vendas ou pelo saldo devedor), posição parcial confirmada no
     * fechamento e origem do quadro não registrada.
     *
     * Snapshot aberto não é consolidado: é uma apuração intermediária, em geral
     * gravada antes de o Quadro de Vendas do mês existir. Usá-lo congelaria no
     * relatório um estoque que a própria seção de unidades já não mostra — por
     * isso, sem fechamento, vale a apuração ao vivo, rotulada como preliminar.
     *
     * Emissão sem garantia cadastrada diz isso, em vez de uma tabela de zeros.
     *
     * @return array<string, mixed>
     */
    private function buildGuarantees(Emission $emission, CarbonImmutable $monthStart): array
    {
        $referenceMonth = $monthStart->toDateString();

        /** @var GuaranteeSnapshot|null $snapshot */
        $snapshot = $emission->guaranteeSnapshots()
            ->whereDate('reference_month', $referenceMonth)
            ->whereNotNull('closed_at')
            ->first();

        if ($snapshot !== null) {
            return $this->guaranteesFromSnapshot($emission, $snapshot, $referenceMonth);
        }

        $position = $this->guaranteeCoverageEngine->buildPosition($emission, $referenceMonth);

        return [
            'has_data' => $position->positions->isNotEmpty(),
            'empty_message' => self::NO_GUARANTEES,
            'consolidated' => false,
            'closed_at' => null,
            'outdated' => false,
            'outdated_reasons' => [],
            'sales_board_outdated' => false,
            'partial_sales_board_position' => $position->hasSalesBoardGaps(),
            'partial_coverage_confirmed' => false,
            'sales_board_coverage_unknown' => false,
            'sales_board_gaps' => $position->salesBoardGapDescriptions(),
            'status' => $position->coverageStatus->label(),
            'outstanding_balance' => $this->guaranteeMoney($position->outstandingBalance),
            'gross_value' => $this->guaranteeMoney($position->totalGrossValue),
            'eligible_value' => $this->guaranteeMoney($position->totalEligibleValue),
            'required_value' => $this->guaranteeMoney($position->totalRequiredValue),
            'coverage_ratio' => $this->guaranteeRatio($position->coverageRatio),
            'required_ratio' => $this->guaranteeRatio($position->requiredRatio),
            'surplus_deficit' => $this->guaranteeMoney($position->surplusDeficit),
            'active_count' => $position->activeGuaranteesCount,
            'items' => $position->positions
                ->map(fn (GuaranteePositionData $item): array => [
                    'name' => $item->guarantee->display_name,
                    'type' => GuaranteeType::labelFor($item->guarantee->type),
                    'source' => $item->value->source->label(),
                    'value_status' => $item->value->status->value,
                    'contracted_value' => $this->guaranteeMoney(
                        $item->guarantee->contracted_value === null ? null : (float) $item->guarantee->contracted_value,
                    ),
                    'current_value' => $this->guaranteeMoney($item->currentValue()),
                    'eligible_value' => $this->guaranteeMoney($item->eligibleValue),
                    'required_value' => $this->guaranteeMoney($item->requiredValue()),
                    'status' => $item->coverageStatus->label(),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guaranteesFromSnapshot(
        Emission $emission,
        GuaranteeSnapshot $snapshot,
        string $referenceMonth,
    ): array {
        $positions = $emission->guaranteeMonthlyPositions()
            ->with('guarantee')
            ->whereDate('reference_month', $referenceMonth)
            ->get();

        $salesBoardCoverage = $snapshot->salesBoardCoverage();

        return [
            // Sem posição gravada por garantia, o fechamento ainda tem os totais
            // da competência a mostrar quando contou alguma garantia.
            'has_data' => $positions->isNotEmpty() || ((int) $snapshot->active_guarantees_count > 0),
            'empty_message' => self::NO_GUARANTEES,
            'consolidated' => true,
            'closed_at' => $snapshot->closed_at === null ? null : BusinessTime::at($snapshot->closed_at)->format('d/m/Y H:i'),
            'outdated' => $snapshot->isOutdated(),
            'outdated_reasons' => $snapshot->outdatedReasons(),
            'sales_board_outdated' => $snapshot->isSalesBoardOutdated(),
            'partial_sales_board_position' => $salesBoardCoverage?->hasGaps() ?? false,
            'partial_coverage_confirmed' => $snapshot->hasPartialCoverageConfirmation(),
            'sales_board_coverage_unknown' => $snapshot->hasUnrecordedSalesBoardCoverage(),
            'sales_board_gaps' => $salesBoardCoverage?->gapDescriptions() ?? [],
            'status' => $snapshot->coverage_status?->label() ?? self::NOT_CONSOLIDATED,
            'outstanding_balance' => $this->guaranteeMoney($this->toFloat($snapshot->outstanding_balance)),
            'gross_value' => $this->guaranteeMoney($this->toFloat($snapshot->total_gross_value)),
            'eligible_value' => $this->guaranteeMoney($this->toFloat($snapshot->total_eligible_value)),
            'required_value' => $this->guaranteeMoney($this->toFloat($snapshot->total_required_value)),
            'coverage_ratio' => $this->guaranteeRatio($this->toFloat($snapshot->coverage_ratio)),
            'required_ratio' => $this->guaranteeRatio($this->toFloat($snapshot->required_ratio)),
            'surplus_deficit' => $this->guaranteeMoney($this->toFloat($snapshot->surplus_deficit)),
            'active_count' => $snapshot->active_guarantees_count ?? $positions->count(),
            'items' => $positions
                ->map(fn (GuaranteeMonthlyPosition $item): array => [
                    'name' => $item->guarantee?->display_name ?? '—',
                    'type' => GuaranteeType::labelFor($item->guarantee?->type),
                    'source' => $item->value_source?->label() ?? '—',
                    'value_status' => $item->value_status?->value,
                    'contracted_value' => $this->guaranteeMoney(
                        $item->guarantee?->contracted_value === null ? null : (float) $item->guarantee->contracted_value,
                    ),
                    'current_value' => $this->guaranteeMoney($this->toFloat($item->current_value)),
                    'eligible_value' => $this->guaranteeMoney($this->toFloat($item->eligible_value)),
                    'required_value' => $this->guaranteeMoney($this->toFloat($item->required_value)),
                    'status' => $item->coverage_status?->label() ?? '—',
                ])
                ->all(),
        ];
    }

    /**
     * Instrumentos jurídicos na competência do relatório (§33 do escopo).
     *
     * A posição é reconstruída **na data de fechamento do mês**, não hoje: o
     * relatório de dezembro tem de mostrar o que valia em dezembro, mesmo que
     * um aditamento de março tenha mudado tudo depois. A consolidação vem do
     * domínio; aqui só se formata.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLegalInstruments(Emission $emission, CarbonImmutable $monthEnd): array
    {
        $resolver = app(InstrumentPositionResolver::class);

        return $emission->legalInstruments()
            ->with(['documents.document', 'guarantees'])
            ->get()
            ->map(function (LegalInstrument $instrument) use ($resolver, $monthEnd): array {
                $position = $resolver->resolve($instrument, Carbon::parse($monthEnd->toDateString()));
                $latestAmendment = $instrument->latestAmendment();

                return [
                    'name' => $instrument->display_name,
                    'type' => $instrument->type->label(),
                    'status' => $instrument->status_label,
                    'issuer' => $position->valueOrNotFound(LegalInstrumentFieldKey::Issuer),
                    'creditor' => $position->valueOrNotFound(LegalInstrumentFieldKey::Creditor),
                    'original_amount' => $position->valueOrNotFound(LegalInstrumentFieldKey::OriginalAmount),
                    'current_amount' => $position->valueOrNotFound(LegalInstrumentFieldKey::PrincipalAmount),
                    'maturity_date' => $position->valueOrNotFound(LegalInstrumentFieldKey::MaturityDate),
                    'remuneration' => $position->valueOrNotFound(LegalInstrumentFieldKey::Remuneration),
                    'minimum_coverage' => $position->valueOrNotFound(LegalInstrumentFieldKey::MinimumCoverage),
                    'guarantees' => $position->guarantees
                        ->map(fn ($guarantee): string => $guarantee->display_name)
                        ->all(),
                    'last_change' => $latestAmendment === null ? null : sprintf(
                        '%s — %s',
                        $latestAmendment->role_label,
                        $latestAmendment->document_date?->format('d/m/Y') ?? 'sem data',
                    ),
                    'documents_count' => $instrument->documents->count(),
                ];
            })
            ->all();
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Ausência é dita, não convertida em zero (§25 do escopo).
     */
    private function guaranteeMoney(?float $value): string
    {
        return $value === null
            ? 'Não informado'
            : 'R$ '.number_format($value, 2, ',', '.');
    }

    private function guaranteeRatio(?float $value): string
    {
        return $value === null
            ? 'Não apurado'
            : number_format($value * 100, 2, ',', '.').'%';
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Emission $emission, CarbonInterface $referenceMonth): array
    {
        $monthStart = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $emission->loadMissing(['funds.bank', 'funds.fundType', 'funds.fundName']);

        $receivable = $this->latestReceivable($emission, $monthStart, $monthEnd);
        $salesCompetences = $this->salesBoardPositionReader->competencesUntil($emission, $monthEnd);
        $unitsHistoryCompetences = array_slice($salesCompetences, -self::UNITS_HISTORY_LIMIT);
        $salesPositions = $this->salesBoardPositionReader->forEmissionMonths(
            $emission,
            [...$unitsHistoryCompetences, $monthStart],
        );
        $negotiationsData = $this->buildNegotiations($emission, $monthStart, $monthEnd);
        $payment = $this->lastPaymentUntil($emission, $monthEnd);
        $upcomingEvents = $this->upcomingEventsFrom($emission, $monthStart);
        $constructions = $emission->constructions()->orderBy('development_name')->get();

        return [
            'meta' => [
                'reference_label' => $this->monthLabel($monthStart),
                'reference_month' => $monthStart->format('m/Y'),
                'generated_at' => $this->generatedAt(),
            ],
            'header' => $this->buildHeader($emission, $monthStart, $monthEnd),
            'characteristics' => $this->buildCharacteristics($emission),
            'payment' => $this->buildPayment($payment),
            'calendar' => $this->buildCalendar($upcomingEvents),
            'debt_balance' => $this->buildDebtBalance($emission, $monthEnd),
            'guarantees' => $this->buildGuarantees($emission, $monthStart),
            'legal_instruments' => $this->buildLegalInstruments($emission, $monthEnd),
            'accounts' => $this->buildAccounts($emission),
            'expenses' => $this->buildExpenses($emission, $monthStart, $monthEnd),
            'expenses_history' => $this->buildExpensesHistory($emission, $monthEnd),
            'delinquency' => $this->buildDelinquency($receivable),
            'receivables' => $this->buildReceivablesSummary($receivable),
            'units' => $this->buildUnits($emission, $salesPositions->get($monthStart->format('Y-m'))),
            'units_history' => $this->buildUnitsHistory($unitsHistoryCompetences, $salesPositions),
            'negotiations' => $negotiationsData,
            'negotiations_history' => $this->buildNegotiationsHistory($emission, $monthEnd),
            'analise_mes' => $this->buildAnaliseMes($receivable),
            'receivables_history' => $this->buildReceivablesHistory($emission, $monthEnd),
            'construction' => $this->buildConstructionProgress($emission, $monthStart, $constructions),
            'construction_history' => $this->buildConstructionHistory($emission, $monthStart, $constructions),
            'notes' => $this->buildNotes($emission, $monthStart, $monthEnd),
        ];
    }

    /**
     * O instante da geração, no horário de Brasília: o rodapé é lido por quem
     * recebe o PDF, e o relógio da aplicação está em UTC.
     */
    private function generatedAt(): string
    {
        return BusinessTime::at(CarbonImmutable::now())->format('d/m/Y H:i');
    }

    public function fileName(Emission $emission, CarbonInterface $referenceMonth): string
    {
        return sprintf(
            'relatorio-mensal-emissao-%d-%s.pdf',
            $emission->id,
            CarbonImmutable::parse($referenceMonth->toDateString())->format('Y-m'),
        );
    }

    /**
     * Monta o relatório consolidado de múltiplas competências de uma emissão,
     * reaproveitando o montador mensal por competência. Cada mês respeita seus
     * próprios dados; meses sem dados exibem os fallbacks normais sem quebrar o PDF.
     * O número de competências é limitado para evitar consultas/PDF excessivos.
     *
     * @return array<string, mixed>
     */
    public function buildConsolidated(
        Emission $emission,
        CarbonInterface $startMonth,
        CarbonInterface $endMonth,
        int $maxMonths = 12,
    ): array {
        $start = CarbonImmutable::parse($startMonth->toDateString())->startOfMonth();
        $end = CarbonImmutable::parse($endMonth->toDateString())->startOfMonth();

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $months = [];
        $cursor = $start;
        $last = $start;

        while ($cursor->lte($end) && count($months) < $maxMonths) {
            $months[] = [
                'label' => $this->monthLabel($cursor),
                'data' => $this->build($emission, $cursor),
            ];
            $last = $cursor;
            $cursor = $cursor->addMonthNoOverflow();
        }

        $header = $months[0]['data']['header'] ?? [];

        return [
            'meta' => [
                'period_label' => $this->monthLabel($start).' a '.$this->monthLabel($last),
                'generated_at' => $this->generatedAt(),
            ],
            'emission' => [
                'name' => $header['name'] ?? self::NOT_INFORMED,
                'identifier' => $header['identifier'] ?? self::NOT_INFORMED,
                'offer' => $header['offer'] ?? self::NOT_INFORMED,
            ],
            'months' => $months,
        ];
    }

    public function consolidatedFileName(Emission $emission, CarbonInterface $startMonth, CarbonInterface $endMonth): string
    {
        $start = CarbonImmutable::parse($startMonth->toDateString());
        $end = CarbonImmutable::parse($endMonth->toDateString());

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return sprintf(
            'relatorio-mensal-emissao-%d-%s-a-%s.pdf',
            $emission->id,
            $start->format('Y-m'),
            $end->format('Y-m'),
        );
    }

    /**
     * Monta o "Resumo da Operação".
     *
     * Saldo Devedor e PU vêm de `EmissionPuReader` na data-base (último dia do
     * mês de referência): a curva oficial homologada quando existe, o Histórico
     * de PU importado quando não, sempre o último PU menor ou igual à data-base.
     * O Saldo Devedor multiplica esse PU pela Quantidade Integralizada da emissão.
     * O Próximo Evento vem do cronograma contratual (eventos de PU) e, sem ele,
     * do Cronograma de Pagamentos.
     *
     * @return array<string, mixed>
     */
    private function buildHeader(Emission $emission, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $pu = $this->puReader->readingOn($emission, $monthEnd);
        $integralizedQuantity = $this->integralizedQuantity($emission);
        $nextEventDate = $this->nextEventDate($emission, $monthStart);

        return [
            'name' => $this->text($emission->name),
            'identifier' => $this->text($emission->isin_code ?? $emission->if_code),
            'offer' => $this->text($emission->type ?? $emission->offer_type),
            'debt_balance' => $this->resumoDebtBalance($pu, $integralizedQuantity),
            'debt_position' => $this->debtPosition($pu, $monthEnd),
            'circulating_quantity' => $this->integer($emission->integralized_quantity ?: $emission->issued_quantity),
            'remuneration' => $this->text($emission->formatted_remuneration),
            'current_pu' => $pu !== null ? $this->pu((float) $pu->unitValue) : self::NOT_INFORMED,
            'next_event' => $nextEventDate?->format('d/m/Y') ?? self::NO_SCHEDULED_EVENT,
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function buildCharacteristics(Emission $emission): array
    {
        return [
            ['label' => 'Emissão', 'value' => $this->text($emission->emission_number !== null ? $emission->emission_number.'ª Emissão' : null)],
            ['label' => 'Série(s)', 'value' => $this->text($emission->series !== null ? $emission->series.'ª Série' : null)],
            ['label' => 'Tipo de oferta', 'value' => $this->text($emission->offer_type)],
            ['label' => 'Código IF', 'value' => $this->text($emission->if_code)],
            ['label' => 'Código ISIN', 'value' => $this->text($emission->isin_code)],
            ['label' => 'Data da emissão', 'value' => $this->date($emission->issue_date)],
            ['label' => 'Data de vencimento', 'value' => $this->date($emission->maturity_date)],
            ['label' => 'Público alvo', 'value' => $this->text($emission->target_audience)],
            ['label' => 'Regime fiduciário', 'value' => $this->yesNo($emission->fiduciary_regime)],
            ['label' => 'Valor total da oferta', 'value' => $emission->issued_volume !== null ? $this->money((float) $emission->issued_volume) : self::NOT_INFORMED],
            ['label' => 'Quantidade total emitida', 'value' => $this->integer($emission->issued_quantity)],
            ['label' => 'Concentração', 'value' => $this->text($emission->concentration)],
            ['label' => 'Segmento(s)', 'value' => $this->text($emission->segment)],
            ['label' => 'Emissora', 'value' => $this->text($emission->issuer)],
            ['label' => 'Escriturador', 'value' => $this->text($emission->registrar)],
            ['label' => 'Distribuidor', 'value' => $this->text($emission->distributor)],
            ['label' => 'Agente fiduciário', 'value' => $this->text($emission->trustee_agent)],
            ['label' => 'Aval', 'value' => $this->yesNo($emission->aval)],
            ['label' => 'Cessão fiduciária', 'value' => $this->yesNo($emission->fiduciary_assignment)],
            ['label' => 'Alienação fiduciária de imóvel', 'value' => $this->yesNo($emission->property_fiduciary_alienation)],
            ['label' => 'Alienação fiduciária de cotas', 'value' => $this->yesNo($emission->quota_fiduciary_alienation)],
            ['label' => 'Fundo de Juros/Garantia', 'value' => $this->yesNo($emission->guarantee_fund)],
            ['label' => 'Fundo de Despesas', 'value' => $this->yesNo($emission->expense_fund)],
            ['label' => 'Fundo de Reserva', 'value' => $this->yesNo($emission->reserve_fund)],
            ['label' => 'Fundo de Obras', 'value' => $this->yesNo($emission->works_fund)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayment(?Payment $payment): array
    {
        if ($payment === null) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        // A tabela `payments` (decimal 15,2) só possui: premium_value, interest_value,
        // amortization_value e extra_amortization_value. NÃO existe coluna para o
        // desdobramento ordinário x extraordinário em PU nem para "juros extraordinários".
        // Exibir esse detalhamento (como no relatório de referência) exigiria novas
        // colunas em `payments` ou uma fonte consolidada — avaliar em V2. Por ora
        // mantemos os valores disponíveis em R$, sem quebrar relatórios já gerados.
        return [
            'has_data' => true,
            'payment_date' => $this->date($payment->payment_date),
            'rows' => [
                ['label' => 'Prêmio', 'value' => $this->money((float) $payment->premium_value)],
                ['label' => 'Juros', 'value' => $this->money((float) $payment->interest_value)],
                ['label' => 'Amortização', 'value' => $this->money((float) $payment->amortization_value)],
                ['label' => 'Amortização Extraordinária', 'value' => $this->money((float) $payment->extra_amortization_value)],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  Collection<int, EmissionPuEvent>  $events
     * @return array<string, mixed>
     */
    private function buildCalendar(Collection $events): array
    {
        $next = $events->first();

        if (! $next instanceof EmissionPuEvent) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        $rescheduled = $next->original_date !== null
            && $next->effective_date !== null
            && ! $next->original_date->isSameDay($next->effective_date);

        $highlight = [
            ['label' => 'Próximo evento', 'value' => $this->date($next->effective_date)],
            ['label' => 'Tipo de evento', 'value' => $this->eventTypeLabel($next->event_type)],
            ['label' => 'Amortização', 'value' => $this->amortizationLabel($next)],
            ['label' => 'Situação', 'value' => $rescheduled
                ? 'Reagendado (data original: '.$this->date($next->original_date).')'
                : 'Conforme cronograma'],
        ];

        if ($next->description !== null && $next->description !== '') {
            $highlight[] = ['label' => 'Descrição', 'value' => (string) $next->description];
        }

        $upcoming = $events->map(fn (EmissionPuEvent $event): array => [
            'sequence' => $event->sequence !== null ? (string) $event->sequence : '—',
            'date' => $this->date($event->effective_date),
            'type' => $this->eventTypeLabel($event->event_type),
            'amortization' => $this->amortizationLabel($event),
        ])->all();

        return [
            'has_data' => true,
            'highlight' => $highlight,
            'upcoming' => $upcoming,
            'has_upcoming' => count($upcoming) > 1,
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function buildDebtBalance(Emission $emission, CarbonImmutable $monthEnd): array
    {
        $reading = $this->puReader->readingOn($emission, $monthEnd);
        $unitValue = $this->debtBalanceUnitValue($emission, $reading);
        $debt = $this->debtBalanceValue($emission, $unitValue);

        return [
            ['label' => 'Quantidade em circulação', 'value' => $this->integer($emission->integralized_quantity ?: $emission->issued_quantity)],
            ['label' => 'Preço unitário (emissão)', 'value' => $unitValue !== null ? $this->pu((float) $unitValue) : self::NOT_AVAILABLE],
            ['label' => 'Saldo devedor do CRI', 'value' => $debt !== null ? $this->money($debt) : self::NOT_AVAILABLE],
            ['label' => 'Posição em', 'value' => $this->debtPosition($reading, $monthEnd)],
        ];
    }

    /**
     * Data a que o saldo devedor pertence. Curva oficial que ainda não chega ao fim
     * do mês: a posição é a data do PU realizado, nunca a data-base -- o valor não
     * pertence a ela.
     */
    private function debtPosition(?PuReading $pu, CarbonImmutable $monthEnd): string
    {
        return ($pu?->fromOfficialCurve() && $pu->isCarriedForward() ? $pu->date : $monthEnd)->format('d/m/Y');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAccounts(Emission $emission): array
    {
        $rows = $emission->funds->map(function ($fund): array {
            $name = $fund->fundName?->name ?? $fund->trade_name ?? $fund->fundType?->name ?? self::NOT_INFORMED;

            return [
                'name' => $name,
                'bank' => $fund->bank?->name ?? self::NOT_INFORMED,
                'agency' => $this->text($fund->agency),
                'account' => $this->text($fund->account),
                'balance' => $fund->balance !== null ? $this->money((float) $fund->balance) : self::NOT_AVAILABLE,
            ];
        })->all();

        return [
            'has_data' => $rows !== [],
            'empty_message' => self::NO_DATA,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExpenses(Emission $emission, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $expenses = $emission->expenses()
            ->where('start_date', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($monthStart): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $monthStart->toDateString());
            })
            ->orderBy('category')
            ->get();

        $recurring = [];
        $nonRecurring = [];
        $recurringTotal = 0.0;
        $nonRecurringTotal = 0.0;

        foreach ($expenses as $expense) {
            $amount = (float) $expense->amount;
            $row = [
                'category' => $this->text($expense->category),
                'period' => Expense::PERIOD_OPTIONS[$expense->period] ?? self::NOT_INFORMED,
                'amount' => $this->money($amount),
            ];

            if (Expense::isRecurringPeriod($expense->period)) {
                $recurring[] = $row;
                $recurringTotal += $amount;
            } else {
                $nonRecurring[] = $row;
                $nonRecurringTotal += $amount;
            }
        }

        return [
            'has_data' => $recurring !== [] || $nonRecurring !== [],
            'empty_message' => self::NO_DATA,
            'recurring' => $recurring,
            'recurring_total' => $this->money($recurringTotal),
            'non_recurring' => $nonRecurring,
            'non_recurring_total' => $this->money($nonRecurringTotal),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDelinquency(?Receivable $receivable): array
    {
        if ($receivable === null) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        $buckets = [
            '1 a 30 dias' => (float) $receivable->overdue_up_to_30_days_amount,
            '31 a 60 dias' => (float) $receivable->overdue_31_to_60_days_amount,
            '61 a 90 dias' => (float) $receivable->overdue_61_to_90_days_amount,
            '91 a 120 dias' => (float) $receivable->overdue_91_to_120_days_amount,
            '121 a 150 dias' => (float) $receivable->overdue_121_to_150_days_amount,
            '151 a 180 dias' => (float) $receivable->overdue_151_to_180_days_amount,
            '181 a 360 dias' => (float) $receivable->overdue_181_to_360_days_amount,
            'Acima de 360 dias' => (float) $receivable->overdue_over_360_days_amount,
        ];

        $total = array_sum($buckets);
        $rows = [];

        foreach ($buckets as $label => $value) {
            $share = $total > 0 ? $value / $total * 100 : 0.0;

            $rows[] = [
                'label' => $label,
                'value' => $this->money($value),
                'percent' => $total > 0 ? $this->percent($share) : '0,00%',
                'bar_percent' => round($share, 2),
            ];
        }

        return [
            'has_data' => true,
            'rows' => $rows,
            'total' => $this->money($total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReceivablesSummary(?Receivable $receivable): array
    {
        if ($receivable === null) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        return [
            'has_data' => true,
            'rows' => [
                ['label' => 'Contratos ativos', 'value' => $this->integer($receivable->active_contracts_count)],
                ['label' => 'Juros recebidos (parcelas)', 'value' => $this->money((float) $receivable->received_installment_interest_amount)],
                ['label' => 'Amortização recebida (parcelas)', 'value' => $this->money((float) $receivable->received_installment_amortization_amount)],
                ['label' => 'Juros recebidos (antecipação)', 'value' => $this->money((float) $receivable->received_prepayment_interest_amount)],
                ['label' => 'Amortização recebida (antecipação)', 'value' => $this->money((float) $receivable->received_prepayment_amortization_amount)],
                ['label' => 'Saldo inadimplente do mês', 'value' => $this->money((float) $receivable->monthly_default_balance_amount)],
                ['label' => 'Saldo inadimplente total', 'value' => $this->money((float) $receivable->total_default_balance_amount)],
            ],
        ];
    }

    /**
     * Painel de unidades da competência.
     *
     * Recebe a posição **consolidada da emissão** — a soma dos empreendimentos,
     * cada um com a sua última posição conhecida. Antes recebia um único
     * `SalesBoard` escolhido por `first()`, que numa emissão com mais de um
     * empreendimento representava só um deles e omitia o resto (GF-01).
     *
     * @return array<string, mixed>
     */
    private function buildUnits(Emission $emission, ?EmissionSalesPosition $position): array
    {
        if ($position === null || ! $position->hasData()) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        return [
            'has_data' => true,
            'rows' => [
                ['label' => 'Estoque', 'value' => $this->integer($position->stockUnits)],
                ['label' => 'Financiadas/Vendidas', 'value' => $this->integer($position->financedUnits)],
                ['label' => 'Quitadas', 'value' => $this->integer($position->paidUnits)],
                ['label' => 'Permutadas', 'value' => $this->integer($position->exchangedUnits)],
                ['label' => 'Total', 'value' => $this->integer($position->totalUnits)],
            ],
            'composition' => $this->unitsComposition($position),
            'coverage' => $position->coverage(),
            'coverage_summary' => $this->unitsCoverageSummary(
                $position,
                $this->publicationGapClassifier->classify($emission, $position),
            ),
        ];
    }

    /**
     * Cobertura da posição em texto, para o PDF.
     *
     * O painel soma os empreendimentos com a última posição conhecida de cada
     * um. Sem esta legenda, uma soma parcial (empreendimento sem quadro) ou
     * transportada (quadro de competência anterior) sai no relatório como se
     * fosse a posição completa da competência.
     *
     * Quando o motivo é o ciclo mensal, ele vem nomeado
     * ({@see SalesBoardPublicationGapClassifier}): a competência produzida pela
     * automação que ainda aguarda publicação, e a que a Gestão cancelou --
     * esta sem o motivo, que é interno, porque o relatório vai para fora.
     *
     * @return array{
     *     label: string,
     *     complete: bool,
     *     awaiting_publication: bool,
     *     awaiting: list<string>,
     *     cancelled: list<string>,
     *     constructions: list<array{name: string, month: string, status: string}>,
     *     carried_forward: list<array{name: string, month: string}>,
     *     missing: list<string>
     * }
     */
    private function unitsCoverageSummary(EmissionSalesPosition $position, SalesBoardPublicationGaps $gaps): array
    {
        $complete = $position->isFullyCovered() && ! $position->hasCarryForward();
        $name = fn (ConstructionSalesPosition $construction): string => filled($construction->constructionName)
            ? (string) $construction->constructionName
            : 'Empreendimento #'.$construction->constructionId;

        return [
            'label' => sprintf(
                '%d de %d %s com posição',
                $position->constructionsCovered,
                $position->constructionsExpected,
                $position->constructionsExpected === 1 ? 'empreendimento' : 'empreendimentos',
            ),
            'complete' => $complete,
            'awaiting_publication' => $gaps->awaitingPublication !== [],
            'awaiting' => array_map($name, $gaps->awaitingPublication),
            'cancelled' => array_map(
                fn (array $cancelled): string => $name($cancelled['position']),
                $gaps->cancelled,
            ),
            'constructions' => array_map(
                fn (ConstructionSalesPosition $construction): array => [
                    'name' => $name($construction),
                    'month' => $construction->referenceMonthUsedLabel() ?? '—',
                    'status' => $construction->status->label(),
                ],
                $position->positions,
            ),
            'carried_forward' => array_map(
                fn (ConstructionSalesPosition $construction): array => [
                    'name' => $name($construction),
                    'month' => (string) $construction->referenceMonthUsedLabel(),
                ],
                $position->carriedForwardPositions(),
            ),
            'missing' => array_map($name, $position->missingPositions()),
        ];
    }

    /**
     * @return list<array{label?: string, class: string, percent: float}>
     */
    private function unitsComposition(EmissionSalesPosition $position, bool $labelled = true): array
    {
        $base = $position->stockUnits
            + $position->financedUnits
            + $position->paidUnits
            + $position->exchangedUnits;

        if ($base <= 0) {
            return [];
        }

        $segments = [
            ['label' => 'Quitadas', 'class' => 'seg-1', 'units' => $position->paidUnits],
            ['label' => 'Financiadas/Vendidas', 'class' => 'seg-2', 'units' => $position->financedUnits],
            ['label' => 'Permutadas', 'class' => 'seg-3', 'units' => $position->exchangedUnits],
            ['label' => 'Estoque', 'class' => 'seg-4', 'units' => $position->stockUnits],
        ];

        return array_map(
            fn (array $segment): array => $labelled
                ? ['label' => $segment['label'], 'class' => $segment['class'], 'percent' => round($segment['units'] / $base * 100, 2)]
                : ['class' => $segment['class'], 'percent' => round($segment['units'] / $base * 100, 2)],
            $segments,
        );
    }

    /**
     * Negociações do mês, no modo de contratos.
     *
     * Por empreendimento, a fonte é a competência: com o Quadro publicado,
     * valem os movimentos congelados da publicação vigente -- vendas, distratos
     * e quitações, com os fatos de competências anteriores rotulados e a
     * revisão de venda publicada à parte, fora da contagem --; sem publicação,
     * os contratos (`sale_date` e `cancellation_date` dentro do mês), sem o que
     * já foi publicado em outra competência: prévia sujeita a alteração quando
     * a competência é da automação e ainda vai ser publicada, e só "contratos"
     * no Quadro legado, que nunca terá publicação ({@see CompetenceNegotiationEvents}).
     * A competência cancelada cujos fatos a seguinte publicou aponta essa
     * competência. O contrato de permuta não é venda nem distrato, como no
     * Quadro. Escopo estrito à emissão via construction -> emission. A fonte
     * (contratos vs legado) é explícita via Emission::usesContractNegotiations().
     *
     * As colunas "Competência do fato" e "Situação" só fazem sentido quando há
     * publicação ou prévia; no Quadro legado o bloco sai como sempre saiu.
     *
     * @return array<string, mixed>
     */
    private function buildNegotiations(Emission $emission, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        // Explicit source-of-truth: no partial-data inference via exists()
        if ($emission->usesContractNegotiations()) {
            $events = $this->competenceNegotiationEvents->forMonth($emission, $monthStart, $monthEnd);

            $sales = $events['sales'];
            $lateSales = $events['late_sales'];
            $cancellations = $events['cancellations'];
            $lateCancellations = $events['late_cancellations'];
            $settlements = $events['settlements'];
            $revisions = $events['revisions'];
            $anyPublished = in_array(CompetenceNegotiationEvents::SOURCE_PUBLISHED, $events['sources'], true);
            $withSituation = array_intersect($events['sources'], [
                CompetenceNegotiationEvents::SOURCE_PUBLISHED,
                CompetenceNegotiationEvents::SOURCE_PREVIEW,
                CompetenceNegotiationEvents::SOURCE_ABSORBED,
            ]) !== [];
            $note = $this->negotiationsNote($events['sources'], $events['absorbed']);

            $hasData = $sales->isNotEmpty() || $lateSales->isNotEmpty() || $cancellations->isNotEmpty()
                || $lateCancellations->isNotEmpty() || $settlements->isNotEmpty() || $revisions->isNotEmpty();

            if (! $hasData) {
                return [
                    'has_data' => false,
                    'empty_message' => $events['absorbed'] === []
                        ? 'Não houve negociações no período.'
                        : 'Nenhuma negociação a listar nesta competência.',
                    'rows' => [],
                    'sales_count' => 0,
                    'cancellations_count' => 0,
                    'sales' => [],
                    'cancellations' => [],
                    'transactions' => [],
                    'vendas' => [],
                    'distratos' => [],
                    'quitacoes' => [],
                    'revisoes' => [],
                    'late' => ['sales' => 0, 'cancellations' => 0],
                    'sources' => $events['sources'],
                    'absorbed' => $events['absorbed'],
                    'with_situation' => $withSituation,
                    'source' => 'contracts',
                    'note' => $note,
                ];
            }

            $allSales = $sales->merge($lateSales)->sortBy(fn (array $row): string => (string) ($row['date']?->format('Y-m-d') ?? '9999'))->values();
            $allCancellations = $cancellations->merge($lateCancellations)->sortBy(fn (array $row): string => (string) ($row['date']?->format('Y-m-d') ?? '9999'))->values();

            $rows = [['label' => 'Vendas (mês)', 'value' => $this->integer($sales->count())]];

            if ($anyPublished) {
                $rows[] = ['label' => 'Vendas de competências anteriores', 'value' => $this->integer($lateSales->count())];
            }

            $rows[] = ['label' => 'Distratos (mês)', 'value' => $this->integer($cancellations->count())];

            if ($anyPublished) {
                $rows[] = ['label' => 'Distratos de competências anteriores', 'value' => $this->integer($lateCancellations->count())];
                $rows[] = ['label' => 'Quitações (mês)', 'value' => $this->integer($settlements->count())];
            }

            $rows[] = [
                'label' => 'Saldo líquido de unidades',
                'value' => $this->integer(($sales->count() + $lateSales->count()) - ($cancellations->count() + $lateCancellations->count())),
            ];

            return [
                'has_data' => true,
                'empty_message' => '',
                'rows' => $rows,
                'sales_count' => $sales->count(),
                'cancellations_count' => $cancellations->count(),
                'sales' => $allSales->all(),
                'cancellations' => $allCancellations->all(),
                'vendas' => $allSales->all(),
                'distratos' => $allCancellations->all(),
                'quitacoes' => $settlements->all(),
                'revisoes' => $revisions->all(),
                'late' => ['sales' => $lateSales->count(), 'cancellations' => $lateCancellations->count()],
                'transactions' => $allSales->merge($allCancellations)->sortBy(fn (array $row): string => (string) ($row['date']?->format('Y-m-d') ?? '9999'))->values()->all(),
                'sources' => $events['sources'],
                'absorbed' => $events['absorbed'],
                'with_situation' => $withSituation,
                'source' => 'contracts',
                'note' => $note,
            ];
        }

        // Legacy path: single source = manual Negotiation snapshots
        $negotiation = $this->latestNegotiation($emission, $monthStart, $monthEnd);

        return array_merge($this->buildNegotiationsFromManual($negotiation), ['source' => 'legacy']);
    }

    /**
     * A nota do bloco de negociações: o que é posição publicada, o que é
     * prévia lida dos contratos, o que vem dos contratos de uma competência
     * sem ciclo mensal (Quadro legado) e para onde foram os fatos de uma
     * competência cancelada pela Gestão.
     *
     * "Prévia" só para a competência que a automação ainda vai publicar: a do
     * Quadro legado nunca terá publicação, e chamá-la de prévia prometeria ao
     * investidor uma versão definitiva que não vem. Texto externo, sem
     * instrução interna.
     *
     * @param  array<string, string>  $sources  empreendimento => fonte
     * @param  array<string, string>  $absorbed  empreendimento => competência que publicou os fatos (`m/Y`)
     */
    private function negotiationsNote(array $sources, array $absorbed): string
    {
        $has = static fn (string $source): bool => in_array($source, $sources, true);

        $note = match (true) {
            $has(CompetenceNegotiationEvents::SOURCE_PUBLISHED) && $has(CompetenceNegotiationEvents::SOURCE_PREVIEW) => 'Empreendimentos com o Quadro de Vendas publicado: movimentos da posição publicada, inclusive os fatos de competências anteriores publicados no mês. Demais empreendimentos: prévia sujeita a alteração, lida dos contratos (Data da Venda e Data do Distrato dentro da competência).',
            $has(CompetenceNegotiationEvents::SOURCE_PUBLISHED) && $has(CompetenceNegotiationEvents::SOURCE_CONTRACTS) => 'Empreendimentos com o Quadro de Vendas publicado: movimentos da posição publicada, inclusive os fatos de competências anteriores publicados no mês. Demais empreendimentos: contratos da emissão (Data da Venda e Data do Distrato dentro da competência).',
            $has(CompetenceNegotiationEvents::SOURCE_PUBLISHED) => 'Fonte: movimentos da posição publicada no Quadro de Vendas, inclusive os fatos de competências anteriores publicados no mês. Revisões de venda já publicada aparecem à parte e não contam como venda.',
            $has(CompetenceNegotiationEvents::SOURCE_PREVIEW) => 'Fonte: contratos da emissão — Data da Venda e Data do Distrato dentro da competência. Prévia sujeita a alteração: a competência ainda não tem Quadro de Vendas publicado.',
            $has(CompetenceNegotiationEvents::SOURCE_ABSORBED) => '',
            default => 'Fonte: contratos da emissão — Data da Venda e Data do Distrato dentro da competência.',
        };

        if ($absorbed === []) {
            return $note;
        }

        $byCompetence = collect($absorbed)
            ->groupBy(fn (string $competence): string => $competence, preserveKeys: true)
            ->map(fn (Collection $group, string $competence): string => count($sources) === 1
                ? $competence
                : sprintf('%s (%s)', $competence, $group->keys()->implode(', ')))
            ->values()
            ->all();

        $absorbedNote = sprintf(
            'Competência cancelada pela Gestão: os fatos deste mês foram publicados em %s, como fatos de competência sem posição. Os lançados depois dessa publicação aparecem aqui como prévia sujeita a alteração.',
            self::humanList($byCompetence),
        );

        return trim($note.' '.$absorbedNote);
    }

    /**
     * "a", "a e b", "a, b e c".
     *
     * @param  list<string>  $items
     */
    private static function humanList(array $items): string
    {
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' e '.$last;
    }

    /**
     * Legacy manual path — kept for backward compatibility if needed elsewhere.
     * The report itself now uses the contract-derived buildNegotiations above.
     *
     * @return array<string, mixed>
     */
    private function buildNegotiationsFromManual(?Negotiation $negotiation): array
    {
        if ($negotiation === null) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        return [
            'has_data' => true,
            'rows' => [
                ['label' => 'Distratos (mês)', 'value' => $this->integer($negotiation->cancellations)],
                ['label' => 'Vendas (mês)', 'value' => $this->integer($negotiation->sales)],
            ],
        ];
    }

    /**
     * Análise do Mês — Recebíveis (Previsto × Recebido, "pago × não pago").
     * Derivada de Receivable: previsto = juros + amortização esperados; recebido =
     * juros + amortização de parcelas efetivamente recebidos no mês. Representação
     * compatível com DomPDF (cards + barra HTML/CSS), sem Chart.js.
     *
     * @return array<string, mixed>
     */
    private function buildAnaliseMes(?Receivable $receivable): array
    {
        if (! $receivable instanceof Receivable) {
            return ['has_data' => false, 'empty_message' => self::NOT_CONSOLIDATED];
        }

        $expected = (float) $receivable->expected_interest_amount + (float) $receivable->expected_amortization_amount;
        $received = (float) $receivable->received_installment_interest_amount + (float) $receivable->received_installment_amortization_amount;
        $prepayments = (float) $receivable->received_prepayment_interest_amount + (float) $receivable->received_prepayment_amortization_amount;

        if ($expected <= 0.0 && $received <= 0.0) {
            return ['has_data' => false, 'empty_message' => self::NOT_CONSOLIDATED];
        }

        $unpaid = max(0.0, $expected - $received);

        if ($expected > 0.0) {
            $paidPercent = min(100.0, $received / $expected * 100);
        } else {
            $paidPercent = $received > 0.0 ? 100.0 : 0.0;
        }

        $unpaidPercent = max(0.0, 100.0 - $paidPercent);

        return [
            'has_data' => true,
            'cards' => [
                ['label' => 'Total previsto', 'value' => $this->money($expected)],
                ['label' => 'Recebido (pago)', 'value' => $this->money($received)],
                ['label' => 'Em aberto (não pago)', 'value' => $this->money($unpaid)],
                ['label' => 'Antecipações', 'value' => $this->money($prepayments)],
            ],
            'paid_percent' => round($paidPercent, 2),
            'unpaid_percent' => round($unpaidPercent, 2),
            'paid_percent_label' => $this->percent($paidPercent),
            'unpaid_percent_label' => $this->percent($unpaidPercent),
        ];
    }

    /**
     * Evolução da Obra — previsto × realizado (mensal e acumulado) por empreendimento,
     * a partir do provider de progresso (medições). Sem dados de progresso, mantém a
     * relação dos empreendimentos vinculados como contexto e mensagem amigável.
     *
     * @param  Collection<int, Construction>  $constructions
     * @return array<string, mixed>
     */
    private function buildConstructionProgress(Emission $emission, CarbonImmutable $monthStart, Collection $constructions): array
    {
        $progress = [];
        $registry = [];

        foreach ($constructions as $construction) {
            $registry[] = [
                'name' => $this->text($construction->development_name),
                'location' => $this->locationLabel($construction),
                'period' => $this->constructionPeriod($construction),
                'estimated_value' => $construction->estimated_value !== null
                    ? $this->money((float) $construction->estimated_value)
                    : self::NOT_INFORMED,
            ];

            $data = $this->constructionProgressProvider->forEmission($emission, $monthStart, $construction);

            if ($data === null) {
                continue;
            }

            $progress[] = $this->mapProgressRow(
                $construction->development_name ?? $data->planName ?? 'Empreendimento',
                $data,
            );
        }

        if ($constructions->isEmpty()) {
            $data = $this->constructionProgressProvider->forEmission($emission, $monthStart, null);

            if ($data !== null) {
                $progress[] = $this->mapProgressRow($data->planName ?? 'Cronograma da emissão', $data);
            }
        }

        return [
            'has_progress' => $progress !== [],
            'progress' => $progress,
            'has_constructions' => $registry !== [],
            'constructions' => $registry,
            'empty_message' => 'Dados de evolução da obra ainda não consolidados para este período.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProgressRow(string $name, ConstructionProgressData $data): array
    {
        return [
            'name' => $name,
            'planned_cumulative' => $this->percent($data->plannedCumulativePercent),
            'realized_cumulative' => $this->percent($data->realizedCumulativePercent),
            'planned_monthly' => $this->percent($data->plannedMonthlyPercent),
            // Competência sem medição vigente não "mediu 0%": não foi medida. E o
            // acumulado carregado de um mês anterior contra o previsto deste mês
            // acusaria um atraso que ninguém mediu.
            'realized_monthly' => $data->measuredInMonth ? $this->percent($data->realizedMonthlyPercent) : '—',
            'diff' => $data->measuredInMonth ? $this->percent($data->diffPercent) : '—',
            'trend' => $data->measuredInMonth ? ($data->trend ?? '—') : '—',
            'measurement_date' => $data->measurementDate?->format('d/m/Y') ?? self::NOT_INFORMED,
            'bar_percent' => round(max(0.0, min(100.0, $data->realizedCumulativePercent)), 2),
        ];
    }

    private function locationLabel(Construction $construction): string
    {
        $parts = array_filter([$construction->city, $construction->state]);

        return $parts !== [] ? implode(' / ', $parts) : self::NOT_INFORMED;
    }

    private function constructionPeriod(Construction $construction): string
    {
        $start = $construction->construction_start_date?->format('d/m/Y');
        $end = $construction->construction_end_date?->format('d/m/Y');

        if ($start === null && $end === null) {
            return self::NOT_INFORMED;
        }

        return sprintf('%s — %s', $start ?? '—', $end ?? '—');
    }

    /**
     * Histórico de unidades (últimas competências).
     *
     * Cada linha é a posição **consolidada** da emissão naquela competência,
     * lida pelo mesmo {@see SalesBoardPositionReader} do painel principal.
     * Somar apenas os quadros da competência exata, como antes, apagava do mês
     * o empreendimento que não atualizou o quadro (GF-02): a linha caía sem que
     * nada tivesse sido vendido. Exibido apenas com ao menos duas competências.
     *
     * @param  list<CarbonImmutable>  $competences
     * @param  Collection<string, EmissionSalesPosition>  $positions
     * @return array<string, mixed>
     */
    private function buildUnitsHistory(array $competences, Collection $positions): array
    {
        $rows = [];
        $previousTotal = null;

        foreach ($competences as $competence) {
            $position = $positions->get($competence->format('Y-m'));

            if (! $position instanceof EmissionSalesPosition) {
                continue;
            }

            $rows[] = [
                'competencia' => $competence->format('m/Y'),
                'stock' => $this->integer($position->stockUnits),
                'financed' => $this->integer($position->financedUnits),
                'paid' => $this->integer($position->paidUnits),
                'exchanged' => $this->integer($position->exchangedUnits),
                'total' => $this->integer($position->totalUnits),
                'variation' => $this->countVariationLabel($previousTotal, $position->totalUnits),
                'composition' => $this->unitsComposition($position, labelled: false),
                'coverage' => $position->coverage(),
            ];

            $previousTotal = $position->totalUnits;
        }

        return [
            'has_data' => count($rows) >= 2,
            'rows' => $rows,
        ];
    }

    /**
     * Histórico de despesas por competência, a partir dos lançamentos de ExpenseHistory
     * (valor por data de vencimento). Cada linha é uma competência com lançamentos
     * efetivos — nada é inferido. Exibido apenas quando há ao menos duas competências.
     *
     * @return array<string, mixed>
     */
    private function buildExpensesHistory(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6): array
    {
        $histories = ExpenseHistory::query()
            ->whereHas('expense', fn (Builder $query): Builder => $query->where('emission_id', $emission->id))
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $monthEnd->toDateString())
            ->get();

        $months = $histories
            ->map(fn (ExpenseHistory $history): ?string => $history->due_date?->format('Y-m'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->slice(-$limit)
            ->values();

        $totals = [];
        foreach ($months as $ym) {
            $totals[$ym] = (float) $histories
                ->filter(fn (ExpenseHistory $history): bool => $history->due_date?->format('Y-m') === $ym)
                ->sum('amount');
        }

        $max = $totals === [] ? 0.0 : max($totals);
        $previous = null;
        $rows = [];

        foreach ($totals as $ym => $total) {
            $rows[] = [
                'competencia' => CarbonImmutable::parse($ym.'-01')->format('m/Y'),
                'total' => $this->money($total),
                'variation' => $this->moneyVariationLabel($previous, $total),
                'bar_percent' => $max > 0 ? round($total / $max * 100, 2) : 0.0,
            ];

            $previous = $total;
        }

        return [
            'has_data' => count($rows) >= 2,
            'rows' => $rows,
        ];
    }

    private function countVariationLabel(?int $previous, int $current): string
    {
        if ($previous === null) {
            return '—';
        }

        $delta = $current - $previous;

        if ($delta > 0) {
            return '+'.number_format($delta, 0, ',', '.');
        }

        return number_format($delta, 0, ',', '.');
    }

    private function moneyVariationLabel(?float $previous, float $current): string
    {
        if ($previous === null) {
            return '—';
        }

        $delta = $current - $previous;

        if (abs($delta) < 0.005) {
            return $this->money(0.0);
        }

        return ($delta > 0 ? '+' : '-').$this->money(abs($delta));
    }

    /**
     * Histórico de negociações (últimas competências) derivado dos contratos.
     * Fonte única: contracts.sale_date / cancellation_date.
     * Exibido apenas quando há ao menos duas competências com movimento.
     *
     * @return array<string, mixed>
     */
    private function buildNegotiationsHistory(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6): array
    {
        // Explicit source-of-truth: contracts only when emission is migrated
        $usesContracts = $emission->usesContractNegotiations();

        /**
         * Cada competência conta pela fonte dela: a publicada pelos movimentos
         * congelados da publicação vigente -- os fatos de competências
         * anteriores contam na competência em que foram publicados --, a não
         * publicada pelos contratos, sem os de permuta.
         */
        if ($usesContracts) {
            $allRows = $this->competenceNegotiationEvents->historyCounts($emission, $monthEnd, $limit);

            $hasMovementMonths = collect($allRows)->filter(fn (array $row): bool => $row['has'])->count();
            $rows = array_map(fn (array $row): array => [
                'competencia' => $row['competencia'],
                'sales' => $this->integer($row['sales']),
                'cancellations' => $this->integer($row['cancellations']),
                'net' => $this->integer($row['net']),
            ], $allRows);

            return [
                'has_data' => $hasMovementMonths >= 2,
                'rows' => $rows,
                'includes_late' => collect($allRows)->contains(fn (array $row): bool => $row['late'] > 0),
            ];
        }

        // Fallback: legacy manual Negotiation snapshots
        $negotiations = Negotiation::query()
            ->where('emission_id', $emission->id)
            ->where('reference_month', '<=', $monthEnd->toDateString())
            ->orderByDesc('reference_month')
            ->get();

        $months = $negotiations
            ->map(fn (Negotiation $negotiation): ?string => $negotiation->reference_month?->format('Y-m'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->slice(-$limit)
            ->values();

        $rows = $months->map(function (string $ym) use ($negotiations): array {
            $monthRows = $negotiations->filter(fn (Negotiation $negotiation): bool => $negotiation->reference_month?->format('Y-m') === $ym);
            $sales = (int) $monthRows->sum('sales');
            $cancellations = (int) $monthRows->sum('cancellations');

            return [
                'competencia' => CarbonImmutable::parse($ym.'-01')->format('m/Y'),
                'sales' => $this->integer($sales),
                'cancellations' => $this->integer($cancellations),
                'net' => $this->integer($sales - $cancellations),
            ];
        })->all();

        return [
            'has_data' => count($rows) >= 2,
            'rows' => $rows,
        ];
    }

    /**
     * Histórico de recebíveis e inadimplência (últimas competências) a partir dos
     * snapshots mensais de Receivable. Cada linha é uma competência efetivamente
     * cadastrada — nada é inferido. Exibido apenas quando há ao menos duas competências.
     *
     * @return array<string, mixed>
     */
    private function buildReceivablesHistory(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6): array
    {
        $receivables = Receivable::query()
            ->where('emission_id', $emission->id)
            ->where('reference_month', '<=', $monthEnd->toDateString())
            ->orderByDesc('reference_month')
            ->limit($limit)
            ->get()
            ->sortBy('reference_month')
            ->values();

        $rows = $receivables->map(function (Receivable $receivable): array {
            $expected = (float) $receivable->expected_interest_amount + (float) $receivable->expected_amortization_amount;
            $received = (float) $receivable->received_installment_interest_amount + (float) $receivable->received_installment_amortization_amount;

            return [
                'competencia' => $receivable->reference_month?->format('m/Y') ?? self::NOT_INFORMED,
                'expected' => $this->money($expected),
                'received' => $this->money($received),
                'received_percent' => $expected > 0.0 ? $this->percent(min(100.0, $received / $expected * 100)) : '—',
                'delinquency' => $this->money($this->overdueTotal($receivable)),
            ];
        })->all();

        return [
            'has_data' => count($rows) >= 2,
            'rows' => $rows,
            'empty_message' => self::NOT_CONSOLIDATED,
        ];
    }

    /**
     * Histórico de evolução da obra (últimas competências) por empreendimento, a partir
     * do provider de progresso. Inclui somente competências com medição efetiva no mês
     * (descarta carry-forward), evitando inferir evolução em meses sem medição.
     *
     * @param  Collection<int, Construction>  $constructions
     * @return array<string, mixed>
     */
    private function buildConstructionHistory(Emission $emission, CarbonImmutable $monthStart, Collection $constructions, int $window = 4): array
    {
        $months = $this->monthsWindow($monthStart, $window);
        $targets = $constructions->isNotEmpty() ? $constructions->all() : [null];
        $series = [];

        foreach ($targets as $construction) {
            $points = [];

            foreach ($months as $month) {
                $data = $this->constructionProgressProvider->forEmission($emission, $month, $construction);

                if ($data === null || $data->measurementDate === null) {
                    continue;
                }

                $measuredAt = CarbonImmutable::parse($data->measurementDate->toDateString());

                if ($measuredAt->lt($month) || $measuredAt->gt($month->endOfMonth())) {
                    continue;
                }

                $points[] = [
                    'competencia' => $month->format('m/Y'),
                    'planned_cumulative' => $this->percent($data->plannedCumulativePercent),
                    'realized_cumulative' => $this->percent($data->realizedCumulativePercent),
                    'bar_percent' => round(max(0.0, min(100.0, $data->realizedCumulativePercent)), 2),
                ];
            }

            if (count($points) >= 2) {
                $series[] = [
                    'name' => $construction?->development_name ?? 'Cronograma da emissão',
                    'points' => $points,
                ];
            }
        }

        return [
            'has_data' => $series !== [],
            'series' => $series,
        ];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function monthsWindow(CarbonImmutable $monthStart, int $count): array
    {
        $months = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $months[] = $monthStart->subMonthsNoOverflow($i);
        }

        return $months;
    }

    private function overdueTotal(Receivable $receivable): float
    {
        return (float) $receivable->overdue_up_to_30_days_amount
            + (float) $receivable->overdue_31_to_60_days_amount
            + (float) $receivable->overdue_61_to_90_days_amount
            + (float) $receivable->overdue_91_to_120_days_amount
            + (float) $receivable->overdue_121_to_150_days_amount
            + (float) $receivable->overdue_151_to_180_days_amount
            + (float) $receivable->overdue_181_to_360_days_amount
            + (float) $receivable->overdue_over_360_days_amount;
    }

    /**
     * Comentários/notas internos visíveis no relatório para a emissão e competência.
     *
     * @return array<string, mixed>
     */
    private function buildNotes(Emission $emission, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $notes = EmissionMonthlyReportNote::query()
            ->with('createdBy')
            ->where('emission_id', $emission->id)
            ->where('is_visible_on_report', true)
            ->whereBetween('reference_month', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rows = $notes->map(fn (EmissionMonthlyReportNote $note): array => [
            'category' => ($note->category !== null && $note->category !== '') ? $note->category : null,
            'title' => ($note->title !== null && $note->title !== '') ? $note->title : null,
            'content' => (string) $note->content,
            'author' => $note->createdBy?->name,
            'date' => $note->created_at?->format('d/m/Y'),
        ])->all();

        return [
            'has_data' => $rows !== [],
            'empty_message' => 'Nenhum comentário cadastrado para este período.',
            'rows' => $rows,
        ];
    }

    private function latestReceivable(Emission $emission, CarbonImmutable $start, CarbonImmutable $end): ?Receivable
    {
        return Receivable::query()
            ->where('emission_id', $emission->id)
            ->whereBetween('reference_month', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('reference_month')
            ->orderByDesc('id')
            ->first();
    }

    private function latestNegotiation(Emission $emission, CarbonImmutable $start, CarbonImmutable $end): ?Negotiation
    {
        return Negotiation::query()
            ->where('emission_id', $emission->id)
            ->whereBetween('reference_month', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('reference_month')
            ->orderByDesc('id')
            ->first();
    }

    private function lastPaymentUntil(Emission $emission, CarbonImmutable $monthEnd): ?Payment
    {
        return $emission->payments()
            ->where('payment_date', '<=', $monthEnd->toDateString())
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Data do próximo evento a partir do início do mês de referência. O
     * cronograma contratual (eventos de PU) é a fonte: ele traz inclusive a
     * amortização do vencimento, que a planilha de pagamentos pode não ter. Sem
     * eventos cadastrados, vale o Cronograma de Pagamentos.
     */
    private function nextEventDate(Emission $emission, CarbonImmutable $monthStart): ?CarbonImmutable
    {
        $event = $emission->puEvents()
            ->whereNotNull('effective_date')
            ->where('effective_date', '>=', $monthStart->toDateString())
            ->orderBy('effective_date')
            ->first();

        if ($event !== null) {
            return CarbonImmutable::instance($event->effective_date);
        }

        if ($emission->puEvents()->exists()) {
            return null;
        }

        $payment = $emission->payments()
            ->whereNotNull('payment_date')
            ->where('payment_date', '>=', $monthStart->toDateString())
            ->orderBy('payment_date')
            ->orderBy('id')
            ->first();

        return $payment?->payment_date !== null ? CarbonImmutable::instance($payment->payment_date) : null;
    }

    /**
     * Quantidade Integralizada da emissão. Trata ausência/zero como dado ainda
     * não cadastrado para evitar Saldo Devedor fixo em R$ 0,00.
     */
    private function integralizedQuantity(Emission $emission): ?int
    {
        $quantity = (int) ($emission->integralized_quantity ?? 0);

        return $quantity > 0 ? $quantity : null;
    }

    /**
     * Saldo Devedor do Resumo da Operação = PU da data-base × Quantidade
     * Integralizada. Sem PU ou sem quantidade, exibe fallback amigável.
     */
    private function resumoDebtBalance(?PuReading $pu, ?int $integralizedQuantity): string
    {
        if (! $pu instanceof PuReading || $integralizedQuantity === null) {
            return self::NOT_INFORMED;
        }

        return $this->money((float) $pu->unitValue * $integralizedQuantity);
    }

    /**
     * Próximos eventos a partir do início do mês de referência (cronograma da curva PU).
     *
     * @return Collection<int, EmissionPuEvent>
     */
    private function upcomingEventsFrom(Emission $emission, CarbonImmutable $monthStart, int $limit = 6): Collection
    {
        return $emission->puEvents()
            ->whereNotNull('effective_date')
            ->where('effective_date', '>=', $monthStart->toDateString())
            ->orderBy('effective_date')
            ->orderBy('sequence')
            ->limit($limit)
            ->get();
    }

    private function eventTypeLabel(?string $type): string
    {
        return match ($type) {
            'interest_payment' => 'Pagamento de Juros',
            'amortization' => 'Amortização',
            null, '' => self::NOT_INFORMED,
            default => $type,
        };
    }

    /**
     * Formata a amortização conforme o tipo registrado no evento:
     * percentage = fração aplicada ao PU (exibida como %); unit_value = PU em R$;
     * residual = saldo residual; none = sem amortização.
     */
    private function amortizationLabel(EmissionPuEvent $event): string
    {
        return match ($event->amortization_type) {
            'percentage' => $event->amortization_value !== null
                ? $this->percent((float) $event->amortization_value * 100)
                : self::NOT_INFORMED,
            'unit_value' => $event->amortization_value !== null
                ? $this->pu((float) $event->amortization_value)
                : self::NOT_INFORMED,
            'residual' => 'Saldo residual',
            'none', null, '' => '—',
            default => $event->amortization_value !== null ? (string) $event->amortization_value : '—',
        };
    }

    /**
     * PU da seção de saldo devedor na data-base; sem leitura, o PU atual
     * cadastrado na emissão -- só para emissão legada (sem curva de PU): numa
     * governada ele pode ter vindo de uma curva nunca homologada.
     */
    private function debtBalanceUnitValue(Emission $emission, ?PuReading $reading): ?string
    {
        return $reading?->unitValue
            ?? $this->puReader->legacyCurrentUnitValue($emission);
    }

    private function debtBalanceValue(Emission $emission, ?string $unitValue): ?float
    {
        $quantity = $emission->integralized_quantity ?: $emission->issued_quantity;

        if ($unitValue === null || $quantity === null) {
            return null;
        }

        return (float) $unitValue * (float) $quantity;
    }

    private function monthLabel(CarbonImmutable $month): string
    {
        $months = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ];

        return sprintf('%s de %d', $months[$month->month], $month->year);
    }

    private function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private function pu(float $value): string
    {
        return 'R$ '.number_format($value, 8, ',', '.');
    }

    private function percent(float $value): string
    {
        return number_format($value, 2, ',', '.').'%';
    }

    private function integer(int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return self::NOT_INFORMED;
        }

        return number_format((int) $value, 0, ',', '.');
    }

    private function date(?CarbonInterface $value): string
    {
        return $value?->format('d/m/Y') ?? self::NOT_INFORMED;
    }

    private function text(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::NOT_INFORMED;
        }

        return (string) $value;
    }

    private function yesNo(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::NOT_INFORMED;
        }

        if (in_array($value, [1, '1', true, 'Sim', 'sim', 'yes', 'true'], true)) {
            return 'Sim';
        }

        if (in_array($value, [0, '0', false, 'Não', 'nao', 'no', 'false'], true)) {
            return 'Não';
        }

        return (string) $value;
    }
}
