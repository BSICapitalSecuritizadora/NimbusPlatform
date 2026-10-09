<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Models\PuOperationalIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PuOperationalIncident>
 */
class PuOperationalIncidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = PuOperationalConditionType::IndexSyncFailed;

        return [
            'incident_key' => $type->value.':indexer:CDI',
            'type' => $type->value,
            'check_name' => $type->check(),
            'severity' => PuOperationalSeverity::Warning->value,
            'status' => PuIncidentStatus::Active->value,
            'indexer' => 'CDI',
            'reason' => 'A sincronização do CDI falhou.',
            'context' => [],
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'detection_count' => 1,
        ];
    }
}
