<?php

namespace App\Models;

use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use Database\Factories\SalesBoardManagementReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A análise da Gestão sobre uma submissão da construtora.
 *
 * Decide sobre fatos congelados e não escreve em nenhum deles. Não existe aqui
 * -- nem deve existir -- caminho para corrigir contrato, unidade, valor ou
 * classificação: se o fato operacional está errado, o remédio é corrigir a fonte
 * e recalcular, o que produz uma versão nova e uma nova rodada. Editar o
 * snapshot faria a posição publicada deixar de ser explicável pela derivação que
 * a produziu.
 *
 * Enquanto é rascunho, a Gestão decide e muda de ideia. Depois de aprovada,
 * devolvida ou substituída, nada mais muda: as decisões viram registro.
 */
class SalesBoardManagementReview extends Model
{
    /** @use HasFactory<SalesBoardManagementReviewFactory> */
    use HasFactory, LogsActivity;

    /**
     * O que o rascunho ainda pode gravar.
     *
     * @var list<string>
     */
    public const DRAFT_MUTABLE_FIELDS = [
        'status',
        'overall_comment',
        'approved_at',
        'approved_by_user_id',
        'returned_at',
        'returned_by_user_id',
        'return_reason',
        'source_changed',
        'source_change_reason',
        'approved_source_fingerprint',
        'observed_source_fingerprint',
        'approval_declaration_version',
        'superseded_at',
        'superseded_reason',
        'updated_at',
    ];

    /**
     * O que uma análise encerrada ainda pode gravar: só a própria substituição.
     *
     * @var list<string>
     */
    public const SUPERSEDING_FIELDS = [
        'status',
        'superseded_at',
        'superseded_reason',
        'updated_at',
    ];

    protected $fillable = [
        'sales_board_cycle_id',
        'sales_board_cycle_baseline_id',
        'sales_board_builder_review_id',
        'attempt',
        'status',
        'snapshot_fingerprint',
        'opened_at',
        'approved_at',
        'returned_at',
        'superseded_at',
        'superseded_reason',
        'opened_by_user_id',
        'approved_by_user_id',
        'returned_by_user_id',
        'overall_comment',
        'return_reason',
        'source_changed',
        'source_change_reason',
        'approved_source_fingerprint',
        'observed_source_fingerprint',
        'approval_declaration_version',
    ];

    /**
     * Uma análise aprovada não volta a ser rascunho, e uma devolvida não vira
     * aprovada depois. A única mudança admitida depois do encerramento é a
     * substituição por nova versão material -- que não desfaz nada do que foi
     * decidido, apenas registra que aquelas decisões falam de um quadro que não
     * é mais o vigente.
     */
    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            $original = SalesBoardManagementReviewStatus::tryFrom((string) $review->getRawOriginal('status'));
            $dirty = array_keys($review->getDirty());

            $allowed = match ($original) {
                SalesBoardManagementReviewStatus::Draft => self::DRAFT_MUTABLE_FIELDS,
                SalesBoardManagementReviewStatus::Returned,
                SalesBoardManagementReviewStatus::Approved => self::SUPERSEDING_FIELDS,
                default => [],
            };

            if (array_diff($dirty, $allowed) !== []) {
                throw new LogicException('A finished sales board management review is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Sales board management reviews cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => SalesBoardManagementReviewStatus::class,
            'opened_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'source_changed' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'attempt', 'approved_at', 'returned_at', 'superseded_at', 'superseded_reason', 'source_changed'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycle::class, 'sales_board_cycle_id');
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleBaseline::class, 'sales_board_cycle_baseline_id');
    }

    public function builderReview(): BelongsTo
    {
        return $this->belongsTo(SalesBoardBuilderReview::class, 'sales_board_builder_review_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }

    /**
     * As pendências na ordem em que a análise as apresenta: o que o Nimbus
     * apontou primeiro, o que a construtora declarou depois. Elas nascem juntas
     * e nessa ordem, então o id a preserva.
     */
    public function nonconformities(): HasMany
    {
        return $this->hasMany(SalesBoardManagementNonconformity::class, 'sales_board_management_review_id')
            ->orderBy('id');
    }

    public function publication(): HasOne
    {
        return $this->hasOne(SalesBoardPublication::class, 'sales_board_management_review_id');
    }

    public function isEditable(): bool
    {
        return $this->status === SalesBoardManagementReviewStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === SalesBoardManagementReviewStatus::Approved;
    }

    public function isReturned(): bool
    {
        return $this->status === SalesBoardManagementReviewStatus::Returned;
    }

    public function isSuperseded(): bool
    {
        return $this->status === SalesBoardManagementReviewStatus::Superseded;
    }

    /**
     * A análise ainda fala do quadro vigente?
     *
     * A comparação é pelo resumo da posição, e não pelo id da versão, pela mesma
     * razão da Fase D: um recálculo que só troca a origem material produz uma
     * versão nova com exatamente os mesmos fatos, e obrigar a Gestão a decidir
     * tudo de novo seria pedir que ela analisasse duas vezes o mesmo quadro.
     */
    public function appliesTo(?SalesBoardCycleBaseline $baseline): bool
    {
        return ($baseline !== null)
            && ! $this->isSuperseded()
            && ((string) $this->snapshot_fingerprint === (string) $baseline->snapshot_fingerprint);
    }

    /**
     * Pendências ainda sem conclusão, de um total.
     *
     * @return array{decided: int, total: int, percent: int}
     */
    public function progress(): array
    {
        $total = $this->nonconformities->count();
        $decided = $this->nonconformities
            ->reject(fn (SalesBoardManagementNonconformity $item): bool => $item->decision->isPending())
            ->count();

        return [
            'decided' => $decided,
            'total' => $total,
            'percent' => $total === 0 ? 100 : (int) round(($decided / $total) * 100),
        ];
    }

    /**
     * @return list<SalesBoardManagementNonconformity>
     */
    public function pendingNonconformities(): array
    {
        return $this->nonconformities
            ->filter(fn (SalesBoardManagementNonconformity $item): bool => $item->decision->isPending())
            ->values()
            ->all();
    }

    /**
     * @return list<SalesBoardManagementNonconformity>
     */
    public function correctionRequiredNonconformities(): array
    {
        return $this->nonconformities
            ->filter(fn (SalesBoardManagementNonconformity $item): bool => $item->decision === SalesBoardNonconformityDecision::CorrectionRequired)
            ->values()
            ->all();
    }

    public function attemptLabel(): string
    {
        return 'Análise '.$this->attempt;
    }
}
