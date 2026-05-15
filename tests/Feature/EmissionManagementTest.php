<?php

use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('filters emission service provider fields by the expected provider types', function () {
    $this->actingAs(makeAdminUser());

    $issuerType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Emissor',
    ]);
    $leadCoordinatorType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Coordenador Líder',
    ]);
    $settlementBankType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Banco Liquidante',
    ]);
    $registrarType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Escriturador',
    ]);
    $trusteeType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Agente Fiduciário',
    ]);
    $debtorType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Devedor',
    ]);
    $lawFirmType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Escritório de Advocacia',
    ]);
    $otherType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Servicer',
    ]);

    ExpenseServiceProvider::factory()->create([
        'name' => 'BSI Emissor',
        'expense_service_provider_type_id' => $issuerType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Coord Alpha',
        'expense_service_provider_type_id' => $leadCoordinatorType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Banco Delta',
        'expense_service_provider_type_id' => $settlementBankType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Escriturador Ômega',
        'expense_service_provider_type_id' => $registrarType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Trustee Beta',
        'expense_service_provider_type_id' => $trusteeType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Debtor Gamma',
        'expense_service_provider_type_id' => $debtorType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Advocacia Sigma',
        'expense_service_provider_type_id' => $lawFirmType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Prestador Irrelevante',
        'expense_service_provider_type_id' => $otherType->id,
    ]);

    $this->get(EmissionResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertDontSee('Preço Unitário (PU) Atual')
        ->assertDontSee('Status de Integralização')
        ->assertDontSee('Ciclo de Atualização Monetária (Meses)');

    Livewire::test(CreateEmission::class)
        ->assertFormFieldExists('issuer', function (Select $field): bool {
            return $field->getOptions() === [
                'BSI Emissor' => 'BSI Emissor',
            ];
        })
        ->assertFormFieldExists('lead_coordinator', function (Select $field): bool {
            return $field->getOptions() === [
                'Coord Alpha' => 'Coord Alpha',
            ];
        })
        ->assertFormFieldExists('settlement_bank', function (Select $field): bool {
            return $field->getOptions() === [
                'Banco Delta' => 'Banco Delta',
            ];
        })
        ->assertFormFieldExists('registrar', function (Select $field): bool {
            return $field->getOptions() === [
                'Escriturador Ômega' => 'Escriturador Ômega',
            ];
        })
        ->assertFormFieldExists('trustee_agent', function (Select $field): bool {
            return $field->getOptions() === [
                'Trustee Beta' => 'Trustee Beta',
            ];
        })
        ->assertFormFieldExists('debtor', function (Select $field): bool {
            return $field->getOptions() === [
                'Debtor Gamma' => 'Debtor Gamma',
            ];
        })
        ->assertFormFieldExists('law_firm', function (Select $field): bool {
            return $field->getOptions() === [
                'Advocacia Sigma' => 'Advocacia Sigma',
            ];
        })
        ->assertFormFieldExists('issuer_situation', function (Select $field): bool {
            return $field->getOptions() === Emission::ISSUER_SITUATION_OPTIONS;
        })
        ->assertFormFieldExists('monetary_update_period', function (Select $field): bool {
            return $field->getOptions() === [
                'Mensal' => 'Mensal',
                'Anual' => 'Anual',
            ];
        })
        ->assertFormFieldExists('interest_payment_frequency', function (Select $field): bool {
            return $field->getOptions() === [
                'Mensal' => 'Mensal',
                'Anual' => 'Anual',
            ];
        })
        ->assertFormFieldExists('concentration', function (Select $field): bool {
            return $field->getOptions() === [
                'Concentrado' => 'Concentrado',
                'Pulverizado' => 'Pulverizado',
            ];
        })
        ->assertFormFieldExists('amortization_frequency', function (Select $field): bool {
            return $field->getOptions() === [
                'Mensal' => 'Mensal',
                'Anual' => 'Anual',
                'Bullet' => 'Bullet',
            ];
        })
        ->assertFormFieldExists('remuneration_indexer', function (Select $field): bool {
            return $field->getOptions() === [
                'CDI' => 'CDI',
                'IPCA' => 'IPCA',
                'Prefixado' => 'Prefixado',
            ];
        })
        ->assertFormFieldExists('fiduciary_regime', function (Select $field): bool {
            return $field->getOptions() === [
                'Sim' => 'Sim',
                'Não' => 'Não',
            ];
        })
        ->assertFormFieldExists('registered_with_cvm', function (Select $field): bool {
            return $field->getOptions() === [
                'Sim' => 'Sim',
                'Não' => 'Não',
            ];
        })
        ->assertFormFieldExists('form_type', function (Select $field): bool {
            return $field->getOptions() === Emission::FORM_OPTIONS;
        })
        ->assertFormFieldExists('prepayment_possibility', function (Select $field): bool {
            return $field->getOptions() === [
                '1' => 'Sim',
                '0' => 'Não',
            ];
        })
        ->assertFormFieldExists('guarantee_fund', function (Select $field): bool {
            return $field->getOptions() === [
                'Sim' => 'Sim',
                'Não' => 'Não',
            ];
        })
        ->assertFormFieldExists('expense_fund', function (Select $field): bool {
            return $field->getOptions() === [
                'Sim' => 'Sim',
                'Não' => 'Não',
            ];
        })
        ->assertFormFieldExists('reserve_fund', function (Select $field): bool {
            return $field->getOptions() === [
                'Sim' => 'Sim',
                'Não' => 'Não',
            ];
        })
        ->assertFormFieldExists('works_fund', function (Select $field): bool {
            return $field->getOptions() === [
                'Sim' => 'Sim',
                'Não' => 'Não',
            ];
        })
        ->assertFormFieldExists('bsi_code')
        ->assertFormFieldExists('corporate_purpose')
        ->assertFormFieldExists('subscription_and_integralization_terms')
        ->assertFormFieldExists('amortization_payment_schedule')
        ->assertFormFieldExists('remuneration_payment_schedule')
        ->assertFormFieldExists('use_of_proceeds')
        ->assertFormFieldExists('repactuation')
        ->assertFormFieldExists('optional_early_redemption')
        ->assertFormFieldExists('early_amortization')
        ->assertFormFieldExists('remuneration_calculation')
        ->assertFormFieldExists('property_description')
        ->assertFormFieldExists('segregated_estate')
        ->assertFormFieldExists('guarantees_description')
        ->assertFormSet([
            'offer_type' => 'CVM 160',
        ])
        ->assertFormComponentActionExists('issuer', 'createOption')
        ->assertFormComponentActionExists('lead_coordinator', 'createOption')
        ->assertFormComponentActionExists('settlement_bank', 'createOption')
        ->assertFormComponentActionExists('registrar', 'createOption')
        ->assertFormComponentActionExists('trustee_agent', 'createOption')
        ->assertFormComponentActionExists('debtor', 'createOption')
        ->assertFormComponentActionExists('law_firm', 'createOption');
});

it('creates an issuer inline from the emission form with the locked issuer type', function () {
    $this->actingAs(makeAdminUser());

    ExpenseServiceProviderType::factory()->create([
        'name' => 'Emissor',
    ]);

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Emissor Inline Ltda',
            'estabelecimento' => [
                'nome_fantasia' => 'Emissor Inline',
            ],
        ]),
    ]);

    Livewire::test(CreateEmission::class)
        ->assertFormComponentActionHasLabel('issuer', 'createOption', 'Cadastrar prestador')
        ->mountFormComponentAction('issuer', 'createOption')
        ->fillForm([
            'cnpj' => '12.345.678/0001-90',
        ])
        ->assertFormComponentActionDataSet([
            'name' => 'Emissor Inline',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'issuer' => 'Emissor Inline',
        ]);

    $serviceProvider = ExpenseServiceProvider::query()->where('cnpj', '12345678000190')->sole();

    expect($serviceProvider->name)->toBe('Emissor Inline')
        ->and($serviceProvider->type?->name)->toBe('Emissor');
});

it('creates a settlement bank inline from the emission form with the locked settlement bank type', function () {
    $this->actingAs(makeAdminUser());

    ExpenseServiceProviderType::factory()->create([
        'name' => 'Banco Liquidante',
    ]);

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Banco Inline Ltda',
            'estabelecimento' => [
                'nome_fantasia' => 'Banco Inline',
            ],
        ]),
    ]);

    Livewire::test(CreateEmission::class)
        ->assertFormComponentActionHasLabel('settlement_bank', 'createOption', 'Cadastrar prestador')
        ->mountFormComponentAction('settlement_bank', 'createOption')
        ->fillForm([
            'cnpj' => '22.333.444/0001-55',
        ])
        ->assertFormComponentActionDataSet([
            'name' => 'Banco Inline',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'settlement_bank' => 'Banco Inline',
        ]);

    $serviceProvider = ExpenseServiceProvider::query()->where('cnpj', '22333444000155')->sole();

    expect($serviceProvider->name)->toBe('Banco Inline')
        ->and($serviceProvider->type?->name)->toBe('Banco Liquidante');
});

it('creates a debtor inline from the emission form with the locked debtor type', function () {
    $this->actingAs(makeAdminUser());

    ExpenseServiceProviderType::factory()->create([
        'name' => 'Devedor',
    ]);

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Devedor Inline Ltda',
            'estabelecimento' => [
                'nome_fantasia' => 'Devedor Inline',
            ],
        ]),
    ]);

    Livewire::test(CreateEmission::class)
        ->assertFormComponentActionHasLabel('debtor', 'createOption', 'Cadastrar prestador')
        ->mountFormComponentAction('debtor', 'createOption')
        ->fillForm([
            'cnpj' => '98.765.432/0001-10',
        ])
        ->assertFormComponentActionDataSet([
            'name' => 'Devedor Inline',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'debtor' => 'Devedor Inline',
        ]);

    $serviceProvider = ExpenseServiceProvider::query()->where('cnpj', '98765432000110')->sole();

    expect($serviceProvider->name)->toBe('Devedor Inline')
        ->and($serviceProvider->type?->name)->toBe('Devedor');
});

it('creates a debtor inline from the emission edit form without trying to ignore the emission record in provider uniqueness', function () {
    $this->actingAs(makeAdminUser());

    ExpenseServiceProviderType::factory()->create([
        'name' => 'Devedor',
    ]);

    $emission = Emission::factory()->create([
        'debtor' => null,
    ]);

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Devedor Edição Ltda',
            'estabelecimento' => [
                'nome_fantasia' => 'Devedor Edição',
            ],
        ]),
    ]);

    Livewire::test(EditEmission::class, [
        'record' => $emission->getRouteKey(),
    ])
        ->mountFormComponentAction('debtor', 'createOption')
        ->fillForm([
            'cnpj' => '66.106.600/0001-47',
        ])
        ->assertFormComponentActionDataSet([
            'name' => 'Devedor Edição',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'debtor' => 'Devedor Edição',
        ]);

    $serviceProvider = ExpenseServiceProvider::query()->where('cnpj', '66106600000147')->sole();

    expect($serviceProvider->name)->toBe('Devedor Edição')
        ->and($serviceProvider->type?->name)->toBe('Devedor');
});

it('forces the offer type to CVM 160 on the emission edit form', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'offer_type' => '476',
    ]);

    Livewire::test(EditEmission::class, [
        'record' => $emission->getRouteKey(),
    ])
        ->assertFormSet([
            'offer_type' => 'CVM 160',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($emission->refresh()->offer_type)->toBe('CVM 160');
});

it('stores emission provider selections and yes no options from the create form', function () {
    $this->actingAs(makeAdminUser());

    $issuerType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Emissor',
    ]);
    $leadCoordinatorType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Coordenador Líder',
    ]);
    $settlementBankType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Banco Liquidante',
    ]);
    $registrarType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Escriturador',
    ]);
    $trusteeType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Agente Fiduciário',
    ]);
    $debtorType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Devedor',
    ]);
    $lawFirmType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Escritório de Advocacia',
    ]);

    ExpenseServiceProvider::factory()->create([
        'name' => 'BSI Capital Securitizadora',
        'expense_service_provider_type_id' => $issuerType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Lastro RDV DTVM',
        'expense_service_provider_type_id' => $leadCoordinatorType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Banco Liquidante XPTO',
        'expense_service_provider_type_id' => $settlementBankType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Escriturador XPTO',
        'expense_service_provider_type_id' => $registrarType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Terra Investimentos DTVM',
        'expense_service_provider_type_id' => $trusteeType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Grupo Devedor XYZ',
        'expense_service_provider_type_id' => $debtorType->id,
    ]);
    ExpenseServiceProvider::factory()->create([
        'name' => 'Advocacia XPTO',
        'expense_service_provider_type_id' => $lawFirmType->id,
    ]);

    Livewire::test(CreateEmission::class)
        ->fillForm([
            'name' => 'Emissão Formulário',
            'type' => 'CRI',
            'status' => 'draft',
            'issuer_situation' => 'Adimplente',
            'issuer' => 'BSI Capital Securitizadora',
            'lead_coordinator' => 'Lastro RDV DTVM',
            'settlement_bank' => 'Banco Liquidante XPTO',
            'registrar' => 'Escriturador XPTO',
            'fiduciary_regime' => 'Sim',
            'monetary_update_period' => 'Mensal',
            'interest_payment_frequency' => 'Anual',
            'concentration' => 'Concentrado',
            'amortization_frequency' => 'Bullet',
            'trustee_agent' => 'Terra Investimentos DTVM',
            'debtor' => 'Grupo Devedor XYZ',
            'law_firm' => 'Advocacia XPTO',
            'prepayment_possibility' => '1',
            'registered_with_cvm' => 'Sim',
            'form_type' => 'Escritural',
            'corporate_purpose' => 'Exploração de recebíveis imobiliários.',
            'subscription_and_integralization_terms' => 'Subscrição privada com integralização em moeda corrente.',
            'amortization_payment_schedule' => 'Último dia útil de cada mês.',
            'remuneration_payment_schedule' => 'Todo dia 15.',
            'use_of_proceeds' => 'Capital de giro e expansão da operação.',
            'repactuation' => 'Vedada.',
            'optional_early_redemption' => 'Permitido mediante aviso prévio.',
            'early_amortization' => 'Permitida em eventos extraordinários.',
            'remuneration_calculation' => 'Base 252 com spread contratado.',
            'guarantee_fund' => 'Sim',
            'expense_fund' => 'Não',
            'reserve_fund' => 'Sim',
            'works_fund' => 'Não',
            'property_description' => 'Portfólio de imóveis residenciais.',
            'segregated_estate' => 'Constituído conforme termo de securitização.',
            'guarantees_description' => 'Alienação fiduciária e cessão de recebíveis.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $emission = Emission::query()->sole();

    expect($emission->bsi_code)->toBe(sprintf('BSI-%s-%04d', $emission->created_at?->format('Y'), $emission->id))
        ->and($emission->issuer_situation)->toBe('Adimplente')
        ->and($emission->issuer)->toBe('BSI Capital Securitizadora')
        ->and($emission->lead_coordinator)->toBe('Lastro RDV DTVM')
        ->and($emission->settlement_bank)->toBe('Banco Liquidante XPTO')
        ->and($emission->registrar)->toBe('Escriturador XPTO')
        ->and($emission->fiduciary_regime)->toBe('Sim')
        ->and($emission->monetary_update_period)->toBe('Mensal')
        ->and($emission->interest_payment_frequency)->toBe('Anual')
        ->and($emission->offer_type)->toBe('CVM 160')
        ->and($emission->concentration)->toBe('Concentrado')
        ->and($emission->amortization_frequency)->toBe('Bullet')
        ->and($emission->trustee_agent)->toBe('Terra Investimentos DTVM')
        ->and($emission->debtor)->toBe('Grupo Devedor XYZ')
        ->and($emission->law_firm)->toBe('Advocacia XPTO')
        ->and($emission->registered_with_cvm)->toBe('Sim')
        ->and($emission->form_type)->toBe('Escritural')
        ->and($emission->corporate_purpose)->toBe('Exploração de recebíveis imobiliários.')
        ->and($emission->subscription_and_integralization_terms)->toBe('Subscrição privada com integralização em moeda corrente.')
        ->and($emission->amortization_payment_schedule)->toBe('Último dia útil de cada mês.')
        ->and($emission->remuneration_payment_schedule)->toBe('Todo dia 15.')
        ->and($emission->use_of_proceeds)->toBe('Capital de giro e expansão da operação.')
        ->and($emission->repactuation)->toBe('Vedada.')
        ->and($emission->optional_early_redemption)->toBe('Permitido mediante aviso prévio.')
        ->and($emission->early_amortization)->toBe('Permitida em eventos extraordinários.')
        ->and($emission->remuneration_calculation)->toBe('Base 252 com spread contratado.')
        ->and($emission->guarantee_fund)->toBe('Sim')
        ->and($emission->expense_fund)->toBe('Não')
        ->and($emission->reserve_fund)->toBe('Sim')
        ->and($emission->works_fund)->toBe('Não')
        ->and($emission->property_description)->toBe('Portfólio de imóveis residenciais.')
        ->and($emission->segregated_estate)->toBe('Constituído conforme termo de securitização.')
        ->and($emission->guarantees_description)->toBe('Alienação fiduciária e cessão de recebíveis.')
        ->and($emission->prepayment_possibility)->toBeTrue();
});
