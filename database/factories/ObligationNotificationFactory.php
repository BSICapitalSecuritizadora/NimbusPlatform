<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationNotification>
 */
class ObligationNotificationFactory extends Factory
{
    protected $model = ObligationNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'obligation_id' => Obligation::factory(),
            'emission_id' => Emission::factory(),
            'notification_type' => ObligationNotification::TYPE_DUE_SOON,
            'milestone' => 'due_7',
            'recipient' => fake()->safeEmail(),
            'status' => ObligationNotification::STATUS_SENT,
            'error_message' => null,
            'sent_at' => now(),
        ];
    }
}
