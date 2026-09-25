<?php

use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Actions\Emissions\PuHistorySpreadsheetTemplate;
use App\Filament\Pages\SpreadsheetTemplates;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager;
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
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows download and settings actions on the payments relation manager', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create();

    $this->actingAs($user);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['download_template', 'manage_template', 'import', 'create'])
        ->assertTableActionHasLabel('download_template', 'Download do Template')
        ->assertTableActionHasLabel('manage_template', 'Configurar Template');
});

it('downloads the default payment template for admins', function () {
    $user = makeAdminUser();

    $response = $this->actingAs($user)
        ->get(route('admin.payments.template.download'));

    $response->assertSuccessful();

    expect($response->headers->get('content-disposition'))
        ->toContain('attachment;')
        ->toContain('Template - Fluxo de Pagamento.xlsx');
});

it('renders the settings page and allows replacing the payment template', function () {
    $user = makeAdminUser();

    $this->actingAs($user)
        ->get(SpreadsheetTemplates::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Templates de planilhas')
        ->assertSee('Fluxo de pagamentos')
        ->assertSee('Histórico de PU')
        ->assertSee('Histórico de integralizações')
        ->assertSee('Como funciona o gerenciamento de templates')
        ->assertSee('Salvar template')
        ->assertSee('wire:confirm="Restaurar o template padrão do fluxo de pagamentos?', false)
        ->assertSee('wire:confirm="Restaurar o template padrão do histórico de PU?', false)
        ->assertSee('wire:confirm="Restaurar o template padrão do histórico de integralizações?', false);

    Livewire::test(SpreadsheetTemplates::class)
        ->set('paymentTemplateFile', UploadedFile::fake()->create(
            'template-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('savePaymentTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists('payment-templates/template-fluxo-de-pagamento.xlsx');

    expect(app(PaymentSpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();
});

it('restores the default payment template after a custom upload', function () {
    $user = makeAdminUser();

    $this->actingAs($user);

    Livewire::test(SpreadsheetTemplates::class)
        ->set('paymentTemplateFile', UploadedFile::fake()->create(
            'template-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('savePaymentTemplate')
        ->call('restoreDefaultPaymentTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing('payment-templates/template-fluxo-de-pagamento.xlsx');

    expect(app(PaymentSpreadsheetTemplate::class)->hasCustomTemplate())->toBeFalse();
});

it('allows replacing and restoring the PU history template', function () {
    $user = makeAdminUser();

    $this->actingAs($user);

    Livewire::test(SpreadsheetTemplates::class)
        ->set('puHistoryTemplateFile', UploadedFile::fake()->create(
            'template-pu-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('savePuHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists('pu-history-templates/template-historico-de-pu.xlsx');
    expect(app(PuHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();

    Livewire::test(SpreadsheetTemplates::class)
        ->call('restoreDefaultPuHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing('pu-history-templates/template-historico-de-pu.xlsx');
    expect(app(PuHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeFalse();
});

it('allows replacing and restoring the integralization history template', function () {
    $user = makeAdminUser();

    $this->actingAs($user);

    Livewire::test(SpreadsheetTemplates::class)
        ->set('integralizationHistoryTemplateFile', UploadedFile::fake()->create(
            'template-integralizacao-personalizado.xlsx',
            32,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ))
        ->call('saveIntegralizationHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists('integralization-history-templates/template-historico-de-integralizacoes.xlsx');
    expect(app(IntegralizationHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();

    Livewire::test(SpreadsheetTemplates::class)
        ->call('restoreDefaultIntegralizationHistoryTemplate')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing('integralization-history-templates/template-historico-de-integralizacoes.xlsx');
    expect(app(IntegralizationHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeFalse();
});
