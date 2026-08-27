<?php

namespace App\Models;

use App\Enums\MeasurementResponsibility;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\OperationContextMutationService;
use App\Services\OperationContextVisibilityService;
use App\Services\OperationResponsibilityService;
use App\Services\ResponsibilityDelegationService;
use Database\Factories\OperationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_OPTIONS = [
        'draft' => 'Rascunho',
        'pending' => 'Pendente',
        'active' => 'Em Andamento',
        'rejected' => 'Recusada',
        'settled' => 'Liquidada',
        'completed' => 'Concluída',
        'canceled' => 'Cancelada',
    ];

    /**
     * @var list<string>
     */
    public const RESPONSIBILITY_FIELDS = [
        'assigned_user_id',
        'responsible_user_id',
        'stage2_reviewer_user_id',
        'stage3_reviewer_user_id',
        'payment_manager_user_id',
        'payment_receipt_uploader_user_id',
        'payment_finalizer_user_id',
    ];

    protected $fillable = [
        'emission_id',
        'construction_id',
        'code',
        'title',
        'status',
        'issuer',
        'amount',
        'construction_fund_amount',
        'due_date',
        'next_measurement_at',
        'assigned_user_id',
        'responsible_user_id',
        'stage2_reviewer_user_id',
        'stage3_reviewer_user_id',
        'payment_manager_user_id',
        'payment_receipt_uploader_user_id',
        'payment_finalizer_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $operation): void {
            $actor = auth()->user();

            if ($operation->isDirty('emission_id')) {
                app(OperationContextMutationService::class)->assertEmissionCanChange($operation);
            }

            if ($operation->isDirty('construction_id')
                && $operation->measurements()
                    ->whereHas('reviews', fn (Builder $reviews): Builder => $reviews
                        ->where('stage', 1)
                        ->where('status', 'approved'))
                    ->exists()) {
                throw new MeasurementWorkflowException('O contexto de uma operação aprovada pela Engenharia está bloqueado.');
            }

            if ($actor instanceof User && $operation->isDirty(self::RESPONSIBILITY_FIELDS)) {
                app(OperationResponsibilityService::class)->assertCanChange(
                    $actor,
                    $operation,
                    $operation->only(self::RESPONSIBILITY_FIELDS),
                );
            }
        });

        static::deleting(function (self $operation): void {
            if ($operation->measurements()
                ->whereHas('reviews', fn (Builder $reviews): Builder => $reviews
                    ->where('stage', 1)
                    ->where('status', 'approved'))
                ->exists()) {
                throw new MeasurementWorkflowException('Uma operação com medição aprovada pela Engenharia não pode ser excluída.');
            }
        });

        static::created(function (self $operation): void {
            if (filled($operation->code)) {
                return;
            }

            $operation->forceFill([
                'code' => self::generateCode($operation),
            ])->saveQuietly();
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'construction_fund_amount' => 'decimal:2',
            'due_date' => 'date',
            'next_measurement_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_OPTIONS[$this->status] ?? $this->status;
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function stage2Reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stage2_reviewer_user_id');
    }

    public function stage3Reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stage3_reviewer_user_id');
    }

    public function paymentManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_manager_user_id');
    }

    public function paymentReceiptUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_receipt_uploader_user_id');
    }

    public function paymentFinalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_finalizer_user_id');
    }

    public function rejectionNotifyUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'operation_rejection_notify_user')->withTimestamps();
    }

    public function planSets(): HasMany
    {
        return $this->hasMany(MeasurementPlanSet::class);
    }

    public function planLines(): HasMany
    {
        return $this->hasMany(MeasurementPlanLine::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MeasurementPayment::class);
    }

    public function defaultPlanSet(): ?MeasurementPlanSet
    {
        return $this->planSets()
            ->where('is_default', true)
            ->first()
            ?? $this->planSets()->orderBy('id')->first();
    }

    /**
     * Ensures one measurement plan set exists per development, each carrying its
     * own construction fund. Existing plan sets are updated; the first development
     * becomes the default plan when the operation has none yet.
     *
     * @param  array<int, array{construction_id?: mixed, construction_fund_amount?: mixed}>  $developments
     */
    public function syncDevelopmentPlans(array $developments, User $actor): void
    {
        $visibility = app(OperationContextVisibilityService::class);
        $visibility->assertOperationPayloadIsVisible($actor, $this->emission_id, $developments);
        $hasDefault = $this->planSets()->where('is_default', true)->exists();
        $index = 0;

        foreach ($developments as $development) {
            $constructionId = $development['construction_id'] ?? null;

            if (blank($constructionId)) {
                continue;
            }

            $construction = $visibility->assertConstructionIsVisibleForOperation($actor, $this, $constructionId);
            $developmentName = $construction->development_name;
            $fund = $development['construction_fund_amount'] ?? null;

            $planSet = $this->planSets()->where('construction_id', $constructionId)->first();

            if ($planSet instanceof MeasurementPlanSet) {
                $planSet->update([
                    'name' => $developmentName,
                    'construction_fund_amount' => $fund,
                ]);
            } else {
                $this->planSets()->create([
                    'construction_id' => $constructionId,
                    'name' => $developmentName,
                    'construction_fund_amount' => $fund,
                    'is_default' => ! $hasDefault && $index === 0,
                ]);
            }

            $index++;
        }

        $this->refreshTitleFromConstructions();
    }

    /**
     * Rebuilds the operation title from the development names of its plan sets,
     * so each operation is labelled by the empreendimentos it covers.
     */
    public function refreshTitleFromConstructions(): void
    {
        $developmentNames = Construction::query()
            ->whereIn('id', $this->planSets()->whereNotNull('construction_id')->pluck('construction_id'))
            ->orderBy('development_name')
            ->pluck('development_name');

        if ($developmentNames->isEmpty()) {
            return;
        }

        $this->forceFill(['title' => $developmentNames->implode(', ')])->save();
    }

    public function reviewerForStage(int $stage): ?int
    {
        return match ($stage) {
            1 => $this->responsible_user_id,
            2 => $this->stage2_reviewer_user_id,
            3 => $this->stage3_reviewer_user_id,
            default => null,
        };
    }

    /**
     * Maps the full measurement workflow stage (1–5) to the user responsible for it:
     * 1 Engenharia, 2 Gestão, 3 Compliance, 4 Pagamento, 5 Finalização.
     */
    public function stageResponsibleId(int $stage): ?int
    {
        $responsibility = MeasurementResponsibility::primaryForStage($stage);

        return $responsibility instanceof MeasurementResponsibility
            ? $this->responsibleUserIdFor($responsibility)
            : null;
    }

    public function responsibleUserIdFor(MeasurementResponsibility $responsibility): ?int
    {
        $userId = $this->getAttribute($responsibility->operationColumn());

        return filled($userId) ? (int) $userId : null;
    }

    public function hasDirectResponsibility(User $user, MeasurementResponsibility $responsibility): bool
    {
        return $this->responsibleUserIdFor($responsibility) === (int) $user->getKey();
    }

    public function hasParticipant(User $user): bool
    {
        $participantIds = [
            $this->assigned_user_id,
            $this->responsible_user_id,
            $this->stage2_reviewer_user_id,
            $this->stage3_reviewer_user_id,
            $this->payment_manager_user_id,
            $this->payment_receipt_uploader_user_id,
            $this->payment_finalizer_user_id,
        ];

        return in_array((int) $user->getKey(), array_map('intval', array_filter($participantIds)), true)
            || $this->rejectionNotifyUsers()->whereKey($user->getKey())->exists();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(ResponsibilityDelegationService::class)->scopeVisibleOperationsTo($query, $user);
    }

    private static function generateCode(self $operation): string
    {
        $referenceDate = $operation->created_at ?? now();

        return sprintf('OP-%s-%04d', $referenceDate->format('Y'), $operation->getKey());
    }
}
