<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportPuHistoriesFromSpreadsheet;
use App\Actions\Emissions\PuHistorySpreadsheetTemplate;
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

class PuHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'puHistories';

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $title = 'Histórico de Preço Unitário (PU)';

    protected static ?string $modelLabel = 'Histórico de PU';

    protected static ?string $pluralModelLabel = 'Histórico de PUs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('date')
                    ->label('Data de Referência')
                    ->required(),
                TextInput::make('unit_value')
                    ->label('Valor do PU')
                    ->prefix('R$')
                    ->numeric()
                    ->required()
                    ->placeholder('0,000000'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->searchPlaceholder('Buscar por data...')
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('unit_value')
                    ->label('Valor Unitário (PU)')
                    ->numeric(6, ',', '.')
                    ->prefix('R$ ')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([
                Action::make('download_template')
                    ->label('Download do Template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->tooltip('Baixar modelo de planilha para preenchimento')
                    ->url(fn (): string => route('admin.pu-histories.template.download'))
                    ->visible(fn (): bool => app(PuHistorySpreadsheetTemplate::class)->exists()),
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
                    ->tooltip('Importar histórico de PU via planilha (.xlsx / .csv)')
                    ->modalHeading('Importar Planilha de Preço Unitário')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Preços (.xlsx / .csv)')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/csv', 'text/csv'])
                            ->required(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        $path = Storage::disk('local')->path($data['file']);

                        try {
                            $count = app(ImportPuHistoriesFromSpreadsheet::class)->handle($path, $livewire->ownerRecord);
                        } catch (\Throwable) {
                            Notification::make()
                                ->title('Erro ao processar o arquivo')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Importação concluída com sucesso!')
                            ->body("{$count} registros foram processados.")
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->label('Lançar PU')
                    ->icon('heroicon-m-plus')
                    ->tooltip('Cadastrar valor de PU manualmente'),
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
            ->emptyStateIcon('heroicon-o-arrow-trending-up')
            ->emptyStateHeading('Nenhum histórico de PU cadastrado')
            ->emptyStateDescription('Importe uma planilha (.xlsx / .csv) ou lance manualmente os valores para acompanhar a evolução do preço unitário desta emissão.')
            ->emptyStateActions([
                Action::make('empty_import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->modalHeading('Importar Planilha de Preço Unitário')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Preços (.xlsx / .csv)')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/csv', 'text/csv'])
                            ->required(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        $path = Storage::disk('local')->path($data['file']);

                        try {
                            $count = app(ImportPuHistoriesFromSpreadsheet::class)->handle($path, $livewire->ownerRecord);
                        } catch (\Throwable) {
                            Notification::make()
                                ->title('Erro ao processar o arquivo')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Importação concluída com sucesso!')
                            ->body("{$count} registros foram processados.")
                            ->success()
                            ->send();
                    }),
                CreateAction::make('empty_create')
                    ->label('Lançar PU')
                    ->icon('heroicon-m-plus')
                    ->color('gray'),
            ]);
    }
}
