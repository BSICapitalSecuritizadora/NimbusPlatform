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
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
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
            ->components([
                Section::make('Detalhes do Envio')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('reference_code')
                                    ->label('Protocolo')
                                    ->copyable()
                                    ->copyMessage('Protocolo copiado')
                                    ->fontFamily('mono')
                                    ->size('xs')
                                    ->tooltip(fn (?string $state): ?string => $state)
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
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Group::make([
                                    TextEntry::make('company_name')->label('Razão Social')->inlineLabel(),
                                    TextEntry::make('company_cnpj')->label('CNPJ')->inlineLabel(),
                                    TextEntry::make('main_activity')->label('Atividade Principal')->inlineLabel(),
                                    TextEntry::make('phone')->label('Telefone')->inlineLabel(),
                                    TextEntry::make('website')->label('Website')->inlineLabel()->default('-'),
                                ])->columns(1)->extraAttributes(['class' => 'space-y-2 border-r pr-6 border-gray-200 dark:border-white/10'])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('net_worth')->label('Patrimônio Líquido')
                                        ->money('BRL')->inlineLabel(),
                                    TextEntry::make('annual_revenue')->label('Faturamento Anual')
                                        ->money('BRL')->inlineLabel(),
                                    IconEntry::make('is_us_person')
                                        ->label('US Person?')
                                        ->boolean()
                                        ->inlineLabel(),
                                    IconEntry::make('is_pep')
                                        ->label('Pessoa Exposta (PEP)?')
                                        ->boolean()
                                        ->inlineLabel(),
                                ])->columns(1)->extraAttributes(['class' => 'space-y-2 border-r pr-6 border-gray-200 dark:border-white/10'])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('registrant_name')->label('Nome do Cadastrante')->inlineLabel(),
                                    TextEntry::make('registrant_position')->label('Cargo / Posição')->inlineLabel(),
                                    TextEntry::make('registrant_cpf')->label('CPF do Cadastrante')->inlineLabel(),
                                    TextEntry::make('registrant_rg')->label('RG do Cadastrante')->inlineLabel(),
                                ])->columns(1)->extraAttributes(['class' => 'space-y-2 pl-4'])->columnSpan(1),
                            ]),
                    ]),

                Section::make('Timeline da Submissão')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->description('Observações inseridas neste envio')
                    ->schema([
                        RepeatableEntry::make('notes')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('created_at')->label('Data/Hora')->dateTime('d/m/Y H:i'),
                                TextEntry::make('user.name')->label('Usuário Autor'),
                                TextEntry::make('note_text')->label('Mensagem')->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),

                Section::make('Anexos Recebidos')
                    ->icon('heroicon-o-paper-clip')
                    ->schema([
                        RepeatableEntry::make('files')
                            ->hiddenLabel()
                            ->schema([
                                IconEntry::make('is_file')->default(true)->icon('heroicon-o-document')->hiddenLabel(),
                                TextEntry::make('original_name')->label('Arquivo')->weight('bold'),
                                TextEntry::make('size_in_mb')->label('Tamanho')->suffix(' MB'),
                                TextEntry::make('download_url')
                                    ->label('Ação')
                                    ->badge()
                                    ->color('primary')
                                    ->url(fn ($record) => '#', true)
                                    ->formatStateUsing(fn () => 'Baixar Arquivo'),
                            ])
                            ->columns(4),
                    ]),

                Section::make('Trilha de Auditoria')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('created_at')->label('Data de Criação')->dateTime('d/m/Y H:i:s'),
                                TextEntry::make('created_user_agent')->label('User Agent'),
                                TextEntry::make('created_ip')->label('IP de Origem'),
                                TextEntry::make('status_updated_at')->label('Data Status Atualizado')->dateTime('d/m/Y H:i:s'),
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
