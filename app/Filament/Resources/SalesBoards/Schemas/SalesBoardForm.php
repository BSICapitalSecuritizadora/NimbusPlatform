<?php

namespace App\Filament\Resources\SalesBoards\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

class SalesBoardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do Quadro de Vendas')
                ->columnSpanFull()
                ->schema([
                    Select::make('emission_id')
                        ->label('Operação')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->placeholder('Selecione a operação...')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('construction_id', null);
                            }
                        })
                        ->validationMessages([
                            'required' => 'Selecione a operação.',
                        ]),

                    Select::make('construction_id')
                        ->label('Empreendimento')
                        ->relationship(
                            name: 'construction',
                            titleAttribute: 'development_name',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get('emission_id'),
                                fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->placeholder(fn (Get $get): string => blank($get('emission_id')) ? 'Aguardando seleção da operação...' : 'Selecione o empreendimento...')
                        ->required()
                        ->disabled(fn (Get $get): bool => blank($get('emission_id')))
                        ->helperText(fn (Get $get): string => blank($get('emission_id')) ? 'Selecione a operação para listar os empreendimentos.' : 'Empreendimentos vinculados à operação selecionada.')
                        ->rule(static function (Get $get): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if (blank($value) || blank($get('emission_id'))) {
                                    return;
                                }

                                $exists = Construction::query()
                                    ->whereKey($value)
                                    ->where('emission_id', $get('emission_id'))
                                    ->exists();

                                if (! $exists) {
                                    $fail('Selecione um empreendimento vinculado à operação escolhida.');
                                }
                            };
                        })
                        ->validationMessages([
                            'required' => 'Selecione o empreendimento.',
                        ]),

                    static::referenceMonthField(),
                ])
                ->columns(['sm' => 1, 'md' => 3, 'lg' => 3]),

            static::quantitiesSection(),

            static::valuesSection(),

            static::changeReasonField(),
        ]);
    }

    /**
     * Justification required when the competence being saved already has a
     * registered position for that construction and the emission is no longer
     * in the "Em Elaboração" phase. Stored on the history version, not on the
     * board itself.
     */
    public static function changeReasonField(): Textarea
    {
        return Textarea::make('change_reason')
            ->label('Motivo da alteração')
            ->helperText('Informe o motivo desta nova alteração, pois já existe um registro do Quadro de Vendas para esta competência.')
            ->rows(3)
            ->required()
            ->maxLength(2000)
            ->dehydrated(false)
            ->columnSpanFull()
            ->visible(fn (Textarea $component): bool => static::needsChangeReason($component))
            ->validationMessages([
                'required' => 'Informe o motivo da alteração.',
            ]);
    }

    /**
     * Evaluated against the competence currently typed in the form, so moving to
     * an unregistered competence drops the requirement straight away.
     */
    protected static function needsChangeReason(Component $component): bool
    {
        $get = $component->makeGetUtility();

        $emissionId = $get('emission_id');
        $constructionId = $get('construction_id');
        $referenceMonth = SalesBoard::normalizeReferenceMonth($get('reference_month'));

        if (blank($emissionId) || blank($constructionId) || ($referenceMonth === null)) {
            return false;
        }

        if (Emission::query()->whereKey($emissionId)->value('status') === Emission::STATUS_DRAFT) {
            return false;
        }

        return SalesBoardHistory::query()
            ->whereDate('reference_month', $referenceMonth)
            ->whereHas(
                'salesBoard',
                fn (Builder $query): Builder => $query
                    ->where('emission_id', $emissionId)
                    ->where('construction_id', $constructionId),
            )
            ->exists();
    }

    /**
     * Value of the position this update started from, when the form was opened
     * as a new update. Absent everywhere else, which disables the diff hints.
     */
    protected static function previousPositionValue(mixed $livewire, string $field): mixed
    {
        $previousPosition = (is_object($livewire) && property_exists($livewire, 'previousPosition'))
            ? $livewire->previousPosition
            : null;

        return is_array($previousPosition) ? ($previousPosition[$field] ?? null) : null;
    }

    /**
     * Reference month field shared by the Sales Board resource and by the
     * inline Sales Board step of the emission creation wizard.
     *
     * Repeating a competence is no longer an error: it produces a new version of
     * that position, subject to the change reason rule.
     */
    public static function referenceMonthField(): TextInput
    {
        return TextInput::make('reference_month')
            ->label('Competência')
            ->placeholder('MM/AAAA')
            ->mask('99/9999')
            ->prefixIcon('heroicon-m-calendar')
            ->required()
            ->live(onBlur: true)
            ->extraInputAttributes([
                'class' => 'text-center font-mono',
            ])
            ->formatStateUsing(fn (mixed $state): string => SalesBoard::formatReferenceMonthForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?string => SalesBoard::normalizeReferenceMonth($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?string => SalesBoard::normalizeReferenceMonth($state))
            ->validationMessages([
                'required' => 'Informe a competência no formato MM/AAAA.',
            ]);
    }

    public static function quantitiesSection(): Section
    {
        return Section::make('Quantidades por Status')
            ->columnSpanFull()
            ->schema([
                static::quantityField('stock_units', 'Estoque'),
                static::quantityField('financed_units', 'Financiado'),
                static::quantityField('paid_units', 'Quitado'),
                static::quantityField('exchanged_units', 'Permutado'),

                TextInput::make('total_units')
                    ->label('Quantidade Total')
                    ->disabled()
                    ->dehydrated(false)
                    ->default(0)
                    ->prefixIcon('heroicon-m-calculator')
                    ->suffix('un.')
                    ->extraInputAttributes([
                        'class' => 'text-right font-bold font-mono tabular-nums',
                    ])
                    ->extraAttributes([
                        'class' => 'bsi-total-units-field',
                    ]),
            ])
            ->columns(['sm' => 1, 'md' => 2, 'lg' => 5, 'xl' => 5]);
    }

    public static function valuesSection(): Section
    {
        return Section::make('Valores por Status')
            ->columnSpanFull()
            ->schema([
                static::moneyField('stock_value', 'Valor em estoque'),
                static::moneyField('financed_value', 'Valor financiado'),
                static::moneyField('paid_value', 'Valor quitado'),
                static::moneyField('exchanged_value', 'Valor permutado'),
            ])
            ->columns(['sm' => 1, 'md' => 2, 'lg' => 4, 'xl' => 4]);
    }

    protected static function quantityField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->default(0)
            ->numeric()
            ->integer()
            ->minValue(0)
            ->live(onBlur: true)
            ->extraInputAttributes([
                'class' => 'text-right font-mono tabular-nums',
            ])
            ->afterStateUpdated(fn (Set $set, Get $get): null => self::fillTotalUnits($set, $get))
            ->hint(fn (mixed $livewire, mixed $state): ?string => self::changedFromPreviousHint(
                self::previousPositionValue($livewire, $name),
                $state,
                fn (mixed $value): string => (string) self::normalizeIntegerValue($value),
            ))
            ->hintColor('warning')
            ->hintIcon(fn (mixed $livewire, mixed $state): ?string => self::changedFromPreviousHint(
                self::previousPositionValue($livewire, $name),
                $state,
                fn (mixed $value): string => (string) self::normalizeIntegerValue($value),
            ) === null ? null : 'heroicon-m-arrow-path')
            ->validationMessages([
                'required' => "Informe o valor de {$label}.",
                'integer' => "Informe um número inteiro válido para {$label}.",
                'min' => "O valor de {$label} não pode ser negativo.",
            ]);
    }

    protected static function moneyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->default(0)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
            ->minValue(0)
            ->placeholder('0,00')
            ->live(onBlur: true)
            ->extraInputAttributes([
                'class' => 'text-right font-mono tabular-nums',
            ])
            ->hint(fn (mixed $livewire, mixed $state): ?string => self::changedFromPreviousHint(
                self::previousPositionValue($livewire, $name),
                $state,
                fn (mixed $value): string => MoneyFormatter::formatCurrencyForDisplay($value),
            ))
            ->hintColor('warning')
            ->validationMessages([
                'required' => "Informe o {$label}.",
                'min' => "O {$label} não pode ser negativo.",
            ]);
    }

    /**
     * "Anterior: X" hint, shown only while the field differs from the position
     * this update started from.
     *
     * @param  Closure(mixed): string  $format
     */
    protected static function changedFromPreviousHint(mixed $previousValue, mixed $currentValue, Closure $format): ?string
    {
        if ($previousValue === null) {
            return null;
        }

        $previous = $format($previousValue);

        return $previous === $format($currentValue) ? null : 'Anterior: '.$previous;
    }

    protected static function fillTotalUnits(Set $set, Get $get): null
    {
        $set('total_units', self::calculateTotalUnitsFromState($get));

        return null;
    }

    protected static function calculateTotalUnitsFromState(Get $get): int
    {
        return self::normalizeIntegerValue($get('stock_units'))
         + self::normalizeIntegerValue($get('financed_units'))
         + self::normalizeIntegerValue($get('paid_units'))
         + self::normalizeIntegerValue($get('exchanged_units'));
    }

    protected static function normalizeIntegerValue(mixed $value): int
    {
        if (blank($value)) {
            return 0;
        }

        return (int) $value;
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
}
