<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Models\ProposalProject;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;

class ProjectRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Empreendimentos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações Gerais')
                    ->icon('heroicon-o-information-circle')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do Empreendimento')
                            ->required()
                            ->columnSpan(2)
                            ->maxLength(255),
                        TextInput::make('site')
                            ->label('Site')
                            ->url()
                            ->maxLength(255),
                        TextInput::make('value_requested')
                            ->label('Valor Solicitado')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->prefix('R$'),
                    ])->columns(2),

                Section::make('Detalhes do Terreno & Lançamento')
                    ->icon('heroicon-o-map')
                    ->schema([
                        TextInput::make('land_market_value')
                            ->label('Valor atual de mercado do terreno')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$')
                            ->columnSpan(2),
                        TextInput::make('land_area')
                            ->label('Área do Terreno (m²)')
                            ->numeric()
                            ->required()
                            ->default(0),
                        DatePicker::make('launch_date')
                            ->label('Data de lançamento')
                            ->required(),
                    ])->columns(2),

                Section::make('Cronograma')
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        DatePicker::make('sales_launch_date')
                            ->label('Lançamento das Vendas')
                            ->required(),
                        DatePicker::make('construction_start_date')
                            ->label('Início das Obras')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateRemainingMonths($get, $set)),
                        DatePicker::make('delivery_forecast_date')
                            ->label('Previsão de Entrega')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateRemainingMonths($get, $set)),
                        Placeholder::make('remaining_months_display')
                            ->label('Prazo Remanescente')
                            ->columnSpan(2)
                            ->content(fn (Get $get) => (int) $get('remaining_months').' meses'),
                        Hidden::make('remaining_months')
                            ->default(0),
                    ])->columns(2),

                Section::make('Localização')
                    ->icon('heroicon-o-map-pin')
                    ->schema([
                        TextInput::make('cep')
                            ->label('CEP')
                            ->required()
                            ->maxLength(9)
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                if (! $state) {
                                    return;
                                }

                                $cep = preg_replace('/[^0-9]/', '', $state);
                                if (strlen($cep) !== 8) {
                                    return;
                                }

                                try {
                                    $response = Http::get("https://viacep.com.br/ws/{$cep}/json/");

                                    if ($response->ok() && ! isset($response->json()['erro'])) {
                                        $data = $response->json();
                                        $set('logradouro', $data['logradouro'] ?? '');
                                        $set('bairro', $data['bairro'] ?? '');
                                        $set('cidade', $data['localidade'] ?? '');
                                        $set('estado', $data['uf'] ?? '');
                                    }
                                } catch (\Exception $e) {
                                    // Fail silently
                                }
                            }),
                        TextInput::make('logradouro')
                            ->label('Rua')
                            ->columnSpan(2)
                            ->maxLength(255),
                        TextInput::make('complemento')
                            ->label('Complemento')
                            ->maxLength(255),
                        TextInput::make('numero')
                            ->label('Número')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('bairro')
                            ->label('Bairro')
                            ->maxLength(255),
                        TextInput::make('cidade')
                            ->label('Cidade')
                            ->columnSpan(2)
                            ->maxLength(255),
                        TextInput::make('estado')
                            ->label('Estado')
                            ->maxLength(2),
                    ])->columns(3),

                Section::make('Características Técnicas (Obra)')
                    ->icon('heroicon-o-home-modern')
                    ->relationship('characteristics')
                    ->schema([
                        TextInput::make('blocks')
                            ->label('Quantidade de Blocos')
                            ->numeric(),
                        TextInput::make('typical_floors')
                            ->label('Quantidade de Andares')
                            ->numeric(),
                        TextInput::make('units_per_floor')
                            ->label('Unidades por Andar')
                            ->numeric(),
                        TextInput::make('total_units')
                            ->label('Total de Unidades')
                            ->numeric()
                            ->default(0)
                            ->readOnly()
                            ->extraAttributes(['style' => 'cursor: not-allowed;'])
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (Get $get) => self::calculateTechnicalTotalUnits($get('unitTypes'))),

                        Repeater::make('unitTypes')
                            ->relationship('unitTypes')
                            ->label('Tipos de Unidades')
                            ->schema([
                                TextInput::make('order')
                                    ->label('Ordem')
                                    ->numeric()
                                    ->default(1),
                                TextInput::make('bedrooms')
                                    ->label('Dormitórios')
                                    ->maxLength(255),
                                TextInput::make('parking_spaces')
                                    ->label('Garagem')
                                    ->maxLength(255),
                                TextInput::make('total_units')
                                    ->label('Unidades')
                                    ->numeric()
                                    ->live(),
                                TextInput::make('useful_area')
                                    ->label('Área Útil (m²)')
                                    ->columnSpan(2)
                                    ->numeric()
                                    ->live()
                                    ->afterStateHydrated(fn (Get $get, Set $set) => self::updateUnitTypePricePerM2($get, $set))
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateUnitTypePricePerM2($get, $set)),
                                TextInput::make('average_price')
                                    ->label('Preço Médio')
                                    ->columnSpan(3)
                                    ->prefix('R$')
                                    ->inputMode('decimal')
                                    ->live(onBlur: true)
                                    ->mask(RawJs::make(<<<'JS'
                                        $money($input, ',', '.')
                                    JS))
                                    ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
                                    ->dehydrateStateUsing(fn ($state): ?float => self::normalizeDecimalValue($state))
                                    ->mutateStateForValidationUsing(fn ($state): ?float => self::normalizeDecimalValue($state))
                                    ->afterStateHydrated(fn (Get $get, Set $set) => self::syncAveragePriceField($get, $set))
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncAveragePriceField($get, $set)),
                                TextInput::make('price_per_m2')
                                    ->label('Preço m²')
                                    ->columnSpan(3)
                                    ->prefix('R$')
                                    ->readOnly()
                                    ->extraAttributes(['style' => 'cursor: not-allowed;'])
                                    ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
                                    ->dehydrateStateUsing(fn (Get $get): ?float => self::calculateUnitTypePricePerM2(
                                        $get('average_price'),
                                        $get('useful_area'),
                                    )),
                            ])
                            ->columns(3)
                            ->columnSpanFull()
                            ->itemLabel(fn (array $state): ?string => ($state['bedrooms'] ?? null) ? "Unidade: {$state['bedrooms']}" : null)
                            ->afterStateHydrated(fn (?array $state, Set $set) => self::updateTechnicalTotalUnits($state, $set))
                            ->afterStateUpdated(fn (?array $state, Set $set) => self::updateTechnicalTotalUnits($state, $set)),
                    ])->columns(2)->collapsed(),

                Section::make('Quadro de Vendas')
                    ->icon('heroicon-o-shopping-cart')
                    ->schema([
                        TextInput::make('units_unpaid')
                            ->label('Vendidas')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateSalesCalculations($get, $set)),
                        TextInput::make('units_paid')
                            ->label('Quitadas')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateSalesCalculations($get, $set)),
                        TextInput::make('units_exchanged')
                            ->label('Permutadas')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateSalesCalculations($get, $set)),
                        TextInput::make('units_stock')
                            ->label('Estoque')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateSalesCalculations($get, $set)),
                        TextInput::make('units_total')
                            ->label('Total')
                            ->numeric()
                            ->default(0)
                            ->readOnly()
                            ->extraAttributes(['style' => 'cursor: not-allowed;'])
                            ->dehydrated(),
                        TextInput::make('sales_percentage')
                            ->label('Vendas (%)')
                            ->numeric()
                            ->default(0)
                            ->suffix('%')
                            ->readOnly()
                            ->extraAttributes(['style' => 'cursor: not-allowed;'])
                            ->dehydrated(),
                    ])->columns(2)->collapsed(),

                Section::make('Custos')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        TextInput::make('cost_incurred')
                            ->label('Custo Incorrido')
                            ->columnSpan(2)
                            ->default(0)
                            ->prefix('R$')
                            ->inputMode('decimal')
                            ->live(onBlur: true)
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.')
                            JS))
                            ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
                            ->dehydrateStateUsing(fn ($state): ?float => self::normalizeDecimalValue($state))
                            ->mutateStateForValidationUsing(fn ($state): ?float => self::normalizeDecimalValue($state))
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncCostFields($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncCostFields($get, $set)),
                        TextInput::make('cost_to_incur')
                            ->label('Custo a Incorrer')
                            ->columnSpan(2)
                            ->default(0)
                            ->prefix('R$')
                            ->inputMode('decimal')
                            ->live(onBlur: true)
                            ->mask(RawJs::make(<<<'JS'
                                $money($input, ',', '.')
                            JS))
                            ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
                            ->dehydrateStateUsing(fn ($state): ?float => self::normalizeDecimalValue($state))
                            ->mutateStateForValidationUsing(fn ($state): ?float => self::normalizeDecimalValue($state))
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncCostFields($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncCostFields($get, $set)),
                        TextInput::make('cost_total')
                            ->label('Custo Total')
                            ->columnSpan(2)
                            ->default(0)
                            ->prefix('R$')
                            ->readOnly()
                            ->extraAttributes(['style' => 'cursor: not-allowed;'])
                            ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (Get $get): float => ProposalProject::calculateCostTotal(
                                $get('cost_incurred'),
                                $get('cost_to_incur'),
                            )),
                        TextInput::make('work_stage_percentage')
                            ->label('Estágio da Obra (%)')
                            ->columnSpan(2)
                            ->default(0)
                            ->readOnly()
                            ->extraAttributes(['style' => 'cursor: not-allowed;'])
                            ->suffix('%')
                            ->formatStateUsing(fn ($state): string => self::formatPercentageForDisplay($state))
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (Get $get): float => ProposalProject::calculateWorkStagePercentage(
                                $get('cost_incurred'),
                                ProposalProject::calculateCostTotal($get('cost_incurred'), $get('cost_to_incur')),
                            )),
                    ])->columns(4)->collapsed(),

                Section::make('Valores de Venda')
                    ->icon('heroicon-o-currency-dollar')
                    ->schema([
                        self::makeCurrencyField('value_paid', 'Quitadas')
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncSalesValuesTotal($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSalesValuesTotal($get, $set)),
                        self::makeCurrencyField('value_unpaid', 'Vendidas')
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncSalesValuesTotal($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSalesValuesTotal($get, $set)),
                        self::makeCurrencyField('value_stock', 'Estoque')
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncSalesValuesTotal($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSalesValuesTotal($get, $set)),
                        TextInput::make('value_total_sale')
                            ->label('VGV Total')
                            ->columnSpan(2)
                            ->default(0)
                            ->prefix('R$')
                            ->readOnly()
                            ->extraAttributes(['style' => 'cursor: not-allowed;'])
                            ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (Get $get): float => ProposalProject::calculateSalesValuesTotal(
                                $get('value_paid'),
                                $get('value_unpaid'),
                                $get('value_stock'),
                            )),
                    ])->columns(2)->collapsed(),

                Section::make('Fluxo de pagamento')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        self::makeCurrencyField('value_received', 'Valor já Recebido')
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncPaymentFlowTotal($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncPaymentFlowTotal($get, $set)),
                        self::makeCurrencyField('value_until_keys', 'A receber até as chaves')
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncPaymentFlowTotal($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncPaymentFlowTotal($get, $set)),
                        self::makeCurrencyField('value_post_keys', 'A receber pós chaves')
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::syncPaymentFlowTotal($get, $set))
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncPaymentFlowTotal($get, $set)),
                        TextInput::make('payment_flow_total')
                            ->label('Total')
                            ->columnSpan(2)
                            ->default(0)
                            ->prefix('R$')
                            ->readOnly()
                            ->extraAttributes(['style' => 'cursor: not-allowed;'])
                            ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
                            ->dehydrated(false),
                    ])->columns(2)->collapsed(),

                Section::make('Indicadores Avançados (Thresholds)')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->relationship('indicators')
                    ->schema([
                        TextInput::make('financiamento_custo_obra_ideal')->label('Financ/Custo Obra Ideal (%)')->numeric()->default(0),
                        TextInput::make('financiamento_custo_obra_limite')->label('Financ/Custo Obra Limite (%)')->numeric()->default(0),
                        TextInput::make('financiamento_vgv_ideal')->label('Financ/VGV Ideal (%)')->numeric()->default(0),
                        TextInput::make('financiamento_vgv_limite')->label('Financ/VGV Limite (%)')->numeric()->default(0),
                        TextInput::make('custo_obra_vgv_ideal')->label('Custo Obra/VGV Ideal (%)')->numeric()->default(0),
                        TextInput::make('custo_obra_vgv_limite')->label('Custo Obra/VGV Limite (%)')->numeric()->default(0),
                        TextInput::make('recebiveis_vfcto_ideal')->label('Rec/V fcto Ideal (%)')->numeric()->default(0),
                        TextInput::make('recebiveis_vfcto_limite')->label('Rec/V fcto Limite (%)')->numeric()->default(0),
                        TextInput::make('recebiveis_terreno_vfcto_ideal')->label('Rec+Terr/V fcto Ideal (%)')->numeric()->default(0),
                        TextInput::make('recebiveis_terreno_vfcto_limite')->label('Rec+Terr/V fcto Limite (%)')->numeric()->default(0),
                        TextInput::make('vendas_liquido_permutas_ideal')->label('% Vendas Liq. Ideal (%)')->numeric()->default(0),
                        TextInput::make('vendas_liquido_permutas_limite')->label('% Vendas Liq. Limite (%)')->numeric()->default(0),
                        TextInput::make('terreno_vgv_ideal')->label('Terreno/VGV Ideal (%)')->numeric()->default(0),
                        TextInput::make('terreno_vgv_limite')->label('Terreno/VGV Limite (%)')->numeric()->default(0),
                        TextInput::make('terreno_custo_obra_ideal')->label('Terreno/Custo Ideal (%)')->numeric()->default(0),
                        TextInput::make('terreno_custo_obra_limite')->label('Terreno/Custo Limite (%)')->numeric()->default(0),
                        TextInput::make('ltv_ideal')->label('LTV Ideal (%)')->numeric()->default(0),
                        TextInput::make('ltv_limite')->label('LTV Limite (%)')->numeric()->default(0),
                    ])->columns(2)->collapsed(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Empreendimento'),
                Tables\Columns\TextColumn::make('value_requested')
                    ->label('Vlr. Solicitado')
                    ->money('BRL'),
                Tables\Columns\TextColumn::make('work_stage_percentage')
                    ->label('Obra')
                    ->suffix('%'),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Adicionar Empreendimento'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('generateReport')
                    ->label('Relatório')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn ($record) => route('admin.projects.report', $record))
                    ->openUrlInNewTab(),
                \Filament\Actions\Action::make('analyticalReport')
                    ->label('Analítico')
                    ->icon('heroicon-o-chart-bar')
                    ->color('warning')
                    ->url(fn ($record) => route('admin.projects.analytical', $record))
                    ->openUrlInNewTab(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function updateRemainingMonths(Get $get, Set $set): void
    {
        try {
            $start = $get('construction_start_date');
            $end = $get('delivery_forecast_date');

            if ($start && $end) {
                $startDate = \Illuminate\Support\Carbon::parse($start);
                $endDate = \Illuminate\Support\Carbon::parse($end);

                // Only perform math if years look sane (at least 4 digits while typing)
                if ($startDate->year > 1000 && $endDate->year > 1000) {
                    $months = $startDate->diffInMonths($endDate);
                    $set('remaining_months', (int) abs($months));
                }
            }
        } catch (\Throwable $e) {
            // Silence all errors during live state updates to prevent 500s
        }
    }

    protected static function updateTechnicalTotalUnits(?array $unitTypes, Set $set): void
    {
        $set('total_units', self::calculateTechnicalTotalUnits($unitTypes));
    }

    protected static function calculateTechnicalTotalUnits(?array $unitTypes): int
    {
        return collect($unitTypes ?? [])
            ->sum(fn (array $unitType): int => (int) data_get($unitType, 'total_units', 0));
    }

    protected static function updateUnitTypePricePerM2(Get $get, Set $set): void
    {
        $set(
            'price_per_m2',
            self::formatCurrencyForDisplay(
                self::calculateUnitTypePricePerM2(
                    $get('average_price'),
                    $get('useful_area'),
                ),
            ),
        );
    }

    protected static function syncAveragePriceField(Get $get, Set $set): void
    {
        $averagePrice = self::normalizeDecimalValue($get('average_price'));

        $set('average_price', self::formatCurrencyForDisplay($averagePrice));

        $set(
            'price_per_m2',
            self::formatCurrencyForDisplay(
                self::calculateUnitTypePricePerM2(
                    $averagePrice,
                    $get('useful_area'),
                ),
            ),
        );
    }

    protected static function syncCostFields(Get $get, Set $set): void
    {
        $costTotal = ProposalProject::calculateCostTotal(
            $get('cost_incurred'),
            $get('cost_to_incur'),
        );

        $set('cost_total', self::formatCurrencyForDisplay($costTotal));
        $set(
            'work_stage_percentage',
            self::formatPercentageForDisplay(
                ProposalProject::calculateWorkStagePercentage($get('cost_incurred'), $costTotal),
            ),
        );
    }

    protected static function calculateUnitTypePricePerM2(mixed $averagePrice, mixed $usefulArea): ?float
    {
        $averagePrice = self::normalizeDecimalValue($averagePrice);
        $usefulArea = self::normalizeDecimalValue($usefulArea);

        if (($averagePrice === null) || ($usefulArea === null) || ($usefulArea <= 0)) {
            return null;
        }

        return round($averagePrice / $usefulArea, 2);
    }

    protected static function normalizeDecimalValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(['R$', ' '], '', $value);

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, '.')) {
            $parts = explode('.', $value);

            if ((count($parts) > 2) || (strlen(end($parts)) === 3)) {
                $value = str_replace('.', '', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } else {
            $value = str_replace(',', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    protected static function formatCurrencyForDisplay(mixed $value): ?string
    {
        $value = self::normalizeDecimalValue($value);

        if ($value === null) {
            return null;
        }

        return number_format($value, 2, ',', '.');
    }

    protected static function formatPercentageForDisplay(mixed $value): string
    {
        return number_format(self::normalizeDecimalValue($value) ?? 0, 2, ',', '.');
    }

    protected static function makeCurrencyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->columnSpan(2)
            ->default(0)
            ->prefix('R$')
            ->inputMode('decimal')
            ->live(onBlur: true)
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn ($state): ?string => self::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn ($state): ?float => self::normalizeDecimalValue($state))
            ->mutateStateForValidationUsing(fn ($state): ?float => self::normalizeDecimalValue($state));
    }

    protected static function syncPaymentFlowTotal(Get $get, Set $set): void
    {
        $set(
            'payment_flow_total',
            self::formatCurrencyForDisplay(ProposalProject::calculatePaymentFlowTotal(
                $get('value_received'),
                $get('value_until_keys'),
                $get('value_post_keys'),
            )),
        );
    }

    protected static function syncSalesValuesTotal(Get $get, Set $set): void
    {
        $set(
            'value_total_sale',
            self::formatCurrencyForDisplay(ProposalProject::calculateSalesValuesTotal(
                $get('value_paid'),
                $get('value_unpaid'),
                $get('value_stock'),
            )),
        );
    }

    public static function updateSalesCalculations(Get $get, Set $set): void
    {
        $unitsUnpaid = $get('units_unpaid');
        $unitsPaid = $get('units_paid');
        $unitsExchanged = $get('units_exchanged');
        $unitsStock = $get('units_stock');

        $set('units_total', ProposalProject::calculateUnitsTotal(
            $unitsUnpaid,
            $unitsPaid,
            $unitsExchanged,
            $unitsStock,
        ));
        $set('sales_percentage', ProposalProject::calculateSalesPercentage(
            $unitsUnpaid,
            $unitsPaid,
            $unitsExchanged,
            $unitsStock,
        ));
    }
}
