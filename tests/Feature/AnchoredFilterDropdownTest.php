<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Constructions\Pages\ListConstructions;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\ExpenseServiceProviders\Pages\ListExpenseServiceProviders;
use App\Filament\Resources\Funds\Pages\ListFunds;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Negotiations\Pages\ListNegotiations;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\ExpenseServiceProviderType;
use App\Models\Measurement;
use App\Models\Negotiation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Todo filtro de Emissão do painel, com o caminho do arquivo em que o
 * `SelectFilter`/`Select` é construído. A lista é o contrato do escopo: um filtro
 * novo só entra aqui depois de receber o dropdown compartilhado.
 *
 * @return array<int, string>
 */
function emissionFilterSourceFiles(): array
{
    return [
        'app/Filament/Resources/Constructions/Tables/ConstructionsTable.php',
        'app/Filament/Resources/ConstructionUnits/Tables/ConstructionUnitsTable.php',
        'app/Filament/Resources/ContractInstallments/Tables/ContractInstallmentsTable.php',
        'app/Filament/Resources/Contracts/Tables/ContractsTable.php',
        'app/Filament/Resources/Documents/Tables/DocumentsTable.php',
        'app/Filament/Resources/EmissionMonthlyReportNotes/Tables/EmissionMonthlyReportNotesTable.php',
        'app/Filament/Resources/Expenses/Tables/ExpensesTable.php',
        'app/Filament/Resources/Funds/Tables/FundsTable.php',
        'app/Filament/Resources/Measurements/Tables/MeasurementsTable.php',
        'app/Filament/Resources/Negotiations/Tables/NegotiationsTable.php',
        'app/Filament/Resources/Operations/Tables/OperationsTable.php',
        'app/Filament/Resources/PaymentWorkspaces/Tables/PaymentWorkspaceTable.php',
        'app/Filament/Resources/Receivables/Tables/ReceivablesTable.php',
        'app/Filament/Resources/SalesBoardCycles/Tables/SalesBoardCyclesTable.php',
        'app/Filament/Resources/SalesBoards/Tables/SalesBoardsTable.php',
        'app/Filament/Widgets/Obligations/ObligationOperationalTableWidget.php',
    ];
}

/**
 * Todo filtro de Empreendimento do painel: a página de listagem e o nome do filtro.
 *
 * @return array<string, array{0: class-string, 1: string}>
 */
function constructionFilterPages(): array
{
    return [
        'contratos' => [ListContracts::class, 'construction_id'],
        'parcelas de contratos' => [ListContractInstallments::class, 'construction'],
        'unidades' => [ListConstructionUnits::class, 'construction_id'],
        'negociações' => [ListNegotiations::class, 'construction_id'],
        'quadros de vendas' => [ListSalesBoards::class, 'construction_id'],
        'ciclos do quadro de vendas' => [ListSalesBoardCycles::class, 'construction_id'],
        'empreendimentos' => [ListConstructions::class, 'development_name'],
    ];
}

/**
 * Todo filtro de Operação das tabelas do painel: a página de listagem e o nome do filtro.
 * Despesas e Fundos filtram a emissão, mas o rótulo exibido é "Operação".
 *
 * @return array<string, array{0: class-string, 1: string}>
 */
function operationFilterPages(): array
{
    return [
        'medições' => [ListMeasurements::class, 'operation_id'],
        'workspace de pagamentos' => [ListPaymentWorkspace::class, 'operation_id'],
        'despesas' => [ListExpenses::class, 'emission_id'],
        'fundos' => [ListFunds::class, 'emission_id'],
    ];
}

/**
 * Todo filtro de Bloco das tabelas do painel: a página de listagem e o nome do filtro.
 *
 * @return array<string, array{0: class-string, 1: string}>
 */
function blockFilterPages(): array
{
    return [
        'unidades' => [ListConstructionUnits::class, 'block'],
    ];
}

/**
 * Todo filtro "Tipo" renderizado pelo select JS do Filament: a página de listagem e o nome
 * do filtro. Os demais filtros "Tipo" (importações, alterações de importação, movimentos
 * do ciclo) usam o `<select>` nativo, cujo popup é do navegador e nunca foi 100vw.
 *
 * @return array<string, array{0: class-string, 1: string}>
 */
function typeFilterPages(): array
{
    return [
        'negociações' => [ListNegotiations::class, 'tipo'],
        'prestadores de serviço' => [ListExpenseServiceProviders::class, 'expense_service_provider_type_id'],
    ];
}

/**
 * Trechos de código de cada `SelectFilter` rotulado com `$label` em `app/`, do
 * `SelectFilter::make(` até o próximo `Algo::make(` do arquivo (outro filtro, coluna,
 * ação), para que o último filtro de um arquivo não arraste o código seguinte.
 *
 * @return array<int, array{path: string, chain: string}>
 */
function selectFilterChainsLabeled(string $label): array
{
    $chains = [];

    foreach (File::allFiles(app_path()) as $file) {
        $source = $file->getContents();

        if (! str_contains($source, 'SelectFilter::make(')) {
            continue;
        }

        foreach (array_slice(explode('SelectFilter::make(', $source), 1) as $tail) {
            $chain = preg_split('/\b[A-Z]\w*::make\(/', $tail, 2)[0];

            if (str_contains($chain, "->label('{$label}')")) {
                $chains[] = ['path' => str_replace(base_path().'/', '', $file->getPathname()), 'chain' => $chain];
            }
        }
    }

    return $chains;
}

function actingAsFilterDropdownAdmin(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super-admin');

    test()->actingAs($user);
}

it('marks the select built by a SelectFilter with the shared dropdown class', function () {
    $filter = SelectFilter::make('emission_id')
        ->label('Emissão')
        ->options(['1' => 'CRI Alto Bellevue'])
        ->searchable()
        ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField());

    $field = $filter->getSchemaComponents()[0];

    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
});

it('keeps the filter options and searchability untouched by the shared dropdown', function () {
    $options = ['7' => 'CRI Alto Bellevue', '9' => '[UAT F] Emissão'];

    $plain = SelectFilter::make('emission_id')->options($options)->searchable()->preload();
    $styled = SelectFilter::make('emission_id')
        ->options($options)
        ->searchable()
        ->preload()
        ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField());

    // O callback recebe o `Select` já montado pelo filtro; é nele, e não no filtro, que
    // uma regressão apareceria.
    $plainField = $plain->getSchemaComponents()[0];
    $styledField = $styled->getSchemaComponents()[0];

    expect($styledField->getOptions())->toBe($options)
        ->and($styledField->getOptions())->toBe($plainField->getOptions())
        ->and($styledField->isSearchable())->toBeTrue()
        ->and($styledField->isPreloaded())->toBe($plainField->isPreloaded())
        ->and($styledField->getStatePath(isAbsolute: false))->toBe($plainField->getStatePath(isAbsolute: false));
});

it('wires every emission filter in the panel to the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    expect($source)
        ->toContain('use App\Filament\Support\AnchoredFilterDropdown;')
        ->toContain('->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())');
})->with(emissionFilterSourceFiles());

it('wires every SelectFilter labeled Emissão to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Emissão');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // O teste por arquivo acima não distingue o filtro de Emissão do de Empreendimento
    // quando os dois moram na mesma tabela; este confere filtro a filtro. Os filtros do
    // campo emissão rotulados "Série" (um por arquivo) ficam com o de cima; os rotulados
    // "Operação", com o contrato de Operação abaixo.
    expect($chains)->toHaveCount(13)
        ->and($unwired)->toBe([]);
});

it('wires the dashboard emission filter fields to the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    expect($source)
        ->toContain('use App\Filament\Support\AnchoredFilterDropdown;')
        ->toContain('->extraAttributes(AnchoredFilterDropdown::fieldAttributes())');
})->with([
    'app/Filament/Pages/Dashboard.php',
    'app/Filament/Pages/ObligationDashboard.php',
]);

it('renders the shared dropdown class in the emission filter of a real table', function () {
    actingAsFilterDropdownAdmin();

    Emission::factory()->create(['name' => 'CRI Alto Bellevue']);

    Livewire::test(ListReceivables::class)
        ->assertTableFilterExists('emission_id')
        ->assertSee(AnchoredFilterDropdown::DROPDOWN_CLASS, escape: false);
});

it('anchors the dropdown of every Empreendimento filter to its trigger', function (string $page, string $filterName) {
    actingAsFilterDropdownAdmin();

    // A listagem de empreendimentos só registra filtros quando já existe algum.
    Construction::factory()->create(['development_name' => '[UAT F] AutoOpen']);

    $filter = Livewire::test($page)
        ->assertTableFilterExists($filterName)
        ->instance()
        ->getTable()
        ->getFilter($filterName);

    $field = $filter->getSchemaComponents()[0];

    expect($filter->getLabel())->toBe('Empreendimento')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
})->with(constructionFilterPages());

it('wires every Empreendimento SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Empreendimento');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    expect($chains)->toHaveCount(count(constructionFilterPages()))
        ->and($unwired)->toBe([]);
});

it('anchors the dropdown of the Empresa de medição filter to its trigger', function () {
    actingAsFilterDropdownAdmin();

    // A listagem de obras só registra filtros quando já existe alguma.
    Construction::factory()->create(['development_name' => '[UAT F] AutoOpen']);

    $filter = Livewire::test(ListConstructions::class)
        ->assertTableFilterExists('measurement_company_id')
        ->instance()
        ->getTable()
        ->getFilter('measurement_company_id');

    $field = $filter->getSchemaComponents()[0];

    expect($filter->getLabel())->toBe('Empresa de medição')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
});

it('wires every Empresa de medição SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Empresa de medição');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // Hoje existe um único filtro com esse rótulo (a listagem de obras). A contagem é o
    // contrato: um filtro novo de Empresa de medição só passa aqui depois de aderir.
    expect($chains)->toHaveCount(1)
        ->and($unwired)->toBe([]);
});

it('anchors the dropdown of every Operação filter to its trigger', function (string $page, string $filterName) {
    actingAsFilterDropdownAdmin();

    // A listagem de medições só registra filtros quando já existe alguma visível.
    Measurement::factory()->create();

    $filter = Livewire::test($page)
        ->assertTableFilterExists($filterName)
        ->instance()
        ->getTable()
        ->getFilter($filterName);

    $field = $filter->getSchemaComponents()[0];

    expect($filter->getLabel())->toBe('Operação')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
})->with(operationFilterPages());

it('wires every Operação SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Operação');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // O teste por arquivo de Emissão não enxerga o filtro de Operação que divide a tabela
    // com um filtro de Emissão já aderido (medições, workspace de pagamentos); este confere
    // filtro a filtro. A contagem é o contrato: um filtro novo só passa depois de aderir.
    expect($chains)->toHaveCount(count(operationFilterPages()))
        ->and($unwired)->toBe([]);
});

it('anchors the dropdown of every Bloco filter to its trigger', function (string $page, string $filterName) {
    actingAsFilterDropdownAdmin();

    ConstructionUnit::factory()->create(['block' => '01', 'unit' => '101']);

    $filter = Livewire::test($page)
        ->assertTableFilterExists($filterName)
        ->instance()
        ->getTable()
        ->getFilter($filterName);

    $field = $filter->getSchemaComponents()[0];

    expect($filter->getLabel())->toBe('Bloco')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
})->with(blockFilterPages());

it('wires every Bloco SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Bloco');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    expect($chains)->toHaveCount(count(blockFilterPages()))
        ->and($unwired)->toBe([]);
});

it('anchors the dropdown of every Tipo filter rendered by the JS select to its trigger', function (string $page, string $filterName) {
    actingAsFilterDropdownAdmin();

    Negotiation::factory()->create();
    ExpenseServiceProviderType::factory()->create();

    $filter = Livewire::test($page)
        ->assertTableFilterExists($filterName)
        ->instance()
        ->getTable()
        ->getFilter($filterName);

    $field = $filter->getSchemaComponents()[0];

    // O Filament só troca o `<select>` nativo pelo select JS quando o campo é pesquisável,
    // múltiplo, aceita HTML ou declara `native(false)`; é esse select que tinha o defeito.
    expect($filter->getLabel())->toBe('Tipo')
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->isNative() && ! $field->isSearchable())->toBeFalse()
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
})->with(typeFilterPages());

it('keeps the Venda and Distrato options of the negotiations Tipo filter', function () {
    actingAsFilterDropdownAdmin();

    Negotiation::factory()->create();

    $field = Livewire::test(ListNegotiations::class)
        ->instance()
        ->getTable()
        ->getFilter('tipo')
        ->getSchemaComponents()[0];

    expect($field->getOptions())->toBe(['Venda' => 'Venda', 'Distrato' => 'Distrato'])
        ->and($field->isSearchable())->toBeFalse();
});

it('wires exactly the Tipo SelectFilters rendered by the JS select to the shared dropdown', function () {
    $chains = collect(selectFilterChainsLabeled('Tipo'));

    $rendersJsSelect = fn (array $chain): bool => str_contains($chain['chain'], '->searchable()')
        || str_contains($chain['chain'], '->native(false)')
        || str_contains($chain['chain'], '->multiple()');
    $isWired = fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())');

    // "Tipo" é rótulo genérico: só os filtros com select JS aderem. Os nativos ficam fora
    // de propósito, e a contagem total é o contrato para um filtro "Tipo" novo.
    expect($chains)->toHaveCount(5)
        ->and($chains->filter($rendersJsSelect)->pluck('path')->sort()->values()->all())
        ->toBe([
            'app/Filament/Resources/ExpenseServiceProviders/Tables/ExpenseServiceProvidersTable.php',
            'app/Filament/Resources/Negotiations/Tables/NegotiationsTable.php',
        ])
        ->and($chains->filter($rendersJsSelect)->reject($isWired)->all())->toBe([])
        ->and($chains->reject($rendersJsSelect)->filter($isWired)->all())->toBe([]);
});

it('leaves the Tipo registration fields out of the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    // O mesmo rótulo existe nos cadastros (prestador, obrigações); a correção é só de filtro.
    expect($source)
        ->toContain("->label('Tipo')")
        ->not->toContain('AnchoredFilterDropdown');
})->with([
    'app/Filament/Resources/ExpenseServiceProviders/Schemas/ExpenseServiceProviderForm.php',
    'app/Filament/Resources/Emissions/Schemas/ObligationFormFields.php',
    'app/Filament/Resources/Emissions/Schemas/ObligationSeriesFormFields.php',
]);

it('leaves the Bloco registration and editing fields out of the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    expect($source)
        ->toContain("->label('Bloco')")
        ->not->toContain('AnchoredFilterDropdown');
})->with([
    'app/Filament/Resources/ConstructionUnits/Schemas/ConstructionUnitForm.php',
    'app/Filament/Resources/SalesBoardCycles/Pages/BuilderReviewWorkspace.php',
]);

it('anchors the Operação and Emissão fields of the cockpit filters form to their triggers', function () {
    $this->actingAs(new User);

    $dashboard = new Dashboard;
    $schema = $dashboard->filtersForm(Schema::make($dashboard));

    $fields = collect($schema->getFlatComponents(withHidden: true))
        ->filter(fn ($component): bool => $component instanceof Select)
        ->keyBy(fn (Select $select): string => $select->getName());

    // Responsável segue no bloco legado `.bsi-responsible-filter`, fora deste contrato.
    expect($fields->get('operation_id')?->getLabel())->toBe('Operação')
        ->and($fields->get('operation_id')->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($fields->get('emission_id')->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
});

it('leaves the Operação registration fields out of the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    // O mesmo rótulo existe nos cadastros; a correção é só de filtro.
    expect($source)
        ->toContain("->label('Operação')")
        ->not->toContain('AnchoredFilterDropdown');
})->with([
    'app/Filament/Resources/Measurements/Schemas/MeasurementForm.php',
    'app/Filament/Resources/ResponsibilityDelegations/Schemas/ResponsibilityDelegationForm.php',
    'app/Filament/Resources/Negotiations/Schemas/NegotiationForm.php',
    'app/Filament/Resources/SalesBoards/Schemas/SalesBoardForm.php',
    'app/Filament/Resources/Funds/Schemas/FundForm.php',
    'app/Filament/Resources/Expenses/Schemas/ExpenseForm.php',
]);

it('leaves the Empresa de medição registration field out of the shared dropdown', function () {
    $source = file_get_contents(base_path('app/Filament/Resources/Constructions/Schemas/ConstructionForm.php'));

    // O mesmo rótulo existe no cadastro de obras; a correção é só de filtro.
    expect($source)
        ->toContain("->label('Empresa de medição')")
        ->not->toContain('AnchoredFilterDropdown');
});

it('keeps the shared dropdown out of registration forms, modals and relation managers', function () {
    $allowed = [
        'app/Filament/Support/AnchoredFilterDropdown.php',
        'app/Filament/Pages/Dashboard.php',
        'app/Filament/Pages/ObligationDashboard.php',
        'app/Filament/Widgets/Obligations/ObligationOperationalTableWidget.php',
    ];

    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => str_contains(file_get_contents($file->getPathname()), 'AnchoredFilterDropdown'))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->reject(fn (string $path): bool => in_array($path, $allowed, true) || str_contains($path, '/Tables/'))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('anchors the dropdown to the trigger width instead of the viewport', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    $panelRule = str($css)
        ->after(".bsi-anchored-filter-dropdown .fi-dropdown-panel,\n.bsi-anchored-filter-dropdown .fi-select-input .fi-dropdown-panel {")
        ->before('}')
        ->toString();

    // O `width` é o inline do Filament (largura do trigger); o tema só anula o piso de
    // 100% e nunca declara uma largura própria. Um `max-width` aqui perderia para o
    // `max-width: 100% !important` em camada do Filament e não teria efeito.
    expect($panelRule)
        ->toContain('min-width: 0 !important')
        ->toContain('max-height: min(22.5rem, 44vh) !important')
        ->not->toContain('width: 100')
        ->not->toContain('max-width');
});

it('isolates the dropdown scroll in the options list', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.bsi-anchored-filter-dropdown .fi-dropdown-panel .fi-dropdown-list,')
        ->toContain('grid-auto-rows: min-content !important')
        ->toContain('align-content: start !important');
});

it('keeps the dropdown open and close lifecycle driven by the inline display', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.bsi-anchored-filter-dropdown .fi-dropdown-panel[style*="display: block"],')
        ->toContain('.bsi-anchored-filter-dropdown .fi-dropdown-panel[style*="display: none"],');
});

it('stops the table filter panel rules from reaching nested select panels', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    // Sem seletor descendente por `wire:key`: nem o antigo, que alcançava os painéis dos
    // selects aninhados, nem uma versão com `:has()`, cuja especificidade (0,3,0) venceria
    // o teto de 44vh com scroll e o override de celular do próprio painel de filtros.
    expect($css)
        ->toContain(".fi-dropdown-panel:has(.fi-ta-filters),\n")
        ->toContain(".fi-dropdown-panel:has(.fi-ta-col-manager),\n")
        ->not->toContain('[wire\:key*="table.filters"] .fi-dropdown-panel')
        ->not->toContain('[wire\:key*="table.column-manager"] .fi-dropdown-panel');
});
