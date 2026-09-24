<?php

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use App\Filament\Resources\Nimbus\DocumentCategories\Pages\EditDocumentCategory;
use App\Models\Nimbus\DocumentCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('renders the institutional edit composition with the current value', function () {
    $category = DocumentCategory::query()->create(['name' => 'Contratos']);

    $this->get(DocumentCategoryResource::getUrl('edit', ['record' => $category], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Editar Categoria de Documento')
        ->assertSee('Atualize o nome usado para classificar os documentos do módulo.')
        ->assertSee('Excluir')
        ->assertSee('Informações da Categoria')
        ->assertSee('Nome da Categoria')
        ->assertSee('Use um nome curto e objetivo para facilitar a identificação dos documentos.')
        ->assertSee('Contratos');
});

it('shares the institutional page markers with the create page', function () {
    $category = DocumentCategory::query()->create(['name' => 'Regulamentos']);

    $page = Livewire::test(EditDocumentCategory::class, [
        'record' => $category->getRouteKey(),
    ])->instance();

    expect($page->getMaxContentWidth()->value)->toBe('full')
        ->and($page->getExtraBodyAttributes()['class'])->toContain('bsi-fund-form-page', 'bsi-simple-form-page', 'bsi-document-category-form-page')
        ->and($page->getSubheading())->toBe('Atualize o nome usado para classificar os documentos do módulo.');
});

it('keeps the delete action in the header with confirmation', function () {
    $category = DocumentCategory::query()->create(['name' => 'Institucional']);

    $page = Livewire::test(EditDocumentCategory::class, [
        'record' => $category->getRouteKey(),
    ])->instance();

    $headerActions = invade($page)->getHeaderActions();

    expect($headerActions)->toHaveCount(1)
        ->and($headerActions[0])->toBeInstanceOf(DeleteAction::class);

    $deleteAction = $headerActions[0]->record($category);

    expect($deleteAction->getLabel())->toBe('Excluir')
        ->and($deleteAction->isConfirmationRequired())->toBeTrue();
});

it('updates the category through the edit page', function () {
    $category = DocumentCategory::query()->create(['name' => 'Nome Antigo']);

    Livewire::test(EditDocumentCategory::class, [
        'record' => $category->getRouteKey(),
    ])
        ->fillForm(['name' => 'Nome Atualizado'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->fresh()->name)->toBe('Nome Atualizado');
});

it('keeps the name required on update', function () {
    $category = DocumentCategory::query()->create(['name' => 'Contratos']);

    Livewire::test(EditDocumentCategory::class, [
        'record' => $category->getRouteKey(),
    ])
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($category->fresh()->name)->toBe('Contratos');
});

it('keeps the name unique on update', function () {
    $category = DocumentCategory::query()->create(['name' => 'Contratos']);
    DocumentCategory::query()->create(['name' => 'Regulamentos']);

    Livewire::test(EditDocumentCategory::class, [
        'record' => $category->getRouteKey(),
    ])
        ->fillForm(['name' => 'Regulamentos'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($category->fresh()->name)->toBe('Contratos');
});
