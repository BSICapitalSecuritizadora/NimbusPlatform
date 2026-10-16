<?php

namespace App\Filament\Resources\ContaAzulImportBatches\Tables;

use App\Filament\Resources\ContaAzulImportBatches\ContaAzulImportBatchResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use App\Services\Funds\ContaAzulStaleBatchRecoveryService;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContaAzulImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ContaAzulImportBatch $record): string => ContaAzulImportBatchResource::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhuma importação registrada')
            ->emptyStateDescription('Cada tentativa de importação de uma conta financeira aparecerá aqui com seu resultado.')
            ->emptyStateIcon('heroicon-o-arrow-down-tray')
            ->columns([
                TextColumn::make('id')
                    ->label('Lote')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable(),

                TextColumn::make('bank_account_label')
                    ->label('Conta bancária')
                    ->state(fn (ContaAzulImportBatch $record): string => $record->bankAccount?->optionLabel() ?? '—')
                    ->wrap()
                    ->lineClamp(2),

                TextColumn::make('masked_account')
                    ->label('Conta Conta Azul')
                    ->state(fn (ContaAzulImportBatch $record): string => $record->maskedAccountId())
                    ->toggleable(),

                TextColumn::make('period')
                    ->label('Período')
                    ->state(fn (ContaAzulImportBatch $record): string => $record->date_from->format('d/m/Y').'–'.$record->date_to->format('d/m/Y')),

                TextColumn::make('status')
                    ->label('Resultado')
                    ->formatStateUsing(fn (string $state): string => ContaAzulImportBatch::STATUS_OPTIONS[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ContaAzulImportBatch::STATUS_COMPLETED => 'success',
                        ContaAzulImportBatch::STATUS_RUNNING, ContaAzulImportBatch::STATUS_PENDING => 'info',
                        ContaAzulImportBatch::STATUS_PARTIAL => 'warning',
                        ContaAzulImportBatch::STATUS_ABANDONED => 'gray',
                        default => 'danger',
                    }),

                TextColumn::make('staleness')
                    ->label('Atividade')
                    ->state(fn (ContaAzulImportBatch $record): string => match (true) {
                        $record->status !== ContaAzulImportBatch::STATUS_RUNNING => '—',
                        $record->isStale(app(ContaAzulStaleBatchRecoveryService::class)->staleTimeoutMinutes()) => 'Sem atividade — candidata a recuperação',
                        default => 'Em andamento',
                    })
                    ->badge()
                    ->color(fn (ContaAzulImportBatch $record): string => match (true) {
                        $record->status !== ContaAzulImportBatch::STATUS_RUNNING => 'gray',
                        $record->isStale(app(ContaAzulStaleBatchRecoveryService::class)->staleTimeoutMinutes()) => 'danger',
                        default => 'info',
                    }),

                TextColumn::make('records_received')
                    ->label('Recebidos')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('bills_staged')
                    ->label('Novos')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('bills_updated')
                    ->label('Atualizados')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('bills_reobserved')
                    ->label('Reobservados')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_at')
                    ->label('Iniciado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Resultado')
                    ->options(ContaAzulImportBatch::STATUS_OPTIONS)
                    ->multiple(),

                SelectFilter::make('bank_account_id')
                    ->label('Conta bancária')
                    ->options(fn (): array => BankAccount::query()->with('bank')->orderBy('id')->get()
                        ->mapWithKeys(fn (BankAccount $account): array => [$account->getKey() => $account->optionLabel()])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                Filter::make('started_at')
                    ->label('Data de execução')
                    ->form([
                        DatePicker::make('started_from')->label('Executado de')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('started_until')->label('Executado até')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['started_from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '>=', $date),
                        )
                        ->when(
                            $data['started_until'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '<=', $date),
                        )),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
