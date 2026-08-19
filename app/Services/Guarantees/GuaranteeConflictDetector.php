<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\GuaranteeMatch;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeReconciliationOutcome;
use App\Enums\LegalDocumentType;
use App\Models\Guarantee;
use Illuminate\Support\Collection;

/**
 * Liga uma garantia detectada às garantias já confirmadas e classifica o que o
 * documento representa para elas (§34 e §35 do escopo).
 *
 * Antes, encontrar uma garantia parecida bastava para marcar conflito, e a
 * revisão só oferecia criar outra ou rejeitar — as duas saídas perdiam
 * informação, e a mesma garantia citada em Termo, CCB e aditamento virava três
 * cadastros. Agora a correspondência é procurada primeiro
 * ({@see GuaranteeMatcher}) e o que o documento acrescenta é classificado
 * ({@see GuaranteeConsolidationPlanner}): complemento, confirmação, alteração
 * ou — só então — conflito.
 *
 * O módulo continua sem escolher sozinho entre duas informações divergentes: o
 * que mudou é que divergência deixou de ser o caso comum.
 */
class GuaranteeConflictDetector
{
    public function __construct(
        private readonly GuaranteeMatcher $matcher,
        private readonly GuaranteeConsolidationPlanner $planner,
    ) {}

    /**
     * Resultado da análise de uma candidata.
     *
     * @param  array<string, mixed>  $proposal
     * @param  Collection<int, Guarantee>  $existingGuarantees
     * @return array{related_guarantee_id: int|null, has_conflict: bool, conflict_reason: string|null, reconciliation_outcome: string, match_score: float|null, match_level: string|null, match_evidence: array<int, string>|null}
     */
    public function analyse(
        array $proposal,
        Collection $existingGuarantees,
        ?LegalDocumentType $documentType,
        ?string $documentDate,
    ): array {
        $eventType = $this->resolveEventType($proposal['event_type'] ?? null);

        $proposal['document_date'] ??= $documentDate;

        $match = $this->matcher->match($proposal, $this->consolidatable($existingGuarantees));

        if ($match?->suggestsConsolidation() === true) {
            return $this->analyseAgainstMatch($proposal, $match);
        }

        // Sem correspondência utilizável, o que resta é decidir se a candidata
        // pode virar garantia nova. Um aditamento que altera, reforça ou libera
        // sem garantia correspondente não pode: criar uma garantia a partir
        // dele inverteria a cadeia documental.
        if ($eventType !== GuaranteeEventType::Constitution) {
            return $this->conflict($match, sprintf(
                'O documento indica %s, mas nenhuma garantia cadastrada corresponde à identificação extraída.',
                mb_strtolower($eventType->label()),
            ));
        }

        if ($documentType !== null && ! $documentType->canConstituteGuarantees()) {
            return $this->conflict($match, sprintf(
                'O documento é %s e não constitui garantias por si só, mas a extração propôs uma constituição.',
                $documentType->label(),
            ));
        }

        return [
            'related_guarantee_id' => $match?->guarantee->getKey(),
            'has_conflict' => false,
            'conflict_reason' => null,
            'reconciliation_outcome' => GuaranteeReconciliationOutcome::NewGuarantee->value,
            'match_score' => $match?->score,
            'match_level' => $match?->level->value,
            'match_evidence' => $match?->evidence,
        ];
    }

    /**
     * Classifica a candidata contra a garantia correspondente.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{related_guarantee_id: int|null, has_conflict: bool, conflict_reason: string|null, reconciliation_outcome: string, match_score: float|null, match_level: string|null, match_evidence: array<int, string>|null}
     */
    private function analyseAgainstMatch(array $proposal, GuaranteeMatch $match): array
    {
        $outcome = $this->planner->planForProposal($proposal, $match->guarantee, $match)->outcome;

        return [
            'related_guarantee_id' => $match->guarantee->getKey(),
            'has_conflict' => $outcome === GuaranteeReconciliationOutcome::Conflict,
            'conflict_reason' => $this->describe($outcome, $match),
            'reconciliation_outcome' => $outcome->value,
            'match_score' => $match->score,
            'match_level' => $match->level->value,
            'match_evidence' => $match->evidence,
        ];
    }

    /**
     * Frase curta que a listagem mostra. O detalhe do impacto é recalculado na
     * revisão, contra o cadastro do momento — congelar aqui o que será aplicado
     * depois arriscaria descrever um estado que já mudou.
     */
    private function describe(GuaranteeReconciliationOutcome $outcome, GuaranteeMatch $match): string
    {
        $name = $match->guarantee->display_name;

        return match ($outcome) {
            GuaranteeReconciliationOutcome::Complement => sprintf(
                'Provavelmente é a garantia já cadastrada "%s". O documento traz informações que ainda não constam no cadastro.',
                $name,
            ),
            GuaranteeReconciliationOutcome::Confirmation => sprintf(
                'Confirma, em novo documento, informações já cadastradas na garantia "%s".',
                $name,
            ),
            GuaranteeReconciliationOutcome::Change => sprintf(
                'O documento altera informação vigente da garantia "%s". A posição anterior é preservada no histórico.',
                $name,
            ),
            GuaranteeReconciliationOutcome::Conflict => sprintf(
                'O documento diverge do que está cadastrado na garantia "%s" sem indicar alteração. Decida antes de aplicar.',
                $name,
            ),
            GuaranteeReconciliationOutcome::NewGuarantee => 'Nenhuma garantia cadastrada corresponde à identificada neste documento.',
        };
    }

    /**
     * @return array{related_guarantee_id: int|null, has_conflict: bool, conflict_reason: string|null, reconciliation_outcome: string, match_score: float|null, match_level: string|null, match_evidence: array<int, string>|null}
     */
    private function conflict(?GuaranteeMatch $match, string $reason): array
    {
        return [
            'related_guarantee_id' => $match?->guarantee->getKey(),
            'has_conflict' => true,
            'conflict_reason' => $reason,
            'reconciliation_outcome' => GuaranteeReconciliationOutcome::Conflict->value,
            'match_score' => $match?->score,
            'match_level' => $match?->level->value,
            'match_evidence' => $match?->evidence,
        ];
    }

    /**
     * Garantias elegíveis a receber a candidata.
     *
     * Liberadas, substituídas e encerradas ficam de fora: um documento novo não
     * ressuscita garantia extinta, e apontar para ela faria a revisão sugerir
     * complementar o que já saiu da operação.
     *
     * @param  Collection<int, Guarantee>  $guarantees
     * @return Collection<int, Guarantee>
     */
    private function consolidatable(Collection $guarantees): Collection
    {
        return $guarantees
            ->reject(fn (Guarantee $guarantee): bool => $guarantee->legal_status?->isClosed() ?? false)
            ->values();
    }

    private function resolveEventType(mixed $value): GuaranteeEventType
    {
        if ($value instanceof GuaranteeEventType) {
            return $value;
        }

        return GuaranteeEventType::tryFrom((string) $value) ?? GuaranteeEventType::Constitution;
    }
}
