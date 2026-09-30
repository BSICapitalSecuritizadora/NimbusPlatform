<?php

namespace App\Models;

use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use Database\Factories\SalesBoardManagementNonconformityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Uma pendência que a Gestão precisa decidir.
 *
 * Guarda a decisão e nada além dela. O fato está do outro lado da FK -- a
 * declaração da construtora ou o movimento congelado -- e é lá que ele deve
 * continuar: copiar valores para cá criaria duas versões do mesmo número, e a
 * segunda envelheceria sem que ninguém percebesse.
 *
 * A âncora é determinada pela origem, e o guard abaixo não é redundância da
 * unique: a unique impede duplicar; isto impede uma linha sem âncora nenhuma, ou
 * com as duas, que passaria despercebida até a tela tentar exibir um fato que
 * não existe.
 */
class SalesBoardManagementNonconformity extends Model
{
    /** @use HasFactory<SalesBoardManagementNonconformityFactory> */
    use HasFactory, LogsActivity;

    /**
     * O que muda depois de a pendência nascer: apenas a decisão.
     *
     * @var list<string>
     */
    public const DECISION_FIELDS = [
        'decision',
        'decision_reason',
        'decided_at',
        'decided_by_user_id',
        'updated_at',
    ];

    protected $fillable = [
        'sales_board_management_review_id',
        'origin',
        'sales_board_builder_divergence_id',
        'sales_board_cycle_movement_id',
        'decision',
        'decision_reason',
        'decided_at',
        'decided_by_user_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $origin = $item->origin instanceof SalesBoardNonconformityOrigin
                ? $item->origin
                : SalesBoardNonconformityOrigin::tryFrom((string) $item->origin);

            $hasDivergence = $item->sales_board_builder_divergence_id !== null;
            $hasMovement = $item->sales_board_cycle_movement_id !== null;

            /**
             * A âncora certa, e só ela. A regra vive na própria origem para que
             * a materialização, este guard e a invariante da aprovação não
             * possam divergir sobre o que cada origem aponta.
             */
            $valid = ($origin !== null) && ($origin->anchorsMovement()
                ? ($hasMovement && ! $hasDivergence)
                : ($hasDivergence && ! $hasMovement));

            if (! $valid) {
                throw new LogicException('A sales board nonconformity must anchor exactly the reference its origin requires.');
            }
        });

        static::creating(function (self $item): void {
            self::assertReviewIsDraft($item);
        });

        /**
         * Identidade e âncora são imutáveis; a decisão não. Enquanto a análise é
         * rascunho a Gestão pode mudar de ideia. Depois do encerramento --
         * aprovação, devolução ou substituição -- a decisão também congela.
         */
        static::updating(function (self $item): void {
            if (array_diff(array_keys($item->getDirty()), self::DECISION_FIELDS) !== []) {
                throw new LogicException('A sales board nonconformity identity is immutable.');
            }

            self::assertReviewIsDraft($item);
        });

        static::deleting(function (): never {
            throw new LogicException('Sales board nonconformities cannot be deleted.');
        });
    }

    /**
     * A pendência só é gravada enquanto a análise dela é rascunho.
     *
     * A análise é relida do banco, e não aceita como veio na relação carregada:
     * uma relação carregada antes da aprovação diria "rascunho" para sempre, e
     * uma decisão iniciada naquele instante atravessaria o portão já fechado.
     * A leitura é compartilhada para enxergar a versão commitada mais recente e
     * esperar quem estiver encerrando a análise naquele momento.
     */
    private static function assertReviewIsDraft(self $item): void
    {
        $status = SalesBoardManagementReview::query()
            ->whereKey($item->sales_board_management_review_id)
            ->sharedLock()
            ->toBase()
            ->value('status');

        if (SalesBoardManagementReviewStatus::tryFrom((string) $status) !== SalesBoardManagementReviewStatus::Draft) {
            throw new LogicException('A sales board nonconformity is frozen once its management review is finished.');
        }
    }

    protected function casts(): array
    {
        return [
            'origin' => SalesBoardNonconformityOrigin::class,
            'decision' => SalesBoardNonconformityDecision::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * Grava em `sales_board`, a categoria protegida do módulo.
     *
     * "Desfazer decisão" limpa motivo, autor e data da própria linha: enquanto
     * a análise é rascunho, a linha guarda só a decisão vigente. A decisão
     * desfeita sobrevive apenas no `attribute_changes.old` desta trilha, que
     * por isso não pode cair no balde descartado em um ano.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_board')
            ->logOnly(['decision', 'decision_reason', 'decided_at', 'decided_by_user_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(SalesBoardManagementReview::class, 'sales_board_management_review_id');
    }

    public function builderDivergence(): BelongsTo
    {
        return $this->belongsTo(SalesBoardBuilderDivergence::class, 'sales_board_builder_divergence_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleMovement::class, 'sales_board_cycle_movement_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->decision->isPending();
    }

    public function blocksApproval(): bool
    {
        return $this->decision->blocksApproval();
    }

    /**
     * As conclusões que esta pendência admite, pela origem dela.
     *
     * @return list<SalesBoardNonconformityDecision>
     */
    public function allowedDecisions(): array
    {
        return SalesBoardNonconformityDecision::allowedFor($this->origin);
    }
}
