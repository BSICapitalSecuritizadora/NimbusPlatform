<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\GuaranteeFieldDelta;
use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentFieldValueType;
use App\Models\ExtractedGuarantee;
use App\Models\Guarantee;
use App\Models\LegalInstrumentField;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Grava a vigência por campo da garantia (§11 do escopo de consolidação).
 *
 * Reusa `legal_instrument_fields`, que já é uma tabela de *versões de campo*
 * append-only, com proveniência e `effective_date` — a mesma estrutura que
 * responde, para o instrumento, "qual o valor vigente, qual era o anterior,
 * qual documento alterou e desde quando vale". A garantia precisa exatamente
 * disso, e a tabela já prevê `guarantee_id`.
 *
 * O que este serviço acrescenta é o caminho de escrita a partir da
 * consolidação: cada campo complementado ou alterado vira uma linha
 * `confirmed`, e a versão anterior do mesmo campo passa a `superseded` em vez
 * de ser apagada. Daí sai o "vigente até 14/05/2026 / vigente desde 15/05/2026"
 * sem estrutura nova.
 */
class GuaranteeFieldVersionWriter
{
    /**
     * Campos da garantia que têm vocabulário controlado no versionamento.
     *
     * O que não está aqui continua registrado no evento e na auditoria — o
     * mapeamento é conservador de propósito: inventar equivalência entre campos
     * de significado diferente estragaria a comparação entre versões, que é a
     * única razão de o vocabulário ser controlado.
     *
     * @var array<string, LegalInstrumentFieldKey>
     */
    private const FIELD_KEYS = [
        'account' => LegalInstrumentFieldKey::AccountNumber,
        'agency' => LegalInstrumentFieldKey::Agency,
        'bank' => LegalInstrumentFieldKey::Bank,
        'registration_number' => LegalInstrumentFieldKey::PropertyRegistration,
        'registry_office' => LegalInstrumentFieldKey::RegistryOffice,
        'company' => LegalInstrumentFieldKey::Company,
        'grantor' => LegalInstrumentFieldKey::FiduciaryGrantor,
        'assigned_percentage' => LegalInstrumentFieldKey::AssignedPercentage,
        'pledged_percentage' => LegalInstrumentFieldKey::QuotaPercentage,
        'quota_quantity' => LegalInstrumentFieldKey::QuotaQuantity,
        'contracted_value' => LegalInstrumentFieldKey::GuaranteeValue,
        'requirement_percentage' => LegalInstrumentFieldKey::MinimumCoverage,
        'validity_end_date' => LegalInstrumentFieldKey::MaturityDate,
    ];

    /**
     * Registra a nova vigência de cada campo trazido pelo documento.
     *
     * @param  list<GuaranteeFieldDelta>  $deltas
     * @return list<LegalInstrumentField>
     */
    public function record(
        Guarantee $guarantee,
        array $deltas,
        ExtractedGuarantee $source,
        User $actor,
    ): array {
        $effectiveDate = $source->effective_date ?? $source->document_date;
        $versions = [];

        foreach ($deltas as $delta) {
            $key = self::FIELD_KEYS[$delta->field] ?? null;

            if ($key === null) {
                continue;
            }

            $versions[] = $this->writeVersion($guarantee, $key, $delta, $source, $actor, $effectiveDate);
        }

        return $versions;
    }

    /**
     * Linha do tempo por campo, já com o intervalo de vigência resolvido.
     *
     * A data de fim não é gravada: ela é o começo da versão seguinte. Guardá-la
     * criaria duas fontes para o mesmo fato, e bastaria uma correção de data
     * para as duas discordarem.
     *
     * @return Collection<string, array{key: LegalInstrumentFieldKey, current: LegalInstrumentField|null, versions: list<array{field: LegalInstrumentField, valid_from: Carbon|null, valid_until: Carbon|null, is_current: bool}>}>
     */
    public function timeline(Guarantee $guarantee): Collection
    {
        return $guarantee->instrumentFields
            ->reject(fn (LegalInstrumentField $field): bool => $field->status === LegalInstrumentFieldStatus::Rejected)
            ->groupBy(fn (LegalInstrumentField $field): string => $field->field_key?->value ?? 'sem-campo')
            ->map(function (Collection $fields): array {
                $ordered = $fields
                    ->sortBy([
                        fn (LegalInstrumentField $a, LegalInstrumentField $b): int => ($a->effective_date?->toDateString() ?? '') <=> ($b->effective_date?->toDateString() ?? ''),
                        fn (LegalInstrumentField $a, LegalInstrumentField $b): int => $a->getKey() <=> $b->getKey(),
                    ])
                    ->values();

                $versions = [];

                foreach ($ordered as $index => $field) {
                    $next = $ordered->get($index + 1);

                    $versions[] = [
                        'field' => $field,
                        'valid_from' => $field->effective_date,
                        'valid_until' => $next?->effective_date,
                        'is_current' => $field->status === LegalInstrumentFieldStatus::Confirmed && $next === null,
                    ];
                }

                return [
                    'key' => $ordered->last()->field_key,
                    'current' => $ordered->last(fn (LegalInstrumentField $field): bool => $field->status === LegalInstrumentFieldStatus::Confirmed),
                    'versions' => array_reverse($versions),
                ];
            })
            ->sortBy(fn (array $entry): string => $entry['key']?->label() ?? '');
    }

    private function writeVersion(
        Guarantee $guarantee,
        LegalInstrumentFieldKey $key,
        GuaranteeFieldDelta $delta,
        ExtractedGuarantee $source,
        User $actor,
        mixed $effectiveDate,
    ): LegalInstrumentField {
        $previous = $this->currentVersion($guarantee, $key);
        $valueType = $key->valueType();

        $numeric = is_numeric($delta->newValue)
            ? $valueType->normalizeNumeric((float) $delta->newValue)
            : null;

        $version = LegalInstrumentField::create([
            // Pode não haver instrumento: a garantia pode ter sido identificada
            // num documento avulso, e mesmo assim seu campo tem vigência.
            'legal_instrument_id' => $guarantee->legal_instrument_id ?? $source->legal_instrument_id,
            'guarantee_id' => $guarantee->getKey(),
            'field_key' => $key,
            'value_type' => $valueType,
            'value' => $this->textValue($delta->newValue),
            'value_numeric' => $numeric,
            'value_date' => $valueType === LegalInstrumentFieldValueType::Date
                ? $this->dateValue($delta->newValue)
                : null,
            'effective_date' => $effectiveDate,
            // Nasce confirmada: quem chama aqui é a confirmação humana da
            // consolidação, não a extração.
            'status' => LegalInstrumentFieldStatus::Confirmed,
            'evidence_level' => $source->evidenceFor($delta->field) === GuaranteeEvidenceLevel::NotFound
                ? GuaranteeEvidenceLevel::Explicit
                : $source->evidenceFor($delta->field),
            'confidence_score' => $source->confidence_score,
            'legal_instrument_document_id' => $source->legal_instrument_document_id,
            'document_id' => $source->document_id,
            'clause' => $source->source_clause,
            'page' => $source->source_page,
            'excerpt' => $source->source_excerpt,
            'supersedes_id' => $previous?->getKey(),
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ]);

        $previous?->forceFill(['status' => LegalInstrumentFieldStatus::Superseded])->save();

        return $version;
    }

    private function currentVersion(Guarantee $guarantee, LegalInstrumentFieldKey $key): ?LegalInstrumentField
    {
        return LegalInstrumentField::query()
            ->where('guarantee_id', $guarantee->getKey())
            ->where('field_key', $key->value)
            ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    private function textValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
