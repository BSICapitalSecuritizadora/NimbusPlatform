<?php

namespace App\Models;

use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardUnitClassification;
use Database\Factories\SalesBoardBuilderDivergenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Uma discordância declarada pela construtora contra um fato congelado.
 *
 * Não corrige nada. A linha e o movimento apontados continuam exatamente como
 * foram apurados; o que existe aqui é a outra versão do fato, dita por quem
 * vendeu. A Gestão recebe as duas e decide na fase seguinte.
 *
 * Enquanto a revisão é rascunho, a divergência é editável e removível -- é um
 * formulário sendo preenchido. Depois do envio, congela com o resto.
 */
class SalesBoardBuilderDivergence extends Model
{
    /** @use HasFactory<SalesBoardBuilderDivergenceFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_board_builder_review_id',
        'sales_board_builder_review_section_id',
        'type',
        'sales_board_cycle_line_id',
        'sales_board_cycle_movement_id',
        'construction_unit_id',
        'contract_id',
        'declared_block',
        'declared_unit',
        'declared_contract_code',
        'declared_value',
        'declared_date',
        'declared_classification',
        'reason',
    ];

    protected static function booted(): void
    {
        $assertDraft = function (self $divergence): void {
            if (! $divergence->review?->isEditable()) {
                throw new LogicException('A submitted builder divergence is immutable.');
            }
        };

        static::updating($assertDraft);
        static::deleting($assertDraft);
    }

    protected function casts(): array
    {
        return [
            'type' => SalesBoardBuilderDivergenceType::class,
            'declared_value' => 'decimal:2',
            'declared_date' => 'immutable_date',
            'declared_classification' => SalesBoardUnitClassification::class,
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(SalesBoardBuilderReview::class, 'sales_board_builder_review_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SalesBoardBuilderReviewSection::class, 'sales_board_builder_review_section_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleLine::class, 'sales_board_cycle_line_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleMovement::class, 'sales_board_cycle_movement_id');
    }

    public function constructionUnit(): BelongsTo
    {
        return $this->belongsTo(ConstructionUnit::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * A unidade a que a declaração se refere, na melhor forma disponível.
     *
     * Uma venda ausente pode citar uma unidade que o Nimbus não conhece, e nesse
     * caso só existe o texto que a construtora escreveu -- que é exatamente o que
     * a Gestão precisa ver para investigar.
     */
    public function unitLabel(): string
    {
        $line = $this->line;
        $movement = $this->movement;

        $block = $this->declared_block ?? $line?->block ?? $movement?->block;
        $unit = $this->declared_unit ?? $line?->unit ?? $movement?->unit;

        $label = trim(sprintf('%s / %s', (string) $block, (string) $unit), ' /');

        return $label === '' ? '—' : $label;
    }

    public function contractLabel(): ?string
    {
        return $this->declared_contract_code
            ?? $this->movement?->contract_code
            ?? $this->line?->contract_code
            ?? $this->contract?->code;
    }
}
