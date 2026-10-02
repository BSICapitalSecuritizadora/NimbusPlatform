<?php

namespace App\Models;

use Database\Factories\SalesBoardPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * O elo entre a governança e o quadro publicado.
 *
 * Totalmente imutável: nem edição nem exclusão. Uma publicação é a afirmação de
 * que uma posição específica atravessou todas as etapas, e uma afirmação que
 * pode ser reescrita depois não prova nada.
 *
 * A retificação de uma competência publicada não reescreve a publicação: a
 * aprovação dela atualiza o mesmo quadro (o histórico de versões do quadro
 * grava a mudança) e cria uma publicação nova, com a sequência seguinte, que
 * aponta a que ela substitui e a retificação que a produziu. A cadeia é linear
 * no mesmo ciclo e no mesmo quadro -- o guard de criação abaixo recusa
 * qualquer outra forma, e as uniques `(ciclo, sequência)` e `supersedes`
 * fecham a corrida no banco. A vigente é a de maior sequência.
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
        'sequence_number',
        'supersedes_publication_id',
        'sales_board_cycle_rectification_id',
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

        static::creating(function (self $publication): void {
            self::assertLinearChain($publication);
        });
    }

    /**
     * A publicação nova continua a cadeia do próprio ciclo e do próprio quadro.
     *
     * A primeira (sequência 1) não substitui nada. As seguintes substituem a
     * vigente do mesmo ciclo -- a de sequência imediatamente anterior -- e
     * escrevem o mesmo quadro: uma retificação nunca cria um segundo quadro da
     * competência nem pula uma publicação. A leitura da anterior é
     * compartilhada, dentro da transação de quem publica.
     */
    private static function assertLinearChain(self $publication): void
    {
        $sequence = (int) ($publication->sequence_number ?? 1);
        $previousId = $publication->supersedes_publication_id;

        if ($previousId === null) {
            if ($sequence !== 1) {
                throw new LogicException('Only the first publication of a sales board cycle supersedes nothing.');
            }

            return;
        }

        $query = self::query()->whereKey($previousId);

        if ($query->getConnection()->transactionLevel() > 0) {
            $query->sharedLock();
        }

        $previous = $query->first(['id', 'sales_board_cycle_id', 'sales_board_id', 'sequence_number']);

        $linear = ($previous instanceof self)
            && ((int) $previous->sales_board_cycle_id === (int) $publication->sales_board_cycle_id)
            && ((int) $previous->sales_board_id === (int) $publication->sales_board_id)
            && ($sequence === ((int) $previous->sequence_number + 1));

        if (! $linear) {
            throw new LogicException('A sales board publication must supersede the previous publication of the same cycle and board.');
        }
    }

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'source_changed' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * Grava em `sales_board`, a categoria protegida do módulo.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_board')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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
     * A publicação que esta substituiu, quando ela veio de uma retificação.
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_publication_id');
    }

    /**
     * A publicação que substituiu esta, quando houve retificação depois dela.
     */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_publication_id');
    }

    public function rectification(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleRectification::class, 'sales_board_cycle_rectification_id');
    }

    /**
     * A publicação veio da aprovação de uma retificação.
     */
    public function isRectification(): bool
    {
        return $this->sales_board_cycle_rectification_id !== null;
    }

    public function sequenceLabel(): string
    {
        return (int) ($this->sequence_number ?? 1) === 1
            ? 'Publicação original'
            : sprintf('Retificação %d', ((int) $this->sequence_number) - 1);
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
