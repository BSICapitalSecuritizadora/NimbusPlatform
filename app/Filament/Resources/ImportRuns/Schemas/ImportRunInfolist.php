<?php

namespace App\Filament\Resources\ImportRuns\Schemas;

use App\Models\ImportRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The detail of one confirmed reconciliation.
 *
 * Everything shown is what the execution persisted. The platform keeps the name
 * and the content hash of the spreadsheet, not the spreadsheet itself, so there
 * is nothing to download here -- the checksum is what answers whether a file in
 * hand is the same one that was processed.
 */
class ImportRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Execução')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Nº da importação')
                            ->prefix('#'),
                        TextEntry::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->state(fn (ImportRun $record): string => $record->typeLabel())
                            ->color(fn (ImportRun $record): string => $record->type === ImportRun::TYPE_CONTRACTS ? 'info' : 'primary'),
                        TextEntry::make('file_name')
                            ->label('Arquivo')
                            ->placeholder('—'),
                        TextEntry::make('user.name')
                            ->label('Executada por')
                            ->state(fn (ImportRun $record): string => $record->userName()),
                        TextEntry::make('created_at')
                            ->label('Data/hora')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('contract_id')
                            ->label('Escopo')
                            ->state(fn (ImportRun $record): string => $record->coverageLabel()),
                        TextEntry::make('result')
                            ->label('Resultado')
                            ->badge()
                            ->state(fn (ImportRun $record): string => $record->resultLabel())
                            ->color(fn (ImportRun $record): string => $record->resultColor()),
                    ])
                    ->columns(3),

                Section::make('Resumo')
                    ->description('Os números desta execução, como foram registrados no momento da confirmação.')
                    ->schema([
                        TextEntry::make('records_analyzed')
                            ->label('Total analisado')
                            ->numeric(),
                        TextEntry::make('records_created')
                            ->label('Novos')
                            ->numeric(),
                        TextEntry::make('records_updated')
                            ->label('Atualizados')
                            ->numeric(),
                        TextEntry::make('records_unchanged')
                            ->label('Sem alteração')
                            ->numeric(),
                        TextEntry::make('records_critical')
                            ->label('Críticos')
                            ->numeric()
                            ->color(fn (ImportRun $record): string => $record->records_critical > 0 ? 'warning' : 'gray'),
                    ])
                    ->columns(5),

                Section::make('Arquivo processado')
                    ->description('O sistema preserva o nome e o checksum do arquivo, não a planilha enviada.')
                    ->schema([
                        TextEntry::make('checksum')
                            ->label('Checksum (SHA-256)')
                            ->placeholder('—')
                            ->copyable()
                            ->copyMessage('Checksum copiado.')
                            ->columnSpanFull(),
                        TextEntry::make('reprocessed')
                            ->hiddenLabel()
                            ->badge()
                            ->color('gray')
                            ->icon('heroicon-m-arrow-path')
                            ->state('Arquivo já processado anteriormente')
                            ->visible(fn (ImportRun $record): bool => $record->fileWasProcessedBefore())
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
