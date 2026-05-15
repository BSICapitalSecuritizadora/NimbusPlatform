<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class EmissionForm
{
    private const YES_NO_OPTIONS = [
        'Sim' => 'Sim',
        'Não' => 'Não',
    ];

    private const BOOLEAN_SELECT_OPTIONS = [
        '1' => 'Sim',
        '0' => 'Não',
    ];

    private const MONTHLY_ANNUAL_OPTIONS = [
        'Mensal' => 'Mensal',
        'Anual' => 'Anual',
    ];

    private const CONCENTRATION_OPTIONS = [
        'Concentrado' => 'Concentrado',
        'Pulverizado' => 'Pulverizado',
    ];

    private const AMORTIZATION_OPTIONS = [
        'Mensal' => 'Mensal',
        'Anual' => 'Anual',
        'Bullet' => 'Bullet',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados Identificadores')
                    ->schema([
                        TextInput::make('name')
                            ->label('Denominação da Operação')
                            ->required()
                            ->maxLength(255),

                        Select::make('type')
                            ->label('Tipo')
                            ->options(Emission::TYPE_OPTIONS)
                            ->required(),

                        TextInput::make('if_code')
                            ->label('Código IF')
                            ->maxLength(255),

                        TextInput::make('isin_code')
                            ->label('Código ISIN')
                            ->maxLength(255),

                        Select::make('status')
                            ->label('Status da Operação')
                            ->options(Emission::STATUS_OPTIONS)
                            ->default('draft')
                            ->required(),

                        Select::make('issuer_situation')
                            ->label('Situação da Emissora')
                            ->options(Emission::ISSUER_SITUATION_OPTIONS),

                        TextInput::make('bsi_code')
                            ->label('Código BSI')
                            ->readOnly()
                            ->dehydrated(false)
                            ->placeholder('Gerado automaticamente ao salvar')
                            ->helperText('Gerado automaticamente após o primeiro salvamento.'),

                        Select::make('registered_with_cvm')
                            ->label('Registrada na CVM')
                            ->options(self::YES_NO_OPTIONS),
                    ])
                    ->columns(2),

                Section::make('Atributos da Emissão')
                    ->schema([
                        self::serviceProviderField('issuer', 'Emissor', 'Emissor'),

                        self::serviceProviderField('lead_coordinator', 'Coordenador Líder', 'Coordenador Líder'),

                        self::serviceProviderField('settlement_bank', 'Banco Liquidante', 'Banco Liquidante'),

                        self::serviceProviderField('registrar', 'Escriturador', 'Escriturador'),

                        Select::make('fiduciary_regime')
                            ->label('Regime Fiduciário')
                            ->options(self::YES_NO_OPTIONS),

                        DatePicker::make('issue_date')
                            ->label('Data de Emissão'),

                        DatePicker::make('maturity_date')
                            ->label('Data de Vencimento'),

                        Select::make('monetary_update_period')
                            ->label('Periodicidade de Atualização Monetária')
                            ->options(self::MONTHLY_ANNUAL_OPTIONS),

                        TextInput::make('series')
                            ->label('Série')
                            ->maxLength(255),

                        TextInput::make('emission_number')
                            ->label('Número da Emissão')
                            ->maxLength(255),

                        TextInput::make('issued_quantity')
                            ->label('Quantidade Emitida')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('32.600'),

                        Select::make('interest_payment_frequency')
                            ->label('Fluxo de Pagamento de Juros')
                            ->options(self::MONTHLY_ANNUAL_OPTIONS),

                        TextInput::make('offer_type')
                            ->label('Modalidade de Oferta')
                            ->readOnly()
                            ->default('CVM 160')
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state('CVM 160');
                            })
                            ->dehydrateStateUsing(fn (): string => 'CVM 160'),

                        Select::make('concentration')
                            ->label('Nível de Concentração')
                            ->options(self::CONCENTRATION_OPTIONS),

                        TextInput::make('issued_price')
                            ->label('Preço de Emissão')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('1.000,00'),

                        Select::make('amortization_frequency')
                            ->label('Fluxo de Amortização')
                            ->options(self::AMORTIZATION_OPTIONS),

                        TextInput::make('integralized_quantity')
                            ->label('Quantidade Integralizada')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('13.200'),

                        self::serviceProviderField('trustee_agent', 'Agente Fiduciário', 'Agente Fiduciário'),

                        self::serviceProviderField('debtor', 'Devedor', 'Devedor'),

                        self::serviceProviderField('law_firm', 'Escritório de Advocacia', 'Escritório de Advocacia'),

                        Select::make('remuneration_indexer')
                            ->label('Indexador')
                            ->options(Emission::REMUNERATION_INDEXER_OPTIONS),

                        TextInput::make('remuneration_rate')
                            ->label('Taxa')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->suffix('%')
                            ->placeholder('6,50'),

                        Select::make('prepayment_possibility')
                            ->label('Opção de Resgate Antecipado')
                            ->options(self::BOOLEAN_SELECT_OPTIONS)
                            ->default('0')
                            ->formatStateUsing(fn ($state): string => (bool) $state ? '1' : '0')
                            ->dehydrateStateUsing(fn ($state): bool => (bool) $state),

                        Select::make('form_type')
                            ->label('Forma')
                            ->options(Emission::FORM_OPTIONS),

                        TextInput::make('segment')
                            ->label('Segmento de Atuação')
                            ->maxLength(255),

                        TextInput::make('issued_volume')
                            ->label('Volume Emitido')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('32.000.000,00'),
                    ])
                    ->columns(2),

                Section::make('Cláusulas e Garantias')
                    ->schema([
                        Select::make('guarantee_fund')
                            ->label('Fundo de Fiança')
                            ->options(self::YES_NO_OPTIONS),

                        Select::make('expense_fund')
                            ->label('Fundo de Despesa')
                            ->options(self::YES_NO_OPTIONS),

                        Select::make('reserve_fund')
                            ->label('Fundo de Reserva')
                            ->options(self::YES_NO_OPTIONS),

                        Select::make('works_fund')
                            ->label('Fundo de Obras')
                            ->options(self::YES_NO_OPTIONS),

                        Textarea::make('corporate_purpose')
                            ->label('Objeto Social')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('subscription_and_integralization_terms')
                            ->label('Forma de Subscrição e de Integralização e Preço de Integralização')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('amortization_payment_schedule')
                            ->label('Calendário de Pagamento da Amortização')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('remuneration_payment_schedule')
                            ->label('Calendário de Pagamento da Remuneração')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('use_of_proceeds')
                            ->label('Destinação dos Recursos')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('repactuation')
                            ->label('Repactuação')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('optional_early_redemption')
                            ->label('Resgate Antecipado Facultativo')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('early_amortization')
                            ->label('Amortização Antecipada')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('remuneration_calculation')
                            ->label('Cálculo da Remuneração')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('property_description')
                            ->label('Descrição do Imóvel')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('segregated_estate')
                            ->label('Patrimônio Separado')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('guarantees_description')
                            ->label('Garantias')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Divulgação Institucional')
                    ->schema([
                        Toggle::make('is_public')
                            ->label('Divulgação em Ambiente Público')
                            ->default(false),

                        FileUpload::make('logo_path')
                            ->label('Identidade Visual da Operação')
                            ->image()
                            ->disk(Emission::defaultStorageDisk())
                            ->visibility('public')
                            ->directory('emissions/logos')
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label('Notas Institucionais / Sumário')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function serviceProviderField(string $field, string $label, string $typeName): Select
    {
        return Select::make($field)
            ->label($label)
            ->options(fn (): array => self::getServiceProviderOptions($typeName))
            ->searchable()
            ->preload()
            ->getSearchResultsUsing(
                fn (string $search): array => self::getServiceProviderOptions($typeName, $search),
            )
            ->getOptionLabelUsing(
                fn (mixed $value): ?string => filled($value) ? (string) $value : null,
            )
            ->createOptionForm(fn (): array => ExpenseServiceProviderForm::fields(
                serviceProviderTypeId: self::resolveServiceProviderTypeId($typeName),
                lockServiceProviderType: true,
            ))
            ->createOptionUsing(
                fn (array $data): string => (string) ExpenseServiceProvider::query()->create($data)->name,
            )
            ->createOptionAction(
                fn (Action $action): Action => $action
                    ->label('Cadastrar prestador')
                    ->modalHeading('Cadastrar '.$label),
            );
    }

    /**
     * @return array<string, string>
     */
    private static function getServiceProviderOptions(string $typeName, ?string $search = null): array
    {
        return ExpenseServiceProvider::query()
            ->whereHas('type', fn ($query) => $query->where('name', $typeName))
            ->when(
                filled($search),
                fn ($query): mixed => $query->where('name', 'like', '%'.trim((string) $search).'%'),
            )
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    private static function resolveServiceProviderTypeId(string $typeName): int
    {
        return (int) ExpenseServiceProviderType::query()
            ->firstOrCreate(['name' => $typeName])
            ->getKey();
    }
}
