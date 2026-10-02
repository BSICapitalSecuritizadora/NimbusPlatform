<?php

namespace App\Models;

use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesPriceConformityStatus;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
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
 *
 * O `timing` separa o movimento do mês (nulo) do fato que a competência recebeu
 * de antes dela: extemporâneo, revisão de venda publicada ou de competência sem
 * posição ({@see SalesBoardMovementTiming}).
 */
class SalesBoardCycleMovement extends Model
{
    /** @use HasFactory<SalesBoardCycleMovementFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_board_cycle_baseline_id',
        'movement_type',
        'timing',
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
            'timing' => SalesBoardMovementTiming::class,
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
     * O fato é de uma competência anterior a esta: extemporâneo, revisão de
     * venda publicada ou de competência sem posição.
     */
    public function isFromEarlierCompetence(): bool
    {
        return $this->timing !== null;
    }

    /**
     * O selo do movimento de competência anterior, com a data e a competência
     * do fato -- `null` para o movimento do mês.
     *
     * O contexto vem de quem mostra: a competência anterior (a âncora da
     * apuração) e se ela já estava publicada, e, na revisão, a venda como a
     * anterior a congelou. A quitação extemporânea não tem data: o motor prova
     * que ela já valia no fechamento da competência anterior, não o dia em que
     * aconteceu.
     */
    public function timingLabel(
        ?CarbonImmutable $previousMonth = null,
        bool $previousPublished = true,
        ?int $previousSaleValueCents = null,
        ?CarbonImmutable $previousSaleDate = null,
    ): ?string {
        $timing = $this->timing;

        if ($timing === null) {
            return null;
        }

        $factDate = $this->event_date;

        return match ($timing) {
            SalesBoardMovementTiming::Extemporaneous => $this->movement_type === SalesBoardMovementType::Settlement
                ? sprintf(
                    'Extemporânea · já vigente no fechamento de %s (competência anterior)',
                    $previousMonth?->format('m/Y') ?? 'competência anterior',
                )
                : sprintf(
                    '%s · %s de %s (%s já %s)',
                    $this->movement_type === SalesBoardMovementType::Sale ? 'Extemporânea' : 'Extemporâneo',
                    mb_strtolower($this->movement_type->label()),
                    $factDate?->format('d/m/Y') ?? '—',
                    $factDate?->format('m/Y') ?? '—',
                    $previousPublished ? 'publicada' : 'apurada',
                ),
            SalesBoardMovementTiming::SaleRevision => 'Revisão de venda publicada'.$this->revisionDetail($previousSaleValueCents, $previousSaleDate),
            SalesBoardMovementTiming::WithoutPosition => $factDate === null
                ? 'De competência sem posição'
                : sprintf('De competência sem posição (%s)', $factDate->format('m/Y')),
        };
    }

    /**
     * O que mudou na venda revisada, entre parênteses: o valor, a data ou os
     * dois, como a competência anterior os congelou e como estão agora.
     */
    private function revisionDetail(?int $previousSaleValueCents, ?CarbonImmutable $previousSaleDate): string
    {
        $changes = [];
        $currentValue = IntegerMoney::cents($this->sale_value);

        if (($previousSaleValueCents !== null) && ($currentValue !== null) && ($previousSaleValueCents !== $currentValue)) {
            $changes[] = sprintf('R$ %s → R$ %s', IntegerMoney::format($previousSaleValueCents), IntegerMoney::format($currentValue));
        }

        $currentDate = $this->sale_date?->toDateString();

        if (($previousSaleDate !== null) && ($currentDate !== null) && ($previousSaleDate->toDateString() !== $currentDate)) {
            $changes[] = sprintf('venda de %s → %s', $previousSaleDate->format('d/m/Y'), $this->sale_date->format('d/m/Y'));
        }

        return $changes === [] ? '' : ' ('.implode('; ', $changes).')';
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
