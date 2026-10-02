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
        'alerts_failed',
        'alerts_without_recipient',
        'duration_ms',
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
            'alerts_failed' => 'integer',
            'alerts_without_recipient' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SalesBoardAutomationAttempt::class, 'sales_board_automation_run_id');
    }

    /**
     * Quanto a execução durou, em milissegundos -- ou `null`, se não se sabe.
     *
     * Vem da coluna gravada pelo orquestrador com `hrtime()`, e não da
     * diferença entre `started_at` e `finished_at`: esses timestamps não
     * guardam fração de segundo, e a diferença transformava 0,75 s em zero e
     * 1,96 s em um segundo. A execução dada como interrompida e as linhas
     * anteriores à coluna ficam `null` -- duração desconhecida, nunca zero.
     */
    public function durationMs(): ?int
    {
        return $this->duration_ms === null ? null : (int) $this->duration_ms;
    }

    /**
     * A duração como a tela a mostra: "0,8 s", "2 min 05 s" ou "—".
     */
    public function durationLabel(): string
    {
        $milliseconds = $this->durationMs();

        if ($milliseconds === null) {
            return '—';
        }

        if ($milliseconds < 60_000) {
            $seconds = $milliseconds / 1000;

            return ($seconds < 10 ? number_format($seconds, 1, ',', '') : (string) intdiv($milliseconds, 1000)).' s';
        }

        $totalSeconds = intdiv($milliseconds, 1000);

        if ($totalSeconds < 3600) {
            return sprintf('%d min %02d s', intdiv($totalSeconds, 60), $totalSeconds % 60);
        }

        return sprintf('%d h %02d min', intdiv($totalSeconds, 3600), intdiv($totalSeconds % 3600, 60));
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
     * `alerts_sent` conta avisos **enfileirados**: a entrega é do worker da
     * fila, depois da execução. Uma entrega que falha lá não volta para esta
     * linha -- aparece nos jobs falhos, e o aviso volta a ser devido na execução
     * seguinte.
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
            'alerts_failed' => (int) $this->alerts_failed,
            'alerts_without_recipient' => (int) $this->alerts_without_recipient,
            'duration_ms' => $this->durationMs(),
        ];
    }
}
