<?php

namespace App\Filament\Resources\Proposals;

use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\Tables\ProposalsTable;
use App\Models\Proposal;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            Section::make('Dados da Empresa')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('company_name')
                        ->label('Nome da Empresa')
                        ->content(fn ($record) => $record->company?->name),
                    \Filament\Forms\Components\Placeholder::make('company_cnpj')
                        ->label('CNPJ')
                        ->content(fn ($record) => $record->company?->cnpj),
                    \Filament\Forms\Components\Placeholder::make('company_ie')
                        ->label('IE')
                        ->content(fn ($record) => $record->company?->ie ?? '—'),
                    \Filament\Forms\Components\Placeholder::make('company_site')
                        ->label('Site')
                        ->content(fn ($record) => $record->company?->site ?? '—'),
                    \Filament\Forms\Components\Placeholder::make('company_address')
                        ->label('Endereço')
                        ->content(fn ($record) => sprintf(
                            '%s, %s - %s. %s/%s - CEP: %s',
                            $record->company?->logradouro,
                            $record->company?->numero,
                            $record->company?->bairro,
                            $record->company?->cidade,
                            $record->company?->estado,
                            $record->company?->cep
                        )),
                    \Filament\Forms\Components\Placeholder::make('company_sectors')
                        ->label('Setores de Atuação')
                        ->content(fn ($record) => $record->company?->sectors->pluck('name')->join(', ') ?? '—'),
                ])
                ->columns(2),

            Section::make('Dados de Contato')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('contact_name')
                        ->label('Nome do Contato')
                        ->content(fn ($record) => $record->contact?->name),
                    \Filament\Forms\Components\Placeholder::make('contact_email')
                        ->label('E-mail')
                        ->content(fn ($record) => $record->contact?->email),
                    \Filament\Forms\Components\Placeholder::make('contact_phones')
                        ->label('Telefones')
                        ->content(fn ($record) => sprintf(
                            'Pessoal: %s %s | Empresa: %s',
                            $record->contact?->phone_personal,
                            $record->contact?->whatsapp ? '(WhatsApp)' : '',
                            $record->contact?->phone_company ?? '—'
                        )),
                    \Filament\Forms\Components\Placeholder::make('contact_cargo')
                        ->label('Cargo')
                        ->content(fn ($record) => $record->contact?->cargo ?? '—'),
                ])
                ->columns(2),

            Section::make('Proposta')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('observations')
                        ->label('Observações')
                        ->content(fn ($record) => $record->observations ?? 'Sem observações.')
                        ->columnSpanFull(),
                    
                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'pendente' => 'Pendente',
                            'em_analise' => 'Em Análise',
                            'aprovado' => 'Aprovado',
                            'rejeitado' => 'Rejeitado',
                        ])
                        ->required(),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return ProposalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProposals::route('/'),
            'view' => ViewProposal::route('/{record}'),
            'edit' => \App\Filament\Resources\Proposals\Pages\EditProposal::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
