<?php

namespace App\Filament\Resources\Nimbus\Submissions\Tables;

use App\Models\Nimbus\Submission;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'portalUser',
            ]))
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
                    ->label('Visualizar')
                    ->visible(fn (Submission $record): bool => auth()->user()->can('view', $record)),
            ]);
    }
}
