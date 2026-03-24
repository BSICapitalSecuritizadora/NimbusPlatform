<?php

namespace App\Filament\Resources\Recruitment\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VacanciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),
                
                TextColumn::make('department')
                    ->label('Departamento')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),

                TextColumn::make('location')
                    ->label('Localização')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean(),

                TextColumn::make('applications_count')
                    ->label('Candidatos')
                    ->counts('applications')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }
}
