<?php

namespace App\Models;

use Database\Factories\SalesDiscountPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desconto comercial máximo que a BSI autorizava para um empreendimento a
 * partir de uma data.
 *
 * Append-only, como o histórico de valores das unidades: a pergunta que estas
 * linhas respondem é "o que estava autorizado quando aquela venda aconteceu", e
 * editar a linha apagaria a resposta.
 *
 * O percentual é limite máximo, não desconto concedido: uma venda pode ter
 * desconto menor, nunca maior.
 */
class SalesDiscountPolicy extends Model
{
    /** @use HasFactory<SalesDiscountPolicyFactory> */
    use HasFactory;

    public const MINIMUM_DISCOUNT_PERCENT = 0;

    public const MAXIMUM_DISCOUNT_PERCENT = 100;

    protected $fillable = [
        'construction_id',
        'maximum_discount_percent',
        'effective_from',
        'reason',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'maximum_discount_percent' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getFormattedMaximumDiscountPercentAttribute(): string
    {
        return number_format((float) $this->maximum_discount_percent, 2, ',', '.').'%';
    }
}
