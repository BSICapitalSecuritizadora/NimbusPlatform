<?php

namespace Database\Factories;

use App\Enums\SalesBoardRolloutEventType;
use App\Enums\SalesBoardSource;
use App\Models\Emission;
use App\Models\SalesBoardRolloutEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardRolloutEvent>
 */
class SalesBoardRolloutEventFactory extends Factory
{
    protected $model = SalesBoardRolloutEvent::class;

    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'event_type' => SalesBoardRolloutEventType::Activated,
            'from_source' => SalesBoardSource::Legacy,
            'to_source' => SalesBoardSource::Automated,
            'start_reference_month' => '2026-08-01',
            'reason' => 'Ativação de teste com motivo suficiente.',
        ];
    }
}
