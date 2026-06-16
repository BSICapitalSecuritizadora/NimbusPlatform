<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Services\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateEmissionObligationsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
        public readonly int $documentId,
    ) {}

    public static function cacheKey(int $emissionId): string
    {
        return "obligations_extraction_{$emissionId}_status";
    }

    public function handle(GeminiService $geminiService): void
    {
        $emission = Emission::findOrFail($this->emissionId);
        $document = Document::findOrFail($this->documentId);

        try {
            $proposals = $geminiService->extractObligations($document);

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

            Cache::put(self::cacheKey($this->emissionId), [
                'status' => 'completed',
                'count' => $created,
            ], 1800);
        } catch (\Throwable $e) {
            Log::error('GenerateEmissionObligationsJob falhou', [
                'emission_id' => $this->emissionId,
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            Cache::put(self::cacheKey($this->emissionId), ['error' => $e->getMessage()], 1800);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put(self::cacheKey($this->emissionId), ['error' => $exception->getMessage()], 1800);
    }
}
