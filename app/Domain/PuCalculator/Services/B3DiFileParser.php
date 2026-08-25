<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\CdiSourceRecord;
use Carbon\CarbonImmutable;
use Throwable;

final class B3DiFileParser
{
    public function __construct(
        private readonly CdiRateNormalizer $normalizer,
    ) {}

    public function parse(string $fileName, string $content, ?string $sourceReference = null): CdiSourceRecord
    {
        $baseName = basename($fileName);
        $sourceReference ??= sprintf('ftp://ftp.cetip.com.br/MediaCDI/%s', $baseName);
        $payloadSha256 = hash('sha256', $content);

        if (preg_match('/^(\d{8})\.txt$/', $baseName, $matches) !== 1) {
            return new CdiSourceRecord(
                source: 'b3_di',
                referenceDate: null,
                sourceReference: $sourceReference,
                rawValue: $content,
                normalizedValue: null,
                issue: 'invalid_file_name',
                payloadSha256: $payloadSha256,
            );
        }

        $referenceDate = $this->parseDate($matches[1]);

        if ($referenceDate === null) {
            return new CdiSourceRecord(
                source: 'b3_di',
                referenceDate: null,
                sourceReference: $sourceReference,
                rawValue: $content,
                normalizedValue: null,
                issue: 'invalid_reference_date',
                payloadSha256: $payloadSha256,
            );
        }

        $normalized = $this->normalizer->fromB3EncodedValue($content);

        return new CdiSourceRecord(
            source: 'b3_di',
            referenceDate: $referenceDate,
            sourceReference: $sourceReference,
            rawValue: $content,
            normalizedValue: $normalized['value'],
            issue: $normalized['issue'],
            payloadSha256: $payloadSha256,
        );
    }

    /**
     * @param  list<CdiSourceRecord>  $records
     * @return list<array<string, mixed>>
     */
    public function duplicateIssues(array $records): array
    {
        return collect($records)
            ->filter(fn (CdiSourceRecord $record): bool => $record->referenceDate !== null)
            ->groupBy(fn (CdiSourceRecord $record): string => $record->referenceDate?->toDateString() ?? '')
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(fn ($group, string $date): array => [
                'issue' => 'duplicate_date',
                'reference_date' => $date,
                'source_references' => $group->pluck('sourceReference')->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Ymd', $value);

            return $date !== null && $date->format('Ymd') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }
}
