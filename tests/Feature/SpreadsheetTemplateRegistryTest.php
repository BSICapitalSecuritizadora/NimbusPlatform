<?php

use App\Actions\Clients\ClientSpreadsheetTemplate;
use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Actions\Emissions\PuHistorySpreadsheetTemplate;
use App\Filament\Pages\SpreadsheetTemplates;
use App\Models\User;
use App\Support\SpreadsheetTemplates\SpreadsheetTemplateDefinition;
use App\Support\SpreadsheetTemplates\SpreadsheetTemplateManager;
use App\Support\SpreadsheetTemplates\SpreadsheetTemplateRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function makeTemplateRestrictedUser(array $permissions = []): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function fakeTemplateUpload(string $name = 'template-personalizado.xlsx'): UploadedFile
{
    return UploadedFile::fake()->create(
        $name,
        32,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
}

it('exposes every registered template on the settings page', function () {
    $this->actingAs(makeAdminUser());

    $response = $this->get(SpreadsheetTemplates::getUrl(panel: 'admin'));

    $response->assertSuccessful();

    foreach (app(SpreadsheetTemplateRegistry::class)->all() as $definition) {
        $response->assertSee($definition->title);
    }
});

it('keeps the three legacy templates on the page', function () {
    $this->actingAs(makeAdminUser())
        ->get(SpreadsheetTemplates::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Fluxo de pagamentos')
        ->assertSee('Histórico de PU')
        ->assertSee('Histórico de integralizações');
});

it('shows newly registered templates without page changes', function () {
    app(SpreadsheetTemplateRegistry::class)->register(new SpreadsheetTemplateDefinition(
        key: 'future-widgets',
        title: 'Widgets do futuro',
        category: 'Comercial',
        context: 'Planilha de importação de widgets',
        description: 'Modelo de importação de widgets.',
        downloadRoute: 'admin.clients.template.download',
        handler: ClientSpreadsheetTemplate::class,
        dynamic: true,
        downloadAbilities: ['clients.view'],
    ));

    $this->actingAs(makeAdminUser())
        ->get(SpreadsheetTemplates::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Widgets do futuro');
});

it('groups templates by category', function () {
    $this->actingAs(makeAdminUser())
        ->get(SpreadsheetTemplates::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Emissões',
            'Fluxo de pagamentos',
            'Histórico de PU',
            'Histórico de integralizações',
            'Comercial',
            'Clientes',
            'Contratos',
            'Parcelas dos contratos',
            'Empreendimentos',
            'Unidades dos empreendimentos',
            'Valores das unidades',
        ]);
});

it('filters templates by search', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(SpreadsheetTemplates::class)
        ->set('search', 'integralizações')
        ->assertSee('Histórico de integralizações')
        ->assertDontSee('Fluxo de pagamentos')
        ->assertDontSee('Clientes');

    Livewire::test(SpreadsheetTemplates::class)
        ->set('search', 'zzz-sem-correspondencia')
        ->assertSee('Nenhum template encontrado para os filtros selecionados.');
});

it('filters templates by category', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(SpreadsheetTemplates::class)
        ->set('categoryFilter', 'Comercial')
        ->assertSee('Clientes')
        ->assertSee('Contratos')
        ->assertDontSee('Fluxo de pagamentos')
        ->assertDontSee('Unidades dos empreendimentos');
});

it('shows system default status for untouched templates', function () {
    $this->actingAs(makeAdminUser())
        ->get(SpreadsheetTemplates::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Padrão do sistema')
        ->assertSee('0 personalizados');
});

it('shows customized status after a replacement', function () {
    $this->actingAs($user = makeAdminUser());

    Livewire::test(SpreadsheetTemplates::class)
        ->set('templateFiles.payments', fakeTemplateUpload())
        ->call('saveTemplate', 'payments')
        ->assertHasNoErrors()
        ->assertSee('Personalizado')
        ->assertSee('Alterado em')
        ->assertSee($user->name);

    expect(app(SpreadsheetTemplateManager::class)->isCustomized(
        app(SpreadsheetTemplateRegistry::class)->find('payments')
    ))->toBeTrue();
});

it('downloads the effective template, default or customized', function () {
    $this->actingAs(makeAdminUser());

    $defaultResponse = $this->get(route('admin.payments.template.download'));
    $defaultResponse->assertSuccessful();
    $defaultContent = $defaultResponse->streamedContent();

    expect($defaultContent)->toBe(file_get_contents(base_path('resources/templates/payments/template-fluxo-de-pagamento.xlsx')));

    Livewire::test(SpreadsheetTemplates::class)
        ->set('templateFiles.payments', fakeTemplateUpload())
        ->call('saveTemplate', 'payments')
        ->assertHasNoErrors();

    $customResponse = $this->get(route('admin.payments.template.download'));
    $customResponse->assertSuccessful();

    expect($customResponse->streamedContent())
        ->toBe(Storage::disk('local')->get('payment-templates/template-fluxo-de-pagamento.xlsx'))
        ->not->toBe($defaultContent);
});

it('replaces a customizable template and audits the change', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(SpreadsheetTemplates::class)
        ->set('templateFiles.pu-histories', fakeTemplateUpload('template-pu.xlsx'))
        ->call('saveTemplate', 'pu-histories')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists('pu-history-templates/template-historico-de-pu.xlsx');
    expect(app(PuHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'spreadsheet_template',
        'description' => 'spreadsheet_template_customized',
    ]);
});

it('rejects non-xlsx replacements', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(SpreadsheetTemplates::class)
        ->set('templateFiles.payments', UploadedFile::fake()->create('template.pdf', 32, 'application/pdf'))
        ->call('saveTemplate', 'payments')
        ->assertHasErrors('templateFiles.payments');

    Storage::disk('local')->assertMissing('payment-templates/template-fluxo-de-pagamento.xlsx');
});

it('restores only the selected template to default', function () {
    $this->actingAs(makeAdminUser());

    $page = Livewire::test(SpreadsheetTemplates::class)
        ->set('templateFiles.payments', fakeTemplateUpload('pagamentos.xlsx'))
        ->call('saveTemplate', 'payments')
        ->set('templateFiles.pu-histories', fakeTemplateUpload('pu.xlsx'))
        ->call('saveTemplate', 'pu-histories')
        ->assertHasNoErrors();

    expect(app(PaymentSpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue()
        ->and(app(PuHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();

    $page->call('restoreDefaultTemplate', 'payments')->assertHasNoErrors();

    Storage::disk('local')->assertMissing('payment-templates/template-fluxo-de-pagamento.xlsx');
    Storage::disk('local')->assertExists('pu-history-templates/template-historico-de-pu.xlsx');

    expect(app(PaymentSpreadsheetTemplate::class)->hasCustomTemplate())->toBeFalse()
        ->and(app(PuHistorySpreadsheetTemplate::class)->hasCustomTemplate())->toBeTrue();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'spreadsheet_template',
        'description' => 'spreadsheet_template_restored',
    ]);
});

it('hides replacement controls for dynamic templates', function () {
    $response = $this->actingAs(makeAdminUser())
        ->get(SpreadsheetTemplates::getUrl(panel: 'admin'));

    $response->assertSuccessful();

    expect(substr_count($response->getContent(), 'Substituir template'))->toBe(3)
        ->and(substr_count($response->getContent(), 'Template gerado pelo sistema'))->toBe(5);

    $definition = app(SpreadsheetTemplateRegistry::class)->find('clients');

    expect(fn () => app(SpreadsheetTemplateManager::class)->store($definition, fakeTemplateUpload()))
        ->toThrow(HttpException::class);
});

it('rejects unknown template keys', function () {
    $this->actingAs(makeAdminUser());

    foreach (['saveTemplate', 'restoreDefaultTemplate'] as $action) {
        try {
            app(SpreadsheetTemplates::class)->{$action}(
                'unknown-template',
                app(SpreadsheetTemplateManager::class),
                app(SpreadsheetTemplateRegistry::class),
            );

            $this->fail("Action {$action} should reject unknown keys.");
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(404);
        }
    }
});

it('forbids template management without settings.view', function () {
    $user = makeTemplateRestrictedUser();

    $this->actingAs($user);

    expect(SpreadsheetTemplates::canAccess())->toBeFalse();

    $this->get(SpreadsheetTemplates::getUrl(panel: 'admin'))
        ->assertForbidden();

    foreach (['saveTemplate', 'restoreDefaultTemplate'] as $action) {
        try {
            app(SpreadsheetTemplates::class)->{$action}(
                'payments',
                app(SpreadsheetTemplateManager::class),
                app(SpreadsheetTemplateRegistry::class),
            );

            $this->fail("Action {$action} should be forbidden.");
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(403);
        }
    }

    Storage::disk('local')->assertMissing('payment-templates/template-fluxo-de-pagamento.xlsx');
});

it('keeps customizations isolated per template', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(SpreadsheetTemplates::class)
        ->set('templateFiles.payments', fakeTemplateUpload())
        ->call('saveTemplate', 'payments')
        ->assertHasNoErrors();

    $manager = app(SpreadsheetTemplateManager::class);
    $registry = app(SpreadsheetTemplateRegistry::class);

    expect($manager->isCustomized($registry->find('payments')))->toBeTrue()
        ->and($manager->isCustomized($registry->find('pu-histories')))->toBeFalse()
        ->and($manager->isCustomized($registry->find('integralization-histories')))->toBeFalse();
});
