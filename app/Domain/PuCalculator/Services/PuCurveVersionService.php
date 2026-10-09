<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Support\PuVersionNumber;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclo de vida da versão de curva.
 *
 * Toda transição relê o status da versão sob trava, dentro de uma transação, e
 * só então decide ({@see PuCurveStatus::allowedTransitions()}): o status que a
 * tela ou o job viram antes nunca é tomado como o status final. A ordem das
 * travas é sempre Emissão → versão(ões) → pagamentos, a mesma da homologação, da
 * invalidação e da conciliação de pagamentos.
 */
class PuCurveVersionService
{
    /**
     * Cria o registro de versao no estado "processing" antes da geracao começar.
     *
     * @param  array<string, mixed>  $parametersSnapshot
     */
    public function startGeneration(
        Emission $emission,
        ?int $requestedByUserId,
        array $parametersSnapshot = [],
        ?string $calculationVersion = null,
    ): EmissionPuCurveVersion {
        return DB::transaction(function () use (
            $emission,
            $requestedByUserId,
            $parametersSnapshot,
            $calculationVersion,
        ): EmissionPuCurveVersion {
            $lockedEmission = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();

            return EmissionPuCurveVersion::query()->create([
                'emission_id' => $lockedEmission->id,
                'calculation_version' => $calculationVersion ?? $this->nextCalculationVersion($lockedEmission),
                'curve_role' => PuCurveRole::Operational,
                'review_status' => PuCurveReviewStatus::NotApplicable,
                'batch_id' => (string) Str::uuid(),
                'status' => PuCurveStatus::Processing,
                'engine_version' => PuAuditLogService::ENGINE_VERSION,
                'parameters_snapshot' => $parametersSnapshot !== [] ? $parametersSnapshot : null,
                'generated_by' => $requestedByUserId,
            ]);
        });
    }

    /**
     * Conclui a geração. Só uma versão ainda em `processing` vira `generated`:
     * se ela foi substituída ou invalidada enquanto calculava, a conclusão é
     * recusada e a versão continua como está -- a geração nunca ressuscita uma
     * versão que já saiu de cena.
     */
    public function markGenerated(
        EmissionPuCurveVersion $version,
        int $rowsCount,
        ?string $calculationVersion = null,
    ): EmissionPuCurveVersion {
        return DB::transaction(function () use ($version, $rowsCount, $calculationVersion): EmissionPuCurveVersion {
            $locked = $this->lockVersion($version);

            if ($locked->status !== PuCurveStatus::Processing) {
                throw new PuCurveGovernanceException(sprintf(
                    'A versão %s não está mais em processamento (status atual: %s); a conclusão da geração foi descartada.',
                    $locked->calculation_version,
                    $locked->status->label(),
                ));
            }

            $this->transition($locked, PuCurveStatus::Generated, [
                'rows_count' => $rowsCount,
                'calculation_version' => $calculationVersion ?? $locked->calculation_version,
                'generated_at' => now(),
                'error_message' => null,
            ]);

            if ($locked->isOperational()) {
                $this->markPreviousVersionsObsolete($locked);
            }

            return $this->synchronize($version, $locked);
        });
    }

    /**
     * Registra a falha da geração. Uma versão que já saiu de cena (substituída ou
     * invalidada) fica como está: o erro vai para o log da geração, não para ela.
     */
    public function markError(EmissionPuCurveVersion $version, string $message): EmissionPuCurveVersion
    {
        return DB::transaction(function () use ($version, $message): EmissionPuCurveVersion {
            $locked = $this->lockVersion($version);

            if (! $locked->status->canTransitionTo(PuCurveStatus::Error)) {
                return $this->synchronize($version, $locked);
            }

            $this->transition($locked, PuCurveStatus::Error, ['error_message' => $message]);

            return $this->synchronize($version, $locked);
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function markValidated(
        EmissionPuCurveVersion $version,
        bool $approved,
        array $summary,
        ?int $validatedByUserId,
    ): EmissionPuCurveVersion {
        return DB::transaction(function () use ($version, $approved, $summary, $validatedByUserId): EmissionPuCurveVersion {
            $locked = $this->lockVersion($version);

            // Fase 6 (P0-05): a validação alimenta a homologação; indexador sem
            // homologação operacional não registra resultado numa versão operacional.
            if ($locked->isOperational()) {
                app(PuIndexerCapabilityPolicy::class)->assertForVersion($locked, PuIndexerCapability::Validation);
            }

            if (! $locked->status->acceptsValidationResult()) {
                throw new PuCurveGovernanceException(sprintf(
                    'A versão %s está %s; o resultado da validação não é registrado nela.',
                    $locked->calculation_version,
                    mb_strtolower($locked->status->label()),
                ));
            }

            $attributes = [
                'validation_summary' => $summary,
                'validated_at' => now(),
                'validated_by' => $validatedByUserId,
            ];

            if ($locked->status === PuCurveStatus::Homologated) {
                $locked->forceFill($attributes)->save();

                return $this->synchronize($version, $locked);
            }

            $this->transition($locked, $approved ? PuCurveStatus::Validated : PuCurveStatus::Divergent, $attributes);

            return $this->synchronize($version, $locked);
        });
    }

    /**
     * Chamado pela homologação, que já trava a emissão e a versão e já revalidou
     * a elegibilidade: aqui fica só a guarda do grafo de transições.
     */
    public function markHomologated(
        EmissionPuCurveVersion $version,
        ?int $homologatedByUserId,
        bool $selfHomologated = false,
        ?string $justification = null,
    ): EmissionPuCurveVersion {
        $this->transition($version, PuCurveStatus::Homologated, [
            'homologated_at' => now(),
            'homologated_by' => $homologatedByUserId,
            'self_homologated' => $selfHomologated,
            'homologation_justification' => $justification,
        ]);

        return $version;
    }

    /**
     * Chamado pela invalidação, que já trava a emissão e a versão.
     */
    public function markInvalidated(EmissionPuCurveVersion $version, ?int $invalidatedByUserId): EmissionPuCurveVersion
    {
        if (! $version->status->canBeInvalidated()) {
            throw new PuCurveGovernanceException(sprintf(
                'A versão %s está %s e não pode ser invalidada.',
                $version->calculation_version,
                mb_strtolower($version->status->label()),
            ));
        }

        $this->transition($version, PuCurveStatus::Obsolete, [
            'obsolete_reason' => 'invalidated',
            'invalidated_at' => now(),
            'invalidated_by' => $invalidatedByUserId,
        ]);

        return $version;
    }

    /**
     * Versão operacional nomeada EXPLICITAMENTE. Nunca resolve "a vigente": quem
     * age sobre uma versão diz qual. Duas operacionais com o mesmo rótulo são
     * ambiguidade, e ambiguidade é recusa, não escolha da mais nova.
     */
    public function findByCalculationVersion(Emission $emission, string $calculationVersion): ?EmissionPuCurveVersion
    {
        $matches = EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->operational()
            ->where('calculation_version', $calculationVersion)
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw new PuCurveGovernanceException(sprintf(
                'Há mais de uma versão operacional %s nesta emissão; a ação foi recusada.',
                $calculationVersion,
            ));
        }

        return $matches->first();
    }

    /**
     * A versão nomeada, travada para a ação de governança. Deve ser chamada
     * dentro da transação que já travou a emissão.
     */
    public function lockForGovernance(Emission $emission, ?string $calculationVersion): EmissionPuCurveVersion
    {
        if (blank($calculationVersion)) {
            throw new PuCurveGovernanceException('Informe a versão da curva: as ações de governança nunca escolhem a versão sozinhas.');
        }

        $version = $this->findByCalculationVersion($emission, (string) $calculationVersion);

        if (! $version instanceof EmissionPuCurveVersion) {
            throw new PuCurveGovernanceException(sprintf('A versão %s não existe nesta emissão.', $calculationVersion));
        }

        return $this->lockVersion($version);
    }

    public function hasHomologatedVersion(Emission $emission): bool
    {
        return EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->operational()
            ->homologated()
            ->exists();
    }

    /**
     * Proxima versao "vN" considerando linhas de curva e registros de versao ja existentes.
     */
    public function nextCalculationVersion(Emission $emission): string
    {
        $fromCurves = EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->pluck('calculation_version');

        $fromVersions = EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->pluck('calculation_version');

        return PuVersionNumber::formatNext($fromCurves->merge($fromVersions)->all());
    }

    /**
     * Trava a emissão e depois a versão, nessa ordem, e devolve a versão relida.
     */
    private function lockVersion(EmissionPuCurveVersion $version): EmissionPuCurveVersion
    {
        Emission::query()->whereKey($version->emission_id)->lockForUpdate()->first();

        return EmissionPuCurveVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(EmissionPuCurveVersion $version, PuCurveStatus $target, array $attributes = []): void
    {
        if (! $version->status->canTransitionTo($target)) {
            throw new PuCurveGovernanceException(sprintf(
                'Transição recusada: a versão %s não passa de %s para %s.',
                $version->calculation_version,
                mb_strtolower($version->status->label()),
                mb_strtolower($target->label()),
            ));
        }

        $version->forceFill(['status' => $target, ...$attributes])->save();
    }

    /**
     * Quem chamou continua com a própria instância: copia nela o estado gravado.
     */
    private function synchronize(EmissionPuCurveVersion $original, EmissionPuCurveVersion $locked): EmissionPuCurveVersion
    {
        $original->setRawAttributes($locked->getAttributes(), true);

        return $original;
    }

    private function markPreviousVersionsObsolete(EmissionPuCurveVersion $version): void
    {
        EmissionPuCurveVersion::query()
            ->where('emission_id', $version->emission_id)
            ->operational()
            ->where('id', '!=', $version->id)
            ->whereNotIn('status', [PuCurveStatus::Homologated->value, PuCurveStatus::Obsolete->value])
            ->update([
                'status' => PuCurveStatus::Obsolete->value,
                'obsolete_reason' => 'superseded',
            ]);
    }
}
