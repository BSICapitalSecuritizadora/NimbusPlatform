<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GuaranteesRelationManager extends RelationManager
{
    protected static string $relationship = 'guarantees';

    protected static ?string $recordTitleAttribute = 'guarantee_type';

    protected static ?string $title = 'Garantias';

    protected static ?string $modelLabel = 'Garantia';

    protected static ?string $pluralModelLabel = 'Garantias';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('guarantee_type')
                    ->label('Tipo de garantia')
                    ->required()
                    ->maxLength(255),
                TextInput::make('minimum_value')
                    ->label('Valor Mínimo')
                    ->prefix('R$')
                    ->numeric()
                    ->required(),
                DatePicker::make('validity_start_date')
                    ->label('Início de Validade da Garantia')
                    ->required(),
                DatePicker::make('validity_end_date')
                    ->label('Fim de Validade da Garantia')
                    ->required(),
                TextInput::make('evaluation_frequency')
                    ->label('Periodicidade de Avaliação')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(4)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('guarantee_type')
            ->columns([
                TextColumn::make('guarantee_type')
                    ->label('Tipo de garantia')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('minimum_value')
                    ->label('Valor Mínimo')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('validity_start_date')
                    ->label('Início de Validade da Garantia')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('validity_end_date')
                    ->label('Fim de Validade da Garantia')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('evaluation_frequency')
                    ->label('Periodicidade de Avaliação')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(50)
                    ->wrap(),
            ])
            ->defaultSort('validity_start_date', 'desc')
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nenhuma garantia registrada');
    }
}
