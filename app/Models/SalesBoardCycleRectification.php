<?php

namespace App\Models;

use App\Enums\SalesBoardRectificationStatus;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use Database\Factories\SalesBoardCycleRectificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * O pedido de retificação de uma competência já publicada.
 *
 * Nasce aberto, com a publicação que ele pretende substituir e a versão que a
 * abertura gravou, e termina publicado -- a aprovação da Gestão publicou a
 * posição retificada -- ou desistido, com a posição publicada de antes valendo
 * de novo. Quem pediu e por quê, quem fechou, quando e por quê ficam na linha.
 *
 * Registro de governança: a identidade não muda depois de criada, e só o
 * desfecho é gravado, uma vez, a partir de "aberta". Não se apaga -- a
 * retificação desistida continua sendo a prova de que alguém pediu para mudar
 * uma posição publicada e de que ela não mudou. A abertura e o desfecho passam
 * por {@see SalesBoardCycleRectificationService} e pela aprovação.
 */
class SalesBoardCycleRectification extends Model
{
    /** @use HasFactory<SalesBoardCycleRectificationFactory> */
    use HasFactory, LogsActivity;

    /**
     * O que muda depois da abertura: o desfecho, uma única vez.
     *
     * @var list<string>
     */
    public const CLOSING_FIELDS = [
        'status',
        'closed_at',
        'closed_by_user_id',
        'closing_reason',
        'updated_at',
    ];

    protected $fillable = [
        'sales_board_cycle_id',
        'sequence_number',
        'status',
        'rectified_publication_id',
        'opening_baseline_id',
        'reason',
        'requested_by_user_id',
        'requested_at',
        'closed_at',
        'closed_by_user_id',
        'closing_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $rectification): void {
            if (array_diff(array_keys($rectification->getDirty()), self::CLOSING_FIELDS) !== []) {
                throw new LogicException('A sales board cycle rectification identity is immutable.');
            }

            $original = SalesBoardRectificationStatus::tryFrom((string) $rectification->getRawOriginal('status'));

            if ($original !== SalesBoardRectificationStatus::Open) {
                throw new LogicException('A closed sales board cycle rectification cannot change.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Sales board cycle rectifications cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'status' => SalesBoardRectificationStatus::class,
            'requested_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Grava em `sales_board`, a categoria protegida do módulo: o pedido de
     * mudar uma posição publicada é evidência de governança, e no balde
     * `default` seria descartado em um ano.
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

    /**
     * A publicação que estava vigente quando a retificação foi aberta -- a que
     * ela substitui, se for publicada.
     */
    public function rectifiedPublication(): BelongsTo
    {
        return $this->belongsTo(SalesBoardPublication::class, 'rectified_publication_id');
    }

    /**
     * A versão gravada na abertura, com o motivo da retificação.
     */
    public function openingBaseline(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleBaseline::class, 'opening_baseline_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * A publicação que a aprovação da retificação criou, quando houve.
     */
    public function resultingPublication(): HasOne
    {
        return $this->hasOne(SalesBoardPublication::class, 'sales_board_cycle_rectification_id');
    }

    public function isOpen(): bool
    {
        return $this->status === SalesBoardRectificationStatus::Open;
    }
}
