<?php

namespace App\Filament\Resources\SalesBoardCycles\RelationManagers;

use App\Enums\ContractSettlementState;
use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardUnitClassification;
use App\Models\SalesBoardCycleLine;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * As unidades da versão vigente, exatamente como foram congeladas.
 *
 * Somente leitura, sem exceção: não há incluir, editar nem excluir. Cada linha é
 * a parcela de um total já apurado, e mexer numa delas por aqui produziria um
 * total que não fecha com as suas próprias linhas -- o defeito que as fases
 * anteriores existiram para eliminar.
 *
 * A identificação exibida é a congelada, não a da unidade viva. Se o bloco for
 * renomeado amanhã, esta tela continua mostrando o que a construtora conferiu.
 */
class SalesBoardCycleLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'currentLines';

    protected static ?string $title = 'Unidades';

    protected static ?string $modelLabel = 'Unidade';

    protected static ?string $pluralModelLabel = 'Unidades';

    protected static string|BackedEnum|null $icon = 'heroicon-o-squares-2x2';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('unit')
            ->description('A composição da versão vigente, unidade a unidade. Congelada.')
            ->defaultSort('block')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Nenhuma unidade congelada')
            ->searchPlaceholder('Buscar por bloco, unidade ou contrato...')
            ->columns([
                TextColumn::make('block')
                    ->label('Bloco')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Unidade')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('classification')
                    ->label('Classificação')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardUnitClassification $state): string => $state->label())
                    ->color(fn (SalesBoardUnitClassification $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('contract_code')
                    ->label('Contrato')
                    ->placeholder('—')
                    ->description(fn (SalesBoardCycleLine $record): ?string => $record->contract_sale_date
                        ?->format('\v\e\n\d\i\d\o \e\m d/m/Y'))
                    ->searchable(),

                TextColumn::make('contract_sale_value')
                    ->label('Valor de venda')
                    ->money('BRL')
                    ->placeholder('—')
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->sortable(),

                TextColumn::make('unit_reference_value')
                    ->label('Valor vigente')
                    ->money('BRL')
                    ->placeholder('indisponível')
                    ->description(fn (SalesBoardCycleLine $record): ?string => $record->unit_reference_value_source === null
                        ? null
                        : $record->unit_reference_value_source->label())
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->sortable(),

                TextColumn::make('settlement_state')
                    ->label('Quitação')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?ContractSettlementState $state): ?string => $state?->label())
                    ->description(fn (SalesBoardCycleLine $record): ?string => $record->settlement_installments_total === null
                        ? null
                        : sprintf('%d de %d parcelas', (int) $record->settlement_installments_paid, (int) $record->settlement_installments_total))
                    ->toggleable(),

                TextColumn::make('exchange_value')
                    ->label('Permuta')
                    ->money('BRL')
                    ->placeholder('—')
                    ->description(fn (SalesBoardCycleLine $record): ?string => $record->exchange_effective_from
                        ?->format('\v\i\g\e\n\t\e \d\e\s\d\e d/m/Y'))
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('bucket_value')
                    ->label('Valor no balde')
                    ->state(fn (SalesBoardCycleLine $record): ?string => $record->bucketValue())
                    ->money('BRL')
                    ->placeholder('indisponível')
                    ->weight('semibold')
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('classification')
                    ->label('Classificação')
                    ->options(fn (): array => collect(SalesBoardUnitClassification::cases())
                        ->mapWithKeys(fn (SalesBoardUnitClassification $case): array => [$case->value => $case->label()])
                        ->all()),

                SelectFilter::make('unit_reference_value_source')
                    ->label('Origem do valor')
                    ->options(fn (): array => collect(ResolvedUnitValueSource::cases())
                        ->mapWithKeys(fn (ResolvedUnitValueSource $case): array => [$case->value => $case->label()])
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
