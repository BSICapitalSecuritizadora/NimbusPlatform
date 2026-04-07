<?php

namespace App\DTOs\Proposals;

use App\DTOs\BaseDTO;

readonly class VerifyProposalContinuationDTO extends BaseDTO
{
    public function __construct(
        public string $cnpj,
        public string $code,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            cnpj: trim((string) ($data['cnpj'] ?? '')),
            code: trim((string) ($data['code'] ?? '')),
        );
    }
}
