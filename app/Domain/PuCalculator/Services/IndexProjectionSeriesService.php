<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Models\IndexProjectionSeries;
use App\Models\User;
use InvalidArgumentException;

/**
 * Ciclo de vida maker/checker das séries projetadas de número-índice.
 *
 * - O importador (maker) cria a série em estado "importada".
 * - O checker aprova/rejeita; o checker NÃO pode ser o mesmo usuário que importou, exceto super admin.
 * - A curva operacional só consome projeção de séries APROVADAS (ver {@see IpcaIndexResolver} e
 *   {@see PuCurvePrerequisiteService}).
 */
class IndexProjectionSeriesService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(PuIndexer $indexer, array $attributes, ?int $importedByUserId): IndexProjectionSeries
    {
        return IndexProjectionSeries::create([
            'indexer' => $indexer->value,
            'name' => $attributes['name'] ?? sprintf('%s projetado', $indexer->value),
            'status' => IndexProjectionSeriesStatus::Imported->value,
            'projection_source' => $attributes['projection_source'] ?? null,
            'projection_policy' => $attributes['projection_policy'] ?? 'market',
            'version' => $attributes['version'] ?? 'v1',
            'reference_date' => $attributes['reference_date'] ?? null,
            'description' => $attributes['description'] ?? null,
            'imported_by' => $importedByUserId,
            'imported_at' => now(),
        ]);
    }

    public function approve(IndexProjectionSeries $series, ?int $approverUserId): IndexProjectionSeries
    {
        if (! $series->status->isPendingDecision()) {
            throw new InvalidArgumentException(sprintf(
                'Apenas séries em rascunho ou importadas podem ser aprovadas (status atual: %s).',
                $series->status->label(),
            ));
        }

        $this->assertCheckerIsNotImporter($series, $approverUserId);

        $series->forceFill([
            'status' => IndexProjectionSeriesStatus::Approved->value,
            'approved_by' => $approverUserId,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        return $series;
    }

    public function reject(IndexProjectionSeries $series, ?int $rejectedByUserId, ?string $reason = null): IndexProjectionSeries
    {
        if (! $series->status->isPendingDecision()) {
            throw new InvalidArgumentException(sprintf(
                'Apenas séries em rascunho ou importadas podem ser rejeitadas (status atual: %s).',
                $series->status->label(),
            ));
        }

        $this->assertCheckerIsNotImporter($series, $rejectedByUserId);

        $series->forceFill([
            'status' => IndexProjectionSeriesStatus::Rejected->value,
            'rejected_by' => $rejectedByUserId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        return $series;
    }

    public function obsolete(IndexProjectionSeries $series, ?string $reason = null): IndexProjectionSeries
    {
        $series->forceFill([
            'status' => IndexProjectionSeriesStatus::Obsolete->value,
            'obsolete_reason' => $reason,
            'obsoleted_at' => now(),
        ])->save();

        return $series;
    }

    /**
     * Maker/checker: o aprovador/rejeitador não pode ser o importador, exceto super admin.
     */
    private function assertCheckerIsNotImporter(IndexProjectionSeries $series, ?int $checkerUserId): void
    {
        if ($checkerUserId === null) {
            return;
        }

        $checker = User::find($checkerUserId);

        if ($checker !== null && method_exists($checker, 'hasRole') && $checker->hasRole('super-admin')) {
            return;
        }

        if ($series->imported_by !== null && (int) $series->imported_by === $checkerUserId) {
            throw new PuMakerCheckerException(
                'A aprovação da série projetada exige segregação maker/checker: quem importou a série não pode aprová-la ou rejeitá-la. Solicite a decisão a outro usuário autorizado (ou a um super admin).',
            );
        }
    }
}
