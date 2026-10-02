<?php

namespace App\Models;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRectificationStatus;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use Carbon\CarbonImmutable;
use Database\Factories\SalesBoardCycleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * O ciclo mensal do Quadro de Vendas de um empreendimento.
 *
 * Raiz histórica protegida: nunca é apagado, e as versões que pendem dele
 * também não. Depois da criação só mudam o ponteiro para a versão vigente, a
 * situação e a trilha do cancelamento e da reabertura.
 *
 * O cancelamento tem volta: "Reabrir competência" devolve o mesmo ciclo a
 * "Gerado", e as colunas de cancelamento ficam como registro do último
 * cancelamento, ao lado das da reabertura.
 *
 * Competência publicada é a que tem publicação ({@see self::hasPublication()}),
 * nunca o status Aprovado: a retificação devolve o ciclo publicado a "Gerado"
 * enquanto a posição publicada continua valendo. "Em retificação" também não é
 * status -- é a retificação aberta ({@see self::openRectification()}).
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
     * sem ele o guard bloquearia a própria troca de versão vigente. Os campos
     * de cancelamento só são gravados junto com o status `Cancelled`, e os de
     * reabertura junto com a volta a `Generated`.
     *
     * @var list<string>
     */
    public const MUTABLE_FIELDS = [
        'current_baseline_id',
        'status',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
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
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
    ];

    /**
     * Um ciclo é registro financeiro: não se corrige apagando. O encerramento de
     * um ciclo que não deve seguir adiante é o estado `Cancelled` -- e mesmo ele
     * preserva tudo o que foi apurado.
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
            'cancelled_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    /**
     * Toda a governança do Quadro grava em `sales_board`, a categoria que o
     * `audit:clean-filtered` retém por sete anos. No balde `default` ela seria
     * descartada em um ano.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_board')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
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
     * As publicações do ciclo, da primeira à mais recente.
     *
     * Uma por aprovação: a primeira publicação da competência e uma a cada
     * retificação aprovada, encadeadas por `supersedes_publication_id`. Nenhuma
     * é apagada nem reescrita.
     */
    public function publications(): HasMany
    {
        return $this->hasMany(SalesBoardPublication::class, 'sales_board_cycle_id')->orderBy('sequence_number');
    }

    /**
     * A publicação vigente: a de maior sequência. É a posição que Quadro,
     * leitor, garantias, relatório e a âncora da competência seguinte enxergam,
     * inclusive enquanto uma retificação está em andamento.
     */
    public function currentPublication(): HasOne
    {
        return $this->hasOne(SalesBoardPublication::class, 'sales_board_cycle_id')->latestOfMany('sequence_number');
    }

    /**
     * Os pedidos de retificação, do mais recente para o mais antigo.
     */
    public function rectifications(): HasMany
    {
        return $this->hasMany(SalesBoardCycleRectification::class, 'sales_board_cycle_id')->orderByDesc('sequence_number');
    }

    /**
     * A retificação em andamento, se houver -- no máximo uma, pela unique da
     * coluna gerada.
     */
    public function openRectification(): HasOne
    {
        return $this->hasOne(SalesBoardCycleRectification::class, 'sales_board_cycle_id')
            ->where('status', SalesBoardRectificationStatus::Open->value);
    }

    /**
     * Só os ciclos com posição publicada, em qualquer sequência.
     *
     * @param  Builder<SalesBoardCycle>  $query
     */
    public function scopeWithPublication(Builder $query): void
    {
        $cycles = $this->getTable();
        $publications = (new SalesBoardPublication)->getTable();

        $query->whereExists(function (QueryBuilder $exists) use ($cycles, $publications): void {
            $exists->selectRaw('1')
                ->from($publications)
                ->whereColumn("{$publications}.sales_board_cycle_id", "{$cycles}.id");
        });
    }

    /**
     * A competência publicada mais recente da obra. A regra é a da fronteira
     * única ({@see PublishedCompetenceBoundary}); este atalho só delega.
     */
    public static function lastPublishedMonthFor(int $constructionId): ?CarbonImmutable
    {
        return PublishedCompetenceBoundary::lastPublishedMonth($constructionId);
    }

    public function hasPublication(): bool
    {
        if ($this->relationLoaded('currentPublication')) {
            return $this->currentPublication !== null;
        }

        return $this->publications()->exists();
    }

    /**
     * A competência publicada está sendo retificada.
     */
    public function isUnderRectification(): bool
    {
        if ($this->relationLoaded('openRectification')) {
            return $this->openRectification !== null;
        }

        return $this->openRectification()->exists();
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
