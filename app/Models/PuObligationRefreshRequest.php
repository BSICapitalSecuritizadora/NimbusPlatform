<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use Database\Factories\PuObligationRefreshRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido durável de recompor as obrigações financeiras de uma emissão (Fase 6).
 *
 * Nasce na transação do fato que o provoca e só diz "esta emissão precisa ser
 * recomposta": quem executa relê o estado governado vigente
 * ({@see PuObligationRefreshRecovery}).
 */
class PuObligationRefreshRequest extends Model
{
    /** @use HasFactory<PuObligationRefreshRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'trigger',
        'status',
        'correlation_id',
        'requested_by',
        'requested_at',
        'attempts',
        'next_attempt_at',
        'claim_token',
        'claimed_via',
        'claimed_at',
        'claim_expires_at',
        'last_attempt_at',
        'last_error_category',
        'last_error_class',
        'last_error_message',
        'completed_at',
        'satisfied_by_request_id',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'status' => PuObligationRefreshStatus::class,
            'last_error_category' => PuOperationalFailureCategory::class,
            'requested_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'result' => 'array',
        ];
    }

    /**
     * @param  Builder<PuObligationRefreshRequest>  $query
     * @return Builder<PuObligationRefreshRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', PuObligationRefreshStatus::openValues());
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
