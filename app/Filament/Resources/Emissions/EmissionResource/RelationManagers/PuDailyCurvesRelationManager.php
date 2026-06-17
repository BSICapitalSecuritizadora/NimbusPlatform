<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Models\EmissionPuDailyCurve;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PuDailyCurvesRelationManager extends RelationManager
{
    protected static string $relationship = 'puDailyCurves';

    protected static ?string $title = 'Curva PU Diário';

    protected static ?string $modelLabel = 'Linha da curva PU';

    protected static ?string $pluralModelLabel = 'Curva PU diário';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        $latestVersion = EmissionPuDailyCurve::latestCalculationVersionForEmission($this->ownerRecord->id);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $latestVersion === null
                ? $query
                : $query->where('calculation_version', $latestVersion))
            ->recordTitleAttribute('curve_date')
            ->columns([
                TextColumn::make('curve_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('calculation_version')
                    ->label('Versão')
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
            ->defaultSort('curve_date', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma curva de PU gerada');
    }
}
