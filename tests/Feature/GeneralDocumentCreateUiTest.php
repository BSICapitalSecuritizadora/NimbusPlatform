<?php

use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use App\Filament\Resources\Nimbus\GeneralDocuments\Pages\CreateGeneralDocument;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Services\DocumentStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Support\Enums\Alignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(DocumentStorageService::privateDisk());

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('renders the institutional publication flow composition', function () {
    $this->get(GeneralDocumentResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Novo Documento Geral')
        ->assertSee('Cadastre um documento institucional e defina sua disponibilidade.')
        ->assertSee('Dados da Publicação')
        ->assertSee('Cadastre as informações principais e o arquivo do documento.')
        ->assertSee('Categoria')
        ->assertSee('Selecione uma categoria...')
        ->assertSee('Título')
        ->assertSee('Descrição')
        ->assertSee('Arquivo')
        ->assertSee('Tamanho máximo permitido: 100 MB.')
        ->assertSee('Disponibilidade')
        ->assertSee('Defina quando e onde o documento ficará disponível.')
        ->assertSee('Publicado no Portal')
        ->assertSee('Quando ativado, o documento ficará visível para os usuários no portal.')
        ->assertSee('Data de Publicação')
        ->assertSee('Caso não seja informada, o documento não terá uma data de publicação definida.');
});

it('shares the institutional document form markers', function () {
    $page = Livewire::test(CreateGeneralDocument::class)->instance();

    expect($page->getMaxContentWidth()->value)->toBe('full')
        ->and($page->getExtraBodyAttributes()['class'])->toContain('bsi-cockpit-page', 'bsi-document-form-page', 'bsi-general-document-form-page')
        ->and($page->getSubheading())->toBe('Cadastre um documento institucional e defina sua disponibilidade.');
});

it('presents the portal toggle as an inline configuration row', function () {
    $page = Livewire::test(CreateGeneralDocument::class)->instance();

    $toggle = $page->getSchema('form')->getComponent('is_active');

    expect($toggle->isInline())->toBeTrue()
        ->and($toggle->getLabel())->toBe('Publicado no Portal');
});

it('configures unified single-column sections with responsive publication date and right-aligned actions', function () {
    $page = Livewire::test(CreateGeneralDocument::class)->instance();

    $form = $page->getSchema('form');
    $components = $form->getComponents();

    expect($components)->toHaveCount(2)
        ->and($components[0]->getColumnSpan('default'))->toBe('full')
        ->and($components[0]->getColumns('lg'))->toBe(1)
        ->and($components[1]->getColumnSpan('default'))->toBe('full')
        ->and($components[1]->getColumns('sm'))->toBe(2);

    $publishedAt = $form->getComponent('published_at');
    expect($publishedAt->getColumnSpan('sm'))->toBe(1)
        ->and($publishedAt->getColumnSpan('default'))->toBe(1);

    expect($page->getFormActionsAlignment())->toBe(Alignment::End);

    $refMethod = new ReflectionMethod($page, 'getFormActions');
    $refMethod->setAccessible(true);
    $actions = $refMethod->invoke($page);
    $actionNames = array_map(
        fn ($action) => $action->getName(),
        $actions
    );

    expect($actionNames)->toBe(['cancel', 'createAnother', 'create']);
});

it('creates a published document with the uploaded file', function () {
    $category = DocumentCategory::query()->create(['name' => 'Institucional']);

    Livewire::test(CreateGeneralDocument::class)
        ->fillForm([
            'nimbus_category_id' => $category->id,
            'title' => 'Regulamento Interno 2026',
            'description' => 'Resumo do conteúdo e da finalidade do documento.',
            'file_path' => UploadedFile::fake()->createWithContent(
                'regulamento.pdf',
                "%PDF-1.4\nRegulamento Interno 2026",
            ),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = GeneralDocument::query()->where('title', 'Regulamento Interno 2026')->firstOrFail();

    expect($document->nimbus_category_id)->toBe($category->id)
        ->and($document->is_active)->toBeTrue()
        ->and($document->published_at)->toBeNull();

    Storage::disk(DocumentStorageService::privateDisk())->assertExists($document->file_path);
});

it('keeps category, title and file required', function () {
    Livewire::test(CreateGeneralDocument::class)
        ->fillForm([
            'title' => '',
            'description' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['nimbus_category_id', 'title', 'file_path']);

    $this->assertDatabaseCount('nimbus_general_documents', 0);
});
