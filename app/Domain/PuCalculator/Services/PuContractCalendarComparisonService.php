<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Carbon\CarbonImmutable;

/**
 * Compara a curva oficial, calculada no calendário de mercado, com a mesma curva
 * contada no Dia Útil literal do Termo.
 *
 * A curva do contrato é calculada na hora e nunca gravada: é o simulador oficial
 * com a hipótese de accrual no calendário do Termo. Datas de pagamento, prêmio
 * pré-integralização e observação do CDI continuam na base oficial -- o CDI não é
 * divulgado em feriado de mercado, qualquer que seja o calendário do contrato.
 */
final class PuContractCalendarComparisonService
{
    private const SCALE = 8;

    public function __construct(
        private readonly PuBaselineCandidateFactory $candidates,
        private readonly PuSimulationService $simulations,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     reason: ?string,
     *     official_calendar: ?string,
     *     contract_calendar: ?string,
     *     calculation_version: ?string,
     *     from: ?string,
     *     to: ?string,
     *     days_compared: int,
     *     divergent_days: int,
     *     first_divergent_date: ?string,
     *     largest_difference: ?string,
     *     last_difference: ?string,
     *     rows: list<array{
     *         date: string,
     *         official_business_day: bool,
     *         contract_business_day: bool,
     *         official_unit_value: string,
     *         contract_unit_value: string,
     *         difference: string,
     *         official_payment: string,
     *         contract_payment: string,
     *     }>,
     * }
     */
    public function compare(Emission $emission): array
    {
        $version = $emission->currentPuCurveVersion();
        $officialCalendar = $emission->puParameter?->calendar_code;
        $contractCalendar = $this->contractCalendar($emission);

        if (! $version instanceof EmissionPuCurveVersion || $officialCalendar === null) {
            return $this->unavailable('Gere a curva oficial antes de comparar.', $officialCalendar, $contractCalendar);
        }

        if ($contractCalendar === null) {
            return $this->unavailable('O calendário do Termo não está confirmado em Instrumentos Jurídicos.', $officialCalendar, null);
        }

        if (BusinessCalendarRegistry::normalize($contractCalendar) === BusinessCalendarRegistry::normalize($officialCalendar)) {
            return $this->unavailable('A curva oficial já usa o calendário do Termo: não há diferença a comparar.', $officialCalendar, $contractCalendar);
        }

        /** @var array<string, EmissionPuDailyCurve> $official */
        $official = $version->dailyCurves()
            ->orderBy('curve_date')
            ->get()
            ->keyBy(fn (EmissionPuDailyCurve $row): string => CarbonImmutable::parse((string) $row->curve_date)->toDateString())
            ->all();

        if ($official === []) {
            return $this->unavailable('A curva oficial vigente não tem linhas.', $officialCalendar, $contractCalendar);
        }

        $from = array_key_first($official);
        $to = array_key_last($official);
        $simulation = $this->simulations->simulate($emission, new PuSimulationInput(
            firstIntegralizationDate: $emission->puParameter->curve_start_date !== null
                ? CarbonImmutable::instance($emission->puParameter->curve_start_date)
                : CarbonImmutable::parse($from),
            simulationEndDate: CarbonImmutable::parse($to),
            accrualCalendarCode: $contractCalendar,
        ));

        if (! $simulation->calculated()) {
            return $this->unavailable(
                'Não foi possível calcular a curva pelo calendário do Termo: '.$simulation->reason,
                $officialCalendar,
                $contractCalendar,
            );
        }

        $rows = [];
        $firstDivergentDate = null;
        $largestDifference = '0';

        foreach ($simulation->rows as $contractRow) {
            $date = $contractRow->date->toDateString();
            $officialRow = $official[$date] ?? null;

            if ($officialRow === null) {
                continue;
            }

            $difference = $this->difference($contractRow->updatedUnitValue, (string) $officialRow->updated_unit_value);

            if ($firstDivergentDate === null && bccomp($difference, '0', self::SCALE) !== 0) {
                $firstDivergentDate = $date;
            }

            if (bccomp($this->absolute($difference), $this->absolute($largestDifference), self::SCALE) === 1) {
                $largestDifference = $difference;
            }

            $rows[] = [
                'date' => $date,
                'official_business_day' => (bool) $officialRow->is_business_day,
                'contract_business_day' => $contractRow->isBusinessDay,
                'official_unit_value' => $this->scaled((string) $officialRow->updated_unit_value),
                'contract_unit_value' => $this->scaled($contractRow->updatedUnitValue),
                'difference' => $difference,
                'official_payment' => $this->scaled((string) $officialRow->payment_total_unit_value),
                'contract_payment' => $this->scaled($contractRow->paymentTotalUnitValue),
            ];
        }

        $divergentDays = count(array_filter(
            $rows,
            fn (array $row): bool => bccomp($row['difference'], '0', self::SCALE) !== 0,
        ));

        return [
            'available' => true,
            'reason' => null,
            'official_calendar' => $officialCalendar,
            'contract_calendar' => $contractCalendar,
            'calculation_version' => $version->calculation_version,
            'from' => $from,
            'to' => $to,
            'days_compared' => count($rows),
            'divergent_days' => $divergentDays,
            'first_divergent_date' => $firstDivergentDate,
            'largest_difference' => $largestDifference,
            'last_difference' => $rows === [] ? null : $rows[array_key_last($rows)]['difference'],
            'rows' => $rows,
        ];
    }

    private function contractCalendar(Emission $emission): ?string
    {
        if (! $this->candidates->supports($emission)) {
            return null;
        }

        return $this->candidates->make($emission, null)->contractCalendarCode;
    }

    private function difference(string $contract, string $official): string
    {
        return bcsub($this->scaled($contract), $this->scaled($official), self::SCALE);
    }

    private function scaled(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }

    private function absolute(string $value): string
    {
        return ltrim($value, '-');
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $reason, ?string $officialCalendar, ?string $contractCalendar): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'official_calendar' => $officialCalendar,
            'contract_calendar' => $contractCalendar,
            'calculation_version' => null,
            'from' => null,
            'to' => null,
            'days_compared' => 0,
            'divergent_days' => 0,
            'first_divergent_date' => null,
            'largest_difference' => null,
            'last_difference' => null,
            'rows' => [],
        ];
    }
}
