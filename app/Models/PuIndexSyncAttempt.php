<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma tentativa de sincronização de índice publicado (Fase 6), com o resultado
 * classificado e o erro higienizado. O dado do índice continua em
 * `index_rates` e o histórico de correções no livro próprio; aqui fica só se a
 * fonte foi consultada, quando, e o que respondeu.
 */
class PuIndexSyncAttempt extends Model
{
    protected $fillable = [
        'indexer',
        'source',
        'requested_from',
        'requested_to',
        'started_at',
        'finished_at',
        'outcome',
        'failure_category',
        'fetched',
        'created',
        'updated',
        'skipped',
        'conflicts',
        'invalid_entries',
        'blocks_total',
        'blocks_failed',
        'latest_observation_date',
        'error_message',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => PuIndexSyncOutcome::class,
            'failure_category' => PuOperationalFailureCategory::class,
            'requested_from' => 'date',
            'requested_to' => 'date',
            'latest_observation_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<PuIndexSyncAttempt>  $query
     * @return Builder<PuIndexSyncAttempt>
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereNotNull('finished_at');
    }
}
