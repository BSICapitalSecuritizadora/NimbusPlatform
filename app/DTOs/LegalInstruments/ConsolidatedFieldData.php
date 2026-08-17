<?php

declare(strict_types=1);

namespace App\DTOs\LegalInstruments;

use App\DTOs\BaseDTO;
use App\Enums\LegalInstrumentFieldKey;
use App\Models\LegalInstrumentField;

/**
 * Um campo consolidado: o valor vigente numa data, com a fonte que o sustenta e
 * o valor que ele substituiu.
 *
 * É a resposta às perguntas da regra principal do escopo — qual o valor
 * vigente, qual era o anterior, qual documento alterou, em que cláusula/página,
 * desde quando e quem confirmou.
 */
readonly class ConsolidatedFieldData extends BaseDTO
{
    public function __construct(
        public LegalInstrumentFieldKey $key,
        public LegalInstrumentField $current,
        public ?LegalInstrumentField $previous = null,
    ) {}

    public function label(): string
    {
        return $this->key->label();
    }

    public function formattedValue(): string
    {
        return $this->current->formatted_value;
    }

    public function previousFormattedValue(): ?string
    {
        return $this->previous?->formatted_value;
    }

    public function hasChanged(): bool
    {
        return $this->previous !== null && ! $this->current->hasSameValueAs($this->previous);
    }

    public function sourceDocumentLabel(): string
    {
        return $this->current->document_label;
    }

    public function sourceLocation(): ?string
    {
        return $this->current->source_label;
    }

    public function effectiveSince(): ?string
    {
        return $this->current->effective_date?->format('d/m/Y');
    }

    public function confirmedBy(): ?string
    {
        return $this->current->reviewer?->name;
    }
}
