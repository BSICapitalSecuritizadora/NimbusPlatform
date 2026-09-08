<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use App\Enums\ConstructionUnitExchangeKind;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonInterface;
use Database\Factories\ConstructionUnitExchangeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Permuta de uma unidade, com vigência própria.
 *
 * Fonte temporal da classificação "permutado". O status do contrato não serve
 * para isso -- ver o comentário da migration.
 *
 * Vigência semiaberta `[effective_from, ended_on)`: a permuta encerrada no dia D
 * não vale em D, do mesmo jeito que o distrato de D libera a unidade em D.
 */
class ConstructionUnitExchange extends Model
{
    /** @use HasFactory<ConstructionUnitExchangeFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'construction_unit_id',
        'contract_id',
        'exchange_value',
        'effective_from',
        'ended_on',
        'kind',
        'reason',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'exchange_value' => 'decimal:2',
            'effective_from' => 'date',
            'ended_on' => 'date',
            'kind' => ConstructionUnitExchangeKind::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function constructionUnit(): BelongsTo
    {
        return $this->belongsTo(ConstructionUnit::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Vigente na data: começou até ela e não terminou antes ou nela.
     *
     * Decidido em PHP sobre datas já convertidas, e não em SQL, porque é a
     * comparação que a derivação faz para milhares de linhas já carregadas.
     */
    public function isEffectiveOn(CarbonInterface $date): bool
    {
        $day = $date->toDateString();
        $start = $this->effective_from?->toDateString();

        if (($start === null) || ($start > $day)) {
            return false;
        }

        $end = $this->ended_on?->toDateString();

        return ($end === null) || ($end > $day);
    }

    /**
     * Permutas vigentes na data.
     *
     * O limite superior vem de {@see InclusiveDateBound}: uma coluna `date`
     * gravada pelo Eloquent guarda `"2026-07-01 00:00:00"` no SQLite e compara
     * como texto, então `<= '2026-07-01'` perderia a linha que passa a valer no
     * próprio dia consultado.
     *
     * @param  Builder<ConstructionUnitExchange>  $query
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $date): void
    {
        $bound = InclusiveDateBound::upperBound($date);

        $query
            ->where('effective_from', '<=', $bound)
            ->where(function (Builder $query) use ($bound): void {
                $query->whereNull('ended_on')->orWhere('ended_on', '>', $bound);
            });
    }

    public function getFormattedExchangeValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->exchange_value);
    }
}
