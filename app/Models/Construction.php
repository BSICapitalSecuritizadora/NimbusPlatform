<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Exceptions\SalesBoardSourceException;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\ConstructionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Construction extends Model
{
    /** @use HasFactory<ConstructionFactory> */
    use HasFactory, LogsActivity;

    public const MEASUREMENT_COMPANY_TYPE_NAME = 'Engenharia';

    /**
     * Por que a obra cujo plano de medição já valeu não sai
     * ({@see self::hasMeasurementPlanHistory()}).
     */
    public const MEASUREMENT_PLAN_DELETION_REFUSAL = 'Este empreendimento tem plano de medição já ativado ou com medição registrada e não pode ser removido: as versões do plano e as medições dependem dele.';

    public const STATE_OPTIONS = [
        'AC' => 'AC',
        'AL' => 'AL',
        'AP' => 'AP',
        'AM' => 'AM',
        'BA' => 'BA',
        'CE' => 'CE',
        'DF' => 'DF',
        'ES' => 'ES',
        'GO' => 'GO',
        'MA' => 'MA',
        'MT' => 'MT',
        'MS' => 'MS',
        'MG' => 'MG',
        'PA' => 'PA',
        'PB' => 'PB',
        'PR' => 'PR',
        'PE' => 'PE',
        'PI' => 'PI',
        'RJ' => 'RJ',
        'RN' => 'RN',
        'RS' => 'RS',
        'RO' => 'RO',
        'RR' => 'RR',
        'SC' => 'SC',
        'SP' => 'SP',
        'SE' => 'SE',
        'TO' => 'TO',
    ];

    protected $fillable = [
        'emission_id',
        'development_name',
        'development_trade_name',
        'development_cnpj',
        'city',
        'state',
        'construction_start_date',
        'construction_end_date',
        'estimated_value',
        'measurement_company_id',
    ];

    protected function casts(): array
    {
        return [
            'construction_start_date' => 'date',
            'construction_end_date' => 'date',
            'estimated_value' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $construction): void {
            if ($construction->exists
                && $construction->isDirty(['emission_id', 'development_cnpj'])
                && $construction->isReferencedByApprovedEngineering()) {
                throw new MeasurementWorkflowException('A identidade de um empreendimento aprovado pela Engenharia está bloqueada.');
            }

            /**
             * Os quadros e os ciclos ficam gravados com a Emissão em que foram
             * registrados. Trocar a Emissão da obra depois deles faria a
             * antiga continuar somando o empreendimento e a nova ficar sem
             * posição -- o formulário já trava o campo; isto vale para os
             * outros caminhos.
             */
            if ($construction->exists && $construction->isDirty('emission_id')) {
                $anchors = app(SalesBoardSourceGuard::class)->constructionEmissionAnchors($construction);

                if ($anchors !== []) {
                    throw SalesBoardSourceException::constructionEmissionLocked($anchors);
                }
            }

            $construction->construction_start_date = self::normalizeMonthDate($construction->construction_start_date);
            $construction->construction_end_date = self::normalizeMonthDate($construction->construction_end_date);
        });

        static::deleting(function (self $construction): void {
            if ($construction->isReferencedByApprovedEngineering()) {
                throw new MeasurementWorkflowException('Um empreendimento aprovado pela Engenharia não pode ser removido.');
            }

            // A FK do plano solta a obra (SET NULL) sem passar pela guarda do
            // plano: o plano que já valeu ou já recebeu medição ficaria sem a
            // obra de que as versões e as medições dependem.
            if ($construction->hasMeasurementPlanHistory()) {
                throw new MeasurementWorkflowException(self::MEASUREMENT_PLAN_DELETION_REFUSAL, [
                    'construction_id' => $construction->getKey(),
                ]);
            }

            /**
             * Os quadros de vendas são a única história da obra que o banco não
             * protege: a FK desce em cascata e leva o histórico de versões
             * junto, sem passar pelo observer nem pela trilha de auditoria. O
             * resto -- contratos, ciclos, políticas -- o banco já recusa.
             */
            if ($construction->salesBoards()->exists()) {
                throw SalesBoardSourceException::constructionDeletionBlocked(['tem Quadro de Vendas registrado']);
            }
        });
    }

    /**
     * A troca de Emissão ou de CNPJ -- o que a aprovação da Engenharia congela
     * no snapshot -- de uma obra com plano de medição trava antes, em ordem de
     * id, as operações que a planejam: a ordem canônica do módulo de medição
     * (Operation → planos → obras), a mesma da aprovação, que trava a
     * Operation e só depois a obra. A guarda do `saving` passa a ler o estado
     * posterior ao lock, e uma aprovação não cabe mais entre a conferência e a
     * gravação. As outras escritas da obra não mudam nada que a medição
     * confira e seguem como antes.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->isDirty(['emission_id', 'development_cnpj'])) {
            return parent::save($options);
        }

        return $this->underMeasurementPlanningLock(fn (): bool => parent::save($options));
    }

    /**
     * A exclusão de obra com plano de medição passa pelo mesmo lock: sem ele, a
     * guarda lia "sem Engenharia aprovada" enquanto uma aprovação gravava, e a
     * FK deixava o plano sem obra logo depois.
     */
    public function delete(): ?bool
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return $this->underMeasurementPlanningLock(fn (): ?bool => parent::delete());
    }

    protected function developmentCnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => blank($value) ? null : Str::digitsOnly((string) $value),
        );
    }

    /**
     * The development anchors measurements, units and the Sales Board of its
     * emission; moving it or changing it changes what those read. The trail goes
     * to the protected `constructions` log instead of `default`, which is
     * discarded in one year.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('constructions')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function measurementCompany(): BelongsTo
    {
        return $this->belongsTo(ExpenseServiceProvider::class, 'measurement_company_id');
    }

    public function salesBoards(): HasMany
    {
        return $this->hasMany(SalesBoard::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ConstructionUnit::class);
    }

    /**
     * Append-only history of the maximum commercial discount authorised for the
     * development, newest first.
     */
    public function salesDiscountPolicies(): HasMany
    {
        return $this->hasMany(SalesDiscountPolicy::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    public function measurementPlanSets(): HasMany
    {
        return $this->hasMany(MeasurementPlanSet::class);
    }

    public function getFormattedDevelopmentCnpjAttribute(): string
    {
        return ExpenseServiceProvider::formatCnpj($this->development_cnpj);
    }

    public function getFormattedEstimatedValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->estimated_value);
    }

    public static function normalizeMonthDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->startOfMonth()->toDateString();
        }

        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            $month = (int) $matches[1];
            $year = (int) $matches[2];

            return checkdate($month, 1, $year)
                ? sprintf('%04d-%02d-01', $year, $month)
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];

            return checkdate($month, 1, $year)
                ? sprintf('%04d-%02d-01', $year, $month)
                : null;
        }

        try {
            return Carbon::parse($value)->startOfMonth()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatMonthForDisplay(mixed $value): string
    {
        $monthDate = self::normalizeMonthDate($value);

        if ($monthDate === null) {
            return '';
        }

        return Carbon::parse($monthDate)->format('m/Y');
    }

    /**
     * As operações são lidas antes da transação, e o lock delas é a primeira
     * instrução dela: no MySQL em REPEATABLE READ a fotografia da transação nasce
     * depois do lock. Se o conjunto mudou nesse meio-tempo, a gravação é
     * recusada em vez de seguir sem uma das operações travada.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    private function underMeasurementPlanningLock(Closure $write): mixed
    {
        $operationIds = $this->measurementPlanningOperationIds();

        if ($operationIds === []) {
            return $write();
        }

        return DB::transaction(function () use ($operationIds, $write): mixed {
            Operation::query()->whereKey($operationIds)->orderBy('id')->lockForUpdate()->get(['id']);

            if ($this->measurementPlanningOperationIds() !== $operationIds) {
                throw new MeasurementWorkflowException('Os planos de medição deste empreendimento mudaram durante a gravação. Tente novamente.', [
                    'construction_id' => $this->getKey(),
                ]);
            }

            return $write();
        });
    }

    /**
     * @return list<int>
     */
    private function measurementPlanningOperationIds(): array
    {
        return MeasurementPlanSet::query()
            ->where('construction_id', $this->getKey())
            ->distinct()
            ->orderBy('operation_id')
            ->pluck('operation_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Uma medição do empreendimento já passou pela aprovação da Engenharia?
     *
     * Pública porque a guarda de exclusão da obra precisa dizer este motivo
     * antes de o `deleting` recusar com um erro.
     */
    /**
     * Algum plano de medição da obra já valeu (versão vigente ou substituída)
     * ou já recebeu arquivo ou pagamento? Plano só com o rascunho da V1 ainda é
     * planejamento e solta a obra pela FK, como antes das versões.
     */
    public function hasMeasurementPlanHistory(): bool
    {
        return $this->measurementPlanSets()
            ->where(fn ($plans) => $plans
                ->whereHas('versions', fn ($versions) => $versions->whereIn('status', [
                    MeasurementPlanVersionStatus::Active->value,
                    MeasurementPlanVersionStatus::Superseded->value,
                ]))
                ->orWhereHas('assets')
                ->orWhereHas('payments'))
            ->exists();
    }

    public function isReferencedByApprovedEngineering(): bool
    {
        return $this->measurementPlanSets()
            ->whereHas('assets.measurement.reviews', fn ($reviews) => $reviews
                ->where('stage', 1)
                ->where('status', 'approved'))
            ->exists();
    }
}
