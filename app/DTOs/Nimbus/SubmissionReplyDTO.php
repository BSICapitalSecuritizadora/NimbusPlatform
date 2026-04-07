<?php

namespace App\DTOs\Nimbus;

use App\DTOs\BaseDTO;
use Illuminate\Http\UploadedFile;

readonly class SubmissionReplyDTO extends BaseDTO
{
    public function __construct(
        public ?string $comment,
        public ?UploadedFile $file,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            comment: self::nullableString($data['comment'] ?? null),
            file: ($data['file'] ?? null) instanceof UploadedFile ? $data['file'] : null,
        );
    }
}
