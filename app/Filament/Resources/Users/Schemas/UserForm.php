<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccessPermission;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados do usuário')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail corporativo')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'Este e-mail corporativo já está cadastrado.',
                            ])
                            ->maxLength(255),
                        TextInput::make('cargo')
                            ->label('Cargo')
                            ->maxLength(255),
                        TextInput::make('departamento')
                            ->label('Departamento')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->helperText('Usuários inativos não conseguem acessar o painel, mesmo autenticados pela Microsoft.')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Acesso e permissões')
                    ->schema([
                        Select::make('roles')
                            ->label('Perfis de acesso')
                            ->relationship(
                                'roles',
                                'name',
                                fn (Builder $query): Builder => $query->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Role $record): string => AccessPermission::roleLabel($record->name))
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->helperText('O perfil define o conjunto base de permissões do usuário.'),
                        CheckboxList::make('permissions')
                            ->label('Permissões adicionais')
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
                            ->columnSpanFull()
                            ->helperText('Use permissões diretas para exceções pontuais ao perfil selecionado.'),
                    ])
                    ->columns(2),
                Section::make('Status de autenticação')
                    ->schema([
                        Placeholder::make('approved_at')
                            ->label('Provisionado em')
                            ->content(fn ($record) => $record?->approved_at?->format('d/m/Y H:i') ?? 'Será provisionado ao salvar'),
                        Placeholder::make('azure_id')
                            ->label('Identificador Microsoft')
                            ->content(fn ($record) => $record?->azure_id ?: 'Aguardando primeiro login via Microsoft 365'),
                        Placeholder::make('last_login_at')
                            ->label('Último Acesso')
                            ->content(fn ($record) => $record?->last_login_at?->format('d/m/Y H:i') ?? 'Nenhum login registrado'),
                    ])
                    ->columns(3),
            ]);
    }
}
