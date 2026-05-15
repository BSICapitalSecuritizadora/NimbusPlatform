<?php

use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\IntegralizationHistoriesRelationManager;
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

it('shows download and settings actions on the integralization histories relation manager', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create();

    $this->actingAs($user);

    Livewire::test(IntegralizationHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['download_template', 'manage_template', 'import', 'create'])
        ->assertTableActionHasLabel('download_template', 'Baixar Template')
        ->assertTableActionHasLabel('manage_template', 'Configurar Template');
});

it('downloads the default integralization history template for admins', function () {
    $user = makeAdminUser();

    $response = $this->actingAs($user)
        ->get(route('admin.integralization-histories.template.download'));

    $response->assertSuccessful();

    expect($response->headers->get('content-disposition'))
        ->toContain('attachment;')
        ->toContain('Template - Historico de Integralizacoes.xlsx')
        ->toContain('Hist%C3%B3rico')
        ->toContain('Integraliza%C3%A7%C3%B5es');
});

it('renders the settings page and allows replacing the integralization history template', function () {
    $user = makeAdminUser();

    $this->actingAs($user)
        ->get(Settings::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Template do histórico de integralizações')
        ->assertSee('Salvar template');

    Livewire::test(Settings::class)
        ->set('integralizationHistoryTemplateFile', UploadedFile::fake()->create(
            'template-integralizacoes-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('saveIntegralizationHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists('integralization-history-templates/template-historico-de-integralizacoes.xlsx');

    expect(app(IntegralizationHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();
});

it('restores the default integralization history template after a custom upload', function () {
    $user = makeAdminUser();

    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('integralizationHistoryTemplateFile', UploadedFile::fake()->create(
            'template-integralizacoes-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('saveIntegralizationHistoryTemplate')
        ->call('restoreDefaultIntegralizationHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing('integralization-history-templates/template-historico-de-integralizacoes.xlsx');

    expect(app(IntegralizationHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeFalse();
});
