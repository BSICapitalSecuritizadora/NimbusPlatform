<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Actions\Emissions\ImportIntegralizationHistoriesFromSpreadsheet;
use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
use App\Actions\Emissions\RecordIntegralizationHistory;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\ValueObjects\Decimal;
use App\Enums\AccessPermission;
use App\Enums\IntegralizationSource;
use App\Filament\Pages\Settings as SettingsPage;
use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use App\Models\IntegralizationHistory;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
use Throwable;

class IntegralizationHistoriesRelationManager extends RelationManager
{
    private const INVESTOR_FUND_TYPE = 'Fundo do Investidor';

    protected static string $relationship = 'integralizationHistories';

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $title = 'Histórico de Integralizações';

    protected static ?string $modelLabel = 'Integralização';

    protected static ?string $pluralModelLabel = 'Integralizações';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('date')
                    ->label('Data de Integralização')
                    ->displayFormat('d/m/Y')
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
                    ->dehydrateStateUsing(fn (mixed $state): ?string => self::normalizeDecimalValue($state))
                    ->rule(fn (): Closure => self::maskedDecimalRule('Informe uma quantidade válida.'))
                    ->placeholder('0')
                    ->validationMessages([
                        'required' => 'Informe a quantidade a integralizar.',
                        'numeric' => 'Informe uma quantidade válida.',
                    ]),
                TextInput::make('unit_value')
                    ->label('Preço Unitário (PU)')
                    ->prefix('R$')
                    ->inputMode('decimal')
                    ->mask(RawJs::make(<<<'JS'
                        $money($input, ',', '.', 8)
                    JS))
                    ->live(onBlur: true)
                    ->afterStateHydrated(fn (Get $get, Set $set): null => self::syncFinancialValue($get, $set))
                    ->afterStateUpdated(fn (Get $get, Set $set): null => self::syncFinancialValue($get, $set))
                    ->formatStateUsing(fn (mixed $state): ?string => self::formatDecimalForDisplay($state, 8))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => self::normalizeDecimalValue($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->rule(fn (): Closure => self::maskedDecimalRule('Informe um PU válido.'))
                    ->placeholder('0,00000000')
                    ->validationMessages([
                        'required' => 'Informe o PU da integralização.',
                    ]),
                TextInput::make('financial_value')
                    ->label('Valor Financeiro')
                    ->prefix('R$')
                    ->readOnly()
                    ->inputMode('decimal')
                    ->mask(RawJs::make(<<<'JS'
                        $money($input, ',', '.', 2)
                    JS))
                    ->helperText('Calculado automaticamente: Quantidade × PU.')
                    ->formatStateUsing(fn (mixed $state): ?string => self::formatDecimalForDisplay($state, 2))
                    ->dehydrateStateUsing(fn (Get $get): ?string => self::calculateFinancialValue($get))
                    ->placeholder('0,00'),
                Select::make('investor_fund')
                    ->label('Fundo do Investidor')
                    ->options(fn (): array => self::getInvestorFundOptions())
                    ->searchable()
                    ->preload()
                    ->getSearchResultsUsing(fn (string $search): array => self::getInvestorFundOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => filled($value) ? (string) $value : null)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->in(fn (?IntegralizationHistory $record): array => self::allowedInvestorFunds($record))
                    ->validationMessages([
                        'required' => 'Selecione o fundo do investidor.',
                        'in' => 'Selecione um fundo do investidor cadastrado.',
                    ])
                    ->createOptionForm(fn (): array => ExpenseServiceProviderForm::fields(
                        serviceProviderTypeId: self::resolveInvestorFundTypeId(),
                        lockServiceProviderType: true,
                    ))
                    ->createOptionUsing(
                        fn (array $data): string => (string) ExpenseServiceProvider::query()->create($data)->name,
                    )
                    ->createOptionAction(
                        fn (Action $action): Action => $action
                            ->label('Cadastrar Fundo do Investidor')
                            ->modalHeading('Cadastrar Fundo do Investidor'),
                    )
                    ->placeholder('Selecione ou cadastre o fundo')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->searchPlaceholder('Buscar integralizações...')
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->weight('medium')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric(0, ',', '.')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('unit_value')
                    ->label('PU')
                    ->numeric(8, ',', '.')
                    ->prefix('R$ ')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('financial_value')
                    ->label('Valor Financeiro')
                    ->money('BRL')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('investor_fund')
                    ->label('Fundo do Investidor')
                    ->searchable()
                    ->wrap()
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([
                Action::make('download_template')
                    ->label('Download do Template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->tooltip('Baixar modelo de planilha para preenchimento')
                    ->url(fn (): string => route('admin.integralization-histories.template.download'))
                    ->visible(fn (): bool => app(IntegralizationHistorySpreadsheetTemplate::class)->exists()),
                Action::make('manage_template')
                    ->label('Configurar Template')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->tooltip('Configurar mapeamento de colunas do template')
                    ->url(fn (): string => SettingsPage::getUrl(panel: 'admin'))
                    ->visible(fn (): bool => auth()->user()?->can('settings.view') ?? false),
                $this->makeCreateIntegralizationAction('create')
                    ->outlined(),
                Action::make('import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->tooltip('Importar histórico de integralizações via planilha (.xlsx / .csv)')
                    ->authorize(fn (): bool => $this->canRecordIntegralizations())
                    ->modalHeading('Importar Planilha de Integralizações')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Dados (.xlsx / .csv)')
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
                                ->title('Falha na importação')
                                ->body(collect($exception->errors())->flatten()->implode(PHP_EOL))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        } catch (Throwable) {
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
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->modalHeading('Editar Integralização')
                        ->before(function (Action $action, array $data, IntegralizationHistory $record): void {
                            $this->validateIntegralizationQuantityOrHalt($action, $data, $record);
                        }),
                    DeleteAction::make()
                        ->modalHeading('Excluir Integralização'),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading('Nenhuma integralização cadastrada')
            ->emptyStateDescription('Cadastre manualmente a primeira integralização ou importe uma planilha para iniciar o histórico desta emissão.')
            ->emptyStateActions([
                $this->makeCreateIntegralizationAction('empty_create')
                    ->color('primary'),
                Action::make('empty_import')
                    ->label('Importar Dados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->authorize(fn (): bool => $this->canRecordIntegralizations())
                    ->modalHeading('Importar Planilha de Integralizações')
                    ->modalSubmitActionLabel('Importar Dados')
                    ->form([
                        FileUpload::make('file')
                            ->label('Planilha de Dados (.xlsx / .csv)')
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
                                ->title('Falha na importação')
                                ->body(collect($exception->errors())->flatten()->implode(PHP_EOL))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        } catch (Throwable) {
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
            ]);
    }

    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        $this->dispatch('integralization-histories-updated');
    }

    /**
     * "Nova Integralização" grava pela mesma operação da planilha
     * ({@see RecordIntegralizationHistory}); aqui só se converte a máscara pt-BR
     * em decimal. O Valor Financeiro do modal é prévia: a gravação o deriva de
     * novo da quantidade e do PU.
     *
     * O `authorize()` explícito vale também na página de visualização, onde o
     * painel deixa o RelationManager somente leitura -- editar e excluir
     * continuam fora dali.
     */
    protected function makeCreateIntegralizationAction(string $name): CreateAction
    {
        return CreateAction::make($name)
            ->label('Nova Integralização')
            ->icon('heroicon-m-plus')
            ->tooltip('Cadastrar integralização manualmente')
            ->modalHeading('Nova Integralização')
            ->createAnother(false)
            ->authorize(fn (): bool => $this->canRecordIntegralizations())
            ->successNotificationTitle('Integralização adicionada com sucesso.')
            ->failureNotificationTitle('Não foi possível adicionar a integralização.')
            ->using(function (CreateAction $action, array $data, RecordIntegralizationHistory $recordIntegralizationHistory): IntegralizationHistory {
                try {
                    return $recordIntegralizationHistory->create($this->getOwnerRecord(), [
                        'date' => $data['date'] ?? null,
                        'quantity' => $data['quantity'] ?? null,
                        'unit_value' => $data['unit_value'] ?? null,
                        'investor_fund' => $data['investor_fund'] ?? null,
                    ], IntegralizationSource::Manual);
                } catch (ValidationException $exception) {
                    throw $this->withMountedActionErrorPaths($exception);
                } catch (Throwable $exception) {
                    report($exception);

                    $action->sendFailureNotification();
                    $action->halt();
                }
            });
    }

    /**
     * A operação responde pelo nome do campo (`quantity`, `date`...); no modal o
     * campo vive sob o caminho da ação montada, e é lá que o erro aparece.
     */
    protected function withMountedActionErrorPaths(ValidationException $exception): ValidationException
    {
        $statePath = $this->getMountedActionSchema()?->getStatePath();

        if (blank($statePath)) {
            return $exception;
        }

        return ValidationException::withMessages(
            collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["{$statePath}.{$field}" => $messages])
                ->all(),
        );
    }

    /**
     * Integralização é dado financeiro da emissão e alimenta a curva de PU e o
     * saldo devedor: registrar, pela planilha ou à mão, exige `emissions.update`.
     */
    protected function canRecordIntegralizations(): bool
    {
        return auth()->user()?->can(AccessPermission::EmissionsUpdate->value) ?? false;
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

    /**
     * O que vem do banco e da prévia é decimal canônico em string, formatado
     * sem passar por `float`: a prévia do Valor Financeiro mostra exatamente o
     * centavo que a gravação vai derivar, em qualquer ordem de grandeza.
     */
    private static function formatDecimalForDisplay(mixed $state, int $decimals): ?string
    {
        if (blank($state) && $state !== 0 && $state !== '0') {
            return null;
        }

        if (! (is_string($state) && RecordIntegralizationHistory::isPlainDecimal($state))) {
            return number_format((float) $state, $decimals, ',', '.');
        }

        $rounded = app(DecimalRounder::class)->round($state, $decimals);
        $isNegative = str_starts_with($rounded, '-');
        [$integerPart, $fractionPart] = array_pad(explode('.', ltrim($rounded, '-'), 2), 2, '');

        return ($isNegative ? '-' : '')
            .strrev(implode('.', str_split(strrev($integerPart), 3)))
            .($decimals > 0 ? ','.$fractionPart : '');
    }

    /**
     * Máscara pt-BR ("1.000,98765432") para o decimal canônico
     * ("1000.98765432") que {@see RecordIntegralizationHistory} recebe; `null`
     * para vazio ou para o que não for número.
     */
    private static function normalizeDecimalValue(mixed $state): ?string
    {
        if (is_int($state)) {
            return (string) $state;
        }

        if (is_float($state)) {
            return Decimal::of($state)->value();
        }

        if (! is_string($state)) {
            return null;
        }

        $normalizedValue = str_replace(['.', ','], ['', '.'], trim($state));

        return RecordIntegralizationHistory::isPlainDecimal($normalizedValue) ? $normalizedValue : null;
    }

    private static function maskedDecimalRule(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (filled($value) && (self::normalizeDecimalValue($value) === null)) {
                $fail($message);
            }
        };
    }

    private static function syncFinancialValue(Get $get, Set $set): null
    {
        $financialValue = self::calculateFinancialValue($get);

        $set('financial_value', self::formatDecimalForDisplay($financialValue, 2));

        return null;
    }

    private static function calculateFinancialValue(Get $get): ?string
    {
        return app(RecordIntegralizationHistory::class)->financialValueFor(
            self::normalizeDecimalValue($get('quantity')),
            self::normalizeDecimalValue($get('unit_value')),
        );
    }

    /**
     * Fundos aceitos pelo formulário: o cadastro de Fundos do Investidor. Na
     * edição o nome já gravado também vale -- a planilha grava texto livre, e
     * corrigir a quantidade não pode obrigar a trocar o fundo.
     *
     * @return list<string>
     */
    private static function allowedInvestorFunds(?IntegralizationHistory $record): array
    {
        return collect(array_keys(self::getInvestorFundOptions()))
            ->push($record?->investor_fund)
            ->filter(fn (mixed $name): bool => filled($name))
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function getInvestorFundOptions(?string $search = null): array
    {
        return ExpenseServiceProvider::query()
            ->whereHas('type', fn ($query) => $query->where('name', self::INVESTOR_FUND_TYPE))
            ->when(
                filled($search),
                fn ($query): mixed => $query->where('name', 'like', '%'.trim((string) $search).'%'),
            )
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    private static function resolveInvestorFundTypeId(): int
    {
        return (int) ExpenseServiceProviderType::query()
            ->firstOrCreate(['name' => self::INVESTOR_FUND_TYPE])
            ->getKey();
    }
}
