<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportIntegralizationHistoriesFromSpreadsheet;
use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
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

class IntegralizationHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'integralizationHistories';

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $title = "Hist\u{00F3}rico de Integraliza\u{00E7}\u{00F5}es";

    protected static ?string $modelLabel = "Integraliza\u{00E7}\u{00E3}o";

    protected static ?string $pluralModelLabel = "Integraliza\u{00E7}\u{00F5}es";

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('date')
                    ->label('Data')
                    ->required(),
                TextInput::make('quantity')
                    ->label('Quantidade')
                    ->numeric()
                    ->required(),
                TextInput::make('unit_value')
                    ->label('PU')
                    ->numeric(),
                TextInput::make('financial_value')
                    ->label('Financeiro')
                    ->numeric()
                    ->prefix('R$'),
                TextInput::make('investor_fund')
                    ->label('Fundo (Investidor)')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric(0, ',', '.')
                    ->sortable(),
                TextColumn::make('unit_value')
                    ->label('PU')
                    ->numeric(6, ',', '.')
                    ->sortable(),
                TextColumn::make('financial_value')
                    ->label('Financeiro')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('investor_fund')
                    ->label('Fundo (Investidor)')
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([
                \Filament\Actions\Action::make('download_template')
                    ->label('Baixar Template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('admin.integralization-histories.template.download'))
                    ->visible(fn (): bool => app(IntegralizationHistorySpreadsheetTemplate::class)->exists()),
                \Filament\Actions\Action::make('manage_template')
                    ->label('Configurar Template')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->url(fn (): string => SettingsPage::getUrl(panel: 'admin'))
                    ->visible(fn (): bool => auth()->user()?->can('settings.view') ?? false),
                \Filament\Actions\Action::make('import')
                    ->label('Importar Planilha')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')
                            ->label('Arquivo Excel (.xlsx)')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/csv', 'text/csv'])
                            ->required(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        $path = Storage::disk('local')->path($data['file']);

                        try {
                            $count = app(ImportIntegralizationHistoriesFromSpreadsheet::class)->handle($path, $livewire->ownerRecord);
                        } catch (\Throwable) {
                            Notification::make()
                                ->title('Erro ao ler o arquivo')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title("Importa\u{00E7}\u{00E3}o conclu\u{00ED}da!")
                            ->body("{$count} registros foram importados ou atualizados.")
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\CreateAction::make(),
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
            ->emptyStateHeading("Nenhuma integraliza\u{00E7}\u{00E3}o registrada");
    }
}
