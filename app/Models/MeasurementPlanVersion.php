<?php

namespace App\Models;

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\MeasurementPlanVersionService;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Database\Factories\MeasurementPlanVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Uma versão do plano de medição: o cronograma previsto e o Fundo de Obra (o
 * custo previsto da obra) que valem a partir de uma competência.
 *
 * O plano ({@see MeasurementPlanSet}) é o contexto da obra na operação e não
 * muda de identidade: dele são o avanço físico inicial, o teto de 100%, os
 * arquivos e os pagamentos. A versão é o que se replaneja. Rascunho é o único
 * estado editável; vigente e substituída são histórico -- a medição criada sob
 * uma versão continua ligada a ela, mesmo depois de outra a substituir.
 *
 * As regras abaixo valem para toda gravação Eloquent; a ordem de locks, as
 * validações de ativação e os eventos de ciclo de vida da trilha ficam em
 * {@see MeasurementPlanVersionService}. No banco, as colunas
 * geradas `active_plan_set_id` e `draft_plan_set_id` recusam a segunda vigente
 * e o segundo rascunho do mesmo plano.
 */
class MeasurementPlanVersion extends Model
{
    /** @use HasFactory<MeasurementPlanVersionFactory> */
    use HasFactory, LogsActivity;

    public const IMMUTABLE_HISTORY_REFUSAL = 'A versão %s do plano já valeu e não pode ser alterada: crie uma revisão do plano.';

    public const CANCELLED_VERSION_REFUSAL = 'A versão %s do plano foi cancelada e não muda mais: para replanejar, crie uma revisão do plano.';

    /**
     * O que a substituição grava na versão vigente. Fora do rascunho é a única
     * escrita aceita: quem ativou, quando, com que avanço, quem cancelou e por
     * quê são registro do ciclo de vida e não se reescrevem.
     *
     * @var list<string>
     */
    private const SUPERSESSION_COLUMNS = [
        'status',
        'superseded_at',
        'superseded_by_version_id',
        'updated_at',
    ];

    /**
     * @var list<string>
     */
    private const IDENTITY_COLUMNS = [
        'operation_id',
        'plan_set_id',
        'version_number',
        'previous_version_id',
        'created_by',
    ];

    protected $fillable = [
        'operation_id',
        'plan_set_id',
        'version_number',
        'previous_version_id',
        'effective_from',
        'construction_fund_amount',
        'revision_category',
        'revision_reason',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
        'revision' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->guardCreation();
        });

        static::updating(function (self $version): void {
            $version->guardUpdate();
        });

        static::deleting(function (self $version): void {
            $version->guardDeletion();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MeasurementPlanVersionStatus::class,
            'revision_category' => MeasurementPlanRevisionCategory::class,
            'version_number' => 'integer',
            'revision' => 'integer',
            'effective_from' => 'date',
            'construction_fund_amount' => 'decimal:2',
            'activation_progress_percent' => 'decimal:2',
            'last_measurement_id_at_activation' => 'integer',
            'activated_at' => 'datetime',
            'superseded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * A trilha de atributos vai para `measurements`, categoria protegida, como
     * a do plano e a das linhas: o conteúdo de um rascunho e quem o mudou
     * continuam reconstituíveis pelo prazo das demais evidências da medição. A
     * situação não é atributo preenchível: a ativação, a substituição e o
     * cancelamento têm evento próprio, gravado pelo serviço com o autor.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('measurements')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function supersededByVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_version_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MeasurementPlanLine::class, 'plan_version_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MeasurementAsset::class, 'plan_version_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), MeasurementPlanVersionStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), MeasurementPlanVersionStatus::Draft->value);
    }

    public function label(): string
    {
        return 'V'.$this->version_number;
    }

    public function isDraft(): bool
    {
        return $this->status === MeasurementPlanVersionStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === MeasurementPlanVersionStatus::Active;
    }

    /**
     * Último dia em que a versão valeu: a véspera da vigência da que a
     * substituiu. `null` enquanto vigente, e para rascunho ou cancelada.
     */
    public function effectiveUntil(): ?CarbonImmutable
    {
        if ($this->status !== MeasurementPlanVersionStatus::Superseded) {
            return null;
        }

        $next = $this->relationLoaded('supersededByVersion')
            ? $this->supersededByVersion
            : $this->supersededByVersion()->first();

        return $next?->effective_from === null
            ? null
            : CarbonImmutable::parse($next->effective_from->toDateString())->subDay();
    }

    /**
     * Por que esta versão, fora do rascunho, não muda: já valeu (vigente ou
     * substituída) ou foi cancelada.
     */
    public function immutabilityRefusal(): string
    {
        return sprintf(
            $this->status === MeasurementPlanVersionStatus::Cancelled ? self::CANCELLED_VERSION_REFUSAL : self::IMMUTABLE_HISTORY_REFUSAL,
            $this->label(),
        );
    }

    /**
     * Fundo de Obra em centavos inteiros; `null` quando não informado.
     */
    public function constructionFundCents(): ?int
    {
        return IntegerMoney::cents($this->construction_fund_amount);
    }

    private function guardCreation(): void
    {
        $planSet = MeasurementPlanSet::query()->find($this->plan_set_id);

        if (! $planSet instanceof MeasurementPlanSet) {
            throw new MeasurementWorkflowException('A versão precisa pertencer a um plano de medição existente.');
        }

        if (blank($this->operation_id)) {
            $this->operation_id = $planSet->operation_id;
        }

        if ((int) $this->operation_id !== (int) $planSet->operation_id) {
            throw new MeasurementWorkflowException('A versão precisa pertencer à operação do próprio plano.', [
                'plan_set_id' => $planSet->getKey(),
            ]);
        }

        if ((int) $this->version_number < 1) {
            throw new MeasurementWorkflowException('O número da versão do plano começa em 1.', [
                'plan_set_id' => $planSet->getKey(),
            ]);
        }
    }

    /**
     * Rascunho muda de conteúdo e sai para vigente (com vigência e ativação
     * gravadas) ou cancelado; vigente só vira substituída, gravando apenas a
     * substituição; substituída e cancelada não mudam mais. A identidade da
     * versão nunca muda.
     */
    private function guardUpdate(): void
    {
        $from = $this->statusFromOriginal();
        $to = $this->status;

        if ($this->isDirty(self::IDENTITY_COLUMNS)) {
            throw new MeasurementWorkflowException('A identidade da versão do plano não pode ser alterada.', [
                'plan_version_id' => $this->getKey(),
            ]);
        }

        if ($from !== $to && ! $from->canTransitionTo($to)) {
            throw new MeasurementWorkflowException(sprintf(
                'A versão %s não pode passar de "%s" para "%s".',
                $this->label(),
                $from->label(),
                $to->label(),
            ), ['plan_version_id' => $this->getKey()]);
        }

        $allowed = match (true) {
            $from === MeasurementPlanVersionStatus::Draft => null,
            $from === MeasurementPlanVersionStatus::Active && $to === MeasurementPlanVersionStatus::Superseded => self::SUPERSESSION_COLUMNS,
            default => ['updated_at'],
        };

        if ($allowed !== null && array_diff(array_keys($this->getDirty()), $allowed) !== []) {
            throw new MeasurementWorkflowException(sprintf(
                $from === MeasurementPlanVersionStatus::Cancelled ? self::CANCELLED_VERSION_REFUSAL : self::IMMUTABLE_HISTORY_REFUSAL,
                $this->label(),
            ), [
                'plan_version_id' => $this->getKey(),
                'status' => $from->value,
            ]);
        }

        if ($to === MeasurementPlanVersionStatus::Active
            && ($this->effective_from === null || $this->activated_at === null)) {
            throw new MeasurementWorkflowException('A versão só fica vigente com a data de vigência e o registro da ativação.', [
                'plan_version_id' => $this->getKey(),
            ]);
        }
    }

    /**
     * Versão não se exclui: o número dela é parte do histórico (V2 cancelada,
     * V3 vigente), e apagar a última abriria o número para outra versão. Um
     * plano cadastrado por engano, sem medição, sai inteiro -- e as versões
     * descem com ele pela FK, sem passar por aqui.
     */
    private function guardDeletion(): void
    {
        throw new MeasurementWorkflowException(sprintf('A versão %s do plano não pode ser excluída: o rascunho se cancela, e o plano sem medição se exclui inteiro.', $this->label()), [
            'plan_version_id' => $this->getKey(),
        ]);
    }

    private function statusFromOriginal(): MeasurementPlanVersionStatus
    {
        $original = $this->getRawOriginal('status');

        return MeasurementPlanVersionStatus::tryFrom((string) $original)
            ?? throw new MeasurementWorkflowException('Situação desconhecida na versão do plano.', [
                'plan_version_id' => $this->getKey(),
                'status' => $original,
            ]);
    }
}
