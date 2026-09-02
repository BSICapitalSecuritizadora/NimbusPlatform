<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuEventType;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use Carbon\CarbonImmutable;

final class PuEventPlanService
{
    /** @var list<string> */
    private const IDENTITY_FIELDS = [
        'event_type',
        'original_date',
        'effective_date',
        'amortization_type',
        'sequence',
    ];

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @return array{
     *     requirements:list<array<string,mixed>>,
     *     existing_events:list<array<string,mixed>>,
     *     present_events:list<array<string,mixed>>,
     *     missing_events:list<array<string,mixed>>,
     *     conflicting_events:list<array<string,mixed>>
     * }
     */
    public function inspect(
        Emission $emission,
        array $requirements,
        CarbonImmutable $homologationEndDate,
    ): array {
        usort($requirements, fn (array $left, array $right): int => $this->eventSortKey($left) <=> $this->eventSortKey($right));
        $existingModels = EmissionPuEvent::query()
            ->whereBelongsTo($emission)
            ->orderBy('effective_date')
            ->orderBy('event_type')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $existingEvents = $existingModels
            ->map(fn (EmissionPuEvent $event): array => $this->eventPayload($event))
            ->all();
        $byStorageIdentity = $existingModels->groupBy(
            fn (EmissionPuEvent $event): string => $this->eventStorageIdentity($this->eventPayload($event)),
        );
        $byContractIdentity = $existingModels->groupBy(
            fn (EmissionPuEvent $event): string => $this->eventContractIdentity($this->eventPayload($event)),
        );
        $presentEvents = [];
        $missingEvents = [];
        $conflictingEvents = [];
        $consumedEventIds = [];

        foreach ($requirements as $requirement) {
            $storageMatches = $byStorageIdentity->get($this->eventStorageIdentity($requirement), collect());
            $contractMatches = $byContractIdentity->get($this->eventContractIdentity($requirement), collect());
            $matches = $storageMatches->merge($contractMatches)->unique('id')->values();

            if ($matches->isEmpty()) {
                $missingEvents[] = $requirement;

                continue;
            }

            /** @var EmissionPuEvent|null $exact */
            $exact = $matches->first(fn (EmissionPuEvent $event): bool => $this->eventsMatch(
                $this->eventPayload($event),
                $requirement,
            ));

            if ($exact instanceof EmissionPuEvent) {
                $consumedEventIds[] = $exact->id;
                $presentEvents[] = $this->eventPayload($exact);

                continue;
            }

            $conflictingEvents[] = [
                'identity' => $this->eventContractIdentity($requirement),
                'expected' => $requirement,
                'existing' => $matches
                    ->map(fn (EmissionPuEvent $event): array => $this->eventPayload($event))
                    ->all(),
                'reason' => 'event_semantics_mismatch',
            ];
            $consumedEventIds = [
                ...$consumedEventIds,
                ...$matches->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ];
        }

        $existingModels
            ->reject(fn (EmissionPuEvent $event): bool => in_array($event->id, $consumedEventIds, true))
            ->filter(function (EmissionPuEvent $event) use ($homologationEndDate): bool {
                if (! PuEventType::tryFrom((string) $event->event_type) instanceof PuEventType) {
                    return true;
                }

                $comparisonDate = $event->original_date ?? $event->effective_date;

                return $comparisonDate !== null
                    && CarbonImmutable::instance($comparisonDate)->lte($homologationEndDate);
            })
            ->each(function (EmissionPuEvent $event) use (&$conflictingEvents): void {
                $conflictingEvents[] = [
                    'identity' => $this->eventContractIdentity($this->eventPayload($event)),
                    'expected' => null,
                    'existing' => [$this->eventPayload($event)],
                    'reason' => 'unexpected_contractual_event',
                ];
            });

        usort($presentEvents, fn (array $left, array $right): int => $this->eventSortKey($left) <=> $this->eventSortKey($right));
        usort($missingEvents, fn (array $left, array $right): int => $this->eventSortKey($left) <=> $this->eventSortKey($right));
        usort($conflictingEvents, fn (array $left, array $right): int => (string) $left['identity'] <=> (string) $right['identity']);

        return [
            'requirements' => array_values($requirements),
            'existing_events' => array_values($existingEvents),
            'present_events' => array_values($presentEvents),
            'missing_events' => array_values($missingEvents),
            'conflicting_events' => array_values($conflictingEvents),
        ];
    }

    /**
     * Identidade material de um evento, idêntica à do gate oficial
     * `PuBaselineEventRequirementService`: tipo, data original, data efetiva
     * (a convenção aplicada), tipo de amortização e sequência.
     *
     * `amortization_value` fica fora de propósito — para `bullet` o contrato
     * comprova amortização RESIDUAL, cujo valor a engine apura no vencimento e
     * que não consta do cronograma. Compará-lo faria este planner declarar
     * conflito exatamente onde o readiness validado declara requisito
     * satisfeito.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function eventsMatch(array $left, array $right): bool
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function eventPayload(EmissionPuEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'original_date' => $event->original_date?->toDateString(),
            'effective_date' => $event->effective_date?->toDateString(),
            'amortization_type' => $event->amortization_type,
            'amortization_value' => $event->amortization_value !== null
                ? (string) $event->amortization_value
                : null,
            'sequence' => (int) $event->sequence,
        ];
    }

    /** @param array<string, mixed> $event */
    private function eventStorageIdentity(array $event): string
    {
        return implode('|', [
            $event['event_type'] ?? '',
            $event['effective_date'] ?? '',
            $event['sequence'] ?? '',
        ]);
    }

    /** @param array<string, mixed> $event */
    private function eventContractIdentity(array $event): string
    {
        return implode('|', [
            $event['event_type'] ?? '',
            $event['original_date'] ?? '',
            $event['sequence'] ?? '',
        ]);
    }

    /** @param array<string, mixed> $event */
    private function eventSortKey(array $event): string
    {
        return sprintf(
            '%s|%s|%010d',
            $event['effective_date'] ?? '',
            $event['event_type'] ?? '',
            (int) ($event['sequence'] ?? 0),
        );
    }
}
