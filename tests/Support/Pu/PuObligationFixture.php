<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuSettlementActor;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\DTOs\PuSettlementResult;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuObligation;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Cenário de obrigações financeiras do PU (Fase 5), sempre pelo caminho de
 * produção: parâmetros e eventos cadastrados, curva gerada pela ação, homologada
 * por outra pessoa, estendida pela rotina, liquidação registrada pelo serviço.
 *
 * Emissão CDI + 6% a.a., defasagem de 1 dia útil, calendário B3 de fins de
 * semana, 100 títulos de R$ 1.000 integralizados em 02/03/2026 e vencimento em
 * 31/12/2026. O CDI publicado de 27/02 a 13/03 leva a curva realizada até 16/03.
 */
final class PuObligationFixture
{
    public const START = '2026-03-02';

    public const MATURITY = '2026-12-31';

    /**
     * @param  list<array{0: PuEventType, 1: string, 2?: array<string, mixed>}>  $events  tipo, data efetiva, atributos
     */
    public static function emission(array $events = [], string $quantity = '100.0000', bool $withCalendar = true): Emission
    {
        if ($withCalendar) {
            self::calendar();
        }

        $emission = Emission::factory()->active()->create(['type' => 'CRI', 'issued_quantity' => 1000]);
        $emission->integralizationHistories()->create([
            'date' => self::START,
            'quantity' => $quantity,
            'unit_value' => '1000.00000000',
            'financial_value' => bcmul($quantity, '1000', 2),
            'investor_fund' => 'Head Invest',
        ]);
        $emission->puParameter()->create([
            'curve_start_date' => self::START,
            'curve_end_date' => self::MATURITY,
            'initial_unit_value' => '1000.0000000000000000',
            'spread_rate' => '6.00000000',
            'indexer' => PuIndexer::Cdi->value,
            'business_day_basis' => 252,
            'calendar_code' => 'B3',
            'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
            'index_rate_lag_business_days' => -1,
            'legacy_projection_enabled' => false,
        ]);

        foreach ($events as $event) {
            self::event($emission, $event[0], $event[1], $event[2] ?? []);
        }

        self::publish('2026-02-27', '2026-03-13');

        return $emission->fresh();
    }

    public static function calendar(string $from = '2026-02-23', string $to = '2027-01-08'): void
    {
        if (BusinessCalendarDate::query()->where('calendar_code', 'B3')->whereDate('calendar_date', $from)->exists()) {
            return;
        }

        for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
            BusinessCalendarDate::query()->create([
                'calendar_code' => 'B3',
                'calendar_date' => $date->toDateString(),
                'is_business_day' => ! $date->isWeekend(),
                'description' => null,
            ]);
        }

        app(BusinessDayCalendarService::class)->flushCache();
    }

    /**
     * CDI realizado de cada dia útil do intervalo (atualiza o que já existir).
     */
    public static function publish(string $from, string $to, string $rate = '14.90000000'): void
    {
        for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            $existing = IndexRate::query()
                ->where('indexer', PuIndexer::Cdi->value)
                ->whereDate('rate_date', $date->toDateString())
                ->first();

            if ($existing instanceof IndexRate) {
                $existing->forceFill(['rate_value' => $rate])->save();

                continue;
            }

            IndexRate::query()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->toDateString(),
                'rate_value' => $rate,
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
                'is_projected' => false,
            ]);
        }

        app(IndexRateService::class)->flushCache();
        app(IndexRateLookupService::class)->flushCache();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function event(Emission $emission, PuEventType $type, string $effective, array $attributes = []): EmissionPuEvent
    {
        return EmissionPuEvent::query()->create([
            'emission_id' => $emission->id,
            'event_type' => $type->value,
            'original_date' => $type->isScheduledPayment() ? ($attributes['original_date'] ?? $effective) : null,
            'effective_date' => $effective,
            'amortization_type' => PuAmortizationType::None->value,
            'sequence' => 1,
            ...$attributes,
        ]);
    }

    public static function generate(Emission $emission): EmissionPuCurveVersion
    {
        $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

        return EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->where('calculation_version', $result->calculationVersion)
            ->sole();
    }

    public static function homologate(Emission $emission, EmissionPuCurveVersion $version): EmissionPuCurveVersion
    {
        return app(HomologatePuCurve::class)->handle(
            $emission->fresh(),
            $version->calculation_version,
            User::factory()->create()->id,
            'Conferida contra o sistema antigo.',
        );
    }

    /**
     * Gera e homologa: a primeira curva oficial da emissão.
     */
    public static function official(Emission $emission): EmissionPuCurveVersion
    {
        return self::homologate($emission, self::generate($emission));
    }

    public static function obligation(Emission $emission, PuObligationType $type, string $contractualDate, int $sequence = 1): EmissionPuObligation
    {
        return EmissionPuObligation::query()
            ->where('emission_id', $emission->id)
            ->where('obligation_type', $type->value)
            ->whereDate('contractual_date', $contractualDate)
            ->where('sequence', $sequence)
            ->sole();
    }

    public static function row(EmissionPuCurveVersion $version, string $date): EmissionPuDailyCurve
    {
        return EmissionPuDailyCurve::query()->where('curve_version_id', $version->id)->whereDate('curve_date', $date)->sole();
    }

    public static function lastDate(EmissionPuCurveVersion $version): string
    {
        return CarbonImmutable::parse((string) EmissionPuDailyCurve::query()->where('curve_version_id', $version->id)->max('curve_date'))->toDateString();
    }

    /**
     * @param  array<string, string>|null  $components
     */
    public static function settle(
        EmissionPuObligation $obligation,
        string $amount,
        ?string $date = null,
        ?string $reference = null,
        ?array $components = null,
        PuSettlementSource $source = PuSettlementSource::B3,
        ?PuSettlementActor $actor = null,
    ): PuSettlementResult {
        return app(PuSettlementService::class)->record(new PuSettlementData(
            emissionId: (int) $obligation->emission_id,
            settlementDate: $date ?? $obligation->due_date->toDateString(),
            amount: $amount,
            source: $source,
            obligationType: $obligation->obligation_type,
            contractualDate: $obligation->contractual_date->toDateString(),
            sequence: (int) $obligation->sequence,
            externalReference: $reference,
            components: $components,
        ), $actor ?? self::integration());
    }

    public static function integration(): PuSettlementActor
    {
        return PuSettlementActor::integration('b3-connector-test');
    }

    public static function expectedTotal(EmissionPuObligation $obligation): string
    {
        return (string) $obligation->fresh()->currentCalculation->total_amount;
    }
}
