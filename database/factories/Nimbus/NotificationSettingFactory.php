<?php

namespace Database\Factories\Nimbus;

use App\Models\Nimbus\NotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Nimbus\NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'setting_key' => 'portal.notify.new_submission',
            'setting_value' => '1',
        ];
    }
}
