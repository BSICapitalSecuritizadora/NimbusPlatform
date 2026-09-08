<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MonthlyConsolidatedRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';

    protected static ?string $title = 'Consolidado mensal';

    protected static ?string $modelLabel = 'Consolidado';

    protected static ?string $pluralModelLabel = 'Consolidado mensal';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('due_date')
            ->heading('Consolidado mensal')
            ->description('Soma mensal dos pagamentos registrados para esta despesa.')
            ->modifyQueryUsing(function (Builder $query): Builder {
                $monthExpr = $query->getConnection()->getDriverName() === 'sqlite'
                    ? "strftime('%Y-%m-01', due_date)"
                    : "DATE_FORMAT(due_date, '%Y-%m-01')";

                return $query
                    ->selectRaw("{$monthExpr} as due_date")
                    ->selectRaw('SUM(amount) as amount')
                    ->selectRaw('COUNT(*) as total_payments')
                    ->selectRaw('MIN(id) as id')
                    ->groupByRaw($monthExpr)
                    ->where('due_date', '>=', '2025-01-01')
                    ->reorder();
            })
            ->columns([
                TextColumn::make('due_date')
                    ->label('Mês')
                    ->date('M/Y')
                    ->sortable(),

                TextColumn::make('total_payments')
                    ->label('Pagamentos')
                    ->alignCenter()
                    ->badge(),

                TextColumn::make('amount')
                    ->label('Valor total')
                    ->money('BRL')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('due_date', 'desc')
            ->defaultKeySort(false)
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->paginated(false)
            ->emptyStateHeading('Nenhum histórico de pagamento registrado')
            ->emptyStateDescription('Os pagamentos registrados para esta despesa aparecerão aqui.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
