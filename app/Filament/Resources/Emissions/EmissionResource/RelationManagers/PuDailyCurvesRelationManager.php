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

class PuDailyCurvesRelationManager extends RelationManager
{
    protected static string $relationship = 'puDailyCurves';

    protected static ?string $title = 'Curva PU Diario';

    protected static ?string $modelLabel = 'Linha da curva PU';

    protected static ?string $pluralModelLabel = 'Curva PU diario';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query)
            ->recordTitleAttribute('curve_date')
            ->columns([
                TextColumn::make('curve_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('calculation_version')
                    ->label('Versao')
                    ->badge(),
                TextColumn::make('updated_unit_value')
                    ->label('PU atualizado')
                    ->numeric(8, ',', '.'),
                TextColumn::make('residual_unit_value')
                    ->label('PU residual')
                    ->numeric(8, ',', '.'),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric(4, ',', '.'),
                TextColumn::make('total_value')
                    ->label('Valor total')
                    ->numeric(8, ',', '.'),
                TextColumn::make('payment_total_value')
                    ->label('Pagamento total')
                    ->numeric(8, ',', '.'),
                TextColumn::make('index_rate_value')
                    ->label('CDI usado')
                    ->numeric(8, ',', '.'),
                TextColumn::make('dup_interest')
                    ->label('DUP')
                    ->numeric(),
                TextColumn::make('dut_interest')
                    ->label('DUT')
                    ->numeric(),
            ])
            ->filters([
                SelectFilter::make('calculation_version')
                    ->label('Versao')
                    ->options(
                        EmissionPuDailyCurve::query()
                            ->where('emission_id', $this->ownerRecord->id)
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
                    ->label('Memoria')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalHeading('Memoria de calculo da linha')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (EmissionPuDailyCurve $record) => view('filament.emissions.pu-curve-memory', [
                        'row' => $record,
                    ])),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma curva de PU gerada');
    }
}
