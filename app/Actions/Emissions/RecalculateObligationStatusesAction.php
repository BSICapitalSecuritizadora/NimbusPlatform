<?php

namespace App\Actions\Emissions;

use App\Models\Obligation;
use App\Models\ObligationHistoryEntry;
use App\Services\Obligations\ObligationHistoryRecorder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class RecalculateObligationStatusesAction
{
    /**
     * Statuses managed automatically by the due-date recalculation.
     * Any other status (concluida, em_analise, nao_aplicavel) represents a
     * manual decision and must never be overwritten by this routine.
     *
     * @var list<string>
     */
    public const AUTO_MANAGED_STATUSES = ['em_dia', 'a_vencer', 'vencida'];

    /**
     * Recalculate the status of every eligible obligation based on its due date.
     *
     * @return array{analyzed: int, marked_a_vencer: int, marked_vencida: int, unchanged: int}
     */
    public function handle(?CarbonInterface $referenceDate = null): array
    {
        $today = ($referenceDate ?? now())->copy()->startOfDay();

        $analyzed = 0;
        $markedAVencer = 0;
        $markedVencida = 0;
        $unchanged = 0;

        Log::info('RecalculateObligationStatuses: início', [
            'reference_date' => $today->toDateString(),
        ]);

        ObligationHistoryRecorder::usingSource(ObligationHistoryEntry::SOURCE_SCHEDULED_COMMAND, function () use ($today, &$analyzed, &$markedAVencer, &$markedVencida, &$unchanged): void {
            Obligation::query()
                ->whereNotNull('due_date')
                ->whereIn('status', self::AUTO_MANAGED_STATUSES)
                ->chunkById(200, function (Collection $obligations) use ($today, &$analyzed, &$markedAVencer, &$markedVencida, &$unchanged): void {
                    foreach ($obligations as $obligation) {
                        $analyzed++;

                        $expectedStatus = $obligation->due_date->copy()->startOfDay()->lt($today)
                            ? 'vencida'
                            : 'a_vencer';

                        if ($obligation->status === $expectedStatus) {
                            $unchanged++;

                            continue;
                        }

                        $obligation->update(['status' => $expectedStatus]);

                        if ($expectedStatus === 'vencida') {
                            $markedVencida++;
                        } else {
                            $markedAVencer++;
                        }
                    }
                });
        });

        $result = [
            'analyzed' => $analyzed,
            'marked_a_vencer' => $markedAVencer,
            'marked_vencida' => $markedVencida,
            'unchanged' => $unchanged,
        ];

        Log::info('RecalculateObligationStatuses: concluído', $result);

        return $result;
    }
}
