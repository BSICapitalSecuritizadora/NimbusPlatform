<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Tables;

use App\Models\EmissionMonthlyReportNote;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EmissionMonthlyReportNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('title')
                    ->label('Título')
                    ->limit(40)
                    ->placeholder('—')
                    ->wrap(),

                IconColumn::make('is_visible_on_report')
                    ->label('No relatório')
                    ->boolean(),

                TextColumn::make('createdBy.name')
                    ->label('Autor')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Emissão')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reference_month')
                    ->label('Competência')
                    ->options(fn (): array => EmissionMonthlyReportNote::query()
                        ->orderByDesc('reference_month')
                        ->pluck('reference_month')
                        ->filter()
                        ->unique()
                        ->mapWithKeys(fn (mixed $referenceMonth): array => [
                            (string) EmissionMonthlyReportNote::normalizeReferenceMonth($referenceMonth) => EmissionMonthlyReportNote::formatReferenceMonthForDisplay($referenceMonth),
                        ])
                        ->all()),

                TernaryFilter::make('is_visible_on_report')
                    ->label('Visível no relatório'),
            ])
            ->defaultSort('reference_month', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
