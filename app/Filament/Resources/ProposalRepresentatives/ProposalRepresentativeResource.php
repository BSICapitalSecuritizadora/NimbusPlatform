<?php

namespace App\Filament\Resources\ProposalRepresentatives;

use App\Filament\Resources\ProposalRepresentatives\Pages\CreateProposalRepresentative;
use App\Filament\Resources\ProposalRepresentatives\Pages\EditProposalRepresentative;
use App\Filament\Resources\ProposalRepresentatives\Pages\ListProposalRepresentatives;
use App\Models\ProposalRepresentative;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProposalRepresentativeResource extends Resource
{
    protected static ?string $model = ProposalRepresentative::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?string $modelLabel = 'Representante Comercial';

    protected static ?string $pluralModelLabel = 'Representantes Comerciais';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255),
            TextInput::make('queue_position')->label('Posição na fila')->numeric()->default(1)->required(),
            Toggle::make('is_active')->label('Ativo na fila')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('queue_position')->label('Fila')->sortable(),
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable(),
                TextColumn::make('is_active')->label('Status')->badge()->formatStateUsing(fn (bool $state) => $state ? 'Ativo' : 'Inativo')->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('proposals_count')->counts('proposals')->label('Propostas'),
            ])
            ->defaultSort('queue_position')
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProposalRepresentatives::route('/'),
            'create' => CreateProposalRepresentative::route('/create'),
            'edit' => EditProposalRepresentative::route('/{record}/edit'),
        ];
    }
}
