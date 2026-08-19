<?php

namespace App\Jobs;

use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeReconciliationOutcome;
use App\Enums\LegalDocumentType;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\GuaranteeGenerationRun;
use App\Services\GeminiService;
use App\Services\Guarantees\GuaranteeConflictDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Identifica garantias num documento da emissão e as registra como candidatas
 * pendentes de revisão (§3, §4 e §33 do escopo).
 *
 * O reprocessamento nunca apaga garantia confirmada nem candidata já revisada:
 * as pendentes anteriores do mesmo documento passam a `superseded`, deixando
 * rastro do que havia sido proposto antes.
 */
class GenerateEmissionGuaranteesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Orçamento de tempo do job. Precisa comportar o pior caso do retry do
     * Gemini (`services.gemini.max_attempts` tentativas, cada uma podendo levar
     * minutos sob sobrecarga) e ficar ABAIXO do `retry_after` da fila (900s),
     * senão o Redis reentrega o job ao segundo worker enquanto o primeiro ainda
     * roda e a extração grava as garantias duas vezes.
     */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
        public readonly int $documentId,
        public readonly ?int $runId = null,
    ) {}

    public function handle(GeminiService $geminiService, GuaranteeConflictDetector $conflictDetector): void
    {
        $emission = Emission::findOrFail($this->emissionId);
        $document = Document::findOrFail($this->documentId);

        $this->updateRun([
            'status' => GuaranteeGenerationRun::STATUS_RUNNING,
            'current_step' => 'extracting',
            'message' => 'Identificando garantias no documento...',
            'started_at' => now(),
        ]);

        try {
            $proposals = $geminiService->extractGuarantees($document);

            $this->updateRun([
                'current_step' => 'analysing',
                'message' => 'Comparando com as garantias já confirmadas...',
            ]);

            $documentType = $this->resolveDocumentType($emission, $document);
            $documentDate = $this->resolveDocumentDate($emission, $document);

            $existingGuarantees = $emission->guarantees()->with('documentReferences')->get();

            $this->supersedePendingCandidates($emission, $document);

            $detected = 0;
            $conflicts = 0;
            $consolidations = 0;

            foreach ($proposals as $proposal) {
                $analysis = $conflictDetector->analyse(
                    proposal: $proposal,
                    existingGuarantees: $existingGuarantees,
                    documentType: $documentType,
                    documentDate: $documentDate,
                );

                try {
                    ExtractedGuarantee::create(array_merge($proposal, [
                        'emission_id' => $emission->id,
                        'document_id' => $document->id,
                        'status' => GuaranteeDetectionStatus::Suggested,
                        'document_type' => $documentType,
                        'document_date' => $documentDate,
                        'related_guarantee_id' => $analysis['related_guarantee_id'],
                        'has_conflict' => $analysis['has_conflict'],
                        'conflict_reason' => $analysis['conflict_reason'],
                        'reconciliation_outcome' => $analysis['reconciliation_outcome'],
                        'match_score' => $analysis['match_score'],
                        'match_level' => $analysis['match_level'],
                        'match_evidence' => $analysis['match_evidence'],
                    ]));

                    $detected++;
                    $conflicts += $analysis['has_conflict'] ? 1 : 0;
                    $outcome = GuaranteeReconciliationOutcome::from($analysis['reconciliation_outcome']);
                    $consolidations += $outcome->pointsToExistingGuarantee() ? 1 : 0;
                } catch (\Throwable $e) {
                    Log::warning('GenerateEmissionGuaranteesJob: candidata ignorada', [
                        'emission_id' => $this->emissionId,
                        'document_id' => $this->documentId,
                        'name' => $proposal['name'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->updateRun([
                'status' => GuaranteeGenerationRun::STATUS_COMPLETED,
                'current_step' => 'completed',
                'message' => $this->buildCompletionMessage($detected, $conflicts, $consolidations),
                'detected_count' => $detected,
                'conflict_count' => $conflicts,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateEmissionGuaranteesJob falhou', [
                'emission_id' => $this->emissionId,
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            $this->markRunFailed($e);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->markRunFailed($exception);
    }

    /**
     * Candidatas pendentes do mesmo documento saem de cena, mas ficam
     * registradas. Confirmadas e rejeitadas não são tocadas: apagá-las
     * destruiria decisão humana já tomada.
     */
    private function supersedePendingCandidates(Emission $emission, Document $document): void
    {
        $emission->extractedGuarantees()
            ->where('document_id', $document->id)
            ->where('status', GuaranteeDetectionStatus::Suggested->value)
            ->update([
                'status' => GuaranteeDetectionStatus::Superseded->value,
                'updated_at' => now(),
            ]);
    }

    private function resolveDocumentType(Emission $emission, Document $document): ?LegalDocumentType
    {
        $value = $emission->documents()
            ->wherePivot('document_id', $document->id)
            ->first()
            ?->pivot
            ?->legal_document_type;

        return $value === null ? null : LegalDocumentType::tryFrom((string) $value);
    }

    private function resolveDocumentDate(Emission $emission, Document $document): ?string
    {
        $pivot = $emission->documents()
            ->wherePivot('document_id', $document->id)
            ->first()
            ?->pivot;

        return $pivot?->document_date ?? $pivot?->signed_at;
    }

    /**
     * Resumo da execução.
     *
     * Correspondência com garantia já cadastrada é dita separadamente do
     * conflito: são situações diferentes, e a maioria delas termina em
     * complemento, não em disputa.
     */
    private function buildCompletionMessage(int $detected, int $conflicts, int $consolidations): string
    {
        if ($detected === 0) {
            return 'Nenhuma garantia identificada neste documento.';
        }

        $message = "{$detected} garantia(s) identificada(s) e pendente(s) de revisão.";

        if ($consolidations > 0) {
            $message .= " {$consolidations} corresponde(m) a garantia(s) já cadastrada(s).";
        }

        return $conflicts > 0
            ? $message." {$conflicts} exige(m) atenção por conflito documental."
            : $message;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateRun(array $attributes): void
    {
        if ($this->runId === null) {
            return;
        }

        GuaranteeGenerationRun::query()
            ->whereKey($this->runId)
            ->update($attributes);
    }

    private function markRunFailed(\Throwable $exception): void
    {
        $this->updateRun([
            'status' => GuaranteeGenerationRun::STATUS_FAILED,
            'current_step' => 'failed',
            'message' => 'Não foi possível concluir a identificação de garantias. Tente novamente ou acione o suporte técnico.',
            'error_message' => Str::limit($exception->getMessage(), 500),
            'finished_at' => now(),
        ]);
    }
}
