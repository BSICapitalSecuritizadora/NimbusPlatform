<?php

namespace App\Models;

use App\Enums\SalesBoardAutomationAlertType;
use Database\Factories\SalesBoardAutomationAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um aviso emitido por um canal -- ou a intenção de emitir, registrada antes
 * do envio.
 *
 * Uma linha por aviso, destinatário e canal (e-mail, sino do painel). A linha
 * nasce pelo `insertOrIgnore` que decide, no banco, quem envia: quem inseriu
 * enfileira aquele canal, quem colidiu já sabe que ele saiu. Se a entrega
 * daquele canal falhar, só a linha dele é removida, para que a próxima execução
 * tente de novo só ele -- é at-least-once assumido, não exactly-once fingido.
 */
class SalesBoardAutomationAlert extends Model
{
    /** @use HasFactory<SalesBoardAutomationAlertFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'alert_type',
        'dedupe_key',
        'sales_board_automation_run_id',
        'emission_id',
        'sales_board_automation_target_id',
        'sales_board_cycle_id',
        'sales_board_builder_review_id',
        'sales_board_management_review_id',
        'recipient_user_id',
        'channel',
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
