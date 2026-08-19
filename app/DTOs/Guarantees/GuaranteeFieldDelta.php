<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;

/**
 * O que um documento diz sobre um campo, comparado ao que está cadastrado.
 *
 * `kind` é a distinção central do §3 do escopo: preencher um campo vazio não é
 * a mesma operação que trocar um valor vigente, e repetir o valor que já existe
 * não é operação nenhuma — é uma fonte a mais.
 */
readonly class GuaranteeFieldDelta extends BaseDTO
{
    public const KIND_COMPLEMENT = 'complement';

    public const KIND_CONFIRMATION = 'confirmation';

    public const KIND_DIVERGENCE = 'divergence';

    public function __construct(
        public string $field,
        public string $label,
        public mixed $currentValue,
        public mixed $newValue,
        public string $currentDisplay,
        public string $newDisplay,
        public string $kind,
        /** O campo vive dentro do JSON `identification` da garantia? */
        public bool $isIdentification = false,
    ) {}

    public function isComplement(): bool
    {
        return $this->kind === self::KIND_COMPLEMENT;
    }

    public function isConfirmation(): bool
    {
        return $this->kind === self::KIND_CONFIRMATION;
    }

    public function isDivergence(): bool
    {
        return $this->kind === self::KIND_DIVERGENCE;
    }

    /**
     * @return array{field: string, label: string, from: string, to: string, kind: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'label' => $this->label,
            'from' => $this->currentDisplay,
            'to' => $this->newDisplay,
            'kind' => $this->kind,
        ];
    }
}
