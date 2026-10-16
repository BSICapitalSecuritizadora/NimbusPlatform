<?php

namespace App\Filament\Resources\ContaAzulImportBatches\RelationManagers;

use App\Filament\Resources\ContaAzulImportedBills\ContaAzulImportedBillResource;
use App\Models\ContaAzulImportedBill;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Bills observed by this batch. Read-only: staging rows change only
 * through the ingestion pipeline and the governed attribution service.
 */
class ObservedBillsRelationManager extends RelationManager
{
    protected static string $relationship = 'observedBills';

    protected static ?string $title = 'Títulos observados';

    protected static ?string $modelLabel = 'Título';

    protected static ?string $pluralModelLabel = 'Títulos';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('due_date', 'asc')
            ->emptyStateHeading('Nenhum título observado')
            ->emptyStateDescription('Este lote não observou nenhum título.')
            ->emptyStateIcon('heroicon-o-scale')
            ->columns([
                TextColumn::make('description')
                    ->label('Título')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->placeholder('Sem descrição')
                    ->url(fn (ContaAzulImportedBill $record): string => ContaAzulImportedBillResource::getUrl('view', ['record' => $record])),

                TextColumn::make('total_amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->alignEnd(),

                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('reconciliation_status')
                    ->label('Conciliação')
                    ->formatStateUsing(fn (string $state): string => ContaAzulImportedBill::RECONCILIATION_STATUS_OPTIONS[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ContaAzulImportedBill::RECONCILIATION_ATTRIBUTED => 'success',
                        ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW => 'info',
                        ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION => 'warning',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (ContaAzulImportedBill $record): string => ContaAzulImportedBillResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
