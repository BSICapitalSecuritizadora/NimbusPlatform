<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMessage extends Model
{
    /** @use HasFactory<\Database\Factories\ContactMessageFactory> */
    use HasFactory;

    public const STATUS_NEW = 'novo';

    public const STATUS_IN_PROGRESS = 'em_atendimento';

    public const STATUS_DONE = 'respondido';

    protected $fillable = [
        'name', 'email', 'phone', 'subject', 'message',
        'status', 'internal_notes', 'attended_by_user_id', 'attended_at',
    ];

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Novo',
            self::STATUS_IN_PROGRESS => 'Em atendimento',
            self::STATUS_DONE => 'Respondido',
        ];
    }

    public static function statusLabelFor(?string $status): string
    {
        return static::statusOptions()[$status] ?? ucfirst((string) $status);
    }

    public static function statusColorFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_NEW => 'warning',
            self::STATUS_IN_PROGRESS => 'info',
            self::STATUS_DONE => 'success',
            default => 'gray',
        };
    }

    public function attendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by_user_id');
    }
}
