<?php

namespace App\Filament\Resources\Operations;

use App\Enums\OperationStatus;
use App\Exceptions\OperationLifecycleException;
use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\MeasurementsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Filament\Resources\Operations\Schemas\OperationForm;
use App\Filament\Resources\Operations\Schemas\OperationInfolist;
use App\Filament\Resources\Operations\Tables\OperationsTable;
use App\Models\Operation;
use App\Services\MeasurementAuthorizationService;
use App\Services\OperationLifecycleService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OperationResource extends Resource
{
    protected static ?string $model = Operation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Operações de Obra';

    protected static ?string $modelLabel = 'Operação de Obra';

    protected static ?string $pluralModelLabel = 'Operações de Obra';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return OperationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OperationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PlanSetsRelationManager::class,
            PlanLinesRelationManager::class,
            MeasurementsRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['emission', 'construction', 'planSets.construction']);
        $user = auth()->user();

        return $user === null ? $query->whereRaw('1 = 0') : $query->visibleTo($user);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Operation::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Operation::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    /**
     * A exclusão física deixou de existir na interface -- a saída de uma
     * operação é cancelá-la, com motivo e histórico preservado. A policy e os
     * guardas de modelo continuam válidos para qualquer caminho programático.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * A recusa do lifecycle é esperada, não falha: a operação com medição aberta
     * não encerra, e quem clicou precisa ler por quê. Sem isto a exceção
     * atravessaria a ação e viraria erro de servidor numa regra de negócio.
     */
    private static function guarded(Closure $transition): void
    {
        try {
            $transition();
        } catch (OperationLifecycleException|AuthorizationException $exception) {
            Notification::make()
                ->danger()
                ->title('Situação não alterada.')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    /**
     * Colocar a operação em produção. Nada além da transição: não existe
     * checklist de prontidão no domínio, e inventar um aqui seria criar
     * exigência que ninguém pediu.
     */
    public static function getActivateOperationAction(): Action
    {
        return Action::make('activate_operation')
            ->label('Ativar operação')
            ->icon('heroicon-o-play')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Ativar operação')
            ->modalDescription('A operação passa a aceitar medições, delegações escopadas e o fluxo de trabalho completo.')
            ->modalSubmitActionLabel('Ativar operação')
            ->visible(fn (Operation $record): bool => $record->status === OperationStatus::Draft
                && self::canEdit($record))
            ->action(function (Operation $record): void {
                self::guarded(fn () => app(OperationLifecycleService::class)->activate($record, auth()->user()));

                Notification::make()
                    ->title('Operação ativada.')
                    ->body('A operação está em andamento e aceita novas medições.')
                    ->success()
                    ->send();
            });
    }

    public static function getCompleteOperationAction(): Action
    {
        return Action::make('complete_operation')
            ->label('Concluir operação')
            ->icon('heroicon-o-check-circle')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Concluir operação')
            ->modalDescription('A operação deixa de aceitar medições, responsáveis novos e delegações escopadas. '
                .'Todo o histórico permanece consultável.')
            ->modalSubmitActionLabel('Concluir operação')
            ->visible(fn (Operation $record): bool => $record->status === OperationStatus::Active
                && self::canEdit($record))
            ->action(function (Operation $record): void {
                self::guarded(fn () => app(OperationLifecycleService::class)->complete($record, auth()->user()));

                Notification::make()
                    ->title('Operação concluída.')
                    ->body('Nenhum trabalho novo será aceito; o histórico continua disponível.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Cancelar exige motivo porque é a decisão que alguém vai precisar
     * reconstruir depois -- inclusive a de uma operação criada por engano, que
     * antes se resolvia apagando o registro.
     */
    public static function getCancelOperationAction(): Action
    {
        return Action::make('cancel_operation')
            ->label('Cancelar operação')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancelar operação')
            ->modalDescription('A operação deixa de aceitar qualquer trabalho novo. Nada é excluído: '
                .'medições, responsáveis e delegações permanecem registrados.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo do cancelamento')
                    ->placeholder('Descreva por que esta operação está sendo cancelada...')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3)
                    ->validationMessages([
                        'required' => 'Informe o motivo do cancelamento.',
                    ]),
            ])
            ->modalSubmitActionLabel('Cancelar operação')
            ->visible(fn (Operation $record): bool => in_array(
                $record->status,
                [OperationStatus::Draft, OperationStatus::Active],
                true,
            ) && self::canEdit($record))
            ->action(function (Operation $record, array $data): void {
                self::guarded(fn () => app(OperationLifecycleService::class)
                    ->cancel($record, auth()->user(), (string) ($data['reason'] ?? '')));

                Notification::make()
                    ->title('Operação cancelada.')
                    ->body('O motivo foi registrado na auditoria e o histórico permanece íntegro.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Reabrir é corrigir um encerramento, e devolve autoridade no mesmo
     * instante: os responsáveis nunca deixaram de estar gravados e as delegações
     * nunca foram revogadas. O preflight mostra exatamente o que volta a valer
     * antes de a confirmação existir.
     */
    public static function getReopenOperationAction(): Action
    {
        return Action::make('reopen_operation')
            ->label('Reabrir operação')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reabrir operação')
            ->modalDescription(function (Operation $record): string {
                $preflight = app(OperationLifecycleService::class)->reopenPreflight($record);

                if (! $preflight->restoresAuthority()) {
                    return 'A operação volta a "Em Andamento" e a aceitar medições. '
                        .'Não há responsável nem delegação vinculada que volte a produzir autoridade agora.';
                }

                return sprintf(
                    'A operação volta a "Em Andamento" e a aceitar trabalho novo. '
                        .'Responsabilidades que voltam a valer: %d. Delegações que voltam a alcançá-la: %d.',
                    $preflight->responsibilityCount(),
                    $preflight->delegationCount(),
                );
            })
            ->modalContent(function (Operation $record) {
                $preflight = app(OperationLifecycleService::class)->reopenPreflight($record);

                if (! $preflight->restoresAuthority()) {
                    return null;
                }

                return view('filament.operations.reopen-preflight', [
                    'responsibilities' => $preflight->responsibilityLines(),
                    'delegations' => $preflight->delegationLines(),
                ]);
            })
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da reabertura')
                    ->placeholder('Descreva por que esta operação está sendo reaberta...')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3)
                    ->validationMessages([
                        'required' => 'Informe o motivo da reabertura.',
                    ]),
            ])
            ->modalSubmitActionLabel('Reabrir operação')
            ->visible(fn (Operation $record): bool => $record->status->isTerminal()
                && auth()->user() !== null
                && app(MeasurementAuthorizationService::class)->isWorkflowAdministrator(auth()->user()))
            ->action(function (Operation $record, array $data): void {
                self::guarded(fn () => app(OperationLifecycleService::class)
                    ->reopen($record, auth()->user(), (string) ($data['reason'] ?? '')));

                Notification::make()
                    ->title('Operação reaberta.')
                    ->body('A operação voltou a "Em Andamento" e os vínculos existentes voltaram a produzir efeito.')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperations::route('/'),
            'create' => CreateOperation::route('/create'),
            'view' => ViewOperation::route('/{record}'),
            'edit' => EditOperation::route('/{record}/edit'),
        ];
    }
}
