<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\IndexRateAnomaly;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexQuarantineStatus;
use App\Models\IndexRateQuarantine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Livro da quarentena de observações de índice (Fase 8.1): guarda o que a fonte
 * entregou e o registro não aceitou, sem nunca tornar isso índice realizado.
 *
 * Idempotência: a sincronização consulta dez anos todo dia e reencontra a mesma
 * anomalia a cada passada. Enquanto ela está pendente, a mesma identidade
 * ({@see IndexRateAnomaly::fingerprint()}) só soma ocorrência e atualiza a última
 * tentativa -- a unique de `open_fingerprint` decide entre dois processos. Uma
 * anomalia já recusada também só soma ocorrência: a recusa não volta para a fila
 * a cada sincronização. Um valor diferente da mesma fonte para a mesma data é
 * outra anomalia e supera a pendência anterior.
 *
 * Sem dependências de propósito: o registro de observações e a correção
 * governada chamam {@see supersedePending()} quando uma observação passa a ser
 * aceita, e a revisão depende deles.
 *
 * Travas: nenhuma escrita daqui segura trava de índice ou de emissão. Quem já
 * segura (a revisão) trava as pendências da data antes das emissões; a correção
 * avulsa só encerra pendências depois do próprio commit.
 */
final class IndexRateQuarantineLedger
{
    private const ATTEMPTS = 3;

    private const RAW_VALUE_LENGTH = 512;

    public function retain(IndexRateAnomaly $anomaly, ?int $syncAttemptId = null): IndexRateQuarantine
    {
        $fingerprint = $anomaly->fingerprint();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $rejected = IndexRateQuarantine::query()
                ->where('fingerprint', $fingerprint)
                ->where('status', PuIndexQuarantineStatus::Rejected->value)
                ->orderByDesc('id')
                ->first();

            if ($rejected instanceof IndexRateQuarantine) {
                return $this->touch($rejected, PuIndexQuarantineStatus::Rejected, $anomaly, $syncAttemptId) ?? $rejected;
            }

            $pending = IndexRateQuarantine::query()->where('open_fingerprint', $fingerprint)->first();

            if ($pending instanceof IndexRateQuarantine) {
                $touched = $this->touch($pending, PuIndexQuarantineStatus::PendingReview, $anomaly, $syncAttemptId);

                if ($touched instanceof IndexRateQuarantine) {
                    return $touched;
                }

                // Decidida entre a leitura e a atualização: classifica de novo.
                continue;
            }

            try {
                $created = IndexRateQuarantine::query()->create([
                    'indexer' => $anomaly->indexer->value,
                    'rate_date' => $anomaly->rateDate?->startOfDay(),
                    'source' => $anomaly->source,
                    'external_series_code' => $anomaly->externalSeriesCode,
                    'anomaly_type' => $anomaly->type,
                    'received_value' => $anomaly->receivedValue,
                    'raw_value' => $this->boundedRaw($anomaly->rawValue),
                    'reason' => $anomaly->reason,
                    'reference_value' => $anomaly->referenceValue,
                    'reference_date' => $anomaly->referenceDate?->startOfDay(),
                    'fingerprint' => $fingerprint,
                    'open_fingerprint' => $fingerprint,
                    'status' => PuIndexQuarantineStatus::PendingReview,
                    'occurrences' => 1,
                    'first_detected_at' => now(),
                    'last_detected_at' => now(),
                    'first_sync_attempt_id' => $syncAttemptId,
                    'last_sync_attempt_id' => $syncAttemptId,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Outro processo registrou a mesma anomalia entre a leitura e a gravação.
                continue;
            }

            $this->supersedeOlderResponses($created);

            return $created;
        }

        throw new LogicException(sprintf('Não foi possível registrar a quarentena da observação de %s.', $anomaly->indexer->value));
    }

    /**
     * Encerra as pendências de uma data que passou a ter observação aceita (ou
     * corrigida): o lugar delas foi tomado pelo caminho governado. A evidência
     * fica; se a fonte ainda divergir, a próxima sincronização registra a
     * divergência contra o valor novo.
     *
     * @return int pendências encerradas
     */
    public function supersedePending(
        PuIndexer $indexer,
        CarbonImmutable $rateDate,
        string $reason,
        ?int $resultingIndexRateId = null,
        ?int $resultingCorrectionId = null,
        ?string $source = null,
    ): int {
        return IndexRateQuarantine::query()
            ->pending()
            ->where('indexer', $indexer->value)
            ->whereDate('rate_date', $rateDate->toDateString())
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->update([
                'status' => PuIndexQuarantineStatus::Superseded->value,
                'open_fingerprint' => null,
                'decided_at' => now(),
                'decision_reason' => $reason,
                'resulting_index_rate_id' => $resultingIndexRateId,
                'resulting_correction_id' => $resultingCorrectionId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Datas com pendência de uma fonte, para a sincronização só tocar as datas
     * que precisam (ela percorre anos de observações a cada passada).
     *
     * @return array<string, true>
     */
    public function pendingDates(PuIndexer $indexer, string $source): array
    {
        return IndexRateQuarantine::query()
            ->pending()
            ->where('indexer', $indexer->value)
            ->where('source', $source)
            ->whereNotNull('rate_date')
            ->pluck('rate_date')
            ->mapWithKeys(fn ($date): array => [CarbonImmutable::parse((string) $date)->toDateString() => true])
            ->all();
    }

    /**
     * Soma a ocorrência só se a linha ainda estiver na situação lida.
     */
    private function touch(IndexRateQuarantine $quarantine, PuIndexQuarantineStatus $expected, IndexRateAnomaly $anomaly, ?int $syncAttemptId): ?IndexRateQuarantine
    {
        $updated = IndexRateQuarantine::query()
            ->whereKey($quarantine->getKey())
            ->where('status', $expected->value)
            ->update([
                'occurrences' => DB::raw('occurrences + 1'),
                'last_detected_at' => now(),
                'last_sync_attempt_id' => $syncAttemptId ?? $quarantine->last_sync_attempt_id,
                'reference_value' => $anomaly->referenceValue ?? $quarantine->reference_value,
                'updated_at' => now(),
            ]);

        return $updated === 1 ? $quarantine->refresh() : null;
    }

    /**
     * A mesma fonte passou a informar outra coisa para a mesma data: a pendência
     * anterior não descreve mais a resposta atual.
     */
    private function supersedeOlderResponses(IndexRateQuarantine $current): void
    {
        if ($current->rate_date === null) {
            return;
        }

        IndexRateQuarantine::query()
            ->pending()
            ->where('indexer', $current->indexer)
            ->where('source', $current->source)
            ->whereDate('rate_date', $current->rate_date->toDateString())
            ->whereKeyNot($current->getKey())
            ->update([
                'status' => PuIndexQuarantineStatus::Superseded->value,
                'open_fingerprint' => null,
                'decided_at' => now(),
                'decision_reason' => sprintf('A fonte passou a informar outra resposta para a data (quarentena #%d).', $current->getKey()),
                'superseded_by_id' => $current->getKey(),
                'updated_at' => now(),
            ]);
    }

    private function boundedRaw(?string $raw): ?string
    {
        return $raw === null ? null : mb_strimwidth($raw, 0, self::RAW_VALUE_LENGTH, '…');
    }
}
