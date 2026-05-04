<?php

namespace App\Filament\Resources\Nimbus\Submissions\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShareholdersRelationManager extends RelationManager
{
    protected static string $relationship = 'shareholders';

    protected static ?string $title = 'Sócios';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('document_rg')
                    ->label('RG')
                    ->maxLength(20),
                TextInput::make('document_cnpj')
                    ->label('CNPJ')
                    ->maxLength(18),
                TextInput::make('percentage')
                    ->label('Participação')
                    ->numeric()
                    ->required()
                    ->suffix('%'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('document_rg')
                    ->label('RG')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('document_cnpj')
                    ->label('CNPJ')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('percentage')
                    ->label('Participação')
                    ->numeric(2, ',', '.')
                    ->suffix('%')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (RelationManager $livewire): bool => auth()->user()->can('update', $livewire->getOwnerRecord())),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (RelationManager $livewire): bool => auth()->user()->can('update', $livewire->getOwnerRecord())),
                DeleteAction::make()
                    ->visible(fn (RelationManager $livewire): bool => auth()->user()->can('update', $livewire->getOwnerRecord())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (RelationManager $livewire): bool => auth()->user()->can('update', $livewire->getOwnerRecord())),
                ]),
            ]);
    }
}
