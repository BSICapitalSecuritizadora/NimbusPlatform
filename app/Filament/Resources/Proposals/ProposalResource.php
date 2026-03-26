<?php

namespace App\Filament\Resources\Proposals;

use App\Filament\Resources\Proposals\Pages\EditProposal;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\RelationManagers\ProjectRelationManager;
use App\Filament\Resources\Proposals\RelationManagers\ProposalAssignmentRelationManager;
use App\Filament\Resources\Proposals\Tables\ProposalsTable;
use App\Models\Proposal;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

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
                        ->label('Concluída em')
                        ->content(fn (?Proposal $record): string => $record?->completed_at?->format('d/m/Y H:i') ?? '—'),
                ])
                ->columns(2),
            Section::make('Acesso do Cliente')
                ->schema([
                    Placeholder::make('access_email_view')
                        ->label('E-mail do magic link')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->sent_to_email ?? '—'),
                    Placeholder::make('access_expires_at_view')
                        ->label('Expira em')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->expires_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('access_verified_at_view')
                        ->label('Validado em')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->verified_at?->format('d/m/Y H:i') ?? '—'),
                    Placeholder::make('access_last_used_at_view')
                        ->label('Último uso')
                        ->content(fn (?Proposal $record): string => $record?->latestContinuationAccess?->last_used_at?->format('d/m/Y H:i') ?? '—'),
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
                        ->content(fn (?Proposal $record): string => static::formatCompanyAddress($record)),
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
                        ->content(fn (?Proposal $record): string => static::formatContactPhones($record)),
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
                    Placeholder::make('status_view')
                        ->label('Status')
                        ->content(fn (?Proposal $record): string => $record?->status_label ?? '—')
                        ->visible(fn ($livewire): bool => $livewire instanceof ViewProposal),
                    Select::make('status')
                        ->label('Status')
                        ->options([
                            Proposal::STATUS_AWAITING_COMPLETION => 'Aguardando complementação',
                            Proposal::STATUS_IN_REVIEW => 'Em análise',
                            Proposal::STATUS_APPROVED => 'Aprovado',
                            Proposal::STATUS_REJECTED => 'Rejeitado',
                        ])
                        ->required()
                        ->hidden(fn ($livewire): bool => $livewire instanceof ViewProposal),
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
