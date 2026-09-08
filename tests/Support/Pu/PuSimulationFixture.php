<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\IntegralizationHistory;
use App\Models\Payment;
use App\Models\PuHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Cenário sintético da Calculadora de PU em modo simulação (2B.6.1).
 *
 * Reproduz deliberadamente o estado REAL do Alto Bellevue no ponto em que esta
 * fase precisa ser útil: baseline contratual comprovada, calendário confirmado,
 * taxas locais carregadas -- e NENHUMA evidência de primeira integralização,
 * nenhum `EmissionPuParameter`, nenhuma candidate e nenhuma curva operacional.
 * Ou seja: Gate C bloqueado, simulação possível.
 *
 * As datas usadas aqui (15/05/2026 como integralização hipotética, 30/06/2026
 * como fim de janela) são HIPÓTESES DE TESTE. Não são evidência, não são default
 * de produção e não existem em nenhum arquivo de `app/`.
 */
final class PuSimulationFixture
{
    public const CALENDAR_CODE = BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS;

    /** Hipótese de primeira integralização usada apenas no teste. */
    private const INTEGRALIZATION_DATE = '2026-05-15';

    /** Fim da janela curta de teste. */
    private const WINDOW_END_DATE = '2026-06-30';

    private const CDI_RATE = '14.90000000';

    private const FIRST_INTEREST_PAYMENT_DATE = '2026-06-08';

    private const MATURITY_DATE = '2031-05-08';

    public static function integralizationDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::INTEGRALIZATION_DATE)->startOfDay();
    }

    public static function windowEndDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::WINDOW_END_DATE)->startOfDay();
    }

    /**
     * Emissão com baseline contratual confirmada e calendário confirmado, mas
     * sem evidência de primeira integralização: exatamente o Alto real.
     */
    public static function contractualEmission(): Emission
    {
        $emission = PuCandidateGovernanceFixture::emission();
        PuCandidateGovernanceFixture::proveBaseline($emission);
        PuCandidateGovernanceFixture::confirmCalendar();

        return $emission->fresh();
    }

    /**
     * Cenário completo e calculável: baseline + calendário + taxas locais, com a
     * primeira integralização informada APENAS como hipótese de simulação.
     *
     * @return array{emission:Emission, input:PuSimulationInput}
     */
    public static function calculableScenario(): array
    {
        $emission = self::contractualEmission();
        self::seedRequiredRates();

        return [
            'emission' => $emission,
            'input' => new PuSimulationInput(
                firstIntegralizationDate: self::integralizationDate(),
                simulationEndDate: self::windowEndDate(),
            ),
        ];
    }

    /**
     * Emissão sem qualquer baseline contratual: prova que a calculadora funciona
     * inteiramente por hipóteses informadas à mão, e que declara não ter
     * resolvido cronograma de eventos em vez de simular cupons inexistentes.
     */
    public static function bareEmission(): Emission
    {
        PuCandidateGovernanceFixture::confirmCalendar();
        // O conjunto exigido com prêmio habilitado é superconjunto do conjunto
        // sem prêmio (mesma janela de curva), então este seed cobre também os
        // overrides manuais, que desabilitam o prêmio.
        self::seedRequiredRates();

        return PuCandidateGovernanceFixture::emission()->fresh();
    }

    /**
     * Conjunto mínimo de overrides que torna uma emissão sem baseline
     * calculável. São hipóteses de teste, não configuração de produção.
     *
     * @return array<string, string>
     */
    public static function manualOverrides(): array
    {
        return [
            'indexer' => PuIndexer::Cdi->value,
            'spread_rate' => '0.06000000',
            'business_day_basis' => '252',
            'calendar_code' => self::CALENDAR_CODE,
            'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
            'index_rate_lag_business_days' => '-5',
            'initial_unit_value' => '1000.0000000000000000',
            'curve_end_date' => self::WINDOW_END_DATE,
            'first_coupon_pre_integralization_premium_enabled' => '0',
        ];
    }

    /**
     * Semeia EXATAMENTE as taxas que a configuração simulada exige.
     *
     * As datas não vêm de um intervalo arbitrário: são resolvidas pelo
     * `PuIndexRateRequirementResolver` oficial sobre o parâmetro sintético --
     * o mesmo resolver que a engine usa. Isso inclui, por construção, a cauda
     * do prêmio pré-integralização, que um intervalo escolhido à mão erra com
     * facilidade.
     *
     * NÃO usa `PuSimulationService`: o fixture depende de um resolver inferior,
     * nunca do serviço sob teste.
     *
     * A escrita é feita PELO MODEL (`firstOrCreate`), e não por `insert()` cru,
     * por dois motivos:
     *
     *  1. Formato de armazenamento. O cast `date` do Eloquent serializa com
     *     `Y-m-d H:i:s`, então toda linha de produção grava `2026-05-06 00:00:00`.
     *     Um `insert()` cru gravava `2026-05-06`, e no SQLite a comparação é
     *     textual: `'2026-05-06' < '2026-05-06 00:00:00'`. O `whereBetween` do
     *     `PuIndexSnapshotPlanService` (limite inferior em `startOfDay()`) então
     *     descartava justamente a PRIMEIRA data exigida da janela, que aparecia
     *     como taxa ausente mesmo estando fisicamente no banco.
     *  2. Idempotência. `firstOrCreate` permite que dois helpers garantam a
     *     mesma taxa sintética sem violar `unique(indexer, rate_date)`.
     *
     * @return list<string> datas efetivamente exigidas e garantidas
     */
    public static function seedRequiredRates(
        ?CarbonImmutable $integralizationDate = null,
        ?CarbonImmutable $windowEnd = null,
    ): array {
        $parameter = self::syntheticParameter($integralizationDate, $windowEnd);
        $resolver = app(PuIndexRateRequirementResolver::class);
        $start = CarbonImmutable::instance($parameter->curve_start_date)->startOfDay();
        $end = CarbonImmutable::instance($parameter->curve_end_date)->startOfDay();
        $required = [];

        // Cauda do prêmio: os Dias Úteis anteriores à integralização e o lag
        // aplicado sobre cada um deles.
        foreach ($resolver->firstCouponPreIntegralizationRateRequirements($parameter) as $requirement) {
            $requiredDate = $requirement->requiredRateDate();

            if ($requiredDate instanceof CarbonImmutable) {
                $required[$requiredDate->toDateString()] = $requiredDate;
            }
        }

        // Janela da curva, exatamente como a engine a percorre.
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $requirement = $resolver->resolve($parameter, $date);

            if (! $requirement->isRequiredForCalculation()) {
                continue;
            }

            $requiredDate = $requirement->requiredRateDate();

            if ($requiredDate instanceof CarbonImmutable) {
                $required[$requiredDate->toDateString()] = $requiredDate;
            }
        }

        ksort($required);

        foreach ($required as $requiredDate) {
            self::ensureRate($requiredDate);
        }

        // O resolver acima consultou o provider ANTES do seed e memoizou a
        // timeline vazia; sem o flush a simulação leria o estado pré-seed.
        app(IndexRateLookupService::class)->flushCache();

        return array_keys($required);
    }

    /**
     * Garante uma taxa sintética numa data, sem colidir e sem sobrescrever.
     *
     * Um cenário que queira representar deliberadamente "mesma data, valor
     * diferente" NÃO usa este helper: ele atualiza a linha existente de forma
     * explícita (veja `PuSimulationRateSyncTest`), para que a intenção do
     * conflito econômico continue visível no teste.
     */
    public static function ensureRate(CarbonImmutable $date, ?string $value = null): IndexRate
    {
        return IndexRate::query()->firstOrCreate(
            [
                'indexer' => PuIndexer::Cdi->value,
                // Carbon (e não string) para que o `where` e o `create` usem a
                // mesma serialização do cast `date`.
                'rate_date' => $date->startOfDay(),
            ],
            [
                'rate_value' => $value ?? self::CDI_RATE,
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
                'external_series_code' => '4389',
                'is_projected' => false,
            ],
        );
    }

    /**
     * Parâmetro sintético do Alto, montado a partir de constantes explícitas.
     *
     * É a única fonte de verdade do cenário: alimenta tanto o seed de taxas
     * quanto a execução direta da engine oficial no teste de equivalência. Não
     * passa por `PuSimulationParameterFactory` -- é justamente contra ela que a
     * equivalência é medida.
     */
    public static function syntheticParameter(
        ?CarbonImmutable $integralizationDate = null,
        ?CarbonImmutable $windowEnd = null,
    ): EmissionPuParameter {
        $parameter = new EmissionPuParameter;
        $parameter->exists = false;
        $parameter->forceFill([
            'indexer' => PuIndexer::Cdi->value,
            'spread_rate' => '0.06000000',
            'business_day_basis' => 252,
            'calendar_code' => self::CALENDAR_CODE,
            'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
            'index_rate_lag_business_days' => -5,
            'initial_unit_value' => '1000.0000000000000000',
            'curve_start_date' => ($integralizationDate ?? self::integralizationDate())->toDateString(),
            'curve_end_date' => ($windowEnd ?? self::windowEndDate())->toDateString(),
            'first_coupon_pre_integralization_premium_enabled' => true,
            'first_coupon_pre_integralization_business_days' => 2,
            'first_coupon_pre_integralization_apply_index_factor' => true,
            'first_coupon_pre_integralization_apply_spread_factor' => true,
        ]);

        return $parameter;
    }

    /**
     * Parâmetro operacional persistido, para os cenários que precisam provar a
     * precedência persistido × contratual × override.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function persistParameter(Emission $emission, array $overrides = []): EmissionPuParameter
    {
        return EmissionPuParameter::query()->create([
            'emission_id' => $emission->id,
            'indexer' => PuIndexer::Cdi->value,
            'spread_rate' => '0.06000000',
            'business_day_basis' => 252,
            'calendar_code' => self::CALENDAR_CODE,
            'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
            'index_rate_lag_business_days' => -5,
            'initial_unit_value' => '1000.0000000000000000',
            'curve_start_date' => self::INTEGRALIZATION_DATE,
            'curve_end_date' => self::MATURITY_DATE,
            'first_coupon_pre_integralization_premium_enabled' => true,
            'first_coupon_pre_integralization_business_days' => 2,
            'first_coupon_pre_integralization_apply_index_factor' => true,
            'first_coupon_pre_integralization_apply_spread_factor' => true,
            ...$overrides,
        ]);
    }

    /**
     * Executa a engine oficial com o MESMO input, montado independentemente a
     * partir de constantes explícitas -- sem passar por
     * `PuSimulationService`/`PuSimulationParameterFactory`.
     *
     * É este caminho que prova que a simulação é apenas um adapter: a data
     * efetiva do evento é resolvida pelo calendário oficial, e não por uma
     * segunda implementação da convenção de pagamento.
     *
     * @param  array{emission:Emission, input:PuSimulationInput}  $scenario
     * @return list<PuDailyCurveRowData>
     */
    public static function officialEngineRows(Emission $emission, array $scenario): array
    {
        $windowEnd = $scenario['input']->simulationEndDate ?? self::windowEndDate();
        $parameter = self::syntheticParameter(
            $scenario['input']->firstIntegralizationDate ?? self::integralizationDate(),
            $windowEnd,
        );

        $scenarioEmission = clone $emission;
        $scenarioEmission->setRelation('puParameter', $parameter);
        $scenarioEmission->setRelation('puEvents', self::officialEvents($windowEnd));
        $scenarioEmission->setRelation('integralizationHistories', new EloquentCollection);

        return app(PuCurveGeneratorService::class)->handle($scenarioEmission)->rows;
    }

    /**
     * Cronograma contratual da janela montado a partir de constantes: cupons
     * mensais desde o primeiro pagamento e amortização residual no vencimento,
     * ambos limitados por `original_date <= fim da janela`.
     *
     * @return EloquentCollection<int, EmissionPuEvent>
     */
    private static function officialEvents(CarbonImmutable $windowEnd): EloquentCollection
    {
        $calendar = app(BusinessCalendarService::class);
        $maturity = CarbonImmutable::parse(self::MATURITY_DATE)->startOfDay();
        $models = [];
        $index = 0;

        $following = function (CarbonImmutable $date) use ($calendar): CarbonImmutable {
            while (! $calendar->isBusinessDay($date, self::CALENDAR_CODE)) {
                $date = $date->addDay();
            }

            return $date;
        };

        for (
            $interestDate = CarbonImmutable::parse(self::FIRST_INTEREST_PAYMENT_DATE)->startOfDay();
            $interestDate->lte($maturity);
            $interestDate = $interestDate->addMonthNoOverflow()
        ) {
            if ($interestDate->gt($windowEnd)) {
                break;
            }

            $model = new EmissionPuEvent;
            $model->exists = false;
            $model->forceFill([
                'event_type' => PuEventType::InterestPayment->value,
                'original_date' => $interestDate->toDateString(),
                'effective_date' => $following($interestDate)->toDateString(),
                'amortization_type' => PuAmortizationType::None->value,
                'amortization_value' => null,
                'sequence' => 1,
            ]);
            $model->setAttribute('id', ++$index);
            $models[] = $model;
        }

        if ($maturity->lte($windowEnd)) {
            $model = new EmissionPuEvent;
            $model->exists = false;
            $model->forceFill([
                'event_type' => PuEventType::Amortization->value,
                'original_date' => $maturity->toDateString(),
                'effective_date' => $following($maturity)->toDateString(),
                'amortization_type' => PuAmortizationType::Residual->value,
                'amortization_value' => null,
                'sequence' => 1,
            ]);
            $model->setAttribute('id', ++$index);
            $models[] = $model;
        }

        return new EloquentCollection($models);
    }

    /**
     * Assinatura financeira completa de uma linha, em strings. Serve para
     * comparar simulação × engine oficial sem nenhuma conversão para float.
     *
     * @return array<string, mixed>
     */
    public static function rowSignature(PuDailyCurveRowData $row): array
    {
        return [
            'date' => $row->date->toDateString(),
            'is_business_day' => $row->isBusinessDay,
            'unit_base_value' => $row->unitBaseValue,
            'unit_corrected_value' => $row->unitCorrectedValue,
            'factor_di' => $row->factorDi,
            'factor_di_accumulated' => $row->factorDiAccumulated,
            'factor_spread' => $row->factorSpread,
            'factor_spread_di' => $row->factorSpreadDi,
            'interest_real_unit_value' => $row->interestRealUnitValue,
            'updated_unit_value' => $row->updatedUnitValue,
            'amortization_ratio' => $row->amortizationRatio,
            'amortization_unit_value' => $row->amortizationUnitValue,
            'residual_unit_value' => $row->residualUnitValue,
            'quantity' => $row->quantity,
            'total_value' => $row->totalValue,
            'interest_payment_unit_value' => $row->interestPaymentUnitValue,
            'payment_total_unit_value' => $row->paymentTotalUnitValue,
            'dup_interest' => $row->dupInterest,
            'dut_interest' => $row->dutInterest,
            'index_rate_date' => $row->indexRateDate?->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'event_original_date' => $row->eventOriginalDate?->toDateString(),
            'event_effective_date' => $row->eventEffectiveDate?->toDateString(),
        ];
    }

    /**
     * Torna o calendário matematicamente irresolvível: a política passa a exigir
     * decisão explícita e as linhas do período são removidas. É o cenário que a
     * simulação precisa reportar em vez de estourar.
     */
    public static function breakCalendarCoverage(): void
    {
        DB::table('business_calendars')
            ->where('code', self::CALENDAR_CODE)
            ->update(['materialization_policy' => 'explicit_official_decisions']);
        DB::table('business_calendar_dates')
            ->where('calendar_code', self::CALENDAR_CODE)
            ->delete();

        // O serviço de calendário é `scoped` e memoiza a política por instância.
        app()->forgetInstance(BusinessDayCalendarService::class);
        app()->forgetInstance(BusinessCalendarService::class);
    }

    /**
     * Contadores de TUDO que uma simulação pura não pode alterar.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return [
            'emissions' => Emission::query()->count(),
            'parameters' => EmissionPuParameter::query()->count(),
            'events' => EmissionPuEvent::query()->count(),
            'curve_versions' => EmissionPuCurveVersion::query()->count(),
            'daily_curves' => EmissionPuDailyCurve::query()->count(),
            'baseline_evidences' => EmissionPuBaselineEvidence::query()->count(),
            'external_benchmarks' => EmissionPuExternalBenchmark::query()->count(),
            'external_validations' => EmissionPuExternalValidation::query()->count(),
            'promotions' => EmissionPuCurvePromotion::query()->count(),
            'pu_histories' => PuHistory::query()->count(),
            'payments' => Payment::query()->count(),
            'integralizations' => IntegralizationHistory::query()->count(),
            'index_rates' => IndexRate::query()->count(),
        ];
    }
}
