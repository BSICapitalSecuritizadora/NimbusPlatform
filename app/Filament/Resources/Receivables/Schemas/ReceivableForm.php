<?php

namespace App\Filament\Resources\Receivables\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Receivable;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

class ReceivableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do resumo')
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissao')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a emissao.',
                        ]),

                    TextInput::make('reference_month')
                        ->label('Competencia')
                        ->placeholder('mm/aaaa')
                        ->mask('99/9999')
                        ->required()
                        ->formatStateUsing(fn (mixed $state): string => Receivable::formatReferenceMonthForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?string => Receivable::normalizeReferenceMonth($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?string => Receivable::normalizeReferenceMonth($state))
                        ->rule(static function (Get $get, ?Receivable $record = null): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $referenceMonth = Receivable::normalizeReferenceMonth($value);

                                if (($referenceMonth === null) || blank($get('emission_id'))) {
                                    return;
                                }

                                $exists = Receivable::query()
                                    ->where('emission_id', $get('emission_id'))
                                    ->whereDate('reference_month', $referenceMonth)
                                    ->when($record?->exists, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('Ja existe um resumo de recebiveis para esta emissao e competencia.');
                                }
                            };
                        })
                        ->validationMessages([
                            'required' => 'Informe a competencia no formato mm/aaaa.',
                        ]),

                    TextInput::make('portfolio_id')
                        ->label('Carteira')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('active_contracts_count')
                        ->label('Numero de contratos ativos')
                        ->numeric()
                        ->required()
                        ->minValue(0),

                    Textarea::make('average_rate_details')
                        ->label('Taxa media da carteira')
                        ->rows(3)
                        ->required()
                        ->columnSpanFull(),
                ])
                ->columns(4),

            Section::make('Fluxo do mes')
                ->schema([
                    static::moneyField('expected_interest_amount', 'Esperado - juros'),
                    static::moneyField('expected_amortization_amount', 'Esperado - amortizacao'),
                    static::moneyField('received_installment_interest_amount', 'Recebido do mes - juros'),
                    static::moneyField('received_installment_amortization_amount', 'Recebido do mes - amortizacao'),
                    static::moneyField('received_prepayment_interest_amount', 'Antecipacao - juros', defaultValue: 0),
                    static::moneyField('received_prepayment_amortization_amount', 'Antecipacao - amortizacao', defaultValue: 0),
                    static::moneyField('received_default_interest_amount', 'Inadimplencia - juros', defaultValue: 0),
                    static::moneyField('received_default_amortization_amount', 'Inadimplencia - amortizacao', defaultValue: 0),
                    static::moneyField('received_interest_and_penalty_amount', 'Juros e mora', defaultValue: 0),
                ])
                ->columns(3),

            Section::make('Saldos e indicadores')
                ->schema([
                    static::moneyField('performing_balance_pre_event_amount', 'Adimplente pre evento'),
                    static::moneyField('non_performing_balance_pre_event_amount', 'Inadimplente pre evento'),
                    static::moneyField('performing_balance_post_event_amount', 'Adimplente pos evento'),
                    static::moneyField('non_performing_balance_post_event_amount', 'Inadimplente pos evento'),
                    static::moneyField('monthly_default_balance_amount', 'Saldo inadimplencia mes', defaultValue: 0),
                    static::moneyField('total_default_balance_amount', 'Saldo inadimplencia geral', defaultValue: 0),
                    static::moneyField('linked_credits_current_amount', 'Creditos vinculados em dia', defaultValue: 0),
                    static::moneyField('guarantees_value_amount', 'Garantias incorporadas ao PL do CRI', required: false),
                    static::moneyField('total_prepayment_amount', 'Pre-pagamento no mes', defaultValue: 0),
                    static::moneyField('total_outstanding_balance_amount', 'Saldo devedor total'),
                    static::percentageField('top_five_debtors_concentration_ratio', 'Concentracao 5 maiores', required: false),
                    static::percentageField('portfolio_ltv_ratio', 'LTV', required: false),
                    static::percentageField('sale_ltv_ratio', 'LTV venda', required: false),
                    static::decimalField('portfolio_duration_years', 'Duration (anos)'),
                    static::decimalField('portfolio_duration_months', 'Duration (meses)'),
                ])
                ->columns(4),

            Section::make('Vencidos e nao pagos')
                ->schema([
                    static::moneyField('overdue_up_to_30_days_amount', 'Ate 30 dias', defaultValue: 0),
                    static::moneyField('overdue_31_to_60_days_amount', '31 a 60 dias', defaultValue: 0),
                    static::moneyField('overdue_61_to_90_days_amount', '61 a 90 dias', defaultValue: 0),
                    static::moneyField('overdue_91_to_120_days_amount', '91 a 120 dias', defaultValue: 0),
                    static::moneyField('overdue_121_to_150_days_amount', '121 a 150 dias', defaultValue: 0),
                    static::moneyField('overdue_151_to_180_days_amount', '151 a 180 dias', defaultValue: 0),
                    static::moneyField('overdue_181_to_360_days_amount', '181 a 360 dias', defaultValue: 0),
                    static::moneyField('overdue_over_360_days_amount', 'Acima de 360 dias', defaultValue: 0),
                ])
                ->columns(4),

            Section::make('Pagos antecipadamente')
                ->schema([
                    static::moneyField('prepaid_up_to_30_days_amount', 'Ate 30 dias', defaultValue: 0),
                    static::moneyField('prepaid_31_to_60_days_amount', '31 a 60 dias', defaultValue: 0),
                    static::moneyField('prepaid_61_to_90_days_amount', '61 a 90 dias', defaultValue: 0),
                    static::moneyField('prepaid_91_to_120_days_amount', '91 a 120 dias', defaultValue: 0),
                    static::moneyField('prepaid_121_to_150_days_amount', '121 a 150 dias', defaultValue: 0),
                    static::moneyField('prepaid_151_to_180_days_amount', '151 a 180 dias', defaultValue: 0),
                    static::moneyField('prepaid_181_to_360_days_amount', '181 a 360 dias', defaultValue: 0),
                    static::moneyField('prepaid_over_360_days_amount', 'Acima de 360 dias', defaultValue: 0),
                ])
                ->columns(4),

            Section::make('Creditos vinculados')
                ->schema([
                    static::moneyField('linked_credits_up_to_30_days_amount', 'Ate 30 dias', defaultValue: 0),
                    static::moneyField('linked_credits_31_to_60_days_amount', '31 a 60 dias', defaultValue: 0),
                    static::moneyField('linked_credits_61_to_90_days_amount', '61 a 90 dias', defaultValue: 0),
                    static::moneyField('linked_credits_91_to_120_days_amount', '91 a 120 dias', defaultValue: 0),
                    static::moneyField('linked_credits_121_to_150_days_amount', '121 a 150 dias', defaultValue: 0),
                    static::moneyField('linked_credits_151_to_180_days_amount', '151 a 180 dias', defaultValue: 0),
                    static::moneyField('linked_credits_181_to_360_days_amount', '181 a 360 dias', defaultValue: 0),
                    static::moneyField('linked_credits_over_360_days_amount', 'Acima de 360 dias', defaultValue: 0),
                ])
                ->columns(4),
        ]);
    }

    protected static function moneyField(string $name, string $label, bool $required = true, ?float $defaultValue = null): TextInput
    {
        $field = TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
            ->minValue(0)
            ->placeholder('0,00');

        if ($required) {
            $field->required();
        }

        if ($defaultValue !== null) {
            $field->default($defaultValue);
        }

        return $field;
    }

    protected static function percentageField(string $name, string $label, bool $required = true): TextInput
    {
        $field = TextInput::make($name)
            ->label($label)
            ->suffix('%')
            ->inputMode('decimal')
            ->formatStateUsing(fn (mixed $state): ?string => self::formatPercentageForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizePercentageValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizePercentageValue($state))
            ->minValue(0)
            ->placeholder('0,0000');

        if ($required) {
            $field->required();
        }

        return $field;
    }

    protected static function decimalField(string $name, string $label, bool $required = true): TextInput
    {
        $field = TextInput::make($name)
            ->label($label)
            ->inputMode('decimal')
            ->formatStateUsing(fn (mixed $state): ?string => self::formatDecimalForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeDecimalValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeDecimalValue($state))
            ->minValue(0)
            ->placeholder('0,000000');

        if ($required) {
            $field->required();
        }

        return $field;
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

    protected static function normalizePercentageValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && (trim($value) === '')) {
            return null;
        }

        $normalizedValue = Receivable::normalizeMetricDecimal($value);

        return $normalizedValue === null ? null : ($normalizedValue / 100);
    }

    protected static function formatPercentageForDisplay(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format(((float) $value) * 100, 4, ',', '.');
    }

    protected static function normalizeDecimalValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && (trim($value) === '')) {
            return null;
        }

        return Receivable::normalizeMetricDecimal($value);
    }

    protected static function formatDecimalForDisplay(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 6, ',', '.');
    }
}
