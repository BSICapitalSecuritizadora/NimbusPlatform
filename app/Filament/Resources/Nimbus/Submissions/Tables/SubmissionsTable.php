<?php

namespace App\Filament\Resources\Nimbus\Submissions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nimbus_portal_user_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reference_code')
                    ->searchable(),
                TextColumn::make('submission_type')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('responsible_name')
                    ->searchable(),
                TextColumn::make('company_cnpj')
                    ->searchable(),
                TextColumn::make('company_name')
                    ->searchable(),
                TextColumn::make('main_activity')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('website')
                    ->searchable(),
                TextColumn::make('net_worth')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('annual_revenue')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_us_person')
                    ->boolean(),
                IconColumn::make('is_pep')
                    ->boolean(),
                TextColumn::make('registrant_name')
                    ->searchable(),
                TextColumn::make('registrant_position')
                    ->searchable(),
                TextColumn::make('registrant_rg')
                    ->searchable(),
                TextColumn::make('registrant_cpf')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('created_ip')
                    ->searchable(),
                TextColumn::make('created_user_agent')
                    ->searchable(),
                TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status_updated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status_updated_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
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
