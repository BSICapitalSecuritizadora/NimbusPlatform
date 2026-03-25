<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Filament\Resources\Proposals\Pages\ViewProposal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectRelationManager extends RelationManager
{
    protected static string $relationship = 'project';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Dados do Empreendimento';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Características Técnicas (Obra)')
                    ->relationship('characteristics')
                    ->schema([
                        TextInput::make('blocks')
                            ->label('Qtd. de Blocos')
                            ->numeric(),
                        TextInput::make('floors')
                            ->label('Qtd. de Pavimentos')
                            ->numeric(),
                        TextInput::make('typical_floors')
                            ->label('Qtd. de Andares Tipo')
                            ->numeric(),
                        TextInput::make('units_per_floor')
                            ->label('Unidades por Andar')
                            ->numeric(),
                        TextInput::make('total_units')
                            ->label('Total de Unidades')
                            ->numeric(),

                        Repeater::make('unitTypes')
                            ->relationship('unitTypes')
                            ->label('Tipos de Unidades')
                            ->schema([
                                TextInput::make('order')
                                    ->label('Ordem')
                                    ->numeric()
                                    ->default(1),
                                TextInput::make('bedrooms')
                                    ->label('Dormitórios (Ex: 3 Suítes)')
                                    ->maxLength(255),
                                TextInput::make('parking_spaces')
                                    ->label('Vagas (Ex: 2 Vagas)')
                                    ->maxLength(255),
                                TextInput::make('useful_area')
                                    ->label('Área Útil (m²)')
                                    ->numeric(),
                                TextInput::make('total_units')
                                    ->label('Total de Unidades deste Tipo')
                                    ->numeric(),
                                TextInput::make('average_price')
                                    ->label('Preço Médio')
                                    ->numeric()
                                    ->prefix('R$'),
                                TextInput::make('price_per_m2')
                                    ->label('Preço m²')
                                    ->numeric()
                                    ->prefix('R$'),
                            ])
                            ->columns(3)
                            ->columnSpanFull()
                            ->itemLabel(fn (array $state): ?string => ($state['bedrooms'] ?? null) ? "Unidade: {$state['bedrooms']}" : null),
                    ])->columns(2),

                Section::make('Informações Gerais')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do Empreendimento')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('company_name')
                            ->label('Razão Social (SPE)')
                            ->maxLength(255),
                        TextInput::make('site')
                            ->label('Site')
                            ->url()
                            ->maxLength(255),
                        DatePicker::make('launch_date')
                            ->label('Data de Lançamento'),
                    ])->columns(2),

                Section::make('Localização')
                    ->schema([
                        TextInput::make('cep')
                            ->label('CEP')
                            ->maxLength(9),
                        TextInput::make('logradouro')
                            ->label('Logradouro')
                            ->maxLength(255),
                        TextInput::make('numero')
                            ->label('Número')
                            ->maxLength(50),
                        TextInput::make('complemento')
                            ->label('Complemento')
                            ->maxLength(255),
                        TextInput::make('bairro')
                            ->label('Bairro')
                            ->maxLength(255),
                        TextInput::make('cidade')
                            ->label('Cidade')
                            ->maxLength(255),
                        TextInput::make('estado')
                            ->label('Estado')
                            ->maxLength(2),
                    ])->columns(3),

                Section::make('Dados Financeiros & Área')
                    ->schema([
                        TextInput::make('value_requested')
                            ->label('Valor Solicitado')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('land_market_value')
                            ->label('Valor de Mercado do Terreno')
                            ->numeric()
                            ->default(0)
                            ->prefix('R$'),
                        TextInput::make('land_area')
                            ->label('Área do Terreno (m²)')
                            ->numeric()
                            ->default(0),
                        TextInput::make('work_stage_percentage')
                            ->label('Estágio da Obra (%)')
                            ->numeric()
                            ->default(0)
                            ->suffix('%'),
                    ])->columns(2),

                Section::make('Unidades e Vendas')
                    ->schema([
                        TextInput::make('units_total')
                            ->label('Total de Unidades')
                            ->numeric()
                            ->default(0),
                        TextInput::make('units_exchanged')
                            ->label('Unidades Permutadas')
                            ->numeric()
                            ->default(0),
                        TextInput::make('units_paid')
                            ->label('Unidades Quitadas')
                            ->numeric()
                            ->default(0),
                        TextInput::make('units_unpaid')
                            ->label('Unidades Não Quitadas')
                            ->numeric()
                            ->default(0),
                        TextInput::make('units_stock')
                            ->label('Unidades em Estoque')
                            ->numeric()
                            ->default(0),
                        TextInput::make('sales_percentage')
                            ->label('Percentual de Vendas (%)')
                            ->numeric()
                            ->default(0)
                            ->suffix('%'),
                    ])->columns(3),

                Section::make('Custos')
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
                    ])->columns(3),

                Section::make('Valores de Venda')
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
                    ])->columns(3),

                Section::make('Indicadores Avançados (Thresholds)')
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
}
