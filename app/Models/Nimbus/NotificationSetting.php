<?php

namespace App\Models\Nimbus;

use Database\Factories\Nimbus\NotificationSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    /** @use HasFactory<\Database\Factories\Nimbus\NotificationSettingFactory> */
    use HasFactory;

    protected $table = 'nimbus_notification_settings';

    protected $guarded = ['id'];

    protected static function newFactory(): NotificationSettingFactory
    {
        return NotificationSettingFactory::new();
    }

    public static function getValues(array $keys): array
    {
        return static::query()
            ->whereIn('setting_key', $keys)
            ->pluck('setting_value', 'setting_key')
            ->all();
    }

    public static function setValues(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value],
            );
        }
    }
}
