<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Jobs\ExtractSecuritizationClausesJob;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

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
                Section::make('Dados da Operação')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome da Operação')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('type')
                            ->label('Tipo')
                            ->options(Emission::TYPE_OPTIONS)
                            ->required(),

                        Select::make('status')
                            ->label('Status da Operação')
                            ->options(Emission::STATUS_OPTIONS)
                            ->default('draft')
                            ->required(),

                        Select::make('registered_with_cvm')
                            ->label('Registrada na CVM')
                            ->options(self::YES_NO_OPTIONS),

                        TextInput::make('if_code')
                            ->label('Código IF')
                            ->maxLength(255),

                        TextInput::make('isin_code')
                            ->label('Código ISIN')
                            ->maxLength(255),

                        Select::make('issuer_situation')
                            ->label('Situação da Emissora')
                            ->options(Emission::ISSUER_SITUATION_OPTIONS),

                        TextInput::make('bsi_code')
                            ->label('Código BSI')
                            ->readOnly()
                            ->dehydrated(false)
                            ->placeholder('Gerado automaticamente.')
                            ->helperText('Gerado automaticamente.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Partes Envolvidas')
                    ->columnSpanFull()
                    ->compact()
                    ->columns([
                        'default' => 1,
                        'xl' => 1,
                    ])
                    ->schema([
                        self::serviceProviderField('issuer', 'Emissor', 'Emissor'),

                        self::serviceProviderField('lead_coordinator', 'Coordenador Líder', 'Coordenador Líder'),

                        self::serviceProviderField('settlement_bank', 'Banco Liquidante', 'Banco Liquidante'),

                        self::serviceProviderField('registrar', 'Escriturador', 'Escriturador'),

                        self::serviceProviderField('trustee_agent', 'Agente Fiduciário', 'Agente Fiduciário'),

                        self::serviceProviderField('debtor', 'Devedor', 'Devedor'),

                        self::serviceProviderField('law_firm', 'Escritório de Advocacia', 'Escritório de Advocacia')
                            ->columnSpanFull(),
                    ]),

                Section::make('Estrutura da Emissão')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->schema([
                        DatePicker::make('issue_date')
                            ->label('Data de Emissão'),

                        DatePicker::make('maturity_date')
                            ->label('Data de Vencimento'),

                        TextInput::make('series')
                            ->label('Série')
                            ->maxLength(255),

                        TextInput::make('emission_number')
                            ->label('Número da Emissão')
                            ->maxLength(255),

                        Select::make('fiduciary_regime')
                            ->label('Regime Fiduciário')
                            ->options(self::YES_NO_OPTIONS),

                        Select::make('form_type')
                            ->label('Forma')
                            ->options(Emission::FORM_OPTIONS),

                        Select::make('monetary_update_period')
                            ->label('Periodicidade de Atualização Monetária')
                            ->options(self::MONTHLY_ANNUAL_OPTIONS),

                        Select::make('interest_payment_frequency')
                            ->label('Fluxo de Pagamento de Juros')
                            ->options(self::MONTHLY_ANNUAL_OPTIONS),

                        Select::make('amortization_frequency')
                            ->label('Fluxo de Amortização')
                            ->options(self::AMORTIZATION_OPTIONS),

                        Select::make('concentration')
                            ->label('Nível de Concentração')
                            ->options(self::CONCENTRATION_OPTIONS),

                        Select::make('prepayment_possibility')
                            ->label('Opção de Resgate Antecipado')
                            ->options(self::BOOLEAN_SELECT_OPTIONS)
                            ->default('0')
                            ->formatStateUsing(fn ($state): string => (bool) $state ? '1' : '0')
                            ->dehydrateStateUsing(fn ($state): bool => (bool) $state),

                        TextInput::make('segment')
                            ->label('Segmento de Atuação')
                            ->maxLength(255),
                    ]),

                Section::make('Valores e Remuneração')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->schema([
                        TextInput::make('offer_type')
                            ->label('Tipo de Oferta')
                            ->readOnly()
                            ->default('CVM 160')
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state('CVM 160');
                            })
                            ->dehydrateStateUsing(fn (): string => 'CVM 160')
                            ->columnSpanFull(),

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

                        TextInput::make('issued_quantity')
                            ->label('Quantidade Emitida')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 0)
                            JS))
                            ->stripCharacters(['.', ','])
                            ->minValue(0)
                            ->placeholder('32.600'),

                        TextInput::make('integralized_quantity')
                            ->label('Quantidade Integralizada')
                            ->readOnly()
                            ->dehydrated(false)
                            ->default(0)
                            ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 0, ',', '.'))
                            ->placeholder('0'),

                        TextInput::make('issued_price')
                            ->label('Preço de Emissão')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('1.000,00'),

                        TextInput::make('issued_volume')
                            ->label('Volume Emitido')
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                            ->prefix('R$')
                            ->placeholder('32.000.000,00'),
                    ]),

                Section::make('Cláusulas e Garantias')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->headerActions([
                        Action::make('extract_from_term')
                            ->label(fn (mixed $livewire): string => ($livewire instanceof \App\Filament\Resources\Emissions\Pages\EditEmission && $livewire->isExtractingClauses) ? 'Extraindo...' : 'Extrair do Termo')
                            ->icon('heroicon-o-sparkles')
                            ->color('warning')
                            ->disabled(fn (mixed $livewire): bool => $livewire instanceof \App\Filament\Resources\Emissions\Pages\EditEmission && $livewire->isExtractingClauses)
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->requiresConfirmation()
                            ->modalHeading('Extrair Cláusulas do Termo de Securitização')
                            ->modalDescription('O processo de extração via IA leva entre 3 e 5 minutos. A página será atualizada automaticamente ao concluir. Os campos preenchidos serão sobrescritos.')
                            ->modalSubmitActionLabel('Iniciar Extração')
                            ->mountUsing(function (Action $action, Emission $record): void {
                                $document = $record->documents()
                                    ->where('category', 'documentos_operacao')
                                    ->whereRaw('TRIM(title) = ?', ['Termo de Securitização'])
                                    ->first();

                                if (! $document) {
                                    Notification::make()
                                        ->title('Termo de Securitização não encontrado')
                                        ->body('Adicione o documento em "Documentos da Operação" com o título "Termo de Securitização".')
                                        ->warning()
                                        ->send();

                                    $action->halt();
                                }
                            })
                            ->action(function (Emission $record, mixed $livewire): void {
                                $document = $record->documents()
                                    ->where('category', 'documentos_operacao')
                                    ->whereRaw('TRIM(title) = ?', ['Termo de Securitização'])
                                    ->first();

                                if (! $document) {
                                    Notification::make()
                                        ->title('Termo de Securitização não encontrado')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                Cache::put("gemini_extraction_{$record->id}_status", 'processing', 1800);
                                $livewire->isExtractingClauses = true;

                                ExtractSecuritizationClausesJob::dispatch($record->id, $document->id);

                                Notification::make()
                                    ->title('Extração iniciada')
                                    ->body('O processo leva de 3 a 5 minutos. A página será atualizada automaticamente quando concluída.')
                                    ->info()
                                    ->send();
                            }),
                    ])
                    ->schema([
                        Placeholder::make('securitization_term_status')
                            ->label('Termo de Securitização')
                            ->content(function (?Emission $record): HtmlString {
                                $exists = $record?->documents()
                                    ->where('category', 'documentos_operacao')
                                    ->whereRaw('TRIM(title) = ?', ['Termo de Securitização'])
                                    ->exists();

                                if ($exists) {
                                    return new HtmlString(
                                        '<span style="display: inline-flex; align-items: center; gap: 6px; border-radius: 9999px; background-color: rgba(34, 197, 94, 0.12); padding: 4px 10px; font-size: 0.75rem; font-weight: 500; color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.25);">
                                            <svg style="width: 12px; height: 12px; flex-shrink: 0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                                            Cadastrado em Documentos da Operação
                                        </span>'
                                    );
                                }

                                return new HtmlString(
                                    '<span style="display: inline-flex; align-items: center; gap: 6px; border-radius: 9999px; background-color: rgba(245, 158, 11, 0.12); padding: 4px 10px; font-size: 0.75rem; font-weight: 500; color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25);">
                                        <svg style="width: 12px; height: 12px; flex-shrink: 0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                                        Não cadastrado — adicione em Documentos da Operação com o título "Termo de Securitização"
                                    </span>'
                                );
                            })
                            ->columnSpanFull()
                            ->visibleOn('edit'),

                        Placeholder::make('gemini_extraction_progress')
                            ->label('')
                            ->content(new HtmlString(
                                '<div class="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400">
                                    <svg class="animate-spin size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    Extração em andamento via IA... Isso pode levar de 3 a 5 minutos. A página será atualizada automaticamente.
                                </div>'
                            ))
                            ->columnSpanFull()
                            ->visibleOn('edit')
                            ->hidden(fn (mixed $livewire): bool => ! ($livewire instanceof \App\Filament\Resources\Emissions\Pages\EditEmission && $livewire->isExtractingClauses))
                            ->extraAttributes(['wire:poll.5000ms' => 'checkGeminiExtractionStatus']),

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
                            ->rows(4),

                        Textarea::make('use_of_proceeds')
                            ->label('Destinação dos Recursos')
                            ->rows(4),

                        Textarea::make('subscription_and_integralization_terms')
                            ->label('Forma de Subscrição e de Integralização e Preço de Integralização')
                            ->rows(4),

                        Textarea::make('repactuation')
                            ->label('Repactuação')
                            ->rows(4),

                        Textarea::make('amortization_payment_schedule')
                            ->label('Calendário de Pagamento da Amortização')
                            ->rows(4),

                        Textarea::make('remuneration_payment_schedule')
                            ->label('Calendário de Pagamento da Remuneração')
                            ->rows(4),

                        Textarea::make('optional_early_redemption')
                            ->label('Resgate Antecipado Facultativo')
                            ->rows(4),

                        Textarea::make('early_amortization')
                            ->label('Amortização Antecipada')
                            ->rows(4),

                        Textarea::make('remuneration_calculation')
                            ->label('Cálculo da Remuneração')
                            ->rows(4),

                        Textarea::make('segregated_estate')
                            ->label('Patrimônio Separado')
                            ->rows(4),

                        Textarea::make('property_description')
                            ->label('Descrição do Imóvel')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('guarantees_description')
                            ->label('Garantias')
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('covenants')
                            ->label('Covenants')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Divulgação Institucional')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->schema([
                        Toggle::make('is_public')
                            ->label('Divulgação em Ambiente Público')
                            ->default(false)
                            ->columnSpanFull(),

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
                    ]),
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
