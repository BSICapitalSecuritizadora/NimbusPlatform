<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\ObligationGenerationRun;
use App\Services\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateEmissionObligationsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
        public readonly int $documentId,
        public readonly ?int $runId = null,
    ) {}

    public function handle(GeminiService $geminiService): void
    {
        $emission = Emission::findOrFail($this->emissionId);
        $document = Document::findOrFail($this->documentId);

        $this->updateRun([
            'status' => ObligationGenerationRun::STATUS_RUNNING,
            'current_step' => 'extracting',
            'message' => 'Extraindo obrigações do documento...',
            'started_at' => now(),
        ]);

        try {
            $proposals = $geminiService->extractObligations($document);

            $this->updateRun([
                'current_step' => 'saving',
                'message' => 'Validando e salvando obrigações geradas...',
            ]);

            $emission->extractedObligations()
                ->where('status', 'suggested')
                ->delete();

            $created = 0;

            foreach ($proposals as $proposal) {
                try {
                    ExtractedObligation::create(array_merge($proposal, [
                        'emission_id' => $emission->id,
                        'document_id' => $document->id,
                        'status' => 'suggested',
                    ]));

                    $created++;
                } catch (\Throwable $e) {
                    Log::warning('GenerateEmissionObligationsJob: sugestão ignorada', [
                        'emission_id' => $this->emissionId,
                        'document_id' => $this->documentId,
                        'title' => $proposal['title'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->updateRun([
                'status' => ObligationGenerationRun::STATUS_COMPLETED,
                'current_step' => 'completed',
                'message' => "Geração concluída com sucesso. {$created} obrigação(ões) sugerida(s).",
                'generated_count' => $created,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateEmissionObligationsJob falhou', [
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
     * @param  array<string, mixed>  $attributes
     */
    protected function updateRun(array $attributes): void
    {
        if ($this->runId === null) {
            return;
        }

        ObligationGenerationRun::query()
            ->whereKey($this->runId)
            ->update($attributes);
    }

    protected function markRunFailed(\Throwable $exception): void
    {
        $this->updateRun([
            'status' => ObligationGenerationRun::STATUS_FAILED,
            'current_step' => 'failed',
            'message' => 'Não foi possível concluir a geração das obrigações. Tente novamente ou acione o suporte técnico.',
            'error_message' => Str::limit($exception->getMessage(), 500),
            'finished_at' => now(),
        ]);
    }
}
