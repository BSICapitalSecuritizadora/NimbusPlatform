<?php

namespace App\Services\Nimbus\Migration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Real read-only database reader for NimbusDocs.
 * Executes only SELECT-style reads via Query Builder / cursor().
 * Never writes.
 */
class DatabaseLegacySourceReader implements LegacySourceReader
{
    public function __construct(
        private readonly string $connection = 'nimbus_legacy',
    ) {}

    private function conn(): Connection
    {
        return DB::connection($this->connection);
    }

    public function portalUsers(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_users')->orderBy('id')->cursor() as $row) {
                yield LegacyPortalUserDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function submissions(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_submissions')->orderBy('id')->cursor() as $row) {
                yield LegacySubmissionDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function shareholders(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_submission_shareholders')->orderBy('id')->cursor() as $row) {
                yield LegacyShareholderDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function submissionFiles(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_submission_files')->orderBy('id')->cursor() as $row) {
                yield LegacySubmissionFileDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function notes(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_submission_notes')->orderBy('id')->cursor() as $row) {
                yield LegacyNoteDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function portalDocuments(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_documents')->orderBy('id')->cursor() as $row) {
                yield LegacyPortalDocumentDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function generalDocuments(): iterable
    {
        try {
            $query = $this->conn()->table('general_documents')
                ->leftJoin('document_categories', 'document_categories.id', '=', 'general_documents.category_id')
                ->select('general_documents.*', 'document_categories.name as category_name')
                ->orderBy('general_documents.id');
            foreach ($query->cursor() as $row) {
                yield LegacyGeneralDocumentDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function accessTokens(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_access_tokens')->orderBy('id')->cursor() as $row) {
                yield LegacyAccessTokenDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function notifications(): iterable
    {
        try {
            foreach ($this->conn()->table('notification_outbox')->orderBy('id')->cursor() as $row) {
                yield LegacyNotificationDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function auditLogs(): iterable
    {
        try {
            foreach ($this->conn()->table('audit_logs')->orderBy('occurred_at')->cursor() as $row) {
                yield LegacyAuditDto::fromArray((array) $row);
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function findPortalUserById(int|string $id): ?LegacyPortalUserDto
    {
        try {
            $row = $this->conn()->table('portal_users')->where('id', $id)->first();

            return $row ? LegacyPortalUserDto::fromArray((array) $row) : null;
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }

            return null;
        }
    }

    public function findSubmissionById(int|string $id): ?LegacySubmissionDto
    {
        try {
            $row = $this->conn()->table('portal_submissions')->where('id', $id)->first();

            return $row ? LegacySubmissionDto::fromArray((array) $row) : null;
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }

            return null;
        }
    }

    public function portalAnnouncements(): iterable
    {
        try {
            foreach ($this->conn()->table('portal_announcements')->orderBy('id')->cursor() as $row) {
                yield $row;
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function tags(): iterable
    {
        try {
            foreach ($this->conn()->table('tags')->orderBy('id')->cursor() as $row) {
                yield $row;
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }

    public function submissionTags(): iterable
    {
        try {
            foreach ($this->conn()->table('submission_tags')->orderBy('submission_id')->cursor() as $row) {
                yield $row;
            }
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
        }
    }
}
