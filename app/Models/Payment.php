<?php

namespace App\Models;

use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Linha do Cronograma de Pagamentos INFORMADO de uma emissão: a data e os valores
 * que a planilha ou o cadastro manual trouxeram. É previsão informada -- não é o
 * valor que a curva oficial calcula nem o que foi liquidado.
 *
 * Desde a Fase 5 nada escreve o cálculo da curva aqui: o valor esperado oficial
 * vive nas obrigações financeiras ({@see EmissionPuObligation}, com o histórico
 * de cálculos por versão) e a liquidação no livro de liquidações
 * ({@see EmissionPuSettlement}). As colunas da projeção antiga (`value_source`,
 * `expected_*`, `pu_curve_version_id`, `calculated_at`) continuam no schema, sem
 * escritor. Do cronograma informado as obrigações só aproveitam o que a curva não
 * calcula (o prêmio); mudar uma linha refaz as obrigações da emissão depois do
 * commit.
 */
class Payment extends Model
{
    use LogsActivity;

    /** @var list<string> */
    public const VALUE_FIELDS = [
        'premium_value',
        'interest_value',
        'amortization_value',
        'extra_amortization_value',
    ];

    protected $fillable = [
        'emission_id',
        'payment_date',
        'premium_value',
        'interest_value',
        'amortization_value',
        'extra_amortization_value',
    ];

    protected static function booted(): void
    {
        $refreshObligations = function (self $payment): void {
            if ($payment->emission_id !== null) {
                app(PuFinancialObligationService::class)->refreshAfterCommit((int) $payment->emission_id, 'informed_schedule_changed');
            }
        };

        static::saved($refreshObligations);
        static::deleted($refreshObligations);
    }

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'premium_value' => 'decimal:2',
            'interest_value' => 'decimal:2',
            'amortization_value' => 'decimal:2',
            'extra_amortization_value' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}
