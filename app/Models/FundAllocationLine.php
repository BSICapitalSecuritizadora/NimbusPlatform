<?php

namespace App\Models;

use Database\Factories\FundAllocationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Fund's allocated amount inside a revision.
 *
 * The emission is frozen on the line at creation: historical reports
 * reconstruct from (competence, effective revision) and never depend on the
 * Fund's current associations. Lines of approved revisions are never edited
 * — corrections create a new revision.
 */
class FundAllocationLine extends Model
{
    /** @use HasFactory<FundAllocationLineFactory> */
    use HasFactory;

    protected $fillable = [
        'fund_allocation_revision_id',
        'fund_id',
        'emission_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(FundAllocationRevision::class, 'fund_allocation_revision_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}
