<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Filament\Pages\Settings as SettingsPage;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
            ->searchPlaceholder('Buscar pagamentos...')
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Data de Pagamento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('premium_value')
                    ->label('Prêmio')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('interest_value')
                    ->label('Juros')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('amortization_value')
                    ->label('Amortização')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('extra_amortization_value')
                    ->label('Amortização Extra')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('download_template')
                    ->label('Download do Template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->tooltip('Baixar modelo de planilha para preenchimento')
                    ->url(fn (): string => route('admin.payments.template.download'))
                    ->visible(fn (): bool => app(PaymentSpreadsheetTemplate::class)->exists()),
                Action::make('manage_template')
                    ->label('Configurar Template')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->tooltip('Configurar mapeamento de colunas do template')
                    ->url(fn (): string => SettingsPage::getUrl(panel: 'admin'))
                    ->visible(fn (): bool => auth()->user()?->can('settings.view') ?? false),
                Action::make('import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->tooltip('Importar pagamentos via planilha (.xlsx / .csv)')
                    ->modalHeading('Importar Planilha de Pagamentos')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Pagamentos (.xlsx / .csv)')
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
                CreateAction::make()
                    ->label('Novo Pagamento')
                    ->icon('heroicon-m-plus')
                    ->tooltip('Lançar pagamento individual'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('Nenhum pagamento cadastrado')
            ->emptyStateDescription('Importe uma planilha (.xlsx / .csv) ou cadastre manualmente para compor o cronograma desta emissão.')
            ->emptyStateActions([
                Action::make('empty_import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->modalHeading('Importar Planilha de Pagamentos')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Pagamentos (.xlsx / .csv)')
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
                CreateAction::make('empty_create')
                    ->label('Cadastrar Pagamento')
                    ->icon('heroicon-m-plus')
                    ->color('gray'),
            ]);
    }
}
