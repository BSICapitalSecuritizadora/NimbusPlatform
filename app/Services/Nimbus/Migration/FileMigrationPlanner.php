<?php

namespace App\Services\Nimbus\Migration;

/**
 * Plans file migrations including version=1 baseline artifact.
 * One legacy base file → one base file + one version baseline (per DocumentManager invariant).
 */
class FileMigrationPlanner
{
    public function __construct(
        private readonly LegacyFilePathResolver $resolver,
    ) {}

    /**
     * @return array<int, array> list of planned map rows (PRIMARY + VERSION_BASELINE)
     */
    public function planSubmissionFile(
        LegacySubmissionFileDto $dto,
        string $fingerprint,
        array $availableSourceFiles,
        array $submissionMapBySourceId,
        ?string $actualSourceSha256 = null,
    ): array {
        $parent = $submissionMapBySourceId[(string) $dto->submissionId] ?? null;
        if ($parent === null) {
            return [[
                'source_entity' => 'portal_submission_files',
                'source_id' => (string) $dto->id,
                'target_entity' => 'nimbus_submission_files',
                'target_role' => 'PRIMARY',
                'status' => 'INVALID_RELATION',
                'disposition' => 'Parent submission not mapped',
                'error_code' => 'MISSING_PARENT',
                'source_fingerprint' => $fingerprint,
                'legacy_sha256' => $dto->checksum,
                'actual_source_sha256' => $actualSourceSha256,
                'metadata' => ['submission_id' => (string) $dto->submissionId],
            ]];
        }

        $resolve = $this->resolver->resolve($dto, $availableSourceFiles);

        if ($resolve['status'] === 'MISSING_SOURCE_FILE') {
            return [[
                'source_entity' => 'portal_submission_files',
                'source_id' => (string) $dto->id,
                'target_entity' => 'nimbus_submission_files',
                'target_role' => 'PRIMARY',
                'status' => 'MISSING_SOURCE_FILE',
                'disposition' => 'Source file not found for any candidate path',
                'error_code' => 'MISSING_SOURCE_FILE',
                'source_fingerprint' => $fingerprint,
                'legacy_sha256' => $dto->checksum,
                'actual_source_sha256' => null,
                'metadata' => ['storage_path' => $dto->storagePath, 'candidates' => $resolve['candidates']],
            ]];
        }

        if ($resolve['status'] === 'AMBIGUOUS_SOURCE_FILE') {
            return [[
                'source_entity' => 'portal_submission_files',
                'source_id' => (string) $dto->id,
                'target_entity' => 'nimbus_submission_files',
                'target_role' => 'PRIMARY',
                'status' => 'AMBIGUOUS_SOURCE_FILE',
                'disposition' => 'Multiple source files match basename/candidate — fail-closed',
                'error_code' => 'AMBIGUOUS_SOURCE_FILE',
                'source_fingerprint' => $fingerprint,
                'legacy_sha256' => $dto->checksum,
                'actual_source_sha256' => $actualSourceSha256,
                'metadata' => ['candidates' => $resolve['candidates'] ?? []],
            ]];
        }

        $checksumPlan = ChecksumService::planStatus($dto->checksum, $actualSourceSha256, null);
        if ($checksumPlan['status'] === 'CHECKSUM_MISMATCH') {
            return [[
                'source_entity' => 'portal_submission_files',
                'source_id' => (string) $dto->id,
                'target_entity' => 'nimbus_submission_files',
                'target_role' => 'PRIMARY',
                'status' => 'CHECKSUM_MISMATCH',
                'disposition' => 'Legacy recorded checksum does not match actual source file',
                'error_code' => 'CHECKSUM_MISMATCH',
                'source_fingerprint' => $fingerprint,
                'legacy_sha256' => $checksumPlan['legacy_sha256'],
                'actual_source_sha256' => $checksumPlan['actual_source_sha256'],
                'metadata' => ['storage_path' => $dto->storagePath],
            ]];
        }

        $targetPath = LegacyFilePathResolver::plannedTargetPath('portal_submission_files', $parent['target_id'] ?? '000', $dto->storedName);

        // Guard: target path must never contain legacy absolute markers
        if (str_contains($targetPath, 'C:') || str_contains($targetPath, 'xampp') || str_contains($targetPath, '..')) {
            throw new \LogicException('Target path leaked legacy path');
        }

        // Primary base file
        $primary = [
            'source_entity' => 'portal_submission_files',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_submission_files',
            'target_role' => 'PRIMARY',
            'status' => 'MIGRATED',
            'disposition' => 'Planned base file',
            'error_code' => null,
            'source_fingerprint' => $fingerprint,
            'legacy_sha256' => $checksumPlan['legacy_sha256'],
            'actual_source_sha256' => $checksumPlan['actual_source_sha256'],
            'metadata' => [
                'storage_path' => $dto->storagePath,
                'planned_target_path' => $targetPath,
                'resolved_source_path' => $resolve['resolved_path'],
                'checksum_status' => $checksumPlan['status'],
            ],
        ];

        // Derived version=1 baseline (structural invariant, not legacy history)
        $versionBaseline = [
            'source_entity' => 'portal_submission_files',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_submission_file_versions',
            'target_role' => 'VERSION_BASELINE',
            'status' => 'MIGRATED',
            'disposition' => 'Planned version=1 baseline (DocumentManager invariant)',
            'error_code' => null,
            'source_fingerprint' => $fingerprint.'::v1',
            'legacy_sha256' => $checksumPlan['legacy_sha256'],
            'actual_source_sha256' => $checksumPlan['actual_source_sha256'],
            'metadata' => [
                'version' => 1,
                'parent_source_id' => (string) $dto->id,
                'planned_target_path' => $targetPath,
            ],
        ];

        return [$primary, $versionBaseline];
    }

    public function planPortalDocument(
        LegacyPortalDocumentDto $dto,
        string $fingerprint,
        array $availableSourceFiles,
        array $userMapBySourceId,
        ?string $actualSourceSha256 = null,
    ): array {
        $parent = $userMapBySourceId[(string) $dto->portalUserId] ?? null;
        if ($parent === null) {
            return [[
                'source_entity' => 'portal_documents',
                'source_id' => (string) $dto->id,
                'target_entity' => 'nimbus_documents',
                'target_role' => 'PRIMARY',
                'status' => 'INVALID_RELATION',
                'disposition' => 'Parent portal_user not mapped',
                'error_code' => 'MISSING_PARENT',
                'source_fingerprint' => $fingerprint,
                'metadata' => ['portal_user_id' => (string) $dto->portalUserId],
            ]];
        }
        $resolve = $this->resolver->resolve($dto, $availableSourceFiles);
        if ($resolve['status'] !== 'RESOLVED') {
            return [[
                'source_entity' => 'portal_documents',
                'source_id' => (string) $dto->id,
                'target_entity' => 'nimbus_documents',
                'target_role' => 'PRIMARY',
                'status' => $resolve['status'],
                'disposition' => $resolve['status'],
                'error_code' => $resolve['status'],
                'source_fingerprint' => $fingerprint,
                'metadata' => ['candidates' => $resolve['candidates'] ?? []],
            ]];
        }
        $targetPath = LegacyFilePathResolver::plannedTargetPath('portal_documents', $parent['target_id'] ?? '000', basename($dto->filePath));

        return [[
            'source_entity' => 'portal_documents',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_documents',
            'target_role' => 'PRIMARY',
            'status' => 'MIGRATED',
            'disposition' => 'Planned portal document',
            'error_code' => null,
            'source_fingerprint' => $fingerprint,
            'metadata' => ['planned_target_path' => $targetPath, 'resolved_source_path' => $resolve['resolved_path']],
        ]];
    }

    public function planGeneralDocument(
        LegacyGeneralDocumentDto $dto,
        string $fingerprint,
        array $availableSourceFiles,
        ?string $actualSourceSha256 = null,
    ): array {
        $resolve = $this->resolver->resolve($dto, $availableSourceFiles);
        if ($resolve['status'] !== 'RESOLVED') {
            return [[
                'source_entity' => 'general_documents',
                'source_id' => (string) $dto->id,
                'target_entity' => 'nimbus_general_documents',
                'target_role' => 'PRIMARY',
                'status' => $resolve['status'],
                'disposition' => $resolve['status'],
                'error_code' => $resolve['status'],
                'source_fingerprint' => $fingerprint,
                'metadata' => ['candidates' => $resolve['candidates'] ?? []],
            ]];
        }
        $targetPath = LegacyFilePathResolver::plannedTargetPath('general_documents', $dto->categoryId, basename($dto->filePath));

        return [[
            'source_entity' => 'general_documents',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_general_documents',
            'target_role' => 'PRIMARY',
            'status' => 'MIGRATED',
            'disposition' => 'Planned general document',
            'error_code' => null,
            'source_fingerprint' => $fingerprint,
            'metadata' => [
                'category_id' => (string) $dto->categoryId,
                'is_active' => $dto->isActive,
                'published_at' => $dto->publishedAt,
                'planned_target_path' => $targetPath,
                'resolved_source_path' => $resolve['resolved_path'],
            ],
        ]];
    }
}
