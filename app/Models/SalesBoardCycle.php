<?php

namespace App\Models;

use App\Enums\SalesBoardCycleStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SalesBoardCycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * O ciclo mensal do Quadro de Vendas de um empreendimento.
 *
 * Raiz histórica protegida: nunca é apagado, e as versões que pendem dele
 * também não. O único campo que muda depois da criação é o ponteiro para a
 * versão vigente.
 *
 * Não confundir com {@see SalesBoard}, que continua sendo a posição publicada. O
 * ciclo é o que foi *apurado*, com todo o caminho até chegar lá.
 */
class SalesBoardCycle extends Model
{
    /** @use HasFactory<SalesBoardCycleFactory> */
    use HasFactory, LogsActivity;

    /**
     * O que pode mudar depois de o ciclo nascer.
     *
     * `updated_at` entra porque o Eloquent toca o timestamp em qualquer save --
     * sem ele o guard bloquearia a própria troca de versão vigente.
     *
     * @var list<string>
     */
    public const MUTABLE_FIELDS = [
        'current_baseline_id',
        'status',
        'updated_at',
    ];

    protected $fillable = [
        'emission_id',
        'construction_id',
        'reference_month',
        'position_date',
        'status',
        'current_baseline_id',
        'created_by_id',
    ];

    /**
     * Um ciclo é registro financeiro: não se corrige apagando. O encerramento de
     * um ciclo que não deve seguir adiante é o estado `Cancelled`, das fases
     * seguintes -- e mesmo ele preserva tudo o que foi apurado.
     */
    protected static function booted(): void
    {
        static::updating(function (self $cycle): void {
            if (array_diff(array_keys($cycle->getDirty()), self::MUTABLE_FIELDS) !== []) {
                throw new LogicException('A sales board cycle identity is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Sales board cycles cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'immutable_date',
            'position_date' => 'immutable_date',
            'status' => SalesBoardCycleStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Versões do ciclo, da mais recente para a mais antiga.
     */
    public function baselines(): HasMany
    {
        return $this->hasMany(SalesBoardCycleBaseline::class)->orderByDesc('version');
    }

    public function currentBaseline(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycleBaseline::class, 'current_baseline_id');
    }

    /**
     * As unidades congeladas na versão vigente.
     *
     * A chave local é o ponteiro da versão, e não a chave do ciclo: quando o
     * recálculo move o ponteiro, a listagem passa a mostrar a versão nova sem
     * que nenhuma tela precise saber que isso aconteceu -- e as versões
     * anteriores continuam inteiras, alcançáveis pelo histórico.
     */
    public function currentLines(): HasMany
    {
        return $this->hasMany(SalesBoardCycleLine::class, 'sales_board_cycle_baseline_id', 'current_baseline_id');
    }

    public function currentMovements(): HasMany
    {
        return $this->hasMany(SalesBoardCycleMovement::class, 'sales_board_cycle_baseline_id', 'current_baseline_id');
    }

    /**
     * As validações da construtora, da mais recente para a mais antiga.
     */
    public function builderReviews(): HasMany
    {
        return $this->hasMany(SalesBoardBuilderReview::class, 'sales_board_cycle_id')
            ->orderByDesc('attempt');
    }

    /**
     * Competência normalizada, sempre no primeiro dia do mês.
     */
    public static function normalizeReferenceMonth(mixed $referenceMonth): CarbonImmutable
    {
        return CarbonImmutable::parse((string) (
            $referenceMonth instanceof \DateTimeInterface
                ? $referenceMonth->format('Y-m-d')
                : $referenceMonth
        ))->startOfMonth();
    }

    public function referenceMonthLabel(): string
    {
        return $this->reference_month?->format('m/Y') ?? '—';
    }
}
