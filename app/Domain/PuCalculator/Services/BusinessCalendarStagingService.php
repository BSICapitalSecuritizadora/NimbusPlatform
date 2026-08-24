<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarStagingBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BusinessCalendarStagingService
{
    public function __construct(private readonly BusinessCalendarCatalogService $catalog) {}

    /**
     * Armazena fatos candidatos sem alterar business_calendar_dates nem confirmar o ano.
     *
     * @param  list<array{date:string,is_business_day:bool,description?:?string}>  $rows
     */
    public function stage(
        string $calendarCode,
        int $year,
        array $rows,
        string $source,
        string $sourceDocument,
        ?string $sourceRevision,
        ?int $stagedByUserId,
        bool $sourceIsOfficial = true,
    ): BusinessCalendarStagingBatch {
        $calendar = $this->catalog->findOrFail($calendarCode);

        if ($calendar->code === BusinessCalendarRegistry::B3_LISTED_TRADING && str_contains(strtolower($source), 'anbima')) {
            throw new InvalidArgumentException('B3_LISTED_TRADING não pode receber dados ANBIMA nem no staging.');
        }

        if ($rows === []) {
            throw new InvalidArgumentException('O lote de staging deve conter ao menos uma data.');
        }

        $normalizedRows = [];

        foreach ($rows as $row) {
            if (! isset($row['date']) || ! array_key_exists('is_business_day', $row) || ! is_bool($row['is_business_day'])) {
                throw new InvalidArgumentException('Cada data de staging deve informar date e is_business_day booleano.');
            }

            $date = CarbonImmutable::parse($row['date']);

            if ($date->year !== $year) {
                throw new InvalidArgumentException(sprintf('A data %s não pertence ao ano %d.', $date->toDateString(), $year));
            }

            if (isset($normalizedRows[$date->toDateString()])) {
                throw new InvalidArgumentException(sprintf('A data %s está duplicada no lote de staging.', $date->toDateString()));
            }

            $normalizedRows[$date->toDateString()] = [
                'calendar_date' => $date->toDateString(),
                'is_business_day' => $row['is_business_day'],
                'description' => filled($row['description'] ?? null) ? trim((string) $row['description']) : null,
            ];
        }

        ksort($normalizedRows);
        $checksum = hash('sha256', (string) json_encode(array_values($normalizedRows), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $calendar,
            $year,
            $normalizedRows,
            $source,
            $sourceDocument,
            $sourceRevision,
            $stagedByUserId,
            $sourceIsOfficial,
            $checksum,
        ): BusinessCalendarStagingBatch {
            $batch = BusinessCalendarStagingBatch::query()->create([
                'batch_uuid' => (string) Str::uuid(),
                'calendar_code' => $calendar->code,
                'year' => $year,
                'source' => trim($source),
                'source_is_official' => $sourceIsOfficial,
                'source_document' => trim($sourceDocument),
                'source_revision' => filled($sourceRevision) ? trim((string) $sourceRevision) : null,
                'checksum' => $checksum,
                'status' => BusinessCalendarStagingBatch::STATUS_PENDING_REVIEW,
                'records_staged' => count($normalizedRows),
                'staged_by' => $stagedByUserId,
                'staged_at' => now(),
            ]);

            $batch->dates()->createMany(array_values($normalizedRows));

            return $batch->load('dates');
        });
    }

    public function approve(BusinessCalendarStagingBatch $batch, int $reviewedByUserId, string $reviewNotes): BusinessCalendarStagingBatch
    {
        if ($batch->status !== BusinessCalendarStagingBatch::STATUS_PENDING_REVIEW) {
            throw new InvalidArgumentException('Somente lotes pendentes podem ser aprovados.');
        }

        $batch->update([
            'status' => BusinessCalendarStagingBatch::STATUS_APPROVED,
            'reviewed_by' => $reviewedByUserId,
            'reviewed_at' => now(),
            'review_notes' => trim($reviewNotes),
        ]);

        return $batch->fresh(['dates', 'reviewedBy']);
    }

    public function reject(BusinessCalendarStagingBatch $batch, int $reviewedByUserId, string $reviewNotes): BusinessCalendarStagingBatch
    {
        if ($batch->status !== BusinessCalendarStagingBatch::STATUS_PENDING_REVIEW) {
            throw new InvalidArgumentException('Somente lotes pendentes podem ser rejeitados.');
        }

        $batch->update([
            'status' => BusinessCalendarStagingBatch::STATUS_REJECTED,
            'reviewed_by' => $reviewedByUserId,
            'reviewed_at' => now(),
            'review_notes' => trim($reviewNotes),
        ]);

        return $batch->fresh(['dates', 'reviewedBy']);
    }
}
