<?php

namespace App\DTOs\Nimbus;

use App\DTOs\BaseDTO;

readonly class LookupNimbusCnpjDTO extends BaseDTO
{
    public function __construct(
        public string $cnpj,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            cnpj: trim((string) ($data['cnpj'] ?? '')),
        );
    }
}
