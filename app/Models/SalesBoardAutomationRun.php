<?php

namespace App\Models;

use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationRunTrigger;
use Database\Factories\SalesBoardAutomationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Uma execução do orquestrador.
 *
 * Não é apagável: sem a execução não se distingue "o scheduler rodou e não
 * havia nada a fazer" de "o scheduler não rodou". Essa é a diferença entre um
 * mês tranquilo e um mês em que a automação estava morta.
 */
class SalesBoardAutomationRun extends Model
{
    /** @use HasFactory<SalesBoardAutomationRunFactory> */
    use HasFactory;

    protected $fillable = [
        'trigger',
        'status',
        'as_of_date',
        'latest_due_reference_month',
        'started_at',
        'finished_at',
        'targets_discovered',
        'targets_attempted',
        'generated_count',
        'existing_count',
        'blocked_count',
        'failed_count',
        'skipped_count',
        'alerts_sent',
        'alerts_deduped',
        'instance_key',
        'failure_message',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Sales board automation runs cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'trigger' => SalesBoardAutomationRunTrigger::class,
            'status' => SalesBoardAutomationRunStatus::class,
            'as_of_date' => 'immutable_date',
            'latest_due_reference_month' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'targets_discovered' => 'integer',
            'targets_attempted' => 'integer',
            'generated_count' => 'integer',
            'existing_count' => 'integer',
            'blocked_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'alerts_sent' => 'integer',
            'alerts_deduped' => 'integer',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SalesBoardAutomationAttempt::class, 'sales_board_automation_run_id');
    }

    public function durationMs(): ?int
    {
        if ($this->finished_at === null) {
            return null;
        }

        return (int) round($this->started_at->diffInMilliseconds($this->finished_at));
    }

    public function latestDueMonthLabel(): string
    {
        return $this->latest_due_reference_month?->format('m/Y') ?? '—';
    }

    /**
     * O resumo que vai para o log estruturado e para o `--json`.
     *
     * Só contadores e identificadores: nenhum nome de empreendimento, nenhum
     * dado de comprador, nada que transforme uma linha de observabilidade em
     * dado pessoal espalhado por arquivo de log.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'event' => 'sales_board_automation_run',
            'run_id' => (int) $this->getKey(),
            'trigger' => $this->trigger->value,
            'status' => $this->status->value,
            'as_of' => $this->as_of_date->toDateString(),
            'latest_due_month' => $this->latest_due_reference_month?->format('Y-m'),
            'discovered' => $this->targets_discovered,
            'attempted' => $this->targets_attempted,
            'generated' => $this->generated_count,
            'existing' => $this->existing_count,
            'blocked' => $this->blocked_count,
            'failed' => $this->failed_count,
            'skipped' => $this->skipped_count,
            'alerts_sent' => $this->alerts_sent,
            'alerts_deduped' => $this->alerts_deduped,
            'duration_ms' => $this->durationMs(),
        ];
    }
}
