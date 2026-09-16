<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\MeasurementPayment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagamentos';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('currentReceiptEvidence'))
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
                TextColumn::make('measurement.reference_month')
                    ->label('Medição')
                    ->state(fn (MeasurementPayment $record): ?string => $record->measurement === null
                        ? null
                        : 'Medição #'.$record->measurement->getKey().' · '.($record->measurement->reference_month?->format('m/Y') ?? '—'))
                    ->url(fn (MeasurementPayment $record): ?string => $record->measurement === null
                        ? null
                        : MeasurementResource::getUrl('view', ['record' => $record->measurement]))
                    ->placeholder('Medição não vinculada')
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Método')
                    ->placeholder('Não informado'),
                IconColumn::make('receipt_evidence')
                    ->label('Comprovante')
                    ->boolean()
                    ->state(fn ($record): bool => $record->hasReceipt()),
            ])
            ->recordActions([
                Action::make('downloadReceipt')
                    ->label('Baixar comprovante')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record): string => route('admin.measurements.receipts.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => $record->hasReceipt()),
            ])
            ->defaultSort('pay_date', 'desc');
    }
}
