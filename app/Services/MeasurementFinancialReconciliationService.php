<?php

namespace App\Services;

use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\ValueObjects\Decimal;
use App\DTOs\Measurements\MeasurementFinancialReconciliation;
use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\Enums\MeasurementReconciliationStatus;
use App\Models\Measurement;
use App\Models\MeasurementPayment;

/**
 * Referência financeira da medição: liga o percentual realizado aprovado pela
 * Engenharia ao valor monetário registrado na etapa de Pagamento.
 *
 * A base é sempre o `engineering_snapshot` congelado na aprovação da
 * Engenharia -- nunca o plano atual. Alterar o fundo de obra depois da
 * aprovação não pode reescrever retroativamente a referência de uma medição
 * já aprovada, e é por isso que o serviço não consulta `MeasurementPlanSet`.
 *
 * Nesta V1 a divergência é informativa: nada aqui bloqueia registro,
 * aprovação ou finalização.
 */
class MeasurementFinancialReconciliationService
{
    /** Escala monetária do domínio: `decimal(18,2)` em `measurement_payments`. */
    public const MONEY_SCALE = 2;

    /** Escala percentual de exibição da divergência relativa. */
    public const PERCENT_SCALE = 2;

    private const CALCULATION_SCALE = 16;

    private const PERCENT_BASE = '100';

    public function __construct(private DecimalRounder $rounder) {}

    /**
     * @param  array<int|string, mixed>  $enteredAmounts  valores informados agora, indexados por `plan_set_id`
     */
    public function forMeasurement(Measurement $measurement, array $enteredAmounts = []): MeasurementFinancialReconciliation
    {
        $planSets = $this->snapshotPlanSets($measurement);
        $registeredByPlanSet = $this->registeredAmountsByPlanSet($measurement);

        $lines = [];

        foreach ($planSets as $planSet) {
            $planSetId = (int) ($planSet['plan_set_id'] ?? 0);

            if ($planSetId === 0) {
                continue;
            }

            $lines[] = $this->buildLine(
                $planSet,
                $registeredByPlanSet[$planSetId] ?? $this->zero(),
                $enteredAmounts[$planSetId] ?? null,
            );
        }

        return $this->aggregate((int) $measurement->getKey(), $lines);
    }

    /**
     * Valor esperado de um empreendimento nesta medição: fundo de obra do
     * snapshot vezes o percentual realizado da própria competência.
     *
     * A conversão do percentual vive aqui e em nenhum outro lugar.
     */
    public function expectedAmount(?string $fundAmount, string $realizedMonthlyPercent): ?string
    {
        if ($fundAmount === null) {
            return null;
        }

        $raw = Decimal::of($fundAmount)
            ->multiply(Decimal::of($realizedMonthlyPercent), self::CALCULATION_SCALE)
            ->divide(Decimal::of(self::PERCENT_BASE), self::CALCULATION_SCALE)
            ->value();

        return $this->money($raw);
    }

    /**
     * Normaliza um valor monetário informado -- inclusive mascarado em pt-BR --
     * para string decimal, sem passar por float binário.
     */
    public function normalizeAmount(mixed $value): string
    {
        if ($value === null) {
            return $this->zero();
        }

        if (is_int($value) || is_float($value)) {
            return $this->money(Decimal::of($value)->value());
        }

        $normalized = str_replace(['R$', ' ', "\u{00A0}"], '', trim((string) $value));

        if ($normalized === '') {
            return $this->zero();
        }

        if (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', str_replace('.', '', $normalized));
        } elseif (str_contains($normalized, '.')) {
            $parts = explode('.', $normalized);

            if (count($parts) > 2 || mb_strlen((string) end($parts)) === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        if (! is_numeric($normalized)) {
            return $this->zero();
        }

        return $this->money($normalized);
    }

    /**
     * Exibe uma string decimal como moeda pt-BR sem passar por float.
     *
     * O serviço detém a representação decimal, então também detém a forma de
     * mostrá-la: `number_format()` exigiria converter para float justamente o
     * valor que o cálculo tomou o cuidado de manter exato.
     */
    public static function formatCurrency(?string $value): string
    {
        if ($value === null) {
            return '—';
        }

        $negative = str_starts_with($value, '-');
        [$integer, $decimals] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '00');
        $grouped = strrev(implode('.', str_split(strrev($integer), 3)));

        return sprintf('%sR$ %s,%s', $negative ? '-' : '', $grouped, str_pad($decimals, 2, '0'));
    }

    public static function formatPercent(?string $value): string
    {
        return $value === null ? '—' : str_replace('.', ',', $value).'%';
    }

    /**
     * @param  array<string, mixed>  $planSet
     */
    private function buildLine(array $planSet, string $registeredAmount, mixed $enteredAmount): MeasurementFinancialReconciliationLine
    {
        $fundAmount = $this->nullableMoney($planSet['construction_fund_amount'] ?? null);
        $realizedMonthlyPercent = $this->percent($planSet['realized_monthly_percent'] ?? '0');
        $expectedAmount = $this->expectedAmount($fundAmount, $realizedMonthlyPercent);
        $expectedBalance = $expectedAmount === null
            ? null
            : $this->subtract($expectedAmount, $registeredAmount);
        $entered = $this->normalizeAmount($enteredAmount);
        $divergenceAmount = $expectedBalance === null
            ? null
            : $this->subtract($entered, $expectedBalance);

        return new MeasurementFinancialReconciliationLine(
            planSetId: (int) $planSet['plan_set_id'],
            constructionId: isset($planSet['construction_id']) && $planSet['construction_id'] !== null
                ? (int) $planSet['construction_id']
                : null,
            label: $this->label($planSet),
            fundAmount: $fundAmount,
            realizedMonthlyPercent: $realizedMonthlyPercent,
            expectedAmount: $expectedAmount,
            registeredAmount: $registeredAmount,
            expectedBalance: $expectedBalance,
            enteredAmount: $entered,
            divergenceAmount: $divergenceAmount,
            divergencePercent: $this->divergencePercent($divergenceAmount, $expectedBalance),
            status: $this->status($divergenceAmount),
        );
    }

    /**
     * @param  array<int, MeasurementFinancialReconciliationLine>  $lines
     */
    private function aggregate(int $measurementId, array $lines): MeasurementFinancialReconciliation
    {
        $registeredAmount = $this->zero();
        $enteredAmount = $this->zero();
        $expectedAmount = null;
        $expectedBalance = null;
        $divergenceAmount = null;
        $referenceComplete = $lines !== [];

        foreach ($lines as $line) {
            $registeredAmount = $this->add($registeredAmount, $line->registeredAmount);
            $enteredAmount = $this->add($enteredAmount, $line->enteredAmount);

            if (! $line->hasFinancialReference()) {
                $referenceComplete = false;

                continue;
            }

            $expectedAmount = $this->add($expectedAmount ?? $this->zero(), (string) $line->expectedAmount);
            $expectedBalance = $this->add($expectedBalance ?? $this->zero(), (string) $line->expectedBalance);
            $divergenceAmount = $this->add($divergenceAmount ?? $this->zero(), (string) $line->divergenceAmount);
        }

        return new MeasurementFinancialReconciliation(
            measurementId: $measurementId,
            lines: $lines,
            referenceComplete: $referenceComplete,
            expectedAmount: $expectedAmount,
            registeredAmount: $registeredAmount,
            expectedBalance: $expectedBalance,
            enteredAmount: $enteredAmount,
            divergenceAmount: $divergenceAmount,
            divergencePercent: $this->divergencePercent($divergenceAmount, $expectedBalance),
            status: $referenceComplete
                ? $this->status($divergenceAmount)
                : MeasurementReconciliationStatus::ReferenceUnavailable,
        );
    }

    /**
     * Soma dos pagamentos desta MESMA medição por empreendimento.
     *
     * O escopo é deliberadamente a competência atual: o acumulado do fundo de
     * obra ao longo de várias medições é outra pergunta, respondida por
     * `MeasurementPlanSet::incurred_amount`.
     *
     * @return array<int, string>
     */
    private function registeredAmountsByPlanSet(Measurement $measurement): array
    {
        $measurement->loadMissing('payments');

        $totals = [];

        foreach ($measurement->payments as $payment) {
            /** @var MeasurementPayment $payment */
            if ($payment->plan_set_id === null) {
                continue;
            }

            $planSetId = (int) $payment->plan_set_id;
            $totals[$planSetId] = $this->add($totals[$planSetId] ?? $this->zero(), $this->money((string) $payment->amount));
        }

        return $totals;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshotPlanSets(Measurement $measurement): array
    {
        $snapshot = $measurement->engineering_snapshot;

        if (! is_array($snapshot) || ! is_array($snapshot['plan_sets'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $snapshot['plan_sets'],
            fn (mixed $planSet): bool => is_array($planSet),
        ));
    }

    /**
     * @param  array<string, mixed>  $planSet
     */
    private function label(array $planSet): string
    {
        $constructionName = $planSet['construction_name'] ?? null;

        if (is_string($constructionName) && $constructionName !== '') {
            return $constructionName;
        }

        return (string) ($planSet['plan_set_name'] ?? '—');
    }

    private function divergencePercent(?string $divergenceAmount, ?string $expectedBalance): ?string
    {
        if ($divergenceAmount === null
            || $expectedBalance === null
            || bccomp($expectedBalance, '0', self::MONEY_SCALE) === 0) {
            return null;
        }

        $raw = Decimal::of($divergenceAmount)
            ->divide(Decimal::of($expectedBalance), self::CALCULATION_SCALE)
            ->multiply(Decimal::of(self::PERCENT_BASE), self::CALCULATION_SCALE)
            ->value();

        return $this->rounder->round($raw, self::PERCENT_SCALE);
    }

    private function status(?string $divergenceAmount): MeasurementReconciliationStatus
    {
        if ($divergenceAmount === null) {
            return MeasurementReconciliationStatus::ReferenceUnavailable;
        }

        return match (bccomp($divergenceAmount, '0', self::MONEY_SCALE)) {
            0 => MeasurementReconciliationStatus::Matched,
            -1 => MeasurementReconciliationStatus::Under,
            default => MeasurementReconciliationStatus::Over,
        };
    }

    private function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::MONEY_SCALE);
    }

    private function subtract(string $left, string $right): string
    {
        return bcsub($left, $right, self::MONEY_SCALE);
    }

    private function money(string $value): string
    {
        return $this->rounder->round($value, self::MONEY_SCALE);
    }

    private function nullableMoney(mixed $value): ?string
    {
        return $value === null ? null : $this->money(Decimal::of(is_string($value) ? $value : (string) $value)->value());
    }

    private function percent(mixed $value): string
    {
        return $this->rounder->round(Decimal::of(is_string($value) ? $value : (string) $value)->value(), self::PERCENT_SCALE);
    }

    private function zero(): string
    {
        return $this->money('0');
    }
}
