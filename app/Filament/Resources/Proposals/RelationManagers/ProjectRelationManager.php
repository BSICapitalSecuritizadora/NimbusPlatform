<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Filament\Resources\Proposals\Pages\ViewProposal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Http;

class ProjectRelationManager extends RelationManager
{
    protected static string $relationship = 'project';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Dados do Empreendimento';

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
                            ->content(fn (Get $get) => (int) $get('remaining_months') . ' meses'),
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
                                if (!$state) return;
                                
                                $cep = preg_replace('/[^0-9]/', '', $state);
                                if (strlen($cep) !== 8) return;

                                try {
                                    $response = Http::get("https://viacep.com.br/ws/{$cep}/json/");
                                    
                                    if ($response->ok() && !isset($response->json()['erro'])) {
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
                                    ->numeric(),
                                TextInput::make('average_price')
                                    ->label('Preço Médio')
                                    ->columnSpan(3)
                                    ->numeric()
                                    ->prefix('R$'),
                                TextInput::make('price_per_m2')
                                    ->label('Preço m²')
                                    ->columnSpan(3)
                                    ->numeric()
                                    ->prefix('R$'),
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
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateSalesCalculations($get, $set)),
                        TextInput::make('units_paid')
                            ->label('Quitadas')
                            ->numeric()
                            ->default(0)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateSalesCalculations($get, $set)),
                        TextInput::make('units_exchanged')
                            ->label('Permutadas')
                            ->numeric()
                            ->default(0)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateSalesCalculations($get, $set)),
                        TextInput::make('units_stock')
                            ->label('Estoque')
                            ->numeric()
                            ->default(0)
                            ->live()
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
                            ->label('Custo Incidido')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('cost_to_incur')
                            ->label('Custo a Incorrer')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('cost_total')
                            ->label('Custo Total')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('work_stage_percentage')
                            ->label('Estágio da Obra (%)')
                            ->numeric()
                            ->default(0)
                            ->suffix('%'),
                    ])->columns(4)->collapsed(),

                Section::make('Valores de Venda')
                    ->icon('heroicon-o-currency-dollar')
                    ->schema([
                        TextInput::make('value_total_sale')
                            ->label('VGV Total')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('value_paid')
                            ->label('Valor Unidades Quitadas')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('value_unpaid')
                            ->label('Valor Unidades Não Quitadas')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('value_stock')
                            ->label('Valor em Estoque')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('value_received')
                            ->label('Valor já Recebido')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('value_until_keys')
                            ->label('A receber até as chaves')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('value_post_keys')
                            ->label('A receber pós chaves')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                    ])->columns(3)->collapsed(),

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
                    ->label('Projeto'),
                Tables\Columns\TextColumn::make('value_requested')
                    ->label('Vlr. Solicitado')
                    ->money('BRL'),
                Tables\Columns\TextColumn::make('work_stage_percentage')
                    ->label('Obra')
                    ->suffix('%'),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Adicionar Projeto'),
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

    public static function updateSalesCalculations(Get $get, Set $set): void
    {
        $unpaid = (int) $get('units_unpaid');
        $paid = (int) $get('units_paid');
        $exchanged = (int) $get('units_exchanged');
        $stock = (int) $get('units_stock');

        $total = $unpaid + $paid + $exchanged + $stock;
        $set('units_total', $total);

        if ($total > 0) {
            $percentage = (($unpaid + $paid) / $total) * 100;
            $set('sales_percentage', round($percentage, 2));
        } else {
            $set('sales_percentage', 0);
        }
    }
}
