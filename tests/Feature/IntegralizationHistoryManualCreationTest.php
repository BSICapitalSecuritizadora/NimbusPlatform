<?php

use App\Actions\Emissions\ImportIntegralizationHistoriesFromSpreadsheet;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\IntegralizationHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\ViewEmission;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use App\Models\IntegralizationHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

/**
 * Valores `DECIMAL(18, x)`, trava da emissão e `whereDate` são justamente o que
 * o SQLite da suíte não reproduz como o MySQL de produção.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function integralizationHistoryOn(Emission $emission): Testable
{
    return Livewire::test(IntegralizationHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => ViewEmission::class,
    ]);
}

function integralizationEditorUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

function integralizationViewerUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole(Role::findOrCreate('emission-viewer')->givePermissionTo('emissions.view'));

    return $user;
}

function integralizationInvestorFund(string $name = 'Headinvest Asset Management'): ExpenseServiceProvider
{
    return ExpenseServiceProvider::factory()->create([
        'name' => $name,
        'expense_service_provider_type_id' => ExpenseServiceProviderType::query()->firstOrCreate(['name' => 'Fundo do Investidor'])->getKey(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manualIntegralizationData(array $overrides = []): array
{
    return [
        'date' => '2026-06-04',
        'quantity' => '4.000',
        'unit_value' => '1.000,98765432',
        'investor_fund' => 'Headinvest Asset Management',
        ...$overrides,
    ];
}

/**
 * @param  array<int, array<int, mixed>>  $rows
 */
function importIntegralizationRows(Emission $emission, array $rows): int
{
    Storage::disk('local')->makeDirectory('imports/testing');

    $relativePath = 'imports/testing/'.uniqid('integralizacoes-manuais-', true).'.xlsx';

    $writer = SimpleExcelWriter::create(Storage::disk('local')->path($relativePath));
    $writer->noHeaderRow()->addRows([
        ['Data', 'Quantidade', 'PU', 'Financeiro', 'Fundo (Investidor)'],
        ...$rows,
    ]);
    $writer->close();

    return app(ImportIntegralizationHistoriesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($relativePath),
        $emission,
    );
}

/**
 * @return array{quantity: ?string, unit_value: ?string, financial_value: ?string, investor_fund: ?string}
 */
function integralizationValues(IntegralizationHistory $integralizationHistory): array
{
    return $integralizationHistory->only(['quantity', 'unit_value', 'financial_value', 'investor_fund']);
}

it('offers Nova Integralização as a secondary action before Importar Dados on the view page', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    $integralizationHistory = $emission->integralizationHistories()->create(['date' => '2026-06-01', 'quantity' => 100]);

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->assertTableHeaderActionsExistInOrder(['manage_template', 'create', 'import'])
        ->assertTableActionVisible('create')
        ->assertTableActionHasLabel('create', 'Nova Integralização')
        ->assertTableActionHasIcon('create', 'heroicon-m-plus')
        ->assertTableActionExists('create', fn (CreateAction $action): bool => $action->isOutlined())
        ->assertTableActionVisible('import')
        ->assertTableActionExists('import', fn (Action $action): bool => ($action->getColor() === 'primary') && (! $action->isOutlined()))
        ->assertTableActionHidden('edit', $integralizationHistory)
        ->assertTableActionHidden('delete', $integralizationHistory)
        ->mountTableAction('create')
        ->assertFormFieldExists('date', fn (DatePicker $field): bool => $field->getDisplayFormat() === 'd/m/Y')
        ->assertFormFieldExists('financial_value', fn (TextInput $field): bool => $field->isReadOnly())
        ->assertFormFieldExists('investor_fund', fn (Select $field): bool => $field->isSearchable() && ($field->getColumnSpan('default') === 'full'));
});

it('creates an integralization manually, closes the modal and shows it in the table', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();
    $user = integralizationEditorUser();

    $this->actingAs($user);

    $component = integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertHasNoTableActionErrors()
        ->assertNotified('Integralização adicionada com sucesso.')
        ->assertTableActionNotMounted('create');

    $integralizationHistory = IntegralizationHistory::query()->sole();

    $component->assertCanSeeTableRecords([$integralizationHistory]);

    expect($integralizationHistory->emission_id)->toBe($emission->id)
        ->and($integralizationHistory->date?->toDateString())->toBe('2026-06-04')
        ->and(integralizationValues($integralizationHistory))->toBe([
            'quantity' => '4000.0000',
            'unit_value' => '1000.98765432',
            'financial_value' => '4003950.62',
            'investor_fund' => 'Headinvest Asset Management',
        ])
        ->and($emission->refresh()->integralized_quantity)->toBe(4000);
});

it('persists nothing when the modal is cancelled', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->mountTableAction('create')
        ->setTableActionData(manualIntegralizationData())
        ->call('unmountAction')
        ->assertTableActionNotMounted('create');

    expect(IntegralizationHistory::query()->count())->toBe(0);
});

/**
 * O valor conferido é o que a gravação entrega ao banco. O SQLite da suíte
 * guarda NUMERIC com 15 dígitos significativos e devolveria o último caso
 * arredondado; o `DECIMAL(18,2)` do MySQL guarda a string exata.
 */
it('previews the financial value with the same exact rule the recording applies', function (string $quantity, string $unitValue, string $preview, string $recorded) {
    $emission = Emission::factory()->create(['issued_quantity' => 10_000_000]);
    integralizationInvestorFund();
    $recordedFinancialValue = null;

    IntegralizationHistory::created(function (IntegralizationHistory $integralizationHistory) use (&$recordedFinancialValue): void {
        $recordedFinancialValue = $integralizationHistory->getAttributes()['financial_value'];
    });

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->mountTableAction('create')
        ->setTableActionData(manualIntegralizationData(['quantity' => $quantity, 'unit_value' => $unitValue]))
        ->assertTableActionDataSet(['financial_value' => $preview])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($recordedFinancialValue)->toBe($recorded);
})->with([
    'realistic amount' => ['4.000', '1.000,98765432', '4.003.950,62', '4003950.62'],
    'half cent rounds up' => ['1.250', '1.000,98765432', '1.251.234,57', '1251234.57'],
    /**
     * 9.999.999 × 99.999.999,99999999 = 999.999.899.999.999,90000001. Em
     * `float` o produto vira ...999,875 e o centavo sai ,88.
     */
    'beyond float precision' => ['9.999.999', '99.999.999,99999999', '999.999.899.999.999,90', '999999899999999.90'],
]);

it('stores the same normalized values as the spreadsheet import for the same integralization', function () {
    $importedEmission = Emission::factory()->create(['issued_quantity' => 10000]);
    $manualEmission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    importIntegralizationRows($importedEmission, [
        ['04/06/2026', '4000', '1.000,98765432', '', 'Headinvest Asset Management'],
        ['05/06/2026', 1250, 1000.98765432, '', 'Headinvest Asset Management'],
    ]);

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($manualEmission)
        ->callTableAction('create', data: manualIntegralizationData(['date' => '2026-06-04']))
        ->assertHasNoTableActionErrors()
        ->callTableAction('create', data: manualIntegralizationData(['date' => '2026-06-05', 'quantity' => '1.250']))
        ->assertHasNoTableActionErrors();

    $imported = $importedEmission->integralizationHistories()->orderBy('date')->get()->map(integralizationValues(...))->all();
    $manual = $manualEmission->integralizationHistories()->orderBy('date')->get()->map(integralizationValues(...))->all();

    expect($manual)->toBe($imported)
        ->and($manual[0]['financial_value'])->toBe('4003950.62')
        ->and($manual[1]['financial_value'])->toBe('1251234.57');
});

it('rejects an invalid quantity inline and persists nothing', function (mixed $quantity, string $message) {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData(['quantity' => $quantity]))
        ->assertHasTableActionErrors(['quantity' => $message])
        ->assertTableActionMounted('create')
        ->assertNotNotified('Integralização adicionada com sucesso.');

    expect(IntegralizationHistory::query()->count())->toBe(0);
})->with([
    'blank' => ['', 'Informe a quantidade a integralizar.'],
    'zero' => ['0', 'A quantidade deve ser maior que zero.'],
    'negative' => ['-5', 'A quantidade deve ser maior que zero.'],
    'not a number' => ['quatro mil', 'Informe uma quantidade válida.'],
]);

it('rejects an invalid PU inline and persists nothing', function (mixed $unitValue, string $message) {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData(['unit_value' => $unitValue]))
        ->assertHasTableActionErrors(['unit_value' => $message])
        ->assertNotNotified('Integralização adicionada com sucesso.');

    expect(IntegralizationHistory::query()->count())->toBe(0);
})->with([
    'blank' => ['', 'Informe o PU da integralização.'],
    'zero' => ['0,00000000', 'O PU deve ser maior que zero.'],
    'negative' => ['-1.000,00', 'O PU deve ser maior que zero.'],
    'not a number' => ['mil reais', 'Informe um PU válido.'],
]);

it('rejects values the DECIMAL(18, x) columns cannot hold instead of failing in the database', function (array $overrides, string $field, string $message) {
    $emission = Emission::factory()->create(['issued_quantity' => 100_000_000]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData($overrides))
        ->assertHasTableActionErrors([$field => $message])
        ->assertNotNotified('Não foi possível adicionar a integralização.');

    expect(IntegralizationHistory::query()->count())->toBe(0);
})->with([
    'PU with 11 integer digits' => [['unit_value' => '12.345.678.901,00'], 'unit_value', 'O PU deve ter no máximo 10 dígitos antes da vírgula.'],
    'financial value with 17 integer digits' => [['quantity' => '99.999.999', 'unit_value' => '9.999.999.999,00'], 'financial_value', 'O Valor Financeiro deve ter no máximo 16 dígitos antes da vírgula.'],
]);

it('reports an out of range PU in a spreadsheet as a validation message and imports nothing', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000, 'integralized_quantity' => 0]);

    expect(fn () => importIntegralizationRows($emission, [
        ['01/06/2026', '1000', '1.000,00', '', 'Fundo A'],
        ['04/06/2026', '1000', '12345678901', '', 'Fundo B'],
    ]))->toThrow(ValidationException::class, 'O PU deve ter no máximo 10 dígitos antes da vírgula.');

    expect(IntegralizationHistory::query()->count())->toBe(0)
        ->and($emission->refresh()->integralized_quantity)->toBe(0);
});

it('rejects an investor fund outside the investor fund registry', function (string $investorFund) {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();
    ExpenseServiceProvider::factory()->create([
        'name' => 'Prestador Irrelevante',
        'expense_service_provider_type_id' => ExpenseServiceProviderType::factory()->create()->getKey(),
    ]);

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData(['investor_fund' => $investorFund]))
        ->assertHasTableActionErrors(['investor_fund' => 'Selecione um fundo do investidor cadastrado.']);

    expect(IntegralizationHistory::query()->count())->toBe(0);
})->with([
    'provider of another type' => ['Prestador Irrelevante'],
    'free text' => ['Fundo Inexistente'],
]);

it('requires the investor fund on a new integralization', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData(['investor_fund' => null]))
        ->assertHasTableActionErrors(['investor_fund' => 'Selecione o fundo do investidor.']);

    expect(IntegralizationHistory::query()->count())->toBe(0);
});

it('hides both recording actions from users who cannot update emissions and refuses forged requests', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    $forgeCreateRequest = fn (Testable $component): Testable => $component
        ->set('mountedActions', [[
            'name' => 'create',
            'arguments' => [],
            'context' => ['table' => true],
            'data' => manualIntegralizationData(),
        ]])
        ->call('callMountedAction');

    $this->actingAs(integralizationViewerUser());

    $forgeCreateRequest(
        integralizationHistoryOn($emission)
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('import')
            ->call('mountAction', 'create', [], ['table' => true])
            ->assertTableActionNotMounted('create'),
    )->assertNotNotified('Integralização adicionada com sucesso.');

    expect(IntegralizationHistory::query()->count())->toBe(0);

    /**
     * A mesma requisição forjada grava para quem tem permissão: a recusa acima
     * vem da autorização, não de um estado de teste que nunca chegaria à ação.
     */
    $this->actingAs(integralizationEditorUser());

    $forgeCreateRequest(integralizationHistoryOn($emission));

    expect(IntegralizationHistory::query()->count())->toBe(1);
});

it('refuses a second integralization on a date the emission already has, as the import keys by date', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    importIntegralizationRows($emission, [
        ['04/06/2026', '1000', '1.000,00', '', 'Head Invest'],
    ]);

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertHasTableActionErrors([
            'date' => 'Já existe uma integralização em 04/06/2026 nesta emissão. A importação identifica a integralização pela data, por isso não é possível registrar outra no mesmo dia.',
        ])
        ->assertNotNotified('Integralização adicionada com sucesso.');

    expect(IntegralizationHistory::query()->sole()->quantity)->toBe('1000.0000')
        ->and($emission->refresh()->integralized_quantity)->toBe(1000);
});

it('creates a single integralization when the same submission arrives twice', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertHasNoTableActionErrors()
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertHasTableActionErrors(['date']);

    expect(IntegralizationHistory::query()->count())->toBe(1)
        ->and($emission->refresh()->integralized_quantity)->toBe(4000);
});

it('lets a later spreadsheet update the manually created integralization of the same date', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertHasNoTableActionErrors();

    $manualIntegralization = IntegralizationHistory::query()->sole();

    importIntegralizationRows($emission, [
        ['04/06/2026', '5000', '1.000,50', '', 'Headinvest Asset Management'],
    ]);

    expect(IntegralizationHistory::query()->sole()->is($manualIntegralization))->toBeTrue()
        ->and(integralizationValues($manualIntegralization->refresh()))->toBe([
            'quantity' => '5000.0000',
            'unit_value' => '1000.50000000',
            'financial_value' => '5002500.00',
            'investor_fund' => 'Headinvest Asset Management',
        ])
        ->and($emission->refresh()->integralized_quantity)->toBe(5000);
});

it('refuses to guess which integralization a spreadsheet row updates when the date is ambiguous', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    $first = $emission->integralizationHistories()->create(['date' => '2026-06-04', 'quantity' => 1000, 'investor_fund' => 'Fundo A']);
    $second = $emission->integralizationHistories()->create(['date' => '2026-06-04', 'quantity' => 2000, 'investor_fund' => 'Fundo B']);

    expect(fn () => importIntegralizationRows($emission, [
        ['01/06/2026', '500', '1.000,00', '', 'Fundo C'],
        ['04/06/2026', '3000', '1.000,00', '', 'Fundo A'],
    ]))->toThrow(ValidationException::class, 'Há mais de uma integralização em 04/06/2026 nesta emissão; a planilha não identifica qual delas atualizar.');

    expect($first->refresh()->quantity)->toBe('1000.0000')
        ->and($second->refresh()->quantity)->toBe('2000.0000')
        ->and(IntegralizationHistory::query()->count())->toBe(2)
        ->and($emission->refresh()->integralized_quantity)->toBe(3000);
});

it('does not touch another emission integralized on the same date', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    $otherEmission = Emission::factory()->create(['issued_quantity' => 10000]);
    $otherIntegralization = $otherEmission->integralizationHistories()->create([
        'date' => '2026-06-04',
        'quantity' => 9000,
        'unit_value' => '1000.00000000',
        'financial_value' => '9000000.00',
        'investor_fund' => 'Troupe FIM',
    ]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$otherIntegralization]);

    expect($emission->integralizationHistories()->sole()->quantity)->toBe('4000.0000')
        ->and($emission->refresh()->integralized_quantity)->toBe(4000)
        ->and(integralizationValues($otherIntegralization->refresh()))->toBe([
            'quantity' => '9000.0000',
            'unit_value' => '1000.00000000',
            'financial_value' => '9000000.00',
            'investor_fund' => 'Troupe FIM',
        ])
        ->and($otherEmission->refresh()->integralized_quantity)->toBe(9000);
});

it('records who created the integralization and through which channel in the activity log', function () {
    $emission = Emission::factory()->create(['issued_quantity' => 10000]);
    integralizationInvestorFund();
    $user = integralizationEditorUser();

    $this->actingAs($user);

    integralizationHistoryOn($emission)
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertHasNoTableActionErrors();

    $integralizationHistory = IntegralizationHistory::query()->sole();

    importIntegralizationRows($emission, [
        ['04/06/2026', '5000', '1.000,50', '', 'Headinvest Asset Management'],
    ]);

    $activities = Activity::query()
        ->whereMorphedTo('subject', $integralizationHistory)
        ->orderBy('id')
        ->get();

    expect($activities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($activities[0]->causer?->is($user))->toBeTrue()
        ->and($activities[0]->properties['source'])->toBe('manual')
        ->and($activities[0]->properties['attributes'])->toMatchArray([
            'emission_id' => $emission->id,
            'quantity' => '4000.0000',
            'unit_value' => '1000.98765432',
            'financial_value' => '4003950.62',
            'investor_fund' => 'Headinvest Asset Management',
        ])
        ->and($activities[1]->properties['source'])->toBe('spreadsheet')
        ->and($activities[1]->properties['old']['quantity'])->toBe('4000.0000')
        ->and($activities[1]->properties['attributes']['quantity'])->toBe('5000.0000');
});

it('keeps the modal open with a generic message when the recording fails unexpectedly', function () {
    Exceptions::fake();

    $emission = Emission::factory()->create(['issued_quantity' => 10000, 'integralized_quantity' => 0]);
    integralizationInvestorFund();

    $this->actingAs(integralizationEditorUser());

    $component = integralizationHistoryOn($emission);

    Activity::creating(function (): never {
        throw new RuntimeException('SQLSTATE[HY000]: activity_log indisponível');
    });

    $component
        ->callTableAction('create', data: manualIntegralizationData())
        ->assertTableActionMounted('create');

    $notification = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->sole();

    expect($notification['title'])->toBe('Não foi possível adicionar a integralização.')
        ->and(filled($notification['body'] ?? null))->toBeFalse()
        ->and(json_encode($notification))->not->toContain('SQLSTATE')
        ->and(json_encode($notification))->not->toContain('activity_log');

    Exceptions::assertReported(RuntimeException::class);

    expect(IntegralizationHistory::query()->count())->toBe(0)
        ->and($emission->refresh()->integralized_quantity)->toBe(0);
});
