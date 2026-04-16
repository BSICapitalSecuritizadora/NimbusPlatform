<?php

declare(strict_types=1);

namespace App\DTOs\Nimbus;

use Illuminate\Http\UploadedFile;

readonly class StoreAdminSubmissionResponseFilesDTO
{
    /**
     * @param  array<int, UploadedFile>  $responseFiles
     */
    public function __construct(
        public array $responseFiles,
        public bool $visibleToUser = true,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            responseFiles: array_values(array_filter(
                $data['response_files'] ?? [],
                static fn (mixed $file): bool => $file instanceof UploadedFile,
            )),
            visibleToUser: (bool) ($data['visible_to_user'] ?? true),
        );
    }
}
