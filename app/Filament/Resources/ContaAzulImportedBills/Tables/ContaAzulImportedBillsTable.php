<?php

namespace App\Filament\Resources\ContaAzulImportedBills\Tables;

use App\Filament\Resources\ContaAzulImportedBills\ContaAzulImportedBillResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\Expense;
use App\Support\BankIdentifierMasker;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContaAzulImportedBillsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ContaAzulImportedBill $record): string => ContaAzulImportedBillResource::getUrl('view', ['record' => $record]))
            ->searchPlaceholder('Buscar por descrição, fornecedor ou referência externa...')
            ->searchDebounce('400ms')
            ->defaultSort('due_date', 'asc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum título importado')
            ->emptyStateDescription('Os títulos importados das contas financeiras Conta Azul aparecerão aqui para conciliação.')
            ->emptyStateIcon('heroicon-o-scale')
            ->columns([
                TextColumn::make('description')
                    ->label('Título')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->placeholder('Sem descrição')
                    ->description(fn (ContaAzulImportedBill $record): ?string => $record->supplier_name)
                    ->searchable(['description', 'supplier_name'])
                    ->sortable(),

                TextColumn::make('conta_azul_bill_id')
                    ->label('Referência externa')
                    ->formatStateUsing(fn (ContaAzulImportedBill $record): string => $record->maskedBillId())
                    ->tooltip('Identificador externo mascarado; o valor integral é restrito à operação.')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('total_amount')
                    ->label('Valor nominal')
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('semibold')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('mapped_category')
                    ->label('Categoria')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Sem mapeamento')
                    ->toggleable(),

                TextColumn::make('import_status')
                    ->label('Importação')
                    ->formatStateUsing(fn (string $state): string => ContaAzulImportedBill::IMPORT_STATUS_OPTIONS[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ContaAzulImportedBill::IMPORT_ERROR => 'danger',
                        ContaAzulImportedBill::IMPORT_UPDATED => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('reconciliation_status')
                    ->label('Conciliação')
                    ->formatStateUsing(fn (string $state): string => ContaAzulImportedBill::RECONCILIATION_STATUS_OPTIONS[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ContaAzulImportedBill::RECONCILIATION_ATTRIBUTED => 'success',
                        ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW => 'info',
                        ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('needs_re_review')
                    ->label('Mudança externa')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip(fn (ContaAzulImportedBill $record): ?string => $record->needs_re_review
                        ? $record->re_review_reason
                        : 'Sem mudanças externas pendentes de revisão'),

                TextColumn::make('has_legacy_history')
                    ->label('Fluxo legado')
                    ->formatStateUsing(fn ($state): string => $state ? 'Já registrado no fluxo legado' : '—')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'warning' : 'gray')
                    ->toggleable(),

                TextColumn::make('attributedEmission.name')
                    ->label('Emissão atribuída')
                    ->placeholder('Não atribuído')
                    ->wrap()
                    ->lineClamp(2)
                    ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),

                TextColumn::make('bankAccount.option_label')
                    ->label('Conta bancária')
                    ->state(fn (ContaAzulImportedBill $record): string => $record->bankAccount?->optionLabel() ?? '—')
                    ->wrap()
                    ->lineClamp(2)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Importado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('bank_account_id')
                    ->label('Conta bancária')
                    ->options(fn (): array => BankAccount::query()->with('bank')->orderBy('id')->get()
                        ->mapWithKeys(fn (BankAccount $account): array => [$account->getKey() => $account->optionLabel()])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                SelectFilter::make('conta_azul_account_id')
                    ->label('Conta financeira Conta Azul')
                    ->options(fn (): array => ContaAzulImportedBill::query()
                        ->distinct()
                        ->orderBy('conta_azul_account_id')
                        ->pluck('conta_azul_account_id', 'conta_azul_account_id')
                        ->mapWithKeys(fn (string $id): array => [$id => BankIdentifierMasker::maskContaAzulId($id)])
                        ->all())
                    ->searchable()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                SelectFilter::make('conta_azul_import_batch_id')
                    ->label('Lote de importação')
                    ->relationship('batch', 'id')
                    ->getOptionLabelFromRecordUsing(fn (ContaAzulImportBatch $batch): string => "#{$batch->getKey()} · {$batch->date_from->format('d/m/Y')}–{$batch->date_to->format('d/m/Y')} · ".(ContaAzulImportBatch::STATUS_OPTIONS[$batch->status] ?? $batch->status))
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                Filter::make('imported_at')
                    ->label('Data de importação')
                    ->form([
                        DatePicker::make('imported_from')->label('Importado de')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('imported_until')->label('Importado até')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['imported_from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('conta_azul_imported_bills.created_at', '>=', $date),
                        )
                        ->when(
                            $data['imported_until'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('conta_azul_imported_bills.created_at', '<=', $date),
                        )),

                Filter::make('due_date')
                    ->label('Vencimento do título')
                    ->form([
                        DatePicker::make('due_from')->label('Vence de')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('due_until')->label('Vence até')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['due_from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->where('due_date', '>=', $date),
                        )
                        ->when(
                            $data['due_until'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->where('due_date', '<=', $date),
                        )),

                SelectFilter::make('reconciliation_status')
                    ->label('Conciliação')
                    ->options(ContaAzulImportedBill::RECONCILIATION_STATUS_OPTIONS)
                    ->multiple(),

                SelectFilter::make('import_status')
                    ->label('Importação')
                    ->options(ContaAzulImportedBill::IMPORT_STATUS_OPTIONS),

                TernaryFilter::make('needs_re_review')
                    ->label('Mudança externa a revisar')
                    ->placeholder('Todos')
                    ->trueLabel('Com mudança a revisar')
                    ->falseLabel('Sem mudanças pendentes'),

                SelectFilter::make('attributed_emission_id')
                    ->label('Emissão atribuída')
                    ->relationship('attributedEmission', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField())
                    ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? false),

                SelectFilter::make('mapped_category')
                    ->label('Categoria')
                    ->options(Expense::CATEGORY_OPTIONS)
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
