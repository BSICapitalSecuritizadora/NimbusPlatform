<?php

namespace App\Models;

use App\Services\SalesBoards\ConstructionUnitRetirementService;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonInterface;
use Database\Factories\ConstructionUnitRetirementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Baixa de uma unidade: a partir da data, ela deixa de compor o Quadro de
 * Vendas.
 *
 * Fato datado da Gestão, e não estado da unidade. Vigência semiaberta
 * `[retired_on, reactivated_on)`, como a da permuta: a unidade reativada no dia
 * D volta a compor o Quadro em D. Reativar na própria data da baixa anula a
 * baixa -- o período fica vazio e não vale em competência nenhuma --, e é assim
 * que uma baixa lançada por engano se desfaz sem apagar a trilha.
 *
 * Append-only: nada se edita nem se exclui. A única escrita depois do registro é
 * a reativação, uma vez. Quem decide se a baixa e a reativação podem acontecer
 * é o {@see ConstructionUnitRetirementService}; o model só recusa o que nenhum
 * caminho pode fazer.
 */
class ConstructionUnitRetirement extends Model
{
    /** @use HasFactory<ConstructionUnitRetirementFactory> */
    use HasFactory, LogsActivity;

    /**
     * O que a reativação grava. `updated_at` entra porque o Eloquent toca o
     * carimbo em qualquer save.
     *
     * @var list<string>
     */
    public const REACTIVATION_FIELDS = [
        'reactivated_on',
        'reactivated_at',
        'reactivated_by_id',
        'reactivation_reason',
        'updated_at',
    ];

    protected $fillable = [
        'construction_unit_id',
        'retired_on',
        'reason',
        'retired_by_id',
        'reactivated_on',
        'reactivated_at',
        'reactivated_by_id',
        'reactivation_reason',
    ];

    protected function casts(): array
    {
        return [
            'retired_on' => 'date',
            'reactivated_on' => 'date',
            'reactivated_at' => 'datetime',
        ];
    }

    /**
     * A baixa decidiu competências: mudar a data, a unidade ou o motivo depois
     * reescreveria o que já foi apurado com ela, e apagá-la faria a unidade
     * voltar em silêncio a todas as competências do período. A reativação é
     * gravada uma vez -- a segunda trocaria o fim de um período que já pode ter
     * decidido uma competência.
     */
    protected static function booted(): void
    {
        static::updating(function (self $retirement): void {
            if (array_diff(array_keys($retirement->getDirty()), self::REACTIVATION_FIELDS) !== []) {
                throw new LogicException('Uma baixa de unidade não é editada: só a reativação é gravada depois do registro.');
            }

            if ($retirement->getOriginal('reactivated_on') !== null) {
                throw new LogicException('A reativação de uma baixa de unidade é gravada uma única vez.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Baixas de unidade não são excluídas.');
        });
    }

    /**
     * A baixa decide se a unidade compõe o Quadro de Vendas. A trilha grava em
     * `construction_unit_retirements`, categoria protegida, e não em `default`,
     * que é descartado em um ano.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('construction_unit_retirements')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function constructionUnit(): BelongsTo
    {
        return $this->belongsTo(ConstructionUnit::class);
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by_id');
    }

    public function reactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reactivated_by_id');
    }

    /**
     * Sem reativação gravada. Como nem a baixa nem a reativação aceitam data
     * futura, a baixa aberta é a que vale hoje.
     */
    public function isOpen(): bool
    {
        return $this->reactivated_on === null;
    }

    /**
     * Reativada na própria data da baixa: o período é vazio.
     */
    public function isAnnulled(): bool
    {
        return ($this->reactivated_on !== null)
            && ($this->reactivated_on->toDateString() <= $this->retired_on?->toDateString());
    }

    /**
     * Vale na data: começou até ela e não foi reativada antes ou nela.
     *
     * Decidido em PHP sobre datas já convertidas, como na permuta, porque é a
     * comparação que a derivação faz para as baixas já carregadas.
     */
    public function isEffectiveOn(CarbonInterface $date): bool
    {
        $day = $date->toDateString();
        $start = $this->retired_on?->toDateString();

        if (($start === null) || ($start > $day)) {
            return false;
        }

        $end = $this->reactivated_on?->toDateString();

        return ($end === null) || ($end > $day);
    }

    /**
     * Baixas vigentes na data.
     *
     * O limite superior vem de {@see InclusiveDateBound}: uma coluna `date`
     * gravada pelo Eloquent guarda `"2026-07-01 00:00:00"` no SQLite e compara
     * como texto, então `<= '2026-07-01'` perderia a baixa que passa a valer no
     * próprio dia consultado.
     *
     * @param  Builder<ConstructionUnitRetirement>  $query
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $date): void
    {
        $bound = InclusiveDateBound::upperBound($date);

        $query
            ->where('retired_on', '<=', $bound)
            ->where(function (Builder $query) use ($bound): void {
                $query->whereNull('reactivated_on')->orWhere('reactivated_on', '>', $bound);
            });
    }
}
