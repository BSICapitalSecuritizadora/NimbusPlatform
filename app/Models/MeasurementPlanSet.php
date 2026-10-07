<?php

namespace App\Models;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\OperationContextVisibilityService;
use App\Support\BusinessTime;
use Database\Factories\MeasurementPlanSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
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

    protected $fillable = [
        'operation_id',
        'construction_id',
        'name',
        'is_default',
        'construction_fund_amount',
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

        static::updating(function (self $planSet): void {
            $planSet->guardInitialPhysicalProgressImmutability();
        });

        static::saving(function (self $planSet): void {
            if ($planSet->exists
                && $planSet->isDirty([
                    'operation_id',
                    'construction_id',
                    'construction_fund_amount',
                    'initial_incurred_amount',
                ])
                && $planSet->isReferencedByApprovedEngineering()) {
                throw new MeasurementWorkflowException('O contexto de um plano aprovado pela Engenharia está bloqueado.');
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
        });
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'construction_fund_amount' => 'decimal:2',
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

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MeasurementPlanLine::class, 'plan_set_id');
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

    public function getIncurredAmountAttribute(): float
    {
        $paymentsSum = (float) $this->payments()->sum('amount');

        return (float) $this->initial_incurred_amount + $paymentsSum;
    }

    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->construction_fund_amount - $this->incurred_amount;
    }

    public function getUsedPercentageAttribute(): float
    {
        if ((float) $this->construction_fund_amount <= 0) {
            return 0;
        }

        return ($this->incurred_amount / (float) $this->construction_fund_amount) * 100;
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
