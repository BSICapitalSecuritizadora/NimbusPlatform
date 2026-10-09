<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuOperationalCondition;
use App\Domain\PuCalculator\DTOs\PuOperationalSnapshot;
use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Enums\AccessPermission;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ciclo de vida dos incidentes operacionais do PU (Fase 6).
 *
 *  - detectado: a condição que pede ação (WARNING ou CRITICAL) abre um
 *    incidente com identidade estável. Avaliar de novo a mesma condição só
 *    atualiza o aberto (última detecção, contagem, urgência, motivo) -- o
 *    `open_key` UNIQUE garante um aberto por identidade mesmo com dois monitores
 *    ao mesmo tempo;
 *  - escalado: a urgência subiu; volta a "ativo" e é avisado de novo;
 *  - reconhecido: alguém assumiu. Não corrige nada financeiro, não resolve e não
 *    impede a escalada;
 *  - resolvido: SÓ pelo monitor, quando a verificação que produz a condição
 *    rodou inteira e não a encontrou mais. Verificação que falhou não resolve
 *    nada, e a detecção mais nova que o início da rodada também não (corrida
 *    entre resolver e voltar a falhar). Conflito de liquidação só some com a
 *    decisão governada; reprocessamento, com a versão nova homologada; efeito
 *    sem regra, quando o estado de domínio muda -- nunca porque uma repetição
 *    técnica deu certo;
 *  - reincidente: a mesma identidade voltando depois de resolvida abre outro
 *    incidente, ligado ao anterior, que fica como histórico.
 *
 * Condição informativa nunca abre incidente; se um incidente aberto passa a ser
 * informativo, ele continua aberto (a condição não sumiu), com a urgência atual.
 */
final class PuOperationalIncidentService
{
    public function __construct(
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * @param  int|null  $runId  execução do monitor que produziu o retrato: guarda
     *                           a resolução contra uma detecção de execução posterior
     * @return array{opened: int, updated: int, resolved: int, notify: list<int>}
     */
    public function synchronize(PuOperationalSnapshot $snapshot, CarbonImmutable $runStartedAt, ?int $runId = null): array
    {
        $present = [];

        foreach ($snapshot->conditions() as $condition) {
            $key = $condition->incidentKey();

            if (! isset($present[$key]) || $condition->severity->rank() > $present[$key]->severity->rank()) {
                $present[$key] = $condition;
            }
        }

        $opened = 0;
        $updated = 0;
        $notify = [];

        foreach ($present as $condition) {
            if (! $condition->alertRequired()) {
                $this->refreshOpenSeverity($condition, $runId);

                continue;
            }

            [$incident, $action] = $this->upsert($condition, $runId);
            $opened += $action === 'opened' ? 1 : 0;
            $updated += $action === 'opened' ? 0 : 1;

            if ($this->needsNotification($incident)) {
                $notify[] = (int) $incident->id;
            }
        }

        // Abertos que ainda não foram avisados (aviso anterior falhou) continuam devidos.
        foreach (PuOperationalIncident::query()->open()->whereNull('notified_at')->pluck('id') as $id) {
            if (! in_array((int) $id, $notify, true)) {
                $incident = PuOperationalIncident::query()->find($id);

                if ($incident instanceof PuOperationalIncident && $this->needsNotification($incident)) {
                    $notify[] = (int) $id;
                }
            }
        }

        return [
            'opened' => $opened,
            'updated' => $updated,
            'resolved' => $this->resolveCleared($snapshot, array_keys($present), $runStartedAt, $runId),
            'notify' => $notify,
        ];
    }

    /**
     * Reconhece um incidente aberto. Exige `pu.operations.recover`; fica na trilha
     * protegida. Não resolve, não corrige e não silencia a condição.
     *
     * @throws AuthorizationException
     */
    public function acknowledge(PuOperationalIncident $incident, User $actor, ?string $note = null): PuOperationalIncident
    {
        if (! $actor->can(AccessPermission::PuOperationsRecover->value)) {
            throw new AuthorizationException('Reconhecer incidente do PU exige a permissão pu.operations.recover.');
        }

        $acknowledged = DB::transaction(function () use ($incident, $actor, $note): PuOperationalIncident {
            $locked = PuOperationalIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new InvalidArgumentException('O incidente já foi resolvido: não há o que reconhecer.');
            }

            $locked->forceFill([
                'status' => PuIncidentStatus::Acknowledged,
                'acknowledged_at' => now(),
                'acknowledged_by' => $actor->getKey(),
                'acknowledgement_note' => filled($note) ? trim((string) $note) : null,
            ])->save();

            return $locked;
        });

        $this->auditLog->logIncidentAcknowledged($acknowledged, $actor);

        return $acknowledged;
    }

    public function needsNotification(PuOperationalIncident $incident): bool
    {
        if (! $incident->isOpen()) {
            return false;
        }

        $minimum = PuOperationalSeverity::tryFrom((string) config('pu_calculator.monitoring.notify_min_severity', 'warning')) ?? PuOperationalSeverity::Warning;

        if (! $incident->severity->atLeast($minimum)) {
            return false;
        }

        if ($incident->notified_at === null) {
            return $incident->status !== PuIncidentStatus::Acknowledged;
        }

        return $incident->notified_severity === null || $incident->severity->rank() > $incident->notified_severity->rank();
    }

    /**
     * @return array{0: PuOperationalIncident, 1: string}
     */
    private function upsert(PuOperationalCondition $condition, ?int $runId): array
    {
        $key = $condition->incidentKey();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $open = PuOperationalIncident::query()->where('open_key', $key)->first();

            if ($open instanceof PuOperationalIncident) {
                return [$this->touch($open, $condition, $runId), 'updated'];
            }

            $previous = PuOperationalIncident::query()
                ->where('incident_key', $key)
                ->whereNotNull('resolved_at')
                ->latest('id')
                ->first();
            $now = now();

            try {
                $incident = PuOperationalIncident::query()->create([
                    ...$this->attributes($condition),
                    'incident_key' => $key,
                    'status' => PuIncidentStatus::Active,
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'detection_count' => 1,
                    'last_detected_run_id' => $runId,
                    'recurrence_of_id' => $previous?->id,
                ]);

                return [$incident, 'opened'];
            } catch (UniqueConstraintViolationException) {
                // Outro monitor abriu o mesmo incidente agora: atualiza o dele.
                continue;
            }
        }

        throw new RuntimeException(sprintf('Não foi possível registrar o incidente %s.', $key));
    }

    private function touch(PuOperationalIncident $open, PuOperationalCondition $condition, ?int $runId): PuOperationalIncident
    {
        $escalated = $condition->severity->rank() > $open->severity->rank();

        PuOperationalIncident::query()->whereKey($open->id)->update([
            ...$this->encodedAttributes($condition),
            'last_detected_at' => now(),
            // Nunca volta: uma execução mais antiga que termina depois não apaga a
            // marca de uma mais nova.
            'last_detected_run_id' => $runId === null
                ? DB::raw('last_detected_run_id')
                : DB::raw(sprintf('case when last_detected_run_id is null or last_detected_run_id < %d then %d else last_detected_run_id end', $runId, $runId)),
            'detection_count' => DB::raw('detection_count + 1'),
            ...($escalated ? ['status' => PuIncidentStatus::Active->value] : []),
            'updated_at' => now(),
        ]);

        return $open->refresh();
    }

    /**
     * Condição que deixou de pedir ação, mas não sumiu: o aberto fica, com a
     * urgência de agora.
     */
    private function refreshOpenSeverity(PuOperationalCondition $condition, ?int $runId): void
    {
        $open = PuOperationalIncident::query()->where('open_key', $condition->incidentKey())->first();

        if ($open instanceof PuOperationalIncident) {
            $this->touch($open, $condition, $runId);
        }
    }

    /**
     * @param  list<string>  $presentKeys
     */
    private function resolveCleared(PuOperationalSnapshot $snapshot, array $presentKeys, CarbonImmutable $runStartedAt, ?int $runId): int
    {
        $resolved = 0;

        foreach ([
            PuOperationalConditionType::CHECK_CURVE,
            PuOperationalConditionType::CHECK_INDEX,
            PuOperationalConditionType::CHECK_OBLIGATIONS,
            PuOperationalConditionType::CHECK_REFRESH,
            PuOperationalConditionType::CHECK_SYSTEM,
        ] as $check) {
            $status = $snapshot->checks[$check]['status'] ?? 'failed';

            if (! in_array($status, ['ok', 'partial'], true)) {
                continue;
            }

            $failedEmissions = $snapshot->failedEmissionIds($check);
            $now = now();

            $resolved += PuOperationalIncident::query()
                ->open()
                ->where('check_name', $check)
                ->when($presentKeys !== [], fn ($query) => $query->whereNotIn('incident_key', $presentKeys))
                // Detecção de uma execução posterior a esta (reincidência no meio da
                // corrida) não é resolvida por ela.
                ->when(
                    $runId !== null,
                    fn ($query) => $query->where(fn ($guard) => $guard->whereNull('last_detected_run_id')->orWhere('last_detected_run_id', '<=', $runId)),
                    fn ($query) => $query->where('last_detected_at', '<=', $runStartedAt),
                )
                ->when($failedEmissions !== [], fn ($query) => $query->where(fn ($scope) => $scope
                    ->whereNull('emission_id')
                    ->orWhereNotIn('emission_id', $failedEmissions)))
                ->update([
                    'status' => PuIncidentStatus::Resolved->value,
                    'resolved_at' => $now,
                    'resolution' => 'condition_cleared',
                    'updated_at' => $now,
                ]);
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(PuOperationalCondition $condition): array
    {
        return [
            'type' => $condition->type,
            'check_name' => $condition->type->check(),
            'severity' => $condition->severity,
            'emission_id' => $condition->emissionId,
            'curve_version_id' => $condition->curveVersionId,
            'obligation_id' => $condition->obligationId,
            'settlement_conflict_id' => $condition->settlementConflictId,
            'indexer' => $condition->indexer,
            'business_date' => $condition->businessDate?->toDateString(),
            'reason' => $condition->reason,
            'context' => $condition->context,
        ];
    }

    /**
     * Os mesmos atributos, prontos para o update do query builder.
     *
     * @return array<string, mixed>
     */
    private function encodedAttributes(PuOperationalCondition $condition): array
    {
        $attributes = $this->attributes($condition);

        return [
            ...$attributes,
            'type' => $condition->type->value,
            'severity' => $condition->severity->value,
            'context' => json_encode($condition->context),
        ];
    }
}
