<?php

use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Models\Document;
use App\Models\Emission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function documentUiUser(string ...$permissions): User
{
    $user = User::factory()->withTwoFactor()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('renders the documents list page with cockpit styling and subheadings', function (): void {
    $user = documentUiUser('documents.view', 'documents.create');
    $this->actingAs($user);

    $page = new ListDocuments;
    expect($page->getTitle())->toBe('Documentos')
        ->and($page->getSubheading())->toContain('Gestão institucional e governança de documentos');
});

it('renders the header actions with differentiated primary and secondary hierarchy', function (): void {
    $user = documentUiUser('documents.view', 'documents.create');
    $this->actingAs($user);

    $headerActionsMethod = new ReflectionMethod(ListDocuments::class, 'getHeaderActions');
    $headerActionsMethod->setAccessible(true);
    $actions = collect($headerActionsMethod->invoke(new ListDocuments));

    $batchAction = $actions->first(fn (mixed $action): bool => $action instanceof Action && $action->getName() === 'batch_create');
    $createAction = $actions->first(fn (mixed $action): bool => $action instanceof CreateAction && $action->getName() === 'create');

    expect($batchAction)->not->toBeNull()
        ->and($batchAction->getLabel())->toBe('Cadastrar documentos em lote')
        ->and($batchAction->getColor())->toBe('gray')
        ->and($createAction)->not->toBeNull()
        ->and($createAction->getLabel())->toBe('Criar Documento')
        ->and($createAction->getColor())->toBe('primary');
});

it('calculates tab badge counts correctly for all workflow states', function (): void {
    $user = documentUiUser('documents.view');
    $this->actingAs($user);

    Document::factory()->create([
        'title' => 'Rascunho 1',
        'is_published' => false,
        'is_public' => false,
    ]);

    Document::factory()->count(2)->create([
        'title' => 'Publicado Interno',
        'is_published' => true,
        'is_public' => false,
    ]);

    Document::factory()->create([
        'title' => 'Público Geral',
        'is_published' => true,
        'is_public' => true,
    ]);

    $page = new ListDocuments;
    $tabs = $page->getTabs();

    expect($tabs)->toHaveKeys(['todos', 'rascunho', 'publicado', 'publico', 'nao_publicado'])
        ->and((int) $tabs['todos']->getBadge())->toBe(4)
        ->and((int) $tabs['rascunho']->getBadge())->toBe(1)
        ->and((int) $tabs['publicado']->getBadge())->toBe(2)
        ->and((int) $tabs['publico']->getBadge())->toBe(1)
        ->and((int) $tabs['nao_publicado']->getBadge())->toBe(1);
});

it('configures table search, pagination and empty state for institutional documents', function (): void {
    $user = documentUiUser('documents.view');
    $this->actingAs($user);

    $page = new ListDocuments;
    $table = DocumentsTable::configure(Table::make($page));

    expect($table->getSearchPlaceholder())->toContain('Buscar por título, categoria')
        ->and($table->getEmptyStateHeading())->toBe('Nenhum documento encontrado')
        ->and($table->getDefaultPaginationPageOption())->toBe(10)
        ->and($table->getPaginationPageOptions())->toBe([10, 25, 50, 100]);
});

it('renders document table columns with formatted metadata and series associations', function (): void {
    $user = documentUiUser('documents.view');
    $this->actingAs($user);

    $emission = Emission::factory()->create(['name' => '1ª Série CRI 100']);
    $document = Document::factory()->create([
        'title' => 'Ata da Assembleia Geral Ordinária',
        'category' => 'assembleias',
        'file_name' => 'ata-ago-2026.pdf',
        'file_size' => 2097152,
        'mime_type' => 'application/pdf',
        'is_published' => true,
        'is_public' => false,
        'version' => 1,
    ]);
    $document->emissions()->attach($emission);

    Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords([$document])
        ->assertSee('Ata da Assembleia Geral Ordinária')
        ->assertSee('Assembleias')
        ->assertSee('1ª Série CRI 100')
        ->assertSee('ata-ago-2026.pdf')
        ->assertSee('2 MB')
        ->assertSee('Publicado');
});

it('renders the edit document page with institutional cockpit layout and actions', function (): void {
    $user = documentUiUser('documents.view', 'documents.update', 'documents.delete');
    $this->actingAs($user);

    $document = Document::factory()->create([
        'title' => 'Regulamento de Emissão 2026',
        'category' => 'governanca',
        'file_name' => 'regulamento-2026.pdf',
        'file_size' => 1048576,
        'mime_type' => 'application/pdf',
        'is_published' => true,
        'is_public' => true,
    ]);

    Livewire::test(EditDocument::class, [
        'record' => $document->getRouteKey(),
    ])
        ->assertSee('Editar Regulamento de Emissão 2026')
        ->assertSee('Atualize os metadados, publicação e vínculos deste documento.')
        ->assertSee('Dados do documento')
        ->assertSee('Informações do arquivo')
        ->assertSee('Visibilidade e publicação')
        ->assertSee('Salvar alterações');
});

it('renders the versions relation manager with clean empty state when no previous version exists', function (): void {
    $user = documentUiUser('documents.view', 'documents.update');
    $this->actingAs($user);

    $document = Document::factory()->create([
        'title' => 'Documento Sem Versões',
    ]);

    Livewire::test(VersionsRelationManager::class, [
        'ownerRecord' => $document,
        'pageClass' => EditDocument::class,
    ])
        ->assertSee('Histórico de versões')
        ->assertSee('Nenhuma versão anterior')
        ->assertSee('Este documento ainda não teve seu arquivo substituído.');
});

it('renders the create document page with institutional cockpit layout, subheadings and actions', function (): void {
    $user = documentUiUser('documents.view', 'documents.create');
    $this->actingAs($user);

    Livewire::test(CreateDocument::class)
        ->assertSee('Criar documento')
        ->assertSee('Cadastre o arquivo, sua classificação, vínculos e regras de publicação.')
        ->assertSee('Dados do documento')
        ->assertSee('Informações cadastrais, classificação, arquivo e regras de visibilidade.')
        ->assertSee('Visibilidade e publicação')
        ->assertSee('Publicado')
        ->assertSee('Público')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar');
});

it('validates required fields with specific friendly error messages on document creation', function (): void {
    $user = documentUiUser('documents.view', 'documents.create');
    $this->actingAs($user);

    Livewire::test(CreateDocument::class)
        ->fillForm([
            'title' => '',
            'category' => '',
            'file_path' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'title' => 'Informe o título do documento.',
            'category' => 'Selecione a categoria.',
            'file_path' => 'Selecione um arquivo.',
        ]);
});

/**
 * Arquivo de outra área do mesmo disco -- um arquivo de medição --, que um
 * documento só passaria a apontar com o caminho forjado no payload.
 */
function documentUiForeignFile(): string
{
    $path = 'nimbus_docs/measurements/assets/'.Str::uuid().'/'.Str::random(40).'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 arquivo de medição de outra operação');

    return $path;
}

function documentUiStoredDocument(): Document
{
    Storage::disk('local')->put('documents/original.pdf', '%PDF-1.4 versão original');

    return Document::factory()->create([
        'file_path' => 'documents/original.pdf',
        'file_name' => 'original.pdf',
        'storage_disk' => 'local',
    ]);
}

it('refuses a document created with a file path forged to another module file', function (): void {
    $this->actingAs(documentUiUser('documents.view', 'documents.create'));
    $foreign = documentUiForeignFile();

    Livewire::test(CreateDocument::class)
        ->fillForm(['title' => 'Documento forjado', 'category' => 'governanca'])
        ->set('data.file_path', [(string) Str::uuid() => $foreign])
        ->call('create')
        ->assertHasFormErrors(['file_path']);

    expect(Document::query()->count())->toBe(0);
    Storage::disk('local')->assertExists($foreign);
});

it('keeps the stored file when an edited document arrives with a forged file path', function (): void {
    $this->actingAs(documentUiUser('documents.view', 'documents.update'));
    $document = documentUiStoredDocument();

    Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
        ->set('data.file_path', [(string) Str::uuid() => documentUiForeignFile()])
        ->call('save')
        ->assertHasFormErrors(['file_path']);

    expect($document->fresh()->file_path)->toBe('documents/original.pdf');
});

it('saves an edited document that keeps its stored file', function (): void {
    $this->actingAs(documentUiUser('documents.view', 'documents.update'));
    $document = documentUiStoredDocument();

    Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
        ->fillForm(['title' => 'Regulamento revisado'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->fresh()->title)->toBe('Regulamento revisado')
        ->and($document->fresh()->file_path)->toBe('documents/original.pdf');
});

it('refuses a new document version whose file path was forged', function (): void {
    $this->actingAs(documentUiUser('documents.view', 'documents.update'));
    $document = documentUiStoredDocument();
    $foreign = documentUiForeignFile();

    Livewire::test(ListDocuments::class)
        ->mountAction(TestAction::make('new_version')->table($document))
        ->set('mountedActions.0.data.file_path', [(string) Str::uuid() => $foreign])
        ->callMountedAction()
        ->assertHasActionErrors(['file_path']);

    expect(Document::query()->where('file_path', $foreign)->exists())->toBeFalse()
        ->and($document->fresh()->replaced_at)->toBeNull();
});

it('creates a new document version from a fresh upload', function (): void {
    $this->actingAs(documentUiUser('documents.view', 'documents.update'));
    $document = documentUiStoredDocument();

    Livewire::test(ListDocuments::class)
        ->callAction(TestAction::make('new_version')->table($document), data: [
            'file_path' => UploadedFile::fake()->createWithContent('versao-2.pdf', '%PDF-1.4 versão 2'),
        ])
        ->assertHasNoActionErrors();

    $newVersion = Document::query()->where('parent_document_id', $document->id)->sole();

    expect($newVersion->version)->toBe($document->fresh()->version + 1)
        ->and(Storage::disk('local')->get($newVersion->file_path))->toBe('%PDF-1.4 versão 2')
        ->and($document->fresh()->replaced_at)->not->toBeNull();
});
