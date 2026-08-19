<?php

namespace App\Filament\Widgets\PuCalculator;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

class PuCurveOperationalTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.pu-calculator.pu-curve-operational-table-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected static ?string $heading = 'Curvas por Emissão';

    /**
     * Recorte aplicado pelo indicador selecionado no resumo operacional.
     */
    public ?string $focusedState = null;

    /**
     * Espelha na tabela o indicador escolhido no widget de resumo.
     */
    #[On('pu-curves-focus')]
    public function focusCurves(?string $state = null): void
    {
        $this->focusedState = $state;

        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        $monitor = app(PuOperationalMonitorService::class);
        $missingCdiIds = $monitor->missingCdiEmissionIds();
        $canExport = auth()->user()?->can('pu.curve.export') ?? false;

        return $table
            ->query(
                Emission::query()
                    ->whereHas('puParameter')
                    ->with([
                        'puParameter',
                        'latestPuCurveVersion.generatedBy',
                    ]),
            )
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyFocus($query, $missingCdiIds))
            ->description($this->focusDescription())
            ->searchPlaceholder('Buscar emissão...')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                TextColumn::make('name')
                    ->label('Emissão')
                    ->weight('semibold')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Emission $record): string => filled($record->latestPuCurveVersion?->calculation_version)
                        ? 'Versão '.$record->latestPuCurveVersion->calculation_version
                        : 'Sem versão gerada'),

                TextColumn::make('latestPuCurveVersion.status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Emission $record): PuCurveStatus|string => $record->latestPuCurveVersion?->status ?? 'sem_curva')
                    ->formatStateUsing(fn ($state): string => $state instanceof PuCurveStatus ? $state->label() : 'Sem curva')
                    ->color(fn ($state): string => $state instanceof PuCurveStatus ? $state->color() : 'gray'),

                TextColumn::make('indexer')
                    ->label('Indexador')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Emission $record): string => $record->latestPuCurveVersion?->parameters_snapshot['indexer']
                        ?? $record->puParameter?->indexer
                        ?? '—'),

                TextColumn::make('latestPuCurveVersion.generated_at')
                    ->label('Última geração')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Emission $record): ?string => $record->latestPuCurveVersion?->generated_at?->diffForHumans())
                    ->placeholder('—'),

                TextColumn::make('latestPuCurveVersion.validated_at')
                    ->label('Validação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('latestPuCurveVersion.homologated_at')
                    ->label('Homologação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('largest_pu_difference')
                    ->label('Maior dif. PU')
                    ->alignEnd()
                    ->tooltip('Maior diferença de PU apurada na validação contra a planilha de referência.')
                    ->state(fn (Emission $record): ?string => $record->latestPuCurveVersion?->validation_summary['largest_pu_difference'] ?? null)
                    ->formatStateUsing(fn (?string $state): string => is_numeric($state)
                        ? number_format((float) $state, 6, ',', '.')
                        : (string) $state)
                    ->color(fn (Emission $record): string => $record->latestPuCurveVersion?->status === PuCurveStatus::Divergent
                        ? 'danger'
                        : 'gray')
                    ->placeholder('—'),

                TextColumn::make('cdi_coverage')
                    ->label('CDI')
                    ->badge()
                    ->state(fn (Emission $record): string => in_array($record->id, $missingCdiIds, true) ? 'Faltante' : 'OK')
                    ->icon(fn (string $state): string => $state === 'Faltante' ? 'heroicon-m-no-symbol' : 'heroicon-m-check')
                    ->color(fn (string $state): string => $state === 'Faltante' ? 'danger' : 'success')
                    ->tooltip(fn (string $state): string => $state === 'Faltante'
                        ? 'Há lacuna bloqueante na série do índice exigido por esta emissão.'
                        : 'Série do índice íntegra para todo o período da curva.'),

                TextColumn::make('latestPuCurveVersion.generatedBy.name')
                    ->label('Responsável')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::ExtraSmall)
            ->filters([
                SelectFilter::make('status')
                    ->label('Status da curva')
                    ->options(collect(PuCurveStatus::cases())->mapWithKeys(fn (PuCurveStatus $s): array => [$s->value => $s->label()])->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereIn('id', $this->emissionIdsWithLatestStatus($value));
                    }),
            ])
            ->recordUrl(fn (Emission $record): string => route('filament.admin.resources.emissions.pu-history', ['record' => $record]))
            ->actions([
                Action::make('timeline')
                    ->label('Timeline')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->url(fn (Emission $record): string => route('filament.admin.resources.emissions.pu-history', ['record' => $record])),
                Action::make('homologationPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (Emission $record): bool => $canExport && $record->latestPuCurveVersion !== null)
                    ->url(fn (Emission $record): string => route('admin.emissions.pu-homologation.pdf', [
                        'emission' => $record,
                        'version' => $record->latestPuCurveVersion,
                    ])),
                Action::make('reprocess')
                    ->label('Reprocessar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->url(fn (Emission $record): string => route('filament.admin.resources.emissions.edit', ['record' => $record])),
            ])
            ->emptyStateIcon(filled($this->focusedState) ? 'heroicon-o-funnel' : 'heroicon-o-adjustments-horizontal')
            ->emptyStateHeading(filled($this->focusedState)
                ? 'Nenhuma emissão neste recorte'
                : 'Nenhuma emissão com PU configurado')
            ->emptyStateDescription(filled($this->focusedState)
                ? 'O indicador selecionado não corresponde a nenhuma emissão. Limpe o recorte para ver todas as curvas acompanhadas.'
                : 'Configure os parâmetros de PU em uma emissão para acompanhar a geração, a validação e a homologação da curva aqui.')
            ->emptyStateActions($this->resolveEmptyStateActions());
    }

    /**
     * @return array<int, Action>
     */
    protected function resolveEmptyStateActions(): array
    {
        if (filled($this->focusedState)) {
            return [
                Action::make('clearFocus')
                    ->label('Limpar recorte')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->action(fn () => $this->focusCurves(null)),
            ];
        }

        if (! EmissionResource::canViewAny()) {
            return [];
        }

        return [
            Action::make('goToEmissions')
                ->label('Ir para emissões')
                ->icon('heroicon-m-arrow-right')
                ->url(EmissionResource::getUrl('index')),
        ];
    }

    /**
     * Recorta a listagem conforme o indicador selecionado no resumo. Reaproveita
     * exatamente os mesmos criterios usados para contar cada indicador.
     *
     * @param  list<int>  $missingCdiIds
     */
    protected function applyFocus(Builder $query, array $missingCdiIds): Builder
    {
        $state = $this->focusedState;

        if (blank($state)) {
            return $query;
        }

        if ($state === 'missing_cdi') {
            return $query->whereIn('id', $missingCdiIds ?: [0]);
        }

        if ($state === 'sem_curva') {
            return $query->whereDoesntHave('puCurveVersions');
        }

        if (PuCurveStatus::tryFrom($state) === null) {
            return $query;
        }

        return $query->whereIn('id', $this->emissionIdsWithLatestStatus($state));
    }

    /**
     * IDs das emissoes cuja versao mais recente esta no status informado.
     *
     * @return Collection<int, int>
     */
    protected function emissionIdsWithLatestStatus(string $status): Collection
    {
        $latestIds = EmissionPuCurveVersion::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('emission_id')
            ->pluck('id');

        return EmissionPuCurveVersion::query()
            ->whereIn('id', $latestIds)
            ->where('status', $status)
            ->pluck('emission_id');
    }

    protected function focusDescription(): string
    {
        if (blank($this->focusedState)) {
            return 'Uma linha por emissão com parâmetros de PU, sempre na versão mais recente da curva.';
        }

        return 'Recorte ativo a partir dos indicadores acima. Limpe o recorte no resumo para voltar à lista completa.';
    }
}
