<?php

namespace App\Models;

use Database\Factories\FundAllocationPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Allocation-period aggregate: one row per physical BankApplication and
 * reporting competence.
 *
 * The period carries no money itself. It anchors the revision chain, holds
 * the single effective (approved) revision and the single open (draft or
 * pending-review) revision as structural one-valued columns, and is the
 * row every approval locks (`lockForUpdate`) so the whole distribution is
 * approved atomically.
 */
class FundAllocationPeriod extends Model
{
    /** @use HasFactory<FundAllocationPeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'bank_application_id',
        'competence',
        'effective_revision_id',
        'open_revision_id',
    ];

    protected function casts(): array
    {
        return [
            'competence' => 'date',
        ];
    }

    public function bankApplication(): BelongsTo
    {
        return $this->belongsTo(BankApplication::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FundAllocationRevision::class)->orderBy('revision_number');
    }

    public function effectiveRevision(): BelongsTo
    {
        return $this->belongsTo(FundAllocationRevision::class, 'effective_revision_id');
    }

    public function openRevision(): BelongsTo
    {
        return $this->belongsTo(FundAllocationRevision::class, 'open_revision_id');
    }

    public function hasEffectiveRevision(): bool
    {
        return $this->effective_revision_id !== null;
    }

    public function hasOpenRevision(): bool
    {
        return $this->open_revision_id !== null;
    }
}
