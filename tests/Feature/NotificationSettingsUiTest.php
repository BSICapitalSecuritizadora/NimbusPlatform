<?php

use App\Filament\Pages\Nimbus\NotificationSettings;
use App\Models\Nimbus\NotificationSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->withTwoFactor()->create([
        'email' => 'admin-notificacoes@bsicapital.com.br',
    ]);
    $this->user->assignRole('admin');
});

it('renders notification settings page with subheading and dark cockpit layout', function () {
    $this->actingAs($this->user)
        ->get(NotificationSettings::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Configurações de notificações')
        ->assertSee('Defina quando os usuários são notificados e acompanhe a disponibilidade dos canais de envio.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-notification-settings-page');
});

it('renders single container with four notification rows and mandatory access link indicator', function () {
    Livewire::actingAs($this->user)
        ->test(NotificationSettings::class)
        ->assertSee('Notificações do portal')
        ->assertSee('Nova submissão')
        ->assertSee('Alteração de status')
        ->assertSee('Documento de resposta')
        ->assertSee('Link de acesso')
        ->assertSee('Obrigatório')
        ->assertSee('Esta notificação é obrigatória por segurança e não pode ser desativada.');
});

it('saves preferences accurately while maintaining access link mandatory', function () {
    Livewire::actingAs($this->user)
        ->test(NotificationSettings::class)
        ->set('data.portal_notify_new_submission', false)
        ->set('data.portal_notify_status_change', true)
        ->set('data.portal_notify_response_upload', false)
        ->set('data.portal_notify_access_link', false)
        ->call('save')
        ->assertNotified('Configurações salvas com sucesso.');

    expect(NotificationSetting::getValues([
        'portal.notify.new_submission',
        'portal.notify.status_change',
        'portal.notify.response_upload',
        'portal.notify.access_link',
    ]))->toMatchArray([
        'portal.notify.new_submission' => '0',
        'portal.notify.status_change' => '1',
        'portal.notify.response_upload' => '0',
        'portal.notify.access_link' => '1',
    ]);
});

it('displays microsoft infrastructure card with pending config indicators and connection action', function () {
    config()->set('services.outlook.tenant_id', null);
    config()->set('services.outlook.client_id', null);
    config()->set('services.outlook.client_secret', null);
    config()->set('services.outlook.mailbox', null);

    Livewire::actingAs($this->user)
        ->test(NotificationSettings::class)
        ->assertSee('Infraestrutura')
        ->assertSee('Microsoft 365 / Outlook')
        ->assertSee('Não conectado')
        ->assertSee('Configuração pendente')
        ->assertSee('Tenant ID')
        ->assertSee('Client ID')
        ->assertSee('Client Secret')
        ->assertSee('Mailbox corporativo')
        ->assertSee('Conectar conta corporativa')
        ->assertSee('Fila e logs')
        ->assertSee('Ver auditoria de envios');
});

it('triggers microsoft corporate connection action notification', function () {
    Livewire::actingAs($this->user)
        ->test(NotificationSettings::class)
        ->call('connectMicrosoftCorporateAccount')
        ->assertNotified('Conexão corporativa pendente.');
});
