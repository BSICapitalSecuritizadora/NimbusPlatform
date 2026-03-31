<?php

namespace App\Filament\Pages\Nimbus;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Models\Nimbus\NotificationSetting;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class NotificationSettings extends Page
{
    public ?array $data = [];

    protected string $view = 'filament.pages.nimbus.notification-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Comunicação';

    protected static ?string $navigationLabel = 'Configurar Notificações';

    protected static ?string $title = 'Configurar Notificações';

    protected static ?int $navigationSort = 32;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected const DEFAULT_SETTINGS = [
        'portal.notify.new_submission' => true,
        'portal.notify.status_change' => true,
        'portal.notify.response_upload' => true,
        'portal.notify.access_link' => true,
    ];

    public function mount(): void
    {
        $this->form->fill($this->getFormState());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                    'xl' => 12,
                ])
                    ->schema([
                        Section::make('Portal do Usuário')
                            ->description('Gerencie quando e como o usuário do portal recebe notificações.')
                            ->icon(Heroicon::OutlinedBell)
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 8,
                            ])
                            ->schema([
                                Toggle::make('portal_notify_new_submission')
                                    ->label('Nova submissão')
                                    ->helperText('Envia confirmação quando uma nova submissão é recebida.'),
                                Toggle::make('portal_notify_status_change')
                                    ->label('Alteração de status')
                                    ->helperText('Avisa quando a submissão muda de etapa na análise.'),
                                Toggle::make('portal_notify_response_upload')
                                    ->label('Documento de resposta')
                                    ->helperText('Notifica quando um administrador anexa um arquivo ou resposta.'),
                                Toggle::make('portal_notify_access_link')
                                    ->label('Link de acesso')
                                    ->helperText('Permite o envio automático do código/link de acesso ao portal.'),
                            ]),
                        Section::make('Infraestrutura')
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 4,
                            ])
                            ->schema([
                                Placeholder::make('provider')
                                    ->label('Canal de entrega')
                                    ->content('Fila de notificações do NimbusDocs'),
                                Placeholder::make('outbox')
                                    ->label('Fila e auditoria')
                                    ->content(new HtmlString(
                                        '<a href="'.NotificationOutboxResource::getUrl(panel: 'admin').'" class="fi-link text-primary-600">Ver Auditoria de Envios</a>'
                                    )),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        NotificationSetting::setValues([
            'portal.notify.new_submission' => ! empty($data['portal_notify_new_submission']) ? '1' : '0',
            'portal.notify.status_change' => ! empty($data['portal_notify_status_change']) ? '1' : '0',
            'portal.notify.response_upload' => ! empty($data['portal_notify_response_upload']) ? '1' : '0',
            'portal.notify.access_link' => ! empty($data['portal_notify_access_link']) ? '1' : '0',
        ]);

        Notification::make()
            ->title('Configurações salvas com sucesso.')
            ->success()
            ->send();
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
