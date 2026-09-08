<?php

namespace App\Models;

use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesPriceConformityStatus;
use App\Support\Money\IntegerMoney;
use Database\Factories\SalesBoardCycleMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Uma venda, quitação ou distrato ocorrido na competência, congelado.
 *
 * Imutável como a linha. A conformidade da venda fica aqui, com a tabela e a
 * política que a produziram: quando alguém perguntar por que aquela venda foi
 * apontada, a resposta não pode depender de reler uma política que já pode ter
 * sido substituída.
 */
class SalesBoardCycleMovement extends Model
{
    /** @use HasFactory<SalesBoardCycleMovementFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_board_cycle_baseline_id',
        'movement_type',
        'construction_unit_id',
        'block',
        'unit',
        'contract_id',
        'contract_code',
        'event_date',
        'sale_date',
        'sale_value',
        'cancellation_date',
        'settlement_installments_total',
        'unit_reference_value',
        'unit_reference_value_source',
        'unit_reference_value_effective_from',
        'sales_discount_policy_id',
        'authorized_discount_basis_points',
        'minimum_authorized_value',
        'effective_discount_basis_points',
        'difference_value',
        'conformity_status',
        'conformity_reason',
        'source_fingerprint',
        'snapshot_fingerprint',
    ];

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('Sales board cycle movements are immutable.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return [
            'movement_type' => SalesBoardMovementType::class,
            'event_date' => 'immutable_date',
            'sale_date' => 'immutable_date',
            'sale_value' => 'decimal:2',
            'cancellation_date' => 'immutable_date',
            'settlement_installments_total' => 'integer',
            'unit_reference_value' => 'decimal:2',
            'unit_reference_value_source' => ResolvedUnitValueSource::class,
            'unit_reference_value_effective_from' => 'immutable_date',
            'authorized_discount_basis_points' => 'integer',
            'minimum_authorized_value' => 'decimal:2',
            'effective_discount_basis_points' => 'integer',
            'difference_value' => 'decimal:2',
            'conformity_status' => SalesPriceConformityStatus::class,
        ];
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleBaseline::class, 'sales_board_cycle_baseline_id');
    }

    public function constructionUnit(): BelongsTo
    {
        return $this->belongsTo(ConstructionUnit::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function salesDiscountPolicy(): BelongsTo
    {
        return $this->belongsTo(SalesDiscountPolicy::class, 'sales_discount_policy_id');
    }

    public function displayName(): string
    {
        return trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');
    }

    /**
     * Desconto autorizado e praticado como percentual legível.
     *
     * Apresentação: o veredito congelado é `conformity_status`, apurado em
     * centavos exatos na geração e nunca recalculado aqui.
     */
    public function authorizedDiscountLabel(): ?string
    {
        return $this->authorized_discount_basis_points === null
            ? null
            : IntegerMoney::formatBasisPoints($this->authorized_discount_basis_points).'%';
    }

    public function effectiveDiscountLabel(): ?string
    {
        $basisPoints = $this->effective_discount_basis_points;

        if ($basisPoints === null) {
            return null;
        }

        if ($basisPoints < 0) {
            return 'Ágio de '.IntegerMoney::formatBasisPoints(-$basisPoints).'%';
        }

        if ($basisPoints === 0) {
            return 'Sem desconto';
        }

        return 'Desconto de '.IntegerMoney::formatBasisPoints($basisPoints).'%';
    }
}
