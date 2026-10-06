<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Uma correção de observação de índice já registrada. Livro só de inclusão: a
 * correção seguinte é outra linha, e nenhuma é editada ou apagada.
 */
class IndexRateCorrection extends Model
{
    public const ORIGIN_MANUAL_CORRECTION = 'manual_correction';

    public const ORIGIN_PROVIDER_REVISION = 'provider_revision';

    protected $fillable = [
        'index_rate_id',
        'indexer',
        'rate_date',
        'origin',
        'previous_rate_value',
        'new_rate_value',
        'previous_source',
        'new_source',
        'previous_source_reference',
        'new_source_reference',
        'reason',
        'corrected_by',
        'affected_curve_versions',
        'corrected_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Index rate corrections are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Index rate corrections are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'previous_rate_value' => 'decimal:8',
            'new_rate_value' => 'decimal:8',
            'affected_curve_versions' => 'array',
            'corrected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IndexRate, $this>
     */
    public function indexRate(): BelongsTo
    {
        return $this->belongsTo(IndexRate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
