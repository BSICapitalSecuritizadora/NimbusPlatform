<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Concerns\MoneyFormatter;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Jobs\ExtractSecuritizationClausesJob;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use App\Models\SalesBoard;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
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

    private const MONETARY_UPDATE_OPTIONS = [
        'Mensal' => 'Mensal',
        'Anual' => 'Anual',
        'Não' => 'Não',
    ];

    private const CONCENTRATION_OPTIONS = [
        'Concentrado' => 'Concentrado',
        'Pulverizado' => 'Pulverizado',
    ];

    private const AMORTIZATION_OPTIONS = [
        'Mensal' => 'Mensal',
        'Anual' => 'Anual',
        'Bullet' => 'Bullet',
        'Data de Vencimento' => 'Data de Vencimento',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Dados básicos')
                        ->columns([
                            'default' => 1,
                            'xl' => 2,
                        ])
                        ->schema([
                            TextInput::make('name')
                                ->label('Denominação da Operação')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('Ex: CRI BSI Capital - 1ª Emissão')
                                ->columnSpanFull()
                                ->validationMessages([
                                    'required' => 'Informe a denominação da operação.',
                                ]),

                            Select::make('type')
                                ->label('Tipo de Título')
                                ->options(Emission::TYPE_OPTIONS)
                                ->native(false)
                                ->required()
                                ->validationMessages([
                                    'required' => 'Selecione o tipo de título.',
                                ]),

                            Select::make('status')
                                ->label('Status da Operação')
                                ->options(Emission::STATUS_OPTIONS)
                                ->default('draft')
                                ->native(false)
                                ->required()
                                ->validationMessages([
                                    'required' => 'Selecione o status da operação.',
                                ]),

                            Select::make('registered_with_cvm')
                                ->label('Registrada na CVM')
                                ->options(self::YES_NO_OPTIONS)
                                ->native(false)
                                ->placeholder('Selecione'),

                            Select::make('issuer_situation')
                                ->label('Situação da Emissora')
                                ->options(Emission::ISSUER_SITUATION_OPTIONS)
                                ->native(false)
                                ->placeholder('Selecione'),

                            Grid::make([
                                'default' => 1,
                                'md' => 3,
                            ])
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('if_code')
                                        ->label('Código IF')
                                        ->maxLength(255)
                                        ->placeholder('Informe o código IF'),

                                    TextInput::make('isin_code')
                                        ->label('Código ISIN')
                                        ->maxLength(255)
                                        ->placeholder('Informe o código ISIN'),

                                    TextInput::make('bsi_code')
                                        ->label('Código BSI')
                                        ->readOnly()
                                        ->dehydrated(false)
                                        ->placeholder('Gerado automaticamente pelo sistema')
                                        ->extraInputAttributes([
                                            'class' => 'bsi-code-readonly',
                                        ]),
                                ]),
                        ]),

                    EmissionConstructionsStep::make(),

                    Step::make('Participantes')
                        ->columns([
                            'default' => 1,
                            'xl' => 1,
                        ])
                        ->schema([
                            self::serviceProviderField('issuer', 'Emissor', 'Emissor'),

                            self::serviceProviderField('lead_coordinator', 'Coordenador Líder', 'Coordenador Líder'),

                            self::serviceProviderField('settlement_bank', 'Banco Liquidante', 'Banco Liquidante'),

                            self::serviceProviderField('registrar', 'Escriturador', 'Escriturador'),

                            self::serviceProviderField('distributor', 'Distribuidor', 'Distribuidor'),

                            self::serviceProviderField('trustee_agent', 'Agente Fiduciário', 'Agente Fiduciário'),

                            self::serviceProviderField('debtor', 'Devedor', 'Devedor'),

                            self::serviceProviderField('law_firm', 'Escritório de Advocacia', 'Escritório de Advocacia')
                                ->columnSpanFull(),
                        ]),

                    Step::make('Características financeiras')
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
                                ->maxLength(255)
                                ->placeholder('Ex: 1ª Série'),

                            TextInput::make('emission_number')
                                ->label('Número da Emissão')
                                ->maxLength(255)
                                ->placeholder('Ex: 1ª Emissão'),

                            Select::make('fiduciary_regime')
                                ->label('Regime Fiduciário')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('form_type')
                                ->label('Forma dos Títulos')
                                ->options(Emission::FORM_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('monetary_update_period')
                                ->label('Periodicidade de Atualização Monetária')
                                ->options(self::MONETARY_UPDATE_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('interest_payment_frequency')
                                ->label('Periodicidade de Pagamento de Juros')
                                ->options(self::MONTHLY_ANNUAL_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('amortization_frequency')
                                ->label('Periodicidade de Amortização')
                                ->options(self::AMORTIZATION_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('concentration')
                                ->label('Nível de Concentração')
                                ->options(self::CONCENTRATION_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('prepayment_possibility')
                                ->label('Possibilidade de Resgate Antecipado')
                                ->options(self::BOOLEAN_SELECT_OPTIONS)
                                ->default('0')
                                ->formatStateUsing(fn ($state): string => (bool) $state ? '1' : '0')
                                ->dehydrateStateUsing(fn ($state): bool => (bool) $state)
                                ->placeholder('Selecione'),

                            self::segmentField(),

                            TextInput::make('target_audience')
                                ->label('Público Alvo')
                                ->readOnly()
                                ->default(Emission::DEFAULT_TARGET_AUDIENCE)
                                ->afterStateHydrated(function (TextInput $component, mixed $state): void {
                                    $component->state(filled($state) ? $state : Emission::DEFAULT_TARGET_AUDIENCE);
                                })
                                ->maxLength(255),
                        ]),

                    Step::make('Valores e Remuneração')
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
                                ->label('Indexador de Remuneração')
                                ->options(Emission::REMUNERATION_INDEXER_OPTIONS)
                                ->placeholder('Selecione'),

                            TextInput::make('remuneration_rate')
                                ->label('Taxa de Remuneração')
                                ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                                ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                                ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                                ->suffix('%')
                                ->placeholder('0,00'),

                            Grid::make([
                                'default' => 1,
                                'xl' => 3,
                            ])
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('issued_quantity')
                                        ->label('Quantidade Emitida')
                                        ->mask(RawJs::make(<<<'JS'
                                        $money($input, ',', '.', 0)
                                    JS))
                                        ->stripCharacters(['.', ','])
                                        ->minValue(0)
                                        ->live(onBlur: true)
                                        ->afterStateHydrated(fn (Get $get, Set $set): null => self::syncRemainingQuantity($get, $set))
                                        ->afterStateUpdated(function (Get $get, Set $set): void {
                                            self::syncRemainingQuantity($get, $set);
                                            self::syncIssuedVolume($get, $set);
                                        })
                                        ->placeholder('0'),

                                    TextInput::make('integralized_quantity')
                                        ->label('Quantidade Integralizada')
                                        ->readOnly()
                                        ->dehydrated(false)
                                        ->default('0')
                                        ->afterStateHydrated(fn (Get $get, Set $set): null => self::syncRemainingQuantity($get, $set))
                                        ->afterStateUpdated(fn (Get $get, Set $set): null => self::syncRemainingQuantity($get, $set))
                                        ->placeholder('0'),

                                    TextInput::make('remaining_quantity')
                                        ->label('Quantidade Restante')
                                        ->readOnly()
                                        ->dehydrated(false)
                                        ->default('0')
                                        ->placeholder('0'),
                                ]),

                            TextInput::make('issued_price')
                                ->label('Preço Unitário de Emissão (PU)')
                                ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                                ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, ',', '.') : null)
                                ->dehydrateStateUsing(fn ($state) => filled($state) ? (float) str_replace(['.', ','], ['', '.'], (string) $state) : null)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set): null => self::syncIssuedVolume($get, $set))
                                ->prefix('R$')
                                ->placeholder('0,00'),

                            TextInput::make('issued_volume')
                                ->label('Volume Total Emitido')
                                ->readOnly()
                                ->helperText('Calculado automaticamente: Quantidade Emitida × Preço Unitário de Emissão (PU).')
                                ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.', 2)
                            JS))
                                ->formatStateUsing(function (Get $get, mixed $state): ?string {
                                    $issuedVolume = self::calculateIssuedVolume($get) ?? $state;

                                    return $issuedVolume !== null ? number_format((float) $issuedVolume, 2, ',', '.') : null;
                                })
                                ->dehydrateStateUsing(fn (Get $get, mixed $state): ?float => self::calculateIssuedVolume($get)
                                    ?? (filled($state) ? MoneyFormatter::normalizeDecimalValue($state) : null))
                                ->prefix('R$')
                                ->placeholder('0,00'),
                        ]),

                    Step::make('Lastro, garantias e operação')
                        ->columns([
                            'default' => 1,
                            'xl' => 2,
                        ])
                        ->schema([
                            SchemaActions::make([
                                Action::make('extract_from_term')
                                    ->label(fn (mixed $livewire): string => ($livewire instanceof EditEmission && $livewire->isExtractingClauses) ? 'Extraindo cláusulas...' : 'Extrair do Termo')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('warning')
                                    ->disabled(fn (mixed $livewire): bool => $livewire instanceof EditEmission && $livewire->isExtractingClauses)
                                    ->visible(fn (string $operation): bool => $operation === 'edit')
                                    ->requiresConfirmation()
                                    ->modalHeading('Extrair Cláusulas do Termo de Securitização')
                                    ->modalDescription('O processo de extração via inteligência artificial leva entre 3 e 5 minutos. A página será atualizada automaticamente ao concluir. Note que os campos preenchidos serão sobrescritos.')
                                    ->modalSubmitActionLabel('Iniciar Extração')
                                    ->mountUsing(function (Action $action, Emission $record): void {
                                        $document = $record->documents()
                                            ->where('category', 'documentos_operacao')
                                            ->whereRaw('TRIM(title) = ?', ['Termo de Securitização'])
                                            ->first();

                                        if (! $document) {
                                            Notification::make()
                                                ->title('Termo de Securitização não encontrado')
                                                ->body('Certifique-se de adicionar o documento na seção "Documentos da Operação" com o título exato "Termo de Securitização".')
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
                                            ->body('O processo de extração está em execução e deve levar de 3 a 5 minutos. A página será atualizada automaticamente.')
                                            ->info()
                                            ->send();
                                    }),
                            ]),

                            Placeholder::make('securitization_term_status')
                                ->label('Status do Termo de Securitização')
                                ->content(function (?Emission $record): HtmlString {
                                    $exists = $record?->documents()
                                        ->where('category', 'documentos_operacao')
                                        ->whereRaw('TRIM(title) = ?', ['Termo de Securitização'])
                                        ->exists();

                                    if ($exists) {
                                        return new HtmlString(
                                            '<span style="display: inline-flex; align-items: center; gap: 6px; border-radius: 9999px; background-color: rgba(34, 197, 94, 0.12); padding: 4px 10px; font-size: 0.75rem; font-weight: 500; color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.25);">
                                            <svg style="width: 12px; height: 12px; flex-shrink: 0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                                            Cadastrado em "Documentos da Operação"
                                        </span>'
                                        );
                                    }

                                    return new HtmlString(
                                        '<span style="display: inline-flex; align-items: center; gap: 6px; border-radius: 9999px; background-color: rgba(245, 158, 11, 0.12); padding: 4px 10px; font-size: 0.75rem; font-weight: 500; color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25);">
                                        <svg style="width: 12px; height: 12px; flex-shrink: 0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                                        Não identificado — Adicione em "Documentos da Operação" com o título "Termo de Securitização"
                                    </span>'
                                    );
                                })
                                ->columnSpanFull()
                                ->visibleOn('edit'),

                            Placeholder::make('gemini_extraction_progress')
                                ->label('')
                                ->content(new HtmlString(
                                    '<div class="bsi-progress-card w-full">
                                        <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-white/10">
                                            <div class="flex items-center gap-2.5">
                                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/15 border border-amber-500/30 text-amber-400">
                                                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h4 class="text-sm font-semibold text-[#fbfaf8]">Extração de Cláusulas via IA</h4>
                                                    <p class="text-xs text-white/50">Processamento e estruturação do Termo de Securitização</p>
                                                </div>
                                            </div>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/15 border border-amber-500/30 text-amber-300">
                                                <span class="size-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                                                Em andamento
                                            </span>
                                        </div>

                                        <p class="text-xs text-white/75 mt-3 leading-relaxed">
                                            A inteligência artificial está analisando o documento para identificar, extrair e preencher automaticamente as cláusulas e obrigações da operação.
                                        </p>

                                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 my-3.5 pt-1">
                                            <div class="flex items-center gap-1.5 text-xs text-emerald-400 font-medium">
                                                <svg class="size-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                                                <span>Documento identificado</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 text-xs text-amber-300 font-medium">
                                                <span class="size-2 rounded-full bg-amber-400 animate-ping shrink-0"></span>
                                                <span>Extração via IA</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 text-xs text-white/40">
                                                <span class="size-2 rounded-full border border-white/25 shrink-0"></span>
                                                <span>Estruturando cláusulas</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 text-xs text-white/40">
                                                <span class="size-2 rounded-full border border-white/25 shrink-0"></span>
                                                <span>Preenchimento final</span>
                                            </div>
                                        </div>

                                        <div class="bsi-progress-bar-track">
                                            <div class="bsi-progress-bar-indeterminate"></div>
                                        </div>

                                        <div class="flex flex-wrap items-center justify-between gap-2 mt-3 pt-2 text-[11px] text-white/50 border-t border-white/5">
                                            <div class="flex items-center gap-1.5">
                                                <svg class="size-3.5 text-amber-400/80" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                </svg>
                                                <span>Tempo estimado: <strong class="text-white/85 font-medium">3 a 5 minutos</strong></span>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <span class="size-1.5 rounded-full bg-emerald-400/70"></span>
                                                <span>Atualização automática ao concluir (a cada 5s)</span>
                                            </div>
                                        </div>
                                    </div>'
                                ))
                                ->columnSpanFull()
                                ->visibleOn('edit')
                                ->hidden(fn (mixed $livewire): bool => ! ($livewire instanceof EditEmission && $livewire->isExtractingClauses))
                                ->extraAttributes(['wire:poll.5000ms' => 'checkGeminiExtractionStatus']),

                            Placeholder::make('pu_curve_generation_progress')
                                ->label('')
                                ->content(new HtmlString(
                                    '<div class="bsi-progress-card bsi-progress-card-sky w-full">
                                        <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-white/10">
                                            <div class="flex items-center gap-2.5">
                                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sky-500/15 border border-sky-500/30 text-sky-400">
                                                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h4 class="text-sm font-semibold text-[#fbfaf8]">Geração da Curva Diária de PU</h4>
                                                    <p class="text-xs text-white/50">Cálculo diário de PU sendo processado em segundo plano (fila)</p>
                                                </div>
                                            </div>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-sky-500/15 border border-sky-500/30 text-sky-300">
                                                <span class="size-1.5 rounded-full bg-sky-400 animate-pulse"></span>
                                                Em andamento
                                            </span>
                                        </div>

                                        <div class="bsi-progress-bar-track my-3">
                                            <div class="bsi-progress-bar-indeterminate-sky"></div>
                                        </div>

                                        <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-white/50">
                                            <span>Acompanhe o andamento no <strong>Painel da Curva PU</strong>.</span>
                                            <span>Atualização automática ao concluir</span>
                                        </div>
                                    </div>'
                                ))
                                ->columnSpanFull()
                                ->visibleOn('edit')
                                ->hidden(fn (mixed $livewire): bool => ! ($livewire instanceof EditEmission && $livewire->isGeneratingPuCurve))
                                ->extraAttributes(['wire:poll.5000ms' => 'checkPuCurveGenerationStatus']),

                            Placeholder::make('pu_curve_validation_progress')
                                ->label('')
                                ->content(new HtmlString(
                                    '<div class="bsi-progress-card bsi-progress-card-sky w-full">
                                        <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-white/10">
                                            <div class="flex items-center gap-2.5">
                                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sky-500/15 border border-sky-500/30 text-sky-400">
                                                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h4 class="text-sm font-semibold text-[#fbfaf8]">Validação da Curva de PU</h4>
                                                    <p class="text-xs text-white/50">Comparação com planilha de referência em execução na fila</p>
                                                </div>
                                            </div>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-sky-500/15 border border-sky-500/30 text-sky-300">
                                                <span class="size-1.5 rounded-full bg-sky-400 animate-pulse"></span>
                                                Em andamento
                                            </span>
                                        </div>

                                        <div class="bsi-progress-bar-track my-3">
                                            <div class="bsi-progress-bar-indeterminate-sky"></div>
                                        </div>

                                        <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-white/50">
                                            <span>O relatório de divergências será gerado ao concluir.</span>
                                            <span>Atualização automática ao concluir</span>
                                        </div>
                                    </div>'
                                ))
                                ->columnSpanFull()
                                ->visibleOn('edit')
                                ->hidden(fn (mixed $livewire): bool => ! ($livewire instanceof EditEmission && $livewire->isValidatingPuCurve))
                                ->extraAttributes(['wire:poll.5000ms' => 'checkPuCurveValidationStatus']),

                            Select::make('guarantee_fund')
                                ->label('Fundo de Fiança')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('expense_fund')
                                ->label('Fundo de Despesa')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('reserve_fund')
                                ->label('Fundo de Reserva')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('works_fund')
                                ->label('Fundo de Obras')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('fiduciary_assignment')
                                ->label('Cessão Fiduciária')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('vehicle_fiduciary_alienation')
                                ->label('Alienação Fiduciária de Veículos')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('quota_fiduciary_alienation')
                                ->label('Alienação Fiduciária de Cotas')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('surety')
                                ->label('Fiança')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('real_estate_guarantee')
                                ->label('Garantia de Imóveis')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('property_fiduciary_alienation')
                                ->label('Alienação Fiduciária de Imóvel')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Select::make('aval')
                                ->label('Aval')
                                ->options(self::YES_NO_OPTIONS)
                                ->placeholder('Selecione'),

                            Textarea::make('corporate_purpose')
                                ->label('Objeto Social')
                                ->placeholder('Descreva o objeto social da operação')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('use_of_proceeds')
                                ->label('Destinação dos Recursos')
                                ->placeholder('Descreva a destinação dos recursos captados')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('subscription_and_integralization_terms')
                                ->label('Condições de Subscrição e Integralização')
                                ->placeholder('Descreva as formas e preços de subscrição e integralização')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('repactuation')
                                ->label('Repactuação')
                                ->placeholder('Descreva as condições de repactuação, se houver')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('amortization_payment_schedule')
                                ->label('Calendário de Pagamento da Amortização')
                                ->placeholder('Descreva o cronograma de amortização')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('remuneration_payment_schedule')
                                ->label('Calendário de Pagamento da Remuneração')
                                ->placeholder('Descreva o cronograma de pagamento de juros/remuneração')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('optional_early_redemption')
                                ->label('Resgate Antecipado Facultativo')
                                ->placeholder('Descreva as condições para resgate antecipado facultativo')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('early_amortization')
                                ->label('Amortização Antecipada')
                                ->placeholder('Descreva as hipóteses de amortização antecipada')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('remuneration_calculation')
                                ->label('Cálculo da Remuneração')
                                ->placeholder('Descreva a metodologia de cálculo da remuneração')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('segregated_estate')
                                ->label('Patrimônio Separado')
                                ->placeholder('Descreva a constituição do patrimônio separado')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('property_description')
                                ->label('Descrição do Imóvel')
                                ->placeholder('Descreva detalhadamente o imóvel objeto da operação')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('guarantees_description')
                                ->label('Garantias da Operação')
                                ->placeholder('Descreva as garantias constituídas')
                                ->rows(4)
                                ->columnSpanFull(),

                            Textarea::make('covenants')
                                ->label('Covenants')
                                ->placeholder('Descreva as obrigações adicionais (covenants)')
                                ->rows(4)
                                ->columnSpanFull(),
                        ]),

                    Step::make('Documentos e informações públicas')
                        ->columns([
                            'default' => 1,
                            'xl' => 2,
                        ])
                        ->schema([
                            Toggle::make('is_public')
                                ->label('Divulgação Pública (Portal e Site)')
                                ->default(false)
                                ->columnSpanFull(),

                            FileUpload::make('logo_path')
                                ->label('Logotipo ou Identidade Visual da Operação')
                                ->image()
                                ->acceptedFileTypes((array) config('uploads.logo.allowed_mimes', []))
                                ->disk(Emission::defaultStorageDisk())
                                ->visibility('public')
                                ->directory('emissions/logos')
                                ->columnSpanFull(),

                            Textarea::make('description')
                                ->label('Notas Institucionais / Sumário Executivo')
                                ->placeholder('Resumo descritivo da operação para exibição pública')
                                ->rows(6)
                                ->columnSpanFull(),
                        ]),

                    Step::make('Revisão')
                        ->schema([
                            Placeholder::make('resumo')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->content(fn (Get $get, string $operation) => view('filament.emissions.review-sheet', self::buildReviewData($get, $operation))),
                        ]),
                ])
                    ->columnSpanFull()
                    ->skippable(fn (string $operation): bool => $operation !== 'create'),
            ]);
    }

    /**
     * Builds comprehensive structured review data across all wizard steps.
     */
    public static function buildReviewData(Get $get, string $operation): array
    {
        $isCreate = $operation === 'create';

        $stepsIndexes = [
            'dados_basicos' => 0,
            'empreendimentos' => $isCreate ? 1 : 0,
            'participantes' => $isCreate ? 2 : 1,
            'caracteristicas_financeiras' => $isCreate ? 3 : 2,
            'valores_remuneracao' => $isCreate ? 4 : 3,
            'lastro_garantias' => $isCreate ? 5 : 4,
            'documentos' => $isCreate ? 6 : 5,
        ];

        // Format and collect constructions
        $constructions = [];
        $constructionsAreComplete = true;
        if ($isCreate) {
            $rawConstructions = array_values((array) $get(EmissionConstructionsStep::STATE_PATH));

            $constructionsAreComplete = $rawConstructions !== [];

            foreach ($rawConstructions as $construction) {
                $developmentName = $construction['development_name'] ?? null;
                $referenceMonth = $construction[EmissionConstructionsStep::SALES_BOARD_STATE_PATH]['reference_month'] ?? null;

                if (blank($developmentName) || blank($referenceMonth)) {
                    $constructionsAreComplete = false;
                    $constructions[] = [
                        'name' => 'Empreendimento incompleto',
                        'details' => 'Quadro de Vendas pendente',
                    ];

                    continue;
                }

                $constructions[] = [
                    'name' => (string) $developmentName,
                    'details' => 'Quadro de Vendas: '.SalesBoard::formatReferenceMonthForDisplay($referenceMonth),
                ];
            }
        }

        // Status option labels
        $statusOptions = Emission::STATUS_OPTIONS;
        $statusKey = (string) $get('status');
        $statusLabel = $statusOptions[$statusKey] ?? $statusKey;

        // CVM registered
        $cvmVal = (string) $get('registered_with_cvm');
        $cvmLabel = $cvmVal === '1' ? 'Sim' : ($cvmVal === '0' ? 'Não' : ($cvmVal ?: 'Não informado'));

        // Issuer situation
        $issuerSitKey = (string) $get('issuer_situation');
        $issuerSitOptions = Emission::ISSUER_SITUATION_OPTIONS;
        $issuerSitLabel = $issuerSitOptions[$issuerSitKey] ?? $issuerSitKey;

        // Fiduciary regime
        $fidRegime = (string) $get('fiduciary_regime');
        $fidRegimeLabel = $fidRegime === '1' ? 'Sim' : ($fidRegime === '0' ? 'Não' : ($fidRegime ?: 'Não informado'));

        // Prepayment possibility
        $prepay = $get('prepayment_possibility');
        $prepayLabel = ($prepay === '1' || $prepay === true || $prepay === 1) ? 'Sim' : 'Não';

        // Funds Yes/No
        $guaranteeFund = (string) $get('guarantee_fund');
        $expenseFund = (string) $get('expense_fund');
        $liquidityFund = (string) $get('liquidity_fund');
        $reserveFund = (string) $get('reserve_fund');

        // Clauses in Lastro, garantias e operação
        $clauses = [];
        $clauseDefs = [
            ['field' => 'destination_of_funds', 'label' => 'Destinação dos Recursos'],
            ['field' => 'subscription_conditions', 'label' => 'Condições de Subscrição e Integralização'],
            ['field' => 'repurchase_conditions', 'label' => 'Repactuação'],
            ['field' => 'amortization_schedule', 'label' => 'Calendário de Amortização'],
            ['field' => 'remuneration_schedule', 'label' => 'Calendário de Remuneração'],
            ['field' => 'early_redemption_conditions', 'label' => 'Resgate Antecipado Facultativo'],
            ['field' => 'early_amortization_conditions', 'label' => 'Amortização Antecipada'],
            ['field' => 'remuneration_calculation', 'label' => 'Cálculo da Remuneração'],
            ['field' => 'penalty_conditions', 'label' => 'Multa e Juros Moratórios'],
            ['field' => 'underlying_asset', 'label' => 'Descrição do Lastro'],
            ['field' => 'covenant_conditions', 'label' => 'Obrigações Adicionais (Covenants)'],
            ['field' => 'cross_default_conditions', 'label' => 'Vencimento Antecipado'],
            ['field' => 'operational_structure', 'label' => 'Estrutura da Operação'],
        ];

        foreach ($clauseDefs as $cd) {
            $val = $get($cd['field']);
            if (filled($val)) {
                $clauses[] = [
                    'label' => $cd['label'],
                    'value' => (string) $val,
                ];
            }
        }

        // Remuneration summary string
        $indexer = (string) ($get('remuneration_indexer') ?: $get('indexer') ?: '');
        $rate = (string) ($get('remuneration_rate') ?: $get('spread_rate') ?: '');
        $remunerationSummary = trim($indexer.(filled($rate) ? ' + '.$rate.'%' : ''));

        // Series and Emission number combined
        $series = $get('series');
        $emNumber = $get('emission_number');
        $seriesNumber = trim(($series ? $series : '').($series && $emNumber ? ' / ' : '').($emNumber ? $emNumber : ''));

        $stepsValidity = [
            'dados_basicos' => filled($get('name')) && filled($get('type')),
            'empreendimentos' => ! $isCreate || $constructionsAreComplete,
            'participantes' => filled($get('issuer')),
            'caracteristicas_financeiras' => filled($get('issue_date')) || filled($get('maturity_date')),
            'valores_remuneracao' => filled($get('issued_volume')) || filled($get('issued_price')) || filled($get('remuneration_indexer')),
            'lastro_garantias' => true,
            'documentos' => true,
        ];

        return [
            'is_create' => $isCreate,
            'steps_indexes' => $stepsIndexes,
            'steps_validity' => $stepsValidity,
            'summary' => [
                'name' => (string) $get('name'),
                'type' => (string) $get('type'),
                'issued_volume' => (string) $get('issued_volume'),
                'issuer' => (string) $get('issuer'),
                'status_label' => $statusLabel,
                'issue_date' => self::formatDateForDisplay($get('issue_date')),
                'maturity_date' => self::formatDateForDisplay($get('maturity_date')),
                'remuneration_summary' => $remunerationSummary,
            ],
            'dados_basicos' => [
                'name' => (string) $get('name'),
                'type' => (string) $get('type'),
                'status_label' => $statusLabel,
                'registered_with_cvm_label' => $cvmLabel,
                'issuer_situation_label' => $issuerSitLabel,
                'if_code' => (string) $get('if_code'),
                'isin_code' => (string) $get('isin_code'),
                'bsi_code' => (string) $get('bsi_code'),
            ],
            'constructions' => $constructions,
            'participantes' => [
                'issuer' => (string) $get('issuer'),
                'lead_coordinator' => (string) $get('lead_coordinator'),
                'settlement_bank' => (string) $get('settlement_bank'),
                'registrar' => (string) $get('registrar'),
                'distributor' => (string) $get('distributor'),
                'trustee_agent' => (string) $get('trustee_agent'),
                'debtor' => (string) $get('debtor'),
                'law_firm' => (string) $get('law_firm'),
            ],
            'caracteristicas_financeiras' => [
                'issue_date' => self::formatDateForDisplay($get('issue_date')),
                'maturity_date' => self::formatDateForDisplay($get('maturity_date')),
                'series_number' => $seriesNumber,
                'fiduciary_regime_label' => $fidRegimeLabel,
                'form_type' => (string) $get('form_type'),
                'monetary_update_period' => (string) $get('monetary_update_period'),
                'interest_payment_frequency' => (string) $get('interest_payment_frequency'),
                'amortization_frequency' => (string) $get('amortization_frequency'),
                'concentration' => (string) $get('concentration'),
                'prepayment_possibility_label' => $prepayLabel,
                'segment' => (string) $get('segment'),
                'target_audience' => (string) $get('target_audience'),
            ],
            'valores_remuneracao' => [
                'offer_type' => (string) $get('offer_type'),
                'remuneration_indexer' => (string) $get('remuneration_indexer'),
                'remuneration_rate' => (string) $get('remuneration_rate'),
                'issued_price' => (string) $get('issued_price'),
                'issued_quantity' => (string) $get('issued_quantity'),
                'integralized_quantity' => (string) $get('integralized_quantity'),
                'remaining_quantity' => (string) $get('remaining_quantity'),
                'issued_volume' => (string) $get('issued_volume'),
            ],
            'lastro_garantias' => [
                'guarantee_fund' => $guaranteeFund === '1' ? 'Sim' : ($guaranteeFund === '0' ? 'Não' : ($guaranteeFund ?: 'Não')),
                'expense_fund' => $expenseFund === '1' ? 'Sim' : ($expenseFund === '0' ? 'Não' : ($expenseFund ?: 'Não')),
                'liquidity_fund' => $liquidityFund === '1' ? 'Sim' : ($liquidityFund === '0' ? 'Não' : ($liquidityFund ?: 'Não')),
                'reserve_fund' => $reserveFund === '1' ? 'Sim' : ($reserveFund === '0' ? 'Não' : ($reserveFund ?: 'Não')),
                'clauses' => $clauses,
            ],
            'documentos' => [
                'risk_rating' => (string) $get('risk_rating'),
                'public_trading_code' => (string) $get('public_trading_code'),
                'trading_environment' => (string) $get('trading_environment'),
                'description' => (string) $get('description'),
            ],
        ];
    }

    /**
     * Summarizes the mandatory constructions step. The block is omitted
     * entirely outside the creation wizard, where that step is not rendered.
     */
    private static function summarizeInitialConstructions(Get $get, string $operation): string
    {
        if ($operation !== 'create') {
            return '';
        }

        $constructions = array_values((array) $get(EmissionConstructionsStep::STATE_PATH));

        if ($constructions === []) {
            return '<b>Empreendimentos:</b> <span class="text-danger-500">Nenhum empreendimento cadastrado</span><br>';
        }

        $lines = array_map(static function (array $construction): string {
            $developmentName = $construction['development_name'] ?? null;
            $referenceMonth = $construction[EmissionConstructionsStep::SALES_BOARD_STATE_PATH]['reference_month'] ?? null;

            if (blank($developmentName) || blank($referenceMonth)) {
                return '&nbsp;&nbsp;• <span class="text-danger-500">Empreendimento incompleto</span>';
            }

            return '&nbsp;&nbsp;• '.e($developmentName)
                .' — Quadro de Vendas '.e(SalesBoard::formatReferenceMonthForDisplay($referenceMonth));
        }, $constructions);

        return '<b>Empreendimentos:</b><br>'.implode('<br>', $lines).'<br>';
    }

    /**
     * Keeps the read-only "Volume Total Emitido" field in sync with its factors.
     */
    private static function syncIssuedVolume(Get $get, Set $set): null
    {
        $issuedVolume = self::calculateIssuedVolume($get);

        if ($issuedVolume !== null) {
            $set('issued_volume', number_format($issuedVolume, 2, ',', '.'));
        }

        return null;
    }

    /**
     * Volume Total Emitido = Quantidade Emitida x Preço Unitário de Emissão (PU).
     *
     * Returns null when either factor is missing so that a volume already
     * stored on the emission is never overwritten by an incomplete form.
     */
    private static function calculateIssuedVolume(Get $get): ?float
    {
        $issuedQuantity = $get('issued_quantity');
        $issuedPrice = $get('issued_price');

        if (blank($issuedQuantity) || blank($issuedPrice)) {
            return null;
        }

        return round(
            self::normalizeQuantityValue($issuedQuantity) * MoneyFormatter::normalizeDecimalValue($issuedPrice),
            2,
        );
    }

    private static function syncRemainingQuantity(Get $get, Set $set): null
    {
        $set(
            'remaining_quantity',
            self::formatQuantityForDisplay(
                self::calculateRemainingQuantity($get('issued_quantity'), $get('integralized_quantity')),
            ),
        );

        return null;
    }

    private static function calculateRemainingQuantity(mixed $issuedQuantity, mixed $integralizedQuantity): int
    {
        return max(
            0,
            self::normalizeQuantityValue($issuedQuantity) - self::normalizeQuantityValue($integralizedQuantity),
        );
    }

    private static function normalizeQuantityValue(mixed $value): int
    {
        if (blank($value)) {
            return 0;
        }

        if (is_int($value) || is_float($value)) {
            return (int) round($value);
        }

        return (int) str_replace(['.', ',', ' '], '', (string) $value);
    }

    public static function formatQuantityForDisplay(mixed $value): string
    {
        return number_format((float) self::normalizeQuantityValue($value), 0, ',', '.');
    }

    public static function formatDateForDisplay(mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        try {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('d/m/Y');
            }

            return Carbon::parse((string) $value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * Segment selector following the same "pick one or create it inline"
     * behaviour of the Participantes fields.
     */
    private static function segmentField(): Select
    {
        return Select::make('segment')
            ->label('Segmento de Atuação')
            ->options(fn (): array => self::getSegmentOptions())
            ->searchable()
            ->preload()
            ->getSearchResultsUsing(
                fn (string $search): array => self::getSegmentOptions($search),
            )
            ->getOptionLabelUsing(
                fn (mixed $value): ?string => filled($value) ? (string) $value : null,
            )
            ->createOptionForm([
                TextInput::make('segment')
                    ->label('Segmento de Atuação')
                    ->required()
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'Informe o segmento de atuação.',
                    ]),
            ])
            ->createOptionUsing(
                fn (array $data): string => trim((string) $data['segment']),
            )
            ->createOptionAction(
                fn (Action $action): Action => $action
                    ->label('Cadastrar Segmento')
                    ->modalHeading('Cadastrar Segmento de Atuação'),
            )
            ->placeholder('Selecione ou cadastre um segmento');
    }

    /**
     * Segments already in use by other emissions.
     *
     * The column is free text with no catalog table behind it, so the option
     * list is derived from the values already registered.
     *
     * @return array<string, string>
     */
    private static function getSegmentOptions(?string $search = null): array
    {
        return Emission::query()
            ->whereNotNull('segment')
            ->where('segment', '<>', '')
            ->when(
                filled($search),
                fn ($query): mixed => $query->where('segment', 'like', '%'.trim((string) $search).'%'),
            )
            ->distinct()
            ->orderBy('segment')
            ->pluck('segment', 'segment')
            ->all();
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
                    ->label('Cadastrar Prestador')
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
