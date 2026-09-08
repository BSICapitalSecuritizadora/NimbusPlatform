<?php

namespace App\Models;

use App\Enums\SalesBoardAutomationSatisfiedVia;
use App\Enums\SalesBoardAutomationTargetStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SalesBoardAutomationTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * O estado da automação para um empreendimento numa competência.
 *
 * Mutável, ao contrário de quase tudo no Quadro de Vendas, e de propósito: ele
 * é o presente, não o passado. O passado inteiro está nas tentativas, que são
 * append-only -- e é lá que se olha para entender como se chegou aqui.
 *
 * A identidade nunca muda. Empreendimento e competência definem o alvo; deixá-los
 * editáveis permitiria "mover" uma competência para outra, e a trilha passaria a
 * descrever um alvo que nunca existiu.
 */
class SalesBoardAutomationTarget extends Model
{
    /** @use HasFactory<SalesBoardAutomationTargetFactory> */
    use HasFactory;

    /**
     * O que muda ao longo da vida do alvo.
     *
     * @var list<string>
     */
    public const MUTABLE_FIELDS = [
        'status',
        'satisfied_via',
        'attempt_count',
        'first_attempt_at',
        'last_attempt_at',
        'next_attempt_at',
        'last_outcome_at',
        'sales_board_cycle_id',
        'last_blocker_codes',
        'last_blocker_message',
        'last_error_code',
        'last_error_message',
        'auto_open_builder_review',
        'updated_at',
    ];

    protected $fillable = [
        'construction_id',
        'reference_month',
        'due_date',
        'status',
        'satisfied_via',
        'attempt_count',
        'first_attempt_at',
        'last_attempt_at',
        'next_attempt_at',
        'last_outcome_at',
        'sales_board_cycle_id',
        'last_blocker_codes',
        'last_blocker_message',
        'last_error_code',
        'last_error_message',
        'auto_open_builder_review',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $target): void {
            if (array_diff(array_keys($target->getDirty()), self::MUTABLE_FIELDS) !== []) {
                throw new LogicException('A sales board automation target identity is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Sales board automation targets cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'immutable_date',
            'due_date' => 'immutable_date',
            'status' => SalesBoardAutomationTargetStatus::class,
            'satisfied_via' => SalesBoardAutomationSatisfiedVia::class,
            'attempt_count' => 'integer',
            'first_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'last_outcome_at' => 'immutable_datetime',
            'last_blocker_codes' => 'array',
            'auto_open_builder_review' => 'boolean',
        ];
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycle::class, 'sales_board_cycle_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SalesBoardAutomationAttempt::class, 'sales_board_automation_target_id')
            ->orderBy('attempt_number');
    }

    public function isSatisfied(): bool
    {
        return $this->status === SalesBoardAutomationTargetStatus::Satisfied;
    }

    /**
     * O alvo pode ser tentado agora?
     *
     * Satisfeito nunca mais tenta -- é terminal para a automação daquela
     * competência, mesmo que o ciclo depois avance para validação, análise ou
     * aprovação. O que a automação prometeu foi garantir a existência do ciclo,
     * e isso já aconteceu.
     */
    public function isDueForAttempt(CarbonImmutable $now): bool
    {
        if ($this->isSatisfied()) {
            return false;
        }

        return $this->next_attempt_at === null
            || $this->next_attempt_at->lessThanOrEqualTo($now);
    }

    public function referenceMonthLabel(): string
    {
        return $this->reference_month?->format('m/Y') ?? '—';
    }

    /**
     * @return list<string>
     */
    public function blockerCodes(): array
    {
        return array_values(array_map('strval', $this->last_blocker_codes ?? []));
    }

    /**
     * O motivo atual, em linguagem de tela, seja qual for a natureza da parada.
     */
    public function currentReason(): ?string
    {
        return match ($this->status) {
            SalesBoardAutomationTargetStatus::Blocked => $this->last_blocker_message,
            SalesBoardAutomationTargetStatus::Failed => $this->last_error_message,
            default => null,
        };
    }
}
