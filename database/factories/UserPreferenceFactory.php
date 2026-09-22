<?php

namespace Database\Factories;

use App\Enums\SidebarBehavior;
use App\Enums\TableDensity;
use App\Enums\UserInterfaceTheme;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
class UserPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'theme' => UserInterfaceTheme::System,
            'sidebar_behavior' => SidebarBehavior::Remember,
            'table_density' => TableDensity::Comfortable,
            'per_page' => 25,
            'home_page' => UserPreference::HOME_ADMIN,
        ];
    }
}
