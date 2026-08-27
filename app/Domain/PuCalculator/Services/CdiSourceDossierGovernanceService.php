<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Enums\AccessPermission;
use App\Models\IndexRateSourceGovernanceReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CdiSourceDossierGovernanceService
{
    public const SOURCE_CODE = 'bcb_sgs_4389';

    /** @return array<string, mixed> */
    public function latest(): array
    {
        $disk = (string) config('pu_indexes.source_homologation.artifact_disk', 'local');
        $directory = trim((string) config('pu_indexes.source_homologation.artifact_directory'), '/');
        $paths = collect(Storage::disk($disk)->files($directory))
            ->filter(fn (string $path): bool => Str::endsWith($path, '.json'))
            ->sortDesc()
            ->values();
        $path = $paths->first();

        if (! is_string($path)) {
            return $this->missingDossier($disk);
        }

        try {
            $contents = Storage::disk($disk)->get($path);
            $report = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                ...$this->missingDossier($disk),
                'artifact_found' => true,
                'artifact_path' => $path,
                'artifact_checksum' => hash('sha256', $contents ?? ''),
                'integrity_error' => 'O artefato mais recente não contém JSON válido.',
            ];
        }

        if (! is_array($report)) {
            return $this->missingDossier($disk);
        }

        $reportChecksum = (string) ($report['report_checksum'] ?? '');
        $checksumValid = $this->reportChecksumIsValid($report);
        $summary = (array) data_get($report, 'comparison.summary', []);
        $technicalSatisfied = $checksumValid
            && ($report['workflow_status'] ?? null) === 'ready_for_review'
            && Str::startsWith((string) ($report['classification'] ?? ''), 'B')
            && (int) ($summary['common_dates'] ?? 0) >= (int) config('pu_indexes.source_homologation.minimum_common_records', 250)
            && (int) ($summary['present_equal'] ?? 0) === (int) ($summary['common_dates'] ?? -1)
            && (int) ($summary['present_different'] ?? -1) === 0
            && (int) ($summary['only_b3'] ?? -1) === 0
            && (int) ($summary['only_bcb'] ?? -1) === 0
            && hash_equals(
                (string) data_get($report, 'sources.b3.normalized_checksum', ''),
                (string) data_get($report, 'sources.bcb.normalized_checksum', 'different'),
            );
        $review = $reportChecksum !== ''
            ? IndexRateSourceGovernanceReview::query()
                ->with('reviewedBy:id,name')
                ->where('source_code', self::SOURCE_CODE)
                ->where('report_checksum', $reportChecksum)
                ->where('status', IndexRateSourceGovernanceReview::STATUS_APPROVED)
                ->first()
            : null;

        return [
            'artifact_found' => true,
            'artifact_disk' => $disk,
            'artifact_path' => $path,
            'artifact_checksum' => hash('sha256', $contents),
            'report_checksum' => $reportChecksum,
            'checksum_valid' => $checksumValid,
            'technical_homologation_satisfied' => $technicalSatisfied,
            'workflow_status' => $report['workflow_status'] ?? null,
            'classification' => $report['classification'] ?? null,
            'approved' => $review instanceof IndexRateSourceGovernanceReview,
            'report' => $report,
            'executor' => $report['executor'] ?? [
                'type' => 'console_command',
                'label' => 'Execução histórica via comando Artisan; usuário da aplicação não registrado',
                'user_id' => null,
            ],
            'executed_at' => $report['completed_at'] ?? null,
            'review' => $review === null ? null : [
                'status' => $review->status,
                'reviewed_by' => $review->reviewedBy?->name,
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
                'review_notes' => $review->review_notes,
            ],
        ];
    }

    public function approveLatest(User $reviewer, string $notes): IndexRateSourceGovernanceReview
    {
        if (! $reviewer->can(AccessPermission::PuCalendarHomologationReview->value)) {
            throw new AuthorizationException('Você não possui permissão para revisar o dossiê da fonte DI.');
        }

        $dossier = $this->latest();

        if (! ($dossier['technical_homologation_satisfied'] ?? false)) {
            throw ValidationException::withMessages([
                'dossier' => 'Somente um dossiê íntegro, classificado como B e pronto para revisão pode ser aprovado.',
            ]);
        }

        if ($dossier['approved'] ?? false) {
            throw ValidationException::withMessages([
                'dossier' => 'O dossiê mais recente já possui aprovação operacional.',
            ]);
        }

        $normalizedNotes = trim($notes);

        if ($normalizedNotes === '') {
            throw ValidationException::withMessages([
                'review_notes' => 'Registre a conclusão da revisão antes de aprovar o dossiê.',
            ]);
        }

        $executorUserId = data_get($dossier, 'report.executor.user_id');

        if ($executorUserId !== null && (int) $executorUserId === (int) $reviewer->id) {
            throw new PuMakerCheckerException('O executor da homologação não pode aprovar o próprio dossiê.');
        }

        return IndexRateSourceGovernanceReview::query()->create([
            'source_code' => self::SOURCE_CODE,
            'report_checksum' => $dossier['report_checksum'],
            'artifact_disk' => $dossier['artifact_disk'],
            'artifact_path' => $dossier['artifact_path'],
            'status' => IndexRateSourceGovernanceReview::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $normalizedNotes,
        ]);
    }

    /** @param array<string, mixed> $report */
    private function reportChecksumIsValid(array $report): bool
    {
        $expected = (string) ($report['report_checksum'] ?? '');
        unset($report['report_checksum']);
        $actual = hash('sha256', (string) json_encode(
            $report,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $expected !== '' && hash_equals($expected, $actual);
    }

    /** @return array<string, mixed> */
    private function missingDossier(string $disk): array
    {
        return [
            'artifact_found' => false,
            'artifact_disk' => $disk,
            'artifact_path' => null,
            'artifact_checksum' => null,
            'report_checksum' => null,
            'checksum_valid' => false,
            'technical_homologation_satisfied' => false,
            'workflow_status' => null,
            'classification' => null,
            'approved' => false,
            'report' => null,
            'executor' => null,
            'executed_at' => null,
            'review' => null,
        ];
    }
}
