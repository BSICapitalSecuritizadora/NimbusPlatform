<?php

namespace App\Models;

use App\Enums\BusinessArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Linha do catálogo de áreas. O código vem de {@see BusinessArea}; as linhas
 * nascem por migration e nunca são criadas nem apagadas pela interface.
 */
class Area extends Model
{
    protected $fillable = [
        'code',
    ];

    protected function casts(): array
    {
        return [
            'code' => BusinessArea::class,
        ];
    }

    public static function for(BusinessArea $area): self
    {
        return self::query()->where('code', $area->value)->firstOrFail();
    }

    public function responsibles(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('assigned_by')
            ->withTimestamps()
            ->orderBy('users.name');
    }
}
