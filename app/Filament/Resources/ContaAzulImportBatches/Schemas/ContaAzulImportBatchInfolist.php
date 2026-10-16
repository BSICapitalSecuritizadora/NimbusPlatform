<?php

namespace App\Filament\Resources\ContaAzulImportBatches\Schemas;

use App\Filament\Resources\ContaAzulImportBatches\ContaAzulImportBatchResource;
use App\Models\ContaAzulImportBatch;
use App\Services\Funds\ContaAzulStaleBatchRecoveryService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContaAzulImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resultado')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label('Resultado')
                        ->formatStateUsing(fn (string $state): string => ContaAzulImportBatch::STATUS_OPTIONS[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            ContaAzulImportBatch::STATUS_COMPLETED => 'success',
                            ContaAzulImportBatch::STATUS_RUNNING, ContaAzulImportBatch::STATUS_PENDING => 'info',
                            ContaAzulImportBatch::STATUS_PARTIAL => 'warning',
                            ContaAzulImportBatch::STATUS_ABANDONED => 'gray',
                            default => 'danger',
                        }),

                    TextEntry::make('staleness')
                        ->label('Atividade')
                        ->state(fn (ContaAzulImportBatch $record): string => match (true) {
                            $record->status !== ContaAzulImportBatch::STATUS_RUNNING => 'Lote encerrado',
                            $record->isStale(app(ContaAzulStaleBatchRecoveryService::class)->staleTimeoutMinutes()) => 'Sem atividade — candidata a recuperação',
                            default => 'Importação em andamento — protegida contra recuperação',
                        })
                        ->badge()
                        ->color(fn (ContaAzulImportBatch $record): string => match (true) {
                            $record->status !== ContaAzulImportBatch::STATUS_RUNNING => 'gray',
                            $record->isStale(app(ContaAzulStaleBatchRecoveryService::class)->staleTimeoutMinutes()) => 'danger',
                            default => 'info',
                        }),

                    TextEntry::make('last_heartbeat_at')
                        ->label('Última atividade')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('error_code')
                        ->label('Código do erro')
                        ->placeholder('—')
                        ->badge()
                        ->color('gray')
                        ->visible(fn (ContaAzulImportBatch $record): bool => filled($record->error_code)),

                    TextEntry::make('error_message')
                        ->label('Detalhe')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (ContaAzulImportBatch $record): bool => filled($record->error_message)),
                ]),

            Section::make('Contadores')
                ->columnSpanFull()
                ->columns(4)
                ->schema([
                    TextEntry::make('pages_completed')
                        ->label('Páginas')
                        ->state(fn (ContaAzulImportBatch $record): string => "{$record->pages_completed}/{$record->pages_requested}"),

                    TextEntry::make('records_received')->label('Recebidos')->numeric(),

                    TextEntry::make('bills_staged')->label('Novos')->numeric(),

                    TextEntry::make('bills_updated')->label('Atualizados')->numeric(),

                    TextEntry::make('bills_skipped')->label('Ignorados')->numeric(),

                    TextEntry::make('bills_reobserved')->label('Reobservados')->numeric(),
                ]),

            Section::make('Conta e período')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('bank_account_label')
                        ->label('Conta bancária')
                        ->state(fn (ContaAzulImportBatch $record): string => $record->bankAccount?->optionLabel() ?? '—'),

                    TextEntry::make('masked_account')
                        ->label('Conta Conta Azul')
                        ->state(fn (ContaAzulImportBatch $record): string => $record->maskedAccountId()),

                    TextEntry::make('integration_context')->label('Contexto'),

                    TextEntry::make('period')
                        ->label('Período')
                        ->state(fn (ContaAzulImportBatch $record): string => $record->date_from->format('d/m/Y').' a '.$record->date_to->format('d/m/Y')),

                    TextEntry::make('requestedBy.name')
                        ->label('Solicitado por')
                        ->placeholder('—'),

                    TextEntry::make('started_at')
                        ->label('Iniciado em')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('finished_at')
                        ->label('Encerrado em')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('retries_batch_id')
                        ->label('Retoma o lote')
                        ->formatStateUsing(fn (?int $state): string => $state !== null ? "#{$state}" : '—')
                        ->url(fn (ContaAzulImportBatch $record): ?string => $record->retries_batch_id !== null
                            ? ContaAzulImportBatchResource::getUrl('view', ['record' => $record->retries_batch_id])
                            : null),
                ]),

            Section::make('Recuperação')
                ->columnSpanFull()
                ->columns(3)
                ->visible(fn (ContaAzulImportBatch $record): bool => $record->isAbandoned())
                ->schema([
                    TextEntry::make('recoveredBy.name')
                        ->label('Recuperado por')
                        ->placeholder('—'),

                    TextEntry::make('recovered_at')
                        ->label('Recuperado em')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('recovered_from_status')
                        ->label('Estado anterior')
                        ->formatStateUsing(fn (?string $state): string => $state !== null
                            ? (ContaAzulImportBatch::STATUS_OPTIONS[$state] ?? $state)
                            : '—'),

                    TextEntry::make('recovery_reason')
                        ->label('Motivo')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
