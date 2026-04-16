<?php

namespace App\DTOs\Nimbus;

use App\DTOs\BaseDTO;
use Illuminate\Http\UploadedFile;

readonly class StoreSubmissionFileDTO extends BaseDTO
{
    public function __construct(
        public UploadedFile $file,
        public string $documentType,
        public string $origin,
        public bool $visibleToUser,
        public string $uploadedByType,
        public int $uploadedById,
        public ?string $notes = null,
        public ?string $directory = null,
    ) {}
}
