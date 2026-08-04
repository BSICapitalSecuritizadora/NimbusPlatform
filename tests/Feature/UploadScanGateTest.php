<?php

use App\Enums\MalwareScanStatus;
use App\Models\Document;
use App\Models\Emission;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\User;
use App\Services\Obligations\ObligationEvidenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
});

/**
 * A varredura só protege se todo caminho que entrega o arquivo consultar o
 * resultado. Este teste percorre os controllers que servem arquivo enviado por
 * usuário e exige a checagem — para que o próximo controller de download não
 * nasça sem ela.
 */
it('checks the scan status in every controller that serves an uploaded file', function () {
    $servingControllers = [
        'Admin/AdminDocumentDownloadController.php',
        'Admin/JobApplicationResumeController.php',
        'Admin/ObligationEvidenceDownloadController.php',
        'Nimbus/AdminDocumentController.php',
        'Nimbus/DocumentController.php',
        'Portal/DocumentDownloadController.php',
        'Site/ProposalContinuationController.php',
        'Site/SiteDocumentDownloadController.php',
    ];

    $ungated = collect($servingControllers)
        ->reject(fn (string $controller): bool => str_contains(
            File::get(app_path('Http/Controllers/'.$controller)),
            'scan_status',
        ))
        ->values()
        ->all();

    expect($ungated)->toBe([]);
});

it('covers every uploaded file table with a scan status column', function () {
    $tables = [
        'proposal_files',
        'nimbus_submission_files',
        'job_applications',
        'obligation_evidences',
        'nimbus_general_documents',
        'nimbus_documents',
        'documents',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'scan_status'))->toBeTrue("A tabela {$table} não tem scan_status.");
    }
});

// ── Documentos do site / portal / painel ─────────────────────────────────────

function scannableDocument(MalwareScanStatus $scanStatus): Document
{
    Storage::disk('local')->put('documents/relatorio.pdf', '%PDF-1.4');

    $document = Document::factory()->create([
        'file_path' => 'documents/relatorio.pdf',
        'file_name' => 'relatorio.pdf',
        'storage_disk' => 'local',
        'is_published' => true,
        'is_public' => true,
    ]);

    // A gravação passa pela varredura e devolve o veredito; para exercitar o
    // gate é preciso reescrever o status sem tocar no caminho do arquivo.
    $document->forceFill(['scan_status' => $scanStatus])->save();

    return $document;
}

it('does not serve an unscanned document through the admin panel', function () {
    $admin = makeAdminUser();
    $document = scannableDocument(MalwareScanStatus::Pending);

    $this->actingAs($admin)
        ->get(route('admin.documents.download', $document))
        ->assertNotFound();
});

it('serves a clean document through the admin panel', function () {
    $admin = makeAdminUser();
    $document = scannableDocument(MalwareScanStatus::Clean);

    $this->actingAs($admin)
        ->get(route('admin.documents.download', $document))
        ->assertDownload('relatorio.pdf');
});

it('does not serve an infected document on the public site', function () {
    $document = scannableDocument(MalwareScanStatus::Infected);

    $this->get(route('site.documents.download', $document))->assertNotFound();
});

it('serves a clean document on the public site', function () {
    $document = scannableDocument(MalwareScanStatus::Clean);

    $this->get(route('site.documents.download', $document))->assertDownload('relatorio.pdf');
});

// ── Evidências de obrigação ──────────────────────────────────────────────────

it('does not serve an unscanned obligation evidence', function () {
    $admin = makeAdminUser();

    Storage::disk('local')->put('obligation-evidences/comprovante.pdf', '%PDF-1.4');

    $evidence = ObligationEvidence::factory()->awaitingScan()->create([
        'path' => 'obligation-evidences/comprovante.pdf',
        'original_name' => 'comprovante.pdf',
        'disk' => 'local',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.obligations.evidences.download', $evidence))
        ->assertNotFound();

    $evidence->forceFill(['scan_status' => MalwareScanStatus::Clean])->save();

    $this->actingAs($admin)
        ->get(route('admin.obligations.evidences.download', $evidence))
        ->assertDownload('comprovante.pdf');
});

it('marks an evidence as scanned when the synchronous scanner clears the upload', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->create(['emission_id' => $emission->id]);

    $evidence = app(ObligationEvidenceService::class)->store(
        $obligation,
        UploadedFile::fake()->create('comprovante.pdf', 10, 'application/pdf'),
    );

    expect($evidence->scan_status)->toBe(MalwareScanStatus::Clean);
});

// ── Biblioteca do portal externo ─────────────────────────────────────────────

it('does not serve an unscanned general document to the external portal', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Usuário Externo',
        'email' => 'externo@example.com',
        'document_number' => '33333333333',
        'phone_number' => '11999990003',
        'status' => 'ACTIVE',
    ]);

    $category = DocumentCategory::query()->create([
        'name' => 'Institucional',
        'slug' => 'institucional',
    ]);

    Storage::disk('local')->put('nimbus/general-documents/manual.pdf', '%PDF-1.4');

    $document = GeneralDocument::query()->create([
        'nimbus_category_id' => $category->id,
        'title' => 'Manual do investidor',
        'file_path' => 'nimbus/general-documents/manual.pdf',
        'file_original_name' => 'manual.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'is_active' => true,
    ]);

    $document->forceFill(['scan_status' => MalwareScanStatus::Pending])->save();

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.general.download', $document))
        ->assertNotFound();

    $document->forceFill(['scan_status' => MalwareScanStatus::Clean])->save();

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.general.download', $document))
        ->assertDownload('manual.pdf');
});

it('does not serve an unscanned personal document to its own portal user', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Usuário Externo',
        'email' => 'externo2@example.com',
        'document_number' => '44444444444',
        'phone_number' => '11999990004',
        'status' => 'ACTIVE',
    ]);

    $author = User::factory()->create();

    Storage::disk('local')->put('nimbus/documents/parecer.pdf', '%PDF-1.4');

    $document = PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => 'Parecer',
        'file_path' => 'nimbus/documents/parecer.pdf',
        'file_original_name' => 'parecer.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $author->id,
    ]);

    $document->forceFill(['scan_status' => MalwareScanStatus::Pending])->save();

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.download', $document))
        ->assertNotFound();

    $document->forceFill(['scan_status' => MalwareScanStatus::Clean])->save();

    $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.documents.download', $document))
        ->assertDownload('parecer.pdf');
});

// ── Varredura no momento da gravação ─────────────────────────────────────────

it('records the scan verdict when a document file is stored', function () {
    Storage::disk('local')->put('documents/novo.pdf', '%PDF-1.4');

    $document = Document::factory()->create([
        'file_path' => 'documents/novo.pdf',
        'storage_disk' => 'local',
    ]);

    expect($document->scan_status)->toBe(MalwareScanStatus::Clean);
});

it('re-scans a document when its file is replaced', function () {
    $document = scannableDocument(MalwareScanStatus::Clean);

    Storage::disk('local')->put('documents/substituto.pdf', '%PDF-1.4');

    $document->forceFill(['scan_status' => MalwareScanStatus::Infected])->save();
    $document->update(['file_path' => 'documents/substituto.pdf']);

    expect($document->fresh()->scan_status)->toBe(MalwareScanStatus::Clean);
});
