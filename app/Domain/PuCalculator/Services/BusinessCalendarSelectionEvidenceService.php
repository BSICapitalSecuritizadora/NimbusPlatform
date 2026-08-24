<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\BusinessCalendarSelectionEvidence;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class BusinessCalendarSelectionEvidenceService
{
    public function __construct(private readonly BusinessCalendarCatalogService $catalog) {}

    /**
     * @param  array{source_document?:?string,clause_reference?:?string,page_reference?:?string,excerpt?:?string,notes?:?string}  $evidence
     */
    public function record(
        Model $subject,
        string $context,
        string $calendarCode,
        array $evidence,
        ?int $createdByUserId,
        bool $confirmed = false,
    ): BusinessCalendarSelectionEvidence {
        if (! $subject->exists) {
            throw new InvalidArgumentException('A evidência só pode ser vinculada a uma configuração persistida.');
        }

        if (blank($context)) {
            throw new InvalidArgumentException('O contexto da seleção de calendário é obrigatório.');
        }

        $calendar = $this->catalog->findOrFail($calendarCode);

        return BusinessCalendarSelectionEvidence::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'context' => trim($context),
            'calendar_code' => $calendar->code,
            'source_document' => $this->nullableTrim($evidence['source_document'] ?? null),
            'clause_reference' => $this->nullableTrim($evidence['clause_reference'] ?? null),
            'page_reference' => $this->nullableTrim($evidence['page_reference'] ?? null),
            'excerpt' => $this->nullableTrim($evidence['excerpt'] ?? null),
            'notes' => $this->nullableTrim($evidence['notes'] ?? null),
            'confirmed_by' => $confirmed ? $createdByUserId : null,
            'confirmed_at' => $confirmed ? now() : null,
            'created_by' => $createdByUserId,
        ]);
    }

    private function nullableTrim(?string $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }
}
