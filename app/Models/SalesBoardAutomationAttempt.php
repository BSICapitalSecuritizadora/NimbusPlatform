<?php

namespace App\Models;

use App\Enums\SalesBoardAutomationAttemptOutcome;
use Database\Factories\SalesBoardAutomationAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Uma investida sobre um alvo.
 *
 * Append-only: nem edição nem exclusão. Uma tentativa que pudesse ser reescrita
 * depois não serviria para explicar nada -- e explicar é a única coisa que ela
 * faz.
 */
class SalesBoardAutomationAttempt extends Model
{
    /** @use HasFactory<SalesBoardAutomationAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'sales_board_automation_run_id',
        'sales_board_automation_target_id',
        'attempt_number',
        'outcome',
        'started_at',
        'finished_at',
        'sales_board_cycle_id',
        'reason_code',
        'reason_message',
    ];

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('Sales board automation attempts are append-only.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return [
            'outcome' => SalesBoardAutomationAttemptOutcome::class,
            'attempt_number' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SalesBoardAutomationRun::class, 'sales_board_automation_run_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SalesBoardAutomationTarget::class, 'sales_board_automation_target_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycle::class, 'sales_board_cycle_id');
    }
}
