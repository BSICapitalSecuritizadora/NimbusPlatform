<?php

namespace App\Filament\Resources\Receivables\Tables;

use App\Models\Receivable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Emissao')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reference_month')
                    ->label('Competencia')
                    ->date('m/Y')
                    ->sortable(),

                TextColumn::make('portfolio_id')
                    ->label('Carteira')
                    ->sortable(),

                TextColumn::make('active_contracts_count')
                    ->label('Contratos ativos')
                    ->sortable(),

                TextColumn::make('expected_amortization_amount')
                    ->label('Esperado amortizacao')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('expected_interest_amount')
                    ->label('Esperado juros')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('monthly_default_balance_amount')
                    ->label('Inadimplencia mes')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('total_default_balance_amount')
                    ->label('Inadimplencia geral')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('total_prepayment_amount')
                    ->label('Pre-pagamento')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('total_outstanding_balance_amount')
                    ->label('Saldo devedor total')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('sale_ltv_ratio')
                    ->label('LTV venda')
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '-' : number_format(((float) $state) * 100, 2, ',', '.').'%')
                    ->sortable(),

                TextColumn::make('portfolio_duration_months')
                    ->label('Duration (meses)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('average_rate_details')
                    ->label('Taxa media')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Emissao')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reference_month')
                    ->label('Competencia')
                    ->options(fn (): array => Receivable::query()
                        ->orderByDesc('reference_month')
                        ->pluck('reference_month')
                        ->filter()
                        ->unique()
                        ->mapWithKeys(fn (mixed $referenceMonth): array => [
                            (string) Receivable::normalizeReferenceMonth($referenceMonth) => Receivable::formatReferenceMonthForDisplay($referenceMonth),
                        ])
                        ->all()),

                SelectFilter::make('portfolio_id')
                    ->label('Carteira')
                    ->options(fn (): array => Receivable::query()
                        ->orderBy('portfolio_id')
                        ->pluck('portfolio_id', 'portfolio_id')
                        ->all()),
            ])
            ->defaultSort('reference_month', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nenhum resumo de recebíveis cadastrado');
    }
}
