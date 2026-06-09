<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagamentos';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('pay_date')
            ->columns([
                TextColumn::make('pay_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('planSet.construction.development_name')
                    ->label('Empreendimento')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('measurement.filename')
                    ->label('Medição')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Método')
                    ->placeholder('—'),
                IconColumn::make('receipt_path')
                    ->label('Comprovante')
                    ->boolean()
                    ->state(fn ($record): bool => filled($record->receipt_path)),
            ])
            ->defaultSort('pay_date', 'desc');
    }
}
