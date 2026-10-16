<?php

namespace App\Filament\Resources\ContaAzulImportedBills\Schemas;

use App\Filament\Resources\ContaAzulImportBatches\ContaAzulImportBatchResource;
use App\Models\ContaAzulImportedBill;
use App\Models\ExpenseHistory;
use App\Support\BankIdentifierMasker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

/**
 * Staged-bill evidence. The raw encrypted payload is provenance and is
 * never rendered; external identifiers stay masked unless the viewer
 * operates the pipeline.
 */
class ContaAzulImportedBillInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Situação')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('reconciliation_status')
                        ->label('Conciliação')
                        ->formatStateUsing(fn (string $state): string => ContaAzulImportedBill::RECONCILIATION_STATUS_OPTIONS[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            ContaAzulImportedBill::RECONCILIATION_ATTRIBUTED => 'success',
                            ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW => 'info',
                            ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION => 'warning',
                            default => 'gray',
                        }),

                    TextEntry::make('import_status')
                        ->label('Importação')
                        ->formatStateUsing(fn (string $state): string => ContaAzulImportedBill::IMPORT_STATUS_OPTIONS[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            ContaAzulImportedBill::IMPORT_ERROR => 'danger',
                            ContaAzulImportedBill::IMPORT_UPDATED => 'warning',
                            default => 'info',
                        }),

                    TextEntry::make('needs_re_review')
                        ->label('Mudança externa')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Revisão pendente' : 'Sem pendências')
                        ->badge()
                        ->color(fn (bool $state): string => $state ? 'danger' : 'gray')
                        ->helperText(fn (ContaAzulImportedBill $record): ?string => $record->needs_re_review ? $record->re_review_reason : null),

                    TextEntry::make('legacy_history')
                        ->label('Fluxo legado')
                        ->state(fn (ContaAzulImportedBill $record): string => $record->findLegacyHistory() instanceof ExpenseHistory
                            ? 'Já registrado no fluxo legado'
                            : 'Ainda não registrado no fluxo legado')
                        ->badge()
                        ->color(function (ContaAzulImportedBill $record): string {
                            $history = $record->findLegacyHistory();

                            if (! $history instanceof ExpenseHistory) {
                                return 'gray';
                            }

                            $legacyEmissionId = $history->expense()->value('emission_id');

                            if ($record->attributed_emission_id !== null && $legacyEmissionId !== null && (int) $legacyEmissionId !== (int) $record->attributed_emission_id) {
                                return 'danger';
                            }

                            return 'warning';
                        })
                        ->helperText(function (ContaAzulImportedBill $record): ?string {
                            $history = $record->findLegacyHistory();

                            if (! $history instanceof ExpenseHistory) {
                                return null;
                            }

                            $legacyEmissionId = $history->expense()->value('emission_id');

                            if ($record->attributed_emission_id !== null && $legacyEmissionId !== null && (int) $legacyEmissionId !== (int) $record->attributed_emission_id) {
                                return 'A emissão atribuída aqui diverge da emissão do fluxo legado. A divergência exige reconciliação explícita futura; nada foi reescrito.';
                            }

                            return 'Este título já existe no fluxo operacional. A conciliação não cria outra despesa nem altera o histórico.';
                        }),

                    TextEntry::make('completeness')
                        ->label('Elegibilidade')
                        ->state(fn (ContaAzulImportedBill $record): string => $record->hasVerifiedContent()
                            ? 'Conteúdo verificado por importação completa'
                            : 'Aguardando importação completa — atribuição apenas por exceção justificada')
                        ->badge()
                        ->color(fn (ContaAzulImportedBill $record): string => $record->hasVerifiedContent() ? 'success' : 'warning')
                        ->columnSpan(2),

                    TextEntry::make('reconciliation_note')
                        ->label('Nota de revisão')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (ContaAzulImportedBill $record): bool => filled($record->reconciliation_note)),
                ]),

            Section::make('Título')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('conta_azul_bill_id')
                        ->label('Referência externa (mascarada)')
                        ->formatStateUsing(fn (ContaAzulImportedBill $record): string => $record->maskedBillId())
                        ->fontFamily(FontFamily::Mono),

                    TextEntry::make('conta_azul_bill_id_full')
                        ->label('Referência externa integral')
                        ->state(fn (ContaAzulImportedBill $record): string => (string) $record->conta_azul_bill_id)
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->visible(fn (): bool => auth()->user()?->can('conta-azul-imports.manage') ?? false),

                    TextEntry::make('description')
                        ->label('Descrição')
                        ->placeholder('Sem descrição')
                        ->columnSpanFull(),

                    TextEntry::make('supplier_name')
                        ->label('Fornecedor')
                        ->placeholder('Não informado'),

                    TextEntry::make('total_amount')
                        ->label('Valor nominal')
                        ->money('BRL')
                        ->weight('bold'),

                    TextEntry::make('due_date')
                        ->label('Vencimento')
                        ->date('d/m/Y')
                        ->placeholder('—'),

                    TextEntry::make('paid_amount')
                        ->label('Valor pago')
                        ->money('BRL')
                        ->placeholder('—'),

                    TextEntry::make('unpaid_amount')
                        ->label('Valor em aberto')
                        ->money('BRL')
                        ->placeholder('—'),

                    TextEntry::make('payment_date')
                        ->label('Data de pagamento')
                        ->date('d/m/Y')
                        ->placeholder('—'),

                    TextEntry::make('competence_date')
                        ->label('Competência')
                        ->date('d/m/Y')
                        ->placeholder('—'),

                    TextEntry::make('external_status')
                        ->label('Status externo')
                        ->placeholder('—')
                        ->badge()
                        ->color('gray'),

                    TextEntry::make('external_status_translated')
                        ->label('Status traduzido')
                        ->placeholder('—'),

                    TextEntry::make('mapped_category')
                        ->label('Categoria mapeada')
                        ->placeholder('Sem mapeamento')
                        ->badge()
                        ->color('gray'),

                    RepeatableEntry::make('categories')
                        ->label('Categorias Conta Azul')
                        ->schema([
                            TextEntry::make('nome')->label('Nome'),
                        ])
                        ->placeholder('Nenhuma categoria informada')
                        ->columnSpan(2),

                    RepeatableEntry::make('cost_centers')
                        ->label('Centros de custo')
                        ->schema([
                            TextEntry::make('nome')->label('Nome'),
                        ])
                        ->placeholder('Nenhum centro de custo informado')
                        ->columnSpanFull(),
                ]),

            Section::make('Conta e origem')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('bank_account_label')
                        ->label('Conta bancária')
                        ->state(fn (ContaAzulImportedBill $record): string => $record->bankAccount?->optionLabel() ?? '—'),

                    TextEntry::make('conta_azul_account_id')
                        ->label('Conta financeira Conta Azul')
                        ->formatStateUsing(fn (?string $state): string => BankIdentifierMasker::maskContaAzulId($state))
                        ->fontFamily(FontFamily::Mono),

                    TextEntry::make('created_at')
                        ->label('Importado em')
                        ->dateTime('d/m/Y H:i'),

                    TextEntry::make('conta_azul_import_batch_id')
                        ->label('Lote de origem')
                        ->formatStateUsing(fn (?int $state): string => $state !== null ? "#{$state}" : '—')
                        ->url(fn (ContaAzulImportedBill $record): ?string => $record->conta_azul_import_batch_id !== null
                            ? ContaAzulImportBatchResource::getUrl('view', ['record' => $record->conta_azul_import_batch_id])
                            : null),

                    TextEntry::make('content_batch_id')
                        ->label('Lote do conteúdo vigente')
                        ->formatStateUsing(fn (?int $state): string => $state !== null ? "#{$state}" : '—')
                        ->url(fn (ContaAzulImportedBill $record): ?string => $record->content_batch_id !== null
                            ? ContaAzulImportBatchResource::getUrl('view', ['record' => $record->content_batch_id])
                            : null)
                        ->helperText('Lote que estabeleceu o conteúdo atual.'),

                    TextEntry::make('last_seen_batch_id')
                        ->label('Última observação')
                        ->formatStateUsing(fn (?int $state): string => $state !== null ? "#{$state}" : '—')
                        ->url(fn (ContaAzulImportedBill $record): ?string => $record->last_seen_batch_id !== null
                            ? ContaAzulImportBatchResource::getUrl('view', ['record' => $record->last_seen_batch_id])
                            : null),
                ]),

            Section::make('Fonte e versão')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('payload_fingerprint')
                        ->label('Impressão digital vigente')
                        ->fontFamily(FontFamily::Mono)
                        ->copyable(),

                    TextEntry::make('previous_payload_fingerprint')
                        ->label('Versão anterior')
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('Primeira versão observada'),

                    TextEntry::make('attribution_source_fingerprint')
                        ->label('Versão da decisão vigente')
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('Sem decisão')
                        ->helperText('Versão da fonte contra a qual a atribuição vigente foi decidida.'),

                    TextEntry::make('external_updated_at')
                        ->label('Atualizado na fonte em')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                ]),

            Section::make('Atribuição')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('attributedEmission.name')
                        ->label('Emissão atribuída')
                        ->placeholder('Não atribuído')
                        ->weight('bold')
                        ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),

                    TextEntry::make('attributedBy.name')
                        ->label('Responsável')
                        ->placeholder('—'),

                    TextEntry::make('attributed_at')
                        ->label('Decidido em')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('attribution_reason')
                        ->label('Justificativa')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
