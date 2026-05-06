<?php

namespace App\Services;

use App\DTOs\Nimbus\StoreSubmissionFileDTO;
use App\Jobs\ScanFileForMalware;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Unified service for private document storage, database record creation,
 * and versioning — usable by Submissions and Proposals alike.
 */
class DocumentManager
{
    public function __construct(private readonly DocumentStorageService $documentStorageService) {}

    public function storeSubmissionFile(Submission $submission, StoreSubmissionFileDTO $dto): SubmissionFile
    {
        $directory = $dto->directory ?? $this->submissionDefaultDirectory($submission, $dto->origin);

        // Stage in the tmp area first. A crash here leaves the file in tmp_uploads/
        // where the daily GC will reclaim it — it never reaches the permanent path.
        $stagedFile = $this->documentStorageService->stagePrivateFile($dto->file);
        $finalPath = null;

        try {
            return DB::transaction(function () use ($submission, $dto, $stagedFile, $directory, &$finalPath): SubmissionFile {
                // Move from staging to the permanent directory inside the transaction so
                // that the DB record and the final file location are created together.
                $finalPath = $this->documentStorageService->moveStagedFile(
                    $stagedFile['path'],
                    $directory,
                    $stagedFile['stored_name'],
                );

                $submissionFile = $submission->files()->create([
                    'document_type' => $dto->documentType,
                    'origin' => $dto->origin,
                    'visible_to_user' => $dto->visibleToUser,
                    'original_name' => $stagedFile['original_name'],
                    'stored_name' => $stagedFile['stored_name'],
                    'mime_type' => $stagedFile['mime_type'],
                    'size_bytes' => $stagedFile['size_bytes'],
                    'storage_path' => $finalPath,
                    'checksum' => $stagedFile['checksum'],
                    'uploaded_at' => now(),
                ]);

                $submissionFile->versions()->create([
                    'version' => 1,
                    'original_name' => $stagedFile['original_name'],
                    'stored_name' => $stagedFile['stored_name'],
                    'storage_path' => $finalPath,
                    'size_bytes' => $stagedFile['size_bytes'],
                    'mime_type' => $stagedFile['mime_type'],
                    'checksum' => $stagedFile['checksum'],
                    'uploaded_by_type' => $dto->uploadedByType,
                    'uploaded_by_id' => $dto->uploadedById,
                    'notes' => $dto->notes,
                ]);

                ScanFileForMalware::dispatch(
                    DocumentStorageService::PRIVATE_DISK,
                    $finalPath,
                    "submission:{$submission->id}",
                );

                return $submissionFile;
            });
        } catch (Throwable $e) {
            // If the file was already moved to the final path before the failure,
            // clean it up from there; otherwise clean it up from staging.
            Storage::disk(DocumentStorageService::PRIVATE_DISK)->delete($finalPath ?? $stagedFile['path']);

            throw $e;
        }
    }

    protected function submissionDefaultDirectory(Submission $submission, string $origin): string
    {
        return match ($origin) {
            'ADMIN' => "submissions/{$submission->id}/responses",
            default => "submissions/{$submission->id}",
        };
    }
}
