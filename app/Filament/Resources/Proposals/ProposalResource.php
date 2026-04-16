<?php

namespace App\Filament\Resources\Proposals;

use App\Actions\Proposals\UpdateProposalStatus;
use App\DTOs\Proposals\UpdateProposalStatusDTO;
use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\Pages\EditProposal;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\RelationManagers\ProjectRelationManager;
use App\Filament\Resources\Proposals\RelationManagers\ProposalAssignmentRelationManager;
use App\Filament\Resources\Proposals\RelationManagers\ProposalContinuationAccessRelationManager;
use App\Filament\Resources\Proposals\RelationManagers\ProposalStatusHistoryRelationManager;
use App\Filament\Resources\Proposals\Tables\ProposalsTable;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ProposalVisibilityFilter;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProposalResource extends Resource
{
    protected static ?string $model = Proposal::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Propostas';

    protected static ?string $modelLabel = 'Proposta';

    protected static ?string $pluralModelLabel = 'Propostas';

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Análise Comercial')
                ->schema([
                    Textarea::make('internal_notes')
                        ->label('Observações internas')
                        ->rows(6)
                        ->helperText('Visível apenas ao time comercial no painel administrativo.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Section::make('Distribuição')
                    ->schema([
                        TextEntry::make('distribution_sequence')
                            ->label('Ordem na fila')
                            ->numeric(decimalPlaces: 0)
                            ->placeholder('—'),
                        TextEntry::make('representative.name')
                            ->label('Representante')
                            ->placeholder('Não atribuído'),
                        TextEntry::make('distributed_at')
                            ->label('Distribuída em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('completed_at')
                            ->label('Complementada em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Dados da Empresa')
                    ->schema([
                        TextEntry::make('company.name')
                            ->label('Nome da Empresa')
                            ->placeholder('—'),
                        TextEntry::make('company.cnpj')
                            ->label('CNPJ')
                            ->placeholder('—'),
                        TextEntry::make('company.ie')
                            ->label('IE')
                            ->placeholder('—'),
                        TextEntry::make('company.site')
                            ->label('Site')
                            ->placeholder('—')
                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                            ->openUrlInNewTab(),
                        TextEntry::make('company.full_address')
                            ->label('Endereço')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('company.sectors.name')
                            ->label('Setores de Atuação')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Acesso do Cliente')
                    ->schema([
                        TextEntry::make('latestContinuationAccess.status_label')
                            ->label('Status do link')
                            ->state(fn (Proposal $record): ?string => $record->latestContinuationAccess?->status_label)
                            ->placeholder('—')
                            ->badge()
                            ->color(fn (Proposal $record): string => $record->latestContinuationAccess?->status_color ?? 'gray'),
                        TextEntry::make('latestContinuationAccess.sent_to_email')
                            ->label('E-mail do magic link')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.display_code')
                            ->label('Codigo enviado')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('latestContinuationAccess.sent_at')
                            ->label('Enviado em')
                            ->state(fn (Proposal $record) => $record->latestContinuationAccess?->sent_at ?? $record->latestContinuationAccess?->created_at)
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.expires_at')
                            ->label('Expira em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.first_accessed_at')
                            ->label('Primeiro acesso')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.last_accessed_at')
                            ->label('Ultimo acesso')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.verified_at')
                            ->label('Validado em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.last_used_at')
                            ->label('Ultimo uso autenticado')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestContinuationAccess.generated_url')
                            ->label('Link gerado')
                            ->placeholder('—')
                            ->copyable()
                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                            ->openUrlInNewTab()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Análise Comercial')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status atual')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => ProposalStatus::labelFor($state))
                            ->color(fn (?string $state): string => ProposalStatus::colorFor($state)),
                        TextEntry::make('next_statuses')
                            ->label('Próximos status possíveis')
                            ->state(fn (?Proposal $record): array => $record ? array_values(
                                app(UpdateProposalStatus::class)->availableStatusOptions($record->status),
                            ) : [])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Sem novas transições disponíveis.'),
                        TextEntry::make('latest_status_changed_by')
                            ->label('Última alteração por')
                            ->state(fn (?Proposal $record): ?string => match (true) {
                                ! $record?->latestStatusHistory => null,
                                (bool) $record->latestStatusHistory->changedByUser?->name => $record->latestStatusHistory->changedByUser->name,
                                default => 'Sistema',
                            })
                            ->placeholder('—'),
                        TextEntry::make('latestStatusHistory.changed_at')
                            ->label('Última alteração em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latestStatusHistory.note')
                            ->label('Última observação da movimentação')
                            ->placeholder('Sem observação registrada.')
                            ->columnSpanFull(),
                        TextEntry::make('internal_notes')
                            ->label('Observações internas')
                            ->placeholder('Sem observações internas.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Dados de Contato')
                    ->schema([
                        TextEntry::make('contact.name')
                            ->label('Nome do Contato')
                            ->placeholder('—'),
                        TextEntry::make('contact.email')
                            ->label('E-mail')
                            ->placeholder('—'),
                        TextEntry::make('contact.phone_summary')
                            ->label('Telefones')
                            ->placeholder('—'),
                        TextEntry::make('contact.cargo')
                            ->label('Cargo')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Proposta')
                    ->schema([
                        TextEntry::make('observations')
                            ->label('Observações')
                            ->placeholder('Sem observações.')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ProposalsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProposalAssignmentRelationManager::class,
            ProposalContinuationAccessRelationManager::class,
            ProposalStatusHistoryRelationManager::class,
            ProjectRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProposals::route('/'),
            'view' => ViewProposal::route('/{record}'),
            'edit' => EditProposal::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $user = static::resolveCurrentUser();

        return static::userCanManageAll($user) || (bool) $user?->can('proposals.view');
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Proposal
            && static::userCanAccessRecord(static::resolveCurrentUser(), $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Proposal
            && static::userCanManageRecord(static::resolveCurrentUser(), $record);
    }

    public static function getEloquentQuery(): Builder
    {
        return ProposalVisibilityFilter::apply(
            parent::getEloquentQuery()->with([
                'company.sectors',
                'contact',
                'representative.user',
                'latestContinuationAccess',
                'latestStatusHistory.changedByUser',
            ]),
            static::resolveCurrentUser(),
        );
    }

    public static function getChangeStatusAction(): Action
    {
        return Action::make('change_status')
            ->label('Atualizar status')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->modalHeading('Atualizar andamento da proposta')
            ->visible(fn (Proposal $record): bool => static::userCanManageRecord(static::resolveCurrentUser(), $record)
                && filled(app(UpdateProposalStatus::class)->availableStatusOptions($record->status)))
            ->form([
                Placeholder::make('current_status')
                    ->label('Status atual')
                    ->content(fn (Proposal $record): string => ProposalStatus::labelFor($record->status)),
                Placeholder::make('current_representative')
                    ->label('Representante responsável')
                    ->content(fn (Proposal $record): string => $record->representative?->name ?? 'Não atribuído'),
                Select::make('status')
                    ->label('Novo status')
                    ->options(fn (Proposal $record): array => app(UpdateProposalStatus::class)->availableStatusOptions($record->status))
                    ->required()
                    ->native(false),
                Textarea::make('note')
                    ->label('Observação da movimentação')
                    ->rows(4)
                    ->helperText('Obrigatório ao rejeitar a proposta ou solicitar novas informações.'),
            ])
            ->action(function (Proposal $record, array $data): void {
                app(UpdateProposalStatus::class)->handle(
                    $record,
                    UpdateProposalStatusDTO::fromArray([
                        'status' => $data['status'],
                        'user' => static::resolveCurrentUser(),
                        'note' => $data['note'] ?? null,
                    ]),
                );

                $record->refresh();

                Notification::make()
                    ->title('Status da proposta atualizado com sucesso.')
                    ->success()
                    ->send();
            });
    }

    protected static function resolveCurrentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function userCanManageAll(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(['super-admin', 'admin']);
    }

    protected static function userCanAccessRecord(?User $user, Proposal $proposal): bool
    {
        if (! $user) {
            return false;
        }

        if (static::userCanManageAll($user)) {
            return true;
        }

        return $user->can('proposals.view') && $proposal->isAssignedToUser($user);
    }

    protected static function userCanManageRecord(?User $user, Proposal $proposal): bool
    {
        if (! $user) {
            return false;
        }

        if (static::userCanManageAll($user)) {
            return true;
        }

        return $user->can('proposals.update') && $proposal->isAssignedToUser($user);
    }
}
