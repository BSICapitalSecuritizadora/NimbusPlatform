<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Concerns\ImportsContractInstallments;
use App\Concerns\MoneyFormatter;
use App\Enums\ContractInstallmentStatus;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\ContractInstallments\Schemas\ContractInstallmentForm;
use App\Models\ContractInstallment;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The payment schedule shown on the contract's own page.
 *
 * Everything the contract already knows -- emission, development, unit, client
 * and the contract itself -- is context here, so the form only asks for what is
 * actually new about the installment.
 */
class ContractInstallmentsRelationManager extends RelationManager
{
    use ImportsContractInstallments;

    protected static string $relationship = 'installments';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $title = 'Parcelas';

    protected static ?string $modelLabel = 'Parcela';

    protected static ?string $pluralModelLabel = 'Parcelas';

    /**
     * The panel turns relation managers read-only on resource view pages by
     * default. This one opts out: registering and correcting an installment from
     * the contract's own page is the whole point of it, and every action here is
     * already behind the installment permissions.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return ContractInstallmentForm::configure($schema, (int) $this->getOwnerRecord()->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->description('Fluxo de recebimento do contrato. Status, saldo e dias em atraso são calculados a partir das datas e valores da própria parcela.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]))
            ->defaultSort('due_date', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('number')
                    ->label('Nº')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('expected_value')
                    ->label('Previsto')
                    ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(self::moneySum('expected_value', 'Total previsto')),

                TextColumn::make('paid_value')
                    ->label('Pago')
                    ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state))
                    ->placeholder('R$ 0,00')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(self::moneySum('paid_value', 'Total recebido')),

                TextColumn::make('outstanding_value')
                    ->label('Saldo')
                    ->state(fn (ContractInstallment $record): float => $record->outstanding_value)
                    ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state))
                    ->color(fn (mixed $state): string => ((float) $state) > 0 ? 'warning' : 'gray')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ContractInstallment $record): ContractInstallmentStatus => $record->status)
                    ->formatStateUsing(fn (ContractInstallmentStatus $state): string => $state->label())
                    ->color(fn (ContractInstallmentStatus $state): string => $state->color()),

                TextColumn::make('days_overdue')
                    ->label('Dias em Atraso')
                    ->state(fn (ContractInstallment $record): int => $record->days_overdue)
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : '—')
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignEnd(),

                TextColumn::make('payment_date')
                    ->label('Data Pagamento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cancellation_date')
                    ->label('Data Cancelamento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color('danger')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ContractInstallmentStatus::options())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        ContractInstallmentStatus::tryFrom((string) ($data['value'] ?? '')),
                        fn (Builder $query, ContractInstallmentStatus $status): Builder => $query->withStatus($status),
                    )),

                TrashedFilter::make()
                    ->label('Parcelas excluídas'),
            ])
            ->headerActions([
                $this->installmentTemplateAction(),

                $this->installmentImportAction((int) $this->getOwnerRecord()->getKey())
                    ->visible(fn (): bool => ContractInstallmentResource::canCreate()),

                CreateAction::make()
                    ->label('Nova Parcela')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Nova Parcela')
                    ->visible(fn (): bool => ContractInstallmentResource::canCreate()),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->modalHeading('Editar Parcela')
                        ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canEdit($record)),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->modalHeading('Excluir parcela')
                        ->modalDescription('Use a exclusão apenas para um registro criado por engano. Para tirar uma parcela do fluxo contratual preservando o histórico, informe a data de cancelamento.')
                        ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canDelete($record)),

                    RestoreAction::make()
                        ->label('Restaurar')
                        ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canRestore($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da parcela'),
            ])
            ->emptyStateHeading('Nenhuma parcela cadastrada')
            ->emptyStateDescription('Cadastre manualmente ou importe a planilha de parcelas deste contrato.');
    }

    /**
     * Column footers total what is on screen. They are a convenience next to the
     * numbers, not the contract's official figures -- those are the summary on
     * the page above, which always ignores cancelled installments.
     */
    private static function moneySum(string $column, string $label): Sum
    {
        return Sum::make()
            ->label($label)
            ->query(fn (QueryBuilder $query): QueryBuilder => $query->whereNull('cancellation_date'))
            ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state));
    }
}
