<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccessPermission;
use App\Models\User;
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
                    ->description('Informações cadastrais e credenciais corporativas do colaborador.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->placeholder('Ex: Ana Paula Silva')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail corporativo')
                            ->placeholder('colaborador@bsicapital.com.br')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'Este e-mail corporativo já está cadastrado.',
                            ])
                            ->maxLength(255),
                        TextInput::make('cargo')
                            ->label('Cargo')
                            ->placeholder('Ex: Analista de Estruturação')
                            ->maxLength(255),
                        TextInput::make('departamento')
                            ->label('Departamento')
                            ->placeholder('Ex: Gestão & Operações')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Usuário ativo na plataforma')
                            ->helperText(fn (?User $record): string => $record?->getKey() === auth()->id()
                                ? 'Você não pode desativar o seu próprio usuário. Peça a outro administrador.'
                                : 'Usuários inativos têm o acesso bloqueado imediatamente em todas as rotas do painel, mesmo com sessão Microsoft válida. Prefira as ações Desativar/Reativar, que avisam o que volta a valer.')
                            // Desativar-se a si mesmo tranca a porta por dentro:
                            // o painel de usuários exige super-admin, e
                            // super-admin inativo não autentica para desfazer.
                            ->disabled(fn (?User $record): bool => $record?->getKey() === auth()->id())
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Acessos e permissões')
                    ->description('Defina o perfil de acesso e as permissões específicas concedidas a este usuário.')
                    ->schema([
                        Select::make('roles')
                            ->label('Perfil de acesso')
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
                            ->helperText('O perfil define o conjunto base de permissões. O perfil Super Admin possui acesso integral irrestrito a todas as funcionalidades da plataforma.')
                            ->columnSpanFull(),

                        CheckboxList::make('permissions')
                            ->label('Permissões individuais')
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

                Section::make('Status de autenticação')
                    ->description('Dados de provisionamento e auditoria de login corporativo Microsoft 365.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Placeholder::make('approved_at')
                            ->label('Provisionado em')
                            ->content(fn ($record) => $record?->approved_at?->format('d/m/Y H:i') ?? 'Será provisionado ao salvar'),
                        Placeholder::make('azure_id')
                            ->label('Identificador Microsoft (Entra ID)')
                            ->content(fn ($record) => $record?->azure_id ?: 'Aguardando primeiro login via Microsoft 365'),
                        Placeholder::make('last_login_at')
                            ->label('Último acesso')
                            ->content(fn ($record) => $record?->last_login_at?->format('d/m/Y H:i') ?? 'Nenhum login registrado'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
