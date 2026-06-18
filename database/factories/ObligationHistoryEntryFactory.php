<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationHistoryEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationHistoryEntry>
 */
class ObligationHistoryEntryFactory extends Factory
{
    protected $model = ObligationHistoryEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $emission = Emission::factory();

        return [
            'obligation_id' => Obligation::factory()->for($emission),
            'emission_id' => $emission,
            'user_id' => null,
            'event_type' => ObligationHistoryEntry::EVENT_CREATED,
            'source' => ObligationHistoryEntry::SOURCE_MANUAL,
            'title' => 'Obrigação criada',
            'description' => null,
            'old_values' => null,
            'new_values' => null,
            'metadata' => null,
            'occurred_at' => now(),
        ];
    }
}
