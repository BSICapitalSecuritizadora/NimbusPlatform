<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuExternalBenchmarkStatus;
use App\Models\Emission;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionPuExternalBenchmark>
 */
class EmissionPuExternalBenchmarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'source_type' => 'external_file',
            'source_name' => fake()->company(),
            'source_document_id' => null,
            'source_evidence_id' => null,
            'reference_as_of' => fake()->date(),
            'input_file_name' => 'pu-reference.csv',
            'file_sha256' => hash('sha256', fake()->uuid()),
            'dataset_sha256' => hash('sha256', fake()->uuid()),
            'import_identity_sha256' => hash('sha256', fake()->uuid()),
            'row_count' => 1,
            'from_date' => fake()->date(),
            'to_date' => fake()->date(),
            'status' => PuExternalBenchmarkStatus::Ready->value,
            'created_by' => User::factory(),
        ];
    }
}
