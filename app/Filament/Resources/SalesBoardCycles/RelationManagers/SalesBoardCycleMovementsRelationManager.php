<?php

namespace App\Filament\Resources\SalesBoardCycles\RelationManagers;

use App\Enums\SalesBoardMovementType;
use App\Enums\SalesPriceConformityStatus;
use App\Models\SalesBoardCycleMovement;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * As vendas, quitações e distratos da competência, congelados.
 *
 * Existem separados da posição porque posição não é movimento: uma unidade
 * vendida e distratada dentro do mesmo mês fecha em estoque, e uma tela que só
 * mostrasse o fechamento registraria um mês em que nada aconteceu.
 *
 * Na venda, a conformidade vem com a tabela e a política que a produziram --
 * ambas congeladas. Explicar por que aquela venda foi apontada não pode depender
 * de reler uma política que já pode ter sido substituída.
 */
class SalesBoardCycleMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'currentMovements';

    protected static ?string $title = 'Movimentações';

    protected static ?string $modelLabel = 'Movimentação';

    protected static ?string $pluralModelLabel = 'Movimentações';

    protected static string|BackedEnum|null $icon = 'heroicon-o-arrows-right-left';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('contract_code')
            ->description('O que aconteceu dentro da competência. Congelado com a versão vigente.')
            ->defaultSort('event_date')
            ->emptyStateHeading('Nenhuma movimentação na competência')
            ->searchPlaceholder('Buscar por contrato, bloco ou unidade...')
            ->columns([
                TextColumn::make('movement_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardMovementType $state): string => $state->label())
                    ->color(fn (SalesBoardMovementType $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('contract_code')
                    ->label('Contrato')
                    ->weight('semibold')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('unit')
                    ->label('Unidade')
                    ->state(fn (SalesBoardCycleMovement $record): string => $record->displayName())
                    ->searchable(['block', 'unit']),

                TextColumn::make('event_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    /**
                     * A quitação não tem data: o motor prova a transição dentro
                     * do mês, não o dia dela. Um traço aqui é a resposta
                     * honesta; preencher com a data de algum pagamento seria
                     * inventar uma regra que ninguém decidiu.
                     */
                    ->placeholder('—')
                    ->tooltip(fn (SalesBoardCycleMovement $record): ?string => $record->movement_type === SalesBoardMovementType::Settlement
                        ? 'A quitação é apurada como transição dentro da competência; o dia exato não é derivável do cronograma.'
                        : null)
                    ->sortable(),

                TextColumn::make('sale_value')
                    ->label('Valor da venda')
                    ->money('BRL')
                    ->placeholder('—')
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->sortable(),

                TextColumn::make('unit_reference_value')
                    ->label('Tabela na venda')
                    ->money('BRL')
                    ->placeholder('—')
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->toggleable(),

                TextColumn::make('authorized_discount_basis_points')
                    ->label('Desconto autorizado')
                    ->state(fn (SalesBoardCycleMovement $record): ?string => $record->authorizedDiscountLabel())
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('effective_discount_basis_points')
                    ->label('Desconto praticado')
                    ->state(fn (SalesBoardCycleMovement $record): ?string => $record->effectiveDiscountLabel())
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('minimum_authorized_value')
                    ->label('Preço mínimo')
                    ->money('BRL')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('conformity_status')
                    ->label('Conformidade')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?SalesPriceConformityStatus $state): ?string => $state?->label())
                    ->color(fn (?SalesPriceConformityStatus $state): string => $state?->color() ?? 'gray')
                    ->tooltip(fn (SalesBoardCycleMovement $record): ?string => $record->conformity_reason),

                TextColumn::make('settlement_installments_total')
                    ->label('Parcelas')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('movement_type')
                    ->label('Tipo')
                    ->options(fn (): array => collect(SalesBoardMovementType::cases())
                        ->mapWithKeys(fn (SalesBoardMovementType $case): array => [$case->value => $case->label()])
                        ->all()),

                SelectFilter::make('conformity_status')
                    ->label('Conformidade')
                    ->options(fn (): array => collect(SalesPriceConformityStatus::cases())
                        ->mapWithKeys(fn (SalesPriceConformityStatus $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->headerActions([])
            ->actions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
