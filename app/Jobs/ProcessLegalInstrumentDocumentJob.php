<?php

namespace App\Jobs;

use App\Enums\LegalInstrumentDocumentStatus;
use App\Models\LegalInstrumentDocument;
use App\Services\LegalInstruments\InstrumentDocumentExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Processa um documento do dossiê fora da requisição HTTP (§5 e §37).
 *
 * É idempotente por vínculo: `ShouldBeUnique` impede que dois workers leiam o
 * mesmo documento em paralelo e criem propostas duplicadas — o mesmo cuidado
 * que a extração de garantias já exigia.
 *
 * O job nunca altera a posição vigente: ele só produz propostas pendentes. Uma
 * falha deixa o vínculo em `failed` com a causa registrada, e a interface
 * oferece nova tentativa.
 */
class ProcessLegalInstrumentDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 1;

    /** Janela do lock de unicidade, acima do timeout para cobrir o job inteiro. */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $instrumentDocumentId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->instrumentDocumentId;
    }

    public function handle(InstrumentDocumentExtractor $extractor): void
    {
        $instrumentDocument = LegalInstrumentDocument::find($this->instrumentDocumentId);

        if ($instrumentDocument === null) {
            return;
        }

        $instrumentDocument->forceFill([
            'processing_status' => LegalInstrumentDocumentStatus::Processing,
            'current_step' => 'extracting',
            'message' => 'Lendo o documento e comparando com a posição vigente...',
            'processing_started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $result = $extractor->extract($instrumentDocument);

            $needsReview = $result['fields'] > 0
                || $result['conflicts'] > 0
                || $result['guarantees'] > 0
                || $result['obligations'] > 0;

            $instrumentDocument->forceFill([
                'processing_status' => $needsReview
                    ? LegalInstrumentDocumentStatus::NeedsReview
                    : LegalInstrumentDocumentStatus::Processed,
                'current_step' => 'completed',
                'message' => $this->buildMessage($result),
                'effect_summary' => $result['effect_summary'] ?? $instrumentDocument->effect_summary,
                'extraction_attempts' => $instrumentDocument->extraction_attempts + 1,
                'processed_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('ProcessLegalInstrumentDocumentJob falhou', [
                'legal_instrument_document_id' => $this->instrumentDocumentId,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($instrumentDocument, $e);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $instrumentDocument = LegalInstrumentDocument::find($this->instrumentDocumentId);

        if ($instrumentDocument === null) {
            return;
        }

        $this->markFailed($instrumentDocument, $exception);
    }

    /**
     * @param  array{fields: int, guarantees: int, obligations: int, conflicts: int, duplicates: int, effect_summary: string|null}  $result
     */
    private function buildMessage(array $result): string
    {
        if ($result['fields'] === 0 && $result['guarantees'] === 0) {
            return 'Documento lido. Nenhuma alteração em relação à posição vigente.';
        }

        $parts = [];

        if ($result['fields'] > 0) {
            $parts[] = "{$result['fields']} alteração(ões) pendente(s) de revisão";
        }

        if ($result['guarantees'] > 0) {
            $parts[] = "{$result['guarantees']} garantia(s) identificada(s)";
        }

        if (($result['obligations'] ?? 0) > 0) {
            $parts[] = "{$result['obligations']} obrigação(ões) sugerida(s)";
        }

        if (($result['duplicates'] ?? 0) > 0) {
            $parts[] = "{$result['duplicates']} possível(is) duplicata(s)";
        }

        if ($result['conflicts'] > 0) {
            $parts[] = "{$result['conflicts']} exige(m) atenção por conflito";
        }

        return ucfirst(implode(' · ', $parts)).'.';
    }

    private function markFailed(LegalInstrumentDocument $instrumentDocument, \Throwable $exception): void
    {
        $instrumentDocument->forceFill([
            'processing_status' => LegalInstrumentDocumentStatus::Failed,
            'current_step' => 'failed',
            'message' => 'Não foi possível processar o documento. Tente novamente ou acione o suporte técnico.',
            'error_message' => Str::limit($exception->getMessage(), 500),
            'extraction_attempts' => $instrumentDocument->extraction_attempts + 1,
        ])->save();
    }
}
