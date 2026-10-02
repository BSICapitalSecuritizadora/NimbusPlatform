<?php

namespace App\Models;

use App\DTOs\SalesBoards\SalesBoardChainStructure;
use App\Enums\SalesBoardStaleImpact;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardFrozenWarnings;
use Database\Factories\SalesBoardCycleBaselineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Uma versão congelada da posição de um ciclo.
 *
 * O conteúdo apurado é imutável; só os metadados de obsolescência mudam. Essa
 * separação é o coração da fase: a fonte viva pode mudar quando quiser, e a V1
 * continua dizendo exatamente o que o Nimbus calculou no dia em que calculou.
 * O que a detecção de mudanças escreve aqui não é a apuração -- é a relação
 * entre ela e o mundo depois dela.
 */
class SalesBoardCycleBaseline extends Model
{
    /** @use HasFactory<SalesBoardCycleBaselineFactory> */
    use HasFactory;

    /**
     * Os únicos campos graváveis depois de a versão nascer.
     *
     * @var list<string>
     */
    public const STALE_MUTABLE_FIELDS = [
        'is_stale',
        'stale_impact',
        'stale_detected_at',
        'last_checked_at',
        'last_observed_source_fingerprint',
        'last_observed_snapshot_fingerprint',
        'updated_at',
    ];

    protected $fillable = [
        'sales_board_cycle_id',
        'version',
        'units_total',
        'stock_units',
        'stock_value',
        'financed_units',
        'financed_value',
        'settled_units',
        'settled_value',
        'exchanged_units',
        'exchanged_value',
        'undetermined_units',
        'is_complete',
        'warnings',
        'previous_competence_baseline_id',
        'absorbed_cancelled_months',
        'source_fingerprint',
        'snapshot_fingerprint',
        'computed_at',
        'computed_by_id',
        'reason',
        'is_stale',
        'stale_impact',
        'stale_detected_at',
        'last_checked_at',
        'last_observed_source_fingerprint',
        'last_observed_snapshot_fingerprint',
    ];

    /**
     * Recalcular cria uma versão nova; nunca reescreve uma existente. Sem este
     * guard bastaria um `save()` distraído para uma posição já conferida virar
     * outra coisa sem deixar rastro -- e o histórico de versões perderia o
     * sentido de existir.
     */
    protected static function booted(): void
    {
        static::updating(function (self $baseline): void {
            if (array_diff(array_keys($baseline->getDirty()), self::STALE_MUTABLE_FIELDS) !== []) {
                throw new LogicException('A persisted sales board baseline is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Sales board baselines cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'units_total' => 'integer',
            'stock_units' => 'integer',
            'stock_value' => 'decimal:2',
            'financed_units' => 'integer',
            'financed_value' => 'decimal:2',
            'settled_units' => 'integer',
            'settled_value' => 'decimal:2',
            'exchanged_units' => 'integer',
            'exchanged_value' => 'decimal:2',
            'undetermined_units' => 'integer',
            'is_complete' => 'boolean',
            'warnings' => 'array',
            'absorbed_cancelled_months' => 'array',
            'computed_at' => 'immutable_datetime',
            'is_stale' => 'boolean',
            'stale_impact' => SalesBoardStaleImpact::class,
            'stale_detected_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycle::class, 'sales_board_cycle_id');
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by_id');
    }

    /**
     * A versão da competência anterior contra a qual os movimentos
     * extemporâneos desta versão foram apurados: a da publicação vigente dela
     * naquele momento, ou a versão vigente se ela ainda não tinha sido
     * publicada.
     *
     * Metadado de auditoria, gravado só na criação e fora do fingerprint -- a
     * coluna fica fora de {@see self::STALE_MUTABLE_FIELDS}. `null` quando a
     * versão é anterior a este registro ou não havia competência anterior.
     */
    public function previousCompetenceBaseline(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_competence_baseline_id');
    }

    /**
     * As competências canceladas cujos fatos esta versão absorveu (`Y-m`, do
     * mês mais recente para o mais antigo): com âncora, as puladas entre ela e
     * esta competência; sem âncora, as que esta competência absorve.
     *
     * Com {@see self::previousCompetenceBaseline()}, é a estrutura da cadeia da
     * versão ({@see SalesBoardChainStructure}). `null` quando a versão foi
     * congelada antes de a cadeia ser registrada -- "não registrada" é
     * diferente de "nenhuma absorvida". Gravada só na criação: a coluna fica
     * fora de {@see self::STALE_MUTABLE_FIELDS} e dos fingerprints.
     *
     * @return list<string>|null
     */
    public function absorbedCancelledMonths(): ?array
    {
        return $this->absorbed_cancelled_months === null ? null : array_values($this->absorbed_cancelled_months);
    }

    /**
     * As unidades congeladas, na ordem em que a derivação as compôs.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesBoardCycleLine::class)
            ->orderBy('block')
            ->orderBy('unit')
            ->orderBy('construction_unit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SalesBoardCycleMovement::class)
            ->orderBy('movement_type')
            ->orderBy('contract_id');
    }

    /**
     * Os avisos que a apuração registrou nesta versão, na forma de
     * {@see SalesBoardFrozenWarnings::fromPosition()}.
     *
     * `null` quando a versão foi congelada antes de os avisos passarem a ser
     * registrados -- "não registrados" é diferente de "nenhum aviso", e a tela
     * diz qual dos dois. Gravados só na criação: a coluna fica fora de
     * {@see self::STALE_MUTABLE_FIELDS}, e o guard de imutabilidade a protege
     * como o resto da apuração.
     *
     * @return list<array<string, mixed>>|null
     */
    public function frozenWarnings(): ?array
    {
        return $this->warnings === null ? null : array_values($this->warnings);
    }

    public function isCurrent(): bool
    {
        return (int) $this->cycle?->current_baseline_id === (int) $this->getKey();
    }

    public function versionLabel(): string
    {
        return 'V'.$this->version;
    }

    /**
     * Total apurado da posição, ou `null` se algum balde não pôde ser somado.
     */
    public function totalValueCents(): ?int
    {
        $buckets = [$this->stock_value, $this->financed_value, $this->settled_value, $this->exchanged_value];
        $total = 0;

        foreach ($buckets as $bucket) {
            $cents = IntegerMoney::cents($bucket);

            if ($cents === null) {
                return null;
            }

            $total += $cents;
        }

        return $total;
    }
}
