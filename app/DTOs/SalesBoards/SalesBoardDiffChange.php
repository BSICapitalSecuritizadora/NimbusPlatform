<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardDiffCode;

/**
 * Uma diferença isolada entre duas versões, com os dois lados legíveis.
 *
 * Guardar o antes e o depois já formatados é deliberado: quem lê um diff precisa
 * ver "R$ 950.000,00 → R$ 960.000,00", não dois ponteiros para objetos que
 * teriam de ser reabertos -- e um deles pode não existir mais.
 */
readonly class SalesBoardDiffChange extends BaseDTO
{
    public function __construct(
        public SalesBoardDiffCode $code,
        public ?string $before = null,
        public ?string $after = null,
    ) {}

    public function isSourceOnly(): bool
    {
        return $this->code->isSourceOnly();
    }

    public function describe(): string
    {
        if (($this->before === null) && ($this->after === null)) {
            return $this->code->label();
        }

        return sprintf('%s: %s → %s', $this->code->label(), $this->before ?? '—', $this->after ?? '—');
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'label' => $this->code->label(),
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}
