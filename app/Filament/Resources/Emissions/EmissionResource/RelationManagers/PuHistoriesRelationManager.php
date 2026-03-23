<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Models\PuHistory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;

class PuHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'puHistories';

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $title = 'Histórico de PU';

    protected static ?string $modelLabel = 'Histórico de PU';
    protected static ?string $pluralModelLabel = 'Histórico de PUs';

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
                    ->action(function (array $data, RelationManager $livewire) {
                        $path = Storage::disk('local')->path($data['file']);
                        
                        try {
                            // Using noHeaderRow allows us to skip metadata at the top and read by index
                            $rows = SimpleExcelReader::create($path)->noHeaderRow()->getRows();
                        } catch (\Exception $e) {
                            Notification::make()->title('Erro ao ler o arquivo')->danger()->send();
                            return;
                        }

                        $emissionId = $livewire->ownerRecord->id;
                        $count = 0;

                        $rows->each(function(array $row) use ($emissionId, &$count) {
                            $dataDate = $row[0] ?? null;
                            $puValue = $row[11] ?? null;

                            if ($dataDate && $puValue && $dataDate !== 'Data') {
                                // Parse Date
                                if ($dataDate instanceof \DateTimeInterface) {
                                    $dateStr = $dataDate->format('Y-m-d');
                                } else {
                                    try {
                                        if (str_contains($dataDate, '/')) {
                                            $dateStr = \Carbon\Carbon::createFromFormat('d/m/Y', $dataDate)->format('Y-m-d');
                                        } else {
                                            $dateStr = \Carbon\Carbon::parse($dataDate)->format('Y-m-d');
                                        }
                                    } catch (\Exception $e) {
                                        return; // not a date, maybe header
                                    }
                                }

                                // Parse Value
                                if (is_string($puValue)) {
                                    $puValue = str_replace(['R$', ' '], '', $puValue);
                                    if (str_contains($puValue, ',')) {
                                        $puValue = str_replace('.', '', $puValue);
                                        $puValue = str_replace(',', '.', $puValue);
                                    }
                                }

                                if (is_numeric($puValue) && floatval($puValue) > 0) {
                                    PuHistory::updateOrCreate(
                                        ['emission_id' => $emissionId, 'date' => $dateStr],
                                        ['unit_value' => (float) $puValue]
                                    );
                                    $count++;
                                }
                            }
                        });

                        $latest = PuHistory::where('emission_id', $emissionId)->orderByDesc('date')->first();
                        if ($latest) {
                            $livewire->ownerRecord->update(['current_pu' => $latest->unit_value]);
                        }

                        Notification::make()
                            ->title('Importação concluída!')
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
