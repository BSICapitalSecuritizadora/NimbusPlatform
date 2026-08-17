<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;

/**
 * Mínimo contratual traduzido em número para uma competência.
 *
 * `amount` nulo com `basis = Formula` significa fórmula reconhecida mas não
 * computável com os dados disponíveis — caso em que o texto literal do contrato
 * fica em `description` para leitura humana, em vez de virar um valor inventado.
 */
readonly class ResolvedGuaranteeRequirement extends BaseDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?float $amount,
        public ?float $ratio,
        public GuaranteeRequirementBasis $basis,
        public ?GuaranteeRequirementBase $base = null,
        public ?string $description = null,
        public array $metadata = [],
    ) {}

    public static function none(): self
    {
        return new self(null, null, GuaranteeRequirementBasis::None);
    }

    public static function absolute(float $amount, ?string $description = null): self
    {
        return new self($amount, null, GuaranteeRequirementBasis::Absolute, null, $description);
    }

    public static function percentage(
        ?float $amount,
        float $ratio,
        GuaranteeRequirementBase $base,
        ?string $description = null,
        array $metadata = [],
    ): self {
        return new self($amount, $ratio, GuaranteeRequirementBasis::Percentage, $base, $description, $metadata);
    }

    public static function formula(
        ?float $amount,
        ?GuaranteeRequirementBase $base,
        ?string $description,
        array $metadata = [],
    ): self {
        return new self($amount, null, GuaranteeRequirementBasis::Formula, $base, $description, $metadata);
    }

    public function exists(): bool
    {
        return $this->basis !== GuaranteeRequirementBasis::None;
    }

    /** A regra existe mas não foi possível traduzi-la em valor nesta competência. */
    public function isUncomputable(): bool
    {
        return $this->exists() && $this->amount === null;
    }
}
