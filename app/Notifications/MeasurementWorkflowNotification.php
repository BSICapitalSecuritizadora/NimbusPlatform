<?php

namespace App\Notifications;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class MeasurementWorkflowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EVENT_LABELS = [
        'submitted' => 'Nova medição para análise',
        'advanced' => 'Medição avançou de etapa',
        'awaiting_payment' => 'Medição encaminhada à etapa Pagamento',
        'payment_registered' => 'Pagamento registrado — aguardando aprovação',
        'payment_approved' => 'Etapa Pagamento aprovada — envie os comprovantes',
        'ready_to_finalize' => 'Comprovantes completos — validação final necessária',
        'rejected' => 'Medição recusada',
        'returned' => 'Medição devolvida para etapa anterior',
        'paused' => 'Análise de medição pausada',
        'resumed' => 'Análise de medição retomada',
        'awaiting_receipt' => 'Pagamento aprovado — aguardando comprovante',
        'receipt_attached' => 'Comprovante anexado',
        'finalized' => 'Medição finalizada',
        'revision_submitted' => 'Revisão de medição para análise',
        'revision_rejected' => 'Revisão de medição recusada',
        'revision_effective' => 'Revisão de medição passou a valer',
        'workflow_closed_by_revision' => 'Medição substituída por revisão',
    ];

    /**
     * Optional event-specific lines, rendered after the title to tailor each modality.
     *
     * @var array<string, string>
     */
    public const EVENT_DESCRIPTIONS = [
        'submitted' => 'Uma nova medição foi enviada e aguarda sua análise na etapa de Engenharia.',
        'advanced' => 'A medição avançou e aguarda sua análise nesta etapa.',
        'awaiting_payment' => 'A medição chegou à etapa Pagamento. Registre os lançamentos e aprove formalmente esta etapa.',
        'payment_registered' => 'Os pagamentos foram registrados. A etapa Pagamento continua pendente de aprovação formal.',
        'payment_approved' => 'A etapa Pagamento foi aprovada. Anexe todos os comprovantes; a ação de Finalização revalidará o workflow e as evidências.',
        'ready_to_finalize' => 'Todos os pagamentos possuem comprovantes. A Finalização ainda revalidará as aprovações e a integridade das evidências.',
        'awaiting_receipt' => 'A etapa Pagamento foi aprovada. Anexe o(s) comprovante(s) para seguir à Finalização.',
        'rejected' => 'A medição foi recusada na etapa de Engenharia e foi encerrada.',
        'returned' => 'A medição foi devolvida para esta etapa e precisa ser reavaliada.',
        'receipt_attached' => 'O comprovante de pagamento foi anexado. A prontidão completa será verificada na ação de Finalização.',
        'finalized' => 'A medição foi finalizada e o fluxo está concluído.',
        'revision_submitted' => 'Uma revisão de medição foi enviada e aguarda sua análise na etapa de Engenharia. A revisão anterior continua valendo até esta ser aprovada pela Compliance.',
        'revision_rejected' => 'A revisão foi recusada na etapa de Engenharia e encerrada. A revisão anterior continua valendo, sem nenhuma alteração.',
        'revision_effective' => 'A revisão foi aprovada pela Compliance e passou a valer no lugar da anterior. Os pagamentos já registrados não foram alterados; confira a diferença financeira na etapa Pagamento.',
        'workflow_closed_by_revision' => 'A medição que aguardava pagamento foi substituída por uma revisão aprovada. O pagamento deve ser registrado na revisão que passou a valer.',
    ];

    /**
     * Visual palettes keyed by tone, mirroring the legacy NimbusOps house style.
     *
     * @var array<string, array{soft: string, border: string, text: string, accent: string}>
     */
    private const PALETTES = [
        'info' => ['soft' => '#eff6ff', 'border' => '#bfdbfe', 'text' => '#1d4ed8', 'accent' => '#2563eb'],
        'success' => ['soft' => '#ecfdf5', 'border' => '#a7f3d0', 'text' => '#047857', 'accent' => '#059669'],
        'warning' => ['soft' => '#fffbeb', 'border' => '#fde68a', 'text' => '#b45309', 'accent' => '#d97706'],
        'danger' => ['soft' => '#fef2f2', 'border' => '#fecaca', 'text' => '#b91c1c', 'accent' => '#dc2626'],
        'gold' => ['soft' => '#f4eee6', 'border' => '#d7c09c', 'text' => '#7b541e', 'accent' => '#a06e28'],
    ];

    /**
     * Maps each workflow event to its tone and a mail-safe glyph.
     *
     * @var array<string, array{tone: string, icon: string}>
     */
    private const EVENT_ACCENTS = [
        'submitted' => ['tone' => 'info', 'icon' => '&#128196;'],
        'advanced' => ['tone' => 'info', 'icon' => '&#10145;'],
        'awaiting_payment' => ['tone' => 'success', 'icon' => '&#128176;'],
        'payment_registered' => ['tone' => 'info', 'icon' => '&#128179;'],
        'payment_approved' => ['tone' => 'gold', 'icon' => '&#129534;'],
        'ready_to_finalize' => ['tone' => 'success', 'icon' => '&#10004;'],
        'rejected' => ['tone' => 'danger', 'icon' => '&#10006;'],
        'returned' => ['tone' => 'warning', 'icon' => '&#8617;'],
        'paused' => ['tone' => 'warning', 'icon' => '&#9208;'],
        'resumed' => ['tone' => 'gold', 'icon' => '&#9654;'],
        'awaiting_receipt' => ['tone' => 'gold', 'icon' => '&#129534;'],
        'receipt_attached' => ['tone' => 'success', 'icon' => '&#128206;'],
        'finalized' => ['tone' => 'success', 'icon' => '&#10004;'],
        'revision_submitted' => ['tone' => 'info', 'icon' => '&#128196;'],
        'revision_rejected' => ['tone' => 'danger', 'icon' => '&#10006;'],
        'revision_effective' => ['tone' => 'success', 'icon' => '&#10004;'],
        'workflow_closed_by_revision' => ['tone' => 'warning', 'icon' => '&#8617;'],
    ];

    public function __construct(
        public Measurement $measurement,
        public string $event,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = self::EVENT_LABELS[$this->event] ?? 'Atualização de medição';
        $operation = $this->measurement->operation;
        $reference = $this->measurement->reference_month?->format('m/Y');

        $accent = self::PALETTES[self::EVENT_ACCENTS[$this->event]['tone'] ?? 'gold'];
        $accent['icon'] = self::EVENT_ACCENTS[$this->event]['icon'] ?? '&#9679;';

        $operationLabel = trim(($operation?->code ? $operation->code.' — ' : '').($operation?->title ?? '—')) ?: '—';
        $stage = app(MeasurementWorkflow::class)->unifiedStage($this->measurement);

        return (new MailMessage)
            ->mailer((string) config('nimbus.mail.mailer', config('mail.default')))
            ->subject(sprintf('[NimbusOps] %s — %s', $title, $operation?->title ?? 'Operação'))
            ->view('emails.measurements.workflow', [
                'title' => $title,
                'description' => self::EVENT_DESCRIPTIONS[$this->event] ?? null,
                'firstName' => Str::of($notifiable->name ?? '')->trim()->explode(' ')->first() ?: 'Parceiro',
                'accent' => $accent,
                'operationLabel' => $operationLabel,
                'reference' => $reference,
                'stageLabel' => MeasurementWorkflow::STAGE_LABELS[$stage] ?? '—',
                'filename' => $this->measurement->filename ?? '—',
                'url' => $this->urlFor($notifiable),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'measurement_id' => $this->measurement->id,
            'operation_id' => $this->measurement->operation_id,
            'event' => $this->event,
            'title' => self::EVENT_LABELS[$this->event] ?? 'Atualização de medição',
        ];
    }

    /**
     * O botão "abrir a medição" só vai para quem consegue abri-la.
     *
     * Quem está só na lista "Notificar em caso de recusa" recebe o aviso, mas
     * não participa da operação: o link o levaria a uma página que não existe
     * para ele. A decisão é por destinatário e na hora do envio -- a
     * notificação é enfileirada, e quem perdeu o acesso entre a recusa e o
     * envio também não recebe o link. O modelo do e-mail já omite o botão
     * quando a URL é nula.
     */
    private function urlFor(object $notifiable): ?string
    {
        if (! $notifiable instanceof User || ! $notifiable->can('view', $this->measurement)) {
            return null;
        }

        return $this->resolveUrl();
    }

    private function resolveUrl(): ?string
    {
        try {
            return MeasurementResource::getUrl('view', [
                'record' => $this->measurement->getKey(),
            ]);
        } catch (\Throwable) {
            return config('app.url');
        }
    }
}
