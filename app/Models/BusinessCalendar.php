<?php

namespace App\Models;

use Database\Factories\BusinessCalendarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCalendar extends Model
{
    /** @use HasFactory<BusinessCalendarFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'purpose',
        'calendar_type',
        'source',
        'status',
        'import_mode',
        'is_official',
        'financial_use_allowed',
        'is_legacy',
        'is_homologation',
        'accepts_anbima',
        'available_for_new_configurations',
    ];

    protected function casts(): array
    {
        return [
            'is_official' => 'boolean',
            'financial_use_allowed' => 'boolean',
            'is_legacy' => 'boolean',
            'is_homologation' => 'boolean',
            'accepts_anbima' => 'boolean',
            'available_for_new_configurations' => 'boolean',
        ];
    }

    public function years(): HasMany
    {
        return $this->hasMany(BusinessCalendarYear::class, 'calendar_code', 'code');
    }

    public function selectionEvidence(): HasMany
    {
        return $this->hasMany(BusinessCalendarSelectionEvidence::class, 'calendar_code', 'code');
    }

    public function stagingBatches(): HasMany
    {
        return $this->hasMany(BusinessCalendarStagingBatch::class, 'calendar_code', 'code');
    }

    public function selectionLabel(): string
    {
        return sprintf('%s — %s', $this->code, $this->name);
    }
}
