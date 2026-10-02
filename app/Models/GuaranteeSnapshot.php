<?php

namespace App\Models;

use App\DTOs\Guarantees\GuaranteeSalesBoardCoverage;
use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeValueSource;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use App\Support\BusinessTime;
use App\Support\SalesBoards\CompetenceCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\GuaranteeSnapshotFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class GuaranteeSnapshot extends Model
{
    /** @use HasFactory<GuaranteeSnapshotFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'emission_id',
        'reference_month',
        'quota_value',
        'outstanding_balance',
        'total_gross_value',
        'total_eligible_value',
        'total_required_value',
        'coverage_ratio',
        'required_ratio',
        'surplus_deficit',
        'coverage_status',
        'active_guarantees_count',
        'pending_sources',
        'metadata',
        'sales_board_coverage',
        'sales_board_outdated_at',
        'outstanding_balance_outdated_at',
        'outstanding_balance_outdated_reason',
        'computed_at',
        'closed_at',
        'closed_by',
        'partial_coverage_confirmation',
        'partial_coverage_confirmed_at',
        'partial_coverage_confirmed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            // Formato explícito: a competência compõe o índice único com a
            // emissão, e gravá-la como datetime faria o `updateOrCreate` criar
            // uma segunda linha para o mesmo mês.
            'reference_month' => 'date:Y-m-d',
            'quota_value' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'total_gross_value' => 'decimal:2',
            'total_eligible_value' => 'decimal:2',
            'total_required_value' => 'decimal:2',
            'coverage_ratio' => 'decimal:6',
            'required_ratio' => 'decimal:6',
            'surplus_deficit' => 'decimal:2',
            'coverage_status' => GuaranteeCoverageStatus::class,
            'active_guarantees_count' => 'integer',
            'pending_sources' => 'array',
            'metadata' => 'array',
            'sales_board_coverage' => 'array',
            'sales_board_outdated_at' => 'datetime',
            'outstanding_balance_outdated_at' => 'datetime',
            'computed_at' => 'datetime',
            'closed_at' => 'datetime',
            'partial_coverage_confirmation' => 'array',
            'partial_coverage_confirmed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /**
     * A competência foi fechada? Um mês fechado só volta a ser editável por
     * reabertura explícita, que é permissão própria e fica auditada.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Um Quadro de Vendas publicado ou registrado depois da apuração passou a
     * responder pela competência: o número gravado não é mais o que o motor
     * apuraria hoje.
     */
    public function isSalesBoardOutdated(): bool
    {
        return $this->sales_board_outdated_at !== null;
    }

    /**
     * O saldo devedor que a competência apurou deixou de ser o que a fonte de PU
     * responde hoje: uma curva homologada ou invalidada, um Histórico de PU
     * importado ou a verificação diária mudou o número em pelo menos um
     * centavo depois da apuração.
     */
    public function isOutstandingBalanceOutdated(): bool
    {
        return $this->outstanding_balance_outdated_at !== null;
    }

    /**
     * Desatualizada por qualquer uma das duas fontes que mudam o número gravado.
     */
    public function isOutdated(): bool
    {
        return $this->isSalesBoardOutdated() || $this->isOutstandingBalanceOutdated();
    }

    /**
     * Reaberta e ainda não fechada de novo.
     *
     * `reopened_at` guarda a última reabertura e continua preenchido depois de
     * um novo fechamento; o que distingue o estado "reaberta" é o fechamento
     * ainda não ter acontecido.
     */
    public function wasReopenedAndNotClosed(): bool
    {
        return $this->reopened_at !== null && ! $this->isClosed();
    }

    /**
     * Por que o número gravado deixou de valer, uma frase por fonte, com o
     * instante no fuso de negócio.
     *
     * O texto do Quadro de Vendas é o mesmo que a aba já mostrava no selo
     * "Desatualizada": quem lia aquele aviso continua lendo o mesmo aviso.
     *
     * @return list<string>
     */
    public function outdatedReasons(): array
    {
        $reasons = [];

        if ($this->isSalesBoardOutdated()) {
            $reasons[] = sprintf(
                'Quadro de Vendas registrado em %s, depois da apuração.',
                BusinessTime::at($this->sales_board_outdated_at)->format('d/m/Y H:i'),
            );
        }

        if ($this->isOutstandingBalanceOutdated()) {
            $reasons[] = sprintf(
                'Saldo devedor alterado em %s (%s), depois da apuração.',
                BusinessTime::at($this->outstanding_balance_outdated_at)->format('d/m/Y H:i'),
                filled($this->outstanding_balance_outdated_reason)
                    ? $this->outstanding_balance_outdated_reason
                    : 'fonte de PU alterada',
            );
        }

        return $reasons;
    }

    /**
     * O rótulo curto das fontes que desatualizaram a competência, para listas
     * de escolha: "desatualizada pelo Quadro de Vendas", "pelo saldo devedor"
     * ou pelos dois. Nulo quando ela está em dia.
     */
    public function outdatedSourcesLabel(): ?string
    {
        $sources = array_values(array_filter([
            $this->isSalesBoardOutdated() ? 'pelo Quadro de Vendas' : null,
            $this->isOutstandingBalanceOutdated() ? 'pelo saldo devedor' : null,
        ]));

        if ($sources === []) {
            return null;
        }

        return 'desatualizada '.implode(' e ', $sources);
    }

    /**
     * A competência dependia do Quadro de Vendas sem que a origem tenha sido
     * gravada.
     *
     * `sales_board_coverage` nulo tem dois sentidos. Em snapshot gravado antes
     * de a origem passar a ser registrada, é desconhecimento: as garantias de
     * estoque usaram algum quadro, mas não se sabe qual. Em qualquer snapshot
     * posterior, é a competência sem garantia de estoque contribuindo, e aí não
     * há quadro nenhum de que depender. O que separa os dois casos é a posição
     * gravada de cada garantia na mesma competência: basta uma garantia de
     * estoque que compunha a cobertura para a dependência existir.
     */
    public function hasUnrecordedSalesBoardCoverage(): bool
    {
        if ($this->sales_board_coverage !== null) {
            return false;
        }

        return GuaranteeMonthlyPosition::query()
            ->where('emission_id', $this->emission_id)
            ->whereDate('reference_month', $this->reference_month->toDateString())
            ->where('value_source', GuaranteeValueSource::SalesBoard->value)
            ->get(['metadata'])
            ->contains(fn (GuaranteeMonthlyPosition $position): bool => ($position->metadata['contributes_to_coverage'] ?? true) !== false);
    }

    /**
     * Quem fechou aceitou explicitamente uma posição parcial do Quadro de Vendas.
     */
    public function hasPartialCoverageConfirmation(): bool
    {
        return $this->partial_coverage_confirmed_at !== null;
    }

    public function salesBoardCoverage(): ?GuaranteeSalesBoardCoverage
    {
        return GuaranteeSalesBoardCoverage::fromArray($this->sales_board_coverage);
    }

    /**
     * Empreendimentos e meses aceitos na confirmação do fechamento parcial.
     *
     * @return list<string>
     */
    public function partialCoverageDescriptions(): array
    {
        $confirmation = $this->partial_coverage_confirmation;

        if (! is_array($confirmation)) {
            return [];
        }

        return GuaranteeSalesBoardCoverage::fromArray([
            'constructions' => $confirmation['gaps'] ?? [],
        ])?->gapDescriptions() ?? [];
    }

    /**
     * Competência corrente do calendário de negócio (America/Sao_Paulo), no
     * formato gravado (`Y-m-01`).
     *
     * A regra do mês de negócio mora no {@see CompetenceCalendar}, a mesma que o
     * Quadro de Vendas, o relatório mensal e a automação usam.
     */
    public static function currentBusinessMonth(?DateTimeInterface $instant = null): string
    {
        return CompetenceCalendar::currentMonth($instant)->toDateString();
    }

    /**
     * Competência que se espera consolidar agora: o mês de negócio anterior. O
     * Quadro de Vendas de um mês só é publicado no seguinte, então o mês
     * corrente ainda não tem posição própria para fechar.
     */
    public static function previousBusinessMonth(?DateTimeInterface $instant = null): string
    {
        return CompetenceCalendar::lastClosedMonth($instant)->toDateString();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function partialCoverageConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partial_coverage_confirmed_by');
    }

    /**
     * Quem fez a última reabertura.
     */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(GuaranteeMonthlyPosition::class, 'emission_id', 'emission_id')
            ->whereColumn('guarantee_monthly_positions.reference_month', 'guarantee_snapshots.reference_month');
    }

    /**
     * A trilha da competência é evidência de um número que já saiu em
     * relatório: fechamento, confirmação de posição parcial, reabertura e as
     * marcas de desatualização. Ela vai para `guarantee_competences`, a mesma
     * categoria protegida do {@see GuaranteeSnapshotWriter}; no balde `default`
     * seria descartada em um ano.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('guarantee_competences')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public static function normalizeReferenceMonth(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->startOfMonth()->toDateString();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($value))
                ->startOfMonth()
                ->toDateString();
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

    public static function formatReferenceMonthForDisplay(mixed $value): string
    {
        $referenceMonth = self::normalizeReferenceMonth($value);

        if ($referenceMonth === null) {
            return '';
        }

        return Carbon::parse($referenceMonth)->format('m/Y');
    }

    public function getFormattedReferenceMonthAttribute(): string
    {
        return self::formatReferenceMonthForDisplay($this->reference_month);
    }
}
