<?php

namespace App\Filament\Resources\Emissions\Tables;

use App\Models\Emission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Denominação da Operação')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),

                TextColumn::make('if_code')
                    ->label('Código IF')
                    ->toggleable(),

                TextColumn::make('isin_code')
                    ->label('Código ISIN')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Emission::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'default' => 'warning',
                        'active' => 'success',
                        'closed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('issuer')
                    ->label('Emissor')
                    ->toggleable(),

                TextColumn::make('series')
                    ->label('Série')
                    ->toggleable(),

                TextColumn::make('maturity_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('is_public')
                    ->label('Site')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()->can('emissions.update')),

                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('emissions.delete')),
            ])
            ->defaultSort('name');
    }
}
