<?php

namespace App\Notifications;

use App\Enums\SalesBoardAutomationAlertType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O aviso da automação do Quadro de Vendas.
 *
 * Um tipo só para os sete alertas: eles diferem no texto, não na estrutura, e
 * sete classes idênticas só espalhariam a mesma decisão por sete arquivos.
 *
 * Nenhum dado de comprador entra aqui -- nem nome, nem documento, nem contato.
 * O aviso diz qual empreendimento, qual competência e o que está parado; quem
 * precisar do detalhe abre a tela, que aplica as permissões de sempre.
 */
class SalesBoardAutomationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly SalesBoardAutomationAlertType $type,
        public readonly string $constructionName,
        public readonly string $referenceMonth,
        public readonly ?string $detail = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(sprintf('Quadro de Vendas — %s (%s)', $this->type->label(), $this->referenceMonth))
            ->greeting('Quadro de Vendas')
            ->line(sprintf('%s — competência %s.', $this->constructionName, $this->referenceMonth))
            ->line($this->type->label());

        if (filled($this->detail)) {
            $message->line($this->detail);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'construction' => $this->constructionName,
            'reference_month' => $this->referenceMonth,
            'detail' => $this->detail,
        ];
    }
}
