<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Models\IndexRate;
use App\Models\PuIndexSyncAttempt;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Registro de cada tentativa de sincronização de índice publicado (Fase 6).
 *
 * É o que deixa o monitor separar "a divulgação ainda não era esperada", "era
 * esperada e ninguém consultou a fonte", "a fonte respondeu sem a observação" e
 * "a consulta falhou" -- e não tratar uma resposta vazia como a divulgação que
 * deveria ter chegado. Não grava índice nem decide correção: o dado continua no
 * {@see IndexRateObservationRecorder} e no livro de correções.
 *
 * O registro é melhor esforço: uma falha ao gravá-lo nunca derruba a
 * sincronização que ele descreve.
 */
final class PuIndexSyncAttemptRecorder
{
    public function __construct(
        private readonly PuOperationalFailureClassifier $failures,
    ) {}

    public function start(PuIndexer $indexer, string $source, CarbonImmutable $from, CarbonImmutable $to, ?int $userId): ?PuIndexSyncAttempt
    {
        try {
            return PuIndexSyncAttempt::query()->create([
                'indexer' => $indexer->value,
                'source' => $source,
                'requested_from' => $from->toDateString(),
                'requested_to' => $to->toDateString(),
                'started_at' => now(),
                'requested_by' => $userId,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function finish(?PuIndexSyncAttempt $attempt, IndexRateSyncResult $result, int $invalidEntries): void
    {
        if (! $attempt instanceof PuIndexSyncAttempt) {
            return;
        }

        try {
            $attempt->forceFill([
                'finished_at' => now(),
                'outcome' => $this->outcome($result),
                'fetched' => $result->fetched,
                'created' => $result->created,
                'updated' => $result->updated,
                'skipped' => $result->skipped,
                'conflicts' => count($result->conflicts),
                'invalid_entries' => $invalidEntries,
                'blocks_total' => $result->blocksTotal,
                'blocks_failed' => $result->blocksFailed(),
                'latest_observation_date' => $this->latestObservationDate($result->indexer),
                'error_message' => $result->hasBlockFailures()
                    ? $this->failures->sanitize(implode(' | ', $result->blockFailures))
                    : null,
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function fail(?PuIndexSyncAttempt $attempt, Throwable $exception): void
    {
        if (! $attempt instanceof PuIndexSyncAttempt) {
            return;
        }

        try {
            $attempt->forceFill([
                'finished_at' => now(),
                'outcome' => PuIndexSyncOutcome::Failed,
                'failure_category' => $this->failures->classify($exception),
                'latest_observation_date' => $this->latestObservationDate(PuIndexer::from((string) $attempt->indexer)),
                'error_message' => $this->failures->sanitize($exception->getMessage()),
            ])->save();
        } catch (Throwable $recording) {
            report($recording);
        }
    }

    /**
     * Precedência: o que exige decisão (valor divergente, revisão aplicada) antes
     * da falha parcial, e esta antes do sucesso.
     */
    private function outcome(IndexRateSyncResult $result): PuIndexSyncOutcome
    {
        return match (true) {
            $result->conflicts !== [] => PuIndexSyncOutcome::RateConflict,
            $result->updated > 0 => PuIndexSyncOutcome::HistoricalCorrection,
            $result->hasBlockFailures() => PuIndexSyncOutcome::PartialFailure,
            $result->created > 0 => PuIndexSyncOutcome::NewObservations,
            default => PuIndexSyncOutcome::NoNewObservation,
        };
    }

    private function latestObservationDate(PuIndexer $indexer): ?string
    {
        $latest = IndexRate::query()
            ->forIndexer($indexer)
            ->where('is_projected', false)
            ->max('rate_date');

        return $latest !== null ? CarbonImmutable::parse((string) $latest)->toDateString() : null;
    }
}
