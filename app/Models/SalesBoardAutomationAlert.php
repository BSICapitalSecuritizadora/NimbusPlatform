<?php

namespace App\Models;

use App\Enums\SalesBoardAutomationAlertType;
use Database\Factories\SalesBoardAutomationAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um aviso emitido -- ou a intenção de emitir, registrada antes do envio.
 *
 * A linha nasce pelo `insertOrIgnore` que decide, no banco, quem envia: quem
 * inseriu envia, quem colidiu já sabe que o aviso saiu. Se o envio falhar, a
 * linha é removida para que a próxima execução tente de novo -- é at-least-once
 * assumido, não exactly-once fingido.
 */
class SalesBoardAutomationAlert extends Model
{
    /** @use HasFactory<SalesBoardAutomationAlertFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'alert_type',
        'dedupe_key',
        'sales_board_automation_target_id',
        'sales_board_cycle_id',
        'sales_board_builder_review_id',
        'sales_board_management_review_id',
        'recipient_user_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'alert_type' => SalesBoardAutomationAlertType::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SalesBoardAutomationTarget::class, 'sales_board_automation_target_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SalesBoardCycle::class, 'sales_board_cycle_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
