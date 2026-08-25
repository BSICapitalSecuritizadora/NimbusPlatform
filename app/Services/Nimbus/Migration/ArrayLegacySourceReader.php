<?php

namespace App\Services\Nimbus\Migration;

/**
 * In-memory fixture source reader for tests and dry-run against snapshot.
 * Wraps associative arrays, never writes.
 */
class ArrayLegacySourceReader implements LegacySourceReader
{
    /** @param array<string, array<int, array<string,mixed>>> $data */
    public function __construct(private readonly array $data) {}

    public function portalUsers(): iterable
    {
        foreach ($this->data['portal_users'] ?? [] as $row) {
            yield LegacyPortalUserDto::fromArray($row);
        }
    }

    public function submissions(): iterable
    {
        foreach ($this->data['portal_submissions'] ?? [] as $row) {
            yield LegacySubmissionDto::fromArray($row);
        }
    }

    public function shareholders(): iterable
    {
        foreach ($this->data['portal_submission_shareholders'] ?? [] as $row) {
            yield LegacyShareholderDto::fromArray($row);
        }
    }

    public function submissionFiles(): iterable
    {
        foreach ($this->data['portal_submission_files'] ?? [] as $row) {
            yield LegacySubmissionFileDto::fromArray($row);
        }
    }

    public function notes(): iterable
    {
        foreach ($this->data['portal_submission_notes'] ?? [] as $row) {
            yield LegacyNoteDto::fromArray($row);
        }
    }

    public function portalDocuments(): iterable
    {
        foreach ($this->data['portal_documents'] ?? [] as $row) {
            yield LegacyPortalDocumentDto::fromArray($row);
        }
    }

    public function generalDocuments(): iterable
    {
        foreach ($this->data['general_documents'] ?? [] as $row) {
            yield LegacyGeneralDocumentDto::fromArray($row);
        }
    }

    public function accessTokens(): iterable
    {
        foreach ($this->data['portal_access_tokens'] ?? [] as $row) {
            yield LegacyAccessTokenDto::fromArray($row);
        }
    }

    public function notifications(): iterable
    {
        foreach ($this->data['notification_outbox'] ?? [] as $row) {
            yield LegacyNotificationDto::fromArray($row);
        }
    }

    public function auditLogs(): iterable
    {
        foreach ($this->data['audit_logs'] ?? [] as $row) {
            yield LegacyAuditDto::fromArray($row);
        }
    }

    public function findPortalUserById(int|string $id): ?LegacyPortalUserDto
    {
        foreach ($this->portalUsers() as $dto) {
            if ((string) $dto->id === (string) $id) {
                return $dto;
            }
        }

        return null;
    }

    public function findSubmissionById(int|string $id): ?LegacySubmissionDto
    {
        foreach ($this->submissions() as $dto) {
            if ((string) $dto->id === (string) $id) {
                return $dto;
            }
        }

        return null;
    }
}
