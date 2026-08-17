<?php

namespace App\Models;

use App\Enums\GuaranteeValuationBasis;
use Carbon\CarbonInterface;
use Database\Factories\GuaranteeValuationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avaliação de uma garantia numa data-base (§20 e §21 do escopo).
 *
 * Existe porque o valor atual de um imóvel ou de uma participação societária
 * não é derivável dos dados operacionais do Nimbus: alguém precisa informá-lo,
 * e o módulo precisa saber de quando é e em que critério se apoia.
 */
class GuaranteeValuation extends Model
{
    /** @use HasFactory<GuaranteeValuationFactory> */
    use HasFactory;

    protected $fillable = [
        'guarantee_id',
        'document_id',
        'valuation_date',
        'value',
        'basis',
        'appraiser',
        'valid_until',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'basis' => GuaranteeValuationBasis::class,
            'valuation_date' => 'date',
            'valid_until' => 'date',
            'value' => 'decimal:2',
        ];
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * A avaliação já perdeu validade na data analisada?
     *
     * Sem `valid_until` a avaliação não vence sozinha — é o contrato que diz de
     * quanto em quanto tempo reavaliar, e essa regra vive na garantia.
     */
    public function isExpiredOn(?CarbonInterface $date = null): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return $this->valid_until->lt($date ?? now());
    }
}
