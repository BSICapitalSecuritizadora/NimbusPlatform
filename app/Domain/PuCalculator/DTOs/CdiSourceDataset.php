<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class CdiSourceDataset
{
    /**
     * @param  list<CdiSourceRecord>  $records
     * @param  list<array<string, mixed>>  $payloads
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $source,
        public CarbonImmutable $requestedFrom,
        public CarbonImmutable $requestedTo,
        public CarbonImmutable $capturedAt,
        public array $records,
        public array $payloads,
        public array $issues = [],
        public array $metadata = [],
    ) {}

    public function firstAvailableDate(): ?CarbonImmutable
    {
        return collect($this->records)
            ->pluck('referenceDate')
            ->filter()
            ->sortBy(fn (CarbonImmutable $date): string => $date->toDateString())
            ->first();
    }

    public function lastAvailableDate(): ?CarbonImmutable
    {
        return collect($this->records)
            ->pluck('referenceDate')
            ->filter()
            ->sortByDesc(fn (CarbonImmutable $date): string => $date->toDateString())
            ->first();
    }

    public function countBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return count($this->recordsBetween($from, $to));
    }

    /** @return list<CdiSourceRecord> */
    public function recordsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return collect($this->records)
            ->filter(fn (CdiSourceRecord $record): bool => $record->referenceDate !== null
                && $record->referenceDate->betweenIncluded($from, $to))
            ->sortBy(fn (CdiSourceRecord $record): string => sprintf(
                '%s|%s',
                $record->referenceDate?->toDateString() ?? '',
                $record->sourceReference,
            ))
            ->values()
            ->all();
    }

    public function normalizedChecksum(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $manifest = collect($this->recordsBetween($from, $to))
            ->map(fn (CdiSourceRecord $record): array => [
                'date' => $record->referenceDate?->toDateString(),
                'value' => $record->normalizedValue,
                'issue' => $record->issue,
            ])
            ->all();

        return hash('sha256', (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function rawPayloadChecksum(): string
    {
        $manifest = collect($this->payloads)
            ->map(fn (array $payload): array => [
                'source_reference' => $payload['source_reference'] ?? $payload['url'] ?? null,
                'sha256' => $payload['sha256'] ?? null,
            ])
            ->sortBy('source_reference')
            ->values()
            ->all();

        return hash('sha256', (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'requested_from' => $this->requestedFrom->toDateString(),
            'requested_to' => $this->requestedTo->toDateString(),
            'available_from' => $this->firstAvailableDate()?->toDateString(),
            'available_to' => $this->lastAvailableDate()?->toDateString(),
            'captured_at' => $this->capturedAt->toIso8601String(),
            'records' => array_map(
                fn (CdiSourceRecord $record): array => $record->toArray(),
                $this->records,
            ),
            'payloads' => $this->payloads,
            'issues' => $this->issues,
            'metadata' => $this->metadata,
            'raw_payload_checksum' => $this->rawPayloadChecksum(),
        ];
    }
}
