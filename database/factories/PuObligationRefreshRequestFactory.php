<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Models\Emission;
use App\Models\PuObligationRefreshRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PuObligationRefreshRequest>
 */
class PuObligationRefreshRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'trigger' => 'index_rate_corrected',
            'status' => PuObligationRefreshStatus::Pending->value,
            'correlation_id' => (string) Str::uuid(),
            'requested_at' => now(),
            'attempts' => 0,
            'next_attempt_at' => now(),
        ];
    }
}
