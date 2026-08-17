<?php

namespace App\Services\Guarantees;

use App\Enums\GuaranteeEventType;
use App\Enums\LegalDocumentType;
use App\Models\Guarantee;
use App\Models\GuaranteeDocumentReference;
use Illuminate\Support\Collection;

/**
 * Liga uma garantia detectada às garantias já confirmadas e sinaliza conflito
 * quando a relação não é clara (§34 e §35 do escopo).
 *
 * O módulo nunca escolhe sozinho entre duas informações divergentes: ou a
 * relação documental é evidente — e vira evolução histórica —, ou o caso vai
 * para revisão marcado como conflito.
 */
class GuaranteeConflictDetector
{
    /**
     * Resultado da análise de uma candidata.
     *
     * @param  array<string, mixed>  $proposal
     * @param  Collection<int, Guarantee>  $existingGuarantees
     * @return array{related_guarantee_id: int|null, has_conflict: bool, conflict_reason: string|null}
     */
    public function analyse(
        array $proposal,
        Collection $existingGuarantees,
        ?LegalDocumentType $documentType,
        ?string $documentDate,
    ): array {
        $eventType = GuaranteeEventType::tryFrom((string) ($proposal['event_type'] ?? ''))
            ?? GuaranteeEventType::Constitution;

        $match = $this->findMatchingGuarantee($proposal, $existingGuarantees);

        // Aditamento que propõe constituir garantia do zero: ou o extrator
        // classificou mal o evento, ou a garantia original nunca foi
        // confirmada. Nos dois casos alguém precisa olhar.
        if (
            $eventType === GuaranteeEventType::Constitution
            && $documentType !== null
            && ! $documentType->canConstituteGuarantees()
        ) {
            return [
                'related_guarantee_id' => $match?->getKey(),
                'has_conflict' => true,
                'conflict_reason' => sprintf(
                    'O documento é %s e não constitui garantias por si só, mas a extração propôs uma constituição.',
                    $documentType->label(),
                ),
            ];
        }

        if ($eventType === GuaranteeEventType::Constitution) {
            // Constituição que colide com garantia já confirmada do mesmo tipo
            // e mesma identificação é provável duplicata.
            if ($match !== null) {
                return [
                    'related_guarantee_id' => $match->getKey(),
                    'has_conflict' => true,
                    'conflict_reason' => sprintf(
                        'Já existe a garantia confirmada "%s" com a mesma identificação. Verifique se é constituição nova ou duplicata.',
                        $match->display_name,
                    ),
                ];
            }

            return ['related_guarantee_id' => null, 'has_conflict' => false, 'conflict_reason' => null];
        }

        // Alteração, reforço, substituição ou liberação sem garantia
        // correspondente: não há o que alterar, e criar uma garantia nova a
        // partir de um aditamento inverteria a cadeia documental.
        if ($match === null) {
            return [
                'related_guarantee_id' => null,
                'has_conflict' => true,
                'conflict_reason' => sprintf(
                    'O documento indica %s, mas nenhuma garantia confirmada corresponde à identificação extraída.',
                    mb_strtolower($eventType->label()),
                ),
            ];
        }

        $precedenceConflict = $this->detectPrecedenceConflict($match, $documentType, $documentDate);

        if ($precedenceConflict !== null) {
            return [
                'related_guarantee_id' => $match->getKey(),
                'has_conflict' => true,
                'conflict_reason' => $precedenceConflict,
            ];
        }

        return [
            'related_guarantee_id' => $match->getKey(),
            'has_conflict' => false,
            'conflict_reason' => null,
        ];
    }

    /**
     * Garantia confirmada que a candidata parece afetar.
     *
     * A identificação pesa mais que o nome: duas alienações fiduciárias de
     * imóvel só são a mesma garantia se a matrícula coincidir.
     *
     * @param  array<string, mixed>  $proposal
     * @param  Collection<int, Guarantee>  $existingGuarantees
     */
    private function findMatchingGuarantee(array $proposal, Collection $existingGuarantees): ?Guarantee
    {
        $type = $proposal['type'] ?? null;
        $identification = is_array($proposal['identification'] ?? null) ? $proposal['identification'] : [];

        $sameType = $existingGuarantees->filter(
            fn (Guarantee $guarantee): bool => $guarantee->type?->value === $type,
        );

        if ($sameType->isEmpty()) {
            return null;
        }

        foreach ($this->identityKeys() as $key) {
            $candidateValue = $this->normalizeIdentityValue($identification[$key] ?? null);

            if ($candidateValue === null) {
                continue;
            }

            $match = $sameType->first(function (Guarantee $guarantee) use ($key, $candidateValue): bool {
                $existingValue = $this->normalizeIdentityValue(($guarantee->identification ?? [])[$key] ?? null);

                return $existingValue !== null && $existingValue === $candidateValue;
            });

            if ($match instanceof Guarantee) {
                return $match;
            }
        }

        // Sem identificação comparável, um único candidato do mesmo tipo é
        // correspondência aceitável; mais de um seria adivinhação.
        return $sameType->count() === 1 ? $sameType->first() : null;
    }

    /**
     * Conflito de prioridade documental (§35): o documento que propõe a
     * alteração é anterior ao que já fundamenta a garantia.
     *
     * "Documento mais novo vence" não é presumido — o que se recusa aqui é o
     * inverso: deixar um instrumento antigo sobrescrever um mais recente sem
     * que ninguém perceba.
     */
    private function detectPrecedenceConflict(
        Guarantee $guarantee,
        ?LegalDocumentType $documentType,
        ?string $documentDate,
    ): ?string {
        if ($documentDate === null) {
            return null;
        }

        $latestReference = $guarantee->documentReferences
            ->filter(fn (GuaranteeDocumentReference $reference): bool => $reference->document_date !== null)
            ->sortByDesc(fn (GuaranteeDocumentReference $reference): string => $reference->document_date->toDateString())
            ->first();

        if (! $latestReference instanceof GuaranteeDocumentReference) {
            return null;
        }

        $latestDate = $latestReference->document_date->toDateString();

        if ($documentDate > $latestDate) {
            return null;
        }

        if ($documentDate === $latestDate) {
            $incomingWeight = $documentType?->specificityWeight() ?? 0;
            $existingWeight = $latestReference->document_type?->specificityWeight() ?? 0;

            return $incomingWeight >= $existingWeight
                ? null
                : 'Outro documento de mesma data e maior especificidade já fundamenta esta garantia.';
        }

        return sprintf(
            'O documento é de %s, anterior a %s, que fundamenta a posição vigente da garantia.',
            $documentDate,
            $latestDate,
        );
    }

    /**
     * @return array<int, string>
     */
    private function identityKeys(): array
    {
        return [
            'registration_number',
            'tax_id',
            'account',
            'portfolio',
            'policy_number',
            'company',
        ];
    }

    private function normalizeIdentityValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(trim((string) $value)));

        return ($normalized === null || $normalized === '') ? null : $normalized;
    }
}
