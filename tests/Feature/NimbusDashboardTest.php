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
use App\Filament\Resources\Nimbus\Submissions\Pages\ViewSubmission;
use App\Filament\Resources\Nimbus\Submissions\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Nimbus\Submissions\RelationManagers\ShareholdersRelationManager;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('allows admin users to preview and download submission files', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-file-access@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Download',
        'email' => 'cliente.download@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-FILE-ACCESS-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao com acesso a arquivo',
        'responsible_name' => 'Cliente Download',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Download',
        'status' => 'PENDING',
        'submitted_at' => now(),
    ]);

    $storagePath = 'nimbus/submissions/testing/'.uniqid('dre-', true).'.pdf';

    Storage::disk('local')->put($storagePath, 'fake-pdf-content');

    $file = $submission->files()->create([
        'document_type' => 'DRE',
        'origin' => 'USER',
        'visible_to_user' => false,
        'original_name' => 'dre.pdf',
        'stored_name' => 'dre.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'storage_path' => $storagePath,
        'uploaded_at' => now(),
    ]);

    $previewResponse = $this->actingAs($user)
        ->get(route('admin.nimbus.submissions.files.preview', $file));

    $previewResponse->assertSuccessful();

    expect($previewResponse->headers->get('content-type'))
        ->toContain('application/pdf')
        ->and($previewResponse->headers->get('content-disposition'))
        ->toContain('inline;')
        ->toContain('dre.pdf');

    $downloadResponse = $this->actingAs($user)
        ->get(route('admin.nimbus.submissions.files.download', $file));

    $downloadResponse->assertSuccessful();

    expect($downloadResponse->headers->get('content-disposition'))
        ->toContain('attachment;')
        ->toContain('dre.pdf');
});

it('registers a dedicated Nimbus dashboard route inside the admin panel', function () {
    $this->get(route('filament.admin.pages.nimbus-dashboard'))
        ->assertRedirect('/admin/login');
});

it('renders the admin login page', function () {
    $this->get('/admin/login')
        ->assertSuccessful()
        ->assertSee('Entrar no sistema');
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
        ->and(NotificationSettings::getNavigationLabel())->toBe('Configurações de notificações')
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

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Lista',
        'email' => 'cliente.lista@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-LIST-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação listada',
        'responsible_name' => 'Cliente Lista',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa da Lista',
        'status' => Submission::STATUS_NEEDS_CORRECTION,
        'submitted_at' => now(),
    ]);

    expect(SubmissionResource::canCreate())->toBeFalse()
        ->and(SubmissionResource::hasPage('create'))->toBeFalse();

    $this->actingAs($user)
        ->get(SubmissionResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Envios e Solicitações')
        ->assertDontSee('Criar envio e solicitação')
        ->assertSee('CNPJ da Empresa')
        ->assertSee('Empresa')
        ->assertSee('Status')
        ->assertSee('Visualizar')
        ->assertSee('12.345.678/0001-90')
        ->assertSee('Empresa da Lista')
        ->assertSee('Aguardando Correção')
        ->assertDontSee('Usuário do portal Nimbus')
        ->assertDontSee('Código de referência')
        ->assertDontSee('Tipo de envio')
        ->assertDontSee('Nome do responsável');
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
        ->assertSeeInOrder([
            'Detalhes do Envio',
            'Informações Complementares',
            'Documentos de Retorno',
            'Timeline da Submissão',
            'Trilha de Auditoria',
        ])
        ->assertSeeInOrder([
            'Dados da Empresa',
            'Indicadores Financeiros',
            'Dados do Cadastrante',
        ])
        ->assertSee('Dados da Empresa')
        ->assertSee('Indicadores Financeiros')
        ->assertSee('Dados do Cadastrante')
        ->assertSee('Trilha de Auditoria')
        ->assertSee('Documentos de Retorno')
        ->assertSee('Anexar resposta')
        ->assertSee('Nenhuma observação interna foi registrada neste envio até o momento.')
        ->assertSee('Sócios')
        ->assertSee('Arquivos')
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

it('identifies portal replies in the submission timeline for admins', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-submission-timeline@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Timeline',
        'email' => 'cliente.timeline@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-TIMELINE-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao com resposta do portal',
        'responsible_name' => 'Responsável Timeline',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Timeline',
        'status' => Submission::STATUS_UNDER_REVIEW,
        'submitted_at' => now(),
    ]);

    $submission->notes()->create([
        'user_id' => null,
        'visibility' => 'ADMIN_ONLY',
        'message' => 'Documento societário reenviado pelo solicitante.',
    ]);

    $this->actingAs($user)
        ->get(SubmissionResource::getUrl('view', ['record' => $submission], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Cliente Timeline (Solicitante do Portal)')
        ->assertSee('Documento societário reenviado pelo solicitante.');
});

it('allows admin users to upload return documents and renders them in the dedicated section', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-submission-response-files@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Retorno',
        'email' => 'cliente.retorno@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-RETURN-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao com documentos de retorno',
        'responsible_name' => 'Cliente Retorno',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Retorno',
        'status' => 'PENDING',
        'submitted_at' => now(),
    ]);

    $viewUrl = SubmissionResource::getUrl('view', ['record' => $submission], panel: 'admin');

    $this->actingAs($user)
        ->from($viewUrl)
        ->post(route('admin.nimbus.submissions.response-files.store', $submission), [
            'response_files' => [
                UploadedFile::fake()->create('parecer.pdf', 128, 'application/pdf'),
                UploadedFile::fake()->create('planilha.xlsx', 256, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ],
        ])
        ->assertRedirect($viewUrl);

    $responseFiles = $submission->fresh()->responseFiles()->get();
    $pdfResponseFile = $responseFiles->firstWhere('original_name', 'parecer.pdf');
    $sheetResponseFile = $responseFiles->firstWhere('original_name', 'planilha.xlsx');

    expect($responseFiles)->toHaveCount(2)
        ->and($pdfResponseFile)->not->toBeNull()
        ->and($sheetResponseFile)->not->toBeNull()
        ->and($pdfResponseFile?->origin)->toBe('ADMIN')
        ->and($pdfResponseFile?->visible_to_user)->toBeTrue()
        ->and($pdfResponseFile?->document_type)->toBe('OTHER')
        ->and($pdfResponseFile?->versions()->count())->toBe(1)
        ->and($pdfResponseFile?->versions()->first()?->uploaded_by_type)->toBe('ADMIN')
        ->and(Storage::disk('local')->exists((string) $pdfResponseFile?->storage_path))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $sheetResponseFile?->storage_path))->toBeTrue();

    $this->actingAs($user)
        ->get($viewUrl)
        ->assertSuccessful()
        ->assertSee('Documentos de Retorno')
        ->assertSee('parecer.pdf')
        ->assertSee('planilha.xlsx')
        ->assertSee('Disponível no portal')
        ->assertSee('Anexar resposta')
        ->assertSee(route('admin.nimbus.submissions.response-files.store', $submission), false);
});

it('mirrors the original NimbusDocs status review options on the submission action modal', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-submission-status@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Status',
        'email' => 'cliente.status@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-STATUS-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao com revisao de status',
        'responsible_name' => 'Cliente Status',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Status',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(ViewSubmission::class, [
        'record' => $submission->getRouteKey(),
    ])
        ->assertActionExists('alterar_situacao')
        ->mountAction('alterar_situacao')
        ->assertSchemaStateSet([
            'status' => Submission::STATUS_PENDING,
            'visibility' => 'USER_VISIBLE',
            'note' => null,
        ])
        ->assertMountedActionModalSee([
            'Nova Situação',
            'Visibilidade da Observação',
            'Observação / Comentário (opcional)',
            'Aprovar',
            'Solicitar Correção',
            'Rejeitar',
            'Enviar Comentário Interno',
        ])
        ->setActionData([
            'status' => Submission::STATUS_UNDER_REVIEW,
            'visibility' => 'USER_VISIBLE',
            'note' => 'Corrigir a documentação societária enviada.',
        ])
        ->callMountedAction(arguments: [
            'intent' => 'request_correction',
        ]);

    expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_CORRECTION)
        ->and($submission->fresh()->status_updated_by)->toBe($user->id)
        ->and($submission->fresh()->status_updated_at)->not->toBeNull()
        ->and($submission->notes()->count())->toBe(1)
        ->and($submission->notes()->first()?->visibility)->toBe('USER_VISIBLE')
        ->and($submission->notes()->first()?->message)->toBe('Corrigir a documentação societária enviada.');
});

it('falls back to the legacy review status when the database enum does not yet support needs correction', function () {
    config()->set('nimbus.submissions.supports_needs_correction_status', false);

    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-submission-legacy-status@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Legado',
        'email' => 'cliente.legado@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-STATUS-LEGACY-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao com banco legado',
        'responsible_name' => 'Cliente Legado',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Legado',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(ViewSubmission::class, [
        'record' => $submission->getRouteKey(),
    ])
        ->mountAction('alterar_situacao')
        ->setActionData([
            'status' => Submission::STATUS_UNDER_REVIEW,
            'visibility' => 'USER_VISIBLE',
            'note' => 'Corrigir a documentação societária enviada.',
        ])
        ->callMountedAction(arguments: [
            'intent' => 'request_correction',
        ])
        ->assertHasNoActionErrors();

    expect($submission->fresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($submission->notes()->count())->toBe(1)
        ->and($submission->notes()->first()?->message)->toBe('Corrigir a documentação societária enviada.');
});

it('renders shareholder participation percentages in the relation manager', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-shareholders@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Socios',
        'email' => 'cliente.socios@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-SHARE-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao com socios',
        'responsible_name' => 'Cliente Socios',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Socios',
        'status' => 'PENDING',
        'submitted_at' => now(),
    ]);

    $firstShareholder = $submission->shareholders()->create([
        'name' => 'Caroline Evelyn Nogueira',
        'percentage' => 32.5,
    ]);

    $secondShareholder = $submission->shareholders()->create([
        'name' => 'Rosângela Esther da Mota',
        'percentage' => 17.25,
    ]);

    $this->actingAs($user);

    Livewire::test(ShareholdersRelationManager::class, [
        'ownerRecord' => $submission,
        'pageClass' => ViewSubmission::class,
    ])
        ->assertCanSeeTableRecords([$firstShareholder, $secondShareholder], inOrder: true)
        ->assertTableColumnExists('percentage')
        ->assertTableColumnFormattedStateSet('percentage', '32,50%', $firstShareholder)
        ->assertTableColumnFormattedStateSet('percentage', '17,25%', $secondShareholder);
});

it('renders original NimbusDocs document labels in the files relation manager', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-files@example.com',
    ]);
    $user->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Arquivos',
        'email' => 'cliente.arquivos@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-FILES-001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitacao com arquivos',
        'responsible_name' => 'Cliente Arquivos',
        'company_cnpj' => '12.345.678/0001-90',
        'company_name' => 'Empresa Arquivos',
        'status' => 'PENDING',
        'submitted_at' => now(),
    ]);

    $balanceSheet = $submission->files()->create([
        'document_type' => 'BALANCE_SHEET',
        'origin' => 'USER',
        'visible_to_user' => false,
        'original_name' => '0.pdf',
        'stored_name' => 'balance-sheet.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'storage_path' => 'nimbus/submissions/1/balance-sheet.pdf',
        'uploaded_at' => now(),
    ]);

    $dre = $submission->files()->create([
        'document_type' => 'DRE',
        'origin' => 'USER',
        'visible_to_user' => false,
        'original_name' => '1.pdf',
        'stored_name' => 'dre.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'storage_path' => 'nimbus/submissions/1/dre.pdf',
        'uploaded_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(FilesRelationManager::class, [
        'ownerRecord' => $submission,
        'pageClass' => ViewSubmission::class,
    ])
        ->assertCanSeeTableRecords([$balanceSheet, $dre], inOrder: true)
        ->assertTableColumnExists('document_type_label')
        ->assertTableColumnStateSet('document_type_label', 'Último Balanço', $balanceSheet)
        ->assertTableColumnStateSet('document_type_label', 'DRE (Demonstração do Resultado do Exercício)', $dre)
        ->assertTableColumnDoesNotExist('original_name')
        ->assertTableActionExists('visualizar', null, $balanceSheet)
        ->assertTableActionHasUrl('visualizar', route('admin.nimbus.submissions.files.preview', $balanceSheet), $balanceSheet)
        ->assertTableActionShouldOpenUrlInNewTab('visualizar', $balanceSheet)
        ->assertTableActionExists('baixar', null, $balanceSheet)
        ->assertTableActionHasUrl('baixar', route('admin.nimbus.submissions.files.download', $balanceSheet), $balanceSheet);
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
        ->assertSee('Configurações de notificações')
        ->assertSee('Notificações do portal')
        ->assertSee('Nova submissão')
        ->assertSee('Alteração de status')
        ->assertSee('Documento de resposta')
        ->assertSee('Link de acesso')
        ->assertSee('Microsoft 365 / Outlook')
        ->assertSee('Conectar conta corporativa')
        ->assertSee('Salvar configurações')
        ->assertSee('Ver auditoria de envios');

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
