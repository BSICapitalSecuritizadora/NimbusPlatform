<?php

namespace App\Filament\Resources\SalesBoardCycles\RelationManagers;

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Services\SalesBoards\SalesBoardBaselineDiffService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * O histórico de versões do ciclo.
 *
 * Nenhuma versão é editável ou apagável -- inclusive a vigente. O valor do
 * histórico está justamente em ele não poder ser arrumado depois: se a V1 fosse
 * corrigível, a V2 não provaria nada.
 *
 * Comparar duas versões é leitura pura sobre o que foi gravado, sem tocar na
 * fonte viva.
 */
class SalesBoardCycleBaselinesRelationManager extends RelationManager
{
    protected static string $relationship = 'baselines';

    protected static ?string $title = 'Versões';

    protected static ?string $modelLabel = 'Versão';

    protected static ?string $pluralModelLabel = 'Versões';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->description('Cada recálculo cria uma versão nova. As anteriores permanecem, inteiras.')
            ->defaultSort('version', 'desc')
            ->emptyStateHeading('Nenhuma versão')
            ->columns([
                TextColumn::make('version')
                    ->label('Versão')
                    ->formatStateUsing(fn (int $state): string => 'V'.$state)
                    ->badge()
                    ->color(fn (SalesBoardCycleBaseline $record): string => $record->id === $this->getOwnerRecord()->current_baseline_id
                        ? 'success'
                        : 'gray')
                    ->description(fn (SalesBoardCycleBaseline $record): ?string => $record->id === $this->getOwnerRecord()->current_baseline_id
                        ? 'vigente'
                        : null)
                    ->sortable(),

                TextColumn::make('computed_at')
                    ->label('Apurada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('computedBy.name')
                    ->label('Por')
                    ->placeholder('—'),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->placeholder('Versão inicial')
                    ->wrap()
                    ->limit(80),

                TextColumn::make('units_total')
                    ->label('Unidades')
                    ->description(fn (SalesBoardCycleBaseline $record): string => sprintf(
                        '%d / %d / %d / %d',
                        $record->stock_units,
                        $record->financed_units,
                        $record->settled_units,
                        $record->exchanged_units,
                    ))
                    ->tooltip('Estoque / Financiado / Quitado / Permutado')
                    ->alignEnd(),

                TextColumn::make('stock_value')
                    ->label('Estoque')
                    ->money('BRL')
                    ->placeholder('indisponível')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('stale_impact')
                    ->label('Fonte')
                    ->badge()
                    ->formatStateUsing(fn (?SalesBoardStaleImpact $state): string => ($state ?? SalesBoardStaleImpact::None)->label())
                    ->color(fn (?SalesBoardStaleImpact $state): string => ($state ?? SalesBoardStaleImpact::None)->color())
                    ->description(fn (SalesBoardCycleBaseline $record): ?string => $record->stale_detected_at
                        ?->format('\d\i\v\e\r\g\i\u \e\m d/m/Y')),

                TextColumn::make('snapshot_fingerprint')
                    ->label('Resumo da posição')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 12).'…')
                    ->copyable()
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('source_fingerprint')
                    ->label('Resumo da fonte')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 12).'…')
                    ->copyable()
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->actions([
                $this->compareAction(),
            ])
            ->toolbarActions([])
            ->extraAttributes(['class' => 'bsi-cycle-toolbar-end']);
    }

    /**
     * Compara esta versão com outra do mesmo ciclo.
     */
    protected function compareAction(): Action
    {
        return Action::make('compare')
            ->label('Comparar')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->modalWidth(Width::FourExtraLarge)
            ->modalHeading(fn (SalesBoardCycleBaseline $record): string => 'Comparar a partir da '.$record->versionLabel())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->visible(fn (): bool => $this->otherVersions()->isNotEmpty())
            ->schema([
                Select::make('other_baseline_id')
                    ->label('Comparar com')
                    ->options(fn (SalesBoardCycleBaseline $record): array => $this->otherVersions($record)
                        ->mapWithKeys(fn (SalesBoardCycleBaseline $other): array => [
                            $other->getKey() => $other->versionLabel().' — '.$other->computed_at?->format('d/m/Y H:i'),
                        ])
                        ->all())
                    ->live()
                    ->required(),

                TextEntry::make('diff')
                    ->label('Diferenças')
                    ->state(fn (SalesBoardCycleBaseline $record, Get $get): string => $this->describeDiff(
                        $record,
                        $get('other_baseline_id'),
                    ))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return Collection<int, SalesBoardCycleBaseline>
     */
    protected function otherVersions(?SalesBoardCycleBaseline $except = null): Collection
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getOwnerRecord();

        return SalesBoardCycleBaseline::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->orderByDesc('version')
            ->get();
    }

    /**
     * O diff entre duas versões, em texto.
     *
     * Só orquestra: quem monta a comparação é o serviço de diff, e quem a
     * descreve é o próprio DTO. A tela não pode ter uma segunda opinião sobre o
     * que mudou.
     */
    protected function describeDiff(SalesBoardCycleBaseline $record, mixed $otherId): string
    {
        if (blank($otherId)) {
            return 'Escolha a versão com que comparar.';
        }

        $other = SalesBoardCycleBaseline::query()->with(['lines', 'movements', 'cycle'])->find($otherId);

        if ($other === null) {
            return 'Versão não encontrada.';
        }

        [$before, $after] = $record->version <= $other->version ? [$record, $other] : [$other, $record];

        $diff = app(SalesBoardBaselineDiffService::class)->compare(
            SalesBoardComparableSnapshot::fromBaseline($before->load(['lines', 'movements', 'cycle'])),
            SalesBoardComparableSnapshot::fromBaseline($after->load(['lines', 'movements', 'cycle'])),
        );

        return sprintf('%s → %s', $before->versionLabel(), $after->versionLabel())."\n".$diff->describe();
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
