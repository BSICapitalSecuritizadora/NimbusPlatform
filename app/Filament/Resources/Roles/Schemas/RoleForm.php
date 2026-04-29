<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\AccessPermission;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Perfil')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do perfil')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'Já existe um perfil de acesso com este nome.',
                            ])
                            ->maxLength(255),
                        Hidden::make('guard_name')
                            ->default('web'),
                    ]),
                Section::make('Permissões')
                    ->schema([
                        CheckboxList::make('permissions')
                            ->label('Permissões do perfil')
                            ->relationship(
                                'permissions',
                                'name',
                                fn (Builder $query): Builder => $query
                                    ->whereIn('name', AccessPermission::values())
                                    ->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Permission $record): string => AccessPermission::labelFor($record->name))
                            ->bulkToggleable()
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
