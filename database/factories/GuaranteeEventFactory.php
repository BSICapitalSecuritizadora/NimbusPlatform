<?php

namespace Database\Factories;

use App\Enums\GuaranteeEventType;
use App\Models\Guarantee;
use App\Models\GuaranteeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuaranteeEvent>
 */
class GuaranteeEventFactory extends Factory
{
    protected $model = GuaranteeEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guarantee_id' => Guarantee::factory(),
            'event_type' => GuaranteeEventType::Constitution,
            'effective_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'title' => 'Constituição',
            'source' => GuaranteeEvent::SOURCE_MANUAL,
        ];
    }

    public function ofType(GuaranteeEventType $eventType, string $effectiveDate): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_type' => $eventType,
            'effective_date' => $effectiveDate,
            'title' => $eventType->label(),
        ]);
    }
}
