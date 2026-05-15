<?php

use App\Actions\Emissions\PuHistorySpreadsheetTemplate;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $temporaryUploadRoot = storage_path('framework/testing/disks/tmp-for-tests-'.uniqid());
    $localDiskRoot = storage_path('framework/testing/disks/local-'.uniqid());

    config()->set('filesystems.disks.tmp-for-tests', [
        'driver' => 'local',
        'root' => $temporaryUploadRoot,
        'throw' => false,
    ]);
    config()->set('livewire.temporary_file_upload.disk', 'tmp-for-tests');

    Storage::set('tmp-for-tests', Storage::createLocalDriver([
        'root' => $temporaryUploadRoot,
        'throw' => false,
    ]));
    Storage::set('local', Storage::createLocalDriver([
        'root' => $localDiskRoot,
        'throw' => false,
    ]));

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows download and settings actions on the pu histories relation manager', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create();

    $this->actingAs($user);

    Livewire::test(PuHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['download_template', 'manage_template', 'import', 'create'])
        ->assertTableActionHasLabel('download_template', 'Baixar Template')
        ->assertTableActionHasLabel('manage_template', 'Configurar Template');
});

it('downloads the default pu history template for admins', function () {
    $user = makeAdminUser();

    $response = $this->actingAs($user)
        ->get(route('admin.pu-histories.template.download'));

    $response->assertSuccessful();

    expect($response->headers->get('content-disposition'))
        ->toContain('attachment;')
        ->toContain('Template - Historico de PU.xlsx')
        ->toContain('Hist%C3%B3rico');
});

it('renders the settings page and allows replacing the pu history template', function () {
    $user = makeAdminUser();

    $this->actingAs($user)
        ->get(Settings::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Template do histórico de PU')
        ->assertSee('Salvar template');

    Livewire::test(Settings::class)
        ->set('puHistoryTemplateFile', UploadedFile::fake()->create(
            'template-pu-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('savePuHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists('pu-history-templates/template-historico-de-pu.xlsx');

    expect(app(PuHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();
});

it('restores the default pu history template after a custom upload', function () {
    $user = makeAdminUser();

    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('puHistoryTemplateFile', UploadedFile::fake()->create(
            'template-pu-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('savePuHistoryTemplate')
        ->call('restoreDefaultPuHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing('pu-history-templates/template-historico-de-pu.xlsx');

    expect(app(PuHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeFalse();
});
