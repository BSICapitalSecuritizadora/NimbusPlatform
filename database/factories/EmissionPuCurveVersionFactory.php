<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\Emission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmissionPuCurveVersion>
 */
class EmissionPuCurveVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'calculation_version' => 'v1',
            'batch_id' => (string) Str::uuid(),
            'status' => PuCurveStatus::Generated->value,
            'engine_version' => 'phase1-cdi-v1',
            'parameters_snapshot' => null,
            'rows_count' => 4,
            'error_message' => null,
            'validation_summary' => null,
            'generated_at' => now(),
        ];
    }

    public function homologated(): static
    {
        return $this->state(fn (): array => [
            'status' => PuCurveStatus::Homologated->value,
            'homologated_at' => now(),
        ]);
    }

    public function obsolete(): static
    {
        return $this->state(fn (): array => [
            'status' => PuCurveStatus::Obsolete->value,
        ]);
    }
}
