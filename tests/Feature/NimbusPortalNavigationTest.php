<?php

use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

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
        ->assertSee('href="'.route('nimbus.submissions.index').'"', false)
        ->assertSee('href="'.route('nimbus.submissions.create').'"', false)
        ->assertSee('href="'.route('nimbus.documents.index').'"', false)
        ->assertSee('href="'.route('nimbus.submissions.show', $submission).'"', false);
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
