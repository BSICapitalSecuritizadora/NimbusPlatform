<?php

use App\Filament\Pages\Nimbus\NimbusDashboard;
use App\Filament\Pages\Nimbus\NotificationSettings;
use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\Announcement;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\NotificationOutbox;
use App\Models\Nimbus\NotificationSetting;
use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('registers a dedicated Nimbus dashboard route inside the admin panel', function () {
    $this->get(route('filament.admin.pages.nimbus-dashboard'))
        ->assertRedirect('/admin/login');
});

it('renders the Nimbus dashboard for authenticated admin users', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-admin@example.com',
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(NimbusDashboard::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Visão Geral')
        ->assertSee('Dashboard')
        ->assertSee('Envios e Solicitações');
});

it('renders the Nimbus dashboard widgets when recent submissions exist', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-admin-submissions@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Nimbus',
        'email' => 'cliente.nimbus@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-DASHBOARD-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação no dashboard',
        'responsible_name' => 'Cliente Nimbus',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Dashboard',
        'status' => 'PENDING',
        'submitted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(NimbusDashboard::getUrl(panel: 'admin'))
        ->assertSuccessful();
});

it('loads the application vite theme for the admin panel', function () {
    expect(Filament::getPanel('admin')->getViteTheme())
        ->toBe('resources/css/filament/admin/theme.css');
});

it('organizes NimbusDocs navigation under the Visão Geral subsection', function () {
    expect(NimbusDashboard::getNavigationParentItem())->toBe('Visão Geral')
        ->and(SubmissionResource::getNavigationParentItem())->toBe('Visão Geral')
        ->and(SubmissionResource::getNavigationLabel())->toBe('Envios e Solicitações')
        ->and(collect(Filament::getPanel('admin')->getNavigationItems())->first(fn ($item) => $item->getLabel() === 'Visão Geral'))
        ->not->toBeNull();
});

it('organizes administrative items under the Administração subsection', function () {
    expect(PortalUserResource::getNavigationParentItem())->toBe('Administração')
        ->and(PortalUserResource::getNavigationLabel())->toBe('Usuários do Portal')
        ->and(AccessTokenResource::getNavigationParentItem())->toBe('Administração')
        ->and(AccessTokenResource::getNavigationLabel())->toBe('Chaves de Acesso')
        ->and(collect(Filament::getPanel('admin')->getNavigationItems())->first(fn ($item) => $item->getLabel() === 'Administração'))
        ->not->toBeNull();
});

it('organizes document management items under the Gestão Documental subsection', function () {
    expect(DocumentCategoryResource::getNavigationParentItem())->toBe('Gestão Documental')
        ->and(DocumentCategoryResource::getNavigationLabel())->toBe('Categorias de Documentos')
        ->and(GeneralDocumentResource::getNavigationParentItem())->toBe('Gestão Documental')
        ->and(GeneralDocumentResource::getNavigationLabel())->toBe('Biblioteca Geral')
        ->and(PortalDocumentResource::getNavigationParentItem())->toBe('Gestão Documental')
        ->and(PortalDocumentResource::getNavigationLabel())->toBe('Documentos por Usuário')
        ->and(collect(Filament::getPanel('admin')->getNavigationItems())->first(fn ($item) => $item->getLabel() === 'Gestão Documental'))
        ->not->toBeNull();
});

it('organizes communication items under the Comunicação subsection', function () {
    expect(AnnouncementResource::getNavigationParentItem())->toBe('Comunicação')
        ->and(AnnouncementResource::getNavigationLabel())->toBe('Avisos Gerais')
        ->and(NotificationOutboxResource::getNavigationParentItem())->toBe('Comunicação')
        ->and(NotificationOutboxResource::getNavigationLabel())->toBe('Auditoria de Envios')
        ->and(NotificationSettings::getNavigationParentItem())->toBe('Comunicação')
        ->and(NotificationSettings::getNavigationLabel())->toBe('Configurar Notificações')
        ->and(collect(Filament::getPanel('admin')->getNavigationItems())->first(fn ($item) => $item->getLabel() === 'Comunicação'))
        ->not->toBeNull();
});

it('makes the Visão Geral subsection clickable', function () {
    $overviewItem = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Visão Geral');

    expect($overviewItem)->not->toBeNull()
        ->and($overviewItem->getUrl())->toBe(NimbusDashboard::getUrl(panel: 'admin'));
});

it('makes the Administração subsection clickable', function () {
    $administrationItem = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Administração');

    expect($administrationItem)->not->toBeNull()
        ->and($administrationItem->getUrl())->toBe(PortalUserResource::getUrl(panel: 'admin'));
});

it('makes the Gestão Documental subsection clickable', function () {
    $documentManagementItem = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Gestão Documental');

    expect($documentManagementItem)->not->toBeNull()
        ->and($documentManagementItem->getUrl())->toBe(DocumentCategoryResource::getUrl(panel: 'admin'));
});

it('makes the Comunicação subsection clickable', function () {
    $communicationItem = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Comunicação');

    expect($communicationItem)->not->toBeNull()
        ->and($communicationItem->getUrl())->toBe(AnnouncementResource::getUrl(panel: 'admin'));
});

it('renders the submissions list in Portuguese without exposing creation to internal users', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-submissions@example.com',
    ]);
    $user->assignRole('admin');

    expect(SubmissionResource::canCreate())->toBeFalse()
        ->and(SubmissionResource::hasPage('create'))->toBeFalse();

    $this->actingAs($user)
        ->get(SubmissionResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Envios e Solicitações')
        ->assertDontSee('Criar envio e solicitação')
        ->assertSee('Usuário do portal Nimbus')
        ->assertSee('Código de referência')
        ->assertSee('Tipo de envio')
        ->assertSee('Nome do responsável');
});

it('renders the submission view page for authenticated admin users', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-submission-view@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Visualizacao',
        'email' => 'cliente.visualizacao@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-01KNHQMXVT88D6RF',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao para visualizacao',
        'responsible_name' => 'Cliente Visualizacao',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Visualizacao',
        'main_activity' => 'Servicos financeiros',
        'phone' => '1130304040',
        'website' => 'https://example.com.br',
        'net_worth' => 100000,
        'annual_revenue' => 250000,
        'registrant_name' => 'Cliente Visualizacao',
        'registrant_position' => 'Diretor',
        'registrant_cpf' => '12345678901',
        'registrant_rg' => '123456789',
        'status' => 'PENDING',
        'submitted_at' => now(),
        'created_ip' => '127.0.0.1',
        'created_user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0',
        'status_updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(SubmissionResource::getUrl('view', ['record' => $submission], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Detalhes do Envio')
        ->assertSee('Informações Complementares')
        ->assertSeeInOrder([
            'Dados da Empresa',
            'Indicadores Financeiros',
            'Dados do Cadastrante',
        ])
        ->assertSee('Dados da Empresa')
        ->assertSee('Indicadores Financeiros')
        ->assertSee('Dados do Cadastrante')
        ->assertSee('Trilha de Auditoria')
        ->assertSee('Metadados do Registro')
        ->assertSee('User Agent da Sessão')
        ->assertDontSee('Anexos Recebidos')
        ->assertSee('NMB-01KNHQMXVT88D6RF')
        ->assertSee('Empresa Visualizacao')
        ->assertSee('Mozilla/5.0')
        ->assertSee('127.0.0.1')
        ->assertSee('fi-font-mono', false)
        ->assertSee('fi-wrapped', false);
});

it('renders the portal users list under Administração', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-portal-users@example.com',
    ]);
    $user->assignRole('admin');

    PortalUser::query()->create([
        'full_name' => 'Cliente Portal',
        'email' => 'cliente.portal@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($user)
        ->get(PortalUserResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Usuários do Portal')
        ->assertSee('Novo usuário')
        ->assertSee('Nome completo')
        ->assertSee('E-mail')
        ->assertSee('Gerar chave')
        ->assertSee('Cliente Portal')
        ->assertSee('123.456.789-01')
        ->assertSee('(11) 99999-9999');
});

it('renders the portal user create form with the same core fields as the NimbusDocs reference', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-portal-users-create@example.com',
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(PortalUserResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Novo Usuário')
        ->assertSee('Dados Cadastrais')
        ->assertSee('Status da Conta')
        ->assertSee('Nome')
        ->assertSee('CPF')
        ->assertSee('Telefone/Celular')
        ->assertSee('Situação')
        ->assertSee('000.000.000-00')
        ->assertSee('(00) 00000-0000')
        ->assertSee('Ativo')
        ->assertSee('Inativo')
        ->assertSee('Suspenso')
        ->assertDontSee('Aguardando Cadastro')
        ->assertDontSee('ID externo')
        ->assertDontSee('Observações')
        ->assertDontSee('Último acesso')
        ->assertDontSee('Método do último acesso');
});

it('renders the access keys list under Administração', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-access-tokens@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Token',
        'email' => 'cliente.token@example.com',
        'document_number' => '98765432100',
        'phone_number' => '11888888888',
        'status' => 'ACTIVE',
    ]);

    AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code' => 'ABCD-EF12-3456',
        'status' => 'PENDING',
        'expires_at' => now()->addDays(3),
    ]);

    expect(AccessTokenResource::canCreate())->toBeFalse()
        ->and(AccessTokenResource::hasPage('view'))->toBeTrue();

    $this->actingAs($user)
        ->get(AccessTokenResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Chaves de Acesso')
        ->assertSee('Usuário do portal')
        ->assertSee('Código')
        ->assertSee('ABCD-EF12-3456')
        ->assertSee('Válida')
        ->assertSee('Revogar');
});

it('renders the announcements list under Comunicação', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-announcements@example.com',
    ]);
    $user->assignRole('admin');

    Announcement::query()->create([
        'title' => 'Manutenção Programada',
        'body' => 'O portal ficará indisponível durante a madrugada.',
        'level' => 'warning',
        'starts_at' => now(),
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(AnnouncementResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Avisos Gerais')
        ->assertSee('Novo aviso')
        ->assertSee('Manutenção Programada')
        ->assertSee('Atenção');
});

it('renders the notification outbox under Comunicação', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-outbox@example.com',
    ]);
    $user->assignRole('admin');

    NotificationOutbox::factory()->create([
        'type' => 'submission_received',
        'recipient_email' => 'cliente.notificado@example.com',
        'subject' => 'Submissão recebida',
        'template' => 'submission_received',
        'payload_json' => ['submission' => ['id' => 1]],
        'status' => 'FAILED',
        'attempts' => 2,
        'max_attempts' => 5,
        'last_error' => 'SMTP timeout',
    ]);

    expect(NotificationOutboxResource::canCreate())->toBeFalse()
        ->and(NotificationOutboxResource::hasPage('view'))->toBeTrue();

    $this->actingAs($user)
        ->get(NotificationOutboxResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Auditoria de Envios')
        ->assertSee('cliente.notificado@example.com')
        ->assertSee('Submissão recebida')
        ->assertSee('Falhou');
});

it('renders and saves notification settings under Comunicação', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-notification-settings@example.com',
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(NotificationSettings::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Configurar Notificações')
        ->assertSee('Portal do Usuário')
        ->assertSee('Nova submissão')
        ->assertSee('Alteração de status')
        ->assertSee('Documento de resposta')
        ->assertSee('Link de acesso')
        ->assertSee('Microsoft 365 / Outlook')
        ->assertSee('Conectar conta corporativa')
        ->assertSee('Ver Auditoria de Envios');

    Livewire::test(NotificationSettings::class)
        ->set('data.portal_notify_new_submission', false)
        ->set('data.portal_notify_status_change', true)
        ->set('data.portal_notify_response_upload', false)
        ->set('data.portal_notify_access_link', true)
        ->call('save');

    expect(NotificationSetting::getValues([
        'portal.notify.new_submission',
        'portal.notify.status_change',
        'portal.notify.response_upload',
        'portal.notify.access_link',
    ]))
        ->toMatchArray([
            'portal.notify.new_submission' => '0',
            'portal.notify.status_change' => '1',
            'portal.notify.response_upload' => '0',
            'portal.notify.access_link' => '1',
        ]);
});

it('shows the microsoft connection as connected when corporate credentials are configured', function () {
    config()->set('services.outlook.tenant_id', 'tenant-id');
    config()->set('services.outlook.client_id', 'client-id');
    config()->set('services.outlook.client_secret', 'client-secret');
    config()->set('services.outlook.mailbox', 'nimbus@empresa.com.br');

    Livewire::test(NotificationSettings::class)
        ->assertSee('Conectado')
        ->assertSee('Revisar conexão corporativa')
        ->assertDontSee('Pendências:');
});

it('renders the document categories list under Gestão Documental', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-document-categories@example.com',
    ]);
    $user->assignRole('admin');

    DocumentCategory::query()->create([
        'name' => 'Institucional',
    ]);

    $this->actingAs($user)
        ->get(DocumentCategoryResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Categorias de Documentos')
        ->assertSee('Nova categoria')
        ->assertSee('Institucional')
        ->assertSee('Documentos vinculados');
});

it('renders the biblioteca geral list under Gestão Documental', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-general-documents@example.com',
    ]);
    $user->assignRole('admin');

    $category = DocumentCategory::query()->create([
        'name' => 'Institucional',
    ]);

    GeneralDocument::query()->create([
        'nimbus_category_id' => $category->id,
        'title' => 'Manual do Investidor',
        'description' => 'Documento institucional para consulta geral.',
        'file_path' => 'nimbus/general-documents/manual-investidor.pdf',
        'file_original_name' => 'manual-investidor.pdf',
        'file_size' => 204800,
        'file_mime' => 'application/pdf',
        'is_active' => true,
        'published_at' => now(),
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(GeneralDocumentResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Biblioteca Geral')
        ->assertSee('Novo documento geral')
        ->assertSee('Manual do Investidor')
        ->assertSee('Institucional');
});

it('renders the portal documents list under Gestão Documental', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-portal-documents@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Documentos',
        'email' => 'cliente.documentos@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => 'Contrato Social',
        'description' => 'Arquivo societário do cliente.',
        'file_path' => 'nimbus/portal-documents/contrato-social.pdf',
        'file_original_name' => 'contrato-social.pdf',
        'file_size' => 153600,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(PortalDocumentResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Documentos por Usuário')
        ->assertSee('Novo documento do usuário')
        ->assertSee('Cliente Documentos')
        ->assertSee('Contrato Social');
});

it('renders the document management create forms in Portuguese', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-document-management-create@example.com',
    ]);
    $user->assignRole('admin');

    $category = DocumentCategory::query()->create([
        'name' => 'Institucional',
    ]);

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Formulário',
        'email' => 'cliente.formulario@example.com',
        'document_number' => '98765432100',
        'phone_number' => '11888888888',
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($user)
        ->get(DocumentCategoryResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Nova Categoria de Documento')
        ->assertSee('Nome da categoria');

    $this->actingAs($user)
        ->get(GeneralDocumentResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Novo Documento Geral')
        ->assertSee('Categoria')
        ->assertSee('Arquivo')
        ->assertSee($category->name);

    $this->actingAs($user)
        ->get(PortalDocumentResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Novo Documento do Usuário')
        ->assertSee('Usuário do portal')
        ->assertSee('Arquivo');
});
