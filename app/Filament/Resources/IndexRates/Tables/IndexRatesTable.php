<?php

namespace App\Filament\Resources\IndexRates\Tables;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\IndexRate;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\PuCalculator\PuDisplay;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class IndexRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading('Histórico dos índices')
            ->description('Valores publicados e projetados utilizados nos cálculos e curvas da plataforma.')
            ->columns([
                TextColumn::make('indexer')
                    ->label('Indexador')
                    ->badge()
                    ->weight('semibold')
                    ->color(fn ($state): string => match ($state) {
                        'CDI' => 'info',
                        'IPCA' => 'warning',
                        'PRE' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn ($state): string => match ($state) {
                        'CDI' => 'heroicon-o-chart-bar',
                        'IPCA' => 'heroicon-o-scale',
                        'PRE' => 'heroicon-o-banknotes',
                        default => 'heroicon-o-table-cells',
                    })
                    ->sortable(),

                TextColumn::make('rate_date')
                    ->label('Data de referência')
                    ->date('d/m/Y')
                    ->fontFamily('mono')
                    ->sortable(),

                TextColumn::make('rate_value')
                    ->label('Valor')
                    ->formatStateUsing(function ($state, IndexRate $record): string {
                        if ($state === null || $state === '') {
                            return '—';
                        }

                        $num = (float) $state;
                        $formatted = number_format($num, 8, ',', '.');

                        if ($record->indexer === 'CDI') {
                            return $formatted.' % a.a.';
                        }

                        return $formatted;
                    })
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->sortable(),

                TextColumn::make('is_projected')
                    ->label('Origem')
                    ->badge()
                    ->state(fn (IndexRate $record): string => $record->isProjectedRate() ? 'Projetado' : 'Publicado')
                    ->color(fn (IndexRate $record): string => $record->isProjectedRate() ? 'warning' : 'success')
                    ->icon(fn (IndexRate $record): string => $record->isProjectedRate() ? 'heroicon-o-presentation-chart-line' : 'heroicon-o-check-badge'),

                TextColumn::make('projectionSeries.status')
                    ->label('Série projetada')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Fonte')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'bcb_sgs' => 'Banco Central',
                        'manual_import' => 'Importação manual',
                        'anbima' => 'ANBIMA',
                        'b3' => 'B3',
                        default => (string) ($state ?? '—'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'bcb_sgs' => 'info',
                        'manual_import' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable()
                    ->searchable(),

                TextColumn::make('external_series_code')
                    ->label('Série SGS')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('fetched_at')
                    ->label('Sincronizado em')
                    ->dateTime('d/m/Y · H:i', timezone: BusinessTime::timezone())
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('projection_source')
                    ->label('Fonte projeção')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('indexer')
                    ->label('Indexador')
                    ->options([
                        PuIndexer::Cdi->value => PuIndexer::Cdi->value,
                        PuIndexer::Ipca->value => PuIndexer::Ipca->value,
                        PuIndexer::Prefixed->value => PuIndexer::Prefixed->value,
                    ]),

                SelectFilter::make('source')
                    ->label('Fonte')
                    ->options([
                        'bcb_sgs' => 'Banco Central (SGS)',
                        'manual_import' => 'Importação manual',
                        'anbima' => 'ANBIMA',
                        'b3' => 'B3',
                    ]),

                SelectFilter::make('is_projected')
                    ->label('Origem')
                    ->options([
                        '0' => 'Publicados',
                        '1' => 'Projetados',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        '0' => $query->where('is_projected', false),
                        '1' => $query->where('is_projected', true),
                        default => $query,
                    }),

                Filter::make('rate_date')
                    ->label('Data de referência')
                    ->form([
                        DatePicker::make('rate_from')
                            ->label('A partir de'),
                        DatePicker::make('rate_until')
                            ->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['rate_from'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('rate_date', '>=', $date),
                            )
                            ->when(
                                $data['rate_until'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('rate_date', '<=', $date),
                            );
                    }),
            ])
            ->emptyStateHeading('Nenhum índice registrado')
            ->emptyStateDescription('Sincronize ou importe índices econômicos para acompanhar seus valores históricos e projetados.')
            ->emptyStateIcon(Heroicon::OutlinedTableCells)
            ->searchPlaceholder('Buscar por indexador, fonte ou série...')
            ->searchOnBlur(false)
            ->striped()
            ->defaultSort('rate_date', 'desc')
            ->recordActions([
                self::correctionAction(),
            ]);
    }

    /**
     * Correção governada de uma observação realizada (Fase 8, UI-4): tudo passa
     * pelo {@see IndexRateCorrectionService}, que exige motivo, grava a trilha e
     * marca para reprocessamento as curvas governadas que usaram a observação --
     * sem reescrever linha homologada.
     */
    private static function correctionAction(): Action
    {
        return Action::make('correctObservation')
            ->label('Corrigir')
            ->icon('heroicon-m-pencil-square')
            ->color('warning')
            ->visible(fn (IndexRate $record): bool => (auth()->user()?->can(AccessPermission::PuIndexCorrect->value) ?? false)
                && ! $record->isProjectedRate()
                && in_array($record->indexer, [PuIndexer::Cdi->value, PuIndexer::Ipca->value], true))
            ->modalHeading(fn (IndexRate $record): string => sprintf('Corrigir observação de %s de %s', $record->indexer, PuDisplay::date($record->rate_date)))
            ->modalDescription('Correção governada: o valor anterior, a origem, quem corrigiu, quando e por quê ficam na trilha. Curvas homologadas que usaram esta observação NÃO são reescritas — ficam com "reprocessamento necessário" até uma nova versão ser homologada; a atualização das obrigações afetadas é pedida em seguida. Nada aqui altera liquidações.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Registrar correção')
            ->modalContent(fn (IndexRate $record): Htmlable => view('filament.index-rates.correction-preview', [
                'rate' => $record,
                'affected' => self::affectedVersions($record),
            ]))
            ->fillForm(fn (IndexRate $record): array => ['reviewed_value' => (string) $record->rate_value])
            ->schema(fn (IndexRate $record): array => [
                Hidden::make('reviewed_value'),
                TextInput::make('new_value')
                    ->label($record->indexer === PuIndexer::Cdi->value ? 'Novo valor (% a.a., base 252)' : 'Novo número-índice')
                    ->required()
                    ->rule('regex:/^-?\d+(\.\d{1,8})?$/')
                    ->validationMessages(['regex' => 'Use ponto como separador decimal e até 8 casas (a escala gravada). O valor não é arredondado.'])
                    ->extraInputAttributes(['class' => 'tabular-nums']),
                TextInput::make('new_source')
                    ->label('Nova origem (opcional)')
                    ->helperText('Sem preencher, a origem atual é mantida.')
                    ->maxLength(100),
                TextInput::make('new_source_reference')
                    ->label('Referência da origem (opcional)')
                    ->maxLength(190),
                Textarea::make('reason')
                    ->label('Motivo da correção')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                Checkbox::make('confirm')
                    ->label('Entendo que as curvas homologadas afetadas ficarão aguardando reprocessamento e que as linhas homologadas não mudam.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme o efeito da correção para continuar.']),
            ])
            ->action(function (IndexRate $record, array $data): void {
                $current = IndexRate::query()->whereKey($record->getKey())->value('rate_value');

                if (bccomp((string) $current, (string) ($data['reviewed_value'] ?? ''), 8) !== 0) {
                    Notification::make()
                        ->title('A observação mudou enquanto o formulário estava aberto.')
                        ->body(sprintf('Agora ela vale %s. Nada foi gravado: feche, reabra e confira antes de corrigir.', (string) $current))
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                /** @var User $user */
                $user = auth()->user();

                try {
                    $correction = app(IndexRateCorrectionService::class)->correctAs(
                        $user,
                        PuIndexer::from((string) $record->indexer),
                        $record->rate_date->toDateString(),
                        (string) $data['new_value'],
                        (string) ($data['reason'] ?? ''),
                        filled($data['new_source'] ?? null) ? (string) $data['new_source'] : null,
                        filled($data['new_source_reference'] ?? null) ? (string) $data['new_source_reference'] : null,
                    );
                } catch (AuthorizationException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Correção não registrada.')
                        ->body($exception->getMessage().' Nada foi gravado.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $affected = count($correction->affected_curve_versions ?? []);

                Notification::make()
                    ->title('Observação corrigida.')
                    ->body(sprintf(
                        'Correção #%d: de %s para %s. %s',
                        $correction->id,
                        (string) $correction->previous_rate_value,
                        (string) $correction->new_rate_value,
                        $affected === 0
                            ? 'Nenhuma curva usava a observação.'
                            : sprintf('%d versão(ões) de curva usavam a observação: as governadas aguardam reprocessamento (nova versão homologada).', $affected),
                    ))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function affectedVersions(IndexRate $rate): array
    {
        $versions = app(IndexRateCorrectionService::class)->dependentVersions(PuIndexer::from((string) $rate->indexer), $rate->rate_date->toDateString());
        $names = Emission::query()->whereKey(array_column($versions, 'emission_id'))->pluck('name', 'id');

        return array_map(fn (array $version): array => [
            ...$version,
            'emission' => $names[$version['emission_id']] ?? 'Emissão #'.$version['emission_id'],
        ], $versions);
    }
}
