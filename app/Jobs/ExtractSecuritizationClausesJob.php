<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Emission;
use App\Services\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExtractSecuritizationClausesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
        public readonly int $documentId,
    ) {}

    public function handle(GeminiService $geminiService): void
    {
        $emission = Emission::findOrFail($this->emissionId);
        $document = Document::findOrFail($this->documentId);

        try {
            $fields = $geminiService->extractSecuritizationClauses($document);
            $emission->update(array_filter($fields, fn ($v): bool => filled($v)));
            Cache::put("gemini_extraction_{$this->emissionId}_status", 'completed', 1800);
        } catch (\Throwable $e) {
            Log::error('ExtractSecuritizationClausesJob falhou', [
                'emission_id' => $this->emissionId,
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            Cache::put("gemini_extraction_{$this->emissionId}_status", ['error' => $e->getMessage()], 1800);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put("gemini_extraction_{$this->emissionId}_status", ['error' => $exception->getMessage()], 1800);
    }
}
