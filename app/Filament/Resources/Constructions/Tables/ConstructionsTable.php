<?php

namespace App\Filament\Resources\Constructions\Tables;

use App\Models\ExpenseServiceProvider;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ConstructionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('development_name')
                    ->label('Empreendimento')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('development_cnpj')
                    ->label('CNPJ do empreendimento')
                    ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->label('Cidade')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('state')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),

                TextColumn::make('construction_start_date')
                    ->label('Início')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('construction_end_date')
                    ->label('Conclusão')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('estimated_value')
                    ->label('Valor previsto')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('measurementCompany.name')
                    ->label('Empresa de medição')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('measurementCompany.cnpj')
                    ->label('CNPJ da empresa de medição')
                    ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Emissão')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('state')
                    ->label('Estado')
                    ->options(\App\Models\Construction::STATE_OPTIONS),
            ])
            ->defaultSort('created_at', 'desc')
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
