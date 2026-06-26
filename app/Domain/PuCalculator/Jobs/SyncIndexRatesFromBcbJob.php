<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Jobs;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sincronização assíncrona de índices publicados (Banco Central/SGS).
 *
 * Idempotente, com retry/backoff para indisponibilidade da API. Uma falha do Banco Central NUNCA quebra
 * a engine de cálculo (que usa index_rates persistido) — apenas registra alerta (log) e estado para a UI.
 */
class SyncIndexRatesFromBcbJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public readonly string $indexer,
        public readonly string $from,
        public readonly string $to,
        public readonly bool $dryRun = false,
        public readonly ?int $requestedByUserId = null,
        public readonly ?string $overwritePolicy = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(IndexRateSyncService $service): void
    {
        $indexer = PuIndexer::from(strtoupper($this->indexer));
        $lock = Cache::lock($this->lockKey(), 600);

        if (! $lock->get()) {
            Log::info('SyncIndexRatesFromBcbJob skipped (already running)', ['indexer' => $this->indexer]);

            return;
        }

        try {
            $result = $service->sync(
                $indexer,
                CarbonImmutable::parse($this->from),
                CarbonImmutable::parse($this->to),
                $this->dryRun,
                $this->requestedByUserId,
                $this->overwritePolicy,
            );

            // O resumo de execução (período/inseridos/ignorados) é logado por IndexRateSyncService.
            Cache::put($this->statusKey(), ['status' => 'completed'] + $result->toArray(), 86400);
        } catch (BcbSgsException $exception) {
            Cache::put($this->statusKey(), [
                'status' => 'failed',
                'indexer' => $this->indexer,
                'error' => $exception->getMessage(),
                'failed_at' => CarbonImmutable::now()->toDateTimeString(),
            ], 86400);

            Log::error('Falha na sincronização de índices do Banco Central.', [
                'indexer' => $this->indexer,
                'from' => $this->from,
                'to' => $this->to,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put($this->statusKey(), [
            'status' => 'failed',
            'indexer' => $this->indexer,
            'error' => $exception->getMessage(),
            'failed_at' => CarbonImmutable::now()->toDateTimeString(),
        ], 86400);
    }

    private function statusKey(): string
    {
        return sprintf('pu_index_sync_%s_status', strtolower($this->indexer));
    }

    private function lockKey(): string
    {
        return sprintf('pu_index_sync_%s_lock', strtolower($this->indexer));
    }
}
