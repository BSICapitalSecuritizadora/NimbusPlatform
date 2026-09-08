<?php

namespace App\Models;

use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use Database\Factories\SalesBoardBuilderReviewSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A resposta da construtora sobre uma das sete seções.
 *
 * Congela junto com a revisão: depois do envio, nem o status nem o comentário
 * mudam. O que a construtora respondeu naquele momento é o que a Gestão vai
 * analisar, e um estado que ainda pudesse mudar tornaria a análise sobre areia.
 */
class SalesBoardBuilderReviewSection extends Model
{
    /** @use HasFactory<SalesBoardBuilderReviewSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_board_builder_review_id',
        'section',
        'status',
        'comment',
        'confirmed_at',
    ];

    /**
     * A seção herda a mutabilidade da revisão a que pertence. Consultar o pai é
     * uma leitura a mais por gravação -- e são sete linhas por revisão, não
     * setecentas.
     */
    protected static function booted(): void
    {
        static::updating(function (self $section): void {
            if (! $section->review?->isEditable()) {
                throw new LogicException('A submitted builder review section is immutable.');
            }
        });

        static::deleting(function (self $section): void {
            throw new LogicException('Builder review sections cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'section' => SectionEnum::class,
            'status' => SalesBoardBuilderReviewSectionStatus::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(SalesBoardBuilderReview::class, 'sales_board_builder_review_id');
    }

    public function divergences(): HasMany
    {
        return $this->hasMany(SalesBoardBuilderDivergence::class, 'sales_board_builder_review_section_id');
    }

    public function isPending(): bool
    {
        return $this->status === SalesBoardBuilderReviewSectionStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === SalesBoardBuilderReviewSectionStatus::Confirmed;
    }

    public function isDivergent(): bool
    {
        return $this->status === SalesBoardBuilderReviewSectionStatus::Divergent;
    }
}
