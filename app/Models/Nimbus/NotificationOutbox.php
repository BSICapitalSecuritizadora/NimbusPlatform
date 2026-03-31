<?php

namespace App\Models\Nimbus;

use Database\Factories\Nimbus\NotificationOutboxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationOutbox extends Model
{
    /** @use HasFactory<\Database\Factories\Nimbus\NotificationOutboxFactory> */
    use HasFactory;

    protected $table = 'nimbus_notification_outboxes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected static function newFactory(): NotificationOutboxFactory
    {
        return NotificationOutboxFactory::new();
    }

    public function canBeCancelled(): bool
    {
        return strtoupper((string) $this->status) === 'PENDING';
    }

    public function canBeReprocessed(): bool
    {
        return in_array(strtoupper((string) $this->status), ['FAILED', 'CANCELLED'], true);
    }

    public function getStatusLabelAttribute(): string
    {
        return match (strtoupper((string) $this->status)) {
            'PENDING' => 'Aguardando',
            'SENDING' => 'Enviando',
            'SENT' => 'Concluído',
            'FAILED' => 'Falhou',
            'CANCELLED' => 'Cancelado',
            default => (string) $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match (strtoupper((string) $this->status)) {
            'PENDING' => 'warning',
            'SENDING' => 'info',
            'SENT' => 'success',
            'FAILED' => 'danger',
            'CANCELLED' => 'gray',
            default => 'gray',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match (strtolower((string) $this->type)) {
            'token_created' => 'Criação de Token',
            'password_reset' => 'Redefinição de Senha',
            'welcome_email' => 'Boas-vindas',
            'submission_received' => 'Protocolo Recebido',
            'user_precreated' => 'Pré-cadastro de Usuário',
            'new_announcement' => 'Novo Comunicado',
            'new_general_document' => 'Documento Publicado',
            default => str((string) $this->type)->replace(['_', '-'], ' ')->title()->toString(),
        };
    }
}
