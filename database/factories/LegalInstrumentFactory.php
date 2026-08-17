<?php

namespace Database\Factories;

use App\Enums\LegalInstrumentType;
use App\Models\Emission;
use App\Models\LegalInstrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalInstrument>
 */
class LegalInstrumentFactory extends Factory
{
    protected $model = LegalInstrument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'type' => LegalInstrumentType::Ccb,
            'number' => '001/2026',
            'name' => 'CCB nº 001/2026',
            'status' => LegalInstrument::STATUS_ACTIVE,
        ];
    }

    public function ofType(LegalInstrumentType $type, ?string $number = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'number' => $number,
            'name' => $number === null ? $type->label() : sprintf('%s nº %s', $type->shortLabel(), $number),
        ]);
    }
}
