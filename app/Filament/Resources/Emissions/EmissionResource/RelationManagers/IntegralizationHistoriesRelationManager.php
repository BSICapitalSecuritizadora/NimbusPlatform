<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegralizationHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'integralizationHistories';

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $title = 'Histórico de Integralizações';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('date')
                    ->label('Data')
                    ->required(),
                TextInput::make('quantity')
                    ->label('Quantidade')
                    ->numeric()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric(0, ',', '.')
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
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
            ->emptyStateHeading('Nenhuma integralização registrada');
    }
}
