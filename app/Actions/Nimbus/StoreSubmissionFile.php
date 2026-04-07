<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use App\Services\DocumentStorageService;
use Illuminate\Http\UploadedFile;

class StoreSubmissionFile
{
    public function __construct(
        protected DocumentStorageService $documentStorageService,
    ) {}

    public function handle(
        Submission $submission,
        UploadedFile $file,
        string $documentType,
        string $origin,
        bool $visibleToUser,
        string $uploadedByType,
        int $uploadedById,
        ?string $notes = null,
        ?string $directory = null,
    ): SubmissionFile {
        $storedFile = $this->documentStorageService->storePrivateFile(
            $file,
            $directory ?? $this->defaultDirectory($submission, $origin),
        );

        $submissionFile = $submission->files()->create([
            'document_type' => $documentType,
            'origin' => $origin,
            'visible_to_user' => $visibleToUser,
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
            'uploaded_by_type' => $uploadedByType,
            'uploaded_by_id' => $uploadedById,
            'notes' => $notes,
        ]);

        return $submissionFile;
    }

    protected function defaultDirectory(Submission $submission, string $origin): string
    {
        return match ($origin) {
            'ADMIN' => "submissions/{$submission->id}/responses",
            default => "submissions/{$submission->id}",
        };
    }
}
