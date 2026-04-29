<?php

namespace App\Filament\Pages\Nimbus;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Models\Nimbus\NotificationSetting;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class NotificationSettings extends Page
{
    public array $data = [];

    protected string $view = 'filament.pages.nimbus.notification-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Comunicação';

    protected static ?string $navigationLabel = 'Configurações de notificações';

    protected static ?string $title = 'Configurações de notificações';

    protected static ?int $navigationSort = 32;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected const DEFAULT_SETTINGS = [
        'portal.notify.new_submission' => true,
        'portal.notify.status_change' => true,
        'portal.notify.response_upload' => true,
        'portal.notify.access_link' => true,
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('nimbus.notification-settings.view') ?? false;
    }

    public function mount(): void
    {
        $this->data = $this->getFormState();
    }

    /**
     * @return array<int, array{
     *     state_path: string,
     *     title: string,
     *     description: string,
     *     icon: Heroicon,
     *     icon_background: string,
     *     icon_color: string
     * }>
     */
    public function notificationOptions(): array
    {
        return [
            [
                'state_path' => 'portal_notify_new_submission',
                'title' => 'Nova submissão',
                'description' => 'Enviar e-mail ao usuário confirmando o recebimento de uma nova submissão.',
                'icon' => Heroicon::OutlinedEnvelope,
                'icon_background' => 'bg-sky-500/15',
                'icon_color' => 'text-sky-300',
            ],
            [
                'state_path' => 'portal_notify_status_change',
                'title' => 'Alteração de status',
                'description' => 'Notificar o usuário quando o status da submissão for alterado pela análise.',
                'icon' => Heroicon::OutlinedCheckBadge,
                'icon_background' => 'bg-emerald-500/15',
                'icon_color' => 'text-emerald-300',
            ],
            [
                'state_path' => 'portal_notify_response_upload',
                'title' => 'Documento de resposta',
                'description' => 'Avisar o usuário quando um administrador anexar um arquivo ou resposta à submissão.',
                'icon' => Heroicon::OutlinedDocumentArrowUp,
                'icon_background' => 'bg-cyan-500/15',
                'icon_color' => 'text-cyan-300',
            ],
            [
                'state_path' => 'portal_notify_access_link',
                'title' => 'Link de acesso',
                'description' => 'Enviar automaticamente um e-mail com o link de acesso sempre que solicitado.',
                'icon' => Heroicon::OutlinedKey,
                'icon_background' => 'bg-amber-500/15',
                'icon_color' => 'text-amber-300',
            ],
        ];
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can('nimbus.notification-settings.update') ?? false, 403);

        NotificationSetting::setValues([
            'portal.notify.new_submission' => ! empty($this->data['portal_notify_new_submission']) ? '1' : '0',
            'portal.notify.status_change' => ! empty($this->data['portal_notify_status_change']) ? '1' : '0',
            'portal.notify.response_upload' => ! empty($this->data['portal_notify_response_upload']) ? '1' : '0',
            'portal.notify.access_link' => ! empty($this->data['portal_notify_access_link']) ? '1' : '0',
        ]);

        Notification::make()
            ->title('Configurações salvas com sucesso.')
            ->success()
            ->send();
    }

    public function connectMicrosoftCorporateAccount(): void
    {
        $connection = $this->getMicrosoftConnectionSummary();

        Notification::make()
            ->title($connection['notification_title'])
            ->body($connection['notification_body'])
            ->persistent()
            ->{$connection['notification_status']}()
            ->send();
    }

    public function getNotificationOutboxUrl(): string
    {
        return NotificationOutboxResource::getUrl(panel: 'admin');
    }

    /**
     * @return array{
     *     is_connected: bool,
     *     is_partial: bool,
     *     status_label: string,
     *     status_classes: string,
     *     description: string,
     *     action_label: string,
     *     action_classes: string,
     *     action_icon: Heroicon,
     *     notification_title: string,
     *     notification_body: string,
     *     notification_status: string,
     *     missing_labels: list<string>
     * }
     */
    public function getMicrosoftConnectionSummary(): array
    {
        $missingLabels = $this->getMissingMicrosoftConfigurationLabels();

        if ($missingLabels === []) {
            return [
                'is_connected' => true,
                'is_partial' => false,
                'status_label' => 'Conectado',
                'status_classes' => 'border border-emerald-400/30 bg-emerald-500/15 text-emerald-200',
                'description' => 'As credenciais corporativas do Microsoft 365 já estão configuradas e prontas para envio transacional.',
                'action_label' => 'Revisar conexão corporativa',
                'action_classes' => 'bg-emerald-500 text-gray-950 hover:bg-emerald-400 focus-visible:ring-emerald-300/60',
                'action_icon' => Heroicon::OutlinedShieldCheck,
                'notification_title' => 'Conta Microsoft corporativa já configurada.',
                'notification_body' => 'As credenciais do Outlook corporativo já estão preenchidas no ambiente atual.',
                'notification_status' => 'success',
                'missing_labels' => [],
            ];
        }

        if (count($missingLabels) < 4) {
            return [
                'is_connected' => false,
                'is_partial' => true,
                'status_label' => 'Configuração parcial',
                'status_classes' => 'border border-amber-400/30 bg-amber-500/15 text-amber-100',
                'description' => 'A conta corporativa já tem parte das credenciais informadas, mas ainda faltam dados para concluir a conexão.',
                'action_label' => 'Concluir conexão corporativa',
                'action_classes' => 'bg-amber-500 text-gray-950 hover:bg-amber-400 focus-visible:ring-amber-300/60',
                'action_icon' => Heroicon::OutlinedKey,
                'notification_title' => 'Faltam credenciais para concluir a conexão.',
                'notification_body' => 'Preencha os campos pendentes no ambiente: '.implode(', ', $missingLabels).'.',
                'notification_status' => 'warning',
                'missing_labels' => $missingLabels,
            ];
        }

        return [
            'is_connected' => false,
            'is_partial' => false,
            'status_label' => 'Não conectado',
            'status_classes' => 'border border-slate-400/20 bg-white/5 text-slate-200',
            'description' => 'Conecte a conta Microsoft corporativa para usar o canal Outlook/Microsoft 365 no envio das notificações do portal.',
            'action_label' => 'Conectar conta corporativa',
            'action_classes' => 'bg-primary-500 text-gray-950 hover:bg-primary-400 focus-visible:ring-primary-300/60',
            'action_icon' => Heroicon::OutlinedArrowTopRightOnSquare,
            'notification_title' => 'Conexão corporativa pendente.',
            'notification_body' => 'Para concluir a conexão, configure no ambiente: '.implode(', ', $missingLabels).'.',
            'notification_status' => 'warning',
            'missing_labels' => $missingLabels,
        ];
    }

    /**
     * @return list<string>
     */
    private function getMissingMicrosoftConfigurationLabels(): array
    {
        $requiredConfiguration = [
            'Tenant ID' => config('services.outlook.tenant_id'),
            'Client ID' => config('services.outlook.client_id'),
            'Client Secret' => config('services.outlook.client_secret'),
            'Mailbox corporativo' => config('services.outlook.mailbox'),
        ];

        return array_keys(
            array_filter(
                $requiredConfiguration,
                static fn (mixed $value): bool => blank($value),
            ),
        );
    }

    private function getFormState(): array
    {
        $storedValues = NotificationSetting::getValues(array_keys(self::DEFAULT_SETTINGS));

        return [
            'portal_notify_new_submission' => ($storedValues['portal.notify.new_submission'] ?? '1') === '1',
            'portal_notify_status_change' => ($storedValues['portal.notify.status_change'] ?? '1') === '1',
            'portal_notify_response_upload' => ($storedValues['portal.notify.response_upload'] ?? '1') === '1',
            'portal_notify_access_link' => ($storedValues['portal.notify.access_link'] ?? '1') === '1',
        ];
    }
}
