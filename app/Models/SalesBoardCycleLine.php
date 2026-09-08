<?php

namespace App\Models;

use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\ContractSettlementState;
use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardUnitClassification;
use Database\Factories\SalesBoardCycleLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Uma unidade como ela era na data da posição.
 *
 * Totalmente imutável: nem edição nem exclusão. Corrigir uma linha é recalcular
 * o ciclo, o que cria uma versão nova e deixa esta intacta ao lado.
 *
 * As FKs apontam para a fonte viva e os campos copiados dizem o que o snapshot
 * viu. Quando os dois discordam, não há erro: há mudança, e é justamente isso
 * que a detecção de obsolescência existe para apontar.
 */
class SalesBoardCycleLine extends Model
{
    /** @use HasFactory<SalesBoardCycleLineFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_board_cycle_baseline_id',
        'construction_unit_id',
        'block',
        'unit',
        'classification',
        'contract_id',
        'contract_code',
        'contract_sale_date',
        'contract_sale_value',
        'unit_reference_value',
        'unit_reference_value_source',
        'unit_reference_value_effective_from',
        'settlement_state',
        'settlement_installments_total',
        'settlement_installments_paid',
        'construction_unit_exchange_id',
        'exchange_value',
        'exchange_effective_from',
        'exchange_ended_on',
        'exchange_kind',
        'source_fingerprint',
        'snapshot_fingerprint',
    ];

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('Sales board cycle lines are immutable.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return [
            'classification' => SalesBoardUnitClassification::class,
            'contract_sale_date' => 'immutable_date',
            'contract_sale_value' => 'decimal:2',
            'unit_reference_value' => 'decimal:2',
            'unit_reference_value_source' => ResolvedUnitValueSource::class,
            'unit_reference_value_effective_from' => 'immutable_date',
            'settlement_state' => ContractSettlementState::class,
            'settlement_installments_total' => 'integer',
            'settlement_installments_paid' => 'integer',
            'exchange_value' => 'decimal:2',
            'exchange_effective_from' => 'immutable_date',
            'exchange_ended_on' => 'immutable_date',
            'exchange_kind' => ConstructionUnitExchangeKind::class,
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

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(ConstructionUnitExchange::class, 'construction_unit_exchange_id');
    }

    public function displayName(): string
    {
        return trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');
    }

    /**
     * Quanto esta linha contribuiu para o valor do seu balde.
     *
     * A regra é a mesma da derivação, e não pode ser outra: cada balde tem a sua
     * fonte, e elas não se misturam.
     */
    public function bucketValue(): ?string
    {
        return match ($this->classification) {
            SalesBoardUnitClassification::Stock => $this->unit_reference_value,
            SalesBoardUnitClassification::Financed,
            SalesBoardUnitClassification::Settled => $this->contract_sale_value,
            SalesBoardUnitClassification::Exchanged => $this->exchange_value,
            SalesBoardUnitClassification::Undetermined, null => null,
        };
    }
}
