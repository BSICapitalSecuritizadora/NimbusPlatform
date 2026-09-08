<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\ExpenseHistory;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';

    protected static ?string $title = 'Histórico de pagamentos';

    protected static ?string $modelLabel = 'Histórico';

    protected static ?string $pluralModelLabel = 'Histórico de pagamentos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('due_date')
                ->label('Data de vencimento')
                ->required()
                ->native(false)
                ->displayFormat('d/m/Y'),

            TextInput::make('amount')
                ->label('Valor nominal')
                ->required()
                ->numeric()
                ->prefix('R$')
                ->minValue(0.01),

            TextInput::make('paid_amount')
                ->label('Valor efetivamente pago')
                ->numeric()
                ->prefix('R$')
                ->minValue(0.01)
                ->helperText('Informe o valor pago caso diferente do valor nominal (ex.: pagamento parcial).'),

            DatePicker::make('payment_date')
                ->label('Data do pagamento')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->helperText('Data efetiva da liquidação. Se não informada, o sistema exibirá "—".'),

            TextInput::make('conta_azul_bill_id')
                ->label('ID Conta Azul')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (?ExpenseHistory $record) => filled($record?->conta_azul_bill_id)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('due_date')
            ->heading('Histórico de pagamentos')
            ->description('Visualização dos lançamentos e pagamentos desta despesa.')
            ->columns([
                TextColumn::make('due_date')
                    ->label('Data de vencimento')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('payment_date')
                    ->label('Data do pagamento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (ExpenseHistory $record): string => match ($record->status) {
                        'partially_paid' => 'info',
                        'pending' => 'warning',
                        'overdue' => 'danger',
                        default => 'success',
                    })
                    ->state(fn (ExpenseHistory $record): string => match ($record->status) {
                        'partially_paid' => 'Parcialmente pago',
                        'pending' => 'Pendente',
                        'overdue' => 'Vencido',
                        default => 'Pago',
                    }),

                TextColumn::make('amount')
                    ->label('Valor nominal')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('paid_amount')
                    ->label('Valor pago')
                    ->money('BRL')
                    ->weight('semibold')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('conta_azul_bill_id')
                    ->label('ID Conta Azul')
                    ->color('gray')
                    ->copyable(),
            ])
            ->defaultSort('due_date', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Registrar pagamento')
                    ->visible(fn (): bool => ExpenseResource::canCreate())
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['status'] = 'paid';
                        if (empty($data['paid_amount'])) {
                            $data['paid_amount'] = $data['amount'];
                        }

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (): bool => ExpenseResource::canEdit($this->getOwnerRecord())),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhum histórico de pagamento registrado')
            ->emptyStateDescription('Os pagamentos registrados para esta despesa aparecerão aqui.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
