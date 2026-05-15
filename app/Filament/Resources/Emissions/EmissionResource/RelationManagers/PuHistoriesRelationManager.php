<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportPuHistoriesFromSpreadsheet;
use App\Actions\Emissions\PuHistorySpreadsheetTemplate;
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

class PuHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'puHistories';

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $title = "Hist\u{00F3}rico de PU";

    protected static ?string $modelLabel = "Hist\u{00F3}rico de PU";

    protected static ?string $pluralModelLabel = "Hist\u{00F3}rico de PUs";

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('date')
                    ->label('Data')
                    ->required(),
                TextInput::make('unit_value')
                    ->label('PU Atualizado (R$)')
                    ->numeric()
                    ->required(),
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
                TextColumn::make('unit_value')
                    ->label('PU')
                    ->numeric(6, ',', '.')
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([
                \Filament\Actions\Action::make('download_template')
                    ->label('Baixar Template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('admin.pu-histories.template.download'))
                    ->visible(fn (): bool => app(PuHistorySpreadsheetTemplate::class)->exists()),
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
                            $count = app(ImportPuHistoriesFromSpreadsheet::class)->handle($path, $livewire->ownerRecord);
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
            ->emptyStateHeading('Nenhum registro de PU');
    }
}
