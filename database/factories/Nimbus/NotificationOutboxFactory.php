<?php

namespace Database\Factories\Nimbus;

use App\Models\Nimbus\NotificationOutbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Nimbus\NotificationOutbox>
 */
class NotificationOutboxFactory extends Factory
{
    protected $model = NotificationOutbox::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement([
                'token_created',
                'submission_received',
                'new_announcement',
            ]),
            'recipient_email' => $this->faker->safeEmail(),
            'recipient_name' => $this->faker->name(),
            'subject' => $this->faker->sentence(4),
            'template' => $this->faker->randomElement([
                'token_created',
                'submission_received',
                'new_announcement',
            ]),
            'payload_json' => [
                'message' => $this->faker->sentence(),
            ],
            'correlation_id' => (string) $this->faker->uuid(),
            'status' => 'PENDING',
            'attempts' => 0,
            'max_attempts' => 5,
            'next_attempt_at' => null,
            'sent_at' => null,
            'last_error' => null,
        ];
    }
}
