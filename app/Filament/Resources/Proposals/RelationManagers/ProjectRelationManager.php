<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Filament\Resources\Proposals\Pages\ViewProposal;
use Filament\Forms;
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
                Forms\Components\Section::make('Características Técnicas (Obra)')
                    ->relationship('characteristics')
                    ->schema([
                        Forms\Components\TextInput::make('blocks')
                            ->label('Qtd. de Blocos')
                            ->numeric(),
                        Forms\Components\TextInput::make('floors')
                            ->label('Qtd. de Pavimentos')
                            ->numeric(),
                        Forms\Components\TextInput::make('typical_floors')
                            ->label('Qtd. de Andares Tipo')
                            ->numeric(),
                        Forms\Components\TextInput::make('units_per_floor')
                            ->label('Unidades por Andar')
                            ->numeric(),
                        Forms\Components\TextInput::make('total_units')
                            ->label('Total de Unidades')
                            ->numeric(),

                        Forms\Components\Repeater::make('unitTypes')
                            ->relationship('unitTypes')
                            ->label('Tipos de Unidades')
                            ->schema([
                                Forms\Components\TextInput::make('order')
                                    ->label('Ordem')
                                    ->numeric()
                                    ->default(1),
                                Forms\Components\TextInput::make('bedrooms')
                                    ->label('Dormitórios (Ex: 3 Suítes)')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('parking_spaces')
                                    ->label('Vagas (Ex: 2 Vagas)')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('useful_area')
                                    ->label('Área Útil (m²)')
                                    ->numeric(),
                                Forms\Components\TextInput::make('total_units')
                                    ->label('Total de Unidades deste Tipo')
                                    ->numeric(),
                                Forms\Components\TextInput::make('average_price')
                                    ->label('Preço Médio')
                                    ->numeric()
                                    ->prefix('R$'),
                                Forms\Components\TextInput::make('price_per_m2')
                                    ->label('Preço m²')
                                    ->numeric()
                                    ->prefix('R$'),
                            ])
                            ->columns(3)
                            ->columnSpanFull()
                            ->itemLabel(fn (array $state): ?string => ($state['bedrooms'] ?? null) ? "Unidade: {$state['bedrooms']}" : null),
                    ])->columns(2),

                Forms\Components\Section::make('Informações Gerais')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome do Empreendimento')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('company_name')
                            ->label('Razão Social (SPE)')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('site')
                            ->label('Site')
                            ->url()
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('launch_date')
                            ->label('Data de Lançamento'),
                    ])->columns(2),

                Forms\Components\Section::make('Localização')
                    ->schema([
                        Forms\Components\TextInput::make('cep')
                            ->label('CEP')
                            ->maxLength(9),
                        Forms\Components\TextInput::make('logradouro')
                            ->label('Logradouro')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('numero')
                            ->label('Número')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('complemento')
                            ->label('Complemento')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('bairro')
                            ->label('Bairro')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cidade')
                            ->label('Cidade')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('estado')
                            ->label('Estado')
                            ->maxLength(2),
                    ])->columns(3),

                Forms\Components\Section::make('Dados Financeiros & Área')
                    ->schema([
                        Forms\Components\TextInput::make('value_requested')
                            ->label('Valor Solicitado')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('land_market_value')
                            ->label('Valor de Mercado do Terreno')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('land_area')
                            ->label('Área do Terreno (m²)')
                            ->numeric(),
                        Forms\Components\TextInput::make('work_stage_percentage')
                            ->label('Estágio da Obra (%)')
                            ->numeric()
                            ->suffix('%'),
                    ])->columns(2),

                Forms\Components\Section::make('Unidades e Vendas')
                    ->schema([
                        Forms\Components\TextInput::make('units_total')
                            ->label('Total de Unidades')
                            ->numeric(),
                        Forms\Components\TextInput::make('units_exchanged')
                            ->label('Unidades Permutadas')
                            ->numeric(),
                        Forms\Components\TextInput::make('units_paid')
                            ->label('Unidades Quitadas')
                            ->numeric(),
                        Forms\Components\TextInput::make('units_unpaid')
                            ->label('Unidades Não Quitadas')
                            ->numeric(),
                        Forms\Components\TextInput::make('units_stock')
                            ->label('Unidades em Estoque')
                            ->numeric(),
                        Forms\Components\TextInput::make('sales_percentage')
                            ->label('Percentual de Vendas (%)')
                            ->numeric()
                            ->suffix('%'),
                    ])->columns(3),

                Forms\Components\Section::make('Custos')
                    ->schema([
                        Forms\Components\TextInput::make('cost_incurred')
                            ->label('Custo Incidido')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('cost_to_incur')
                            ->label('Custo a Incorrer')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('cost_total')
                            ->label('Custo Total')
                            ->numeric()
                            ->prefix('R$'),
                    ])->columns(3),

                Forms\Components\Section::make('Valores de Venda')
                    ->schema([
                        Forms\Components\TextInput::make('value_total_sale')
                            ->label('VGV Total')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('value_paid')
                            ->label('Valor Unidades Quitadas')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('value_unpaid')
                            ->label('Valor Unidades Não Quitadas')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('value_stock')
                            ->label('Valor em Estoque')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('value_received')
                            ->label('Valor já Recebido')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('value_until_keys')
                            ->label('A receber até as chaves')
                            ->numeric()
                            ->prefix('R$'),
                        Forms\Components\TextInput::make('value_post_keys')
                            ->label('A receber pós chaves')
                            ->numeric()
                            ->prefix('R$'),
                    ])->columns(3),
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
                Tables\Actions\CreateAction::make()
                    ->label('Adicionar Projeto'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
