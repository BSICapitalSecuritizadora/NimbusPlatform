<?php

namespace App\Services\LegalInstruments;

use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeReconciliationOutcome;
use App\Enums\GuaranteeType;
use App\Models\ExtractedGuarantee;
use App\Models\LegalInstrumentDocument;
use App\Services\Guarantees\GuaranteeConflictDetector;
use App\Services\Guarantees\GuaranteeIdentificationNormalizer;
use App\Services\Guarantees\GuaranteeSuggestionReviewService;
use Illuminate\Support\Collection;

/**
 * Converte o bloco de garantias da extração em candidatas pendentes de revisão.
 *
 * Reaproveita integralmente o fluxo que já existe para garantias detectadas no
 * Termo: as candidatas entram em `extracted_guarantees` e são confirmadas por
 * {@see GuaranteeSuggestionReviewService}. O que muda é
 * o vínculo com o instrumento, para que a garantia confirmada nasça pendurada
 * na CCB (§14 do escopo).
 *
 * A detecção de duplicidade (§18) é do {@see GuaranteeConflictDetector}, que
 * compara identificadores fortes, tipo e finalidade econômica. A mesma
 * matrícula aparecendo no Termo, na CCB e no contrato de AFI não vira três
 * garantias: vira uma candidata apontando para a existente, classificada pelo
 * que o documento de fato acrescenta a ela.
 *
 * O contador `duplicates` conta correspondências com garantia já cadastrada —
 * candidatas cuja revisão deve terminar em complemento, não em cadastro novo.
 */
class InstrumentGuaranteeCollector
{
    public function __construct(
        private readonly GuaranteeConflictDetector $conflictDetector,
        private readonly GuaranteeIdentificationNormalizer $identificationNormalizer,
    ) {}

    /**
     * @param  array<int, mixed>  $rawGuarantees
     * @return array{created: int, duplicates: int, skipped: int}
     */
    public function collect(LegalInstrumentDocument $instrumentDocument, array $rawGuarantees): array
    {
        $instrument = $instrumentDocument->instrument;
        $emission = $instrument->emission;

        $existing = $emission->guarantees()->with('documentReferences')->get();
        $pending = $this->pendingCandidates($instrumentDocument);

        $created = 0;
        $duplicates = 0;
        $skipped = 0;

        foreach ($rawGuarantees as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $proposal = $this->normalize($raw, $instrumentDocument);

            if ($proposal === null) {
                $skipped++;

                continue;
            }

            // Reprocessar o mesmo documento não deve empilhar a mesma candidata.
            if ($this->alreadyProposed($pending, $proposal)) {
                $skipped++;

                continue;
            }

            $analysis = $this->conflictDetector->analyse(
                proposal: array_merge($proposal, ['legal_instrument_id' => $instrument->getKey()]),
                existingGuarantees: $existing,
                documentType: null,
                documentDate: $instrumentDocument->document_date?->toDateString(),
            );

            $outcome = GuaranteeReconciliationOutcome::from($analysis['reconciliation_outcome']);

            ExtractedGuarantee::create(array_merge($proposal, [
                'emission_id' => $emission->getKey(),
                'legal_instrument_id' => $instrument->getKey(),
                'legal_instrument_document_id' => $instrumentDocument->getKey(),
                'document_id' => $instrumentDocument->document_id,
                'status' => GuaranteeDetectionStatus::Suggested,
                'document_date' => $instrumentDocument->document_date,
                'related_guarantee_id' => $analysis['related_guarantee_id'],
                'has_conflict' => $analysis['has_conflict'],
                'conflict_reason' => $analysis['conflict_reason'],
                'reconciliation_outcome' => $outcome->value,
                'match_score' => $analysis['match_score'],
                'match_level' => $analysis['match_level'],
                'match_evidence' => $analysis['match_evidence'],
            ]));

            $created++;
            $duplicates += $outcome->pointsToExistingGuarantee() ? 1 : 0;
        }

        return ['created' => $created, 'duplicates' => $duplicates, 'skipped' => $skipped];
    }

    /**
     * @return Collection<int, ExtractedGuarantee>
     */
    private function pendingCandidates(LegalInstrumentDocument $instrumentDocument): Collection
    {
        return ExtractedGuarantee::query()
            ->where('legal_instrument_document_id', $instrumentDocument->getKey())
            ->pending()
            ->get();
    }

    /**
     * @param  Collection<int, ExtractedGuarantee>  $pending
     * @param  array<string, mixed>  $proposal
     */
    private function alreadyProposed(Collection $pending, array $proposal): bool
    {
        return $pending->contains(
            fn (ExtractedGuarantee $candidate): bool => $candidate->type?->value === ($proposal['type'] ?? null)
                && $this->identityFingerprint($candidate->identification ?? []) === $this->identityFingerprint($proposal['identification'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $identification
     */
    private function identityFingerprint(array $identification): string
    {
        $relevant = array_intersect_key($identification, array_flip([
            'registration_number', 'tax_id', 'account', 'portfolio', 'company', 'policy_number',
        ]));

        ksort($relevant);

        return (string) preg_replace(
            '/[^\p{L}\p{N}]/u',
            '',
            mb_strtolower(implode('|', array_map(static fn (mixed $v): string => (string) $v, $relevant))),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function normalize(array $raw, LegalInstrumentDocument $instrumentDocument): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        $excerpt = trim((string) ($raw['excerpt'] ?? ''));

        // Sem nome ou sem trecho literal não há como um revisor conferir a
        // proposta contra o documento.
        if ($name === '' || $excerpt === '') {
            return null;
        }

        $type = GuaranteeType::tryFrom((string) ($raw['type'] ?? ''));
        $event = GuaranteeEventType::tryFrom((string) ($raw['event'] ?? ''))
            ?? GuaranteeEventType::Constitution;

        $confidence = $raw['confidence_score'] ?? null;
        $confidence = is_numeric($confidence) ? max(0, min(1, (float) $confidence)) : null;

        return [
            'type' => $type?->value,
            'name' => mb_substr($name, 0, 255),
            'event_type' => $event->value,
            'identification' => $this->identificationNormalizer->normalize(
                is_array($raw['identification'] ?? null) ? $raw['identification'] : null,
                $type,
            ),
            'effective_date' => $this->date($raw['effective_date'] ?? null)
                ?? $instrumentDocument->document_date?->toDateString(),
            'source_clause' => $this->text($raw['clause'] ?? null, 255),
            'source_page' => is_numeric($raw['page'] ?? null) ? (int) $raw['page'] : null,
            'source_excerpt' => mb_substr($excerpt, 0, 2000),
            'confidence_score' => $confidence,
        ];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
