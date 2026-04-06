<?php

namespace App\Filament\Resources\Nimbus\Submissions\Tables;

use App\Models\Nimbus\Submission;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_cnpj')
                    ->label('CNPJ da Empresa')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company_name')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Submission::statusLabelFor($state))
                    ->color(fn (?string $state): string => Submission::statusColorFor($state))
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar'),
            ]);
    }
}
