<?php

declare(strict_types=1);

namespace App\DTOs\Nimbus;

use App\DTOs\BaseDTO;

readonly class SubmissionShareholderDTO extends BaseDTO
{
    public function __construct(
        public string $name,
        public ?string $rg,
        public ?string $cnpj,
        public float $percentage,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            rg: self::nullableString($data['rg'] ?? null),
            cnpj: self::nullableString($data['cnpj'] ?? null),
            percentage: (float) ($data['percentage'] ?? 0),
        );
    }

    /**
     * @return array{name:string,rg:?string,cnpj:?string,percentage:float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'rg' => $this->rg,
            'cnpj' => $this->cnpj,
            'percentage' => $this->percentage,
        ];
    }
}
