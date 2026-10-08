<?php

namespace App\Models;

use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use Database\Factories\MeasurementPlanLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MeasurementPlanLine extends Model
{
    /** @use HasFactory<MeasurementPlanLineFactory> */
    use HasFactory, LogsActivity;

    public const TREND_AHEAD = 'Acima';

    public const TREND_ON_TRACK = 'Na média';

    public const TREND_BEHIND = 'Abaixo';

    /**
     * O previsto da linha: muda só enquanto a versão é rascunho. Vigente e
     * substituída são histórico.
     *
     * @var list<string>
     */
    public const PLANNED_COLUMNS = [
        'sequence_number',
        'planned_monthly_percent',
        'planned_cumulative_percent',
        'initial_realized_cumulative_percent',
        'measurement_date',
    ];

    /**
     * Onde a linha está e quem ela é através das versões: nunca muda.
     *
     * @var list<string>
     */
    private const IDENTITY_COLUMNS = [
        'plan_version_id',
        'plan_set_id',
        'operation_id',
        'lineage_key',
    ];

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
        static::creating(function (self $line): void {
            $line->placeInDraftVersion();
        });

        static::updating(function (self $line): void {
            $line->guardHistory();
        });

        static::saving(function (self $line): void {
            if ($line->exists
                && $line->isDirty()
                && $line->assets()->whereHas('measurement.reviews', fn ($reviews) => $reviews
                    ->where('stage', 1)
                    ->where('status', 'approved'))->exists()) {
                throw new MeasurementWorkflowException('A linha de cronograma usada por uma Engenharia aprovada está bloqueada.');
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

            $status = $line->versionStatus();

            if ($status !== MeasurementPlanVersionStatus::Draft) {
                throw new MeasurementWorkflowException(sprintf(
                    $status === MeasurementPlanVersionStatus::Cancelled
                        ? 'A linha %s pertence a uma revisão cancelada do plano e não muda mais.'
                        : 'A linha %s pertence a uma versão do plano que já valeu e não pode ser removida: crie uma revisão do plano.',
                    str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT),
                ), ['plan_line_id' => $line->getKey()]);
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

    public function version(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanVersion::class, 'plan_version_id');
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
     * A ocupação vale para a linhagem, não só para a linha: a revisão do plano
     * copia a medição prevista com a mesma `lineage_key`, e a cópia da V2 de
     * uma linha que a V1 já mediu continua ocupada. Além do predicado, conta a
     * ocupação registrada no arquivo (`measurement_assets.line_claim_key`),
     * que o banco mantém única.
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
        $lines = $query->getModel()->getTable();
        $currentEngineering = fn (QueryBuilder $reviews, string $measurements): QueryBuilder => $reviews
            ->from('measurement_reviews')
            ->whereColumn('measurement_reviews.measurement_id', "{$measurements}.id")
            ->where('measurement_reviews.stage', 1)
            ->where('measurement_reviews.status', 'approved');

        return $query
            ->whereNotExists(fn (QueryBuilder $claims): QueryBuilder => $claims
                ->from('measurement_assets as claim_assets')
                ->whereColumn('claim_assets.line_claim_key', "{$lines}.lineage_key")
                ->when($measurementId !== null, fn (QueryBuilder $others): QueryBuilder => $others
                    ->where('claim_assets.measurement_id', '!=', $measurementId)))
            ->whereNotExists(fn (QueryBuilder $held): QueryBuilder => $held
                ->from('measurement_plan_lines as lineage_lines')
                ->join('measurement_assets as lineage_assets', 'lineage_assets.plan_line_id', '=', 'lineage_lines.id')
                ->join('measurements as lineage_measurements', 'lineage_measurements.id', '=', 'lineage_assets.measurement_id')
                ->whereColumn('lineage_lines.plan_set_id', "{$lines}.plan_set_id")
                ->whereColumn('lineage_lines.lineage_key', "{$lines}.lineage_key")
                ->when($measurementId !== null, fn (QueryBuilder $others): QueryBuilder => $others
                    ->where('lineage_assets.measurement_id', '!=', $measurementId))
                ->where(fn (QueryBuilder $holding): QueryBuilder => $holding
                    ->whereIn('lineage_measurements.status', [...Measurement::OPEN_STATUSES, 'finalized'])
                    ->orWhereExists(fn (QueryBuilder $reviews): QueryBuilder => $currentEngineering($reviews, 'lineage_measurements'))
                    ->orWhereExists(fn (QueryBuilder $payments): QueryBuilder => $payments
                        ->from('measurement_payments')
                        ->whereColumn('measurement_payments.measurement_id', 'lineage_measurements.id'))))
            ->whereNotExists(fn (QueryBuilder $legacy): QueryBuilder => $legacy
                ->from('measurement_plan_lines as legacy_lines')
                ->join('measurements as legacy_measurements', 'legacy_measurements.id', '=', 'legacy_lines.measurement_id')
                ->whereColumn('legacy_lines.plan_set_id', "{$lines}.plan_set_id")
                ->whereColumn('legacy_lines.lineage_key', "{$lines}.lineage_key")
                ->whereNull('legacy_measurements.engineering_snapshot')
                ->whereExists(fn (QueryBuilder $reviews): QueryBuilder => $currentEngineering($reviews, 'legacy_measurements')));
    }

    /**
     * Linhas da versão vigente de cada plano: o cronograma em vigor. Pela
     * regra de ativação, as competências anteriores à vigência dela são cópia
     * fiel das versões que as governaram.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfActiveVersions(Builder $query): Builder
    {
        return $query->whereHas('version', fn (Builder $versions): Builder => $versions
            ->where($versions->qualifyColumn('status'), MeasurementPlanVersionStatus::Active->value));
    }

    /**
     * Linhas -- desta ou de outra versão do plano -- que são a mesma medição
     * prevista desta.
     *
     * @return list<int>
     */
    public function lineageLineIds(): array
    {
        return self::query()
            ->where('plan_set_id', $this->plan_set_id)
            ->where('lineage_key', $this->lineage_key)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Situação da versão da linha, lida com lock compartilhado: a leitura
     * espera a ativação em curso e vê o que ela gravou, em vez da fotografia
     * anterior a ela.
     */
    public function versionStatus(): MeasurementPlanVersionStatus
    {
        $status = MeasurementPlanVersion::query()->whereKey($this->plan_version_id)->sharedLock()->value('status');

        return $status instanceof MeasurementPlanVersionStatus
            ? $status
            : (MeasurementPlanVersionStatus::tryFrom((string) $status)
                ?? throw new MeasurementWorkflowException('A linha do cronograma não pertence a uma versão conhecida do plano.', [
                    'plan_line_id' => $this->getKey(),
                ]));
    }

    /**
     * A linha nova entra num rascunho: o informado ou, sem ele, o rascunho do
     * plano. Plano, operação e linhagem vêm da versão; a linhagem é nova,
     * salvo na cópia feita pela revisão, que a preserva.
     */
    private function placeInDraftVersion(): void
    {
        $version = filled($this->plan_version_id)
            ? MeasurementPlanVersion::query()->whereKey($this->plan_version_id)->sharedLock()->first()
            : MeasurementPlanVersion::query()->where('plan_set_id', $this->plan_set_id)->draft()->sharedLock()->first();

        if (! $version instanceof MeasurementPlanVersion) {
            throw new MeasurementWorkflowException('O cronograma só recebe medição prevista nova num rascunho do plano: crie uma revisão do plano para alterá-lo.', [
                'plan_set_id' => $this->plan_set_id,
            ]);
        }

        if (! $version->isDraft()) {
            throw new MeasurementWorkflowException($version->immutabilityRefusal(), [
                'plan_version_id' => $version->getKey(),
            ]);
        }

        if (filled($this->plan_set_id) && (int) $this->plan_set_id !== (int) $version->plan_set_id) {
            throw new MeasurementWorkflowException('A medição prevista precisa pertencer ao plano da própria versão.', [
                'plan_set_id' => $this->plan_set_id,
                'plan_version_id' => $version->getKey(),
            ]);
        }

        $this->plan_version_id = $version->getKey();
        $this->plan_set_id = $version->plan_set_id;
        $this->operation_id = $version->operation_id;
        $this->lineage_key = filled($this->lineage_key) ? $this->lineage_key : (string) Str::ulid();
    }

    /**
     * A linha não muda de versão, de plano nem de linhagem; o previsto só muda
     * no rascunho. O realizado gravado pela aprovação da Engenharia continua
     * gravável em qualquer versão: é execução, não planejamento.
     */
    private function guardHistory(): void
    {
        if ($this->isDirty(self::IDENTITY_COLUMNS)) {
            throw new MeasurementWorkflowException('A medição prevista não muda de versão, de plano nem de identidade.', [
                'plan_line_id' => $this->getKey(),
            ]);
        }

        if ($this->isDirty(self::PLANNED_COLUMNS) && $this->versionStatus() !== MeasurementPlanVersionStatus::Draft) {
            $version = MeasurementPlanVersion::query()->find($this->plan_version_id);

            throw new MeasurementWorkflowException($version?->immutabilityRefusal() ?? sprintf(MeasurementPlanVersion::IMMUTABLE_HISTORY_REFUSAL, ''), [
                'plan_line_id' => $this->getKey(),
                'plan_version_id' => $this->plan_version_id,
            ]);
        }
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
