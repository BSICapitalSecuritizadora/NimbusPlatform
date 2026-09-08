<?php

namespace App\Models;

use App\Enums\SalesBoardRolloutEventType;
use App\Enums\SalesBoardSource;
use Database\Factories\SalesBoardRolloutEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Uma mudança de modo de uma Emissão.
 *
 * Append-only. As colunas da Emissão guardam o presente; sem esta linha, um
 * retorno ao legado apagaria a evidência de que a automação existiu -- e a
 * pergunta "desde quando, e com base em qual homologação?" ficaria sem resposta.
 */
class SalesBoardRolloutEvent extends Model
{
    /** @use HasFactory<SalesBoardRolloutEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'emission_id',
        'event_type',
        'from_source',
        'to_source',
        'sales_board_rollout_homologation_id',
        'start_reference_month',
        'reason',
        'actor_user_id',
    ];

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('Sales board rollout events are append-only.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return [
            'event_type' => SalesBoardRolloutEventType::class,
            'from_source' => SalesBoardSource::class,
            'to_source' => SalesBoardSource::class,
            'start_reference_month' => 'immutable_date',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function homologation(): BelongsTo
    {
        return $this->belongsTo(
            SalesBoardRolloutHomologation::class,
            'sales_board_rollout_homologation_id',
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
