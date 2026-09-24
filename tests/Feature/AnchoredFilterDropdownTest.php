<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Constructions\Pages\ListConstructions;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationEvidencesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\ExpenseServiceProviders\Pages\ListExpenseServiceProviders;
use App\Filament\Resources\FundNames\Pages\ListFundNames;
use App\Filament\Resources\Funds\Pages\ListFunds;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Negotiations\Pages\ListNegotiations;
use App\Filament\Resources\Nimbus\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Nimbus\GeneralDocuments\Pages\ListGeneralDocuments;
use App\Filament\Resources\Nimbus\PortalDocuments\Pages\ListPortalDocuments;
use App\Filament\Resources\Nimbus\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Document;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use App\Models\Fund;
use App\Models\FundApplication;
use App\Models\FundName;
use App\Models\FundType;
use App\Models\Measurement;
use App\Models\Negotiation;
use App\Models\Nimbus\Announcement;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\ObligationSeries;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;
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
 * Todo filtro "Solicitante" das tabelas do painel: a página de listagem e o nome do filtro.
 *
 * @return array<string, array{0: class-string, 1: string}>
 */
function solicitanteFilterPages(): array
{
    return [
        'envios e solicitações' => [ListSubmissions::class, 'nimbus_portal_user_id'],
    ];
}

/**
 * Todo filtro "Criado por" das tabelas do painel: a página de listagem. O nome do filtro
 * é `created_by_user_id` nas duas telas.
 *
 * @return array<string, class-string>
 */
function createdByFilterPages(): array
{
    return [
        'biblioteca geral' => ListGeneralDocuments::class,
        'avisos' => ListAnnouncements::class,
    ];
}

/**
 * Todo filtro "Série" das tabelas do painel. Documentos filtra emissões (rótulo "Série");
 * a fila operacional do Painel de Obrigações e a aba de obrigações da Emissão filtram séries
 * de obrigação. O campo "Série" do formulário de filtros do Painel de Obrigações não entra:
 * ele não está em `.fi-fixed-positioning-context`, o painel é `absolute` e sempre teve a
 * largura do trigger.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function seriesFilterScreens(): array
{
    return [
        'documentos' => ['documents', 'emissions'],
        'fila operacional do painel de obrigações' => ['obligation-dashboard', 'obligation_series_id'],
        'obrigações da emissão' => ['emission-obligations', 'obligation_series_id'],
    ];
}

/**
 * Monta a tela que hospeda o filtro "Série". As telas vêm como chave de texto, e não como
 * closure no dataset, para que a montagem de fato rode dentro do teste.
 */
function seriesFilterTable(string $screen, Emission $emission): Testable
{
    return match ($screen) {
        'documents' => Livewire::test(ListDocuments::class),
        'obligation-dashboard' => Livewire::test(ObligationOperationalTableWidget::class),
        'emission-obligations' => Livewire::test(ObligationsRelationManager::class, [
            'ownerRecord' => $emission,
            'pageClass' => EditEmission::class,
        ]),
    };
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

/**
 * Monta a aba de evidências da Emissão, a única tela com filtro "Obrigação".
 */
function obligationEvidencesTable(Emission $emission): Testable
{
    return Livewire::test(ObligationEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ]);
}

/**
 * Diz se um RelationManager adere ao dropdown compartilhado só pela tabela. Tirando o `use`
 * sem alias, toda menção à classe precisa ser a chamada `modifyFormFieldUsing()`, que existe
 * apenas em filtros; `fieldAttributes()`, a constante ou um alias marcariam um campo do
 * formulário ou de um modal do RelationManager.
 */
function onlyWiresTableFilters(string $source): bool
{
    $mentions = substr_count($source, 'AnchoredFilterDropdown')
        - substr_count($source, 'use App\Filament\Support\AnchoredFilterDropdown;');

    return $mentions === substr_count($source, '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())');
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

it('anchors every Tipo de fundo filter without changing relationship options or search', function (string $page) {
    actingAsFilterDropdownAdmin();

    $shortType = FundType::factory()->create(['name' => 'Crédito']);
    $longType = FundType::factory()->create(['name' => str_repeat('Fundo de Reserva para Operações Imobiliárias ', 4)]);

    $component = Livewire::test($page)->assertTableFilterExists('fund_type_id');
    $field = collect($component->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($field): bool => $field instanceof Select && $field->getStatePath() === 'tableDeferredFilters.fund_type_id.value');

    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getRelationshipName())->toBe('fundType')
        ->and($field->getRelationshipTitleAttribute())->toBe('name')
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isPreloaded())->toBeTrue()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('Todos')
        ->and($field->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($field->getOptions())->toBe([
            $shortType->id => $shortType->name,
            $longType->id => $longType->name,
        ])
        ->and($field->getSearchResults('Reserva'))->toBe([$longType->id => $longType->name])
        ->and($field->getSearchResults('inexistente'))->toBe([]);
})->with([
    'fundos' => [ListFunds::class],
    'nomes de fundo' => [ListFundNames::class],
]);

it('wires both capitalizations of Tipo de fundo to the shared dropdown', function () {
    $chains = [...selectFilterChainsLabeled('Tipo de fundo'), ...selectFilterChainsLabeled('Tipo de Fundo')];

    expect(collect($chains)->pluck('path')->sort()->values()->all())->toBe([
        'app/Filament/Resources/FundNames/Tables/FundNamesTable.php',
        'app/Filament/Resources/Funds/Tables/FundsTable.php',
    ]);

    foreach ($chains as $chain) {
        expect($chain['chain'])->toContain('->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())');
    }
});

it('preserves deferred fund type filtering, search, pagination and clearing', function (string $page, string $model, string $searchColumn) {
    actingAsFilterDropdownAdmin();

    $type = FundType::factory()->create();
    $model::factory()->count(11)
        ->sequence(fn (Sequence $sequence): array => [$searchColumn => 'Reserva selecionada '.$sequence->index])
        ->create(['fund_type_id' => $type->id]);
    $other = $model::factory()->create([$searchColumn => 'Reserva de outro tipo']);

    $component = Livewire::test($page)
        ->assertCountTableRecords(12)
        ->set('tableDeferredFilters.fund_type_id.value', $type->id)
        ->assertCountTableRecords(12)
        ->call('applyTableFilters')
        ->assertCountTableRecords(11)
        ->assertCanNotSeeTableRecords([$other])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->count())->toBe(1)
        ->and($component->instance()->getTableRecords()->first()->fund_type_id)->toBe($type->id);

    $component->searchTable('Reserva selecionada')->assertCountTableRecords(11)
        ->searchTable('inexistente')->assertCountTableRecords(0)
        ->searchTable('')->call('resetTableFiltersForm')->assertCountTableRecords(12);
})->with([
    'fundos' => [ListFunds::class, Fund::class, 'trade_name'],
    'nomes de fundo' => [ListFundNames::class, FundName::class, 'name'],
]);

it('combines the fund type filter with the existing operation filter', function () {
    actingAsFilterDropdownAdmin();

    $type = FundType::factory()->create();
    $selected = Fund::factory()->create(['fund_type_id' => $type->id]);
    $otherOperation = Fund::factory()->create(['fund_type_id' => $type->id]);
    $otherType = Fund::factory()->create(['emission_id' => $selected->emission_id]);

    Livewire::test(ListFunds::class)
        ->filterTable('fund_type_id', $type->id)
        ->assertCanSeeTableRecords([$selected, $otherOperation])
        ->assertCanNotSeeTableRecords([$otherType])
        ->filterTable('emission_id', $selected->emission_id)
        ->assertCanSeeTableRecords([$selected])
        ->assertCanNotSeeTableRecords([$otherType, $otherOperation])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$selected, $otherType, $otherOperation]);
});

it('anchors the Aplicação filter without changing relationship options or search', function () {
    actingAsFilterDropdownAdmin();

    $applicationField = fn (): ?Select => collect(Livewire::test(ListFunds::class)->assertTableFilterExists('fund_application_id')->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($field): bool => $field instanceof Select && $field->getStatePath() === 'tableDeferredFilters.fund_application_id.value');

    // Sem aplicações cadastradas o campo resolve zero opções: é o empty state "Nenhuma opção
    // disponível.", que permanece compacto pelo teto do dropdown compartilhado.
    $emptyField = $applicationField();

    expect($emptyField)->toBeInstanceOf(Select::class)
        ->and($emptyField->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($emptyField->getOptions())->toBe([])
        ->and($emptyField->getSearchResults('CDB'))->toBe([]);

    $shortApplication = FundApplication::factory()->create(['name' => 'CDB']);
    $longApplication = FundApplication::factory()->create(['name' => 'Aplicação automática em fundo de renda fixa referenciado DI com resgate diário']);

    $field = $applicationField();

    // O nome longo resolve inteiro nas opções; a quebra acontece só no CSS do popup.
    expect($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getRelationshipName())->toBe('fundApplication')
        ->and($field->getRelationshipTitleAttribute())->toBe('name')
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isPreloaded())->toBeTrue()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('Todos')
        ->and($field->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($field->getOptions())->toBe([
            $longApplication->id => $longApplication->name,
            $shortApplication->id => $shortApplication->name,
        ])
        ->and($field->getSearchResults('resgate'))->toBe([$longApplication->id => $longApplication->name])
        ->and($field->getSearchResults('inexistente'))->toBe([]);
});

it('wires the only Aplicação SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Aplicação');

    // Hoje o único filtro com esse rótulo é o da listagem de fundos. A contagem é o contrato:
    // um filtro "Aplicação" novo só passa aqui depois de aderir.
    expect($chains)->toHaveCount(1)
        ->and($chains[0]['path'])->toBe('app/Filament/Resources/Funds/Tables/FundsTable.php')
        ->and($chains[0]['chain'])->toContain('->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())');
});

it('preserves the deferred Aplicação filter alone and combined with Tipo de fundo', function () {
    actingAsFilterDropdownAdmin();

    $cdb = FundApplication::factory()->create(['name' => 'CDB']);
    $lci = FundApplication::factory()->create(['name' => 'LCI']);
    $credit = FundType::factory()->create(['name' => 'Crédito']);
    $reserve = FundType::factory()->create(['name' => 'Reserva']);

    $cdbCredit = Fund::factory()->count(11)
        ->sequence(fn (Sequence $sequence): array => ['trade_name' => 'Fundo CDB crédito '.$sequence->index])
        ->create(['fund_application_id' => $cdb->id, 'fund_type_id' => $credit->id]);
    $cdbReserve = Fund::factory()->create(['fund_application_id' => $cdb->id, 'fund_type_id' => $reserve->id, 'trade_name' => 'Fundo CDB reserva']);
    $lciCredit = Fund::factory()->create(['fund_application_id' => $lci->id, 'fund_type_id' => $credit->id, 'trade_name' => 'Fundo LCI crédito']);

    // Aplicação isolada: nada muda até "Aplicar filtros"; depois, paginação e busca sobre o recorte.
    $component = Livewire::test(ListFunds::class)
        ->assertCountTableRecords(13)
        ->set('tableDeferredFilters.fund_application_id.value', $cdb->id)
        ->assertCountTableRecords(13)
        ->call('applyTableFilters')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$lciCredit])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->pluck('fund_application_id')->unique()->all())->toBe([$cdb->id]);

    $component->searchTable('Fundo LCI')->assertCountTableRecords(0)
        ->searchTable('Fundo CDB reserva')->assertCountTableRecords(1)
        ->searchTable('');

    // Tipo de fundo + Aplicação, e troca do Tipo de fundo sem mexer na Aplicação escolhida.
    $component
        ->set('tableDeferredFilters.fund_type_id.value', $reserve->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$cdbReserve])
        ->set('tableDeferredFilters.fund_type_id.value', $credit->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(11)
        ->assertCanSeeTableRecords($cdbCredit)
        ->assertCanNotSeeTableRecords([$cdbReserve, $lciCredit])
        ->assertSet('tableFilters.fund_application_id.value', $cdb->id);

    // Limpar só a Aplicação mantém o Tipo de fundo; limpar tudo devolve a listagem inteira.
    $component
        ->call('removeTableFilter', 'fund_application_id')
        ->assertSet('tableFilters.fund_application_id.value', null)
        ->assertSet('tableFilters.fund_type_id.value', $credit->id)
        ->assertCountTableRecords(12)
        ->assertCanSeeTableRecords([$lciCredit])
        ->assertCanNotSeeTableRecords([$cdbReserve])
        ->call('resetTableFiltersForm')
        ->assertCountTableRecords(13);
});

it('anchors the Prestador filter without changing relationship options or search', function () {
    actingAsFilterDropdownAdmin();

    $providerField = fn (): ?Select => collect(Livewire::test(ListExpenses::class)->assertTableFilterExists('expense_service_provider_id')->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($field): bool => $field instanceof Select && $field->getStatePath() === 'tableDeferredFilters.expense_service_provider_id.value');

    // Sem prestadores cadastrados o campo resolve zero opções: é o empty state "Nenhuma opção
    // disponível.", que permanece compacto pelo teto do dropdown compartilhado.
    $emptyField = $providerField();

    expect($emptyField)->toBeInstanceOf(Select::class)
        ->and($emptyField->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($emptyField->getOptions())->toBe([])
        ->and($emptyField->getSearchResults('Bellevue'))->toBe([]);

    $shortProvider = ExpenseServiceProvider::factory()->create(['name' => 'Balestero-Gil']);
    $longProvider = ExpenseServiceProvider::factory()->create(['name' => 'ALTO BELLEVUE EMPREENDIMENTOS IMOBILIARIOS SPE LTDA']);

    $field = $providerField();

    // O nome longo resolve inteiro nas opções; a quebra acontece só no CSS do popup.
    expect($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getLabel())->toBe('Prestador')
        ->and($field->getRelationshipName())->toBe('serviceProvider')
        ->and($field->getRelationshipTitleAttribute())->toBe('name')
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isPreloaded())->toBeTrue()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('Todos')
        ->and($field->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($field->getOptions())->toBe([
            $longProvider->id => $longProvider->name,
            $shortProvider->id => $shortProvider->name,
        ])
        ->and($field->getSearchResults('Bellevue'))->toBe([$longProvider->id => $longProvider->name])
        ->and($field->getSearchResults('inexistente'))->toBe([]);
});

it('wires the only Prestador SelectFilter in the panel to the shared dropdown and leaves Categoria native', function () {
    $chains = selectFilterChainsLabeled('Prestador');
    $category = collect(selectFilterChainsLabeled('Categoria'))
        ->where('path', 'app/Filament/Resources/Expenses/Tables/ExpensesTable.php')
        ->values()
        ->all();

    // Hoje o único filtro com esse rótulo é o da listagem de despesas. A contagem é o contrato:
    // um filtro "Prestador" novo só passa aqui depois de aderir. O vizinho "Categoria" é
    // `<select>` nativo (sem pesquisa), nunca sofreu o defeito e continua fora.
    expect($chains)->toHaveCount(1)
        ->and($chains[0]['path'])->toBe('app/Filament/Resources/Expenses/Tables/ExpensesTable.php')
        ->and($chains[0]['chain'])->toContain('->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())')
        ->and($category)->toHaveCount(1)
        ->and($category[0]['chain'])
        ->not->toContain('AnchoredFilterDropdown')
        ->not->toContain('->searchable()');
});

it('preserves the deferred Prestador filter alone and combined with Categoria', function () {
    actingAsFilterDropdownAdmin();

    $selected = ExpenseServiceProvider::factory()->create(['name' => 'Balestero-Gil']);
    $other = ExpenseServiceProvider::factory()->create(['name' => 'BANCO PAULISTA S.A.']);

    Expense::factory()->count(11)->create(['expense_service_provider_id' => $selected->id, 'category' => 'Auditoria']);
    $selectedRegistry = Expense::factory()->create(['expense_service_provider_id' => $selected->id, 'category' => 'Cartório']);
    $otherAudit = Expense::factory()->create(['expense_service_provider_id' => $other->id, 'category' => 'Auditoria']);

    // Prestador isolado: nada muda até "Aplicar filtros"; depois, paginação sobre o recorte.
    $component = Livewire::test(ListExpenses::class)
        ->assertCountTableRecords(13)
        ->set('tableDeferredFilters.expense_service_provider_id.value', $selected->id)
        ->assertCountTableRecords(13)
        ->call('applyTableFilters')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$otherAudit])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->pluck('expense_service_provider_id')->unique()->all())->toBe([$selected->id]);

    // Prestador + Categoria, troca da Categoria mantendo o Prestador e troca do Prestador
    // mantendo a Categoria.
    $component
        ->set('tableDeferredFilters.category.value', 'Cartório')
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$selectedRegistry])
        ->set('tableDeferredFilters.category.value', 'Auditoria')
        ->call('applyTableFilters')
        ->assertCountTableRecords(11)
        ->assertCanNotSeeTableRecords([$selectedRegistry, $otherAudit])
        ->assertSet('tableFilters.expense_service_provider_id.value', $selected->id)
        ->set('tableDeferredFilters.expense_service_provider_id.value', $other->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$otherAudit])
        ->assertSet('tableFilters.category.value', 'Auditoria');

    // Limpar só o Prestador mantém a Categoria; limpar tudo devolve a listagem inteira.
    $component
        ->call('removeTableFilter', 'expense_service_provider_id')
        ->assertSet('tableFilters.expense_service_provider_id.value', null)
        ->assertSet('tableFilters.category.value', 'Auditoria')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$selectedRegistry])
        ->call('resetTableFiltersForm')
        ->assertCountTableRecords(13);
});

it('anchors the Categoria filter of the general documents library without changing relationship options or search', function () {
    actingAsFilterDropdownAdmin();

    $categoryField = fn (): ?Select => collect(Livewire::test(ListGeneralDocuments::class)->assertTableFilterExists('nimbus_category_id')->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($field): bool => $field instanceof Select && $field->getStatePath() === 'tableDeferredFilters.nimbus_category_id.value');

    // Sem categorias cadastradas o campo resolve zero opções: é o empty state "Nenhuma opção
    // disponível.", que permanece compacto pelo teto do dropdown compartilhado.
    $emptyField = $categoryField();

    expect($emptyField)->toBeInstanceOf(Select::class)
        ->and($emptyField->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($emptyField->getOptions())->toBe([])
        ->and($emptyField->getSearchResults('Contratos'))->toBe([]);

    $shortCategory = DocumentCategory::query()->create(['name' => 'Contratos']);

    expect($categoryField()->getOptions())->toBe([$shortCategory->id => 'Contratos']);

    $longCategory = DocumentCategory::query()->create(['name' => 'Categoria institucional de documentos regulatórios e societários da companhia securitizadora']);

    $field = $categoryField();

    // O nome longo resolve inteiro nas opções; a quebra acontece só no CSS do popup.
    expect($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getLabel())->toBe('Categoria')
        ->and($field->getRelationshipName())->toBe('category')
        ->and($field->getRelationshipTitleAttribute())->toBe('name')
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isPreloaded())->toBeTrue()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('Todos')
        ->and($field->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($field->getOptions())->toBe([
            $longCategory->id => $longCategory->name,
            $shortCategory->id => $shortCategory->name,
        ])
        ->and($field->getSearchResults('societários'))->toBe([$longCategory->id => $longCategory->name])
        ->and($field->getSearchResults('inexistente'))->toBe([]);
});

it('anchors the Categoria filter of the documents library without changing its static options', function () {
    actingAsFilterDropdownAdmin();

    $field = collect(Livewire::test(ListDocuments::class)->assertTableFilterExists('category')->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($field): bool => $field instanceof Select && $field->getStatePath() === 'tableDeferredFilters.category.value');

    // Opções estáticas: a pesquisa acontece no navegador, sobre as mesmas opções de sempre.
    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getLabel())->toBe('Categoria')
        ->and($field->getOptions())->toBe(Document::CATEGORY_OPTIONS)
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isPreloaded())->toBeTrue()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getSearchPrompt())->toBe('Comece a digitar para pesquisar...');
});

it('wires exactly the Categoria SelectFilters rendered by the JS select to the shared dropdown', function () {
    $chains = collect(selectFilterChainsLabeled('Categoria'));

    $rendersJsSelect = fn (array $chain): bool => str_contains($chain['chain'], '->searchable()')
        || str_contains($chain['chain'], '->native(false)')
        || str_contains($chain['chain'], '->multiple()');
    $isWired = fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())');

    // Documentos e Biblioteca Geral renderizam o select JS e aderem; o de Despesas é `<select>`
    // nativo, cujo popup é do navegador e nunca foi 100vw. A contagem total é o contrato para
    // um filtro "Categoria" novo.
    expect($chains)->toHaveCount(3)
        ->and($chains->filter($rendersJsSelect)->pluck('path')->sort()->values()->all())
        ->toBe([
            'app/Filament/Resources/Documents/Tables/DocumentsTable.php',
            'app/Filament/Resources/Nimbus/GeneralDocuments/Tables/GeneralDocumentsTable.php',
        ])
        ->and($chains->filter($rendersJsSelect)->reject($isWired)->all())->toBe([])
        ->and($chains->reject($rendersJsSelect)->filter($isWired)->all())->toBe([]);
});

it('preserves the deferred Categoria filter alone and combined with Status', function () {
    actingAsFilterDropdownAdmin();

    $contracts = DocumentCategory::query()->create(['name' => 'Contratos']);
    $regulations = DocumentCategory::query()->create(['name' => 'Regulamentos']);

    $generalDocument = fn (DocumentCategory $category, string $title, bool $isActive): GeneralDocument => GeneralDocument::query()->create([
        'nimbus_category_id' => $category->id,
        'title' => $title,
        'file_path' => 'nimbus/general-documents/'.str($title)->slug().'.pdf',
        'file_original_name' => str($title)->slug().'.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'is_active' => $isActive,
    ]);

    $activeContracts = collect(range(1, 11))->map(fn (int $index): GeneralDocument => $generalDocument($contracts, "Contrato ativo {$index}", true));
    $inactiveContract = $generalDocument($contracts, 'Contrato inativo', false);
    $inactiveRegulation = $generalDocument($regulations, 'Regulamento inativo', false);

    // Categoria isolada: nada muda até "Aplicar filtros"; depois, paginação e busca sobre o recorte.
    $component = Livewire::test(ListGeneralDocuments::class)
        ->assertCountTableRecords(13)
        ->set('tableDeferredFilters.nimbus_category_id.value', $contracts->id)
        ->assertCountTableRecords(13)
        ->call('applyTableFilters')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$inactiveRegulation])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->pluck('nimbus_category_id')->unique()->all())->toBe([$contracts->id]);

    $component->searchTable('Regulamento')->assertCountTableRecords(0)
        ->searchTable('Contrato inativo')->assertCountTableRecords(1)
        ->searchTable('');

    // Categoria + Status, troca do Status mantendo a Categoria e troca da Categoria mantendo o Status.
    $component
        ->set('tableDeferredFilters.is_active.value', '0')
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$inactiveContract])
        ->set('tableDeferredFilters.is_active.value', '1')
        ->call('applyTableFilters')
        ->assertCountTableRecords(11)
        ->assertCanSeeTableRecords($activeContracts)
        ->assertSet('tableFilters.nimbus_category_id.value', $contracts->id)
        ->set('tableDeferredFilters.is_active.value', '0')
        ->set('tableDeferredFilters.nimbus_category_id.value', $regulations->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$inactiveRegulation])
        ->assertSet('tableFilters.is_active.value', '0');

    // Limpar só a Categoria mantém o Status; limpar tudo devolve a listagem inteira.
    $component
        ->call('removeTableFilter', 'nimbus_category_id')
        ->assertSet('tableFilters.nimbus_category_id.value', null)
        ->assertSet('tableFilters.is_active.value', '0')
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$inactiveContract, $inactiveRegulation])
        ->call('resetTableFiltersForm')
        ->assertCountTableRecords(13);
});

it('leaves the Categoria registration fields out of the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    // O mesmo rótulo existe nos cadastros (documentos, biblioteca geral, despesas, notas e
    // obrigações); a correção é só de filtro.
    expect($source)
        ->toContain("->label('Categoria')")
        ->not->toContain('AnchoredFilterDropdown');
})->with([
    'app/Filament/Resources/Documents/Schemas/DocumentForm.php',
    'app/Filament/Resources/Documents/Pages/BatchCreateDocuments.php',
    'app/Filament/Resources/Nimbus/GeneralDocuments/Schemas/GeneralDocumentForm.php',
    'app/Filament/Resources/Expenses/Schemas/ExpenseForm.php',
    'app/Filament/Resources/EmissionMonthlyReportNotes/Schemas/EmissionMonthlyReportNoteForm.php',
    'app/Filament/Resources/Emissions/Schemas/ObligationFormFields.php',
    'app/Filament/Resources/Emissions/Schemas/ObligationSeriesFormFields.php',
]);

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

it('anchors the dropdown of every Série filter to its trigger', function (string $screen, string $filterName) {
    actingAsFilterDropdownAdmin();

    $emission = Emission::factory()->create(['name' => 'CRI Alto Bellevue 1ª Série']);
    ObligationSeries::factory()->for($emission)->create(['title' => 'Relatório mensal do agente fiduciário']);

    $filter = seriesFilterTable($screen, $emission)
        ->assertTableFilterExists($filterName)
        ->instance()
        ->getTable()
        ->getFilter($filterName);

    $field = $filter->getSchemaComponents()[0];

    expect($filter->getLabel())->toBe('Série')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
})->with(seriesFilterScreens());

it('wires every Série SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Série');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // A contagem é o contrato: um filtro "Série" novo só passa aqui depois de aderir.
    expect($chains)->toHaveCount(count(seriesFilterScreens()))
        ->and($unwired)->toBe([]);
});

it('keeps the obligation Série filters listing and applying the same series', function (string $screen) {
    actingAsFilterDropdownAdmin();

    $emission = Emission::factory()->create();
    $monthly = ObligationSeries::factory()->for($emission)->create(['title' => 'Relatório mensal do agente fiduciário']);
    $quarterly = ObligationSeries::factory()->for($emission)->create(['title' => 'Demonstrações financeiras trimestrais']);
    $fromMonthly = Obligation::factory()->for($emission)->create(['obligation_series_id' => $monthly->id, 'status' => 'a_vencer', 'due_date' => now()->addDays(5)]);
    $fromQuarterly = Obligation::factory()->for($emission)->create(['obligation_series_id' => $quarterly->id, 'status' => 'a_vencer', 'due_date' => now()->addDays(5)]);

    $component = seriesFilterTable($screen, $emission);

    // As opções de um `relationship()` só resolvem com o campo montado no formulário de filtros.
    $field = collect($component->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && str_ends_with($component->getStatePath(), '.obligation_series_id.value'));

    expect($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getOptions())->toBe([
            $quarterly->id => 'Demonstrações financeiras trimestrais',
            $monthly->id => 'Relatório mensal do agente fiduciário',
        ]);

    $component
        ->filterTable('obligation_series_id', $monthly->id)
        ->assertCanSeeTableRecords([$fromMonthly])
        ->assertCanNotSeeTableRecords([$fromQuarterly])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$fromMonthly, $fromQuarterly]);
})->with([
    'fila operacional do painel de obrigações' => 'obligation-dashboard',
    'obrigações da emissão' => 'emission-obligations',
]);

it('anchors the dropdown of the Obrigação filter to its trigger', function () {
    actingAsFilterDropdownAdmin();

    $emission = Emission::factory()->create();
    Obligation::factory()->for($emission)->create(['title' => 'Comunicar aos Titulares dos CRI inadimplemento de obrigações pela Emissora']);

    $filter = obligationEvidencesTable($emission)
        ->assertTableFilterExists('obligation_id')
        ->instance()
        ->getTable()
        ->getFilter('obligation_id');

    $field = $filter->getSchemaComponents()[0];

    expect($filter->getLabel())->toBe('Obrigação')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
});

it('wires every Obrigação SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Obrigação');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // Hoje o único filtro com esse rótulo é o da aba de evidências da Emissão. A contagem é o
    // contrato: um filtro "Obrigação" novo só passa aqui depois de aderir.
    expect($chains)->toHaveCount(1)
        ->and($chains[0]['path'])->toBe('app/Filament/Resources/Emissions/EmissionResource/RelationManagers/ObligationEvidencesRelationManager.php')
        ->and($unwired)->toBe([]);
});

it('keeps the Obrigação filter listing and applying the same obligations', function () {
    actingAsFilterDropdownAdmin();

    $emission = Emission::factory()->create();
    $otherEmission = Emission::factory()->create();
    $communication = Obligation::factory()->for($emission)->create(['title' => 'Comunicar aos Titulares dos CRI inadimplemento de obrigações pela Emissora']);
    $application = Obligation::factory()->for($emission)->create(['title' => 'Aplicação integral dos recursos nas obras do Empreendimento Alto Bellevue']);
    Obligation::factory()->for($otherEmission)->create(['title' => 'Obrigação de outra emissão']);
    $communicationEvidence = ObligationEvidence::factory()->for($communication)->for($emission)->create();
    $applicationEvidence = ObligationEvidence::factory()->for($application)->for($emission)->create();

    $component = obligationEvidencesTable($emission);

    // As opções estáticas só resolvem com o campo montado no formulário de filtros.
    $field = collect($component->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && str_ends_with($component->getStatePath(), '.obligation_id.value'));

    expect($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getOptions())->toBe([
            $application->id => 'Aplicação integral dos recursos nas obras do Empreendimento Alto Bellevue',
            $communication->id => 'Comunicar aos Titulares dos CRI inadimplemento de obrigações pela Emissora',
        ]);

    $component
        ->filterTable('obligation_id', $communication->id)
        ->assertCanSeeTableRecords([$communicationEvidence])
        ->assertCanNotSeeTableRecords([$applicationEvidence])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$communicationEvidence, $applicationEvidence]);
});

it('anchors the dropdown of the Solicitante filter to its trigger', function (string $page, string $filterName) {
    actingAsFilterDropdownAdmin();

    $component = Livewire::test($page)
        ->assertTableFilterExists($filterName)
        ->assertSee(AnchoredFilterDropdown::DROPDOWN_CLASS, escape: false);

    $filter = $component->instance()->getTable()->getFilter($filterName);

    $field = $filter->getSchemaComponents()[0];

    expect($filter->getLabel())->toBe('Solicitante')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
})->with(solicitanteFilterPages());

it('wires every Solicitante SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Solicitante');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // Hoje o único filtro com esse rótulo é o da listagem de envios e solicitações. A contagem é o
    // contrato: um filtro "Solicitante" novo só passa aqui depois de aderir.
    expect($chains)->toHaveCount(count(solicitanteFilterPages()))
        ->and($chains[0]['path'])->toBe('app/Filament/Resources/Nimbus/Submissions/Tables/SubmissionsTable.php')
        ->and($unwired)->toBe([]);
});

it('keeps the Solicitante filter listing, searching and applying the same portal users', function () {
    actingAsFilterDropdownAdmin();

    // Sem solicitantes cadastrados o campo resolve zero opções: é o cenário do empty state
    // "Nenhuma opção disponível.", que permanece compacto pelo teto do dropdown compartilhado.
    $emptyField = collect(Livewire::test(ListSubmissions::class)->assertTableFilterExists('nimbus_portal_user_id')->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && str_ends_with($component->getStatePath(), '.nimbus_portal_user_id.value'));

    expect($emptyField)->toBeInstanceOf(Select::class)
        ->and($emptyField->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($emptyField->getRelationshipName())->toBe('portalUser')
        ->and($emptyField->getRelationshipTitleAttribute())->toBe('full_name')
        ->and($emptyField->isSearchable())->toBeTrue()
        ->and($emptyField->isPreloaded())->toBeTrue()
        ->and($emptyField->isMultiple())->toBeFalse()
        ->and($emptyField->getPlaceholder())->toBe('Todos')
        ->and($emptyField->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($emptyField->getOptions())->toBe([])
        ->and($emptyField->getSearchResults('Anderson'))->toBe([]);

    $anderson = PortalUser::query()->create([
        'full_name' => 'Anderson Cavalcante',
        'email' => 'anderson.cavalcante@example.com',
        'status' => 'ACTIVE',
    ]);
    $longName = PortalUser::query()->create([
        'full_name' => 'Maria da Conceição Albuquerque Cavalcante de Souza Santos',
        'email' => 'maria.souza@example.com',
        'status' => 'ACTIVE',
    ]);

    $first = Submission::query()->create([
        'nimbus_portal_user_id' => $anderson->id,
        'reference_code' => 'NMB-2026-0001',
        'title' => 'Cadastro inicial',
        'status' => Submission::STATUS_PENDING,
    ]);
    $second = Submission::query()->create([
        'nimbus_portal_user_id' => $anderson->id,
        'reference_code' => 'NMB-2026-0002',
        'title' => 'Atualização cadastral',
        'status' => Submission::STATUS_UNDER_REVIEW,
    ]);
    $other = Submission::query()->create([
        'nimbus_portal_user_id' => $longName->id,
        'reference_code' => 'NMB-2026-0003',
        'title' => 'Cadastro inicial',
        'status' => Submission::STATUS_PENDING,
    ]);

    $component = Livewire::test(ListSubmissions::class)->assertTableFilterExists('nimbus_portal_user_id');

    $field = collect($component->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && str_ends_with($component->getStatePath(), '.nimbus_portal_user_id.value'));

    // O nome longo resolve inteiro nas opções; a quebra acontece só no CSS do popup.
    expect($field->getOptions())->toBe([
        $anderson->id => 'Anderson Cavalcante',
        $longName->id => 'Maria da Conceição Albuquerque Cavalcante de Souza Santos',
    ])
        ->and($field->getSearchResults('Cavalcante'))->toBe([
            $anderson->id => 'Anderson Cavalcante',
            $longName->id => 'Maria da Conceição Albuquerque Cavalcante de Souza Santos',
        ])
        ->and($field->getSearchResults('Anderson'))->toBe([$anderson->id => 'Anderson Cavalcante'])
        ->and($field->getSearchResults('inexistente'))->toBe([]);

    $component
        ->assertCountTableRecords(3)
        ->filterTable('nimbus_portal_user_id', $anderson->id)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('status', Submission::STATUS_PENDING)
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second, $other])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$first, $second, $other]);
});

it('leaves the Solicitante display fields out of the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    // O mesmo rótulo existe nas colunas e no infolist; a correção é só do filtro.
    expect($source)
        ->toContain("->label('Solicitante')")
        ->not->toContain('AnchoredFilterDropdown');
})->with([
    'app/Filament/NimbusWidgets/NimbusRecentSubmissions.php',
    'app/Filament/Resources/Nimbus/Submissions/SubmissionResource.php',
]);

/**
 * O `Select` montado pelo filtro `$filterName` da listagem de documentos por usuário.
 */
function portalDocumentsFilterField(Testable $component, string $filterName): Select
{
    return collect($component->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($field): bool => $field instanceof Select && str_ends_with($field->getStatePath(), ".{$filterName}.value"));
}

/**
 * Documento de usuário do portal com arquivo fictício, só para compor a listagem.
 */
function portalDocumentFor(PortalUser $portalUser, User $uploader, string $title): PortalDocument
{
    return PortalDocument::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'title' => $title,
        'file_path' => 'private/portal-documents/'.str($title)->slug().'.pdf',
        'file_original_name' => str($title)->slug().'.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'created_by_user_id' => $uploader->id,
    ]);
}

it('anchors the dropdown of the Usuário do Portal filter to its trigger', function () {
    actingAsFilterDropdownAdmin();

    $component = Livewire::test(ListPortalDocuments::class)
        ->assertTableFilterExists('nimbus_portal_user_id')
        ->assertSee(AnchoredFilterDropdown::DROPDOWN_CLASS, escape: false);

    $filter = $component->instance()->getTable()->getFilter('nimbus_portal_user_id');

    expect($filter->getLabel())->toBe('Usuário do Portal')
        ->and($filter->getSearchable())->toBeTrue()
        ->and($filter->getSchemaComponents()[0])->toBeInstanceOf(Select::class)
        ->and($filter->getSchemaComponents()[0]->getExtraAttributes())
        ->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);
});

it('wires every Usuário do Portal SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Usuário do Portal');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // Hoje o único filtro com esse rótulo é o da listagem de documentos por usuário; os demais
    // "Usuário do Portal" são coluna, infolist ou campo de cadastro. A contagem é o contrato: um
    // filtro novo com esse rótulo só passa aqui depois de aderir.
    expect($chains)->toHaveCount(1)
        ->and($chains[0]['path'])->toBe('app/Filament/Resources/Nimbus/PortalDocuments/Tables/PortalDocumentsTable.php')
        ->and($unwired)->toBe([]);
});

it('keeps the Usuário do Portal filter listing, searching and applying the same portal users', function () {
    actingAsFilterDropdownAdmin();

    // Sem usuários do portal o campo resolve zero opções: é o cenário do empty state
    // "Nenhuma opção disponível.", que permanece compacto pelo teto do dropdown compartilhado.
    $emptyField = portalDocumentsFilterField(Livewire::test(ListPortalDocuments::class), 'nimbus_portal_user_id');

    expect($emptyField->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($emptyField->getRelationshipName())->toBe('portalUser')
        ->and($emptyField->getRelationshipTitleAttribute())->toBe('full_name')
        ->and($emptyField->isSearchable())->toBeTrue()
        ->and($emptyField->isPreloaded())->toBeTrue()
        ->and($emptyField->isMultiple())->toBeFalse()
        ->and($emptyField->getPlaceholder())->toBe('Todos')
        ->and($emptyField->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($emptyField->getOptions())->toBe([])
        ->and($emptyField->getSearchResults('Anderson'))->toBe([]);

    $uploader = auth()->user();
    $otherUploader = User::factory()->create(['name' => 'Beatriz Operações']);

    $anderson = PortalUser::query()->create([
        'full_name' => 'Anderson Cavalcante',
        'email' => 'anderson.cavalcante@example.com',
        'status' => 'ACTIVE',
    ]);
    $longName = PortalUser::query()->create([
        'full_name' => 'Maria da Conceição Albuquerque Cavalcante de Souza Santos',
        'email' => 'maria.da.conceicao.albuquerque.cavalcante.de.souza.santos@investidores.example.com',
        'status' => 'ACTIVE',
    ]);

    collect(range(1, 11))->each(fn (int $month): PortalDocument => portalDocumentFor($anderson, $uploader, "Informe mensal {$month}"));
    $andersonContract = portalDocumentFor($anderson, $otherUploader, 'Contrato social Anderson');
    $longNameReport = portalDocumentFor($longName, $uploader, 'Informe mensal Maria');
    $longNameContract = portalDocumentFor($longName, $otherUploader, 'Contrato social Maria');

    $component = Livewire::test(ListPortalDocuments::class)->assertTableFilterExists('nimbus_portal_user_id');

    // O nome longo resolve inteiro nas opções; a quebra acontece só no CSS do popup. O e-mail
    // não faz parte do rótulo do filtro.
    expect(portalDocumentsFilterField($component, 'nimbus_portal_user_id')->getOptions())->toBe([
        $anderson->id => 'Anderson Cavalcante',
        $longName->id => 'Maria da Conceição Albuquerque Cavalcante de Souza Santos',
    ])
        ->and(portalDocumentsFilterField($component, 'nimbus_portal_user_id')->getSearchResults('Cavalcante'))->toBe([
            $anderson->id => 'Anderson Cavalcante',
            $longName->id => 'Maria da Conceição Albuquerque Cavalcante de Souza Santos',
        ])
        ->and(portalDocumentsFilterField($component, 'nimbus_portal_user_id')->getSearchResults('Anderson'))->toBe([$anderson->id => 'Anderson Cavalcante'])
        ->and(portalDocumentsFilterField($component, 'nimbus_portal_user_id')->getSearchResults('inexistente'))->toBe([])
        ->and(portalDocumentsFilterField($component, 'created_by_user_id')->getOptions())
        ->toMatchArray([$uploader->id => $uploader->name, $otherUploader->id => 'Beatriz Operações']);

    // Usuário do Portal isolado, aplicado pelo botão "Aplicar filtros" (filtros adiados), com paginação.
    $component
        ->assertCountTableRecords(14)
        ->set('tableDeferredFilters.nimbus_portal_user_id.value', $anderson->id)
        ->assertCountTableRecords(14)
        ->call('applyTableFilters')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$longNameReport, $longNameContract])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->count())->toBe(2)
        ->and($component->instance()->getTableRecords()->pluck('nimbus_portal_user_id')->unique()->all())->toBe([$anderson->id]);

    // Usuário do Portal + Enviado por, trocando um de cada vez.
    $component
        ->set('tableDeferredFilters.created_by_user_id.value', $otherUploader->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$andersonContract])
        ->assertCountTableRecords(1)
        ->set('tableDeferredFilters.nimbus_portal_user_id.value', $longName->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$longNameContract])
        ->assertCountTableRecords(1)
        ->set('tableDeferredFilters.created_by_user_id.value', $uploader->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$longNameReport])
        ->assertCountTableRecords(1);

    // Enviado por isolado.
    $component
        ->set('tableDeferredFilters.nimbus_portal_user_id.value', null)
        ->set('tableDeferredFilters.created_by_user_id.value', $otherUploader->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$andersonContract, $longNameContract])
        ->assertCountTableRecords(2);

    // Busca com e sem filtros; "Limpar filtros" mantém a busca e limpar a busca mantém os filtros.
    $component
        ->call('resetTableFiltersForm')
        ->searchTable('Contrato social')
        ->assertCanSeeTableRecords([$andersonContract, $longNameContract])
        ->assertCountTableRecords(2)
        ->set('tableDeferredFilters.nimbus_portal_user_id.value', $anderson->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$andersonContract])
        ->assertCountTableRecords(1)
        ->set('tableDeferredFilters.created_by_user_id.value', $otherUploader->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(1)
        ->call('resetTableFiltersForm')
        ->assertSet('tableSearch', 'Contrato social')
        ->assertCountTableRecords(2)
        ->set('tableDeferredFilters.nimbus_portal_user_id.value', $anderson->id)
        ->call('applyTableFilters')
        ->searchTable('')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$longNameReport, $longNameContract])
        ->call('resetTableFiltersForm')
        ->assertCountTableRecords(14);
});

it('leaves the Usuário do Portal display and registration fields out of the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    // O mesmo rótulo existe na coluna e no infolist das chaves de acesso e no cadastro de
    // documento; a correção é só do filtro.
    expect($source)
        ->toContain("->label('Usuário do Portal')")
        ->not->toContain('AnchoredFilterDropdown');
})->with([
    'app/Filament/Resources/Nimbus/AccessTokens/Tables/AccessTokensTable.php',
    'app/Filament/Resources/Nimbus/AccessTokens/AccessTokenResource.php',
    'app/Filament/Resources/Nimbus/PortalDocuments/Schemas/PortalDocumentForm.php',
]);

it('anchors the Enviado por filter without changing relationship options or search', function () {
    actingAsFilterDropdownAdmin();

    $admin = auth()->user();

    $sentByField = fn (): Select => portalDocumentsFilterField(Livewire::test(ListPortalDocuments::class)->assertTableFilterExists('created_by_user_id'), 'created_by_user_id');

    // Só o admin existe: a lista mínima resolve uma opção e a pesquisa sem resultado
    // resolve zero, mantendo o popup compacto.
    $field = $sentByField();

    expect($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getLabel())->toBe('Enviado por')
        ->and($field->getRelationshipName())->toBe('createdBy')
        ->and($field->getRelationshipTitleAttribute())->toBe('name')
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isPreloaded())->toBeTrue()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('Todos')
        ->and($field->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($field->getOptions())->toBe([$admin->id => $admin->name])
        ->and($field->getSearchResults('inexistente'))->toBe([]);

    $anderson = User::factory()->create(['name' => 'Anderson Cavalcante']);
    $longName = User::factory()->create(['name' => 'Roberto Sérgio Henrique Gonçalves']);

    // O nome longo resolve inteiro nas opções; a quebra acontece só no CSS do popup.
    expect($sentByField()->getOptions())->toBe(collect([
        $admin->id => $admin->name,
        $anderson->id => 'Anderson Cavalcante',
        $longName->id => 'Roberto Sérgio Henrique Gonçalves',
    ])->sort()->all())
        ->and($sentByField()->getSearchResults('Cavalcante'))->toBe([$anderson->id => 'Anderson Cavalcante'])
        ->and($sentByField()->getSearchResults('Roberto Sérgio'))->toBe([$longName->id => 'Roberto Sérgio Henrique Gonçalves']);

    // No mesmo painel só os dois selects pesquisáveis aderem; as datas de "Criado a partir de"
    // e "Criado até" ficam fora do bloco compartilhado.
    $fields = collect(Livewire::test(ListPortalDocuments::class)->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true));

    expect($fields->filter(fn ($component): bool => $component instanceof Select)
        ->mapWithKeys(fn (Select $select): array => [$select->getStatePath() => $select->getExtraAttributes()['class'] ?? null])
        ->all())->toBe([
            'tableDeferredFilters.nimbus_portal_user_id.value' => AnchoredFilterDropdown::DROPDOWN_CLASS,
            'tableDeferredFilters.created_by_user_id.value' => AnchoredFilterDropdown::DROPDOWN_CLASS,
        ])
        ->and($fields->filter(fn ($component): bool => $component instanceof DatePicker)
            ->mapWithKeys(fn (DatePicker $date): array => [$date->getLabel() => $date->getExtraAttributes()])
            ->all())->toBe([
                'Criado a partir de' => [],
                'Criado até' => [],
            ]);
});

it('wires the only Enviado por SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Enviado por');

    // Hoje o único filtro com esse rótulo é o da listagem de documentos por usuário; os demais
    // "Enviado por" são coluna e infolist (documentos por usuário e aba Evidências da Emissão),
    // sem popup. A contagem é o contrato: um filtro novo com esse rótulo só passa aqui depois de aderir.
    expect($chains)->toHaveCount(1)
        ->and($chains[0]['path'])->toBe('app/Filament/Resources/Nimbus/PortalDocuments/Tables/PortalDocumentsTable.php')
        ->and($chains[0]['chain'])->toContain('->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())');
});

it('preserves the deferred Enviado por filter alone and combined with Criado a partir de', function () {
    actingAsFilterDropdownAdmin();

    $admin = auth()->user();
    $roberto = User::factory()->create(['name' => 'Roberto Sérgio Henrique Gonçalves']);

    $anderson = PortalUser::query()->create([
        'full_name' => 'Anderson Cavalcante',
        'email' => 'anderson.cavalcante@example.com',
        'status' => 'ACTIVE',
    ]);

    $sentAt = fn (PortalDocument $document, string $createdAt): PortalDocument => tap($document)->update(['created_at' => $createdAt]);

    collect(range(1, 11))->each(fn (int $index): PortalDocument => $sentAt(portalDocumentFor($anderson, $admin, "Informe mensal {$index}"), '2026-03-10 12:00:00'));
    $adminJanuary = $sentAt(portalDocumentFor($anderson, $admin, 'Contrato social admin'), '2026-01-10 12:00:00');
    $robertoMarch = $sentAt(portalDocumentFor($anderson, $roberto, 'Informe mensal Roberto'), '2026-03-12 12:00:00');
    $robertoJanuary = $sentAt(portalDocumentFor($anderson, $roberto, 'Contrato social Roberto'), '2026-01-12 12:00:00');

    // Enviado por isolado: nada muda até "Aplicar filtros"; depois, paginação sobre o recorte.
    $component = Livewire::test(ListPortalDocuments::class)
        ->assertCountTableRecords(14)
        ->set('tableDeferredFilters.created_by_user_id.value', $admin->id)
        ->assertCountTableRecords(14)
        ->call('applyTableFilters')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$robertoMarch, $robertoJanuary])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->count())->toBe(2)
        ->and($component->instance()->getTableRecords()->pluck('created_by_user_id')->unique()->all())->toBe([$admin->id]);

    // Criado a partir de isolado.
    $component
        ->call('resetTableFiltersForm')
        ->set('tableDeferredFilters.created_at.created_from', '2026-03-01')
        ->call('applyTableFilters')
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([$adminJanuary, $robertoJanuary]);

    // Enviado por + Criado a partir de, trocando um de cada vez sem perder o outro.
    $component
        ->set('tableDeferredFilters.created_by_user_id.value', $roberto->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$robertoMarch])
        ->assertCountTableRecords(1)
        ->set('tableDeferredFilters.created_by_user_id.value', $admin->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(11)
        ->assertSet('tableFilters.created_at.created_from', '2026-03-01')
        ->set('tableDeferredFilters.created_at.created_from', '2026-01-01')
        ->set('tableDeferredFilters.created_at.created_until', '2026-01-31')
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$adminJanuary])
        ->assertCountTableRecords(1)
        ->assertSet('tableFilters.created_by_user_id.value', $admin->id);

    // Busca com os dois filtros; "Limpar filtros" mantém a busca e limpar a busca devolve tudo.
    $component
        ->call('resetTableFiltersForm')
        ->searchTable('Contrato social')
        ->assertCanSeeTableRecords([$adminJanuary, $robertoJanuary])
        ->assertCountTableRecords(2)
        ->set('tableDeferredFilters.created_by_user_id.value', $roberto->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$robertoJanuary])
        ->assertCountTableRecords(1)
        ->set('tableDeferredFilters.created_at.created_from', '2026-03-01')
        ->call('applyTableFilters')
        ->assertCountTableRecords(0)
        ->call('resetTableFiltersForm')
        ->assertSet('tableSearch', 'Contrato social')
        ->assertCountTableRecords(2)
        ->searchTable('')
        ->assertCountTableRecords(14);

    // Limpar só o Enviado por mantém a data; limpar tudo devolve a listagem inteira.
    $component
        ->set('tableDeferredFilters.created_by_user_id.value', $roberto->id)
        ->set('tableDeferredFilters.created_at.created_from', '2026-03-01')
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$robertoMarch])
        ->assertCountTableRecords(1)
        ->call('removeTableFilter', 'created_by_user_id')
        ->assertSet('tableFilters.created_by_user_id.value', null)
        ->assertSet('tableFilters.created_at.created_from', '2026-03-01')
        ->assertCountTableRecords(12)
        ->call('resetTableFiltersForm')
        ->assertCountTableRecords(14);
});

it('anchors every Criado por filter to its trigger without changing relationship options or search', function (string $page) {
    actingAsFilterDropdownAdmin();

    $admin = auth()->user();

    $createdByField = fn (): ?Select => collect(Livewire::test($page)->assertTableFilterExists('created_by_user_id')->instance()->getTableFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($field): bool => $field instanceof Select && $field->getStatePath() === 'tableDeferredFilters.created_by_user_id.value');

    Livewire::test($page)->assertSee(AnchoredFilterDropdown::DROPDOWN_CLASS, escape: false);

    // Só o admin existe: a lista mínima resolve uma opção e a pesquisa sem resultado
    // resolve zero, mantendo o popup compacto.
    $field = $createdByField();

    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getExtraAttributes())->toMatchArray(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS])
        ->and($field->getLabel())->toBe('Criado por')
        ->and($field->getRelationshipName())->toBe('createdBy')
        ->and($field->getRelationshipTitleAttribute())->toBe('name')
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isPreloaded())->toBeTrue()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('Todos')
        ->and($field->getSearchPrompt())->toBe('Comece a digitar para pesquisar...')
        ->and($field->getOptions())->toBe([$admin->id => $admin->name])
        ->and($field->getSearchResults('inexistente'))->toBe([]);

    $anderson = User::factory()->create(['name' => 'Anderson Cavalcante']);
    $longName = User::factory()->create(['name' => 'Roberto Sérgio Henrique Gonçalves']);

    // O nome longo resolve inteiro nas opções; a quebra acontece só no CSS do popup. As
    // opções vêm ordenadas pelo nome, como todo relacionamento pré-carregado.
    expect($createdByField()->getOptions())->toBe(collect([
        $admin->id => $admin->name,
        $anderson->id => 'Anderson Cavalcante',
        $longName->id => 'Roberto Sérgio Henrique Gonçalves',
    ])->sort()->all())
        ->and($createdByField()->getSearchResults('Cavalcante'))->toBe([$anderson->id => 'Anderson Cavalcante'])
        ->and($createdByField()->getSearchResults('Roberto Sérgio'))->toBe([$longName->id => 'Roberto Sérgio Henrique Gonçalves']);
})->with(createdByFilterPages());

it('wires every Criado por SelectFilter in the panel to the shared dropdown', function () {
    $chains = selectFilterChainsLabeled('Criado por');

    $unwired = collect($chains)
        ->reject(fn (array $chain): bool => str_contains($chain['chain'], '->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())'))
        ->pluck('path')
        ->all();

    // Hoje os únicos filtros com esse rótulo são os da biblioteca geral e dos avisos; as
    // demais ocorrências de "Criado por" são colunas de exibição, sem popup. A contagem é
    // o contrato: um filtro novo com esse rótulo só passa aqui depois de aderir.
    expect($chains)->toHaveCount(2)
        ->and(collect($chains)->pluck('path')->sort()->values()->all())->toBe([
            'app/Filament/Resources/Nimbus/Announcements/Tables/AnnouncementsTable.php',
            'app/Filament/Resources/Nimbus/GeneralDocuments/Tables/GeneralDocumentsTable.php',
        ])
        ->and($unwired)->toBe([]);
});

it('preserves the deferred Criado por filter of the general documents library alone and combined with Categoria', function () {
    actingAsFilterDropdownAdmin();

    $admin = auth()->user();
    $anderson = User::factory()->create(['name' => 'Anderson Cavalcante']);
    $roberto = User::factory()->create(['name' => 'Roberto Sérgio Henrique Gonçalves']);

    $contracts = DocumentCategory::query()->create(['name' => 'Contratos']);
    $regulations = DocumentCategory::query()->create(['name' => 'Regulamentos']);

    $generalDocument = fn (DocumentCategory $category, User $author, string $title): GeneralDocument => GeneralDocument::query()->create([
        'nimbus_category_id' => $category->id,
        'title' => $title,
        'file_path' => 'nimbus/general-documents/'.str($title)->slug().'.pdf',
        'file_original_name' => str($title)->slug().'.pdf',
        'file_size' => 1024,
        'file_mime' => 'application/pdf',
        'is_active' => true,
        'created_by_user_id' => $author->id,
    ]);

    $andersonContracts = collect(range(1, 10))->map(fn (int $index): GeneralDocument => $generalDocument($contracts, $anderson, "Informe mensal {$index}"));
    $andersonRegulation = $generalDocument($regulations, $anderson, 'Informe mensal regulamento');
    $adminContract = $generalDocument($contracts, $admin, 'Contrato social admin');
    $robertoContract = $generalDocument($contracts, $roberto, 'Informe mensal Roberto');

    // Criado por isolado: nada muda até "Aplicar filtros"; depois, paginação sobre o recorte.
    $component = Livewire::test(ListGeneralDocuments::class)
        ->assertCountTableRecords(13)
        ->set('tableDeferredFilters.created_by_user_id.value', $anderson->id)
        ->assertCountTableRecords(13)
        ->call('applyTableFilters')
        ->assertCountTableRecords(11)
        ->assertCanNotSeeTableRecords([$adminContract, $robertoContract])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->count())->toBe(1)
        ->and($component->instance()->getTableRecords()->pluck('created_by_user_id')->unique()->all())->toBe([$anderson->id]);

    // Criado por + Categoria, trocando um de cada vez sem perder o outro.
    $component
        ->set('tableDeferredFilters.nimbus_category_id.value', $regulations->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$andersonRegulation])
        ->assertCountTableRecords(1)
        ->set('tableDeferredFilters.nimbus_category_id.value', $contracts->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(10)
        ->assertCanSeeTableRecords($andersonContracts)
        ->assertSet('tableFilters.created_by_user_id.value', $anderson->id)
        ->set('tableDeferredFilters.created_by_user_id.value', $roberto->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$robertoContract])
        ->assertCountTableRecords(1)
        ->assertSet('tableFilters.nimbus_category_id.value', $contracts->id);

    // Criado por isolado com nome longo.
    $component
        ->set('tableDeferredFilters.nimbus_category_id.value', null)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$robertoContract])
        ->assertCountTableRecords(1);

    // Busca com e sem o filtro; "Limpar filtros" mantém a busca.
    $component
        ->call('resetTableFiltersForm')
        ->searchTable('Informe mensal')
        ->assertCountTableRecords(12)
        ->set('tableDeferredFilters.created_by_user_id.value', $roberto->id)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$robertoContract])
        ->assertCountTableRecords(1)
        ->call('resetTableFiltersForm')
        ->assertSet('tableSearch', 'Informe mensal')
        ->assertCountTableRecords(12)
        ->searchTable('')
        ->assertCountTableRecords(13);

    // Limpar só o Criado por mantém a Categoria; limpar tudo devolve a listagem inteira.
    $component
        ->set('tableDeferredFilters.created_by_user_id.value', $anderson->id)
        ->set('tableDeferredFilters.nimbus_category_id.value', $contracts->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(10)
        ->call('removeTableFilter', 'created_by_user_id')
        ->assertSet('tableFilters.created_by_user_id.value', null)
        ->assertSet('tableFilters.nimbus_category_id.value', $contracts->id)
        ->assertCountTableRecords(12)
        ->call('resetTableFiltersForm')
        ->assertCountTableRecords(13);
});

it('preserves the deferred Criado por filter of the announcements board alone and combined with Publicado', function () {
    actingAsFilterDropdownAdmin();

    $admin = auth()->user();
    $anderson = User::factory()->create(['name' => 'Anderson Cavalcante']);
    $roberto = User::factory()->create(['name' => 'Roberto Sérgio Henrique Gonçalves']);

    $announcement = fn (User $author, string $title, bool $isActive): Announcement => Announcement::query()->create([
        'title' => $title,
        'body' => "Corpo do aviso {$title}.",
        'level' => 'info',
        'is_active' => $isActive,
        'created_by_user_id' => $author->id,
    ]);

    $andersonActive = collect(range(1, 10))->map(fn (int $index): Announcement => $announcement($anderson, "Aviso Anderson {$index}", true));
    $andersonInactive = $announcement($anderson, 'Aviso Anderson arquivado', false);
    $adminInactive = $announcement($admin, 'Aviso admin arquivado', false);
    $robertoActive = $announcement($roberto, 'Aviso Roberto', true);

    // Criado por isolado: nada muda até "Aplicar filtros"; depois, paginação sobre o recorte.
    $component = Livewire::test(ListAnnouncements::class)
        ->assertCountTableRecords(13)
        ->set('tableDeferredFilters.created_by_user_id.value', $anderson->id)
        ->assertCountTableRecords(13)
        ->call('applyTableFilters')
        ->assertCountTableRecords(11)
        ->assertCanNotSeeTableRecords([$adminInactive, $robertoActive])
        ->call('gotoPage', 2);

    expect($component->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($component->instance()->getTableRecords()->count())->toBe(1)
        ->and($component->instance()->getTableRecords()->pluck('created_by_user_id')->unique()->all())->toBe([$anderson->id]);

    // Criado por + Publicado, trocando um de cada vez sem perder o outro.
    $component
        ->set('tableDeferredFilters.is_active.value', '0')
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$andersonInactive])
        ->assertCountTableRecords(1)
        ->set('tableDeferredFilters.is_active.value', '1')
        ->call('applyTableFilters')
        ->assertCountTableRecords(10)
        ->assertCanSeeTableRecords($andersonActive)
        ->assertSet('tableFilters.created_by_user_id.value', $anderson->id)
        ->set('tableDeferredFilters.created_by_user_id.value', $admin->id)
        ->set('tableDeferredFilters.is_active.value', '0')
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$adminInactive])
        ->assertCountTableRecords(1);

    // Busca com e sem o filtro; "Limpar filtros" mantém a busca e limpar tudo devolve a listagem.
    $component
        ->call('resetTableFiltersForm')
        ->searchTable('Aviso Anderson')
        ->assertCountTableRecords(11)
        ->set('tableDeferredFilters.created_by_user_id.value', $roberto->id)
        ->call('applyTableFilters')
        ->assertCountTableRecords(0)
        ->call('resetTableFiltersForm')
        ->assertSet('tableSearch', 'Aviso Anderson')
        ->assertCountTableRecords(11)
        ->searchTable('')
        ->call('resetTableFiltersForm')
        ->assertCountTableRecords(13);
});

it('leaves the Obrigação field of the evidence upload modal out of the shared dropdown', function () {
    actingAsFilterDropdownAdmin();

    $emission = Emission::factory()->create();
    Obligation::factory()->for($emission)->create(['title' => 'Comunicar aos Titulares dos CRI inadimplemento de obrigações pela Emissora']);

    // O mesmo RelationManager declara o filtro e o `Select` "Obrigação" do modal "Anexar evidência";
    // só o filtro adere. O campo é lido do modal montado, com todos os atributos que chegam ao HTML.
    $livewire = obligationEvidencesTable($emission)
        ->mountTableAction('create')
        ->assertTableActionMounted('create')
        ->instance();

    $field = collect($livewire->getSchema($livewire->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'obligation_id');

    expect($field?->getLabel())->toBe('Obrigação')
        ->and($field->isSearchable())->toBeTrue()
        ->and(json_encode([
            $field->getExtraAttributes(),
            $field->getExtraFieldWrapperAttributes(),
            $field->getExtraInputAttributes(),
        ]))->not->toContain(AnchoredFilterDropdown::DROPDOWN_CLASS);
});

it('leaves the Série registration fields out of the shared dropdown', function (string $relativePath) {
    $source = file_get_contents(base_path($relativePath));

    // O mesmo rótulo existe nos cadastros de documento e de emissão; a correção é só de filtro.
    expect($source)
        ->toContain("->label('Série')")
        ->not->toContain('AnchoredFilterDropdown');
})->with([
    'app/Filament/Resources/Documents/Schemas/DocumentForm.php',
    'app/Filament/Resources/Documents/Pages/BatchCreateDocuments.php',
    'app/Filament/Resources/Emissions/Schemas/EmissionForm.php',
]);

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

it('leaves the Aplicação registration field and column out of the shared dropdown', function () {
    $form = file_get_contents(base_path('app/Filament/Resources/Funds/Schemas/FundForm.php'));
    $column = str(file_get_contents(base_path('app/Filament/Resources/Funds/Tables/FundsTable.php')))
        ->after("TextColumn::make('fundApplication.name')")
        ->before('::make(')
        ->toString();

    // O mesmo rótulo existe no cadastro de fundos (com o marcador próprio do formulário) e na
    // coluna da listagem; a correção é só do filtro.
    expect($form)
        ->toContain("->label('Aplicação')")
        ->not->toContain('AnchoredFilterDropdown')
        ->and($column)
        ->toContain("->label('Aplicação')")
        ->not->toContain('AnchoredFilterDropdown');
});

it('leaves the Empresa de medição registration field out of the shared dropdown', function () {
    $source = file_get_contents(base_path('app/Filament/Resources/Constructions/Schemas/ConstructionForm.php'));

    // O mesmo rótulo existe no cadastro de obras; a correção é só de filtro.
    expect($source)
        ->toContain("->label('Empresa de medição')")
        ->not->toContain('AnchoredFilterDropdown');
});

it('keeps the shared dropdown out of registration forms, modals and relation manager forms', function () {
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
        ->reject(fn (string $path): bool => str_contains($path, '/RelationManagers/') && onlyWiresTableFilters(file_get_contents(base_path($path))))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('lets a relation manager adhere only through its table filters', function (string $source, bool $isAccepted) {
    expect(onlyWiresTableFilters($source))->toBe($isAccepted);
})->with([
    'filtro de tabela' => [
        'use App\Filament\Support\AnchoredFilterDropdown; '
            ."SelectFilter::make('obligation_series_id')->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField());",
        true,
    ],
    'campo do formulário' => [
        'use App\Filament\Support\AnchoredFilterDropdown; '
            ."Select::make('obligation_series_id')->extraAttributes(AnchoredFilterDropdown::fieldAttributes());",
        false,
    ],
    'filtro de tabela e campo do formulário' => [
        'use App\Filament\Support\AnchoredFilterDropdown; '
            ."SelectFilter::make('obligation_series_id')->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()); "
            ."Select::make('emission_id')->extraAttributes(AnchoredFilterDropdown::fieldAttributes());",
        false,
    ],
    'classe aplicada à mão' => [
        'use App\Filament\Support\AnchoredFilterDropdown; '
            ."Select::make('emission_id')->extraAttributes(['class' => AnchoredFilterDropdown::DROPDOWN_CLASS]);",
        false,
    ],
    'import com alias' => [
        'use App\Filament\Support\AnchoredFilterDropdown as Anchored; '
            ."Select::make('emission_id')->extraAttributes(Anchored::fieldAttributes());",
        false,
    ],
]);

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
        ->toContain('max-height: min(20rem, 44vh) !important')
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
