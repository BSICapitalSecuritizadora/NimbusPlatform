<?php

namespace App\Filament\Resources\Nimbus\Submissions\Tables;

use App\Models\Nimbus\Submission;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                TextColumn::make('reference_code')
                    ->label('Protocolo')
                    ->copyable()
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('portalUser.full_name')
                    ->label('Solicitante')
                    ->searchable()
                    ->sortable(),
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
                TextColumn::make('submitted_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Submission::statusOptions()),
                SelectFilter::make('nimbus_portal_user_id')
                    ->label('Solicitante')
                    ->relationship('portalUser', 'full_name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->visible(fn (Submission $record): bool => auth()->user()->can('view', $record)),
            ])
            ->defaultSort('submitted_at', 'desc');
    }
}
