<?php

namespace App\Services\Nimbus\Migration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only target state for collision analysis.
 * Never writes, no model saves, no events.
 */
class TargetStateReader
{
    public function __construct(
        private readonly string $connection = 'mysql',
    ) {
        // Allow sqlite in tests via config override
    }

    private function conn(): Connection
    {
        // Use default connection if specified connection not configured
        try {
            return DB::connection($this->connection);
        } catch (\Throwable $e) {
            return DB::connection();
        }
    }

    public function findPortalUserByNormalizedEmail(string $normalizedEmail): ?object
    {
        return $this->conn()->table('nimbus_portal_users')->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
    }

    public function findPortalUserByDocumentHash(string $hash): ?object
    {
        return $this->conn()->table('nimbus_portal_users')->where('document_number_hash', $hash)->first();
    }

    public function findSubmissionByReferenceCode(string $referenceCode): ?object
    {
        return $this->conn()->table('nimbus_submissions')->where('reference_code', trim($referenceCode))->first();
    }

    public function targetExists(string $entity, string $id): bool
    {
        $table = $this->entityToTable($entity);
        if ($table === null) {
            return false;
        }

        return $this->conn()->table($table)->where('id', $id)->exists();
    }

    public function findCanonicalMap(string $sourceEntity, string $sourceId, string $sourceRole = 'PRIMARY'): ?object
    {
        // Read from control connection (canonical maps live there)
        $control = config('nimbus.migration.control_connection') ?: config('database.default');
        try {
            return DB::connection($control)->table('nimbus_migration_maps')
                ->where('source_system', 'nimbusdocs')
                ->where('source_entity', $sourceEntity)
                ->where('source_id', $sourceId)
                ->where('source_role', $sourceRole)
                ->first();
        } catch (\Throwable $e) {
            return DB::table('nimbus_migration_maps')
                ->where('source_system', 'nimbusdocs')
                ->where('source_entity', $sourceEntity)
                ->where('source_id', $sourceId)
                ->where('source_role', $sourceRole)
                ->first();
        }
    }

    private function entityToTable(string $entity): ?string
    {
        return match ($entity) {
            'nimbus_portal_users', 'portal_users' => 'nimbus_portal_users',
            'nimbus_submissions', 'portal_submissions' => 'nimbus_submissions',
            'nimbus_submission_files', 'portal_submission_files' => 'nimbus_submission_files',
            'nimbus_submission_file_versions' => 'nimbus_submission_file_versions',
            'nimbus_documents', 'portal_documents' => 'nimbus_documents',
            'nimbus_general_documents', 'general_documents' => 'nimbus_general_documents',
            default => null,
        };
    }
}
