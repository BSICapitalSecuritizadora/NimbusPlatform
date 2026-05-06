<?php

use App\Filament\Resources\Nimbus\GeneralDocuments\Schemas\GeneralDocumentForm;
use App\Filament\Resources\Nimbus\PortalDocuments\Schemas\PortalDocumentForm;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\User;
use App\Services\DocumentStorageService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component as LivewireComponent;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::set(DocumentStorageService::PRIVATE_DISK, Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/local-'.uniqid()),
        'throw' => false,
    ]));
});

it('renders working Nimbus portal navigation and dashboard actions', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Portal',
        'email' => 'teste.portal@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Cadastro Inicial',
        'responsible_name' => 'Teste Portal',
        'company_cnpj' => '12.345.678/0001-99',
        'company_name' => 'Empresa Teste',
        'phone' => '(11) 99999-9999',
        'status' => 'PENDING',
        'submitted_at' => now(),
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.dashboard'))
        ->assertSuccessful()
        ->assertSee('src="'.asset('images/bsi-capital-logo.png').'"', false)
        ->assertSee('Painel do cliente')
        ->assertSee('Resumo operacional')
        ->assertDontSee('https://bsicapital.com.br/wp-content/uploads/2022/06/logo.png', false)
        ->assertDontSee('CLIENTE · #BSI-')
        ->assertSee('href="'.route('nimbus.submissions.index').'"', false)
        ->assertSee('href="'.route('nimbus.submissions.create').'"', false)
        ->assertSee('href="'.route('nimbus.documents.index').'"', false)
        ->assertSee('href="'.route('nimbus.submissions.show', $submission).'"', false);
});

it('renders the updated optional document labels in the registration form', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Formulario',
        'email' => 'teste.formulario@example.com',
        'document_number' => '12345678904',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.create'))
        ->assertSuccessful()
        ->assertSee('Documentos (PDF)')
        ->assertSee('Procuração (Caso houver)')
        ->assertSee('Ata de eleição de diretoria');
});

it('serves Nimbus documents from the private disk even when the default filesystem is public', function () {
    config()->set('filesystems.default', 'public');

    Storage::set('local', Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/local-'.uniqid()),
        'throw' => false,
    ]));
    Storage::set('public', Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/public-'.uniqid()),
        'throw' => false,
    ]));

    $adminUser = User::factory()->create();
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Documentos Privados',
        'email' => 'teste.documentos.privados@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $category = DocumentCategory::query()->create([
        'name' => 'Governança',
    ]);

    $portalDocumentPath = DocumentStorageService::PRIVATE_PREFIX.'/portal-documents/contrato-social.pdf';
    $generalDocumentPath = DocumentStorageService::PRIVATE_PREFIX.'/general-documents/politica-kyc.pdf';

    Storage::disk('local')->put($portalDocumentPath, 'portal-document');
    Storage::disk('local')->put($generalDocumentPath, 'general-document');

    $portalDocument = PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => 'Contrato Social',
        'description' => 'Documento societário privado.',
        'file_path' => $portalDocumentPath,
        'file_original_name' => 'contrato-social.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $adminUser->id,
    ]);

    $generalDocument = GeneralDocument::query()->create([
        'nimbus_category_id' => $category->id,
        'title' => 'Política KYC',
        'description' => 'Documento institucional sigiloso.',
        'file_path' => $generalDocumentPath,
        'file_original_name' => 'politica-kyc.pdf',
        'file_size' => 2048,
        'file_mime' => 'application/pdf',
        'is_active' => true,
        'created_by_user_id' => $adminUser->id,
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.preview', $portalDocument))
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="contrato-social.pdf"');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.download', $portalDocument))
        ->assertDownload('contrato-social.pdf');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.general.preview', $generalDocument))
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="politica-kyc.pdf"');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.general.download', $generalDocument))
        ->assertDownload('politica-kyc.pdf');
});

it('serves document previews and downloads through the renamed admin URLs', function () {
    Storage::set('local', Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/local-'.uniqid()),
        'throw' => false,
    ]));

    $adminUser = User::factory()->withTwoFactor()->create([
        'email' => 'admin.documentos.externos@example.com',
    ]);

    Permission::findOrCreate('nimbus.general-documents.view');
    Permission::findOrCreate('nimbus.portal-documents.view');
    $adminUser->givePermissionTo([
        'nimbus.general-documents.view',
        'nimbus.portal-documents.view',
    ]);

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Documentos Admin',
        'email' => 'teste.documentos.admin@example.com',
        'document_number' => '12345678903',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $category = DocumentCategory::query()->create([
        'name' => 'Compliance',
    ]);

    $portalDocumentPath = DocumentStorageService::PRIVATE_PREFIX.'/portal-documents/admin-contrato.pdf';
    $generalDocumentPath = DocumentStorageService::PRIVATE_PREFIX.'/general-documents/admin-politica.pdf';

    Storage::disk('local')->put($portalDocumentPath, 'portal-admin-document');
    Storage::disk('local')->put($generalDocumentPath, 'general-admin-document');

    $portalDocument = PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => 'Contrato Admin',
        'description' => 'Documento privado para admin.',
        'file_path' => $portalDocumentPath,
        'file_original_name' => 'admin-contrato.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $adminUser->id,
    ]);

    $generalDocument = GeneralDocument::query()->create([
        'nimbus_category_id' => $category->id,
        'title' => 'Política Admin',
        'description' => 'Documento geral para admin.',
        'file_path' => $generalDocumentPath,
        'file_original_name' => 'admin-politica.pdf',
        'file_size' => 2048,
        'file_mime' => 'application/pdf',
        'is_active' => true,
        'created_by_user_id' => $adminUser->id,
    ]);

    expect(route('admin.nimbus.documents.general.preview', $generalDocument, false))
        ->toStartWith('/admin/gestao-documental-externa/documents/general/');

    $this->actingAs($adminUser)
        ->get(route('admin.nimbus.documents.general.preview', $generalDocument))
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="admin-politica.pdf"');

    $this->actingAs($adminUser)
        ->get(route('admin.nimbus.documents.general.download', $generalDocument))
        ->assertDownload('admin-politica.pdf');

    $this->actingAs($adminUser)
        ->get(route('admin.nimbus.documents.portal.preview', $portalDocument))
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="admin-contrato.pdf"');

    $this->actingAs($adminUser)
        ->get(route('admin.nimbus.documents.portal.download', $portalDocument))
        ->assertDownload('admin-contrato.pdf');
});

it('pins Nimbus backoffice uploads to the private local disk', function () {
    $livewire = makeNimbusSchemaTestLivewire();

    $portalSchema = PortalDocumentForm::configure(Schema::make($livewire));
    $generalSchema = GeneralDocumentForm::configure(Schema::make($livewire));

    $portalUpload = collect(flattenNimbusSchemaComponents($portalSchema))
        ->first(fn (mixed $component): bool => $component instanceof FileUpload && $component->getName() === 'file_path');

    $generalUpload = collect(flattenNimbusSchemaComponents($generalSchema))
        ->first(fn (mixed $component): bool => $component instanceof FileUpload && $component->getName() === 'file_path');

    expect($portalUpload)->toBeInstanceOf(FileUpload::class)
        ->and($portalUpload?->getDiskName())->toBe(DocumentStorageService::PRIVATE_DISK)
        ->and($portalUpload?->getDirectory())->toBe(DocumentStorageService::PRIVATE_PREFIX.'/portal-documents')
        ->and($generalUpload)->toBeInstanceOf(FileUpload::class)
        ->and($generalUpload?->getDiskName())->toBe(DocumentStorageService::PRIVATE_DISK)
        ->and($generalUpload?->getDirectory())->toBe(DocumentStorageService::PRIVATE_PREFIX.'/general-documents');
});

it('renders the correction status label in the portal pages', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Correção',
        'email' => 'teste.correcao@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-CORRECAO-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Cadastro com correção',
        'responsible_name' => 'Teste Correção',
        'company_cnpj' => '12.345.678/0001-99',
        'company_name' => 'Empresa Correção',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_NEEDS_CORRECTION,
        'submitted_at' => now(),
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.dashboard'))
        ->assertSuccessful()
        ->assertSee('Aguardando Correção');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index'))
        ->assertSuccessful()
        ->assertSee('Aguardando Correção');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.show', $submission))
        ->assertSuccessful()
        ->assertSee('Aguardando Correção');
});

it('filters the portal submissions list by status tabs', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Filtros',
        'email' => 'teste.filtros@example.com',
        'document_number' => '12345678909',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $pendingSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-PENDING-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação Pendente',
        'responsible_name' => 'Teste Filtros',
        'company_cnpj' => '12.345.678/0001-10',
        'company_name' => 'Empresa Pendente',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now()->subDays(4),
    ]);

    $correctionSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-CORRECTION-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação em Correção',
        'responsible_name' => 'Teste Filtros',
        'company_cnpj' => '12.345.678/0001-11',
        'company_name' => 'Empresa Correção',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_NEEDS_CORRECTION,
        'submitted_at' => now()->subDays(3),
    ]);

    $reviewSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-REVIEW-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação em Análise',
        'responsible_name' => 'Teste Filtros',
        'company_cnpj' => '12.345.678/0001-12',
        'company_name' => 'Empresa Análise',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_UNDER_REVIEW,
        'submitted_at' => now()->subDays(2),
    ]);

    $completedSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-COMPLETED-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação Concluída',
        'responsible_name' => 'Teste Filtros',
        'company_cnpj' => '12.345.678/0001-13',
        'company_name' => 'Empresa Concluída',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_COMPLETED,
        'submitted_at' => now()->subDay(),
    ]);

    $rejectedSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-REJECTED-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação Rejeitada',
        'responsible_name' => 'Teste Filtros',
        'company_cnpj' => '12.345.678/0001-14',
        'company_name' => 'Empresa Rejeitada',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_REJECTED,
        'submitted_at' => now(),
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index'))
        ->assertSuccessful()
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['period' => '90'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['status' => 'pending', 'period' => '90'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['status' => 'under_review', 'period' => '90'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['status' => 'completed', 'period' => '90'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['status' => 'rejected', 'period' => '90'])).'"', false);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['status' => 'pending']))
        ->assertSuccessful()
        ->assertSee($pendingSubmission->company_name)
        ->assertSee($correctionSubmission->company_name)
        ->assertDontSee($reviewSubmission->company_name)
        ->assertDontSee($completedSubmission->company_name)
        ->assertDontSee($rejectedSubmission->company_name);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['status' => 'under_review']))
        ->assertSuccessful()
        ->assertSee($reviewSubmission->company_name)
        ->assertDontSee($pendingSubmission->company_name)
        ->assertDontSee($correctionSubmission->company_name)
        ->assertDontSee($completedSubmission->company_name)
        ->assertDontSee($rejectedSubmission->company_name);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['status' => 'completed']))
        ->assertSuccessful()
        ->assertSee($completedSubmission->company_name)
        ->assertDontSee($pendingSubmission->company_name)
        ->assertDontSee($correctionSubmission->company_name)
        ->assertDontSee($reviewSubmission->company_name)
        ->assertDontSee($rejectedSubmission->company_name);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['status' => 'rejected']))
        ->assertSuccessful()
        ->assertSee($rejectedSubmission->company_name)
        ->assertDontSee($pendingSubmission->company_name)
        ->assertDontSee($correctionSubmission->company_name)
        ->assertDontSee($reviewSubmission->company_name)
        ->assertDontSee($completedSubmission->company_name);
});

it('filters the portal submissions list by operation and period controls', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Escopo',
        'email' => 'teste.escopo@example.com',
        'document_number' => '12345678908',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $recentRegistrationSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-REG-RECENT-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Cadastro recente',
        'responsible_name' => 'Teste Escopo',
        'company_cnpj' => '12.345.678/0001-21',
        'company_name' => 'Empresa Cadastro Recente',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now()->subDays(10),
    ]);

    $recentFollowUpSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-FOLLOW-UP-0001',
        'submission_type' => 'FOLLOW_UP',
        'title' => 'Acompanhamento recente',
        'responsible_name' => 'Teste Escopo',
        'company_cnpj' => '12.345.678/0001-22',
        'company_name' => 'Empresa Follow Up',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_UNDER_REVIEW,
        'submitted_at' => now()->subDays(12),
    ]);

    $olderRegistrationSubmission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-REG-OLD-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Cadastro antigo',
        'responsible_name' => 'Teste Escopo',
        'company_cnpj' => '12.345.678/0001-23',
        'company_name' => 'Empresa Cadastro Antigo',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_COMPLETED,
        'submitted_at' => now()->subDays(120),
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index'))
        ->assertSuccessful()
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['status' => 'pending', 'period' => '90'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['operation' => 'REGISTRATION', 'period' => '90'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['operation' => 'FOLLOW_UP', 'period' => '90'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['period' => '30'])).'"', false)
        ->assertSee('href="'.e(route('nimbus.submissions.index', ['period' => 'all'])).'"', false);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['operation' => 'REGISTRATION', 'period' => '90']))
        ->assertSuccessful()
        ->assertSee($recentRegistrationSubmission->company_name)
        ->assertDontSee($recentFollowUpSubmission->company_name)
        ->assertDontSee($olderRegistrationSubmission->company_name);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['operation' => 'FOLLOW_UP', 'period' => '90']))
        ->assertSuccessful()
        ->assertSee($recentFollowUpSubmission->company_name)
        ->assertDontSee($recentRegistrationSubmission->company_name)
        ->assertDontSee($olderRegistrationSubmission->company_name);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['period' => '30']))
        ->assertSuccessful()
        ->assertSee($recentRegistrationSubmission->company_name)
        ->assertSee($recentFollowUpSubmission->company_name)
        ->assertDontSee($olderRegistrationSubmission->company_name);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index', ['period' => 'all']))
        ->assertSuccessful()
        ->assertSee($recentRegistrationSubmission->company_name)
        ->assertSee($recentFollowUpSubmission->company_name)
        ->assertSee($olderRegistrationSubmission->company_name);
});

it('shows pagination only when the submissions list has more than one page', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Paginação',
        'email' => 'teste.paginacao@example.com',
        'document_number' => '12345678907',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-PAGINATION-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Primeira solicitação',
        'responsible_name' => 'Teste Paginação',
        'company_cnpj' => '12.345.678/0001-31',
        'company_name' => 'Empresa Página Única',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now()->subDays(5),
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index'))
        ->assertSuccessful()
        ->assertDontSee('?page=2', false);

    Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-PAGINATION-0002',
        'submission_type' => 'REGISTRATION',
        'title' => 'Solicitação antiga fora da janela',
        'responsible_name' => 'Teste Paginação',
        'company_cnpj' => '12.345.678/0001-32',
        'company_name' => 'Empresa Fora do Período',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now()->subDays(120),
    ]);

    foreach (range(3, 12) as $index) {
        Submission::query()->create([
            'nimbus_portal_user_id' => $portalUser->id,
            'reference_code' => sprintf('SUB-PAGINATION-%04d', $index),
            'submission_type' => 'REGISTRATION',
            'title' => "Solicitação {$index}",
            'responsible_name' => 'Teste Paginação',
            'company_cnpj' => sprintf('12.345.678/0001-%02d', 30 + $index),
            'company_name' => "Empresa Página {$index}",
            'phone' => '(11) 99999-9999',
            'status' => Submission::STATUS_PENDING,
            'submitted_at' => now()->subDays(5),
        ]);
    }

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.index'))
        ->assertSuccessful()
        ->assertSee('?page=2', false)
        ->assertSee('Mostrando 1 a 10 de 11 solicitações');
});

it('shows portal-visible return documents and allows secure submission file downloads', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Retorno',
        'email' => 'teste.retorno@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-RETORNO-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Cadastro com retorno',
        'responsible_name' => 'Teste Retorno',
        'company_cnpj' => '12.345.678/0001-99',
        'company_name' => 'Empresa Retorno',
        'phone' => '(11) 99999-9999',
        'website' => 'https://empresa-retorno.example.com.br',
        'net_worth' => 1500000,
        'annual_revenue' => 9800000,
        'registrant_name' => 'Anderson de Souza',
        'registrant_position' => 'Diretor Financeiro',
        'registrant_rg' => '12.345.678-9',
        'registrant_cpf' => '123.456.789-00',
        'is_us_person' => false,
        'is_pep' => false,
        'is_anbima_affiliated' => true,
        'status' => Submission::STATUS_UNDER_REVIEW,
        'submitted_at' => now(),
    ]);

    $userFilePath = 'nimbus/submissions/'.$submission->id.'/balance-sheet.pdf';
    $visibleResponsePath = 'nimbus/submissions/'.$submission->id.'/responses/parecer.pdf';
    $internalResponsePath = 'nimbus/submissions/'.$submission->id.'/responses/interno.pdf';

    Storage::disk('local')->put($userFilePath, 'balance-sheet');
    Storage::disk('local')->put($visibleResponsePath, 'parecer');
    Storage::disk('local')->put($internalResponsePath, 'interno');

    $userFile = $submission->files()->create([
        'document_type' => 'BALANCE_SHEET',
        'origin' => 'USER',
        'visible_to_user' => false,
        'original_name' => 'balanco.pdf',
        'stored_name' => 'balance-sheet.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'storage_path' => $userFilePath,
        'uploaded_at' => now(),
    ]);

    $visibleResponseFile = $submission->files()->create([
        'document_type' => 'OTHER',
        'origin' => 'ADMIN',
        'visible_to_user' => true,
        'original_name' => 'parecer.pdf',
        'stored_name' => 'parecer.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'storage_path' => $visibleResponsePath,
        'uploaded_at' => now(),
    ]);

    $internalResponseFile = $submission->files()->create([
        'document_type' => 'OTHER',
        'origin' => 'ADMIN',
        'visible_to_user' => false,
        'original_name' => 'interno.pdf',
        'stored_name' => 'interno.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'storage_path' => $internalResponsePath,
        'uploaded_at' => now(),
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.show', $submission))
        ->assertSuccessful()
        ->assertSee('SUB-RETORNO-0001')
        ->assertSee('Resumo da solicitação')
        ->assertSee('Status Atual')
        ->assertSee('Arquivos Enviados')
        ->assertSee('Retornos Disponíveis')
        ->assertSee('Dados da Empresa')
        ->assertSee('Telefone')
        ->assertSee('https://empresa-retorno.example.com.br')
        ->assertSee('R$ 1.500.000,00')
        ->assertSee('R$ 9.800.000,00')
        ->assertSee('Dados do Responsável pelo Cadastro')
        ->assertSee('Anderson de Souza')
        ->assertSee('Diretor Financeiro')
        ->assertSee('12.345.678-9')
        ->assertSee('123.456.789-00')
        ->assertSee('Declarações')
        ->assertSee('Não me enquadro nas opções')
        ->assertSee('Você é filiado à Anbima?')
        ->assertDontSee('Código de acompanhamento no portal')
        ->assertSee('submission-scroll-area', false)
        ->assertSee('Arquivos Anexos')
        ->assertSee('nd-sticky-sidebar', false)
        ->assertSee('Documentos de Retorno')
        ->assertSee('balanco.pdf')
        ->assertSee('parecer.pdf')
        ->assertDontSee('interno.pdf');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.files.download', [$submission, $userFile]))
        ->assertDownload('balanco.pdf');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.files.download', [$submission, $visibleResponseFile]))
        ->assertDownload('parecer.pdf');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.files.download', [$submission, $internalResponseFile]))
        ->assertNotFound();
});

it('shows only user-visible submission messages in the portal', function () {
    $adminUser = User::factory()->create([
        'name' => 'Equipe Nimbus',
    ]);

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Mensagem',
        'email' => 'teste.mensagem@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-NOTE-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Cadastro com observação',
        'responsible_name' => 'Teste Mensagem',
        'company_cnpj' => '12.345.678/0001-99',
        'company_name' => 'Empresa Mensagem',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_NEEDS_CORRECTION,
        'submitted_at' => now(),
    ]);

    $submission->notes()->create([
        'user_id' => $adminUser->id,
        'visibility' => 'USER_VISIBLE',
        'message' => 'Ajuste a documentação societária e reenvie o contrato social atualizado.',
    ]);

    $submission->notes()->create([
        'user_id' => $adminUser->id,
        'visibility' => 'ADMIN_ONLY',
        'message' => 'Comentário interno que não deve aparecer no portal.',
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.show', $submission))
        ->assertSuccessful()
        ->assertSee('Mensagens da Equipe')
        ->assertSee('Equipe Nimbus')
        ->assertSee('Ajuste a documentação societária e reenvie o contrato social atualizado.')
        ->assertDontSee('Comentário interno que não deve aparecer no portal.');
});

it('allows the portal user to send a correction comment and replacement file when review requests it', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente Correção Portal',
        'email' => 'cliente.correcao.portal@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'SUB-REPLY-0001',
        'submission_type' => 'REGISTRATION',
        'title' => 'Cadastro aguardando correção',
        'responsible_name' => 'Cliente Correção Portal',
        'company_cnpj' => '12.345.678/0001-99',
        'company_name' => 'Empresa Correção Portal',
        'phone' => '(11) 99999-9999',
        'status' => Submission::STATUS_NEEDS_CORRECTION,
        'submitted_at' => now(),
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.show', $submission))
        ->assertSuccessful()
        ->assertSee('Ação Necessária')
        ->assertSee('Enviar Correção')
        ->assertSee(route('nimbus.submissions.reply', $submission), false);

    $this->actingAs($portalUser, 'nimbus')
        ->from(route('nimbus.submissions.show', $submission))
        ->post(route('nimbus.submissions.reply', $submission), [
            'comment' => 'Reenviei o documento corrigido com os ajustes solicitados.',
            'file' => UploadedFile::fake()->create('contrato-social-atualizado.pdf', 128, 'application/pdf'),
        ])
        ->assertRedirect(route('nimbus.submissions.show', $submission));

    $submission->refresh();
    $correctionFile = $submission->files()->where('origin', 'USER')->latest('id')->first();

    expect($submission->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($submission->status_updated_by)->toBeNull()
        ->and($correctionFile)->not->toBeNull()
        ->and($correctionFile?->document_type)->toBe('OTHER')
        ->and($correctionFile?->visible_to_user)->toBeTrue()
        ->and($correctionFile?->versions()->count())->toBe(1)
        ->and($correctionFile?->versions()->first()?->uploaded_by_type)->toBe('PORTAL_USER')
        ->and(Storage::disk('local')->exists((string) $correctionFile?->storage_path))->toBeTrue();

    $this->assertDatabaseHas('nimbus_submission_notes', [
        'nimbus_submission_id' => $submission->id,
        'user_id' => null,
        'visibility' => 'ADMIN_ONLY',
        'message' => 'Reenviei o documento corrigido com os ajustes solicitados.',
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.show', $submission))
        ->assertSuccessful()
        ->assertSee('Documento Complementar')
        ->assertDontSee('Ação Necessária')
        ->assertDontSee('Enviar Correção');

    Storage::disk('local')->deleteDirectory("nimbus/submissions/{$submission->id}/corrections");
});

it('renders only the authenticated portal user documents', function () {
    $adminUser = User::factory()->create();

    $category = DocumentCategory::query()->create([
        'name' => 'Governança',
    ]);

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Portal',
        'email' => 'teste.portal@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $anotherPortalUser = PortalUser::query()->create([
        'full_name' => 'Outro Usuário',
        'email' => 'outro.portal@example.com',
        'document_number' => '10987654321',
        'phone_number' => '11888888888',
        'status' => 'ACTIVE',
    ]);

    PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => 'Contrato Social',
        'description' => 'Documento visível para o usuário autenticado.',
        'file_path' => 'nimbus/portal-documents/contrato-social.pdf',
        'file_original_name' => 'contrato-social.pdf',
        'file_size' => 128,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $adminUser->id,
    ]);

    PortalDocument::query()->create([
        'nimbus_portal_user_id' => $anotherPortalUser->id,
        'title' => 'Arquivo Restrito',
        'description' => 'Não deve aparecer para outro usuário.',
        'file_path' => 'nimbus/portal-documents/arquivo-restrito.pdf',
        'file_original_name' => 'arquivo-restrito.pdf',
        'file_size' => 128,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $adminUser->id,
    ]);

    GeneralDocument::query()->create([
        'title' => 'Política de Cadastro',
        'description' => 'Documento institucional disponível no acervo geral.',
        'nimbus_category_id' => $category->id,
        'file_path' => 'nimbus/general-documents/politica-cadastro.pdf',
        'file_original_name' => 'politica-cadastro.pdf',
        'file_size' => 512000,
        'file_mime' => 'application/pdf',
        'published_at' => now(),
        'is_active' => true,
        'created_by_user_id' => $adminUser->id,
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.index'))
        ->assertSuccessful()
        ->assertSee('Biblioteca de Arquivos')
        ->assertSee('Painel documental')
        ->assertSee('Busca e classificação')
        ->assertSee('Documentos Gerais')
        ->assertSee('Meus Documentos')
        ->assertSee('Política de Cadastro')
        ->assertSee('Contrato Social')
        ->assertDontSee('Arquivo Restrito');
});

it('allows the portal user to download only their own documents', function () {
    $adminUser = User::factory()->create();

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Teste Portal',
        'email' => 'teste.portal@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $anotherPortalUser = PortalUser::query()->create([
        'full_name' => 'Outro Usuário',
        'email' => 'outro.portal@example.com',
        'document_number' => '10987654321',
        'phone_number' => '11888888888',
        'status' => 'ACTIVE',
    ]);

    Storage::disk('local')->put('nimbus/portal-documents/contrato-social.pdf', 'conteudo');
    Storage::disk('local')->put('nimbus/portal-documents/arquivo-restrito.pdf', 'conteudo');

    $visibleDocument = PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => 'Contrato Social',
        'description' => 'Documento liberado.',
        'file_path' => 'nimbus/portal-documents/contrato-social.pdf',
        'file_original_name' => 'contrato-social.pdf',
        'file_size' => 128,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $adminUser->id,
    ]);

    $restrictedDocument = PortalDocument::query()->create([
        'nimbus_portal_user_id' => $anotherPortalUser->id,
        'title' => 'Arquivo Restrito',
        'description' => 'Documento de outro usuário.',
        'file_path' => 'nimbus/portal-documents/arquivo-restrito.pdf',
        'file_original_name' => 'arquivo-restrito.pdf',
        'file_size' => 128,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $adminUser->id,
    ]);

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.download', $visibleDocument))
        ->assertDownload('contrato-social.pdf');

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.download', $restrictedDocument))
        ->assertNotFound();

    Storage::disk('local')->delete([
        'nimbus/portal-documents/contrato-social.pdf',
        'nimbus/portal-documents/arquivo-restrito.pdf',
    ]);
});

/**
 * @return array<int, Component>
 */
function flattenNimbusSchemaComponents(Schema $schema): array
{
    $components = [];

    foreach ($schema->getComponents() as $component) {
        if (! $component instanceof Component) {
            continue;
        }

        $components[] = $component;

        foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
            $components = [
                ...$components,
                ...flattenNimbusSchemaComponents($childSchema),
            ];
        }
    }

    return $components;
}

function makeNimbusSchemaTestLivewire(): LivewireComponent&HasSchemas
{
    return new class extends LivewireComponent implements HasSchemas
    {
        public function __construct()
        {
            $this->setId('nimbus-storage-schema-test');
            $this->setName('nimbus-storage-schema-test');
        }

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function getOldSchemaState(string $statePath): mixed
        {
            return null;
        }

        public function getSchemaComponent(string $key, bool $withHidden = false, array $skipComponentsChildContainersWhileSearching = []): Component|Action|ActionGroup|null
        {
            return null;
        }

        public function getSchema(string $name): ?Schema
        {
            return null;
        }

        public function currentlyValidatingSchema(?Schema $schema): void {}

        public function getDefaultTestingSchemaName(): ?string
        {
            return null;
        }
    };
}
