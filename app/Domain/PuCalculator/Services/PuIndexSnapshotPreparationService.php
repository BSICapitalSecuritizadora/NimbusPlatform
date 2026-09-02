<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\BcbSgsBlockFailure;
use App\Domain\PuCalculator\DTOs\BcbSgsFetchResult;
use App\Domain\PuCalculator\DTOs\BcbSgsRateData;
use App\Domain\PuCalculator\DTOs\BcbSgsRawPayload;
use App\Domain\PuCalculator\DTOs\PuNumericPreparationPlan;
use App\Domain\PuCalculator\DTOs\PuNumericPreparationResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PuIndexSnapshotPreparationService
{
    public const ACTION_RATES_PREPARED = 'rates_prepared';

    public const ACTION_ALREADY_PRESENT = 'rates_already_present';

    public const ACTION_INDEX_RATE_CONFLICT = 'index_rate_conflict';

    public const ACTION_MISSING_INDEX_SNAPSHOT = 'missing_index_snapshot';

    public const ACTION_INVALID_SOURCE_PAYLOAD = 'invalid_source_payload';

    public const ACTION_PREPARATION_STATE_CHANGED = 'preparation_state_changed';

    public function __construct(
        private readonly PuNumericPreparationPlanService $plans,
        private readonly PuNumericPreparationActorService $actors,
        private readonly IndexRateSyncService $indexRateSync,
        private readonly CdiRateNormalizer $cdiRateNormalizer,
        private readonly IndexRateLookupService $indexRateLookup,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function write(
        Emission $emission,
        ?string $actorIdentifier,
        ?CarbonImmutable $asOf = null,
    ): PuNumericPreparationResult {
        $asOf = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $plan = $this->plans->plan($emission, $asOf);

        if (! $plan->financialPreparationReady) {
            return $this->resultFromPlan($plan);
        }

        if ($plan->conflictingRates !== []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_INDEX_RATE_CONFLICT,
                reason: 'Snapshots persistidos incompatíveis impedem a carga create-only.',
                writes: 0,
                plan: $plan,
                details: ['conflicts' => $plan->conflictingRates],
            );
        }

        if ($plan->missingRateDates === []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_ALREADY_PRESENT,
                reason: 'Todos os snapshots exatos requeridos já estão presentes.',
                writes: 0,
                plan: $plan,
                details: ['already_present' => array_column($plan->presentRates, 'date')],
            );
        }

        if ($plan->hasFinancialEffects()) {
            return new PuNumericPreparationResult(
                action: PuNumericPreparationPlanService::ACTION_EXISTING_FINANCIAL_EFFECTS,
                reason: 'Existem efeitos financeiros; a preparação de snapshots foi bloqueada.',
                writes: 0,
                plan: $plan,
            );
        }

        $actorResolution = $this->actors->resolve($actorIdentifier, AccessPermission::PuIndexSync);

        if (! $actorResolution['actor'] instanceof User) {
            return new PuNumericPreparationResult(
                action: $actorResolution['action'],
                reason: $actorResolution['reason'],
                writes: 0,
                plan: $plan,
            );
        }

        $parameter = EmissionPuParameter::query()->find($plan->parameterId);

        if (! $parameter instanceof EmissionPuParameter) {
            return new PuNumericPreparationResult(
                action: self::ACTION_PREPARATION_STATE_CHANGED,
                reason: 'EmissionPuParameter deixou de existir antes do fetch.',
                writes: 0,
                plan: $this->plans->plan($emission, $asOf),
                actorId: $actorResolution['actor']->id,
            );
        }

        $source = $this->validatedCdiSource($parameter);
        $from = CarbonImmutable::parse($plan->requiredRateDates[0]);
        $to = CarbonImmutable::parse($plan->requiredRateDates[array_key_last($plan->requiredRateDates)]);
        $fetch = $this->indexRateSync->fetchPublishedRates(PuIndexer::Cdi, $from, $to);
        $incoming = $this->validatedIncomingRates($fetch, $plan, $source);

        if ($incoming['payload_issues'] !== []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_INVALID_SOURCE_PAYLOAD,
                reason: 'A resposta SGS contém entradas inválidas, duplicadas ou blocos incompletos.',
                writes: 0,
                plan: $plan,
                actorId: $actorResolution['actor']->id,
                details: $incoming,
            );
        }

        if ($incoming['conflicts'] !== []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_INDEX_RATE_CONFLICT,
                reason: 'A resposta SGS diverge de snapshot create-only já persistido.',
                writes: 0,
                plan: $plan,
                actorId: $actorResolution['actor']->id,
                details: $incoming,
            );
        }

        if ($incoming['missing_in_source'] !== []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_MISSING_INDEX_SNAPSHOT,
                reason: 'A fonte não retornou todas as datas exatas ausentes; nenhum fallback temporal foi aplicado.',
                writes: 0,
                plan: $plan,
                actorId: $actorResolution['actor']->id,
                details: $incoming,
            );
        }

        try {
            return Cache::lock('pu:numeric-preparation:index-rates:'.$parameter->indexer, 30)
                ->block(10, fn (): PuNumericPreparationResult => DB::transaction(
                    fn (): PuNumericPreparationResult => $this->persistUnderLock(
                        emission: $emission,
                        actorIdentifier: $actorIdentifier,
                        asOf: $asOf,
                        initialPlan: $plan,
                        incomingByDate: $incoming['rates_by_date'],
                        source: $source,
                        requestedWindow: [
                            'from' => $from->toDateString(),
                            'to' => $to->toDateString(),
                        ],
                        payloadChecksums: $incoming['payload_checksums'],
                    ),
                ));
        } catch (UniqueConstraintViolationException) {
            // A constraint é global — unique(indexer, rate_date) — então outra
            // emissão/processo pode ocupar a data exata entre a rechecagem e o
            // insert. Nada foi sobrescrito; o estado real é lido novamente e
            // reclassificado: idempotente só quando a linha concorrente é
            // compatível, conflito quando divergir.
            $after = $this->plans->plan($emission, $asOf);

            [$action, $reason] = match (true) {
                $after->conflictingRates !== [] => [
                    self::ACTION_INDEX_RATE_CONFLICT,
                    'Uma criação concorrente ocupou uma data exata com valor ou fonte incompatível; nada foi sobrescrito.',
                ],
                $after->missingRateDates === [] => [
                    self::ACTION_ALREADY_PRESENT,
                    'Uma criação concorrente compatível completou os snapshots exatos requeridos.',
                ],
                default => [
                    self::ACTION_PREPARATION_STATE_CHANGED,
                    'Uma criação concorrente alterou os snapshots durante a persistência; nenhuma linha foi sobrescrita.',
                ],
            };

            return new PuNumericPreparationResult(
                action: $action,
                reason: $reason,
                writes: 0,
                plan: $after,
                actorId: $actorResolution['actor']->id,
            );
        }
    }

    /**
     * @param  array<string, string>  $incomingByDate
     * @param  array{source:string,series:string,source_reference:string}  $source
     * @param  array{from:string,to:string}  $requestedWindow
     * @param  list<string>  $payloadChecksums
     */
    private function persistUnderLock(
        Emission $emission,
        ?string $actorIdentifier,
        CarbonImmutable $asOf,
        PuNumericPreparationPlan $initialPlan,
        array $incomingByDate,
        array $source,
        array $requestedWindow,
        array $payloadChecksums,
    ): PuNumericPreparationResult {
        $lockedEmission = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();
        $lockedParameter = EmissionPuParameter::query()
            ->whereKey($initialPlan->parameterId)
            ->whereBelongsTo($lockedEmission)
            ->lockForUpdate()
            ->first();
        $actorResolution = $this->actors->resolve(
            $actorIdentifier,
            AccessPermission::PuIndexSync,
            lockForUpdate: true,
        );
        $plan = $this->plans->plan($lockedEmission, $asOf);

        if (! $lockedParameter instanceof EmissionPuParameter
            || $plan->parameterId !== $initialPlan->parameterId
            || $plan->requiredRateDates !== $initialPlan->requiredRateDates) {
            return new PuNumericPreparationResult(
                action: self::ACTION_PREPARATION_STATE_CHANGED,
                reason: 'A configuração ou a janela exata mudou entre fetch e persistência.',
                writes: 0,
                plan: $plan,
                actorId: $actorResolution['actor']?->id,
            );
        }

        if (! $actorResolution['actor'] instanceof User) {
            return new PuNumericPreparationResult(
                action: $actorResolution['action'],
                reason: $actorResolution['reason'],
                writes: 0,
                plan: $plan,
            );
        }

        if (! $plan->canPrepareRates()) {
            return $plan->missingRateDates === []
                ? new PuNumericPreparationResult(
                    action: self::ACTION_ALREADY_PRESENT,
                    reason: 'Outra execução já criou todos os snapshots exatos.',
                    writes: 0,
                    plan: $plan,
                    actorId: $actorResolution['actor']->id,
                )
                : $this->resultFromPlan($plan, $actorResolution['actor']->id);
        }

        $missingInFetchedPayload = array_values(array_diff($plan->missingRateDates, array_keys($incomingByDate)));

        if ($missingInFetchedPayload !== []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_MISSING_INDEX_SNAPSHOT,
                reason: 'Uma data recém-ausente não está no payload validado; nenhuma linha foi criada.',
                writes: 0,
                plan: $plan,
                actorId: $actorResolution['actor']->id,
                details: ['missing_in_source' => $missingInFetchedPayload],
            );
        }

        $insertedDates = [];

        foreach ($plan->missingRateDates as $rateDate) {
            IndexRate::query()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $rateDate,
                'rate_value' => $incomingByDate[$rateDate],
                'source' => $source['source'],
                'source_reference' => $source['source_reference'],
                'external_series_code' => $source['series'],
                'fetched_at' => now(),
                'is_projected' => false,
                'projection_source' => null,
                'projection_reference_date' => null,
                'projection_policy' => null,
                'index_projection_series_id' => null,
            ]);
            $insertedDates[] = $rateDate;
        }

        $this->indexRateLookup->flushCache();
        $this->auditLog->logNumericSnapshotPreparation(
            emission: $lockedEmission,
            parameter: $lockedParameter,
            actor: $actorResolution['actor'],
            source: $source['source'],
            series: $source['series'],
            requestedWindow: $requestedWindow,
            requiredDates: $plan->requiredRateDates,
            insertedDates: $insertedDates,
            alreadyExistingDates: array_column($plan->presentRates, 'date'),
            conflicts: [],
            payloadChecksums: $payloadChecksums,
        );
        $after = $this->plans->plan($lockedEmission, $asOf);

        return new PuNumericPreparationResult(
            action: self::ACTION_RATES_PREPARED,
            reason: 'Snapshots CDI exatos criados de forma create-only após rechecagem transacional.',
            writes: count($insertedDates),
            plan: $after,
            actorId: $actorResolution['actor']->id,
            details: [
                'inserted_dates' => $insertedDates,
                'already_present' => array_column($plan->presentRates, 'date'),
                'requested_window' => $requestedWindow,
                'source' => $source,
            ],
        );
    }

    /**
     * @param  array{source:string,series:string,source_reference:string}  $source
     * @return array{
     *     rates_by_date:array<string,string>,
     *     payload_issues:list<array<string,mixed>>,
     *     conflicts:list<array<string,mixed>>,
     *     missing_in_source:list<string>,
     *     payload_checksums:list<string>
     * }
     */
    private function validatedIncomingRates(
        BcbSgsFetchResult $fetch,
        PuNumericPreparationPlan $plan,
        array $source,
    ): array {
        $requiredDates = array_fill_keys($plan->requiredRateDates, true);
        $ratesByDate = [];
        $payloadIssues = [
            ...$fetch->invalidEntries,
            ...$fetch->duplicateDates,
            ...array_map(
                fn (BcbSgsBlockFailure $failure): array => [
                    'issue' => 'block_failure',
                    'detail' => $failure->describe(),
                ],
                $fetch->blockFailures,
            ),
        ];

        foreach ($fetch->rates as $rate) {
            $date = $rate->referenceDate->toDateString();

            if (! isset($requiredDates[$date])) {
                continue;
            }

            $normalized = $this->normalizeIncomingCdiRate($rate);

            if ($normalized === null) {
                $payloadIssues[] = [
                    'issue' => 'invalid_cdi_rate',
                    'date' => $date,
                    'value' => $rate->rawValue ?? $rate->value,
                ];

                continue;
            }

            $ratesByDate[$date] = $normalized;
        }

        ksort($ratesByDate);
        $conflicts = [];

        foreach ($plan->presentRates as $presentRate) {
            $date = (string) $presentRate['date'];

            if (! isset($ratesByDate[$date])) {
                continue;
            }

            if (bccomp((string) $presentRate['value'], $ratesByDate[$date], 8) !== 0) {
                $conflicts[] = [
                    'date' => $date,
                    'existing' => $presentRate,
                    'incoming' => [
                        'value' => $ratesByDate[$date],
                        'source' => $source['source'],
                        'series' => $source['series'],
                    ],
                    'source' => $source['source'],
                ];
            }
        }

        return [
            'rates_by_date' => $ratesByDate,
            'payload_issues' => $payloadIssues,
            'conflicts' => $conflicts,
            'missing_in_source' => array_values(array_diff($plan->missingRateDates, array_keys($ratesByDate))),
            'payload_checksums' => array_map(
                fn (BcbSgsRawPayload $payload): string => $payload->sha256,
                $fetch->rawPayloads,
            ),
        ];
    }

    private function normalizeIncomingCdiRate(BcbSgsRateData $rate): ?string
    {
        $normalized = $this->cdiRateNormalizer->fromPublishedDecimal($rate->value, 2);

        if ($normalized['value'] === null || $normalized['issue'] !== null) {
            return null;
        }

        return bcadd($normalized['value'], '0', 8);
    }

    /** @return array{source:string,series:string,source_reference:string} */
    private function validatedCdiSource(EmissionPuParameter $parameter): array
    {
        if ($parameter->indexer_enum !== PuIndexer::Cdi) {
            throw new InvalidArgumentException('Esta preparação controlada suporta somente snapshots CDI publicados.');
        }

        $source = $this->indexRateSync->publishedSource(PuIndexer::Cdi);

        if ($source['source'] !== 'bcb_sgs'
            || $source['code'] !== 4389
            || $source['value_type'] !== 'annual_rate') {
            throw new InvalidArgumentException('A fonte CDI configurada não corresponde ao contrato aprovado bcb_sgs:4389.');
        }

        return [
            'source' => $source['source'],
            'series' => (string) $source['code'],
            'source_reference' => sprintf('%s:%d', $source['source'], $source['code']),
        ];
    }

    private function resultFromPlan(
        PuNumericPreparationPlan $plan,
        ?int $actorId = null,
    ): PuNumericPreparationResult {
        return new PuNumericPreparationResult(
            action: $plan->action,
            reason: $plan->reason,
            writes: 0,
            plan: $plan,
            actorId: $actorId,
        );
    }
}
