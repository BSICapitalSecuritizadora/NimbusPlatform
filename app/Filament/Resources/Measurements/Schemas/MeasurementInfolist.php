<?php

namespace App\Filament\Resources\Measurements\Schemas;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Services\MeasurementWorkflow;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MeasurementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // 1. Resumo Executivo da Medição (Full Width, grid de 3 colunas no desktop)
            Section::make('Resumo da Medição')
                ->extraAttributes(['class' => 'bsi-measurement-summary-section'])
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2, 'lg' => 3])
                ->schema([
                    TextEntry::make('operation.title')
                        ->label('Operação')
                        ->extraAttributes(['class' => 'bsi-summary-operation'])
                        ->formatStateUsing(fn (Measurement $record): string => $record->operation ? "{$record->operation->code} · {$record->operation->title}" : '—'),
                    TextEntry::make('reference_month')
                        ->label('Competência')
                        ->extraAttributes(['class' => 'bsi-summary-month'])
                        ->date('m/Y')
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->label('Situação')
                        ->extraAttributes(['class' => 'bsi-summary-status'])
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Measurement::STATUS_OPTIONS[$state] ?? $state),
                    TextEntry::make('current_stage')
                        ->label('Etapa atual')
                        ->extraAttributes(['class' => 'bsi-summary-stage'])
                        ->badge()
                        ->state(fn (Measurement $record): string => MeasurementWorkflow::STAGE_LABELS[app(MeasurementWorkflow::class)->unifiedStage($record)] ?? '—')
                        ->color(fn (Measurement $record): string => MeasurementWorkflow::STAGE_COLORS[app(MeasurementWorkflow::class)->unifiedStage($record)] ?? 'gray'),
                    TextEntry::make('uploadedByUser.name')
                        ->label('Enviada por')
                        ->extraAttributes(['class' => 'bsi-summary-uploader'])
                        ->placeholder('—'),
                    TextEntry::make('uploaded_at')
                        ->label('Enviada em')
                        ->extraAttributes(['class' => 'bsi-summary-uploaded-at'])
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                    TextEntry::make('storage_path')
                        ->label('Arquivo principal legado')
                        ->state(fn (Measurement $record): ?string => filled($record->storage_path) ? 'Abrir arquivo' : null)
                        ->url(fn (Measurement $record): ?string => filled($record->storage_path)
                            ? route('admin.measurements.file.download', $record)
                            : null)
                        ->openUrlInNewTab()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (Measurement $record): bool => filled($record->storage_path)),
                    TextEntry::make('notes')
                        ->label('Observações')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (Measurement $record): bool => filled($record->notes)),
                ]),

            // 2. Aprovação por Responsabilidade (Full Width, grid interno de 5 mini-cards lado a lado)
            Section::make('Aprovação por Responsabilidade')
                ->description('Responsáveis das etapas no escopo da operação e status de decisão.')
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('responsibilities')
                        ->label('')
                        ->view('filament.infolists.measurement-responsibilities'),
                ]),

            // 3. Condição e Conciliação Financeira (Full Width)
            Section::make('Condição e Conciliação Financeira')
                ->description('Referência aprovada pela Engenharia. Novas divergências exigem justificativa e aceite explícito do Finalizador.')
                ->columnSpanFull()
                ->visible(fn (Measurement $record): bool => is_array($record->engineering_snapshot)
                    && ($record->engineering_snapshot['plan_sets'] ?? []) !== [])
                ->schema([
                    ViewEntry::make('financial_reconciliation')
                        ->label('')
                        ->view('filament.infolists.measurement-financial-reconciliation'),
                    ViewEntry::make('financial_assessments')->label('Regras e justificativas dos pagamentos')
                        ->view('filament.infolists.measurement-financial-assessments'),
                ]),

            // 4. Pagamentos e Comprovações (Full Width)
            Section::make('Pagamentos e Comprovações')
                ->description('Registros de pagamento da competência e histórico de comprovantes.')
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('payments_list')
                        ->label('')
                        ->view('filament.infolists.measurement-payments-list'),
                ]),

            // 5. Arquivos por Empreendimento (Full Width)
            Section::make('Arquivos por Empreendimento')
                ->description('Relatórios e laudos vinculados aos empreendimentos.')
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('assets')
                        ->label('')
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->schema([
                            TextEntry::make('planSet.construction.development_name')->label('Empreendimento')->placeholder('—'),
                            TextEntry::make('storage_path')
                                ->label('Arquivo')
                                ->state(fn (MeasurementAsset $record): ?string => filled($record->storage_path) ? 'Abrir arquivo' : null)
                                ->url(fn (MeasurementAsset $record): ?string => filled($record->storage_path)
                                    ? route('admin.measurements.assets.download', $record)
                                    : null)
                                ->openUrlInNewTab()
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('primary')
                                ->placeholder('—'),
                        ]),
                ]),

            // 6. Linha do Tempo (Full Width)
            Section::make('Linha do Tempo')
                ->description('Histórico completo da medição: envio, aprovações, recusas, devoluções, pausas e pagamentos.')
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('timeline')
                        ->label('')
                        ->view('filament.infolists.measurement-timeline'),
                ]),

            // 7. Atividade por Etapa (Full Width)
            Section::make('Atividade por Etapa')
                ->description('Decisões formais registradas pelos responsáveis em cada fase do fluxo.')
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('reviews_table')
                        ->label('')
                        ->view('filament.infolists.measurement-reviews-table'),
                ]),
        ]);
    }
}
