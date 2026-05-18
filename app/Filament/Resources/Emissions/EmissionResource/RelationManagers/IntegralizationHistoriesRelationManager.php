<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportIntegralizationHistoriesFromSpreadsheet;
use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\IntegralizationHistory;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
                    ->inputMode('numeric')
                    ->mask(RawJs::make(<<<'JS'
                        $money($input, ',', '.', 0)
                    JS))
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateHydrated(fn (Get $get, Set $set): null => self::syncFinancialValue($get, $set))
                    ->afterStateUpdated(fn (Get $get, Set $set): null => self::syncFinancialValue($get, $set))
                    ->formatStateUsing(fn (mixed $state): ?string => self::formatDecimalForDisplay($state, 0))
                    ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeDecimalValue($state))
                    ->placeholder('1.000')
                    ->validationMessages([
                        'required' => 'Informe a quantidade a integralizar.',
                        'numeric' => 'Informe uma quantidade válida.',
                    ]),
                TextInput::make('unit_value')
                    ->label('PU')
                    ->inputMode('decimal')
                    ->mask(RawJs::make(<<<'JS'
                        $money($input, ',', '.', 8)
                    JS))
                    ->live(onBlur: true)
                    ->afterStateHydrated(fn (Get $get, Set $set): null => self::syncFinancialValue($get, $set))
                    ->afterStateUpdated(fn (Get $get, Set $set): null => self::syncFinancialValue($get, $set))
                    ->formatStateUsing(fn (mixed $state): ?string => self::formatDecimalForDisplay($state, 8))
                    ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeDecimalValue($state))
                    ->placeholder('1.000,50000000'),
                TextInput::make('financial_value')
                    ->label('Financeiro')
                    ->prefix('R$')
                    ->readOnly()
                    ->inputMode('decimal')
                    ->mask(RawJs::make(<<<'JS'
                        $money($input, ',', '.', 2)
                    JS))
                    ->helperText('Calculado automaticamente a partir de Quantidade x PU.')
                    ->formatStateUsing(fn (mixed $state): ?string => self::formatDecimalForDisplay($state, 2))
                    ->dehydrateStateUsing(fn (Get $get): ?float => self::calculateFinancialValue($get))
                    ->placeholder('1.000.000,00'),
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
                    ->numeric(8, ',', '.')
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
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Importação não realizada')
                                ->body(collect($exception->errors())->flatten()->implode(PHP_EOL))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
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
                \Filament\Actions\CreateAction::make()
                    ->before(function (Action $action, array $data): void {
                        $this->validateIntegralizationQuantityOrHalt($action, $data);
                    }),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->before(function (Action $action, IntegralizationHistory $record, array $data): void {
                        $this->validateIntegralizationQuantityOrHalt($action, $data, $record);
                    }),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading("Nenhuma integraliza\u{00E7}\u{00E3}o registrada");
    }

    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        $this->dispatch('integralization-histories-updated');
    }

    protected function validateIntegralizationQuantityOrHalt(
        Action $action,
        array $data,
        ?IntegralizationHistory $record = null,
    ): void {
        try {
            $this->ownerRecord->ensureIntegralizationQuantityWithinIssuedLimit(
                quantity: $data['quantity'] ?? null,
                ignoringIntegralizationHistory: $record,
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            if (filled($message)) {
                Notification::make()
                    ->title('Integralização não realizada')
                    ->body((string) $message)
                    ->danger()
                    ->persistent()
                    ->send();
            }

            $action->halt();
        }
    }

    private static function formatDecimalForDisplay(mixed $state, int $decimals): ?string
    {
        if (blank($state) && $state !== 0 && $state !== '0') {
            return null;
        }

        return number_format((float) $state, $decimals, ',', '.');
    }

    private static function normalizeDecimalValue(mixed $state): ?float
    {
        if (blank($state) && $state !== 0 && $state !== '0') {
            return null;
        }

        if (is_int($state) || is_float($state)) {
            return (float) $state;
        }

        return (float) str_replace(['.', ','], ['', '.'], (string) $state);
    }

    private static function syncFinancialValue(Get $get, Set $set): null
    {
        $financialValue = self::calculateFinancialValue($get);

        $set('financial_value', self::formatDecimalForDisplay($financialValue, 2));

        return null;
    }

    private static function calculateFinancialValue(Get $get): ?float
    {
        $quantity = self::normalizeDecimalValue($get('quantity'));
        $unitValue = self::normalizeDecimalValue($get('unit_value'));

        if ($quantity === null || $unitValue === null) {
            return null;
        }

        return round($quantity * $unitValue, 2);
    }
}
