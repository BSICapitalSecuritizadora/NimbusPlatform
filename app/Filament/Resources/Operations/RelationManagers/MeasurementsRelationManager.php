<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MeasurementsRelationManager extends RelationManager
{
    protected static string $relationship = 'measurements';

    protected static ?string $title = 'Medições';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('filename')
            ->columns([
                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('assets_count')
                    ->label('Arquivos')
                    ->counts('assets')
                    ->badge(),
                TextColumn::make('current_stage')
                    ->label('Etapa')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Measurement::STATUS_OPTIONS[$state] ?? $state),
                TextColumn::make('uploaded_at')
                    ->label('Enviada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('uploaded_at', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
