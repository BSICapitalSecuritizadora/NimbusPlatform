<?php

namespace App\Services;

use App\DTOs\Nimbus\StoreSubmissionFileDTO;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;

/**
 * Unified service for private document storage, database record creation,
 * and versioning — usable by Submissions and Proposals alike.
 */
class DocumentManager
{
    public function __construct(private readonly DocumentStorageService $documentStorageService) {}

    public function storeSubmissionFile(Submission $submission, StoreSubmissionFileDTO $dto): SubmissionFile
    {
        $storedFile = $this->documentStorageService->storePrivateFile(
            $dto->file,
            $dto->directory ?? $this->submissionDefaultDirectory($submission, $dto->origin),
        );

        $submissionFile = $submission->files()->create([
            'document_type' => $dto->documentType,
            'origin' => $dto->origin,
            'visible_to_user' => $dto->visibleToUser,
            'original_name' => $storedFile['original_name'],
            'stored_name' => $storedFile['stored_name'],
            'mime_type' => $storedFile['mime_type'],
            'size_bytes' => $storedFile['size_bytes'],
            'storage_path' => $storedFile['path'],
            'checksum' => $storedFile['checksum'],
            'uploaded_at' => now(),
        ]);

        $submissionFile->versions()->create([
            'version' => 1,
            'original_name' => $storedFile['original_name'],
            'stored_name' => $storedFile['stored_name'],
            'storage_path' => $storedFile['path'],
            'size_bytes' => $storedFile['size_bytes'],
            'mime_type' => $storedFile['mime_type'],
            'checksum' => $storedFile['checksum'],
            'uploaded_by_type' => $dto->uploadedByType,
            'uploaded_by_id' => $dto->uploadedById,
            'notes' => $dto->notes,
        ]);

        return $submissionFile;
    }

    protected function submissionDefaultDirectory(Submission $submission, string $origin): string
    {
        return match ($origin) {
            'ADMIN' => "submissions/{$submission->id}/responses",
            default => "submissions/{$submission->id}",
        };
    }
}
