<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Filament\Pages\Settings as SettingsPage;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $recordTitleAttribute = 'payment_date';

    protected static ?string $title = 'Cronograma de Pagamentos';

    protected static ?string $modelLabel = 'Pagamento';

    protected static ?string $pluralModelLabel = 'Pagamentos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('payment_date')
                    ->label('Data de Pagamento')
                    ->required(),
                TextInput::make('premium_value')
                    ->label('Valor do Prêmio')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
                TextInput::make('interest_value')
                    ->label('Valor dos Juros')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
                TextInput::make('amortization_value')
                    ->label('Valor da Amortização')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
                TextInput::make('extra_amortization_value')
                    ->label('Amortização Extraordinária')
                    ->prefix('R$')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->placeholder('0,00'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_date')
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Data de Pagamento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('premium_value')
                    ->label('Prêmio')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('interest_value')
                    ->label('Juros')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('amortization_value')
                    ->label('Amortização')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('extra_amortization_value')
                    ->label('Amortização Extra')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\Action::make('download_template')
                    ->label('Download do Template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('admin.payments.template.download'))
                    ->visible(fn (): bool => app(PaymentSpreadsheetTemplate::class)->exists()),
                \Filament\Actions\Action::make('manage_template')
                    ->label('Configurar Template')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->url(fn (): string => SettingsPage::getUrl(panel: 'admin'))
                    ->visible(fn (): bool => auth()->user()?->can('settings.view') ?? false),
                \Filament\Actions\Action::make('import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Pagamentos (.xlsx)')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/csv', 'text/csv'])
                            ->required(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        $path = Storage::disk('local')->path($data['file']);

                        try {
                            $count = app(ImportPaymentsFromSpreadsheet::class)->handle($path, $livewire->ownerRecord);
                        } catch (\Throwable) {
                            Notification::make()
                                ->title('Erro ao processar o arquivo')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Importação concluída com sucesso!')
                            ->body("{$count} pagamentos foram processados.")
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\CreateAction::make()
                    ->label('Lançar Pagamento'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nenhum pagamento cadastrado');
    }
}
