<?php

namespace App\Models;

use App\Enums\SalesBoardRolloutHomologationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SalesBoardRolloutHomologationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A homologação de uma Emissão para o modo automatizado.
 *
 * Enquanto é rascunho, é formulário: reavalia, aceita diferença, marca impacto
 * revisado, muda de ideia. Depois de aprovada, vira declaração -- e declaração
 * não se reescreve. Corrigir uma premissa é abrir a tentativa seguinte, o que
 * preserva o que foi revisado antes.
 *
 * A única mudança admitida depois da aprovação é ser marcada como substituída,
 * quando os fatos materiais que ela revisou deixam de ser os atuais, e o
 * registro da ativação -- que não altera nenhuma premissa, apenas anota que
 * aquela homologação foi usada.
 */
class SalesBoardRolloutHomologation extends Model
{
    /** @use HasFactory<SalesBoardRolloutHomologationFactory> */
    use HasFactory, LogsActivity;

    /**
     * O que o rascunho ainda pode gravar.
     *
     * @var list<string>
     */
    public const DRAFT_MUTABLE_FIELDS = [
        'status',
        'proposed_start_reference_month',
        'comparison_reference_month',
        'comparison_month_reason',
        'auto_open_builder_review',
        'assessment_hash',
        'construction_scope_hash',
        'assessed_at',
        'guarantees_reviewed_at',
        'guarantees_reviewed_by_user_id',
        'monthly_report_reviewed_at',
        'monthly_report_reviewed_by_user_id',
        'approved_at',
        'approved_by_user_id',
        'approval_reason',
        'rejected_at',
        'rejected_by_user_id',
        'rejection_reason',
        'superseded_at',
        'superseded_reason',
        'updated_at',
    ];

    /**
     * O que uma homologação encerrada ainda pode gravar: a própria substituição
     * e o registro de que foi usada numa ativação.
     *
     * @var list<string>
     */
    public const FINAL_MUTABLE_FIELDS = [
        'status',
        'superseded_at',
        'superseded_reason',
        'activated_at',
        'updated_at',
    ];

    protected $fillable = [
        'emission_id',
        'attempt',
        'status',
        'proposed_start_reference_month',
        'comparison_reference_month',
        'comparison_month_reason',
        'auto_open_builder_review',
        'assessment_hash',
        'construction_scope_hash',
        'assessed_at',
        'guarantees_reviewed_at',
        'guarantees_reviewed_by_user_id',
        'monthly_report_reviewed_at',
        'monthly_report_reviewed_by_user_id',
        'approved_at',
        'approved_by_user_id',
        'approval_reason',
        'rejected_at',
        'rejected_by_user_id',
        'rejection_reason',
        'superseded_at',
        'superseded_reason',
        'activated_at',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $homologation): void {
            $original = SalesBoardRolloutHomologationStatus::tryFrom(
                (string) $homologation->getRawOriginal('status')
            );

            $allowed = $original === SalesBoardRolloutHomologationStatus::Draft
                ? self::DRAFT_MUTABLE_FIELDS
                : self::FINAL_MUTABLE_FIELDS;

            if (array_diff(array_keys($homologation->getDirty()), $allowed) !== []) {
                throw new LogicException('A finished sales board rollout homologation is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Sales board rollout homologations cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => SalesBoardRolloutHomologationStatus::class,
            'proposed_start_reference_month' => 'immutable_date',
            'comparison_reference_month' => 'immutable_date',
            'auto_open_builder_review' => 'boolean',
            'assessed_at' => 'immutable_datetime',
            'guarantees_reviewed_at' => 'immutable_datetime',
            'monthly_report_reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'attempt', 'proposed_start_reference_month', 'approved_at', 'activated_at', 'superseded_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function constructions(): HasMany
    {
        return $this->hasMany(
            SalesBoardRolloutHomologationConstruction::class,
            'sales_board_rollout_homologation_id',
        )->orderBy('construction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function guaranteesReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guarantees_reviewed_by_user_id');
    }

    public function monthlyReportReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'monthly_report_reviewed_by_user_id');
    }

    public function isEditable(): bool
    {
        return $this->status === SalesBoardRolloutHomologationStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === SalesBoardRolloutHomologationStatus::Approved;
    }

    public function wasActivated(): bool
    {
        return $this->activated_at !== null;
    }

    public function guaranteesReviewed(): bool
    {
        return $this->guarantees_reviewed_at !== null && $this->guarantees_reviewed_by_user_id !== null;
    }

    public function monthlyReportReviewed(): bool
    {
        return $this->monthly_report_reviewed_at !== null && $this->monthly_report_reviewed_by_user_id !== null;
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->proposed_start_reference_month->toDateString())->startOfMonth();
    }

    public function startMonthLabel(): string
    {
        return $this->proposed_start_reference_month?->format('m/Y') ?? '—';
    }

    public function comparisonMonthLabel(): string
    {
        return $this->comparison_reference_month?->format('m/Y') ?? '—';
    }

    public function attemptLabel(): string
    {
        return 'Homologação '.$this->attempt;
    }
}
