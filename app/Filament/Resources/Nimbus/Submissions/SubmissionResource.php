<?php

namespace App\Filament\Resources\Nimbus\Submissions;

use App\Filament\Resources\Nimbus\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\Nimbus\Submissions\Pages\ViewSubmission;
use App\Filament\Resources\Nimbus\Submissions\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Nimbus\Submissions\RelationManagers\ShareholdersRelationManager;
use App\Filament\Resources\Nimbus\Submissions\Schemas\SubmissionForm;
use App\Filament\Resources\Nimbus\Submissions\Tables\SubmissionsTable;
use App\Models\Nimbus\Submission;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Visão Geral';

    protected static ?string $navigationLabel = 'Envios e Solicitações';

    protected static ?string $modelLabel = 'envio e solicitação';

    protected static ?string $pluralModelLabel = 'Envios e Solicitações';

    protected static ?int $navigationSort = -9;

    public static function form(Schema $schema): Schema
    {
        return SubmissionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Detalhes do Envio')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 4,
                        ])
                            ->schema([
                                TextEntry::make('reference_code')
                                    ->label('Protocolo')
                                    ->copyable()
                                    ->copyMessage('Protocolo copiado')
                                    ->fontFamily('mono')
                                    ->size('xs')
                                    ->tooltip(fn (?string $state): ?string => $state)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 2,
                                    ])
                                    ->wrap(),
                                TextEntry::make('portalUser.name')
                                    ->label('Solicitante')
                                    ->helperText(fn (Submission $record) => $record->portalUser?->email)
                                    ->icon('heroicon-m-user-circle')
                                    ->iconColor('primary'),
                                TextEntry::make('created_at')
                                    ->label('Data do Envio')
                                    ->date('d/m/Y \à\s H:i'),
                                TextEntry::make('title')
                                    ->label('Assunto')
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Informações Complementares')
                    ->icon('heroicon-o-information-circle')
                    ->columns(1)
                    ->schema([
                        Section::make('Dados da Empresa')
                            ->description('Identificação e contatos informados pelo solicitante')
                            ->compact()
                            ->secondary()
                            ->columns([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->schema([
                                TextEntry::make('company_name')
                                    ->label('Razão social')
                                    ->placeholder('-')
                                    ->wrap()
                                    ->columnSpanFull(),
                                TextEntry::make('company_cnpj')
                                    ->label('CNPJ')
                                    ->placeholder('-'),
                                TextEntry::make('phone')
                                    ->label('Telefone')
                                    ->placeholder('-'),
                                TextEntry::make('main_activity')
                                    ->label('Atividade principal')
                                    ->placeholder('-')
                                    ->wrap()
                                    ->columnSpanFull(),
                                TextEntry::make('website')
                                    ->label('Website')
                                    ->placeholder('-')
                                    ->url(fn (?string $state): ?string => filled($state) ? $state : null, true)
                                    ->color('primary')
                                    ->wrap()
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Indicadores Financeiros')
                            ->description('Resumo patrimonial e classificações regulatórias')
                            ->compact()
                            ->secondary()
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ])
                            ->schema([
                                TextEntry::make('net_worth')
                                    ->label('Patrimônio líquido')
                                    ->money('BRL')
                                    ->placeholder('-'),
                                TextEntry::make('annual_revenue')
                                    ->label('Último faturamento anual')
                                    ->money('BRL')
                                    ->placeholder('-'),
                                IconEntry::make('is_us_person')
                                    ->label('US Person')
                                    ->boolean(),
                                IconEntry::make('is_pep')
                                    ->label('Pessoa exposta politicamente (PEP)')
                                    ->boolean(),
                            ]),

                        Section::make('Dados do Cadastrante')
                            ->description('Responsável pelo preenchimento da solicitação')
                            ->compact()
                            ->secondary()
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ])
                            ->schema([
                                TextEntry::make('registrant_name')
                                    ->label('Nome completo')
                                    ->placeholder('-')
                                    ->wrap()
                                    ->columnSpanFull(),
                                TextEntry::make('registrant_position')
                                    ->label('Cargo / posição')
                                    ->placeholder('-'),
                                TextEntry::make('registrant_cpf')
                                    ->label('CPF')
                                    ->placeholder('-'),
                                TextEntry::make('registrant_rg')
                                    ->label('RG')
                                    ->placeholder('-'),
                            ]),
                    ]),

                Section::make('Documentos de Retorno')
                    ->icon('heroicon-o-paper-airplane')
                    ->description('Arquivos enviados pela equipe interna para devolutiva e acompanhamento do solicitante.')
                    ->schema([
                        View::make('filament.resources.nimbus.submissions.response-files-section'),
                    ]),

                Section::make('Timeline da Submissão')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->description('Observações inseridas neste envio')
                    ->schema([
                        TextEntry::make('notes_empty_state')
                            ->hiddenLabel()
                            ->state('Nenhuma observação interna foi registrada neste envio até o momento.')
                            ->visible(fn (Submission $record): bool => $record->notes->isEmpty()),
                        RepeatableEntry::make('notes')
                            ->hiddenLabel()
                            ->visible(fn (Submission $record): bool => $record->notes->isNotEmpty())
                            ->schema([
                                TextEntry::make('created_at')->label('Data/Hora')->dateTime('d/m/Y H:i'),
                                TextEntry::make('author_label')->label('Usuário Autor'),
                                TextEntry::make('visibility_label')->label('Visibilidade'),
                                TextEntry::make('message')->label('Mensagem')->columnSpanFull()->wrap(),
                            ])
                            ->columns(3),
                    ]),

                Section::make('Trilha de Auditoria')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Section::make('Metadados do Registro')
                            ->description('Datas do fluxo e origem técnica do envio')
                            ->compact()
                            ->secondary()
                            ->columns([
                                'default' => 1,
                                'md' => 3,
                            ])
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Data de criação')
                                    ->dateTime('d/m/Y H:i:s'),
                                TextEntry::make('status_updated_at')
                                    ->label('Status atualizado em')
                                    ->dateTime('d/m/Y H:i:s')
                                    ->placeholder('-'),
                                TextEntry::make('created_ip')
                                    ->label('IP de origem')
                                    ->placeholder('-')
                                    ->copyable()
                                    ->copyMessage('IP copiado')
                                    ->fontFamily('mono'),
                            ]),

                        Section::make('User Agent da Sessão')
                            ->description('Cabeçalho completo enviado pelo navegador no momento do cadastro')
                            ->compact()
                            ->secondary()
                            ->schema([
                                TextEntry::make('created_user_agent')
                                    ->hiddenLabel()
                                    ->placeholder('-')
                                    ->fontFamily('mono')
                                    ->size('xs')
                                    ->copyable()
                                    ->copyMessage('User Agent copiado')
                                    ->wrap(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            ShareholdersRelationManager::class,
            FilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
