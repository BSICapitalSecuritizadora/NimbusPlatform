<?php

namespace App\Models;

use App\Domain\PuCalculator\Services\PuGovernedHistoryGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Âncora do histórico financeiro governado do PU de uma emissão (Fase 7).
 *
 * Uma linha por emissão, gravada na mesma transação do primeiro fato governado
 * (a homologação que torna uma curva oficial) e nunca mais alterada nem apagada.
 * A chave estrangeira para `emissions` é RESTRICT: com a âncora, nenhum caminho
 * -- `delete()` do model, exclusão em massa pelo query builder ou `DELETE` direto
 * no banco -- apaga a emissão, e por isso nenhuma cascata leva as versões, as
 * linhas oficiais, os retratos, os eventos, as obrigações, os cálculos, a
 * conciliação ou as liquidações dela. Ver {@see PuGovernedHistoryGuard}.
 */
class EmissionPuHistoryAnchor extends Model
{
    public const REASON_CURVE_HOMOLOGATED = 'curve_homologated';

    public const REASON_PREEXISTING_GOVERNED_HISTORY = 'preexisting_governed_history';

    protected $fillable = [
        'emission_id',
        'reason',
        'curve_version_id',
        'anchored_by',
        'anchored_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('The governed PU history anchor of an emission is immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('The governed PU history anchor of an emission is never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'anchored_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function curveVersion(): BelongsTo
    {
        return $this->belongsTo(EmissionPuCurveVersion::class, 'curve_version_id');
    }
}
