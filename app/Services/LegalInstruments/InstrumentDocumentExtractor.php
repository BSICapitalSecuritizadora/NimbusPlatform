<?php

namespace App\Services\LegalInstruments;

use App\DTOs\LegalInstruments\ConsolidatedFieldData;
use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentFieldValueType;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentField;
use App\Services\GeminiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Lê um documento do dossiê e registra o que ele diz como **propostas** de
 * alteração (§5 e §20 do escopo).
 *
 * Nada aqui altera a posição vigente: cada campo extraído vira uma linha
 * `pending_review`. Campos cujo valor coincide com o já confirmado são
 * descartados — repropor o que não mudou encheria a tela de revisão de ruído e
 * treinaria o usuário a aprovar sem ler.
 */
class InstrumentDocumentExtractor
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly InstrumentDocumentPromptBuilder $promptBuilder,
        private readonly InstrumentPositionResolver $positionResolver,
        private readonly InstrumentGuaranteeCollector $guaranteeCollector,
        private readonly InstrumentObligationCollector $obligationCollector,
    ) {}

    /**
     * @return array{fields: int, guarantees: int, obligations: int, conflicts: int, duplicates: int, effect_summary: string|null}
     */
    public function extract(LegalInstrumentDocument $instrumentDocument): array
    {
        $instrumentDocument->loadMissing(['instrument.emission', 'document']);

        $document = $instrumentDocument->document;

        if ($document === null) {
            throw new \RuntimeException('O vínculo do dossiê não aponta para um documento existente.');
        }

        $position = $this->positionResolver->resolve($instrumentDocument->instrument);
        $currentPosition = $this->describePosition($position->fields);

        $prompt = $this->promptBuilder->build($instrumentDocument, $currentPosition);
        $payload = $this->gemini->extractFromDocumentWithPrompt($document, $prompt);

        $fields = $this->persistFields($instrumentDocument, $payload['fields'] ?? []);

        $guarantees = $this->guaranteeCollector->collect(
            $instrumentDocument,
            is_array($payload['guarantees'] ?? null) ? $payload['guarantees'] : [],
        );

        $obligations = $this->obligationCollector->collect(
            $instrumentDocument,
            is_array($payload['obligations'] ?? null) ? $payload['obligations'] : [],
        );

        Log::info('InstrumentDocumentExtractor: documento processado', [
            'legal_instrument_id' => $instrumentDocument->legal_instrument_id,
            'legal_instrument_document_id' => $instrumentDocument->getKey(),
            'fields' => $fields['created'],
            'skipped_unchanged' => $fields['skipped'],
            'guarantees' => $guarantees['created'],
            'guarantee_duplicates' => $guarantees['duplicates'],
            'obligations' => $obligations['created'],
        ]);

        return [
            'fields' => $fields['created'],
            'guarantees' => $guarantees['created'],
            'obligations' => $obligations['created'],
            'conflicts' => $fields['conflicts'],
            'duplicates' => $guarantees['duplicates'],
            'effect_summary' => $this->nullableString($payload['effect_summary'] ?? null, 255),
        ];
    }

    /**
     * Posição vigente em texto, para o prompt comparar.
     *
     * @param  Collection<string, ConsolidatedFieldData>  $fields
     * @return array<string, string>
     */
    private function describePosition(Collection $fields): array
    {
        return $fields
            ->mapWithKeys(fn ($field): array => [$field->label() => $field->formattedValue()])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $rawFields
     * @return array{created: int, skipped: int, conflicts: int}
     */
    private function persistFields(LegalInstrumentDocument $instrumentDocument, array $rawFields): array
    {
        $created = 0;
        $skipped = 0;
        $conflicts = 0;

        $confirmed = $this->confirmedByKey($instrumentDocument);

        foreach ($rawFields as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $key = LegalInstrumentFieldKey::tryFrom((string) ($raw['field_key'] ?? ''));

            if ($key === null) {
                continue;
            }

            $candidate = $this->buildField($instrumentDocument, $key, $raw);

            if ($candidate === null) {
                continue;
            }

            // Já existe proposta pendente idêntica deste mesmo documento?
            // Reprocessar não deve empilhar duplicatas na fila de revisão.
            if ($this->hasEquivalentPendingProposal($instrumentDocument, $candidate)) {
                $skipped++;

                continue;
            }

            if ($candidate->hasSameValueAs($confirmed->get($key->value))) {
                $skipped++;

                continue;
            }

            $candidate->save();

            $created++;
            $conflicts += $candidate->has_conflict ? 1 : 0;
        }

        return ['created' => $created, 'skipped' => $skipped, 'conflicts' => $conflicts];
    }

    /**
     * @return Collection<string, LegalInstrumentField>
     */
    private function confirmedByKey(LegalInstrumentDocument $instrumentDocument): Collection
    {
        return $instrumentDocument->instrument
            ->fields()
            ->confirmed()
            ->whereNull('guarantee_id')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (LegalInstrumentField $field): string => $field->field_key?->value ?? '')
            ->keyBy(fn (LegalInstrumentField $field): string => $field->field_key?->value ?? '');
    }

    /**
     * Já existe proposta pendente com o mesmo valor para este campo?
     *
     * A busca cobre o instrumento inteiro, não só o documento em análise: a
     * mesma alteração costuma aparecer no aditamento e no instrumento de
     * garantia que o acompanha, e propor duas vezes obrigaria o revisor a
     * decidir duas vezes sobre o mesmo fato (§18).
     */
    private function hasEquivalentPendingProposal(
        LegalInstrumentDocument $instrumentDocument,
        LegalInstrumentField $candidate,
    ): bool {
        return $instrumentDocument->instrument
            ->fields()
            ->pendingReview()
            ->where('field_key', $candidate->field_key?->value)
            ->when(
                $candidate->guarantee_id === null,
                fn ($query) => $query->whereNull('guarantee_id'),
                fn ($query) => $query->where('guarantee_id', $candidate->guarantee_id),
            )
            ->get()
            ->contains(fn (LegalInstrumentField $existing): bool => $existing->hasSameValueAs($candidate));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildField(
        LegalInstrumentDocument $instrumentDocument,
        LegalInstrumentFieldKey $key,
        array $raw,
    ): ?LegalInstrumentField {
        $rawValue = $raw['value'] ?? null;

        if (! is_scalar($rawValue)) {
            return null;
        }

        $value = trim((string) $rawValue);

        if ($value === '') {
            return null;
        }

        $valueType = $key->valueType();
        $evidence = GuaranteeEvidenceLevel::tryFrom((string) ($raw['evidence_level'] ?? ''))
            ?? GuaranteeEvidenceLevel::Explicit;

        $confidence = $raw['confidence_score'] ?? null;
        $confidence = is_numeric($confidence) ? max(0, min(1, (float) $confidence)) : null;

        // O documento contradiz a si mesmo: nunca escolher por conta própria (§19).
        $isConflicting = $evidence === GuaranteeEvidenceLevel::Conflicting;

        $field = new LegalInstrumentField([
            'legal_instrument_id' => $instrumentDocument->legal_instrument_id,
            'field_key' => $key,
            'value_type' => $valueType,
            'value' => $value,
            'value_numeric' => $this->numericValue($valueType, $value),
            'value_date' => $this->dateValue($valueType, $value),
            'effective_date' => $this->effectiveDate($raw, $instrumentDocument),
            'status' => LegalInstrumentFieldStatus::PendingReview,
            'evidence_level' => $evidence,
            'confidence_score' => $confidence,
            'legal_instrument_document_id' => $instrumentDocument->getKey(),
            'document_id' => $instrumentDocument->document_id,
            'clause' => $this->nullableString($raw['clause'] ?? null, 255),
            'page' => is_numeric($raw['page'] ?? null) ? (int) $raw['page'] : null,
            'excerpt' => $this->nullableString($raw['excerpt'] ?? null, 2000),
            'has_conflict' => $isConflicting || ! $instrumentDocument->role->canAmendPosition(),
        ]);

        $field->conflict_reason = match (true) {
            $isConflicting => 'O documento apresenta informações divergentes para este campo.',
            ! $instrumentDocument->role->canAmendPosition() => sprintf(
                'O documento é %s e não altera a regra contratual por si só.',
                mb_strtolower($instrumentDocument->role->label()),
            ),
            default => null,
        };

        return $field;
    }

    /**
     * Converte o texto extraído em número.
     *
     * A vírgula é o que distingue os dois formatos que chegam aqui: o prompt
     * pede número puro ("1.3", "35000000"), mas o modelo às vezes devolve o
     * formato do documento ("R$ 35.000.000,00"). Só quando há vírgula o ponto é
     * separador de milhar — tratá-lo sempre assim transformaria "1.3" em 13.
     */
    private function numericValue(LegalInstrumentFieldValueType $valueType, string $value): ?float
    {
        if (! $valueType->isNumeric()) {
            return null;
        }

        $normalized = str_replace([' ', "\u{00A0}"], '', $value);

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = (string) preg_replace('/[^0-9.\-]/', '', $normalized);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return $valueType->normalizeNumeric((float) $normalized);
    }

    private function dateValue(LegalInstrumentFieldValueType $valueType, string $value): ?string
    {
        if ($valueType !== LegalInstrumentFieldValueType::Date) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Vigência da alteração: a informada pelo documento, ou a data do próprio
     * documento. Nunca "hoje" — usar a data do processamento faria uma
     * consulta retroativa enxergar alterações que ainda não valiam.
     *
     * @param  array<string, mixed>  $raw
     */
    private function effectiveDate(array $raw, LegalInstrumentDocument $instrumentDocument): ?string
    {
        $raw = $raw['effective_date'] ?? null;

        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) === 1) {
            return trim($raw);
        }

        return $instrumentDocument->document_date?->toDateString()
            ?? $instrumentDocument->signed_at?->toDateString();
    }

    private function nullableString(mixed $value, ?int $maxLength = null): ?string
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
