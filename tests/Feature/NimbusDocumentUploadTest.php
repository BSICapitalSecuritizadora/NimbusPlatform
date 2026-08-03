<?php

use App\Filament\Resources\Nimbus\GeneralDocuments\Pages\CreateGeneralDocument;
use App\Filament\Resources\Nimbus\PortalDocuments\Pages\CreatePortalDocument;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\User;
use App\Services\DocumentStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
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

    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);
});

it('stores homonymous general documents as distinct files', function () {
    $category = DocumentCategory::query()->create([
        'name' => 'Institucional',
    ]);

    $documents = createHomonymousNimbusDocuments(
        CreateGeneralDocument::class,
        GeneralDocument::class,
        [
            'nimbus_category_id' => $category->id,
            'is_active' => true,
        ],
    );

    assertHomonymousDocumentsCoexist(
        $documents,
        DocumentStorageService::PRIVATE_PREFIX.'/general-documents',
    );
});

it('stores homonymous portal documents as distinct files', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Cliente dos Documentos',
        'email' => 'cliente.documentos@example.com',
        'status' => 'ACTIVE',
    ]);

    $documents = createHomonymousNimbusDocuments(
        CreatePortalDocument::class,
        PortalDocument::class,
        [
            'nimbus_portal_user_id' => $portalUser->id,
        ],
    );

    assertHomonymousDocumentsCoexist(
        $documents,
        DocumentStorageService::PRIVATE_PREFIX.'/portal-documents',
    );
});

/**
 * @param  class-string  $pageClass
 * @param  class-string<GeneralDocument|PortalDocument>  $modelClass
 * @param  array<string, mixed>  $formData
 * @return Collection<int, GeneralDocument|PortalDocument>
 */
function createHomonymousNimbusDocuments(string $pageClass, string $modelClass, array $formData): Collection
{
    foreach (['Primeiro documento', 'Segundo documento'] as $index => $title) {
        Livewire::test($pageClass)
            ->fillForm([
                ...$formData,
                'title' => $title,
                'file_path' => UploadedFile::fake()->createWithContent(
                    'relatorio.pdf',
                    "%PDF-1.4\nDocumento {$index}",
                ),
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    return $modelClass::query()->orderBy('id')->get();
}

/**
 * @param  Collection<int, GeneralDocument|PortalDocument>  $documents
 */
function assertHomonymousDocumentsCoexist(Collection $documents, string $directory): void
{
    $storedPaths = $documents->pluck('file_path');

    expect($documents)->toHaveCount(2)
        ->and($storedPaths->unique())->toHaveCount(2)
        ->and(Storage::disk(DocumentStorageService::privateDisk())->files($directory))->toHaveCount(2);

    foreach ($storedPaths as $storedPath) {
        expect(basename($storedPath))->not->toBe('relatorio.pdf');
        Storage::disk(DocumentStorageService::privateDisk())->assertExists($storedPath);
    }
}
