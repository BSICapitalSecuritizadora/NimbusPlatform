<?php

namespace App\Notifications;

use App\Filament\Pages\PuOperationalIncidents;
use App\Filament\Resources\Emissions\EmissionResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Aviso no sino do painel com os incidentes operacionais do PU que acabaram de
 * abrir, reincidir ou escalar (Fase 6).
 *
 * Um aviso por rodada do monitor, com a lista -- nunca um por incidente: um
 * problema que se espalha por muitas obrigações não vira uma enxurrada. Só o
 * tipo, a emissão e o motivo; nenhum payload externo, credencial ou dado de
 * investidor. Vai só para quem tem `pu.operations.monitor`. O e-mail operacional
 * continua pelo resumo de `pu:queue-health --alert`.
 */
class PuOperationalIncidentsNotification extends Notification
{
    /**
     * @param  list<array{id?: int, severity: string, label: string, reason: string, emission: string|null, emission_id?: int|null}>  $incidents
     */
    public function __construct(
        public readonly array $incidents,
        public readonly int $total,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $critical = array_filter($this->incidents, fn (array $incident): bool => $incident['severity'] === 'critical') !== [];
        $lines = array_map(
            fn (array $incident): string => sprintf(
                '[%s] %s%s — %s',
                mb_strtoupper($incident['severity']),
                $incident['label'],
                $incident['emission'] !== null ? ' ('.$incident['emission'].')' : '',
                $incident['reason'],
            ),
            $this->incidents,
        );

        if ($this->total > count($this->incidents)) {
            $lines[] = sprintf('… e mais %d incidente(s).', $this->total - count($this->incidents));
        }

        return FilamentNotification::make()
            ->title(sprintf('Curva de PU — %d incidente(s) operacional(is)', $this->total))
            ->body(implode("\n", $lines))
            ->status($critical ? 'danger' : 'warning')
            ->actions($this->actions())
            ->getDatabaseMessage();
    }

    /**
     * Links para onde o aviso é tratado. A lista de incidentes sempre existe (e
     * respeita a permissão de quem abre); a emissão só entra quando o aviso é de
     * uma emissão só. A tela de destino confere de novo a autorização.
     *
     * @return list<Action>
     */
    private function actions(): array
    {
        $actions = [];

        try {
            $actions[] = Action::make('incidents')
                ->label('Ver incidentes')
                ->url(PuOperationalIncidents::getUrl(panel: 'admin'))
                ->markAsRead();

            $emissionIds = array_values(array_unique(array_filter(array_map(
                fn (array $incident): ?int => $incident['emission_id'] ?? null,
                $this->incidents,
            ))));

            if (count($emissionIds) === 1) {
                $actions[] = Action::make('emission')
                    ->label('Abrir operação do PU da emissão')
                    ->url(EmissionResource::getUrl('pu-operation', ['record' => $emissionIds[0]], panel: 'admin'))
                    ->markAsRead();
            }
        } catch (Throwable $exception) {
            // Sem rota (painel não registrado no contexto): o aviso sai sem link.
            report($exception);
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['incidents' => $this->incidents, 'total' => $this->total];
    }
}
