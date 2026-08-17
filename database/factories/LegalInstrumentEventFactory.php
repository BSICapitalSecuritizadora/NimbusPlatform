<?php

namespace Database\Factories;

use App\Enums\LegalInstrumentEventType;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalInstrumentEvent>
 */
class LegalInstrumentEventFactory extends Factory
{
    protected $model = LegalInstrumentEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_instrument_id' => LegalInstrument::factory(),
            'event_type' => LegalInstrumentEventType::Constitution,
            'effective_date' => '2024-01-10',
            'title' => 'Constituição',
        ];
    }
}
