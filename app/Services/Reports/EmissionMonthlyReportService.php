<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\Expense;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\SalesBoard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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

    private const NO_DATA = 'Dados ainda não cadastrados';

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
        $nextEvent = $this->nextEventFrom($emission, $monthStart);

        return [
            'meta' => [
                'reference_label' => $this->monthLabel($monthStart),
                'reference_month' => $monthStart->format('m/Y'),
                'generated_at' => CarbonImmutable::now()->format('d/m/Y H:i'),
            ],
            'header' => $this->buildHeader($emission, $monthEnd, $nextEvent),
            'characteristics' => $this->buildCharacteristics($emission),
            'payment' => $this->buildPayment($payment),
            'calendar' => $this->buildCalendar($nextEvent),
            'debt_balance' => $this->buildDebtBalance($emission, $monthEnd),
            'accounts' => $this->buildAccounts($emission),
            'expenses' => $this->buildExpenses($emission, $monthStart, $monthEnd),
            'delinquency' => $this->buildDelinquency($receivable),
            'receivables' => $this->buildReceivablesSummary($receivable),
            'units' => $this->buildUnits($salesBoard),
            'negotiations' => $this->buildNegotiations($negotiation),
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

        return [
            'has_data' => true,
            'payment_date' => $this->date($payment->payment_date),
            'rows' => [
                ['label' => 'Prêmio', 'value' => $this->money((float) $payment->premium_value)],
                ['label' => 'Juros', 'value' => $this->money((float) $payment->interest_value)],
                ['label' => 'Amortização', 'value' => $this->money((float) $payment->amortization_value)],
                ['label' => 'Amortização Extraordinária', 'value' => $this->money((float) $payment->extra_amortization_value)],
            ],
            // V2: desdobramento Ordinário x Extraordinário em PU e "Juros Extraordinários" (campos ainda inexistentes no model Payment).
            'note' => 'Desdobramento completo (ordinário/extraordinário em PU) previsto para a V2.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCalendar(?EmissionPuEvent $nextEvent): array
    {
        if ($nextEvent === null) {
            return ['has_data' => false, 'empty_message' => self::NO_DATA];
        }

        return [
            'has_data' => true,
            'rows' => [
                ['label' => 'Próximo evento', 'value' => $this->date($nextEvent->effective_date)],
                ['label' => 'Tipo de evento', 'value' => $this->text($nextEvent->event_type)],
                ['label' => 'Valor de amortização (PU)', 'value' => $nextEvent->amortization_value !== null ? $this->pu((float) $nextEvent->amortization_value) : self::NOT_INFORMED],
                ['label' => 'Descrição', 'value' => $this->text($nextEvent->description)],
            ],
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
            $rows[] = [
                'label' => $label,
                'value' => $this->money($value),
                'percent' => $total > 0 ? $this->percent($value / $total * 100) : '0,00%',
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

        return [
            'has_data' => true,
            'rows' => [
                ['label' => 'Estoque', 'value' => $this->integer($salesBoard->stock_units)],
                ['label' => 'Financiadas/Vendidas', 'value' => $this->integer($salesBoard->financed_units)],
                ['label' => 'Quitadas', 'value' => $this->integer($salesBoard->paid_units)],
                ['label' => 'Permutadas', 'value' => $this->integer($salesBoard->exchanged_units)],
                ['label' => 'Total', 'value' => $this->integer($salesBoard->total_units)],
            ],
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

    private function nextEventFrom(Emission $emission, CarbonImmutable $monthStart): ?EmissionPuEvent
    {
        return $emission->puEvents()
            ->whereNotNull('effective_date')
            ->where('effective_date', '>=', $monthStart->toDateString())
            ->orderBy('effective_date')
            ->orderBy('sequence')
            ->first();
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
