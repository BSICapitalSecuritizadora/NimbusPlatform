<?php

namespace App\Filament\Widgets\IndexRates;

use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Models\PuIndexSyncAttempt;
use App\Support\PuCalculator\PuDisplay;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Histórico das consultas à fonte do índice (Fase 8, UI-21), a partir das
 * tentativas que a Fase 6 já registra. Separa a fonte que FALHOU da que
 * respondeu sem observação nova -- antes da divulgação esperada, isso é normal.
 */
class PuIndexSyncAttemptsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Histórico de sincronização dos índices';

    public function table(Table $table): Table
    {
        return $table
            ->query(PuIndexSyncAttempt::query())
            ->description('Cada consulta à fonte (BCB/SGS), automática ou manual. "Sem observação nova" não é falha: antes do horário de divulgação esperado, a fonte ainda não tem o dado do dia.')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->label('Consulta')
                    ->state(fn (PuIndexSyncAttempt $record): ?string => PuDisplay::dateTime($record->started_at))
                    ->description(fn (PuIndexSyncAttempt $record): string => sprintf(
                        'Período %s a %s%s',
                        PuDisplay::date($record->requested_from) ?? '—',
                        PuDisplay::date($record->requested_to) ?? '—',
                        $record->finished_at !== null ? sprintf(' · %d s', max(0, $record->started_at?->diffInSeconds($record->finished_at) ?? 0)) : ' · em andamento',
                    )),
                TextColumn::make('indexer')
                    ->label('Índice')
                    ->badge()
                    ->color('gray')
                    ->description(fn (PuIndexSyncAttempt $record): ?string => $record->source),
                TextColumn::make('outcome')
                    ->label('Resultado e próximo passo')
                    ->badge()
                    ->formatStateUsing(fn (PuIndexSyncAttempt $record): string => $record->outcome?->label() ?? 'Em andamento')
                    ->color(fn (PuIndexSyncAttempt $record): string => match ($record->outcome) {
                        PuIndexSyncOutcome::NewObservations => 'success',
                        PuIndexSyncOutcome::NoNewObservation => 'gray',
                        PuIndexSyncOutcome::PartialFailure, PuIndexSyncOutcome::HistoricalCorrection => 'warning',
                        PuIndexSyncOutcome::RateConflict, PuIndexSyncOutcome::Failed => 'danger',
                        default => 'info',
                    })
                    ->icon(fn (PuIndexSyncAttempt $record): string => match ($record->outcome) {
                        PuIndexSyncOutcome::NewObservations => 'heroicon-m-check-circle',
                        PuIndexSyncOutcome::NoNewObservation => 'heroicon-m-clock',
                        PuIndexSyncOutcome::Failed => 'heroicon-m-x-circle',
                        default => 'heroicon-m-exclamation-triangle',
                    })
                    ->description(fn (PuIndexSyncAttempt $record): string => trim(($record->failure_category !== null ? $record->failure_category->label().'. ' : '').self::nextStep($record)))
                    ->wrap(),
                TextColumn::make('created')
                    ->label('Novas · conflitos')
                    ->state(fn (PuIndexSyncAttempt $record): string => sprintf('%d · %d', (int) $record->created, (int) $record->conflicts))
                    ->alignEnd(),
                TextColumn::make('latest_observation_date')
                    ->label('Última observação')
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('indexer')
                    ->label('Índice')
                    ->options(['CDI' => 'CDI', 'IPCA' => 'IPCA']),
                SelectFilter::make('outcome')
                    ->label('Resultado')
                    ->options(collect(PuIndexSyncOutcome::cases())->mapWithKeys(fn (PuIndexSyncOutcome $outcome): array => [$outcome->value => $outcome->label()])->all()),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Nenhuma sincronização registrada')
            ->emptyStateDescription('Sem consultas registradas, não dá para distinguir "ainda não divulgado" de "fonte fora do ar". Confira o agendador da sincronização.');
    }

    private static function nextStep(PuIndexSyncAttempt $record): string
    {
        return match ($record->outcome) {
            PuIndexSyncOutcome::NewObservations => 'Nada: as observações novas entram na extensão diária das curvas.',
            PuIndexSyncOutcome::NoNewObservation => 'Nada, se ainda não passou do horário de divulgação esperado; depois dele, o monitor abre incidente.',
            PuIndexSyncOutcome::PartialFailure => 'Repetir a sincronização: parte dos blocos falhou ('.(string) ($record->error_message ?? 'sem detalhe').').',
            PuIndexSyncOutcome::RateConflict => 'Comparar com a fonte e, se for o caso, corrigir a observação com motivo (ação "Corrigir").',
            PuIndexSyncOutcome::HistoricalCorrection => 'A fonte revisou histórico: conferir as curvas marcadas para reprocessamento.',
            PuIndexSyncOutcome::Failed => 'Tentar de novo quando a fonte responder'.($record->failure_category?->isRetryable() === false ? '; a falha não é passageira: conferir a configuração da série' : '').'.',
            default => 'Aguardar o término da consulta.',
        };
    }
}
