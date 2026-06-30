<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\DTOs\ConstructionProgressData;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\EmissionMonthlyReportNote;
use App\Models\EmissionPuEvent;
use App\Models\Expense;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Services\ConstructionProgressProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    public function __construct(
        private readonly ConstructionProgressProvider $constructionProgressProvider,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Emission $emission, CarbonInterface $referenceMonth): array
    {
        $monthStart = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $emission->loadMissing(['funds.bank', 'funds.fundType', 'funds.fundName']);

        $receivable = $this->latestReceivable($emission, $monthStart, $monthEnd);
        $salesBoard = $this->latestSalesBoard($emission, $monthStart, $monthEnd);
        $negotiation = $this->latestNegotiation($emission, $monthStart, $monthEnd);
        $payment = $this->lastPaymentUntil($emission, $monthEnd);
        $upcomingEvents = $this->upcomingEventsFrom($emission, $monthStart);
        $nextEvent = $upcomingEvents->first();
        $constructions = $emission->constructions()->orderBy('development_name')->get();

        return [
            'meta' => [
                'reference_label' => $this->monthLabel($monthStart),
                'reference_month' => $monthStart->format('m/Y'),
                'generated_at' => CarbonImmutable::now()->format('d/m/Y H:i'),
            ],
            'header' => $this->buildHeader($emission, $monthEnd, $nextEvent),
            'characteristics' => $this->buildCharacteristics($emission),
            'payment' => $this->buildPayment($payment),
            'calendar' => $this->buildCalendar($upcomingEvents),
            'debt_balance' => $this->buildDebtBalance($emission, $monthEnd),
            'accounts' => $this->buildAccounts($emission),
            'expenses' => $this->buildExpenses($emission, $monthStart, $monthEnd),
            'delinquency' => $this->buildDelinquency($receivable),
            'receivables' => $this->buildReceivablesSummary($receivable),
            'units' => $this->buildUnits($salesBoard),
            'units_history' => $this->buildUnitsHistory($emission, $monthEnd),
            'negotiations' => $this->buildNegotiations($negotiation),
            'negotiations_history' => $this->buildNegotiationsHistory($emission, $monthEnd),
            'analise_mes' => $this->buildAnaliseMes($receivable),
            'receivables_history' => $this->buildReceivablesHistory($emission, $monthEnd),
            'construction' => $this->buildConstructionProgress($emission, $monthStart, $constructions),
            'construction_history' => $this->buildConstructionHistory($emission, $monthStart, $constructions),
            'notes' => $this->buildNotes($emission, $monthStart, $monthEnd),
        ];
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
     * @return array<string, mixed>
     */
    private function buildHeader(Emission $emission, CarbonImmutable $monthEnd, ?EmissionPuEvent $nextEvent): array
    {
        $debt = $this->debtBalanceValue($emission);

        return [
            'name' => $this->text($emission->name),
            'identifier' => $this->text($emission->isin_code ?? $emission->if_code),
            'offer' => $this->text($emission->type ?? $emission->offer_type),
            'debt_balance' => $debt !== null ? $this->money($debt) : self::NOT_AVAILABLE,
            'debt_position' => $monthEnd->format('d/m/Y'),
            'circulating_quantity' => $this->integer($emission->integralized_quantity ?: $emission->issued_quantity),
            'remuneration' => $this->text($emission->formatted_remuneration),
            'current_pu' => $emission->current_pu !== null ? $this->pu((float) $emission->current_pu) : self::NOT_AVAILABLE,
            'next_event' => $nextEvent?->effective_date?->format('d/m/Y') ?? self::NOT_INFORMED,
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
        $debt = $this->debtBalanceValue($emission);

        return [
            ['label' => 'Quantidade em circulação', 'value' => $this->integer($emission->integralized_quantity ?: $emission->issued_quantity)],
            ['label' => 'Preço unitário (emissão)', 'value' => $emission->current_pu !== null ? $this->pu((float) $emission->current_pu) : self::NOT_AVAILABLE],
            ['label' => 'Saldo devedor do CRI', 'value' => $debt !== null ? $this->money($debt) : self::NOT_AVAILABLE],
            ['label' => 'Posição em', 'value' => $monthEnd->format('d/m/Y')],
        ];
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
     * @return array<string, mixed>
     */
    private function buildUnits(?SalesBoard $salesBoard): array
    {
        if ($salesBoard === null) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        $stock = (int) $salesBoard->stock_units;
        $financed = (int) $salesBoard->financed_units;
        $paid = (int) $salesBoard->paid_units;
        $exchanged = (int) $salesBoard->exchanged_units;
        $base = $stock + $financed + $paid + $exchanged;

        $composition = [];
        if ($base > 0) {
            $composition = [
                ['label' => 'Quitadas', 'class' => 'seg-1', 'percent' => round($paid / $base * 100, 2)],
                ['label' => 'Financiadas/Vendidas', 'class' => 'seg-2', 'percent' => round($financed / $base * 100, 2)],
                ['label' => 'Permutadas', 'class' => 'seg-3', 'percent' => round($exchanged / $base * 100, 2)],
                ['label' => 'Estoque', 'class' => 'seg-4', 'percent' => round($stock / $base * 100, 2)],
            ];
        }

        return [
            'has_data' => true,
            'rows' => [
                ['label' => 'Estoque', 'value' => $this->integer($salesBoard->stock_units)],
                ['label' => 'Financiadas/Vendidas', 'value' => $this->integer($salesBoard->financed_units)],
                ['label' => 'Quitadas', 'value' => $this->integer($salesBoard->paid_units)],
                ['label' => 'Permutadas', 'value' => $this->integer($salesBoard->exchanged_units)],
                ['label' => 'Total', 'value' => $this->integer($salesBoard->total_units)],
            ],
            'composition' => $composition,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNegotiations(?Negotiation $negotiation): array
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
            'realized_monthly' => $this->percent($data->realizedMonthlyPercent),
            'diff' => $this->percent($data->diffPercent),
            'trend' => $data->trend ?? '—',
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
     * Histórico de unidades (últimas competências) a partir dos snapshots de SalesBoard,
     * agregando por competência (soma dos empreendimentos). Sem inferências. Exibido
     * apenas quando há ao menos duas competências.
     *
     * @return array<string, mixed>
     */
    private function buildUnitsHistory(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6): array
    {
        $boards = SalesBoard::query()
            ->where('emission_id', $emission->id)
            ->where('reference_month', '<=', $monthEnd->toDateString())
            ->orderByDesc('reference_month')
            ->get();

        $months = $boards
            ->map(fn (SalesBoard $board): ?string => $board->reference_month?->format('Y-m'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->slice(-$limit)
            ->values();

        $rows = $months->map(function (string $ym) use ($boards): array {
            $monthBoards = $boards->filter(fn (SalesBoard $board): bool => $board->reference_month?->format('Y-m') === $ym);

            return [
                'competencia' => CarbonImmutable::parse($ym.'-01')->format('m/Y'),
                'stock' => $this->integer((int) $monthBoards->sum('stock_units')),
                'financed' => $this->integer((int) $monthBoards->sum('financed_units')),
                'paid' => $this->integer((int) $monthBoards->sum('paid_units')),
                'exchanged' => $this->integer((int) $monthBoards->sum('exchanged_units')),
                'total' => $this->integer((int) $monthBoards->sum('total_units')),
            ];
        })->all();

        return [
            'has_data' => count($rows) >= 2,
            'rows' => $rows,
        ];
    }

    /**
     * Histórico de negociações (últimas competências) a partir dos snapshots de
     * Negotiation, agregando por competência. Apenas contagens (vendas/distratos): a
     * tabela não possui valor monetário negociado, então nada é inferido nesse sentido.
     * Exibido apenas quando há ao menos duas competências.
     *
     * @return array<string, mixed>
     */
    private function buildNegotiationsHistory(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6): array
    {
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

    private function latestSalesBoard(Emission $emission, CarbonImmutable $start, CarbonImmutable $end): ?SalesBoard
    {
        return SalesBoard::query()
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

    private function debtBalanceValue(Emission $emission): ?float
    {
        $quantity = $emission->integralized_quantity ?: $emission->issued_quantity;

        if ($emission->current_pu === null || $quantity === null) {
            return null;
        }

        return (float) $emission->current_pu * (float) $quantity;
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
