<?php

namespace App\Filament\Resources\Proposals;

use App\Actions\Proposals\UpdateProposalStatus;
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
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
            Section::make('Distribuição')
                ->schema([
                    Placeholder::make('distribution_sequence_view')
                        ->label('Ordem na fila')
                        ->content(fn (?Proposal $record): string => $record?->distribution_sequence
                            ? (string) $record->distribution_sequence
                            : '—'),
                    Placeholder::make('representative_view')
                        ->label('Representante')
                        ->content(fn (?Proposal $record): string => $record?->representative?->name ?? 'Não atribuído'),
                    Placeholder::make('distributed_at_view')
                        ->label('Distribuída em')
                        ->content(fn (?Proposal $record): string => $record?->distributed_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('completed_at_view')
                        ->label('Complementada em')
                        ->content(fn (?Proposal $record): string => $record?->completed_at?->format('d/m/Y H:i') ?? '—'),
                ])
                ->columns(2),
            Section::make('Acesso do Cliente')
                ->schema([
                    Placeholder::make('access_status_view')
                        ->label('Status do link')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->status_label ?? '—'),
                    Placeholder::make('access_email_view')
                        ->label('E-mail do magic link')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->sent_to_email ?? '—'),
                    Placeholder::make('access_code_view')
                        ->label('Codigo enviado')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->display_code ?? '—'),
                    Placeholder::make('access_sent_at_view')
                        ->label('Enviado em')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->sent_at?->format('d/m/Y H:i')
                            ?? $record?->latestContinuationAccess?->created_at?->format('d/m/Y H:i')
                            ?? '—'),
                    Placeholder::make('access_expires_at_view')
                        ->label('Expira em')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->expires_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('access_first_accessed_at_view')
                        ->label('Primeiro acesso')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->first_accessed_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('access_last_accessed_at_view')
                        ->label('Ultimo acesso')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->last_accessed_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('access_verified_at_view')
                        ->label('Validado em')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->verified_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('access_last_used_at_view')
                        ->label('Ultimo uso autenticado')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->last_used_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('access_generated_url_view')
                        ->label('Link gerado')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->generated_url ?? '—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Análise Comercial')
                ->schema([
                    Placeholder::make('analysis_status_view')
                        ->label('Status atual')
                        ->content(fn (?Proposal $record): string => $record?->status_label ?? '—'),
                    Placeholder::make('analysis_next_statuses_view')
                        ->label('Próximos status possíveis')
                        ->content(fn (?Proposal $record): string => static::formatNextStatuses($record)),
                    Placeholder::make('analysis_changed_by_view')
                        ->label('Última alteração por')
                        ->content(fn (?Proposal $record): string => match (true) {
                            ! $record?->latestStatusHistory => '—',
                            (bool) $record->latestStatusHistory->changedByUser?->name => $record->latestStatusHistory->changedByUser->name,
                            default => 'Sistema',
                        }),
                    Placeholder::make('analysis_changed_at_view')
                        ->label('Última alteração em')
                        ->content(fn (?Proposal $record): string => $record?->latestStatusHistory?->changed_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('analysis_latest_note_view')
                        ->label('Última observação da movimentação')
                        ->content(fn (?Proposal $record): string => $record?->latestStatusHistory?->note ?? 'Sem observação registrada.')
                        ->columnSpanFull(),
                    Placeholder::make('internal_notes_view')
                        ->label('Observações internas')
                        ->content(fn (?Proposal $record): string => $record?->internal_notes ?? 'Sem observações internas.')
                        ->columnSpanFull()
                        ->visible(fn ($livewire): bool => $livewire instanceof ViewProposal),
                    Textarea::make('internal_notes')
                        ->label('Observações internas')
                        ->rows(6)
                        ->helperText('Visível apenas ao time comercial no painel administrativo.')
                        ->columnSpanFull()
                        ->hidden(fn ($livewire): bool => $livewire instanceof ViewProposal),
                ])
                ->columns(2),
            Section::make('Dados da Empresa')
                ->schema([
                    Placeholder::make('company_name')
                        ->label('Nome da Empresa')
                        ->content(fn (?Proposal $record): string => $record?->company?->name ?? '—'),
                    Placeholder::make('company_cnpj')
                        ->label('CNPJ')
                        ->content(fn (?Proposal $record): string => $record?->company?->cnpj ?? '—'),
                    Placeholder::make('company_ie')
                        ->label('IE')
                        ->content(fn (?Proposal $record): string => $record?->company?->ie ?? '—'),
                    Placeholder::make('company_site')
                        ->label('Site')
                        ->content(fn (?Proposal $record): string => $record?->company?->site ?? '—'),
                    Placeholder::make('company_address')
                        ->label('Endereço')
                        ->content(fn (?Proposal $record): string => $record?->company_address ?? 'â€”'),
                    Placeholder::make('company_sectors')
                        ->label('Setores de Atuação')
                        ->content(fn (?Proposal $record): string => $record?->company?->sectors->pluck('name')->join(', ') ?: '—'),
                ])
                ->columns(2),
            Section::make('Dados de Contato')
                ->schema([
                    Placeholder::make('contact_name')
                        ->label('Nome do Contato')
                        ->content(fn (?Proposal $record): string => $record?->contact?->name ?? '—'),
                    Placeholder::make('contact_email')
                        ->label('E-mail')
                        ->content(fn (?Proposal $record): string => $record?->contact?->email ?? '—'),
                    Placeholder::make('contact_phones')
                        ->label('Telefones')
                        ->content(fn (?Proposal $record): string => $record?->contact?->phone_summary ?? 'â€”'),
                    Placeholder::make('contact_cargo')
                        ->label('Cargo')
                        ->content(fn (?Proposal $record): string => $record?->contact?->cargo ?? '—'),
                ])
                ->columns(2),
            Section::make('Proposta')
                ->schema([
                    Placeholder::make('observations')
                        ->label('Observações')
                        ->content(fn (?Proposal $record): string => $record?->observations ?? 'Sem observações.')
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
        return parent::getEloquentQuery()
            ->with([
                'company.sectors',
                'contact',
                'representative.user',
                'latestContinuationAccess',
                'latestStatusHistory.changedByUser',
            ])
            ->visibleTo(static::resolveCurrentUser());
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
                    ->content(fn (Proposal $record): string => $record->status_label),
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
                    $data['status'],
                    static::resolveCurrentUser(),
                    $data['note'] ?? null,
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

    protected static function formatNextStatuses(?Proposal $record): string
    {
        if (! $record) {
            return '—';
        }

        $nextStatuses = array_values(app(UpdateProposalStatus::class)->availableStatusOptions($record->status));

        return $nextStatuses !== [] ? implode(' | ', $nextStatuses) : 'Sem novas transições disponíveis.';
    }

    protected static function formatCompanyAddress(?Proposal $record): string
    {
        $company = $record?->company;

        if (! $company) {
            return '—';
        }

        $street = trim(implode(', ', array_filter([
            $company->logradouro,
            $company->numero,
        ])));

        $district = $company->bairro ? " - {$company->bairro}" : '';
        $city = trim(implode('/', array_filter([
            $company->cidade,
            $company->estado,
        ])));
        $zip = $company->cep ? " - CEP: {$company->cep}" : '';

        $address = trim($street.$district);

        if ($city !== '') {
            $address = trim($address.'. '.$city, '. ');
        }

        return $address !== '' ? $address.$zip : '—';
    }

    protected static function formatContactPhones(?Proposal $record): string
    {
        $contact = $record?->contact;

        if (! $contact) {
            return '—';
        }

        $personalPhone = $contact->phone_personal
            ? 'Pessoal: '.$contact->phone_personal.($contact->whatsapp ? ' (WhatsApp)' : '')
            : null;
        $companyPhone = $contact->phone_company ? 'Empresa: '.$contact->phone_company : null;

        return implode(' | ', array_filter([$personalPhone, $companyPhone])) ?: '—';
    }
}
