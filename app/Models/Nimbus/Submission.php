<?php

namespace App\Models\Nimbus;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Submission extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';

    public const STATUS_NEEDS_CORRECTION = 'NEEDS_CORRECTION';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_REJECTED = 'REJECTED';

    protected $table = 'nimbus_submissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_us_person' => 'boolean',
            'is_pep' => 'boolean',
            'shareholder_data' => 'array',
            'submitted_at' => 'datetime',
            'status_updated_at' => 'datetime',
            'net_worth' => 'decimal:2',
            'annual_revenue' => 'decimal:2',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_UNDER_REVIEW => 'Em Análise',
            self::STATUS_NEEDS_CORRECTION => 'Aguardando Correção',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_REJECTED => 'Rejeitada',
        ];
    }

    public static function persistableStatusFor(string $status): string
    {
        if (($status === self::STATUS_NEEDS_CORRECTION) && (! static::supportsNeedsCorrectionStatus())) {
            return self::STATUS_UNDER_REVIEW;
        }

        return $status;
    }

    public static function supportsNeedsCorrectionStatus(): bool
    {
        $override = config('nimbus.submissions.supports_needs_correction_status');

        if (is_bool($override)) {
            return $override;
        }

        static $supportsNeedsCorrectionStatus;

        if ($supportsNeedsCorrectionStatus !== null) {
            return $supportsNeedsCorrectionStatus;
        }

        $connection = static::query()->getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return $supportsNeedsCorrectionStatus = true;
        }

        $column = $connection->selectOne(sprintf(
            "SHOW COLUMNS FROM `%s` LIKE 'status'",
            (new static)->getTable(),
        ));

        $columnType = is_object($column) ? ($column->Type ?? null) : null;

        return $supportsNeedsCorrectionStatus = str_contains((string) $columnType, self::STATUS_NEEDS_CORRECTION);
    }

    public static function statusLabelFor(?string $status): string
    {
        return static::statusOptions()[$status] ?? match ($status) {
            null => '—',
            default => Str::headline((string) $status),
        };
    }

    public static function statusColorFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_UNDER_REVIEW => 'info',
            self::STATUS_NEEDS_CORRECTION => 'warning',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    public static function statusIconFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'bi-clock',
            self::STATUS_UNDER_REVIEW => 'bi-search',
            self::STATUS_NEEDS_CORRECTION => 'bi-arrow-counterclockwise',
            self::STATUS_COMPLETED => 'bi-check-all',
            self::STATUS_REJECTED => 'bi-x-circle',
            default => 'bi-dash',
        };
    }

    public function isFinalStatus(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_REJECTED,
        ], true);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'nimbus_portal_user_id');
    }

    public function statusUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'status_updated_by');
    }

    public function shareholders(): HasMany
    {
        return $this->hasMany(SubmissionShareholder::class, 'nimbus_submission_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'nimbus_submission_id');
    }

    public function userUploadedFiles(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'nimbus_submission_id')
            ->where('origin', 'USER')
            ->orderBy('uploaded_at');
    }

    public function responseFiles(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'nimbus_submission_id')
            ->where('origin', 'ADMIN')
            ->orderByDesc('uploaded_at');
    }

    public function portalVisibleResponseFiles(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'nimbus_submission_id')
            ->where('origin', 'ADMIN')
            ->where('visible_to_user', true)
            ->orderByDesc('uploaded_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SubmissionNote::class, 'nimbus_submission_id');
    }

    public function portalVisibleNotes(): HasMany
    {
        return $this->hasMany(SubmissionNote::class, 'nimbus_submission_id')
            ->where('visibility', 'USER_VISIBLE')
            ->with('user')
            ->latest('created_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'nimbus_submission_tags', 'nimbus_submission_id', 'nimbus_tag_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return static::statusLabelFor($this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return static::statusColorFor($this->status);
    }

    public function getStatusIconAttribute(): string
    {
        return static::statusIconFor($this->status);
    }
}
