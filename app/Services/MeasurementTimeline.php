<?php

namespace App\Services;

use App\Concerns\MoneyFormatter;
use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class MeasurementTimeline
{
    /**
     * Builds a chronological timeline of a measurement: upload, stage transitions
     * (approvals, rejections and returns from the activity log), pauses/resumes and
     * payments/receipts.
     *
     * @return Collection<int, array{at: \Illuminate\Support\Carbon, title: string, detail: ?string, actor: ?string, color: string, icon: string}>
     */
    public function for(Measurement $measurement): Collection
    {
        $events = collect();

        if ($measurement->uploaded_at !== null) {
            $events->push([
                'at' => $measurement->uploaded_at,
                'title' => 'Medição enviada',
                'detail' => null,
                'actor' => $measurement->uploadedByUser?->name,
                'color' => 'info',
                'icon' => 'heroicon-o-arrow-up-tray',
            ]);
        }

        $this->pushTransitions($measurement, $events);
        $this->pushPauses($measurement, $events);
        $this->pushPayments($measurement, $events);

        return $events
            ->sortBy(fn (array $event): float => $event['at']->getTimestamp())
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function pushTransitions(Measurement $measurement, Collection $events): void
    {
        $activities = $measurement->activities()
            ->with('causer')
            ->orderBy('created_at')
            ->get();

        foreach ($activities as $activity) {
            $event = $this->mapTransition($activity);

            if ($event === null) {
                continue;
            }

            $events->push([
                'at' => $activity->created_at,
                'title' => $event['title'],
                'detail' => null,
                'actor' => $activity->causer?->name,
                'color' => $event['color'],
                'icon' => $event['icon'],
            ]);
        }
    }

    /**
     * @return array{title: string, color: string, icon: string}|null
     */
    private function mapTransition(Activity $activity): ?array
    {
        $attributes = $activity->properties['attributes'] ?? [];
        $old = $activity->properties['old'] ?? [];

        $statusChanged = array_key_exists('status', $attributes);
        $stageChanged = array_key_exists('current_stage', $attributes);

        if (! $statusChanged && ! $stageChanged) {
            return null;
        }

        $newStatus = $attributes['status'] ?? null;
        $oldStatus = $old['status'] ?? null;
        $newStage = (int) ($attributes['current_stage'] ?? $old['current_stage'] ?? 0);
        $oldStage = (int) ($old['current_stage'] ?? 0);

        if ($statusChanged) {
            return match (true) {
                $newStatus === 'rejected' => ['title' => 'Medição recusada e encerrada', 'color' => 'danger', 'icon' => 'heroicon-o-x-circle'],
                $newStatus === 'finalized' => ['title' => 'Medição finalizada', 'color' => 'success', 'icon' => 'heroicon-o-flag'],
                $newStatus === 'awaiting_payment' && $oldStatus !== 'awaiting_payment' && in_array($oldStatus, ['in_review', 'pending', null], true) => ['title' => 'Aprovada na revisão — aguardando pagamento', 'color' => 'success', 'icon' => 'heroicon-o-banknotes'],
                $newStatus === 'in_review' && in_array($oldStatus, ['awaiting_payment', 'awaiting_receipt'], true) => ['title' => 'Devolvida para Etapa '.$newStage, 'color' => 'warning', 'icon' => 'heroicon-o-arrow-uturn-left'],
                $newStatus === 'in_review' && $stageChanged && $newStage > $oldStage => ['title' => 'Etapa '.$oldStage.' aprovada — avançou para Etapa '.$newStage, 'color' => 'success', 'icon' => 'heroicon-o-check-circle'],
                $newStatus === 'in_review' && $stageChanged && $newStage < $oldStage => ['title' => 'Devolvida para Etapa '.$newStage, 'color' => 'warning', 'icon' => 'heroicon-o-arrow-uturn-left'],
                default => null,
            };
        }

        // Status unchanged, only the stage moved (advance or return between review stages).
        return match (true) {
            $newStage > $oldStage => ['title' => 'Etapa '.$oldStage.' aprovada — avançou para Etapa '.$newStage, 'color' => 'success', 'icon' => 'heroicon-o-check-circle'],
            $newStage < $oldStage => ['title' => 'Devolvida para Etapa '.$newStage, 'color' => 'warning', 'icon' => 'heroicon-o-arrow-uturn-left'],
            default => null,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function pushPauses(Measurement $measurement, Collection $events): void
    {
        foreach ($measurement->pauses()->with(['pausedByUser', 'resumedByUser'])->get() as $pause) {
            /** @var MeasurementPause $pause */
            $events->push([
                'at' => $pause->paused_at,
                'title' => 'Análise pausada (Etapa '.$pause->stage.')',
                'detail' => $pause->pause_reason,
                'actor' => $pause->pausedByUser?->name,
                'color' => 'warning',
                'icon' => 'heroicon-o-pause-circle',
            ]);

            if ($pause->resumed_at !== null) {
                $events->push([
                    'at' => $pause->resumed_at,
                    'title' => 'Análise retomada',
                    'detail' => null,
                    'actor' => $pause->resumedByUser?->name,
                    'color' => 'info',
                    'icon' => 'heroicon-o-play-circle',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function pushPayments(Measurement $measurement, Collection $events): void
    {
        foreach ($measurement->payments()->with(['planSet.construction', 'createdByUser'])->get() as $payment) {
            /** @var MeasurementPayment $payment */
            $development = $payment->planSet?->construction?->development_name ?? $payment->planSet?->name;
            $amount = MoneyFormatter::formatCurrencyForDisplay($payment->amount);

            $events->push([
                'at' => $payment->created_at,
                'title' => 'Pagamento registrado'.($development ? ' — '.$development : ''),
                'detail' => 'R$ '.$amount.($payment->method ? ' • '.$payment->method : ''),
                'actor' => $payment->createdByUser?->name,
                'color' => 'success',
                'icon' => 'heroicon-o-banknotes',
            ]);

            if ($payment->receipt_uploaded_at !== null) {
                $events->push([
                    'at' => $payment->receipt_uploaded_at,
                    'title' => 'Comprovante anexado'.($development ? ' — '.$development : ''),
                    'detail' => null,
                    'actor' => $payment->createdByUser?->name,
                    'color' => 'info',
                    'icon' => 'heroicon-o-paper-clip',
                ]);
            }
        }
    }
}
