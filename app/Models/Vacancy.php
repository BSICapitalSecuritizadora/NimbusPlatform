<?php

namespace App\Models;

use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyStatus;
use App\Enums\VacancyWorkModel;
use App\Services\Recruitment\VacancySlugService;
use App\Services\Security\RichTextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Vacancy extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'title',
        'slug',
        'status',
        'department',
        'location',
        'type',
        'work_model',
        'description',
        'requirements',
        'benefits',
        'positions',
        'hiring_manager_id',
        'published_at',
        'expires_at',
        'closed_at',
        'salary_min',
        'salary_max',
        'salary_visible',
        'internal_notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'status' => VacancyStatus::class,
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'positions' => 'integer',
            'salary_min' => 'integer',
            'salary_max' => 'integer',
            'salary_visible' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $vacancy): void {
            if (! $vacancy->slug) {
                $vacancy->slug = VacancySlugService::generate($vacancy->title);
            } elseif (self::where('slug', $vacancy->slug)->exists()) {
                $vacancy->slug = VacancySlugService::generate($vacancy->title);
            }

            if (! $vacancy->status) {
                $vacancy->status = VacancyStatus::Draft;
            }

            if ($vacancy->status === VacancyStatus::Published && ! $vacancy->published_at) {
                $vacancy->published_at = now();
            }

            // Sync legacy column (raw to avoid accessor mapping Draft->Paused)
            $status = $vacancy->status instanceof VacancyStatus ? $vacancy->status : VacancyStatus::tryFrom((string) $vacancy->status);
            $vacancy->attributes['is_active'] = $status?->isVisibleForSite() ? 1 : 0;
        });

        static::updating(function (self $vacancy): void {
            if ($vacancy->isDirty('title') && $vacancy->isDirty('slug')) {
                // Slug was manually changed alongside title — ensure uniqueness
                if (self::where('slug', $vacancy->slug)->where('id', '!=', $vacancy->id)->exists()) {
                    $vacancy->slug = VacancySlugService::generate($vacancy->title, $vacancy->id);
                }
            }
        });

        static::saving(function (self $vacancy): void {
            if ($vacancy->isDirty('status')) {
                $status = $vacancy->status instanceof VacancyStatus ? $vacancy->status : VacancyStatus::tryFrom((string) $vacancy->status);
                // Directly set raw attribute to avoid accessor recursion
                $vacancy->attributes['is_active'] = $status?->isVisibleForSite() ? 1 : 0;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status', 'department', 'type', 'work_model', 'location', 'positions', 'hiring_manager_id', 'published_at', 'expires_at', 'closed_at', 'salary_min', 'salary_max', 'salary_visible'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Backward-compatible accessor for legacy `is_active` column.
     * `is_active = true`  <=> status published
     * `is_active = false` <=> status not visible (paused/draft/closed/archived)
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: function (): bool {
                $status = $this->status instanceof VacancyStatus ? $this->status : VacancyStatus::tryFrom((string) $this->attributes['status'] ?? '');

                return $status?->isVisibleForSite() ?? (bool) ($this->attributes['is_active'] ?? false);
            },
            set: function (bool $value): array {
                if ($value) {
                    return [
                        'status' => VacancyStatus::Published->value,
                        'is_active' => true,
                    ];
                }

                // Preserve draft/closed/archived when they are already set; only published → paused transition is implied.
                $currentRaw = $this->attributes['status'] ?? null;
                $current = $currentRaw instanceof VacancyStatus ? $currentRaw : VacancyStatus::tryFrom((string) $currentRaw);

                if ($current && $current !== VacancyStatus::Published) {
                    return [
                        'is_active' => false,
                    ];
                }

                return [
                    'status' => VacancyStatus::Paused->value,
                    'is_active' => false,
                ];
            }
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::sanitizeHtml($value),
        );
    }

    protected function requirements(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::sanitizeHtml($value),
        );
    }

    protected function benefits(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::sanitizeHtml($value),
        );
    }

    protected function internalNotes(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::sanitizeHtml($value),
        );
    }

    // Scopes

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', VacancyStatus::Published->value);
    }

    public function scopeVisibleForSite(Builder $query): Builder
    {
        return $query
            ->where('status', VacancyStatus::Published->value)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', VacancyStatus::Draft->value);
    }

    public function scopePaused(Builder $query): Builder
    {
        return $query->where('status', VacancyStatus::Paused->value);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', VacancyStatus::Closed->value);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', VacancyStatus::Archived->value);
    }

    // Relations

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_id');
    }

    // Helpers

    public function isVisibleForSite(): bool
    {
        $status = $this->status instanceof VacancyStatus ? $this->status : VacancyStatus::tryFrom((string) $this->status);

        if (! $status?->isVisibleForSite()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function salaryRangeLabel(): ?string
    {
        if (! $this->salary_visible) {
            return null;
        }

        if ($this->salary_min && $this->salary_max) {
            if ($this->salary_min === $this->salary_max) {
                return 'R$ '.number_format($this->salary_min, 0, ',', '.');
            }

            return 'R$ '.number_format($this->salary_min, 0, ',', '.').' — R$ '.number_format($this->salary_max, 0, ',', '.');
        }

        if ($this->salary_min) {
            return 'A partir de R$ '.number_format($this->salary_min, 0, ',', '.');
        }

        if ($this->salary_max) {
            return 'Até R$ '.number_format($this->salary_max, 0, ',', '.');
        }

        return null;
    }

    /**
     * Transient per-instance memoization for pipeline counts.
     * Not persisted, not serialized, not dirty.
     *
     * @var array<string,int>|null
     */
    private ?array $memoizedApplicationCounts = null;

    /**
     * Efficiently load pipeline counts keyed by status value.
     * Result is memoized per-instance to avoid repeated grouped queries in infolists.
     *
     * @return array<string,int>
     */
    public function applicationCountsByStatus(): array
    {
        if ($this->memoizedApplicationCounts !== null) {
            return $this->memoizedApplicationCounts;
        }

        $counts = $this->applications()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        foreach (JobApplication::statusOptions() as $value => $label) {
            $counts[$value] ??= 0;
        }

        return $this->memoizedApplicationCounts = $counts;
    }

    public function departmentEnum(): ?VacancyDepartment
    {
        return VacancyDepartment::tryFrom((string) $this->department)
            ?? VacancyDepartment::normalize($this->department);
    }

    public function employmentTypeEnum(): ?VacancyEmploymentType
    {
        return VacancyEmploymentType::tryFrom((string) $this->type)
            ?? VacancyEmploymentType::normalize($this->type);
    }

    public function workModelEnum(): ?VacancyWorkModel
    {
        return $this->work_model ? VacancyWorkModel::tryFrom((string) $this->work_model) : null;
    }

    private static function sanitizeHtml(?string $html): ?string
    {
        return RichTextSanitizer::sanitize($html);
    }
}
