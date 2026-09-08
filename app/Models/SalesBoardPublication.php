<?php

namespace App\Models;

use Database\Factories\SalesBoardPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * O elo entre a governança e o quadro publicado.
 *
 * Totalmente imutável: nem edição nem exclusão. Uma publicação é a afirmação de
 * que uma posição específica atravessou todas as etapas, e uma afirmação que
 * pode ser reescrita depois não prova nada.
 *
 * Nesta fase não existe republicação. Corrigir uma posição já publicada é
 * decisão de rollout, e o caminho conhecido continua sendo o mesmo: corrigir a
 * fonte, recalcular, revalidar, reanalisar.
 */
class SalesBoardPublication extends Model
{
    /** @use HasFactory<SalesBoardPublicationFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'sales_board_cycle_id',
        'sales_board_cycle_baseline_id',
        'sales_board_builder_review_id',
        'sales_board_management_review_id',
        'sales_board_id',
        'snapshot_fingerprint',
        'source_fingerprint',
        'observed_source_fingerprint',
        'source_changed',
        'source_change_reason',
        'published_by_user_id',
        'published_at',
    ];

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('Sales board publications are immutable.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return [
            'source_changed' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycle::class, 'sales_board_cycle_id');
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleBaseline::class, 'sales_board_cycle_baseline_id');
    }

    public function builderReview(): BelongsTo
    {
        return $this->belongsTo(SalesBoardBuilderReview::class, 'sales_board_builder_review_id');
    }

    public function managementReview(): BelongsTo
    {
        return $this->belongsTo(SalesBoardManagementReview::class, 'sales_board_management_review_id');
    }

    public function salesBoard(): BelongsTo
    {
        return $this->belongsTo(SalesBoard::class, 'sales_board_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * A publicação aconteceu sabendo que a fonte havia mudado sem alterar o
     * resultado.
     */
    public function hadSourceOverride(): bool
    {
        return (bool) $this->source_changed;
    }
}
