<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\PuOperationalIncidentService;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Incidentes operacionais do PU (Fase 6): lista os abertos (ou todos) com o
 * motivo estruturado, e reconhece um incidente em nome de um usuário com
 * `pu.operations.recover`. Reconhecer não resolve nem corrige nada financeiro.
 */
class PuOperationalIncidentsCommand extends Command
{
    protected $signature = 'pu:operations:incidents
        {--all : Inclui os resolvidos (histórico)}
        {--acknowledge= : ID do incidente a reconhecer}
        {--user= : id ou e-mail de quem reconhece (obrigatório com --acknowledge)}
        {--note= : observação do reconhecimento}
        {--json : Imprime em JSON}';

    protected $description = 'Lista os incidentes operacionais do PU e permite reconhecê-los (sem efeito financeiro).';

    public function handle(PuOperationalIncidentService $incidents): int
    {
        if (filled($this->option('acknowledge'))) {
            return $this->acknowledge($incidents);
        }

        $rows = PuOperationalIncident::query()
            ->when(! (bool) $this->option('all'), fn ($query) => $query->open())
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows->map(fn (PuOperationalIncident $incident): array => [
                'id' => $incident->id,
                'key' => $incident->incident_key,
                'type' => $incident->type->value,
                'severity' => $incident->severity->value,
                'status' => $incident->status->value,
                'emission_id' => $incident->emission_id,
                'obligation_id' => $incident->obligation_id,
                'settlement_conflict_id' => $incident->settlement_conflict_id,
                'indexer' => $incident->indexer,
                'business_date' => $incident->business_date?->toDateString(),
                'reason' => $incident->reason,
                'context' => $incident->context,
                'first_detected_at' => $incident->first_detected_at?->toIso8601String(),
                'last_detected_at' => $incident->last_detected_at?->toIso8601String(),
                'detection_count' => $incident->detection_count,
                'recurrence_of_id' => $incident->recurrence_of_id,
                'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
            ])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['ID', 'Urgência', 'Situação', 'Tipo', 'Emissão', 'Desde', 'Detecções', 'Motivo'], $rows->map(fn (PuOperationalIncident $incident): array => [
            $incident->id,
            $incident->severity->label(),
            $incident->status->label(),
            $incident->type->label(),
            $incident->emission_id ?? ($incident->indexer ?? '—'),
            $incident->first_detected_at?->format('d/m/Y H:i'),
            $incident->detection_count,
            mb_strimwidth($incident->reason, 0, 120, '…'),
        ])->all());

        return self::SUCCESS;
    }

    private function acknowledge(PuOperationalIncidentService $incidents): int
    {
        $incident = PuOperationalIncident::query()->find((int) $this->option('acknowledge'));

        if (! $incident instanceof PuOperationalIncident) {
            $this->error('Incidente não encontrado.');

            return self::FAILURE;
        }

        $userOption = (string) $this->option('user');
        $user = ctype_digit($userOption)
            ? User::query()->find((int) $userOption)
            : User::query()->where('email', $userOption)->first();

        if (! $user instanceof User) {
            $this->error('Informe --user com um usuário existente.');

            return self::FAILURE;
        }

        try {
            $incidents->acknowledge($incident, $user, $this->option('note') !== null ? (string) $this->option('note') : null);
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Incidente #%d reconhecido. Ele continua aberto até a condição deixar de existir.', $incident->id));

        return self::SUCCESS;
    }
}
