<?php

namespace Database\Factories;

use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'subject' => fake()->randomElement(array_keys(ContactMessage::SUBJECT_OPTIONS)),
            'message' => fake()->paragraph(),
            'status' => ContactMessage::STATUS_NEW,
            'internal_notes' => null,
            'attended_by_user_id' => null,
            'attended_at' => null,
        ];
    }
}
