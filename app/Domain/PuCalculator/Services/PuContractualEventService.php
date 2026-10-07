<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancelamento de evento contratual de PU.
 *
 * Cancelar é o estorno do evento: ele sai do cálculo e do retrato de insumos das
 * próximas versões, mas continua no banco com quem cancelou, quando e por quê -- e
 * a versão de curva que já o usou guarda o conteúdo dele no próprio retrato, que
 * não muda. O efeito sobre a curva oficial é decidido pelo classificador central
 * como qualquer outra mudança contratual: cancelado depois do último dia gravado,
 * a oficial para na véspera até uma versão nova ser homologada; dentro do trecho
 * gravado, reprocessamento.
 *
 * Exige a mesma permissão que configura o cálculo e os eventos de PU.
 */
final class PuContractualEventService
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancel(EmissionPuEvent $event, User $actor, string $reason): EmissionPuEvent
    {
        if (! $actor->can(AccessPermission::PuParametersConfigure->value)) {
            throw new AuthorizationException('Você não possui permissão para cancelar eventos de PU.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => 'Informe o motivo do cancelamento.',
            ]);
        }

        return DB::transaction(function () use ($event, $actor, $reason): EmissionPuEvent {
            Emission::query()->whereKey($event->emission_id)->lockForUpdate()->first();
            $locked = EmissionPuEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'O evento já está cancelado.',
                ]);
            }

            $locked->forceFill([
                'status' => PuEventStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
