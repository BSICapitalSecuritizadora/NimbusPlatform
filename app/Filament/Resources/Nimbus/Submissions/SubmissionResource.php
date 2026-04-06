<?php

namespace App\Filament\Resources\Nimbus\Submissions;

use App\Filament\Resources\Nimbus\Submissions\Pages\EditSubmission;
use App\Filament\Resources\Nimbus\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\Nimbus\Submissions\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Nimbus\Submissions\RelationManagers\ShareholdersRelationManager;
use App\Filament\Resources\Nimbus\Submissions\Schemas\SubmissionForm;
use App\Filament\Resources\Nimbus\Submissions\Tables\SubmissionsTable;
use App\Models\Nimbus\Submission;
use BackedEnum;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use App\Filament\Resources\Nimbus\Submissions\Pages\ViewSubmission;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
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
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('portalUser.name')
                                    ->label('Solicitante')
                                    ->description(fn (Submission $record) => $record->portalUser?->email)
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
                                ])->columns(1)->extraAttributes(['class' => 'space-y-2 pl-4'])->columnSpan(1),
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
            'view' => Pages\ViewSubmission::route('/{record}'),
            'edit' => EditSubmission::route('/{record}/edit'),
        ];
    }
}
