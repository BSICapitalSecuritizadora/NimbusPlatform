<?php

namespace App\Notifications;

use App\Enums\SalesBoardAutomationAlertType;
use App\Models\SalesBoardAutomationAlert;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * O aviso da automação do Quadro de Vendas.
 *
 * Um tipo só para todos os alertas: eles diferem no texto, não na estrutura, e
 * uma classe por alerta só espalharia a mesma decisão por vários arquivos.
 *
 * Nenhum dado de comprador entra aqui -- nem nome, nem documento, nem contato.
 * O aviso diz qual empreendimento, qual competência e o que está parado; quem
 * precisar do detalhe abre a tela pelo link, e a tela aplica as permissões de
 * sempre.
 *
 * Vai pela fila, como as outras notificações do sistema. Síncrona, ela enviava
 * SMTP dentro do comando agendado: com o servidor de e-mail fora, cada
 * destinatário segurava o tick das HH:00 até o timeout do socket, e as outras
 * rotinas do mesmo minuto atrasavam junto. Na fila, cada canal é um job, com
 * prazo curto; o que falhar no worker devolve o aviso para a próxima execução.
 *
 * No banco, grava no formato do Filament: é o que o sino do painel lê. O
 * `toArray()` cru gravava linhas que nenhuma tela mostrava.
 */
class SalesBoardAutomationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Uma tentativa por job: quem tenta de novo é a execução horária, depois de
     * a linha do livro-razão ter sido devolvida em {@see self::failed()}.
     */
    public int $tries = 1;

    /**
     * Segundos. Um SMTP pendurado não pode segurar o worker por dez minutos.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly SalesBoardAutomationAlertType $type,
        public readonly string $constructionName,
        public readonly string $referenceMonth,
        public readonly ?string $detail = null,
        public readonly ?string $url = null,
        public readonly ?string $dedupeKey = null,
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
            ->subject($this->subject())
            ->greeting('Quadro de Vendas')
            ->line($this->context())
            ->line($this->type->label());

        if (filled($this->detail)) {
            $message->line($this->detail);
        }

        if (filled($this->url)) {
            $message->action('Abrir no Nimbus', $this->url);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title('Quadro de Vendas — '.$this->type->label())
            ->body(trim($this->context().' '.($this->detail ?? '')))
            ->status($this->type->isEscalation() || $this->type === SalesBoardAutomationAlertType::RunInterrupted ? 'danger' : 'warning');

        if (filled($this->url)) {
            $notification->actions([
                Action::make('abrir')
                    ->label('Abrir')
                    ->url($this->url)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
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
            'url' => $this->url,
        ];
    }

    /**
     * A entrega falhou no worker: o aviso volta a ser devido.
     *
     * A linha do livro-razão é o que faz a execução seguinte considerar o aviso
     * enviado. Mantê-la depois de uma falha de entrega faria o aviso sumir em
     * silêncio.
     */
    public function failed(Throwable $exception): void
    {
        if (blank($this->dedupeKey)) {
            return;
        }

        SalesBoardAutomationAlert::query()->where('dedupe_key', $this->dedupeKey)->delete();
    }

    private function subject(): string
    {
        return $this->referenceMonth === ''
            ? sprintf('Quadro de Vendas — %s', $this->type->label())
            : sprintf('Quadro de Vendas — %s (%s)', $this->type->label(), $this->referenceMonth);
    }

    /**
     * De que se trata: o empreendimento e a competência, ou -- nos avisos que não
     * pertencem a uma competência -- a execução ou a Emissão.
     */
    private function context(): string
    {
        return $this->referenceMonth === ''
            ? $this->constructionName.'.'
            : sprintf('%s — competência %s.', $this->constructionName, $this->referenceMonth);
    }
}
