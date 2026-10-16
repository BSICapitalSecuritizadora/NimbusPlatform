<?php

namespace App\Filament\Resources\ContaAzulImportedBills\RelationManagers;

use App\Models\ContaAzulBillAttribution;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Append-only attribution history. Read-only by design: decisions are
 * recorded through the governed attribution service, never edited here.
 */
class AttributionsRelationManager extends RelationManager
{
    protected static string $relationship = 'attributions';

    protected static ?string $title = 'Histórico de atribuição';

    protected static ?string $modelLabel = 'Decisão';

    protected static ?string $pluralModelLabel = 'Decisões';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->defaultSort('id', 'asc')
            ->emptyStateHeading('Nenhuma decisão registrada')
            ->emptyStateDescription('A primeira atribuição e cada correção governada aparecerão aqui, em ordem, sem jamais apagar uma decisão anterior.')
            ->emptyStateIcon('heroicon-o-clock')
            ->columns([
                TextColumn::make('action')
                    ->label('Decisão')
                    ->formatStateUsing(fn (string $state): string => ContaAzulBillAttribution::ACTION_OPTIONS[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => $state === ContaAzulBillAttribution::ACTION_CORRECTED ? 'warning' : 'success'),

                TextColumn::make('fromEmission.name')
                    ->label('De')
                    ->placeholder('—')
                    ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),

                TextColumn::make('toEmission.name')
                    ->label('Para')
                    ->weight('semibold')
                    ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),

                TextColumn::make('decidedBy.name')
                    ->label('Responsável')
                    ->placeholder('—'),

                TextColumn::make('decided_at')
                    ->label('Decidido em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Justificativa')
                    ->wrap()
                    ->lineClamp(3),

                TextColumn::make('source_fingerprint')
                    ->label('Versão da fonte')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 12).'…')
                    ->fontFamily(FontFamily::Mono)
                    ->tooltip(fn (ContaAzulBillAttribution $record): string => $record->source_fingerprint)
                    ->copyable()
                    ->copyableState(fn (ContaAzulBillAttribution $record): string => $record->source_fingerprint)
                    ->toggleable(),

                TextColumn::make('is_exceptional')
                    ->label('Excepcional')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Excepcional' : '—')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
            ]);
    }
}
