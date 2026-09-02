<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Services\UserLifecycleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Usuários';

    protected static ?string $modelLabel = 'Usuário';

    protected static ?string $pluralModelLabel = 'Usuários';

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Configurações';

    protected static ?int $navigationSort = 91;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['roles', 'permissions']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    /**
     * Exclusão física deixou de ser ação operacional.
     *
     * O ciclo de vida de um usuário passou a ser Desativar/Reativar: o cadastro
     * continua existindo porque continua sendo referência de tudo o que a pessoa
     * fez. A proteção de histórico da P2.5 -- evento `deleting` no modelo e chaves
     * RESTRICT no schema -- permanece intacta como última defesa para qualquer
     * caminho de código que ainda chame `delete()`.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getApproveUserAction(): Action
    {
        return Action::make('approve_user')
            ->label('Aprovar acesso')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Aprovar acesso do usuário')
            ->modalDescription('Ao confirmar, o usuário terá acesso liberado ao portal BSI Capital.')
            ->visible(fn (User $record): bool => ! $record->isApproved())
            ->action(function (User $record): void {
                $record->update(['approved_at' => now()]);

                Notification::make()
                    ->title('Acesso do usuário '.$record->name.' aprovado com sucesso.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Retirar o usuário da operação.
     *
     * O texto não promete o que a ação não faz: nenhuma delegação é revogada e
     * nenhuma responsabilidade é solta. Elas apenas deixam de produzir efeito
     * enquanto ele estiver inativo -- e é essa a diferença que o histórico
     * precisa preservar.
     */
    public static function getDeactivateUserAction(): Action
    {
        return Action::make('deactivate_user')
            ->label('Desativar')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Desativar usuário')
            ->modalDescription(fn (User $record): string => sprintf(
                'Este usuário perderá o acesso ao sistema e deixará de ser elegível para novas responsabilidades e delegações. '
                    .'O histórico existente de %s será preservado: nada é excluído e nenhuma delegação é revogada.',
                $record->name,
            ))
            ->modalSubmitActionLabel('Desativar usuário')
            ->visible(fn (User $record): bool => $record->isActive() && self::canEdit($record))
            // Auto-desativação fecharia a porta por dentro: quem executa a ação é
            // super-admin, e super-admin inativo não autentica nem pode ser
            // reativado por si mesmo. Bloquear aqui também garante que a operação
            // nunca fica sem nenhum administrador ativo -- o próprio ator sobra.
            ->disabled(fn (User $record): bool => (int) $record->getKey() === (int) auth()->id())
            ->action(function (User $record): void {
                app(UserLifecycleService::class)->deactivate($record, auth()->user());

                Notification::make()
                    ->title('Usuário '.$record->name.' desativado.')
                    ->body('O acesso foi bloqueado e o histórico permanece íntegro.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Devolver o usuário à operação, dizendo antes o que isso devolve junto.
     *
     * Os vínculos continuaram gravados durante toda a inatividade, então reativar
     * restaura autoridade no mesmo instante. O preflight calcula o efeito real
     * -- não o inventário histórico -- e o modal só mostra o bloco quando há algo
     * a mostrar.
     */
    public static function getReactivateUserAction(): Action
    {
        return Action::make('reactivate_user')
            ->label('Reativar')
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reativar usuário')
            ->modalDescription(function (User $record): string {
                $preflight = app(UserLifecycleService::class)->reactivationPreflight($record);

                if (! $preflight->restoresAuthority()) {
                    return sprintf(
                        '%s voltará a acessar o sistema e a ser elegível para novas responsabilidades. '
                            .'Não há responsabilidade nem delegação vinculada que volte a valer agora.',
                        $record->name,
                    );
                }

                return sprintf(
                    'Reativar este usuário pode restaurar autoridades ainda vinculadas ao cadastro. '
                        .'Responsabilidades diretas: %d. Delegações que voltarão a valer: %d.',
                    $preflight->directResponsibilityCount(),
                    $preflight->delegationCount(),
                );
            })
            ->modalContent(function (User $record) {
                $preflight = app(UserLifecycleService::class)->reactivationPreflight($record);

                if (! $preflight->restoresAuthority()) {
                    return null;
                }

                return view('filament.users.reactivation-preflight', [
                    'directResponsibilities' => $preflight->directResponsibilityLines(),
                    'delegations' => $preflight->delegationLines(),
                ]);
            })
            ->modalSubmitActionLabel('Reativar usuário')
            ->visible(fn (User $record): bool => ! $record->isActive() && self::canEdit($record))
            ->action(function (User $record): void {
                app(UserLifecycleService::class)->reactivate($record, auth()->user());

                Notification::make()
                    ->title('Usuário '.$record->name.' reativado.')
                    ->body('O acesso foi liberado e os vínculos existentes voltaram a produzir efeito.')
                    ->success()
                    ->send();
            });
    }
}
