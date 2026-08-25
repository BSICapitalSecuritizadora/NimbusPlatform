<?php

namespace App\Services\Nimbus\Migration;

/**
 * Abstraction for read-only legacy source.
 * Implementations must never write to the legacy connection.
 */
interface LegacySourceReader
{
    /**
     * @return iterable<LegacyPortalUserDto>
     */
    public function portalUsers(): iterable;

    /**
     * @return iterable<LegacySubmissionDto>
     */
    public function submissions(): iterable;

    /**
     * @return iterable<LegacyShareholderDto>
     */
    public function shareholders(): iterable;

    /**
     * @return iterable<LegacySubmissionFileDto>
     */
    public function submissionFiles(): iterable;

    /**
     * @return iterable<LegacyNoteDto>
     */
    public function notes(): iterable;

    /**
     * @return iterable<LegacyPortalDocumentDto>
     */
    public function portalDocuments(): iterable;

    /**
     * @return iterable<LegacyGeneralDocumentDto>
     */
    public function generalDocuments(): iterable;

    /**
     * @return iterable<LegacyAccessTokenDto>
     */
    public function accessTokens(): iterable;

    /**
     * @return iterable<LegacyNotificationDto>
     */
    public function notifications(): iterable;

    /**
     * @return iterable<LegacyAuditDto>
     */
    public function auditLogs(): iterable;

    public function findPortalUserById(int|string $id): ?LegacyPortalUserDto;

    public function findSubmissionById(int|string $id): ?LegacySubmissionDto;
}
