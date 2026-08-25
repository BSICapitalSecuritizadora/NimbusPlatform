<?php

namespace App\Models;

use App\Exceptions\MeasurementWorkflowException;
use App\Services\OperationContextVisibilityService;
use Database\Factories\MeasurementPlanSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MeasurementPlanSet extends Model
{
    /** @use HasFactory<MeasurementPlanSetFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'operation_id',
        'construction_id',
        'name',
        'is_default',
        'construction_fund_amount',
        'initial_incurred_amount',
    ];

    protected static function booted(): void
    {
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
        });
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'construction_fund_amount' => 'decimal:2',
            'initial_incurred_amount' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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
