<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $recordTitleAttribute = 'payment_date';

    protected static ?string $title = 'Fluxo de Pagamentos';

    protected static ?string $modelLabel = 'Pagamento';
    protected static ?string $pluralModelLabel = 'Pagamentos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('payment_date')
                    ->label('Data do Pagamento')
                    ->required(),
                TextInput::make('premium_value')
                    ->label('Prêmio (R$)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('interest_value')
                    ->label('Juros (R$)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('amortization_value')
                    ->label('Amortização (R$)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('extra_amortization_value')
                    ->label('Amortização Extraordinária (R$)')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_date')
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('premium_value')
                    ->label('Prêmio')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('interest_value')
                    ->label('Juros')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('amortization_value')
                    ->label('Amortização')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('extra_amortization_value')
                    ->label('Amortização Extra')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
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
            ->emptyStateHeading('Nenhum pagamento registrado');
    }
}
