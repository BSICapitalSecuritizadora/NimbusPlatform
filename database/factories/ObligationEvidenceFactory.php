<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationEvidence>
 */
class ObligationEvidenceFactory extends Factory
{
    protected $model = ObligationEvidence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $emission = Emission::factory();

        return [
            'obligation_id' => Obligation::factory()->for($emission),
            'emission_id' => $emission,
            'uploaded_by' => null,
            'original_name' => fake()->word().'.pdf',
            'path' => 'nimbus_docs/obligation-evidences/'.fake()->uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 2_000_000),
            'description' => null,
            'uploaded_at' => now(),
        ];
    }
}
