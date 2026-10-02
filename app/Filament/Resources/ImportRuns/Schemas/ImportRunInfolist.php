<?php

namespace App\Filament\Resources\ImportRuns\Schemas;

use App\Models\ImportRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The detail of one confirmed reconciliation.
 *
 * Everything shown is what the execution persisted. Runs confirmed since the
 * archive existed keep the spreadsheet on the private disk too; it is named
 * here, not offered for download -- the checksum remains what answers whether a
 * file in hand is the same one that was processed.
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
                            ->color(fn (ImportRun $record): string => match ($record->type) {
                                ImportRun::TYPE_CONTRACTS => 'info',
                                ImportRun::TYPE_CONSTRUCTION_UNITS => 'success',
                                ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES => 'warning',
                                default => 'primary',
                            }),
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
                        TextEntry::make('records_warned')
                            ->label('Com aviso')
                            ->numeric()
                            ->color(fn (ImportRun $record): string => (int) $record->records_warned > 0 ? 'warning' : 'gray'),
                        TextEntry::make('records_absent')
                            ->label('Ausentes da planilha')
                            ->numeric()
                            ->helperText('Registros cadastrados que a planilha não trouxe. Nada muda neles sem uma decisão explícita.'),
                    ])
                    ->columns(4),

                Section::make('Registros criados por esta importação')
                    ->description('Os registros que esta execução criou em lote e que continuam ligados a ela. Alterações aparecem na lista de alterações abaixo.')
                    ->schema([
                        TextEntry::make('created_records')
                            ->label(fn (ImportRun $record): string => match ($record->type) {
                                ImportRun::TYPE_CONTRACTS => 'Contratos criados',
                                ImportRun::TYPE_CONSTRUCTION_UNITS => 'Unidades criadas',
                                ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES => 'Linhas de valor acrescentadas',
                                default => 'Parcelas criadas',
                            })
                            ->state(fn (ImportRun $record): int => $record->createdRecordsCount())
                            ->numeric(),
                    ]),

                Section::make('Parcelas canceladas por ausência')
                    ->description('Parcelas em aberto, ausentes da planilha, que quem confirmou decidiu cancelar.')
                    ->schema([
                        TextEntry::make('records_cancelled')
                            ->label('Canceladas')
                            ->numeric(),
                        TextEntry::make('absence_cancellation_date')
                            ->label('Data do cancelamento')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('absence_cancellation_reason')
                            ->label('Motivo')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (ImportRun $record): bool => (int) $record->records_cancelled > 0),

                Section::make('Arquivo processado')
                    ->description('O nome e o checksum do arquivo e, nas importações mais recentes, a planilha arquivada no disco privado.')
                    ->schema([
                        TextEntry::make('file_path')
                            ->label('Planilha arquivada')
                            ->placeholder('Não arquivada (importação anterior ao arquivamento)')
                            ->columnSpanFull(),
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
