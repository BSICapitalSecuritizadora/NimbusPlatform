<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContactMessage>
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
            'subject' => fake()->randomElement([
                'Relações com investidores',
                'Comercial e novos negócios',
                'Compliance e canal de ética',
                'Carreiras / Trabalhe conosco',
                'Assuntos institucionais',
            ]),
            'message' => fake()->paragraph(),
            'status' => \App\Models\ContactMessage::STATUS_NEW,
            'internal_notes' => null,
            'attended_by_user_id' => null,
            'attended_at' => null,
        ];
    }
}
