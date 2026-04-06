<?php

namespace App\Actions\Nimbus;

use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use Illuminate\Http\UploadedFile;

class StoreSubmissionFile
{
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
        $path = $file->store($directory ?? $this->defaultDirectory($submission, $origin), 'local');
        $storedName = basename($path);
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        $submissionFile = $submission->files()->create([
            'document_type' => $documentType,
            'origin' => $origin,
            'visible_to_user' => $visibleToUser,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'storage_path' => $path,
            'checksum' => $checksum,
            'uploaded_at' => now(),
        ]);

        $submissionFile->versions()->create([
            'version' => 1,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'storage_path' => $path,
            'size_bytes' => (int) $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'checksum' => $checksum,
            'uploaded_by_type' => $uploadedByType,
            'uploaded_by_id' => $uploadedById,
            'notes' => $notes,
        ]);

        return $submissionFile;
    }

    protected function defaultDirectory(Submission $submission, string $origin): string
    {
        return match ($origin) {
            'ADMIN' => "nimbus/submissions/{$submission->id}/responses",
            default => "nimbus/submissions/{$submission->id}",
        };
    }
}
