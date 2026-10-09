<?php

namespace App\Observers;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveChangeImpactClassifier;
use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Trilha das mudanças de insumo contratual da curva de PU (Fase 4).
 *
 * Cada gravação de evento, integralização ou parâmetros de PU registra, na trilha
 * `pu-calculation`: quem, o quê, valor anterior e novo, a data a partir da qual
 * vale, motivo e documento quando houver, e -- o que a trilha genérica não sabe --
 * o impacto da mudança em cada versão viva da curva (a oficial e a de trabalho),
 * decidido pelo classificador central. Nada aqui muda a curva: é a extensão, a
 * homologação e a atualidade que aplicam a decisão.
 *
 * Fase 5: depois do commit, as obrigações financeiras da emissão são refeitas --
 * uma mudança que alcança o trecho já calculado da oficial deixa o esperado em
 * dúvida (reprocessamento), e a conciliação acompanha.
 */
class PuContractualInputObserver
{
    /**
     * Campos que não descrevem o contrato (carimbos de tempo, marca de governança).
     *
     * @var list<string>
     */
    private const IGNORED_FIELDS = ['created_at', 'updated_at', 'governed_at', 'active_identity'];

    public function __construct(
        private readonly PuCurveChangeImpactClassifier $classifier,
        private readonly PuAuditLogService $auditLog,
        private readonly PuFinancialObligationService $obligations,
    ) {}

    public function saved(Model $model): void
    {
        $changed = array_diff(array_keys($model->getChanges()), self::IGNORED_FIELDS);

        if ($changed === [] && ! ($model->wasRecentlyCreated && $model->getChanges() === [])) {
            return;
        }

        $created = $model->wasRecentlyCreated && $model->getChanges() === [];
        $action = match (true) {
            $created => 'created',
            $model instanceof EmissionPuEvent
                && in_array('status', $changed, true)
                && $model->status === PuEventStatus::Cancelled => 'cancelled',
            default => 'updated',
        };
        $before = $created ? [] : $this->only($model->getRawOriginal(), $changed);
        $after = $created
            ? $this->only($model->getAttributes(), array_diff(array_keys($model->getAttributes()), self::IGNORED_FIELDS))
            : $this->only($model->getAttributes(), $changed);

        $this->record($model, $action, $before, $after, (int) $model->getAttribute('emission_id'));
    }

    public function deleted(Model $model): void
    {
        $original = $model->getRawOriginal();

        $this->record(
            $model,
            'deleted',
            $this->only($original, array_diff(array_keys($original), self::IGNORED_FIELDS)),
            [],
            (int) ($original['emission_id'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function record(Model $model, string $action, array $before, array $after, int $emissionId): void
    {
        $emission = Emission::query()->find($emissionId);

        if (! $emission instanceof Emission) {
            return;
        }

        // Integralização é da Emissão: só vira insumo de curva quando há PU configurado.
        if ($model instanceof IntegralizationHistory
            && ! EmissionPuParameter::query()->where('emission_id', $emissionId)->exists()) {
            return;
        }

        $this->auditLog->logContractualInputChanged(
            emission: $emission,
            input: match (true) {
                $model instanceof EmissionPuEvent => 'pu_event',
                $model instanceof IntegralizationHistory => 'integralization',
                default => 'pu_parameters',
            },
            inputId: $model->getKey() !== null ? (int) $model->getKey() : null,
            action: $action,
            before: $before,
            after: $after,
            effectiveDate: $this->effectiveDate($model),
            reason: $model instanceof EmissionPuEvent
                ? ($model->cancellation_reason ?? $model->effective_date_justification)
                : null,
            documentReference: $model instanceof EmissionPuEvent
                ? ($model->document_reference ?? $model->effective_date_evidence_reference)
                : null,
            affectedVersions: $this->affectedVersions($emission),
            requestedByUserId: auth()->id() !== null ? (int) auth()->id() : null,
        );

        $this->obligations->refreshAfterCommit($emissionId, 'contractual_input_changed');
    }

    /**
     * A oficial e a de trabalho, cada uma com o impacto da mudança.
     *
     * @return list<array<string, mixed>>
     */
    private function affectedVersions(Emission $emission): array
    {
        $versions = collect([$emission->officialPuCurveVersion(), $emission->currentPuCurveVersion()])
            ->filter(fn (?EmissionPuCurveVersion $version): bool => $version instanceof EmissionPuCurveVersion
                && $version->status !== PuCurveStatus::Obsolete)
            ->unique('id')
            ->values();

        return $versions->map(function (EmissionPuCurveVersion $version): array {
            $entry = [
                'curve_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'status' => $version->status->value,
            ];

            try {
                $assessment = $this->classifier->assessVersion($version);
            } catch (PuCurveInputsException $exception) {
                return [...$entry, 'impact' => null, 'note' => $exception->getMessage()];
            }

            if ($assessment === null) {
                return [...$entry, 'impact' => null, 'note' => 'Versão sem retrato de insumos (anterior à Fase 4).'];
            }

            return [
                ...$entry,
                'impact' => $assessment->impact->value,
                'earliest_affected_date' => $assessment->earliestAffectedDate?->toDateString(),
                'extension_limit' => $assessment->extensionLimit()?->toDateString(),
            ];
        })->all();
    }

    private function effectiveDate(Model $model): ?string
    {
        $date = match (true) {
            $model instanceof EmissionPuEvent => $model->effective_date,
            $model instanceof IntegralizationHistory => $model->date,
            default => null,
        };

        return $date instanceof CarbonInterface ? $date->toDateString() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $attributes, array $keys): array
    {
        $selected = [];

        foreach ($keys as $key) {
            $value = $attributes[$key] ?? null;
            $selected[$key] = match (true) {
                $value instanceof CarbonInterface => $value->toDateString(),
                $value instanceof \BackedEnum => $value->value,
                default => $value,
            };
        }

        ksort($selected);

        return $selected;
    }
}
