<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class PortalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('portalUser.full_name')
                    ->label('Usuário do portal')
                    ->searchable(),
                TextColumn::make('portalUser.email')
                    ->label('E-mail')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable(),
                TextColumn::make('file_original_name')
                    ->label('Arquivo')
                    ->searchable(),
                TextColumn::make('file_size')
                    ->label('Tamanho')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : '—')
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Enviado por')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
