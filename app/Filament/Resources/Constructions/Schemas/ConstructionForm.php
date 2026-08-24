<?php

namespace App\Filament\Resources\Constructions\Schemas;

use App\Actions\Expenses\LookupExpenseServiceProviderCnpj;
use App\Concerns\MoneyFormatter;
use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Models\Construction;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ConstructionForm
{
    /**
     * Holds the CNPJ whose name was already resolved through the public API.
     * While it is filled, the development name came from the lookup and stays
     * read-only; it is cleared whenever the lookup fails or the CNPJ changes,
     * which hands the field back to the user.
     */
    protected const LOOKUP_RESOLVED_CNPJ_FLAG = '__development_name_lookup_resolved';

    /**
     * Holds the CNPJ whose lookup could not resolve a name. It is the only
     * situation in which the development name is typed by hand, so it is also
     * the only situation in which the field is editable.
     */
    protected const LOOKUP_FAILED_CNPJ_FLAG = '__development_name_lookup_failed';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(static::sections());
    }

    /**
     * Full set of construction sections used by the Construction resource.
     *
     * Individual sections are public so other schemas -- such as the inline
     * Sales Board step of the emission creation wizard -- can reuse them
     * without duplicating fields or validation rules.
     *
     * @return array<int, Section>
     */
    public static function sections(): array
    {
        return [
            static::identificationSection(),
            static::locationSection(),
            static::scheduleAndValuesSection(),
            static::measurementSection(),
        ];
    }

    /**
     * @param  bool  $withEmission  Renders the emission selector. Must be disabled when the
     *                              owning emission is already implied by the surrounding schema.
     */
    public static function identificationSection(bool $withEmission = true): Section
    {
        return Section::make('Identificação')
            ->description('Informações gerais sobre o empreendimento.')
            ->schema(array_values(array_filter([
                $withEmission
                    ? Select::make('emission_id')
                        ->label('Emissão')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(['sm' => 2, 'lg' => 1])
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ])
                    : null,

                Hidden::make(self::LOOKUP_RESOLVED_CNPJ_FLAG)
                    ->dehydrated(false),

                Hidden::make(self::LOOKUP_FAILED_CNPJ_FLAG)
                    ->dehydrated(false),

                TextInput::make('development_name')
                    ->label('Empreendimento')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(['sm' => 2, 'lg' => 3])
                    ->readOnly(fn (Get $get): bool => blank($get(self::LOOKUP_FAILED_CNPJ_FLAG)))
                    ->helperText(fn (Get $get): string => match (true) {
                        filled($get(self::LOOKUP_FAILED_CNPJ_FLAG)) => 'A busca não retornou o nome deste CNPJ. Preencha manualmente.',
                        filled($get(self::LOOKUP_RESOLVED_CNPJ_FLAG)) => 'Preenchido automaticamente a partir do CNPJ informado.',
                        default => 'Informe o CNPJ do empreendimento para buscar o nome automaticamente.',
                    })
                    ->validationMessages([
                        'required' => 'Informe o empreendimento.',
                    ]),

                TextInput::make('development_cnpj')
                    ->label('CNPJ do empreendimento')
                    ->required()
                    ->placeholder('00.000.000/0000-00')
                    ->mask('99.999.999/9999-99')
                    ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                    ->stripCharacters(['.', '/', '-'])
                    ->dehydrateStateUsing(fn (?string $state): ?string => self::normalizeCnpj($state))
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => self::normalizeCnpj($state))
                    ->rule(static function (): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail): void {
                            if (blank($value)) {
                                return;
                            }

                            if (strlen((string) $value) !== 14) {
                                $fail('Informe um CNPJ válido com 14 dígitos.');
                            }
                        };
                    })
                    ->validationMessages([
                        'required' => 'Informe o CNPJ do empreendimento.',
                    ])
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?string $state): null => self::resolveDevelopmentNameFromCnpj($get, $set, $state))
                    ->columnSpan(['sm' => 1, 'lg' => 1]),

                TextInput::make('development_trade_name')
                    ->label('Nome fantasia')
                    ->maxLength(255)
                    ->placeholder('Informe o nome fantasia')
                    ->helperText('Preenchido automaticamente quando o CNPJ possuir nome fantasia registrado.')
                    ->columnSpan(['sm' => 1, 'lg' => 3]),
            ])))
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->columnSpanFull();
    }

    public static function locationSection(): Section
    {
        return Section::make('Localização')
            ->description('Endereço principal da obra.')
            ->schema([
                TextInput::make('city')
                    ->label('Cidade')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(['sm' => 2])
                    ->validationMessages([
                        'required' => 'Informe a cidade.',
                    ]),

                Select::make('state')
                    ->label('Estado')
                    ->options(Construction::STATE_OPTIONS)
                    ->searchable()
                    ->extraAttributes(['class' => 'fi-fixed-positioning-context'])
                    ->required()
                    ->columnSpan(['sm' => 1])
                    ->validationMessages([
                        'required' => 'Selecione o estado.',
                    ]),
            ])
            ->columns(['default' => 1, 'sm' => 3])
            ->columnSpanFull();
    }

    public static function scheduleAndValuesSection(): Section
    {
        return Section::make('Prazos e Valores')
            ->description('Período de execução e estimativa financeira.')
            ->schema([
                TextInput::make('construction_start_date')
                    ->label('Início da obra')
                    ->placeholder('mm/aaaa')
                    ->mask('99/9999')
                    ->suffixIcon('heroicon-m-calendar')
                    ->extraInputAttributes(['class' => 'tabular-nums'])
                    ->columnSpan(['sm' => 1, 'lg' => 1])
                    ->formatStateUsing(fn (mixed $state): string => Construction::formatMonthForDisplay($state))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state))
                    ->mutateStateForValidationUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state)),

                TextInput::make('construction_end_date')
                    ->label('Conclusão da obra')
                    ->placeholder('mm/aaaa')
                    ->mask('99/9999')
                    ->suffixIcon('heroicon-m-calendar')
                    ->extraInputAttributes(['class' => 'tabular-nums'])
                    ->columnSpan(['sm' => 1, 'lg' => 1])
                    ->formatStateUsing(fn (mixed $state): string => Construction::formatMonthForDisplay($state))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state))
                    ->mutateStateForValidationUsing(fn (mixed $state): ?string => Construction::normalizeMonthDate($state))
                    ->rule(static function (Get $get): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $startDate = Construction::normalizeMonthDate($get('construction_start_date'));
                            $endDate = Construction::normalizeMonthDate($value);

                            if (($startDate === null) || ($endDate === null)) {
                                return;
                            }

                            if ($endDate < $startDate) {
                                $fail('A conclusão da obra deve ser igual ou posterior ao início da obra.');
                            }
                        };
                    }),

                TextInput::make('estimated_value')
                    ->label('Valor previsto')
                    ->prefix('R$')
                    ->inputMode('decimal')
                    ->extraInputAttributes(['class' => 'text-right tabular-nums'])
                    ->mask(RawJs::make(<<<'JS'
                            $money($input, ',', '.')
                        JS))
                    ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
                    ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                    ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                    ->placeholder('0,00')
                    ->columnSpan(['sm' => 2, 'lg' => 2]),
            ])
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->columnSpanFull();
    }

    /**
     * @param  bool  $useRelationship  Selects the measurement company through the
     *                                 `measurementCompany` relationship. Must be disabled when the
     *                                 section is embedded in a schema that is not bound to a
     *                                 Construction model, such as the emission creation wizard.
     */
    public static function measurementSection(bool $useRelationship = true): Section
    {
        $measurementCompanyField = Select::make('measurement_company_id')
            ->label('Empresa de medição');

        $measurementCompanyField = $useRelationship
            ? $measurementCompanyField
                ->relationship(
                    name: 'measurementCompany',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->whereHas('type', fn (Builder $query): Builder => $query->where('name', Construction::MEASUREMENT_COMPANY_TYPE_NAME)),
                )
                ->searchable(['name', 'cnpj'])
            : $measurementCompanyField
                ->options(fn (): array => self::getMeasurementCompanyOptions())
                ->getSearchResultsUsing(fn (string $search): array => self::getMeasurementCompanyOptions($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::measurementCompanyQuery()->whereKey($value)->value('name'))
                ->searchable();

        return Section::make('Medição')
            ->description('Empresa responsável pelo acompanhamento e medição.')
            ->schema([
                $measurementCompanyField
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, mixed $state): null => self::fillMeasurementCompanyCnpj($set, $state))
                    ->createOptionForm(fn (): array => ExpenseServiceProviderForm::fields(
                        serviceProviderTypeId: self::resolveMeasurementCompanyTypeId(),
                        lockServiceProviderType: true,
                    ))
                    ->createOptionUsing(
                        fn (array $data): int => (int) ExpenseServiceProvider::query()->create($data)->getKey(),
                    )
                    ->createOptionAction(
                        fn (Action $action): Action => $action
                            ->label('Cadastrar Empresa de Medição')
                            ->tooltip('Cadastrar empresa de medição')
                            ->modalHeading('Cadastrar Empresa de Medição'),
                    )
                    ->rule(static function (): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail): void {
                            if (blank($value)) {
                                return;
                            }

                            if (! self::measurementCompanyQuery()->whereKey($value)->exists()) {
                                $fail('Selecione uma empresa de medição do tipo Engenharia.');
                            }
                        };
                    })
                    ->validationMessages([
                        'required' => 'Selecione a empresa de medição.',
                    ])
                    ->columnSpan(['lg' => 3]),

                TextInput::make('measurement_company_cnpj')
                    ->label('CNPJ da empresa de medição')
                    ->readOnly()
                    ->helperText('Preenchido automaticamente a partir da empresa de medição selecionada.')
                    ->dehydrated(false)
                    ->afterStateHydrated(function (TextInput $component): void {
                        $record = $component->getRecord();

                        $component->state(ExpenseServiceProvider::formatCnpj(
                            $record instanceof Construction ? $record->measurementCompany?->cnpj : null,
                        ));
                    })
                    ->columnSpan(['lg' => 2]),
            ])
            ->columns(['default' => 1, 'lg' => 5])
            ->columnSpanFull();
    }

    /**
     * Providers of any other type are not eligible, so the inline creation form
     * locks the type to the engineering one, creating it when still missing.
     */
    protected static function resolveMeasurementCompanyTypeId(): int
    {
        return (int) ExpenseServiceProviderType::query()
            ->firstOrCreate(['name' => Construction::MEASUREMENT_COMPANY_TYPE_NAME])
            ->getKey();
    }

    /**
     * @return Builder<ExpenseServiceProvider>
     */
    protected static function measurementCompanyQuery(): Builder
    {
        return ExpenseServiceProvider::query()
            ->whereHas('type', fn (Builder $query): Builder => $query->where('name', Construction::MEASUREMENT_COMPANY_TYPE_NAME));
    }

    /**
     * @return array<int|string, string>
     */
    protected static function getMeasurementCompanyOptions(?string $search = null): array
    {
        return self::measurementCompanyQuery()
            ->when(
                filled($search),
                fn (Builder $query): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('name', 'like', '%'.trim((string) $search).'%')
                        ->orWhere('cnpj', 'like', '%'.trim((string) $search).'%'),
                ),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Fills the development name from the public CNPJ registry.
     *
     * Reuses the same lookup already backing the service provider form. When it
     * cannot resolve a name the field is left unlocked so the user can type it.
     */
    protected static function resolveDevelopmentNameFromCnpj(Get $get, Set $set, ?string $state): null
    {
        $cnpj = Str::digitsOnly((string) $state);
        $resolvedCnpj = Str::digitsOnly((string) $get(self::LOOKUP_RESOLVED_CNPJ_FLAG));

        if (($cnpj !== '') && ($cnpj === $resolvedCnpj)) {
            return null;
        }

        $set(self::LOOKUP_RESOLVED_CNPJ_FLAG, null);
        $set(self::LOOKUP_FAILED_CNPJ_FLAG, null);

        if (strlen($cnpj) !== 14) {
            return null;
        }

        $result = app(LookupExpenseServiceProviderCnpj::class)->handle($cnpj);

        if ($result['status'] !== 200) {
            $set(self::LOOKUP_FAILED_CNPJ_FLAG, $cnpj);

            Notification::make()
                ->warning()
                ->title('Não foi possível obter o nome do empreendimento.')
                ->body((string) ($result['payload']['error'] ?? 'Informe o empreendimento manualmente.'))
                ->send();

            return null;
        }

        $set('development_name', (string) data_get($result, 'payload.data.name', ''));

        $tradeName = data_get($result, 'payload.data.trade_name');

        if (filled($tradeName)) {
            $set('development_trade_name', (string) $tradeName);
        }

        $set(self::LOOKUP_RESOLVED_CNPJ_FLAG, $cnpj);

        return null;
    }

    protected static function fillMeasurementCompanyCnpj(Set $set, mixed $state): null
    {
        $cnpj = blank($state)
            ? null
            : ExpenseServiceProvider::query()->find($state)?->cnpj;

        $set('measurement_company_cnpj', ExpenseServiceProvider::formatCnpj($cnpj));

        return null;
    }

    protected static function normalizeCurrencyValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && (trim($value) === '')) {
            return null;
        }

        return MoneyFormatter::normalizeDecimalValue($value);
    }

    protected static function formatCurrencyForDisplay(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && (trim($value) === '')) {
            return null;
        }

        return MoneyFormatter::formatCurrencyForDisplay($value);
    }

    protected static function normalizeCnpj(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $digits = Str::digitsOnly($value);

        return $digits === '' ? null : $digits;
    }
}
