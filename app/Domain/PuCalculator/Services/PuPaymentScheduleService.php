<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\Payment;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cronograma de Pagamentos alimentado pela curva oficial.
 *
 * Único escritor da curva na tabela `payments`, e só a partir da versão
 * operacional HOMOLOGADA: gerar ou validar uma curva não toca pagamento nenhum.
 *
 * A curva é dona apenas do que calcula -- juros ordinários e amortização
 * ordinária ({@see Payment::CURVE_OWNED_FIELDS}). Para esses, todo pagamento que
 * a oficial já calculou passa a mostrar o valor dela e o valor que estava na
 * linha (planilha ou cadastro manual) fica nas colunas `expected_*` como
 * previsto. Prêmio e amortização extraordinária ({@see Payment::EXTERNAL_FIELDS})
 * nunca são escritos pela conciliação. Datas futuras continuam com o previsto até
 * a curva chegar nelas. Uma linha de planilha na data original de um evento que o
 * calendário adiou é movida para a data efetiva, em vez de duplicar o pagamento.
 *
 * Pagamento liquidado ({@see self::isSettled()}) é fato: a conciliação não o
 * recalcula nem o devolve ao previsto quando a oficial muda; se a nova oficial
 * calcula outro valor para ele, a divergência fica registrada na auditoria.
 *
 * A conciliação é determinística: o estado dos pagamentos não liquidados é
 * função da curva oficial vigente e do previsto. Linha calculada por uma versão
 * que deixou de ser a oficial (ou que ela não cobre mais) volta ao previsto.
 */
final class PuPaymentScheduleService
{
    public const ACTION_RECONCILED = 'payments_reconciled';

    public function __construct(
        private readonly EmissionPuReader $puReader,
        private readonly DecimalRounder $rounder,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * @return array{action: string, version: ?string, updated: int, created: int, moved: int, reverted: int, restored_components: int, settled_kept: int, unmatched_dates: list<string>, settled_divergent_dates: list<string>}
     */
    public function reconcile(Emission $emission, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($emission, $actorId): array {
            Emission::query()->whereKey($emission->id)->lockForUpdate()->first();
            $version = $this->puReader->officialVersion($emission, fresh: true);
            $rows = $version instanceof EmissionPuCurveVersion ? $this->paymentRows($version) : collect();
            $today = BusinessTime::dateString();
            $counts = ['updated' => 0, 'created' => 0, 'moved' => 0, 'reverted' => 0, 'restored_components' => 0, 'settled_kept' => 0];
            $settledDivergent = [];
            $touched = [];

            foreach ($rows as $row) {
                [$payment, $how] = $this->paymentFor($emission, $row);

                if ($payment->exists && $this->isSettled($payment, $today)) {
                    $counts['restored_components'] += $this->restoreExternalComponents($payment);

                    if ($payment->isDirty()) {
                        $payment->save();
                    }

                    if ($this->curveValuesDiffer($payment, $row)) {
                        $settledDivergent[] = $payment->payment_date->toDateString();
                    }

                    $counts['settled_kept']++;
                    $touched[] = $payment->id;

                    continue;
                }

                $counts['restored_components'] += $this->restoreExternalComponents($payment);
                $payment = $this->applyCurveValues($payment, $row, $version);

                if ($payment->isDirty() || ! $payment->exists) {
                    $payment->save();
                    $counts[$how]++;
                }

                $touched[] = $payment->id;
            }

            [$reverted, $settledKept, $restored] = $this->revertStaleCalculations($emission, $touched, $today);
            $counts['reverted'] = $reverted;
            $counts['settled_kept'] += $settledKept;
            $counts['restored_components'] += $restored;
            $unmatched = $this->unmatchedForecasts($emission, $rows);

            if ($counts['updated'] + $counts['created'] + $counts['moved'] + $counts['reverted'] + $counts['restored_components'] > 0
                || $settledDivergent !== []) {
                $this->auditLog->logPaymentsReconciled(
                    $emission,
                    $version?->calculation_version,
                    [...$counts, 'settled_divergent_dates' => $settledDivergent],
                    $unmatched,
                    $actorId,
                );
            }

            return $this->result(self::ACTION_RECONCILED, $version?->calculation_version, $counts, $unmatched, $settledDivergent);
        });
    }

    /**
     * Pagamento LIQUIDADO: calculado por uma curva que já era a oficial quando a
     * data do pagamento chegou (homologada até aquela data) e cuja data já
     * passou no calendário de negócio. É o valor que valia no dia em que o
     * pagamento aconteceu; uma homologação posterior não o reescreve, e invalidar
     * a curva que o calculou não o devolve ao previsto.
     *
     * Um recálculo retroativo -- a primeira homologação de uma curva cujo passado
     * já tinha pagamentos -- não é liquidação: a curva não valia naquele dia.
     */
    public function isSettled(Payment $payment, ?string $today = null): bool
    {
        if (! $payment->isCalculatedByOfficialCurve() || $payment->payment_date === null) {
            return false;
        }

        $paymentDate = $payment->payment_date->toDateString();

        if ($paymentDate >= ($today ?? BusinessTime::dateString())) {
            return false;
        }

        $homologatedAt = $payment->puCurveVersion?->homologated_at;

        return $homologatedAt !== null && BusinessTime::dateString($homologatedAt) <= $paymentDate;
    }

    /**
     * Linhas da curva em que houve pagamento (juros, amortização ou os dois).
     *
     * @return Collection<int, EmissionPuDailyCurve>
     */
    private function paymentRows(EmissionPuCurveVersion $version)
    {
        return EmissionPuDailyCurve::query()
            ->where('curve_version_id', $version->id)
            ->whereNotNull('event_effective_date')
            ->where('payment_total_unit_value', '>', 0)
            ->orderBy('curve_date')
            ->get();
    }

    /**
     * A linha que recebe o pagamento calculado: a da data efetiva; senão a da
     * data original ainda com valor previsto (movida); senão uma linha nova.
     *
     * @return array{0: Payment, 1: string}
     */
    private function paymentFor(Emission $emission, EmissionPuDailyCurve $row): array
    {
        $date = CarbonImmutable::parse((string) $row->curve_date)->toDateString();
        $payment = Payment::query()
            ->where('emission_id', $emission->id)
            ->whereDate('payment_date', $date)
            ->orderBy('id')
            ->first();

        if ($payment instanceof Payment) {
            return [$payment, 'updated'];
        }

        $originalDate = $row->event_original_date !== null
            ? CarbonImmutable::parse((string) $row->event_original_date)->toDateString()
            : null;

        if ($originalDate !== null && $originalDate !== $date) {
            $forecast = Payment::query()
                ->where('emission_id', $emission->id)
                ->whereDate('payment_date', $originalDate)
                ->whereNull('value_source')
                ->orderBy('id')
                ->first();

            if ($forecast instanceof Payment) {
                $forecast->payment_date = $date;

                return [$forecast, 'moved'];
            }
        }

        return [new Payment(['emission_id' => $emission->id, 'payment_date' => $date]), 'created'];
    }

    /**
     * Grava só os componentes que a curva calcula. O previsto desses
     * componentes vai para `expected_*` na primeira vez em que a curva assume a
     * linha; prêmio e amortização extraordinária ficam como estão.
     */
    private function applyCurveValues(Payment $payment, EmissionPuDailyCurve $row, EmissionPuCurveVersion $version): Payment
    {
        if ($payment->exists && ! $payment->isCalculatedByOfficialCurve()) {
            foreach (Payment::CURVE_OWNED_FIELDS as $field) {
                $payment->{'expected_'.$field} = $payment->getRawOriginal($field);
            }
        }

        $payment->fill([
            ...$this->curveValues($row),
            'value_source' => Payment::SOURCE_OFFICIAL_CURVE,
            'pu_curve_version_id' => $version->id,
        ]);

        if ($payment->isDirty()) {
            $payment->calculated_at = now();
        }

        return $payment;
    }

    /**
     * @return array{interest_value: string, amortization_value: string}
     */
    private function curveValues(EmissionPuDailyCurve $row): array
    {
        return [
            'interest_value' => $this->rounder->round((string) $row->interest_payment_value, DecimalRounder::LEGACY_MONEY_SCALE),
            'amortization_value' => $this->rounder->round((string) $row->amortization_value, DecimalRounder::LEGACY_MONEY_SCALE),
        ];
    }

    private function curveValuesDiffer(Payment $payment, EmissionPuDailyCurve $row): bool
    {
        foreach ($this->curveValues($row) as $field => $value) {
            if (bccomp((string) $payment->getRawOriginal($field), $value, 2) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reparo das linhas calculadas antes da Fase 2 de governança: a conciliação
     * de então zerava prêmio e amortização extraordinária e guardava o valor em
     * `expected_*`. Esses componentes nunca foram da curva, então o valor
     * guardado volta para a linha. Idempotente: sem `expected_` desses campos,
     * não há o que reparar.
     */
    private function restoreExternalComponents(Payment $payment): int
    {
        if (! $payment->exists || ! $payment->isCalculatedByOfficialCurve()) {
            return 0;
        }

        $restored = 0;

        foreach (Payment::EXTERNAL_FIELDS as $field) {
            $expected = $payment->getRawOriginal('expected_'.$field);

            if ($expected === null) {
                continue;
            }

            $payment->{$field} = $expected;
            $payment->{'expected_'.$field} = null;
            $restored++;
        }

        return $restored;
    }

    /**
     * Linhas calculadas que a curva oficial vigente não cobre mais voltam ao
     * previsto que estava nelas -- só nos componentes da curva. As liquidadas
     * ficam como estão.
     *
     * @param  list<int>  $touched
     * @return array{0: int, 1: int, 2: int}
     */
    private function revertStaleCalculations(Emission $emission, array $touched, string $today): array
    {
        $stale = Payment::query()
            ->with('puCurveVersion')
            ->where('emission_id', $emission->id)
            ->where('value_source', Payment::SOURCE_OFFICIAL_CURVE)
            ->whereNotIn('id', $touched === [] ? [0] : $touched)
            ->get();
        $reverted = 0;
        $settledKept = 0;
        $restored = 0;

        foreach ($stale as $payment) {
            $restored += $this->restoreExternalComponents($payment);

            if ($this->isSettled($payment, $today)) {
                if ($payment->isDirty()) {
                    $payment->save();
                }

                $settledKept++;

                continue;
            }

            foreach (Payment::CURVE_OWNED_FIELDS as $field) {
                $payment->{$field} = $payment->getRawOriginal('expected_'.$field) ?? '0.00';
                $payment->{'expected_'.$field} = null;
            }

            $payment->forceFill([
                'value_source' => null,
                'pu_curve_version_id' => null,
                'calculated_at' => null,
            ])->save();
            $reverted++;
        }

        return [$reverted, $settledKept, $restored];
    }

    /**
     * Datas previstas (planilha ou manual) dentro do período já calculado que
     * não correspondem a nenhum pagamento da curva: o previsto aponta um
     * pagamento que o cronograma contratual não tem.
     *
     * @param  Collection<int, EmissionPuDailyCurve>  $rows
     * @return list<string>
     */
    private function unmatchedForecasts(Emission $emission, $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $lastCalculated = CarbonImmutable::parse((string) $rows->last()->curve_date)->toDateString();

        return Payment::query()
            ->where('emission_id', $emission->id)
            ->whereNull('value_source')
            ->whereDate('payment_date', '<=', $lastCalculated)
            ->orderBy('payment_date')
            ->pluck('payment_date')
            ->map(fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString())
            ->values()
            ->all();
    }

    /**
     * @param  array{updated?: int, created?: int, moved?: int, reverted?: int, restored_components?: int, settled_kept?: int}  $counts
     * @param  list<string>  $unmatched
     * @param  list<string>  $settledDivergent
     * @return array{action: string, version: ?string, updated: int, created: int, moved: int, reverted: int, restored_components: int, settled_kept: int, unmatched_dates: list<string>, settled_divergent_dates: list<string>}
     */
    private function result(string $action, ?string $version, array $counts = [], array $unmatched = [], array $settledDivergent = []): array
    {
        return [
            'action' => $action,
            'version' => $version,
            'updated' => $counts['updated'] ?? 0,
            'created' => $counts['created'] ?? 0,
            'moved' => $counts['moved'] ?? 0,
            'reverted' => $counts['reverted'] ?? 0,
            'restored_components' => $counts['restored_components'] ?? 0,
            'settled_kept' => $counts['settled_kept'] ?? 0,
            'unmatched_dates' => $unmatched,
            'settled_divergent_dates' => $settledDivergent,
        ];
    }
}
