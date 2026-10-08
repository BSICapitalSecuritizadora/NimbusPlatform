<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Retrato canônico dos insumos contratuais da curva operacional.
 *
 * Captura do banco -- nunca de relações já carregadas -- os termos contratuais, o
 * horizonte, os eventos ATIVOS e a linha do tempo de integralização, e normaliza
 * tudo para que o fingerprint dependa só do que a engine usa:
 *
 *  - decimais na escala da coluna (a mesma string que a engine recebe);
 *  - termos só do indexador da emissão (um `annual_rate` esquecido numa emissão de
 *    CDI não muda nada e não entra);
 *  - eventos em ordem canônica (data efetiva, prioridade do tipo, sequência),
 *    sem descrição, justificativa ou ids;
 *  - integralizações somadas por data, como a engine as acumula.
 *
 * O mesmo retrato é o que a geração grava na versão, o que a homologação aprova, de
 * onde a extensão diária calcula os dias novos e contra o que os insumos vivos são
 * comparados ({@see PuCurveChangeImpactClassifier}).
 *
 * Com `$lockForShare`, as leituras levam trava compartilhada: dentro da transação
 * que já travou a emissão, nenhuma gravação concorrente de evento, integralização
 * ou parâmetro dessa emissão comita entre a leitura e o commit de quem leu.
 */
final class PuCurveInputSnapshotService
{
    public function __construct(
        private readonly PuCurveHorizonResolver $horizons,
    ) {}

    public function capture(Emission $emission, bool $lockForShare = false): PuCurveInputSnapshot
    {
        $parameter = EmissionPuParameter::query()
            ->where('emission_id', $emission->id)
            ->when($lockForShare, fn ($query) => $query->sharedLock())
            ->first();

        if (! $parameter instanceof EmissionPuParameter) {
            throw new PuCurveInputsException('A emissão não tem parâmetros de cálculo de PU configurados.');
        }

        if ($parameter->curve_start_date === null || $parameter->curve_end_date === null) {
            throw new PuCurveInputsException('Defina a data inicial e o vencimento da curva de PU.');
        }

        $events = EmissionPuEvent::query()
            ->where('emission_id', $emission->id)
            ->when($lockForShare, fn ($query) => $query->sharedLock())
            ->orderBy('id')
            ->get();
        $integralizations = IntegralizationHistory::query()
            ->where('emission_id', $emission->id)
            ->when($lockForShare, fn ($query) => $query->sharedLock())
            ->orderBy('id')
            ->get();

        return $this->fromModels($parameter, $events, $integralizations);
    }

    /**
     * Retrato a partir de modelos já carregados (em memória). Serve à captura e aos
     * testes de canonicalização; a ordem da coleção nunca importa.
     *
     * @param  EloquentCollection<int, EmissionPuEvent>  $events
     * @param  EloquentCollection<int, IntegralizationHistory>  $integralizations
     */
    public function fromModels(
        EmissionPuParameter $parameter,
        EloquentCollection $events,
        EloquentCollection $integralizations,
    ): PuCurveInputSnapshot {
        $activeEvents = $events
            ->filter(fn (EmissionPuEvent $event): bool => $event->isActive() && $event->effective_date !== null)
            ->map(fn (EmissionPuEvent $event): array => [
                'canonical' => $this->canonicalEvent($event),
                'id' => $event->id,
            ])
            ->sortBy(fn (array $event): string => PuEventType::orderingKey(
                (string) $event['canonical']['effective_date'],
                (string) $event['canonical']['event_type'],
                (int) $event['canonical']['sequence'],
            ))
            ->values();
        $canonicalEvents = $activeEvents->pluck('canonical')->all();
        [$timeline, $integralizationIds] = $this->integralizationTimeline($integralizations);
        $maturity = CarbonImmutable::instance($parameter->curve_end_date)->startOfDay();
        $horizon = $this->horizons->resolve($maturity, $canonicalEvents);
        $method = $parameter->resolvedCalculationMethod();

        return PuCurveInputSnapshot::make(
            payload: [
                'engine' => [
                    'engine_version' => PuAuditLogService::ENGINE_VERSION,
                    'calculation_method' => $method->value,
                    'method_engine_version' => $method->engineVersion(),
                    'calculation_profile' => PuCalculationProfile::Contractual->value,
                ],
                'terms' => $this->terms($parameter),
                'horizon' => [
                    'curve_start_date' => CarbonImmutable::instance($parameter->curve_start_date)->toDateString(),
                    'contractual_maturity_date' => $maturity->toDateString(),
                    'curve_end_date' => $horizon['curve_end_date'],
                ],
                'events' => $canonicalEvents,
                'integralizations' => $timeline,
            ],
            provenance: [
                'parameter_id' => $parameter->id,
                'event_ids' => $activeEvents
                    ->mapWithKeys(fn (array $event): array => [
                        PuCurveInputSnapshot::eventIdentity($event['canonical']) => $event['id'],
                    ])
                    ->all(),
                'integralization_ids' => $integralizationIds,
                'quantity_timeline' => $this->quantityTimeline($timeline, $integralizationIds),
                'horizon_contributors' => $horizon['contributors'],
                'captured_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Retrato gravado numa versão, provado contra o próprio fingerprint. Nulo quando
     * a versão é anterior à Fase 4 (sem retrato).
     */
    public function forVersion(EmissionPuCurveVersion $version): ?PuCurveInputSnapshot
    {
        $stored = $version->curve_inputs;

        if (! is_array($stored) || $stored === []) {
            return null;
        }

        return PuCurveInputSnapshot::fromStored($stored, $version->curve_inputs_fingerprint);
    }

    /**
     * Emissão-cenário cujos parâmetros, eventos e integralizações SÃO o retrato. É
     * o que a engine recebe na geração e na extensão: nada vivo entra no cálculo
     * depois que o retrato foi tirado. A emissão real nunca tem as relações
     * trocadas.
     */
    public function hydrate(Emission $emission, PuCurveInputSnapshot $snapshot): Emission
    {
        $scenario = clone $emission;
        $scenario->setRelation('puParameter', $this->hydrateParameter($emission, $snapshot));
        $scenario->setRelation('puEvents', $this->hydrateEvents($emission, $snapshot));
        $scenario->setRelation('integralizationHistories', $this->hydrateIntegralizations($emission, $snapshot));

        return $scenario;
    }

    /**
     * Forma canônica de um evento: só o que a engine lê para o tipo dele.
     *
     * @return array<string, mixed>
     */
    public function canonicalEvent(EmissionPuEvent $event): array
    {
        $type = PuEventType::tryFrom((string) $event->event_type);
        $canonical = [
            'event_type' => (string) $event->event_type,
            'effective_date' => CarbonImmutable::instance($event->effective_date)->toDateString(),
            'sequence' => (int) $event->sequence,
        ];

        if ($type?->isScheduledPayment() ?? false) {
            // A data original vai para a linha da curva (`event_original_date`).
            $canonical['original_date'] = $event->original_date !== null
                ? CarbonImmutable::instance($event->original_date)->toDateString()
                : null;
        }

        if ($type === PuEventType::Amortization) {
            $amortizationType = PuAmortizationType::tryFrom((string) $event->amortization_type);
            $canonical['amortization_type'] = (string) $event->amortization_type;
            $canonical['amortization_value'] = in_array($amortizationType, [PuAmortizationType::Percentage, PuAmortizationType::UnitValue], true)
                && $event->amortization_value !== null
                ? (string) $event->amortization_value
                : null;
        }

        if (! ($type?->isScheduledPayment() ?? false)) {
            $canonical['effective_until'] = $event->effective_until !== null
                ? CarbonImmutable::instance($event->effective_until)->toDateString()
                : null;
            $canonical['financial_effect'] = $this->canonicalEffect($type, $event->financial_effect);
        }

        return $canonical;
    }

    /**
     * Termos contratuais de base que a engine do indexador lê. Tudo vale desde o
     * início da curva; mudança com data de vigência é evento de alteração.
     *
     * Ficam de fora, por não entrarem em conta nenhuma: `method_version` e
     * `rounding_policy` (registro), `legacy_projection_enabled` (inerte desde a
     * Fase 2) e `correction_frequency` (não lido pela engine IPCA).
     *
     * @return array<string, mixed>
     */
    private function terms(EmissionPuParameter $parameter): array
    {
        $indexer = $parameter->indexer_enum;
        $terms = [
            'indexer' => $indexer->value,
            'calculation_method' => $parameter->resolvedCalculationMethod()->value,
            'initial_unit_value' => $this->decimal($parameter->initial_unit_value),
            'business_day_basis' => (int) $parameter->business_day_basis,
            'calendar_code' => (string) $parameter->calendar_code,
        ];

        if ($indexer === PuIndexer::Cdi) {
            $premium = $parameter->hasFirstCouponPreIntegralizationPremium();
            $terms += [
                'spread_rate' => $this->decimal($parameter->spread_rate),
                'index_rate_calendar_code' => filled($parameter->index_rate_calendar_code)
                    ? (string) $parameter->index_rate_calendar_code
                    : null,
                'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode_enum->value,
                'index_rate_lag_business_days' => (int) $parameter->index_rate_lag_business_days,
                'first_coupon_pre_integralization_premium_enabled' => $premium,
            ];

            if ($premium) {
                $terms += [
                    'first_coupon_pre_integralization_business_days' => $parameter->first_coupon_pre_integralization_business_days !== null
                        ? (int) $parameter->first_coupon_pre_integralization_business_days
                        : null,
                    'first_coupon_pre_integralization_apply_index_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_index_factor,
                    'first_coupon_pre_integralization_apply_spread_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor,
                ];
            }
        }

        if ($indexer === PuIndexer::Prefixed) {
            $terms['annual_rate'] = $this->decimal($parameter->annual_rate);
        }

        if ($indexer === PuIndexer::Ipca) {
            $terms += [
                'annual_rate' => $this->decimal($parameter->annual_rate),
                'base_index_date' => $parameter->base_index_date?->toDateString(),
                'index_lag_months' => $parameter->index_lag_months !== null ? (int) $parameter->index_lag_months : null,
                'index_projection_policy' => filled($parameter->index_projection_policy)
                    ? (string) $parameter->index_projection_policy
                    : null,
            ];
        }

        return $terms;
    }

    /**
     * Quantidade integralizada por data: a engine acumula todas as linhas de uma
     * mesma data, então duas linhas (10 + 5) e uma (15) são o mesmo contrato. Data
     * cuja soma é zero não muda nada e não entra.
     *
     * @param  EloquentCollection<int, IntegralizationHistory>  $integralizations
     * @return array{0: list<array{date: string, quantity: string}>, 1: array<string, list<int>>}
     */
    private function integralizationTimeline(EloquentCollection $integralizations): array
    {
        $byDate = [];
        $ids = [];

        foreach ($integralizations as $integralization) {
            if ($integralization->date === null) {
                continue;
            }

            $date = CarbonImmutable::instance($integralization->date)->toDateString();
            $byDate[$date] = bcadd($byDate[$date] ?? '0', (string) ($integralization->quantity ?? '0'), DecimalRounder::QUANTITY_SCALE);
            $ids[$date][] = (int) $integralization->id;
        }

        ksort($byDate);
        ksort($ids);
        $timeline = [];

        foreach ($byDate as $date => $quantity) {
            if (bccomp($quantity, '0', DecimalRounder::QUANTITY_SCALE) === 0) {
                continue;
            }

            $timeline[] = ['date' => $date, 'quantity' => $quantity];
        }

        return [$timeline, array_map(function (array $list): array {
            sort($list);

            return $list;
        }, $ids)];
    }

    /**
     * Prova da quantidade em carteira ao longo da curva: em cada data com
     * integralização, a quantidade antes, a variação, a quantidade depois e as
     * linhas de origem. Explicação, não insumo: fica fora do fingerprint.
     *
     * @param  list<array{date: string, quantity: string}>  $timeline
     * @param  array<string, list<int>>  $ids
     * @return list<array{date: string, quantity_before: string, quantity_change: string, quantity_after: string, source_integralization_ids: list<int>}>
     */
    private function quantityTimeline(array $timeline, array $ids): array
    {
        $cumulative = '0.0000';
        $entries = [];

        foreach ($timeline as $entry) {
            $after = bcadd($cumulative, $entry['quantity'], DecimalRounder::QUANTITY_SCALE);
            $entries[] = [
                'date' => $entry['date'],
                'quantity_before' => $cumulative,
                'quantity_change' => $entry['quantity'],
                'quantity_after' => $after,
                'source_integralization_ids' => $ids[$entry['date']] ?? [],
            ];
            $cumulative = $after;
        }

        return $entries;
    }

    /**
     * Efeito financeiro em forma canônica. Na alteração de spread, a taxa vai na
     * escala da coluna `spread_rate` (8 casas).
     *
     * @return array<string, mixed>|null
     */
    private function canonicalEffect(?PuEventType $type, mixed $effect): ?array
    {
        if (! is_array($effect) || $effect === []) {
            return null;
        }

        if ($type === PuEventType::SpreadAmendment) {
            $rate = $effect['spread_rate'] ?? null;

            return [
                'spread_rate' => is_numeric($rate) ? bcadd((string) $rate, '0', 8) : (is_scalar($rate) ? (string) $rate : null),
            ];
        }

        return PuCurveInputSnapshot::canonicalize($effect);
    }

    private function decimal(mixed $value): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    private function hydrateParameter(Emission $emission, PuCurveInputSnapshot $snapshot): EmissionPuParameter
    {
        $terms = $snapshot->terms();
        $horizon = $snapshot->horizon();
        $parameter = new EmissionPuParameter;
        $parameter->forceFill([
            'emission_id' => $emission->id,
            'curve_start_date' => $horizon['curve_start_date'],
            // O fim do cálculo é o horizonte canônico, não o vencimento cru.
            'curve_end_date' => $horizon['curve_end_date'],
            'initial_unit_value' => $terms['initial_unit_value'] ?? null,
            'indexer' => $terms['indexer'] ?? null,
            'calculation_method' => $terms['calculation_method'] ?? null,
            'business_day_basis' => $terms['business_day_basis'] ?? null,
            'calendar_code' => $terms['calendar_code'] ?? null,
            'spread_rate' => $terms['spread_rate'] ?? null,
            'annual_rate' => $terms['annual_rate'] ?? null,
            'index_rate_calendar_code' => $terms['index_rate_calendar_code'] ?? null,
            'index_rate_lookup_mode' => $terms['index_rate_lookup_mode'] ?? null,
            'index_rate_lag_business_days' => $terms['index_rate_lag_business_days'] ?? 0,
            'first_coupon_pre_integralization_premium_enabled' => (bool) ($terms['first_coupon_pre_integralization_premium_enabled'] ?? false),
            'first_coupon_pre_integralization_business_days' => $terms['first_coupon_pre_integralization_business_days'] ?? null,
            'first_coupon_pre_integralization_apply_index_factor' => (bool) ($terms['first_coupon_pre_integralization_apply_index_factor'] ?? true),
            'first_coupon_pre_integralization_apply_spread_factor' => (bool) ($terms['first_coupon_pre_integralization_apply_spread_factor'] ?? true),
            'base_index_date' => $terms['base_index_date'] ?? null,
            'index_lag_months' => $terms['index_lag_months'] ?? null,
            'index_projection_policy' => $terms['index_projection_policy'] ?? null,
            'legacy_projection_enabled' => false,
        ]);

        // Mesmo id do parâmetro vivo: a memória do prêmio de primeiro cupom cita a
        // evidência registrada nele. Nunca é salvo.
        if (($id = $snapshot->provenance['parameter_id'] ?? null) !== null) {
            $parameter->setAttribute('id', (int) $id);
            $parameter->exists = true;
            $parameter->syncOriginal();
        }

        return $parameter;
    }

    /**
     * @return EloquentCollection<int, EmissionPuEvent>
     */
    private function hydrateEvents(Emission $emission, PuCurveInputSnapshot $snapshot): EloquentCollection
    {
        $ids = is_array($snapshot->provenance['event_ids'] ?? null) ? $snapshot->provenance['event_ids'] : [];

        return new EloquentCollection(array_map(function (array $canonical) use ($emission, $ids): EmissionPuEvent {
            $event = new EmissionPuEvent;
            $event->forceFill([
                'emission_id' => $emission->id,
                'event_type' => $canonical['event_type'],
                'status' => PuEventStatus::Active->value,
                'original_date' => $canonical['original_date'] ?? null,
                'effective_date' => $canonical['effective_date'],
                'effective_until' => $canonical['effective_until'] ?? null,
                'amortization_type' => $canonical['amortization_type'] ?? PuAmortizationType::None->value,
                'amortization_value' => $canonical['amortization_value'] ?? null,
                'financial_effect' => $canonical['financial_effect'] ?? null,
                'sequence' => $canonical['sequence'],
            ]);

            if (($id = $ids[PuCurveInputSnapshot::eventIdentity($canonical)] ?? null) !== null) {
                $event->setAttribute('id', (int) $id);
            }

            return $event;
        }, $snapshot->events()));
    }

    /**
     * Uma integralização por data, com a quantidade somada da data.
     *
     * @return EloquentCollection<int, IntegralizationHistory>
     */
    private function hydrateIntegralizations(Emission $emission, PuCurveInputSnapshot $snapshot): EloquentCollection
    {
        return new EloquentCollection(array_map(function (array $entry) use ($emission): IntegralizationHistory {
            $integralization = new IntegralizationHistory;
            $integralization->forceFill([
                'emission_id' => $emission->id,
                'date' => $entry['date'],
                'quantity' => $entry['quantity'],
            ]);

            return $integralization;
        }, $snapshot->integralizations()));
    }
}
