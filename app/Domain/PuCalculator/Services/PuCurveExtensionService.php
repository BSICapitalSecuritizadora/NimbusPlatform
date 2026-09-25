<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveExtensionResult;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Support\Facades\DB;

/**
 * Extensão diária da curva vigente.
 *
 * Em vez de gravar a curva inteira a cada CDI publicado, a rotina recalcula a
 * curva em memória, prova que o trecho já gravado continua idêntico e grava só
 * os dias novos na MESMA versão. A curva é encadeada -- cada dia parte do
 * anterior --, então anexar em cima de um passado que mudou (CDI corrigido,
 * evento editado, feriado, parâmetro, integralização retroativa) produziria uma
 * curva errada com cara de certa. Por isso a comparação é dia a dia, com a
 * mesma canonicalização do checksum da curva.
 *
 * Quando o passado diverge:
 *  - numa curva comum, a extensão devolve `diverged` e quem chamou gera a curva
 *    inteira numa versão nova, como antes;
 *  - numa curva governada (homologada ou promovida pela revisão), nada é trocado:
 *    a divergência fica registrada na versão, a extensão fica suspensa e o
 *    reprocessamento passa a ser decisão humana.
 */
final class PuCurveExtensionService
{
    public const ACTION_EXTENDED = 'extended';

    public const ACTION_UP_TO_DATE = 'up_to_date';

    public const ACTION_NO_CURRENT_VERSION = 'no_current_version';

    public const ACTION_NOT_EXTENDABLE = 'not_extendable';

    public const ACTION_PREREQUISITES_BLOCKED = 'prerequisites_blocked';

    public const ACTION_DIVERGED = 'diverged';

    public const ACTION_DIVERGED_GOVERNED = 'diverged_governed';

    /** @var list<PuCurveStatus> */
    private const EXTENDABLE_STATUSES = [
        PuCurveStatus::Generated,
        PuCurveStatus::Validated,
        PuCurveStatus::Divergent,
        PuCurveStatus::Homologated,
    ];

    public function __construct(
        private readonly PuCurvePrerequisiteService $prerequisites,
        private readonly PuCurveGeneratorService $generator,
        private readonly PuPersistedCurveChecksumService $checksums,
        private readonly PuOperationalProfileGuard $operationalProfiles,
        private readonly LegacyProjectionService $legacyProjections,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function extend(Emission $emission): PuCurveExtensionResult
    {
        $version = $emission->currentPuCurveVersion();

        if (! $version instanceof EmissionPuCurveVersion) {
            return new PuCurveExtensionResult(self::ACTION_NO_CURRENT_VERSION);
        }

        if (! in_array($version->status, self::EXTENDABLE_STATUSES, true)) {
            return new PuCurveExtensionResult(
                action: self::ACTION_NOT_EXTENDABLE,
                versionId: $version->id,
                reason: sprintf('A versão %s está em %s.', $version->calculation_version, $version->status->label()),
            );
        }

        $prerequisites = $this->prerequisites->handle($emission);

        if (! $prerequisites->passes()) {
            return new PuCurveExtensionResult(
                action: self::ACTION_PREREQUISITES_BLOCKED,
                versionId: $version->id,
                reason: $prerequisites->blockingSummary(),
            );
        }

        $computedRows = $this->generator->handle($emission)->rows;
        $this->operationalProfiles->assertOperational($computedRows, 'a extensão da curva operacional');
        $persistedRows = $this->checksums->persistedRows($version);

        if ($persistedRows === []) {
            return $this->diverged($version, null, 'A versão vigente não tem linhas gravadas.');
        }

        $lastPersistedDate = $persistedRows[array_key_last($persistedRows)]->date->toDateString();
        $prefix = array_values(array_filter(
            $computedRows,
            fn (PuDailyCurveRowData $row): bool => $row->date->toDateString() <= $lastPersistedDate,
        ));
        $tail = array_values(array_filter(
            $computedRows,
            fn (PuDailyCurveRowData $row): bool => $row->date->toDateString() > $lastPersistedDate,
        ));

        if (! hash_equals($this->checksums->checksumForRows($persistedRows), $this->checksums->checksumForRows($prefix))) {
            return $this->diverged(
                $version,
                $this->firstDivergentDate($persistedRows, $prefix),
                'O recálculo não reproduz o trecho já gravado da curva.',
            );
        }

        if ($version->extension_diverged_at !== null) {
            $version->forceFill(['extension_diverged_at' => null, 'extension_divergence' => null])->save();
        }

        if ($tail === []) {
            return new PuCurveExtensionResult(self::ACTION_UP_TO_DATE, $version->id);
        }

        DB::transaction(function () use ($emission, $version, $tail): void {
            $timestamp = now();
            $rows = array_map(fn (PuDailyCurveRowData $row): array => [
                ...$row->toPersistenceArray($emission->id, $version->calculation_version),
                'curve_version_id' => $version->id,
                'extended_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $tail);

            foreach (array_chunk($rows, 500) as $chunk) {
                EmissionPuDailyCurve::query()->insert($chunk);
            }

            $version->forceFill([
                'extended_rows_count' => $version->extended_rows_count + count($rows),
                'last_extended_at' => $timestamp,
            ])->save();

            if ($emission->puParameter?->legacy_projection_enabled ?? true) {
                $this->legacyProjections->sync(
                    $emission,
                    new PuCurveGenerationResult($tail, $version->calculation_version),
                );
            }
        });

        $fromDate = $tail[0]->date->toDateString();
        $toDate = $tail[array_key_last($tail)]->date->toDateString();
        $this->auditLog->logCurveExtended($emission, $version, count($tail), $fromDate, $toDate);

        return new PuCurveExtensionResult(
            action: self::ACTION_EXTENDED,
            versionId: $version->id,
            appendedRows: count($tail),
            fromDate: $fromDate,
            toDate: $toDate,
        );
    }

    /**
     * Homologada ou promovida pela revisão: o conteúdo foi aprovado por alguém e
     * não pode ser trocado pela rotina.
     */
    public function isGoverned(EmissionPuCurveVersion $version): bool
    {
        return $version->status === PuCurveStatus::Homologated
            || $version->review_status === PuCurveReviewStatus::Approved;
    }

    private function diverged(
        EmissionPuCurveVersion $version,
        ?string $firstDivergentDate,
        string $reason,
    ): PuCurveExtensionResult {
        $governed = $this->isGoverned($version);

        if ($governed) {
            $version->forceFill([
                'extension_diverged_at' => $version->extension_diverged_at ?? now(),
                'extension_divergence' => [
                    'first_divergent_date' => $firstDivergentDate,
                    'reason' => $reason,
                    'checked_at' => now()->toIso8601String(),
                ],
            ])->save();
        }

        $this->auditLog->logCurveExtensionDiverged($version, $firstDivergentDate, $reason, $governed);

        return new PuCurveExtensionResult(
            action: $governed ? self::ACTION_DIVERGED_GOVERNED : self::ACTION_DIVERGED,
            versionId: $version->id,
            firstDivergentDate: $firstDivergentDate,
            reason: $reason,
        );
    }

    /**
     * @param  list<PuDailyCurveRowData>  $persistedRows
     * @param  list<PuDailyCurveRowData>  $computedRows
     */
    private function firstDivergentDate(array $persistedRows, array $computedRows): ?string
    {
        $computedByDate = [];

        foreach ($computedRows as $row) {
            $computedByDate[$row->date->toDateString()] = $row;
        }

        foreach ($persistedRows as $row) {
            $date = $row->date->toDateString();
            $computed = $computedByDate[$date] ?? null;

            if (! $computed instanceof PuDailyCurveRowData
                || ! hash_equals($this->checksums->checksumForRows([$row]), $this->checksums->checksumForRows([$computed]))) {
                return $date;
            }

            unset($computedByDate[$date]);
        }

        $extraDates = array_keys($computedByDate);
        sort($extraDates);

        return $extraDates[0] ?? null;
    }
}
