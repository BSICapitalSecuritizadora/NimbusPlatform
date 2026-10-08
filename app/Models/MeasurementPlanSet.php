<?php

namespace App\Models;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\OperationContextVisibilityService;
use App\Support\BusinessTime;
use Database\Factories\MeasurementPlanSetFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MeasurementPlanSet extends Model
{
    /** @use HasFactory<MeasurementPlanSetFactory> */
    use HasFactory, LogsActivity;

    /**
     * Por que o plano que já recebeu medição não pode ser excluído
     * ({@see self::hasMeasurementHistory()}).
     */
    public const MEASUREMENT_HISTORY_DELETION_REFUSAL = 'Este plano já tem medição registrada (arquivo, pagamento ou linha medida) e não pode ser excluído: o histórico da medição depende dele.';

    /**
     * O Fundo de Obra é da versão do plano; mudar o vigente é revisar o plano.
     */
    public const FUND_BELONGS_TO_VERSION_REFUSAL = 'O Fundo de Obra pertence à versão do plano e não muda no plano já criado: altere-o no rascunho da revisão do plano.';

    /**
     * Obra, operação e incorrido inicial são a identidade e o ponto de partida
     * financeiro do plano: a partir da primeira ativação -- ou do primeiro
     * arquivo de medição -- as medições e as versões dependem deles.
     */
    public const PLAN_CONTEXT_LOCKED_REFUSAL = 'A obra, a operação e o incorrido inicial de um plano já ativado não mudam: as medições e as versões do plano dependem deles.';

    /**
     * Um plano por obra na operação; replanejar é revisar o plano existente.
     */
    public const CONSTRUCTION_ALREADY_PLANNED_REFUSAL = 'Esta obra já tem plano de medição nesta operação. Para mudar o cronograma ou o Fundo de Obra, crie uma revisão do plano existente.';

    /**
     * Versões apagadas em cascata com o plano, guardadas no `deleting` para a
     * trilha do `deleted`.
     *
     * @var list<array<string, mixed>>
     */
    private array $versionsDeletedWithThePlan = [];

    /**
     * Quem cria o plano é o autor da V1. Sem informar, vale o usuário
     * autenticado ({@see self::firstVersionAuthoredBy()}).
     */
    private ?int $firstVersionAuthorId = null;

    /**
     * Quem exclui o plano, quando a exclusão vem de um serviço agindo em nome de
     * alguém ({@see self::deletedBy()}); sem ele, vale o usuário autenticado.
     */
    private ?User $deletionActor = null;

    protected $fillable = [
        'operation_id',
        'construction_id',
        'name',
        'is_default',
        'initial_incurred_amount',
        'initial_physical_progress_percent',
        'initial_physical_progress_reference_date',
    ];

    protected $attributes = [
        'initial_physical_progress_percent' => '0.00',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $planSet): void {
            $planSet->normalizeInitialPhysicalProgress();
        });

        // Todo plano nasce com a V1 em rascunho: é nela que entram o
        // cronograma e o Fundo de Obra, e é ela que a ativação torna vigente.
        static::created(function (self $planSet): void {
            $planSet->versions()->create([
                'operation_id' => $planSet->operation_id,
                'version_number' => 1,
                'status' => MeasurementPlanVersionStatus::Draft,
                'created_by' => $planSet->firstVersionAuthorId ?? auth()->id(),
            ]);
        });

        static::updating(function (self $planSet): void {
            $planSet->guardInitialPhysicalProgressImmutability();
        });

        static::saving(function (self $planSet): void {
            if ($planSet->exists
                && $planSet->isDirty([
                    'operation_id',
                    'construction_id',
                    'initial_incurred_amount',
                ])
                && $planSet->isReferencedByApprovedEngineering()) {
                throw new MeasurementWorkflowException('O contexto de um plano aprovado pela Engenharia está bloqueado.');
            }

            if ($planSet->exists
                && $planSet->isDirty(['operation_id', 'construction_id', 'initial_incurred_amount'])
                && $planSet->hasBeenEffectiveOrMeasured()) {
                throw new MeasurementWorkflowException(self::PLAN_CONTEXT_LOCKED_REFUSAL, [
                    'plan_set_id' => $planSet->getKey(),
                ]);
            }

            if (filled($planSet->construction_id)
                && (! $planSet->exists || $planSet->isDirty(['operation_id', 'construction_id']))
                && self::query()
                    ->where('operation_id', $planSet->operation_id)
                    ->where('construction_id', $planSet->construction_id)
                    ->when($planSet->exists, fn ($others) => $others->whereKeyNot($planSet->getKey()))
                    ->exists()) {
                throw ValidationException::withMessages([
                    'construction_id' => self::CONSTRUCTION_ALREADY_PLANNED_REFUSAL,
                ]);
            }

            $actor = auth()->user();

            if ($actor instanceof User
                && filled($planSet->construction_id)
                && (! $planSet->exists || $planSet->isDirty(['operation_id', 'construction_id']))) {
                $operation = Operation::query()->find($planSet->operation_id);

                if (! $operation instanceof Operation) {
                    throw new MeasurementWorkflowException('A operação do plano de medição não está disponível.');
                }

                app(OperationContextVisibilityService::class)->assertConstructionIsVisibleForOperation(
                    $actor,
                    $operation,
                    $planSet->construction_id,
                );
            }
        });

        static::deleting(function (self $planSet): void {
            $approvedMeasurementId = $planSet->approvedEngineeringMeasurementId();

            if ($approvedMeasurementId !== null) {
                throw new MeasurementWorkflowException('Um empreendimento coberto por Engenharia aprovada não pode ser removido.', [
                    'measurement_id' => $approvedMeasurementId,
                    'plan_set_id' => $planSet->getKey(),
                ]);
            }

            if ($planSet->hasMeasurementHistory()) {
                throw new MeasurementWorkflowException(self::MEASUREMENT_HISTORY_DELETION_REFUSAL, [
                    'plan_set_id' => $planSet->getKey(),
                ]);
            }

            $planSet->versionsDeletedWithThePlan = $planSet->versions()
                ->orderBy('version_number')
                ->get()
                ->map(fn (MeasurementPlanVersion $version): array => [
                    'plan_version_id' => (int) $version->getKey(),
                    'version_number' => (int) $version->version_number,
                    'status' => $version->status->value,
                    'effective_from' => $version->effective_from?->toDateString(),
                    'construction_fund_amount' => $version->construction_fund_amount,
                    'line_count' => $version->lines()->count(),
                ])
                ->all();
        });

        // As versões descem com o plano pela FK, sem passar pelos ganchos
        // delas: a trilha guarda o que foi junto. Só sai plano sem medição.
        static::deleted(function (self $planSet): void {
            $actor = $planSet->deletionActor ?? auth()->user();
            $activity = activity('measurements')
                ->performedOn($planSet)
                ->event('plan_versions_deleted_with_plan')
                ->withProperties([
                    'operation_id' => (int) $planSet->operation_id,
                    'plan_set_id' => (int) $planSet->getKey(),
                    'construction_id' => $planSet->construction_id === null ? null : (int) $planSet->construction_id,
                    'versions' => $planSet->versionsDeletedWithThePlan,
                    'actor_user_id' => $actor instanceof User ? (int) $actor->getKey() : null,
                ]);

            if ($actor instanceof User) {
                $activity->causedBy($actor);
            }

            $activity->log('plan_versions_deleted_with_plan');
        });
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'initial_incurred_amount' => 'decimal:2',
            'initial_physical_progress_percent' => 'decimal:2',
            'initial_physical_progress_reference_date' => 'date',
        ];
    }

    /**
     * O plano carrega o avanço físico inicial e o contexto financeiro da obra,
     * que a Engenharia congela no snapshot. A trilha caía em `default`, que o
     * `audit:clean-filtered` descarta em um ano; em `measurements`, categoria
     * protegida, a criação do plano -- valor, data de referência, autor e
     * instante -- continua reconstituível pelo prazo das demais evidências da
     * medição.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('measurements')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * O Fundo de Obra não é do plano: é de cada versão
     * ({@see MeasurementPlanVersion::$construction_fund_amount}). Ler ou gravar
     * pelo nome antigo falha alto, em vez de devolver nulo -- um fundo nulo
     * desligaria em silêncio a referência financeira da medição.
     * Use {@see self::currentConstructionFundAmount()} ou a versão.
     */
    protected function constructionFundAmount(): Attribute
    {
        $refusal = fn (): never => throw new LogicException('measurement_plan_sets.construction_fund_amount saiu do plano: o Fundo de Obra é da versão (MeasurementPlanVersion). Use currentConstructionFundAmount() ou a versão capturada pela medição.');

        return Attribute::make(get: $refusal, set: $refusal);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    /**
     * Linhas de todas as versões do plano -- o histórico inteiro. O cronograma
     * em vigor é o da versão vigente ({@see self::activeVersion()}).
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MeasurementPlanLine::class, 'plan_set_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MeasurementPlanVersion::class, 'plan_set_id');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(MeasurementPlanVersion::class, 'plan_set_id')
            ->where('status', MeasurementPlanVersionStatus::Active->value);
    }

    public function draftVersion(): HasOne
    {
        return $this->hasOne(MeasurementPlanVersion::class, 'plan_set_id')
            ->where('status', MeasurementPlanVersionStatus::Draft->value);
    }

    /**
     * Registra quem cria o plano (e, com ele, a V1) quando a criação não vem
     * do usuário autenticado -- um serviço agindo em nome de alguém.
     */
    public function firstVersionAuthoredBy(User $actor): static
    {
        $this->firstVersionAuthorId = (int) $actor->getKey();

        return $this;
    }

    /**
     * Registra quem exclui o plano quando a exclusão não vem do usuário
     * autenticado -- o serviço de versões, que autorizou essa pessoa.
     */
    public function deletedBy(User $actor): static
    {
        $this->deletionActor = $actor;

        return $this;
    }

    /**
     * A versão que responde pelo plano hoje: a vigente, ou o rascunho da V1
     * enquanto o plano ainda não foi ativado.
     */
    public function currentVersion(): ?MeasurementPlanVersion
    {
        $active = $this->relationLoaded('activeVersion') ? $this->activeVersion : $this->activeVersion()->first();

        if ($active instanceof MeasurementPlanVersion) {
            return $active;
        }

        return $this->relationLoaded('draftVersion') ? $this->draftVersion : $this->draftVersion()->first();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MeasurementAsset::class, 'plan_set_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MeasurementPayment::class, 'plan_set_id');
    }

    /**
     * O plano já recebeu alguma medição, em qualquer situação dela?
     *
     * Arquivo de medição, pagamento ou linha do cronograma gravada por uma
     * aprovação da Engenharia. A exclusão do plano desce pela FK: as linhas vão
     * em cascata, sem passar pelo `deleting` delas, e o `plan_set_id` e o
     * `plan_line_id` dos arquivos e o `plan_set_id` dos pagamentos viram nulos.
     * A medição em análise perderia o vínculo com o empreendimento, e a paga e
     * devolvida à Engenharia ficaria sem saída: sem recusa terminal (há
     * pagamento) e sem a etapa Pagamento, que não aceita pagamento sem plano.
     * Plano cadastrado por engano, ainda sem nenhuma medição, continua
     * excluível.
     */
    public function hasMeasurementHistory(): bool
    {
        return $this->assets()->exists()
            || $this->payments()->exists()
            || $this->lines()->whereNotNull('measurement_id')->exists();
    }

    /**
     * Fundo de Obra da versão que responde pelo plano hoje (a vigente, ou o
     * rascunho da V1 antes da primeira ativação).
     */
    public function currentConstructionFundAmount(): ?string
    {
        return $this->currentVersion()?->construction_fund_amount;
    }

    /**
     * O plano já valeu (alguma versão foi ativada) ou já recebeu arquivo de
     * medição?
     */
    public function hasBeenEffectiveOrMeasured(): bool
    {
        return $this->versions()
            ->whereIn('status', [MeasurementPlanVersionStatus::Active->value, MeasurementPlanVersionStatus::Superseded->value])
            ->exists()
            || $this->assets()->exists();
    }

    public function getIncurredAmountAttribute(): float
    {
        $paymentsSum = (float) $this->payments()->sum('amount');

        return (float) $this->initial_incurred_amount + $paymentsSum;
    }

    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->currentConstructionFundAmount() - $this->incurred_amount;
    }

    public function getUsedPercentageAttribute(): float
    {
        $fund = (float) $this->currentConstructionFundAmount();

        if ($fund <= 0) {
            return 0;
        }

        return ($this->incurred_amount / $fund) * 100;
    }

    /**
     * O avanço físico inicial é definido na criação do plano. O valor é lido do
     * atributo cru, antes do cast: o `decimal:2` arredondaria 35,555 para 35,56
     * e esconderia a terceira casa. Não informado vale 0,00%.
     */
    private function normalizeInitialPhysicalProgress(): void
    {
        $raw = $this->getAttributes()['initial_physical_progress_percent'] ?? null;
        $basisPoints = $raw === null || $raw === '' ? 0 : MeasurementPhysicalProgress::basisPoints($raw);

        if ($basisPoints === null || $basisPoints < 0 || $basisPoints > MeasurementPhysicalProgress::LIMIT_BASIS_POINTS) {
            throw ValidationException::withMessages([
                'initial_physical_progress_percent' => 'Informe o avanço físico inicial entre 0,00% e 100,00%, com no máximo duas casas decimais.',
            ]);
        }

        $referenceDate = $this->initial_physical_progress_reference_date;

        if ($basisPoints > 0 && $referenceDate === null) {
            throw ValidationException::withMessages([
                'initial_physical_progress_reference_date' => 'Informe a data de referência do avanço físico inicial.',
            ]);
        }

        // O avanço já executado só pode se referir a hoje ou ao passado; uma data
        // futura digitada por engano, imutável, recusaria as competências até lá.
        if ($referenceDate !== null && $referenceDate->toDateString() > BusinessTime::dateString()) {
            throw ValidationException::withMessages([
                'initial_physical_progress_reference_date' => 'A data de referência do avanço físico inicial não pode ser futura.',
            ]);
        }

        $this->setAttribute('initial_physical_progress_percent', MeasurementPhysicalProgress::decimal($basisPoints));
    }

    /**
     * Depois da criação o avanço físico inicial é um marco histórico. Corrigi-lo
     * exigirá um fluxo próprio e auditado; até lá nenhuma escrita comum o altera
     * -- nem o formulário do plano, nem o da operação, nem um `update()` ou
     * `increment()` direto. Todo o avanço posterior entra pelas medições.
     *
     * A data é comparada como dia: uma data com hora que cai no mesmo dia não é
     * alteração. Escritas pelo query builder não passam por aqui; no MySQL o
     * CHECK do banco ainda segura a faixa.
     */
    private function guardInitialPhysicalProgressImmutability(): void
    {
        $original = $this->getOriginal('initial_physical_progress_reference_date');
        $current = $this->initial_physical_progress_reference_date;

        if ($this->isDirty('initial_physical_progress_percent')
            || $original?->toDateString() !== $current?->toDateString()) {
            throw new MeasurementWorkflowException('O avanço físico inicial do plano não pode ser alterado depois da criação.', [
                'plan_set_id' => $this->getKey(),
            ]);
        }
    }

    private function isReferencedByApprovedEngineering(): bool
    {
        return $this->approvedEngineeringMeasurementId() !== null;
    }

    private function approvedEngineeringMeasurementId(): ?int
    {
        $measurementId = $this->assets()
            ->whereHas('measurement.reviews', fn ($reviews) => $reviews
                ->where('stage', 1)
                ->where('status', 'approved'))
            ->value('measurement_id');

        return $measurementId === null ? null : (int) $measurementId;
    }
}
