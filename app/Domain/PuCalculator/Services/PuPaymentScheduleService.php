<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cronograma de Pagamentos alimentado pela curva oficial.
 *
 * Todo pagamento que a curva oficial (versão operacional homologada) já
 * calculou passa a mostrar o valor dela; o valor que estava na linha -- da
 * planilha ou do cadastro manual -- fica guardado nas colunas `expected_*` como
 * previsto. Datas futuras continuam com o previsto até a curva chegar nelas. Uma
 * linha de planilha na data original de um evento que o calendário adiou é
 * movida para a data efetiva, em vez de duplicar o pagamento.
 *
 * A conciliação é determinística: o estado dos pagamentos é função da curva
 * oficial vigente e do previsto. Linha calculada por uma versão que deixou de
 * ser a oficial (ou que ela não cobre mais) volta ao valor previsto.
 *
 * Emissões com projeção legada ligada ficam de fora: `LegacyProjectionService`
 * já escreve nelas, e dois escritores na mesma tabela brigariam.
 */
final class PuPaymentScheduleService
{
    public const ACTION_RECONCILED = 'payments_reconciled';

    public const ACTION_LEGACY_PROJECTION = 'legacy_projection_owns_payments';

    public function __construct(
        private readonly EmissionPuReader $puReader,
        private readonly DecimalRounder $rounder,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * @return array{action: string, version: ?string, updated: int, created: int, moved: int, reverted: int, unmatched_dates: list<string>}
     */
    public function reconcile(Emission $emission, ?int $actorId = null): array
    {
        if ((bool) $emission->puParameter?->legacy_projection_enabled) {
            return $this->result(self::ACTION_LEGACY_PROJECTION, null);
        }

        return DB::transaction(function () use ($emission, $actorId): array {
            Emission::query()->whereKey($emission->id)->lockForUpdate()->first();
            $version = $this->puReader->officialVersion($emission, fresh: true);
            $rows = $version instanceof EmissionPuCurveVersion ? $this->paymentRows($version) : collect();
            $counts = ['updated' => 0, 'created' => 0, 'moved' => 0, 'reverted' => 0];
            $touched = [];

            foreach ($rows as $row) {
                [$payment, $how] = $this->paymentFor($emission, $row);
                $payment = $this->applyCurveValues($payment, $row, $version);

                if ($payment->isDirty() || ! $payment->exists) {
                    $payment->save();
                    $counts[$how]++;
                }

                $touched[] = $payment->id;
            }

            $counts['reverted'] = $this->revertStaleCalculations($emission, $touched);
            $unmatched = $this->unmatchedForecasts($emission, $rows);

            if (array_sum($counts) > 0) {
                $this->auditLog->logPaymentsReconciled($emission, $version?->calculation_version, $counts, $unmatched, $actorId);
            }

            return $this->result(self::ACTION_RECONCILED, $version?->calculation_version, $counts, $unmatched);
        });
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

    private function applyCurveValues(Payment $payment, EmissionPuDailyCurve $row, EmissionPuCurveVersion $version): Payment
    {
        if ($payment->exists && ! $payment->isCalculatedByOfficialCurve()) {
            foreach (Payment::VALUE_FIELDS as $field) {
                $payment->{'expected_'.$field} = $payment->getRawOriginal($field);
            }
        }

        $values = [
            'premium_value' => '0.00',
            'interest_value' => $this->rounder->round((string) $row->interest_payment_value, DecimalRounder::LEGACY_MONEY_SCALE),
            'amortization_value' => $this->rounder->round((string) $row->amortization_value, DecimalRounder::LEGACY_MONEY_SCALE),
            'extra_amortization_value' => '0.00',
            'value_source' => Payment::SOURCE_OFFICIAL_CURVE,
            'pu_curve_version_id' => $version->id,
        ];

        $payment->fill($values);

        if ($payment->isDirty()) {
            $payment->calculated_at = now();
        }

        return $payment;
    }

    /**
     * Linhas calculadas que a curva oficial vigente não cobre mais voltam ao
     * previsto que estava nelas.
     *
     * @param  list<int>  $touched
     */
    private function revertStaleCalculations(Emission $emission, array $touched): int
    {
        $stale = Payment::query()
            ->where('emission_id', $emission->id)
            ->where('value_source', Payment::SOURCE_OFFICIAL_CURVE)
            ->whereNotIn('id', $touched === [] ? [0] : $touched)
            ->get();

        foreach ($stale as $payment) {
            foreach (Payment::VALUE_FIELDS as $field) {
                $payment->{$field} = $payment->getRawOriginal('expected_'.$field) ?? '0.00';
                $payment->{'expected_'.$field} = null;
            }

            $payment->forceFill([
                'value_source' => null,
                'pu_curve_version_id' => null,
                'calculated_at' => null,
            ])->save();
        }

        return $stale->count();
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
     * @param  array{updated?: int, created?: int, moved?: int, reverted?: int}  $counts
     * @param  list<string>  $unmatched
     * @return array{action: string, version: ?string, updated: int, created: int, moved: int, reverted: int, unmatched_dates: list<string>}
     */
    private function result(string $action, ?string $version, array $counts = [], array $unmatched = []): array
    {
        return [
            'action' => $action,
            'version' => $version,
            'updated' => $counts['updated'] ?? 0,
            'created' => $counts['created'] ?? 0,
            'moved' => $counts['moved'] ?? 0,
            'reverted' => $counts['reverted'] ?? 0,
            'unmatched_dates' => $unmatched,
        ];
    }
}
