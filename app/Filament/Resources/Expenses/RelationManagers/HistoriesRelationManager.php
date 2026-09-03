<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';

    protected static ?string $title = 'Histórico de pagamentos';

    protected static ?string $modelLabel = 'Histórico';

    protected static ?string $pluralModelLabel = 'Histórico de pagamentos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('due_date')
            ->heading('Histórico de pagamentos')
            ->description('Visualização dos pagamentos registrados para esta despesa.')
            ->columns([
                TextColumn::make('due_date')
                    ->label('Data de vencimento')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('payment_date')
                    ->label('Data do pagamento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color('success')
                    ->state(fn (): string => 'Pago'),

                TextColumn::make('amount')
                    ->label('Valor pago')
                    ->money('BRL')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('conta_azul_bill_id')
                    ->label('ID Conta Azul')
                    ->color('gray')
                    ->copyable(),
            ])
            ->defaultSort('due_date', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('Nenhum histórico de pagamento registrado')
            ->emptyStateDescription('Os pagamentos registrados para esta despesa aparecerão aqui.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
