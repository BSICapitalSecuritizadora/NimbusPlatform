<?php

namespace App\Filament\Resources\Measurements\Tables;

use App\Models\Measurement;
use App\Services\MeasurementWorkflow;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MeasurementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('operation.title')
                    ->label('Operação')
                    ->description(fn (Measurement $record): ?string => $record->operation?->code)
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('assets.planSet.construction.development_name')
                    ->label('Empreendimentos')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('assets_count')
                    ->label('Arquivos')
                    ->counts('assets')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('current_stage')
                    ->label('Etapa')
                    ->badge()
                    ->state(fn (Measurement $record): string => MeasurementWorkflow::STAGE_LABELS[app(MeasurementWorkflow::class)->unifiedStage($record)] ?? '—')
                    ->color(fn (Measurement $record): string => MeasurementWorkflow::STAGE_COLORS[app(MeasurementWorkflow::class)->unifiedStage($record)] ?? 'gray'),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Measurement::STATUS_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'finalized', 'approved' => 'success',
                        'rejected' => 'danger',
                        'paused' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('uploaded_at')
                    ->label('Enviada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(Measurement::STATUS_OPTIONS),

                SelectFilter::make('operation_id')
                    ->label('Operação')
                    ->relationship('operation', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('uploaded_at', 'desc')
            ->recordActions([
                ViewAction::make()->label('Visualizar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
