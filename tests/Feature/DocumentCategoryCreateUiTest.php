<?php

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use App\Filament\Resources\Nimbus\DocumentCategories\Pages\CreateDocumentCategory;
use App\Models\Nimbus\DocumentCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('renders the institutional create composition', function () {
    $this->get(DocumentCategoryResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Nova Categoria de Documento')
        ->assertSee('Cadastre uma classificação para organizar os documentos do módulo.')
        ->assertSee('Informações da Categoria')
        ->assertSee('Nome da Categoria')
        ->assertSee('Use um nome curto e objetivo para facilitar a identificação dos documentos.');
});

it('creates a category with the institutional actions', function () {
    Livewire::test(CreateDocumentCategory::class)
        ->fillForm(['name' => 'Contratos'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('nimbus_document_categories', ['name' => 'Contratos']);
});

it('keeps the name required', function () {
    Livewire::test(CreateDocumentCategory::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name']);

    $this->assertDatabaseCount('nimbus_document_categories', 0);
});

it('keeps the name unique', function () {
    DocumentCategory::query()->create(['name' => 'Regulamentos']);

    Livewire::test(CreateDocumentCategory::class)
        ->fillForm(['name' => 'Regulamentos'])
        ->call('create')
        ->assertHasFormErrors(['name']);

    $this->assertDatabaseCount('nimbus_document_categories', 1);
});
