<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Models\EmissionPuDailyCurve;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabela operacional da curva: a linha de uma candidate nunca aparece aqui, para
 * não se misturar com a curva vigente numa tela sem coluna de papel.
 */
class PuDailyCurvesRelationManager extends RelationManager
{
    protected static string $relationship = 'operationalPuDailyCurves';

    protected static ?string $title = 'Curva PU Diário';

    protected static ?string $modelLabel = 'Linha da curva PU';

    protected static ?string $pluralModelLabel = 'Curva PU diário';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query)
            ->recordTitleAttribute('curve_date')
            ->searchPlaceholder('Buscar por data...')
            ->columns([
                TextColumn::make('curve_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->weight('medium')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('calculation_version')
                    ->label('Versão')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('updated_unit_value')
                    ->label('PU Atualizado')
                    ->weight('semibold')
                    ->numeric(8, ',', '.')
                    ->prefix('R$ ')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('residual_unit_value')
                    ->label('PU Residual')
                    ->numeric(8, ',', '.')
                    ->prefix('R$ ')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric(4, ',', '.')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('total_value')
                    ->label('Valor Total')
                    ->numeric(2, ',', '.')
                    ->prefix('R$ ')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('payment_total_value')
                    ->label('Pagamento Total')
                    ->numeric(2, ',', '.')
                    ->prefix('R$ ')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('index_rate_value')
                    ->label('CDI Usado')
                    ->numeric(8, ',', '.')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('dup_interest')
                    ->label('DUP')
                    ->tooltip('Dias úteis decorridos no período de juros')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('dut_interest')
                    ->label('DUT')
                    ->tooltip('Dias úteis totais no período de juros (base 252)')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('extended_at')
                    ->label('Anexado em')
                    ->tooltip('Dia acrescentado pela extensão diária depois da geração da versão. Numa curva homologada ou promovida, fica fora do trecho revisado.')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->alignCenter()
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('calculation_version')
                    ->label('Versão')
                    ->options(
                        fn () => EmissionPuDailyCurve::query()
                            ->where('emission_id', $this->ownerRecord->id)
                            ->operational()
                            ->orderByDesc('id')
                            ->pluck('calculation_version', 'calculation_version')
                            ->unique()
                            ->all(),
                    ),
            ])
            ->defaultSort('curve_date', 'desc')
            ->headerActions([])
            ->actions([
                Action::make('memory')
                    ->label('Memória')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->tooltip('Visualizar memória de cálculo da linha')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalHeading('Memória de Cálculo da Linha')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (EmissionPuDailyCurve $record) => view('filament.emissions.pu-curve-memory', [
                        'row' => $record,
                    ])),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading('Nenhuma curva de PU gerada')
            ->emptyStateDescription('A curva diária será exibida aqui após o processamento e geração do PU para esta emissão.');
    }
}
