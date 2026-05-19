<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\EmissionAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmissionAccess>
 */
class EmissionAccessFactory extends Factory
{
    protected $model = EmissionAccess::class;

    public function definition(): array
    {
        $code = '123456';

        return [
            'emission_id' => Emission::factory(),
            'token' => (string) Str::uuid(),
            'requester_name' => fake()->name(),
            'requester_email' => fake()->safeEmail(),
            'requester_phone' => fake()->numerify('(##) #####-####'),
            'code_hash' => Hash::make($code),
            'code_encrypted' => Crypt::encryptString($code),
            'sent_at' => now(),
            'first_accessed_at' => null,
            'last_accessed_at' => null,
            'verified_at' => null,
            'last_used_at' => null,
            'expires_at' => now()->addDays(7),
            'revoked_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
