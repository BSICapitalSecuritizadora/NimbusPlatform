<?php

use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::set(DocumentStorageService::privateDisk(), Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/local-'.uniqid()),
        'throw' => false,
    ]));
});

// ── A-4: metadados de arquivo derivados no servidor ──────────────────────────

it('derives file metadata from the stored file instead of the submitted payload (A-4)', function () {
    $document = makeGeneralDocumentOnDisk('politica-kyc.pdf', [
        'file_original_name' => 'payload-forjado.html',
        'file_size' => 1,
        'file_mime' => 'text/html',
    ]);

    $fresh = $document->fresh();

    expect($fresh->file_mime)->toBe('application/pdf')
        ->and($fresh->file_original_name)->toBe('politica-kyc.pdf')
        ->and((int) $fresh->file_size)->toBe(strlen(pdfFixtureBytes()));
});

it('re-derives the mime when only the metadata is tampered with (A-4)', function () {
    $document = makeGeneralDocumentOnDisk('regulamento.pdf');

    // Simula um payload Livewire que reescreve só os metadados, sem trocar o arquivo.
    $document->file_mime = 'text/html';
    $document->save();

    expect($document->fresh()->file_mime)->toBe('application/pdf');
});

it('derives portal document metadata on the server too (A-4)', function () {
    $document = makePortalDocumentOnDisk('contrato-social.pdf', [
        'file_mime' => 'text/html',
    ]);

    expect($document->fresh()->file_mime)->toBe('application/pdf');
});

// ── A-4: preview nunca serve Content-Type arbitrário ─────────────────────────

it('never serves a general document inline with an arbitrary content type (A-4)', function () {
    $document = makeGeneralDocumentOnDisk('politica-kyc.pdf');

    poisonStoredMime($document);

    $response = $this->actingAs(makeNimbusDocumentAdmin())
        ->get(route('admin.nimbus.documents.general.preview', $document));

    $response->assertSuccessful();

    expect($response->headers->get('content-type'))->toBe('application/octet-stream')
        ->and($response->headers->get('content-disposition'))->toStartWith('attachment')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('never serves a portal document inline with an arbitrary content type (A-4)', function () {
    $document = makePortalDocumentOnDisk('contrato-social.pdf');

    poisonStoredMime($document);

    $response = $this->actingAs(makeNimbusDocumentAdmin())
        ->get(route('admin.nimbus.documents.portal.preview', $document));

    $response->assertSuccessful();

    expect($response->headers->get('content-type'))->toBe('application/octet-stream')
        ->and($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('never serves a poisoned general document inline on the external portal (A-4)', function () {
    $document = makeGeneralDocumentOnDisk('politica-kyc.pdf');

    poisonStoredMime($document);

    $response = $this->actingAs(makeNimbusPreviewPortalUser(), 'nimbus')
        ->get(route('nimbus.documents.general.preview', $document));

    $response->assertSuccessful();

    expect($response->headers->get('content-type'))->toBe('application/octet-stream')
        ->and($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('keeps safe types inline and sandboxes the file response (A-4)', function () {
    $document = makeGeneralDocumentOnDisk('politica-kyc.pdf');

    $response = $this->actingAs(makeNimbusDocumentAdmin())
        ->get(route('admin.nimbus.documents.general.preview', $document));

    $response->assertSuccessful();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toStartWith('inline')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->headers->get('content-security-policy'))
        ->toBe("default-src 'none'; style-src 'unsafe-inline'; sandbox allow-scripts");
});

/**
 * Conteúdo mínimo reconhecido pelo `finfo` como `application/pdf`.
 */
function pdfFixtureBytes(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< >>\n%%EOF\n";
}

/**
 * Grava o `file_mime` direto na tabela, sem passar pelos eventos do model, para
 * reproduzir uma linha já envenenada antes da correção.
 */
function poisonStoredMime(GeneralDocument|PortalDocument $document): void
{
    $document->newQuery()->whereKey($document->getKey())->update(['file_mime' => 'text/html']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeGeneralDocumentOnDisk(string $fileName, array $attributes = []): GeneralDocument
{
    $path = DocumentStorageService::PRIVATE_PREFIX.'/general-documents/'.$fileName;

    Storage::disk(DocumentStorageService::privateDisk())->put($path, pdfFixtureBytes());

    return GeneralDocument::query()->create(array_merge([
        'nimbus_category_id' => DocumentCategory::query()->create(['name' => 'Governança'])->id,
        'title' => 'Documento de teste',
        'description' => 'Documento usado nos testes de endurecimento do preview.',
        'file_path' => $path,
        'file_original_name' => $fileName,
        'file_size' => strlen(pdfFixtureBytes()),
        'file_mime' => 'application/pdf',
        'is_active' => true,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makePortalDocumentOnDisk(string $fileName, array $attributes = []): PortalDocument
{
    $path = DocumentStorageService::PRIVATE_PREFIX.'/portal-documents/'.$fileName;

    Storage::disk(DocumentStorageService::privateDisk())->put($path, pdfFixtureBytes());

    return PortalDocument::query()->create(array_merge([
        'nimbus_portal_user_id' => makeNimbusPreviewPortalUser()->id,
        'created_by_user_id' => User::factory()->create()->id,
        'title' => 'Documento privado de teste',
        'description' => 'Documento usado nos testes de endurecimento do preview.',
        'file_path' => $path,
        'file_original_name' => $fileName,
        'file_size' => strlen(pdfFixtureBytes()),
        'file_mime' => 'application/pdf',
    ], $attributes));
}

function makeNimbusPreviewPortalUser(): PortalUser
{
    return PortalUser::query()->firstOrCreate(
        ['email' => 'preview.hardening@example.com'],
        [
            'full_name' => 'Usuário Preview Hardening',
            'document_number' => '12345678909',
            'phone_number' => '11999999999',
            'status' => 'ACTIVE',
        ],
    );
}

function makeNimbusDocumentAdmin(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'admin.preview.hardening@example.com',
    ]);

    Permission::findOrCreate('nimbus.general-documents.view');
    Permission::findOrCreate('nimbus.portal-documents.view');

    $user->givePermissionTo([
        'nimbus.general-documents.view',
        'nimbus.portal-documents.view',
    ]);

    return $user;
}
