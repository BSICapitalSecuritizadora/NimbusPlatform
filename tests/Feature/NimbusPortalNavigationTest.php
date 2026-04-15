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

uses(RefreshDatabase::class);

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
        ->assertSee('images/bsi-logo.png', false)
        ->assertDontSee('https://bsicapital.com.br/wp-content/uploads/2022/06/logo.png', false)
        ->assertSee('href="'.route('nimbus.submissions.index').'"', false)
        ->assertSee('href="'.route('nimbus.submissions.create').'"', false)
        ->assertSee('href="'.route('nimbus.documents.index').'"', false)
        ->assertSee('href="'.route('nimbus.submissions.show', $submission).'"', false);
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

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.index'))
        ->assertSuccessful()
        ->assertSee('Documentos')
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
