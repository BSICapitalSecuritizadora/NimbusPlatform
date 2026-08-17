<?php

namespace App\Services\LegalInstruments;

use App\Models\ExtractedObligation;
use App\Models\LegalInstrumentDocument;
use Illuminate\Support\Collection;

/**
 * Converte o bloco de obrigações da extração em sugestões pendentes (§30).
 *
 * Reaproveita `extracted_obligations` e o fluxo de aprovação que o módulo de
 * Obrigações já tem — a sugestão vinda de uma CCB entra na mesma fila das que
 * vêm do Termo, e nenhuma vira obrigação sem confirmação humana.
 */
class InstrumentObligationCollector
{
    /**
     * @param  array<int, mixed>  $rawObligations
     * @return array{created: int, skipped: int}
     */
    public function collect(LegalInstrumentDocument $instrumentDocument, array $rawObligations): array
    {
        $emission = $instrumentDocument->instrument->emission;
        $existing = $this->existingTitles($instrumentDocument);

        $created = 0;
        $skipped = 0;

        foreach ($rawObligations as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $title = trim((string) ($raw['title'] ?? ''));
            $excerpt = trim((string) ($raw['excerpt'] ?? ''));

            // O módulo de Obrigações exige trecho literal para revisão; sem ele
            // a sugestão não seria conferível.
            if ($title === '' || $excerpt === '') {
                $skipped++;

                continue;
            }

            if ($existing->contains($this->normalizeTitle($title))) {
                $skipped++;

                continue;
            }

            ExtractedObligation::create([
                'emission_id' => $emission->getKey(),
                'document_id' => $instrumentDocument->document_id,
                'title' => mb_substr($title, 0, 255),
                'obligation_category' => 'Garantias',
                'obligation_type' => $instrumentDocument->instrument->type->shortLabel(),
                'description' => $this->text($raw['description'] ?? null),
                'recurrence' => $this->text($raw['recurrence'] ?? null, 255),
                'due_rule' => $this->text($raw['due_rule'] ?? null),
                'priority' => 'medium',
                'status' => ExtractedObligation::STATUS_SUGGESTED,
                'source_clause' => $this->text($raw['clause'] ?? null, 255),
                'source_page' => is_numeric($raw['page'] ?? null) ? (int) $raw['page'] : null,
                'source_excerpt' => mb_substr($excerpt, 0, 2000),
                'confidence_score' => is_numeric($raw['confidence_score'] ?? null)
                    ? max(0, min(1, (float) $raw['confidence_score']))
                    : null,
                'review_notes' => sprintf(
                    'Identificada em %s do instrumento %s.',
                    mb_strtolower($instrumentDocument->role_label),
                    $instrumentDocument->instrument->display_name,
                ),
            ]);

            $existing->push($this->normalizeTitle($title));
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Títulos já sugeridos para o mesmo documento, para o reprocessamento não
     * duplicar a fila de revisão.
     *
     * @return Collection<int, string>
     */
    private function existingTitles(LegalInstrumentDocument $instrumentDocument): Collection
    {
        return ExtractedObligation::query()
            ->where('emission_id', $instrumentDocument->instrument->emission_id)
            ->where('document_id', $instrumentDocument->document_id)
            ->pluck('title')
            ->map(fn (string $title): string => $this->normalizeTitle($title))
            ->values();
    }

    private function normalizeTitle(string $title): string
    {
        $normalized = mb_strtolower(trim($title));

        return (string) preg_replace('/\s+/u', ' ', $normalized);
    }

    private function text(mixed $value, ?int $maxLength = null): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $maxLength === null ? $value : mb_substr($value, 0, $maxLength);
    }
}
