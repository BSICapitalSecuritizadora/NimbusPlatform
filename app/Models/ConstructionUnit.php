<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Database\Factories\ConstructionUnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A single unit of a construction, identified by block and unit.
 *
 * Each unit is a record of its own so later features can relate to it by id.
 * The concatenated form ("Bloco 01 - Unidade 305") is for display only and must
 * never be used as the link between records.
 */
class ConstructionUnit extends Model
{
    /** @use HasFactory<ConstructionUnitFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'construction_id',
        'block',
        'unit',
        'base_value',
        'base_value_reference_date',
    ];

    protected function casts(): array
    {
        return [
            'base_value' => 'decimal:2',
            'base_value_reference_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    /**
     * Commercial history of the unit: every sale it ever had, the distratadas
     * included, newest first.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->orderByDesc('sale_date')->orderByDesc('id');
    }

    /**
     * The contract currently holding the unit. At most one can exist -- both the
     * application and a unique index on the contracts table see to that.
     */
    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->whereIn('status', ContractStatus::occupyingValues());
    }

    /**
     * Append-only history of the unit's commercial value, newest first.
     *
     * The base value is not repeated here: it is the unit's own starting
     * reference, and turning it into a synthetic history row would make an
     * informed price indistinguishable from a recorded update.
     */
    public function valueHistories(): HasMany
    {
        return $this->hasMany(ConstructionUnitValue::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    public function hasBaseValue(): bool
    {
        return ($this->base_value !== null) && ($this->base_value_reference_date !== null);
    }

    /**
     * Block identifications such as "01" or "Torre A" are kept verbatim: only
     * surrounding whitespace is removed, never leading zeros.
     */
    protected function block(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::normalizeIdentifier($value),
        );
    }

    protected function unit(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::normalizeIdentifier($value),
        );
    }

    /**
     * Human readable identification. Display only.
     */
    public function getDisplayNameAttribute(): string
    {
        return trim(sprintf('Bloco %s - Unidade %s', $this->block, $this->unit));
    }

    /**
     * @param  Builder<ConstructionUnit>  $query
     */
    public function scopeForEmission(Builder $query, mixed $emissionId): void
    {
        $query->whereHas(
            'construction',
            fn (Builder $constructionQuery): Builder => $constructionQuery->where('emission_id', $emissionId),
        );
    }

    public static function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Whether the block/unit pair is already taken inside the construction.
     */
    public static function isDuplicate(mixed $constructionId, ?string $block, ?string $unit, mixed $ignoreId = null): bool
    {
        if (blank($constructionId) || blank($block) || blank($unit)) {
            return false;
        }

        return self::query()
            ->where('construction_id', $constructionId)
            ->where('block', self::normalizeIdentifier($block))
            ->where('unit', self::normalizeIdentifier($unit))
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
