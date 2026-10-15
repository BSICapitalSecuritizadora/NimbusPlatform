<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexQuarantineStatus;
use App\Enums\AccessPermission;
use App\Models\IndexRate;
use App\Models\IndexRateQuarantine;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Decisão humana sobre uma observação em quarentena (Fase 8.1).
 *
 * Aprovar não marca a quarentena como índice: grava pelo caminho governado de
 * sempre. Data sem observação aceita entra pelo {@see IndexRateObservationRecorder}
 * com a confirmação explícita do valor fora do padrão (a mesma da importação);
 * data que já tem valor aceito só muda pela {@see IndexRateCorrectionService},
 * com motivo, livro de correções e curvas governadas marcadas para
 * reprocessamento. Recusar só encerra a revisão: nada muda no índice.
 *
 * Autoridade: `pu.index.correct` e usuário ativo, para aprovar e para recusar.
 *
 * Decisão velha é recusada: a revisão precisa estar pendente e o valor aceito
 * na data precisa ser o que o revisor viu ao abrir a decisão.
 *
 * Travas: as pendências da data (por id) antes de tudo -- dois revisores da
 * mesma data se enfileiram ali --, depois as da correção (emissões → observação
 * → versões). A correção avulsa encerra pendências só depois do próprio commit,
 * então ninguém trava emissão antes de pendência. A transação da decisão é READ
 * COMMITTED no MySQL ({@see decisionTransaction()}).
 */
final class IndexRateQuarantineReviewService
{
    public function __construct(
        private readonly IndexRateObservationRecorder $recorder,
        private readonly IndexRateCorrectionService $corrections,
        private readonly IndexRateLookupService $lookups,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function approve(User $actor, int $quarantineId, string $reason, ?string $acceptedValueSeen): IndexRateQuarantine
    {
        $this->authorize($actor);
        $reason = $this->requireReason($reason);

        $quarantine = $this->decisionTransaction(function () use ($actor, $quarantineId, $reason, $acceptedValueSeen): IndexRateQuarantine {
            $quarantine = $this->lockPending($quarantineId);

            if (! $quarantine->anomaly_type->canBeApproved() || $quarantine->received_value === null || $quarantine->rate_date === null) {
                throw new InvalidArgumentException(sprintf(
                    '%s não tem valor aprovável. Recuse e, depois de conferir na fonte, registre o valor certo pela importação ou pela correção.',
                    $quarantine->anomaly_type->label(),
                ));
            }

            $indexer = PuIndexer::from((string) $quarantine->indexer);
            $date = $quarantine->rate_date->toImmutable()->startOfDay();
            $received = (string) $quarantine->received_value;
            $current = IndexRate::query()->forIndexer($indexer)->whereDate('rate_date', $date->toDateString())->first();

            if ($current instanceof IndexRate && $current->isProjectedRate()) {
                throw new InvalidArgumentException(sprintf('A data %s tem valor PROJETADO: observação realizada não o substitui por aqui.', $date->format('d/m/Y')));
            }

            $currentValue = $current instanceof IndexRate ? (string) $current->rate_value : null;

            if (! $this->sameValue($currentValue, $acceptedValueSeen)) {
                throw new InvalidArgumentException(sprintf(
                    'O valor registrado em %s mudou desde que a revisão foi aberta (agora: %s). Nada foi gravado: reabra e confira.',
                    $date->format('d/m/Y'),
                    $currentValue ?? 'sem observação',
                ));
            }

            // Decidida antes de gravar: o encerramento automático das pendências da
            // data (feito pelo registro e pela correção) não alcança esta.
            $quarantine->forceFill([
                'status' => PuIndexQuarantineStatus::Approved,
                'open_fingerprint' => null,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            if (! $current instanceof IndexRate) {
                $outcome = $this->recorder->recordRealized(
                    $indexer,
                    $date,
                    $received,
                    [
                        'source' => (string) $quarantine->source,
                        'source_reference' => sprintf('quarantine:%d', $quarantine->id),
                        'external_series_code' => $quarantine->external_series_code,
                        'fetched_at' => $quarantine->last_detected_at,
                        'notes' => mb_strimwidth(sprintf('Aprovada na revisão da quarentena #%d: %s', $quarantine->id, $reason), 0, 1000, '…'),
                    ],
                    confirmOutlier: true,
                );

                if (! $outcome->wasCreated()) {
                    throw new InvalidArgumentException(($outcome->reason ?? 'A observação não pôde ser registrada.').' Nada foi gravado.');
                }

                $quarantine->forceFill([
                    'resulting_index_rate_id' => IndexRate::query()->forIndexer($indexer)->whereDate('rate_date', $date->toDateString())->value('id'),
                ])->save();

                return $quarantine;
            }

            if (bccomp($currentValue, $received, IndexRateObservationRecorder::VALUE_SCALE) === 0) {
                throw new InvalidArgumentException(sprintf('A data %s já tem %s registrado: não há o que aprovar.', $date->format('d/m/Y'), $currentValue));
            }

            $correction = $this->corrections->correctAs(
                $actor,
                $indexer,
                $date->toDateString(),
                $received,
                sprintf('Aprovação da observação retida #%d (%s): %s', $quarantine->id, (string) $quarantine->source, $reason),
                (string) $quarantine->source,
                sprintf('quarantine:%d', $quarantine->id),
            );

            $quarantine->forceFill([
                'resulting_index_rate_id' => $correction->index_rate_id,
                'resulting_correction_id' => $correction->id,
            ])->save();

            return $quarantine;
        });

        $this->lookups->flushCache();
        $this->auditLog->logIndexObservationReviewed($quarantine);

        return $quarantine;
    }

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function reject(User $actor, int $quarantineId, string $reason): IndexRateQuarantine
    {
        $this->authorize($actor);
        $reason = $this->requireReason($reason);

        $quarantine = $this->decisionTransaction(function () use ($actor, $quarantineId, $reason): IndexRateQuarantine {
            $quarantine = $this->lockPending($quarantineId);

            $quarantine->forceFill([
                'status' => PuIndexQuarantineStatus::Rejected,
                'open_fingerprint' => null,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            return $quarantine;
        });

        $this->auditLog->logIndexObservationReviewed($quarantine);

        return $quarantine;
    }

    /**
     * A decisão roda em READ COMMITTED no MySQL.
     *
     * A correção governada relê, já com as travas, as curvas que usaram a
     * observação; sozinha, essa releitura é a primeira leitura da transação dela
     * e enxerga o que comitou durante a espera. Aninhada aqui, em REPEATABLE
     * READ, ela leria a foto tirada antes da espera: a extensão que anexou um
     * dia com a taxa antiga nesse meio-tempo ficaria fora da lista, e a curva
     * oficial não seria marcada para reprocessamento. Pelo mesmo motivo, o
     * registro de observação não acharia a linha que venceu a unique.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function decisionTransaction(Closure $callback): mixed
    {
        if (DB::getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($callback);
    }

    /**
     * Trava as pendências da mesma data (por id) e devolve a pedida, se ainda
     * estiver pendente.
     */
    private function lockPending(int $quarantineId): IndexRateQuarantine
    {
        $located = IndexRateQuarantine::query()->find($quarantineId);

        if (! $located instanceof IndexRateQuarantine) {
            throw new InvalidArgumentException('A observação retida não existe mais.');
        }

        $locked = IndexRateQuarantine::query()
            ->where('indexer', $located->indexer)
            ->when(
                $located->rate_date !== null,
                fn ($query) => $query
                    ->whereDate('rate_date', $located->rate_date->toDateString())
                    ->where(fn ($candidates) => $candidates->where('status', PuIndexQuarantineStatus::PendingReview->value)->orWhere('id', $quarantineId)),
                fn ($query) => $query->whereKey($quarantineId),
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->firstWhere('id', $quarantineId);

        if (! $locked instanceof IndexRateQuarantine || ! $locked->isPending()) {
            throw new InvalidArgumentException(sprintf(
                'A observação retida #%d já foi encerrada (%s). Nada foi gravado.',
                $quarantineId,
                $locked?->status->label() ?? 'situação desconhecida',
            ));
        }

        return $locked;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor): void
    {
        if (! $actor->isOperational() || ! $actor->can(AccessPermission::PuIndexCorrect->value)) {
            throw new AuthorizationException(sprintf(
                'Decidir sobre observação retida de índice exige a permissão %s e um usuário ativo.',
                AccessPermission::PuIndexCorrect->value,
            ));
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Informe o motivo da decisão: ele fica na trilha da observação.');
        }

        return $reason;
    }

    private function sameValue(?string $current, ?string $seen): bool
    {
        if ($current === null || $seen === null || trim($seen) === '') {
            return $current === null && ($seen === null || trim($seen) === '');
        }

        return is_numeric($seen) && bccomp($current, $seen, IndexRateObservationRecorder::VALUE_SCALE) === 0;
    }
}
