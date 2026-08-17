<?php

declare(strict_types=1);

namespace App\DTOs\LegalInstruments;

use App\DTOs\BaseDTO;
use App\Enums\LegalInstrumentFieldKey;
use App\Models\Guarantee;
use App\Models\LegalInstrument;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Posição jurídica consolidada de um instrumento numa data (§9 e §12).
 *
 * `asOf` guarda a data usada na reconstrução: a mesma estrutura serve para a
 * visão executiva de hoje e para o relatório retroativo de dezembro.
 */
readonly class InstrumentPositionData extends BaseDTO
{
    /**
     * @param  Collection<string, ConsolidatedFieldData>  $fields  indexados pelo valor do field key
     * @param  Collection<int, Guarantee>  $guarantees
     */
    public function __construct(
        public LegalInstrument $instrument,
        public CarbonInterface $asOf,
        public Collection $fields,
        public Collection $guarantees,
    ) {}

    public function field(LegalInstrumentFieldKey $key): ?ConsolidatedFieldData
    {
        return $this->fields->get($key->value);
    }

    public function value(LegalInstrumentFieldKey $key): ?string
    {
        return $this->field($key)?->formattedValue();
    }

    public function numeric(LegalInstrumentFieldKey $key): ?float
    {
        return $this->field($key)?->current->value_numeric;
    }

    /**
     * Valor consolidado ou a frase de ausência — nunca zero (§7 do escopo).
     */
    public function valueOrNotFound(LegalInstrumentFieldKey $key): string
    {
        return $this->value($key) ?? 'Valor não localizado no documento.';
    }

    /**
     * Campos agrupados como a interface os exibe (Identificação, Financeiro…).
     *
     * @return Collection<string, Collection<int, ConsolidatedFieldData>>
     */
    public function fieldsByGroup(): Collection
    {
        return $this->fields
            ->values()
            ->groupBy(fn (ConsolidatedFieldData $field): string => $field->key->group());
    }

    /**
     * Campos que mudaram em relação à versão anterior — o que a visão executiva
     * destaca como efeito dos aditamentos.
     *
     * @return Collection<int, ConsolidatedFieldData>
     */
    public function changedFields(): Collection
    {
        return $this->fields
            ->values()
            ->filter(fn (ConsolidatedFieldData $field): bool => $field->hasChanged())
            ->values();
    }
}
