<?php

namespace App\Models;

use App\Exceptions\MeasurementWorkflowException;
use Database\Factories\MeasurementPlanLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MeasurementPlanLine extends Model
{
    /** @use HasFactory<MeasurementPlanLineFactory> */
    use HasFactory, LogsActivity;

    public const TREND_AHEAD = 'Acima';

    public const TREND_ON_TRACK = 'Na média';

    public const TREND_BEHIND = 'Abaixo';

    protected $fillable = [
        'plan_set_id',
        'operation_id',
        'sequence_number',
        'planned_monthly_percent',
        'planned_cumulative_percent',
        'initial_realized_cumulative_percent',
        'realized_monthly_percent',
        'realized_cumulative_percent',
        'evolution_diff_percent',
        'evolution_trend',
        'measurement_date',
        'measurement_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if ($line->exists
                && $line->isDirty()
                && $line->assets()->whereHas('measurement.reviews', fn ($reviews) => $reviews
                    ->where('stage', 1)
                    ->where('status', 'approved'))->exists()) {
                throw new MeasurementWorkflowException('A linha de cronograma usada por uma Engenharia aprovada está bloqueada.');
            }

            if (blank($line->operation_id) && filled($line->plan_set_id)) {
                $line->operation_id = MeasurementPlanSet::whereKey($line->plan_set_id)->value('operation_id');
            }

            $diff = round((float) $line->realized_cumulative_percent - (float) $line->planned_cumulative_percent, 2);
            $line->evolution_diff_percent = $diff;
            $line->evolution_trend = self::resolveTrend($diff);
        });

        static::deleting(function (self $line): void {
            if ($line->assets()->whereHas('measurement.reviews', fn ($reviews) => $reviews
                ->where('stage', 1)
                ->where('status', 'approved'))->exists()) {
                throw new MeasurementWorkflowException('A linha de cronograma usada por uma Engenharia aprovada não pode ser removida.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'planned_monthly_percent' => 'decimal:2',
            'planned_cumulative_percent' => 'decimal:2',
            'initial_realized_cumulative_percent' => 'decimal:2',
            'realized_monthly_percent' => 'decimal:2',
            'realized_cumulative_percent' => 'decimal:2',
            'evolution_diff_percent' => 'decimal:2',
            'measurement_date' => 'date',
        ];
    }

    /**
     * A linha guarda o previsto, o que a Engenharia gravou em cada aprovação e o
     * "Realiz. inicial" que originou o avanço físico inicial dos planos antigos.
     * Em `default` essa trilha seria descartada em um ano; em `measurements`,
     * categoria protegida, acompanha o prazo das demais evidências da medição.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('measurements')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MeasurementAsset::class, 'plan_line_id');
    }

    /**
     * Linha do cronograma que ainda pode receber o arquivo de uma medição.
     *
     * `measurement_id` e `realized_*` são o retrato da última aprovação da
     * Engenharia gravada na linha, e nada os desfaz quando ela deixa de valer
     * -- devolução, recusa, exclusão, troca de linha. Decidir por essas colunas
     * consumia a competência para sempre. A decisão é por quem ainda está de pé:
     *
     * - reivindicada: arquivo de medição com a Engenharia vigente (revisão da
     *   etapa 1 aprovada, o mesmo predicado das guardas do módulo) ou, nas
     *   aprovações anteriores ao snapshot, a própria linha gravada por uma
     *   medição vigente sem snapshot -- é o único registro do que ela aprovou;
     * - ocupada: arquivo de outra medição aberta ou finalizada;
     * - presa a pagamento: arquivo de outra medição que tenha pagamento, mesmo
     *   recusada (estado legado). Reapresentar a competência abriria caminho
     *   para pagar duas vezes o mesmo avanço.
     *
     * Nada é anulado: a linha e o arquivo da medição invalidada continuam como
     * histórico. `$measurementId` é a medição em edição: os arquivos dela não
     * tornam a linha indisponível para ela mesma.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailableForMeasurement(Builder $query, ?int $measurementId = null): Builder
    {
        $currentEngineering = fn (Builder $reviews): Builder => $reviews
            ->where($reviews->qualifyColumn('stage'), 1)
            ->where($reviews->qualifyColumn('status'), 'approved');

        return $query
            ->whereDoesntHave('assets', fn (Builder $assets): Builder => $assets
                ->when($measurementId !== null, fn (Builder $others): Builder => $others
                    ->where($assets->qualifyColumn('measurement_id'), '!=', $measurementId))
                ->whereHas('measurement', fn (Builder $measurements): Builder => $measurements->where(
                    fn (Builder $holding): Builder => $holding
                        ->whereIn($measurements->qualifyColumn('status'), [...Measurement::OPEN_STATUSES, 'finalized'])
                        ->orWhereHas('reviews', $currentEngineering)
                        ->orWhereHas('payments'),
                )))
            ->whereDoesntHave('measurement', fn (Builder $measurements): Builder => $measurements
                ->whereNull($measurements->qualifyColumn('engineering_snapshot'))
                ->whereHas('reviews', $currentEngineering));
    }

    public static function resolveTrend(float $diff): string
    {
        return match (true) {
            $diff > 0.0 => self::TREND_AHEAD,
            $diff < 0.0 => self::TREND_BEHIND,
            default => self::TREND_ON_TRACK,
        };
    }
}
