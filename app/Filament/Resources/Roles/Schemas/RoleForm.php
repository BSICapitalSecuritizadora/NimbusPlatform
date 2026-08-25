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
                Section::make('Identificação do perfil')
                    ->description('Defina o nome de identificação do perfil de acesso.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do perfil')
                            ->placeholder('Ex: Gestor de Operações')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'Já existe um perfil de acesso com este nome.',
                            ])
                            ->maxLength(255),
                        Hidden::make('guard_name')
                            ->default('web'),
                    ])
                    ->columnSpanFull(),

                Section::make('Permissões do perfil')
                    ->description('Selecione as permissões de acesso concedidas aos usuários vinculados a este perfil.')
                    ->schema([
                        CheckboxList::make('permissions')
                            ->label('Permissões')
                            ->relationship(
                                'permissions',
                                'name',
                                fn (Builder $query): Builder => $query
                                    ->whereIn('name', AccessPermission::values())
                                    ->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Permission $record): string => AccessPermission::labelFor($record->name))
                            ->view('filament.forms.components.permission-matrix')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
