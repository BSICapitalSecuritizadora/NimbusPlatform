<?php

namespace Database\Factories;

use App\Enums\ClientPersonType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_type' => ClientPersonType::Individual,
            'name' => fake()->name(),
            'trade_name' => null,
            'document' => self::validCpf(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('219########'),
        ];
    }

    public function company(): static
    {
        return $this->state(fn (): array => [
            'person_type' => ClientPersonType::Company,
            'name' => fake()->company().' Ltda',
            'trade_name' => fake()->company(),
            'document' => self::validCnpj(),
        ]);
    }

    /**
     * Documents are generated with valid check digits so factory records pass
     * the very same rules the form and the import apply.
     */
    public static function validCpf(): string
    {
        do {
            $base = str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        } while (preg_match('/^(\d)\1{8}$/', $base) === 1);

        foreach ([9, 10] as $digitPosition) {
            $sum = 0;
            $weight = $digitPosition + 1;

            for ($index = 0; $index < $digitPosition; $index++) {
                $sum += (int) $base[$index] * $weight;
                $weight--;
            }

            $remainder = ($sum * 10) % 11;
            $base .= $remainder === 10 ? '0' : (string) $remainder;
        }

        return $base;
    }

    public static function validCnpj(): string
    {
        $base = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT).'0001';

        foreach ([12, 13] as $digitPosition) {
            $sum = 0;
            $weight = $digitPosition === 12 ? 5 : 6;

            for ($index = 0; $index < $digitPosition; $index++) {
                $sum += (int) $base[$index] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $remainder = $sum % 11;
            $base .= (string) ($remainder < 2 ? 0 : 11 - $remainder);
        }

        return $base;
    }
}
