<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplication extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'nova';

    public const STATUS_SCREENING = 'triagem';

    public const STATUS_INTERVIEW = 'entrevista';

    public const STATUS_FINALIST = 'finalista';

    public const STATUS_HIRED = 'contratada';

    public const STATUS_REJECTED = 'reprovada';

    protected $fillable = [
        'vacancy_id', 'name', 'email', 'phone',
        'linkedin_url', 'resume_path', 'message',
        'status', 'internal_notes', 'reviewed_at', 'reviewed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Nova',
            self::STATUS_SCREENING => 'Triagem',
            self::STATUS_INTERVIEW => 'Entrevista',
            self::STATUS_FINALIST => 'Finalista',
            self::STATUS_HIRED => 'Contratada',
            self::STATUS_REJECTED => 'Reprovada',
        ];
    }

    public static function statusLabelFor(?string $status): string
    {
        return static::statusOptions()[$status] ?? match ($status) {
            null => '—',
            default => ucfirst((string) $status),
        };
    }

    public static function statusColorFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_NEW => 'warning',
            self::STATUS_SCREENING => 'info',
            self::STATUS_INTERVIEW => 'primary',
            self::STATUS_FINALIST => 'success',
            self::STATUS_HIRED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return static::statusLabelFor($this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return static::statusColorFor($this->status);
    }
}
