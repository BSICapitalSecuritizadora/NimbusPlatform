<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Enums\AccessPermission;
use App\Models\PuOperationalIncident;
use App\Models\User;
use App\Notifications\PuOperationalIncidentsNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entrega dos incidentes devidos no sino do painel (Fase 6).
 *
 * Destinatários: só quem tem `pu.operations.monitor` (hoje `super-admin` e
 * `admin`, os mesmos que veem a conciliação). Sem destinatário ou com falha na
 * entrega, o incidente fica sem `notified_at` e com o motivo em
 * `notification_error`: a rodada seguinte tenta de novo, e o incidente continua
 * visível no retrato e no comando -- a falha do aviso nunca esconde o problema.
 */
final class PuOperationalAlertDispatcher
{
    private const MAX_LINES = 20;

    public function __construct(
        private readonly PuOperationalFailureClassifier $failures,
    ) {}

    /**
     * @param  list<int>  $incidentIds
     */
    public function dispatch(array $incidentIds): int
    {
        if ($incidentIds === []) {
            return 0;
        }

        $incidents = PuOperationalIncident::query()
            ->whereIn('id', $incidentIds)
            ->with('emission:id,name')
            ->get()
            ->sortByDesc(fn (PuOperationalIncident $incident): int => $incident->severity->rank())
            ->values();

        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            $this->markFailed($incidentIds, 'Nenhum usuário com a permissão pu.operations.monitor para receber o aviso.');

            return 0;
        }

        $lines = $incidents->take(self::MAX_LINES)->map(fn (PuOperationalIncident $incident): array => [
            'severity' => $incident->severity->value,
            'label' => $incident->type->label(),
            'reason' => Str::limit($incident->reason, 300),
            'emission' => $incident->emission?->name,
        ])->values()->all();

        try {
            Notification::sendNow($recipients, new PuOperationalIncidentsNotification($lines, $incidents->count()));
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($incidentIds, $this->failures->sanitize($exception->getMessage()));

            return 0;
        }

        foreach ($incidents as $incident) {
            PuOperationalIncident::query()->whereKey($incident->id)->update([
                'notified_at' => now(),
                'notified_severity' => $incident->severity->value,
                'notification_error' => null,
                'updated_at' => now(),
            ]);
        }

        return $incidents->count();
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        try {
            return User::permission(AccessPermission::PuOperationsMonitor->value)->operational()->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @param  list<int>  $incidentIds
     */
    private function markFailed(array $incidentIds, string $reason): void
    {
        PuOperationalIncident::query()->whereIn('id', $incidentIds)->update([
            'notification_error' => $reason,
            'updated_at' => now(),
        ]);
    }
}
