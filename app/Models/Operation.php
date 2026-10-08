<?php

namespace App\Models;

use App\Enums\MeasurementResponsibility;
use App\Enums\OperationStatus;
use App\Exceptions\DelegationHistoryException;
use App\Exceptions\MeasurementWorkflowException;
use App\Exceptions\OperationLifecycleException;
use App\Services\MeasurementPlanVersionService;
use App\Services\OperationContextMutationService;
use App\Services\OperationResponsibilityService;
use App\Services\ResponsibilityDelegationService;
use Database\Factories\OperationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use HasFactory, LogsActivity;

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

        // Qualquer medição basta para bloquear, não só a aprovada pela
        // Engenharia. O critério antigo deixava passar exatamente o pior caso:
        // uma medição recém-enviada, ainda em revisão, era apagada em cascata
        // junto com seus arquivos, pausas, alertas de SLA e pagamentos, sem
        // nenhum aviso. Operação criada por engano se cancela, não se apaga.
        static::deleting(function (self $operation): void {
            if ($operation->measurements()->exists()) {
                throw OperationLifecycleException::measurementHistory();
            }

            if ($operation->hasResponsibilityDelegationHistory()) {
                throw DelegationHistoryException::forOperation();
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
            'status' => OperationStatus::class,
            'amount' => 'decimal:2',
            'construction_fund_amount' => 'decimal:2',
            'due_date' => 'date',
            'next_measurement_at' => 'date',
        ];
    }

    /**
     * O histórico da operação é evidência regulada, e a política de retenção
     * separa os baldes por `log_name`: `operations` é retido por sete anos,
     * `default` é descartado em um ano. Sem esta linha, toda a trilha de
     * lifecycle e de troca de responsáveis cairia no balde errado.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('operations')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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

    public function planVersions(): HasMany
    {
        return $this->hasMany(MeasurementPlanVersion::class);
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
     * Garante um plano de medição por empreendimento, cada um com o próprio
     * Fundo de Obra na V1. A escrita é do serviço de planos, sob o lock da
     * Operation ({@see MeasurementPlanVersionService::syncDevelopmentPlans()}):
     * plano novo nasce com a V1 em rascunho; o existente só acompanha o nome
     * do empreendimento, e o Fundo de Obra de um plano já ativado muda por
     * revisão do plano, não por aqui.
     *
     * @param  array<int, array{construction_id?: mixed, construction_fund_amount?: mixed, initial_physical_progress_percent?: mixed, initial_physical_progress_reference_date?: mixed}>  $developments
     */
    public function syncDevelopmentPlans(array $developments, User $actor): void
    {
        app(MeasurementPlanVersionService::class)->syncDevelopmentPlans($this, $actor, $developments);
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

    /**
     * Alguma delegação de responsabilidade foi escopada nesta operação?
     *
     * Vale para qualquer estado, revogada inclusive: o vínculo é histórico, e
     * `scope_operation_id` é RESTRICT justamente para que ele não desapareça
     * junto com a operação.
     */
    public function hasResponsibilityDelegationHistory(): bool
    {
        return ResponsibilityDelegation::query()
            ->where('scope_operation_id', $this->getKey())
            ->exists();
    }

    public function hasDirectResponsibility(User $user, MeasurementResponsibility $responsibility): bool
    {
        return $this->responsibleUserIdFor($responsibility) === (int) $user->getKey();
    }

    /**
     * Participa da operação quem ocupa um dos sete papéis do fluxo.
     *
     * A lista "Notificar em caso de recusa" ({@see self::rejectionNotifyUsers()})
     * fica de fora de propósito: ela só decide quem recebe o aviso de recusa da
     * Engenharia. Quando contava como participação, quem fora incluído "para ser
     * avisado" passava a ver e editar a operação, enviar medição, baixar arquivos
     * e comprovantes e -- com a permissão de gerir responsáveis -- trocar os sete
     * responsáveis. A mesma regra vale no escopo SQL
     * ({@see ResponsibilityDelegationService::scopeVisibleOperationsTo()}) e na
     * classificação direto/delegado do cockpit; as três precisam concordar.
     */
    public function hasParticipant(User $user): bool
    {
        $participantIds = array_map(
            fn (string $field): int => (int) $this->getAttribute($field),
            self::RESPONSIBILITY_FIELDS,
        );

        return in_array((int) $user->getKey(), array_filter($participantIds), true);
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
