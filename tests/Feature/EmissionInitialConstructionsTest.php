<?php

use App\Actions\Emissions\CreateInitialConstructions;
use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Schemas\EmissionConstructionsStep;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use App\Models\SalesBoard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Http::preventStrayRequests();
});

it('places the mandatory constructions step right after the operation data', function () {
    $this->actingAs(makeAdminUser());

    $steps = emissionWizardSteps('create');

    expect($steps->map(fn (Step $step): string => Str::of((string) $step->getLabel())->ascii()->lower()->toString())->all())
        ->toBe([
            'dados basicos',
            'empreendimentos',
            'participantes',
            'caracteristicas financeiras',
            'valores e remuneracao',
            'lastro, garantias e operacao',
            'documentos e informacoes publicas',
            'revisao',
        ])
        ->and($steps[1]->getDescription())->toBe('Obrigatório');
});

it('hides the constructions step when editing an existing emission', function () {
    $this->actingAs(makeAdminUser());

    expect(emissionWizardSteps('edit')->map(fn (Step $step): string => Str::of((string) $step->getLabel())->ascii()->lower()->toString())->all())
        ->not->toContain('empreendimentos');
});

it('lets the user jump between steps only while editing', function () {
    $this->actingAs(makeAdminUser());

    expect(emissionWizard('create')->isSkippable())->toBeFalse()
        ->and(emissionWizard('edit')->isSkippable())->toBeTrue();
});

it('starts the constructions step with one empty construction', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateEmission::class)
        ->assertFormFieldVisible(EmissionConstructionsStep::STATE_PATH)
        ->assertSee('Quadro de Vendas')
        ->assertDontSee('Quadro de Vendas Inicial')
        ->assertSee('Adicionar empreendimento');
});

it('fills the development name from the cnpj and locks the field', function () {
    $this->actingAs(makeAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Aurora Empreendimentos Imobiliários Ltda',
            'estabelecimento' => [
                'nome_fantasia' => 'Residencial Aurora',
            ],
        ]),
    ]);

    $component = Livewire::test(CreateEmission::class);
    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $component
        ->assertFormFieldIsReadOnly($item.'.development_name')
        ->assertSee('Informe o CNPJ do empreendimento para buscar o nome automaticamente.')
        ->fillForm([
            $item.'.development_cnpj' => '12.345.678/0001-90',
        ])
        ->assertFormSet([
            $item.'.development_name' => 'Residencial Aurora',
            $item.'.development_trade_name' => 'Residencial Aurora',
        ])
        ->assertFormFieldIsReadOnly($item.'.development_name')
        ->assertFormFieldIsEnabled($item.'.development_trade_name')
        ->assertSee('Preenchido automaticamente a partir do CNPJ informado.');

    Http::assertSentCount(1);
});

it('falls back to the legal name when the cnpj has no trade name', function () {
    $this->actingAs(makeAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Boreal Incorporações S.A.',
            'estabelecimento' => [
                'nome_fantasia' => '',
            ],
        ]),
    ]);

    $component = Livewire::test(CreateEmission::class);
    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $component
        ->fillForm([
            $item.'.development_trade_name' => 'Fantasia Informada',
            $item.'.development_cnpj' => '12.345.678/0001-90',
        ])
        ->assertFormSet([
            $item.'.development_name' => 'Boreal Incorporações S.A.',
            $item.'.development_trade_name' => 'Fantasia Informada',
        ])
        ->assertFormFieldIsReadOnly($item.'.development_name');
});

it('keeps the development name editable when the lookup returns no name', function () {
    $this->actingAs(makeAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response(status: 404),
    ]);

    $component = Livewire::test(CreateEmission::class);
    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $component
        ->assertFormFieldIsReadOnly($item.'.development_name')
        ->fillForm([$item.'.development_cnpj' => '12.345.678/0001-90'])
        ->assertFormFieldIsEnabled($item.'.development_name')
        ->assertNotified('Não foi possível obter o nome do empreendimento.')
        ->assertSee('A busca não retornou o nome deste CNPJ. Preencha manualmente.')
        ->fillForm([$item.'.development_name' => 'Empreendimento Manual'])
        ->assertFormSet([$item.'.development_name' => 'Empreendimento Manual']);
});

it('locks the development name again when the cnpj changes', function () {
    $this->actingAs(makeAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Aurora Empreendimentos Imobiliários Ltda',
            'estabelecimento' => ['nome_fantasia' => 'Residencial Aurora'],
        ]),
    ]);

    $component = Livewire::test(CreateEmission::class);
    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $component
        ->fillForm([$item.'.development_cnpj' => '12.345.678/0001-90'])
        ->assertFormFieldIsReadOnly($item.'.development_name')
        ->fillForm([$item.'.development_cnpj' => '12.345.678/0001'])
        ->assertFormFieldIsReadOnly($item.'.development_name')
        ->assertFormSet([$item.'.development_name' => 'Residencial Aurora']);
});

it('does not call the cnpj lookup while the cnpj is incomplete', function () {
    $this->actingAs(makeAdminUser());

    Http::fake();

    $component = Livewire::test(CreateEmission::class);
    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $component
        ->fillForm([$item.'.development_cnpj' => '12.345.678/00'])
        ->assertFormFieldIsReadOnly($item.'.development_name');

    Http::assertNothingSent();
});

it('creates a measurement company inline with the engineering type locked', function () {
    $this->actingAs(makeAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Engenharia Inline Ltda',
            'estabelecimento' => ['nome_fantasia' => 'Engenharia Inline'],
        ]),
    ]);

    $component = Livewire::test(CreateEmission::class);
    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $createCompanyAction = TestAction::make('createOption')->schemaComponent($item.'.measurement_company_id');

    $component
        ->assertActionHasLabel($createCompanyAction, 'Cadastrar Empresa de Medição')
        ->mountAction($createCompanyAction)
        ->fillForm(['cnpj' => '98.765.432/0001-10'])
        ->assertActionDataSet(['name' => 'Engenharia Inline'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $company = ExpenseServiceProvider::query()->where('cnpj', '98765432000110')->sole();

    expect($company->name)->toBe('Engenharia Inline')
        ->and($company->type?->name)->toBe(Construction::MEASUREMENT_COMPANY_TYPE_NAME);

    $component
        ->assertFormSet([$item.'.measurement_company_id' => $company->id])
        ->assertFormSet([$item.'.measurement_company_cnpj' => '98.765.432/0001-10']);
});

it('accepts an inline measurement company when creating the emission', function () {
    $this->actingAs(makeAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Engenharia Inline Ltda',
            'estabelecimento' => ['nome_fantasia' => 'Engenharia Inline'],
        ]),
    ]);

    $component = Livewire::test(CreateEmission::class);
    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $component
        ->mountAction(TestAction::make('createOption')->schemaComponent($item.'.measurement_company_id'))
        ->fillForm(['cnpj' => '98.765.432/0001-10'])
        ->callMountedAction();

    $company = ExpenseServiceProvider::query()->where('cnpj', '98765432000110')->sole();

    $component
        ->fillForm([
            'name' => 'Emissão Medição Inline',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                emissionConstructionState($company->id),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Emission::query()->where('name', 'Emissão Medição Inline')->sole()->constructions()->sole()->measurement_company_id)
        ->toBe($company->id);
});

it('refuses to create an emission without a construction', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Sem Empreendimento',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [],
        ])
        ->call('create')
        ->assertHasFormErrors([
            EmissionConstructionsStep::STATE_PATH,
        ]);

    expect(Emission::query()->count())->toBe(0)
        ->and(Construction::query()->count())->toBe(0);
});

it('refuses to create an emission when the initial sales board is incomplete', function () {
    $this->actingAs(makeAdminUser());

    $measurementCompany = makeMeasurementCompany();

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Quadro Incompleto',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                [
                    'development_name' => 'Residencial Aurora',
                    'city' => 'Fortaleza',
                    'state' => 'CE',
                    'measurement_company_id' => $measurementCompany->id,
                    EmissionConstructionsStep::SALES_BOARD_STATE_PATH => [
                        'reference_month' => null,
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(Emission::query()->count())->toBe(0)
        ->and(Construction::query()->count())->toBe(0)
        ->and(SalesBoard::query()->count())->toBe(0);
});

it('reports every missing construction field by name', function () {
    $this->actingAs(makeAdminUser());

    $component = Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Empreendimento Vazio',
            'type' => 'CRI',
            'status' => 'draft',
        ])
        ->call('create');

    $item = EmissionConstructionsStep::STATE_PATH.'.'.array_key_first(
        $component->get('data.'.EmissionConstructionsStep::STATE_PATH),
    );

    $component->assertHasFormErrors([
        $item.'.development_name' => 'Informe o empreendimento.',
        $item.'.city' => 'Informe a cidade.',
        $item.'.state' => 'Selecione o estado.',
        $item.'.measurement_company_id' => 'Selecione a empresa de medição.',
        $item.'.'.EmissionConstructionsStep::SALES_BOARD_STATE_PATH.'.reference_month' => 'Informe a competência no formato MM/AAAA.',
    ]);

    expect(Emission::query()->count())->toBe(0);
});

it('creates the construction and its initial sales board with the emission', function () {
    $this->actingAs(makeAdminUser());

    $measurementCompany = makeMeasurementCompany();

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Com Empreendimento',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                emissionConstructionState($measurementCompany->id),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $emission = Emission::query()->where('name', 'Emissão Com Empreendimento')->sole();
    $construction = $emission->constructions()->sole();
    $salesBoard = $emission->salesBoards()->sole();

    expect($construction->development_name)->toBe('Residencial Aurora')
        ->and($construction->development_trade_name)->toBe('Aurora')
        ->and($construction->development_cnpj)->toBe('12345678000190')
        ->and($construction->city)->toBe('Fortaleza')
        ->and($construction->state)->toBe('CE')
        ->and($construction->measurement_company_id)->toBe($measurementCompany->id)
        ->and($salesBoard->construction_id)->toBe($construction->id)
        ->and($salesBoard->reference_month->toDateString())->toBe('2026-05-01')
        ->and($salesBoard->stock_units)->toBe(10)
        ->and($salesBoard->total_units)->toBe(18)
        ->and((float) $salesBoard->stock_value)->toBe(1000.0)
        ->and((float) $salesBoard->financed_value)->toBe(2000.5);
});

it('gives each construction of the operation its own initial sales board', function () {
    $this->actingAs(makeAdminUser());

    $measurementCompany = makeMeasurementCompany();

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Multi Empreendimento',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                emissionConstructionState($measurementCompany->id),
                [
                    ...emissionConstructionState($measurementCompany->id),
                    'development_name' => 'Residencial Boreal',
                    'development_cnpj' => '55.666.777/0001-88',
                    EmissionConstructionsStep::SALES_BOARD_STATE_PATH => [
                        'reference_month' => '06/2026',
                        'stock_units' => 5,
                        'financed_units' => 0,
                        'paid_units' => 0,
                        'exchanged_units' => 0,
                        'stock_value' => '500,00',
                        'financed_value' => '0,00',
                        'paid_value' => '0,00',
                        'exchanged_value' => '0,00',
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $emission = Emission::query()->where('name', 'Emissão Multi Empreendimento')->sole();

    expect($emission->constructions()->count())->toBe(2)
        ->and($emission->salesBoards()->count())->toBe(2);

    $boreal = $emission->constructions()->where('development_name', 'Residencial Boreal')->sole();
    $borealBoard = $emission->salesBoards()->where('construction_id', $boreal->id)->sole();

    expect($borealBoard->reference_month->toDateString())->toBe('2026-06-01')
        ->and($borealBoard->total_units)->toBe(5);
});

it('rejects a measurement company that is not an engineering provider', function () {
    $this->actingAs(makeAdminUser());

    $otherType = ExpenseServiceProviderType::factory()->create(['name' => 'Emissor']);
    $invalidCompany = ExpenseServiceProvider::factory()->create([
        'expense_service_provider_type_id' => $otherType->id,
    ]);

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Medição Inválida',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                [
                    ...emissionConstructionState($invalidCompany->id),
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors([
            EmissionConstructionsStep::STATE_PATH.'.0.measurement_company_id',
        ]);

    expect(Emission::query()->count())->toBe(0);
});

it('rolls the emission back when the constructions cannot be persisted', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->make(['name' => 'Emissão Rollback']);
    $emission->save();

    expect(fn () => app(CreateInitialConstructions::class)->handle($emission, []))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(CreateInitialConstructions::class)->handle($emission, [
        ['development_name' => 'Sem Quadro', 'city' => 'Fortaleza', 'state' => 'CE'],
    ]))->toThrow(InvalidArgumentException::class);

    expect($emission->constructions()->count())->toBe(0)
        ->and($emission->salesBoards()->count())->toBe(0);
});

it('lists the constructions and their initial boards on the review step', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateEmission::class)
        ->assertSee('Empreendimento incompleto')
        ->fillForm([
            EmissionConstructionsStep::STATE_PATH => [
                emissionConstructionState(makeMeasurementCompany()->id),
            ],
        ])
        ->assertSee('Residencial Aurora')
        ->assertSee('Quadro de Vendas: 05/2026')
        ->assertDontSee('Empreendimento incompleto')
        ->fillForm([
            EmissionConstructionsStep::STATE_PATH => [],
        ])
        ->assertSee('Nenhum empreendimento cadastrado');
});

it('discards the emission when the constructions cannot be persisted', function () {
    $this->actingAs(makeAdminUser());

    app()->bind(CreateInitialConstructions::class, fn (): CreateInitialConstructions => new class extends CreateInitialConstructions
    {
        public function handle(Emission $emission, array $constructions): array
        {
            throw new RuntimeException('falha ao persistir empreendimentos');
        }
    });

    $measurementCompany = makeMeasurementCompany();

    expect(fn () => Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Transacional',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                emissionConstructionState($measurementCompany->id),
            ],
        ])
        ->call('create'))->toThrow(RuntimeException::class);

    expect(Emission::query()->count())->toBe(0)
        ->and(Construction::query()->count())->toBe(0)
        ->and(SalesBoard::query()->count())->toBe(0);
});

it('does not persist the constructions payload on the emission record', function () {
    $this->actingAs(makeAdminUser());

    $measurementCompany = makeMeasurementCompany();

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Payload Limpo',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                emissionConstructionState($measurementCompany->id),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $emission = Emission::query()->where('name', 'Emissão Payload Limpo')->sole();

    expect($emission->getAttributes())->not->toHaveKey(EmissionConstructionsStep::STATE_PATH)
        ->and($emission->salesBoards()->count())->toBe(1);
});

it('keeps later sales board updates and their history working', function () {
    $this->actingAs(makeAdminUser());

    $measurementCompany = makeMeasurementCompany();

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Histórico',
            'type' => 'CRI',
            'status' => 'draft',
            EmissionConstructionsStep::STATE_PATH => [
                emissionConstructionState($measurementCompany->id),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $salesBoard = Emission::query()->where('name', 'Emissão Histórico')->sole()->salesBoards()->sole();

    $salesBoard->update(['stock_units' => 42]);

    expect($salesBoard->refresh()->stock_units)->toBe(42)
        ->and($salesBoard->valueHistories()->count())->toBe(2)
        ->and($salesBoard->valueHistories()->first()->stock_units)->toBe(10);
});

function emissionWizard(string $operation): Wizard
{
    $schema = EmissionForm::configure(
        Schema::make($operation === 'edit' ? new EditEmission : new CreateEmission)->operation($operation),
    );

    return collect($schema->getComponents())
        ->sole(fn (mixed $component): bool => $component instanceof Wizard);
}

/**
 * @return Collection<int, Step>
 */
function emissionWizardSteps(string $operation): Collection
{
    return collect(emissionWizard($operation)->getChildSchema()->getComponents())
        ->filter(fn (mixed $component): bool => $component instanceof Step)
        ->values();
}
