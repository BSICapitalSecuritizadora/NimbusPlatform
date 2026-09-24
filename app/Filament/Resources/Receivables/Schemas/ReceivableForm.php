<?php

namespace App\Filament\Resources\Receivables\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Receivable;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

class ReceivableForm
{
    /**
     * Marcador do select pesquisável "Emissão" de "Identificação do resumo". O tema
     * libera o popup da seção recortada do cadastro, junto com o bloco dos
     * Responsáveis pelo Fluxo da operação; nenhum outro campo o recebe.
     */
    public const EMISSION_SELECT_CLASS = 'bsi-receivable-emission-select';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Identificação do resumo')
                    ->description('Operação, competência e dados gerais da carteira.')
                    ->extraAttributes(['class' => 'bsi-ficha-identificacao'])
                    ->schema([
                        Select::make('emission_id')
                            ->label('Emissão')
                            ->relationship('emission', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->extraAttributes(['class' => static::EMISSION_SELECT_CLASS])
                            ->validationMessages([
                                'required' => 'Selecione a emissão.',
                            ]),

                        TextInput::make('reference_month')
                            ->label('Mês')
                            ->placeholder('MM/AAAA')
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
                                        $fail('Já existe um resumo de recebíveis para esta operação e mês.');
                                    }
                                };
                            })
                            ->validationMessages([
                                'required' => 'Informe o mês no formato MM/AAAA.',
                            ]),

                        TextInput::make('portfolio_id')
                            ->label('Carteira')
                            ->required()
                            ->maxLength(255)
                            ->validationMessages([
                                'required' => 'Informe a carteira.',
                            ]),

                        TextInput::make('active_contracts_count')
                            ->label('Contratos ativos')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->validationMessages([
                                'required' => 'Informe o número de contratos ativos.',
                            ]),

                        Textarea::make('average_rate_details')
                            ->label('Taxa média da carteira')
                            ->rows(3)
                            ->required()
                            ->columnSpanFull()
                            ->validationMessages([
                                'required' => 'Informe a taxa média da carteira.',
                            ]),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),

                Section::make('Fluxo do mês')
                    ->description('Valores esperados, recebidos e antecipados na competência.')
                    ->extraAttributes(['class' => 'bsi-ficha-fluxo'])
                    ->schema([
                        Grid::make(2)
                            ->extraAttributes(['class' => 'bsi-flow-head'])
                            ->schema([
                                Text::make('Juros'),
                                Text::make('Amortização'),
                            ]),

                        Fieldset::make('Esperado')
                            ->contained(false)
                            ->columns(2)
                            ->schema([
                                static::moneyField('expected_interest_amount', 'Esperado - Juros')->hiddenLabel(),
                                static::moneyField('expected_amortization_amount', 'Esperado - Amortização')->hiddenLabel(),
                            ]),

                        Fieldset::make('Recebido do mês')
                            ->contained(false)
                            ->columns(2)
                            ->schema([
                                static::moneyField('received_installment_interest_amount', 'Recebido do Mês - Juros')->hiddenLabel(),
                                static::moneyField('received_installment_amortization_amount', 'Recebido do Mês - Amortização')->hiddenLabel(),
                            ]),

                        Fieldset::make('Antecipação')
                            ->contained(false)
                            ->columns(2)
                            ->schema([
                                static::moneyField('received_prepayment_interest_amount', 'Antecipação - Juros', defaultValue: 0)->hiddenLabel(),
                                static::moneyField('received_prepayment_amortization_amount', 'Antecipação - Amortização', defaultValue: 0)->hiddenLabel(),
                            ]),

                        Fieldset::make('Inadimplência')
                            ->contained(false)
                            ->columns(2)
                            ->schema([
                                static::moneyField('received_default_interest_amount', 'Inadimplência - Juros', defaultValue: 0)->hiddenLabel(),
                                static::moneyField('received_default_amortization_amount', 'Inadimplência - Amortização', defaultValue: 0)->hiddenLabel(),
                            ]),

                        Grid::make(2)
                            ->extraAttributes(['class' => 'bsi-flow-juros-mora'])
                            ->schema([
                                static::moneyField('received_interest_and_penalty_amount', 'Juros e mora', defaultValue: 0),
                            ]),
                    ])
                    ->columns(1),

                Section::make('Saldos e indicadores')
                    ->description('Saldos da carteira, inadimplência, pré-pagamento e métricas de risco.')
                    ->extraAttributes(['class' => 'bsi-ficha-saldos'])
                    ->schema([
                        Text::make('Carteira e saldos devedores')
                            ->extraAttributes(['class' => 'bsi-subhead bsi-subhead-first'])
                            ->columnSpanFull(),

                        Grid::make(['default' => 1, 'sm' => 2, 'lg' => 3])
                            ->extraAttributes(['class' => 'bsi-saldos-grid'])
                            ->columnSpanFull()
                            ->schema([
                                static::moneyField('performing_balance_pre_event_amount', 'Adimplente pré-evento'),
                                static::moneyField('non_performing_balance_pre_event_amount', 'Inadimplente pré-evento'),
                                static::moneyField('performing_balance_post_event_amount', 'Adimplente pós-evento'),
                                static::moneyField('non_performing_balance_post_event_amount', 'Inadimplente pós-evento'),
                                static::moneyField('total_outstanding_balance_amount', 'Saldo devedor total'),
                                static::moneyField('linked_credits_current_amount', 'Créditos vinculados em dia', defaultValue: 0),
                            ]),

                        Text::make('Inadimplência')
                            ->extraAttributes(['class' => 'bsi-subhead'])
                            ->columnSpanFull(),

                        static::moneyField('monthly_default_balance_amount', 'Saldo inadimplência mês', defaultValue: 0),
                        static::moneyField('total_default_balance_amount', 'Saldo inadimplência geral', defaultValue: 0),

                        Text::make('Pré-pagamento e garantias')
                            ->extraAttributes(['class' => 'bsi-subhead'])
                            ->columnSpanFull(),

                        static::moneyField('total_prepayment_amount', 'Pré-pagamento no mês', defaultValue: 0),
                        static::moneyField('guarantees_value_amount', 'Garantias incorporadas ao PL do CRI', required: false),

                        Text::make('Concentração e risco')
                            ->extraAttributes(['class' => 'bsi-subhead'])
                            ->columnSpanFull(),

                        static::percentageField('top_five_debtors_concentration_ratio', 'Concentração 5 maiores', required: false),
                        static::percentageField('portfolio_ltv_ratio', 'LTV', required: false),
                        static::percentageField('sale_ltv_ratio', 'LTV venda', required: false),
                        static::decimalField('portfolio_duration_years', 'Duration (anos)'),
                        static::decimalField('portfolio_duration_months', 'Duration (meses)'),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),

                Section::make('Vencidos e não pagos')
                    ->description('Distribuição da inadimplência por faixa de atraso.')
                    ->extraAttributes(['class' => 'bsi-ficha-aging'])
                    ->schema([
                        static::moneyField('overdue_up_to_30_days_amount', 'Até 30 dias', defaultValue: 0),
                        static::moneyField('overdue_31_to_60_days_amount', '31 a 60 dias', defaultValue: 0),
                        static::moneyField('overdue_61_to_90_days_amount', '61 a 90 dias', defaultValue: 0),
                        static::moneyField('overdue_91_to_120_days_amount', '91 a 120 dias', defaultValue: 0),
                        static::moneyField('overdue_121_to_150_days_amount', '121 a 150 dias', defaultValue: 0),
                        static::moneyField('overdue_151_to_180_days_amount', '151 a 180 dias', defaultValue: 0),
                        static::moneyField('overdue_181_to_360_days_amount', '181 a 360 dias', defaultValue: 0),
                        static::moneyField('overdue_over_360_days_amount', 'Acima de 360 dias', defaultValue: 0),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),

                Section::make('Pagos antecipadamente')
                    ->description('Distribuição dos valores quitados antes do vencimento por faixa.')
                    ->extraAttributes(['class' => 'bsi-ficha-aging'])
                    ->schema([
                        static::moneyField('prepaid_up_to_30_days_amount', 'Até 30 dias', defaultValue: 0),
                        static::moneyField('prepaid_31_to_60_days_amount', '31 a 60 dias', defaultValue: 0),
                        static::moneyField('prepaid_61_to_90_days_amount', '61 a 90 dias', defaultValue: 0),
                        static::moneyField('prepaid_91_to_120_days_amount', '91 a 120 dias', defaultValue: 0),
                        static::moneyField('prepaid_121_to_150_days_amount', '121 a 150 dias', defaultValue: 0),
                        static::moneyField('prepaid_151_to_180_days_amount', '151 a 180 dias', defaultValue: 0),
                        static::moneyField('prepaid_181_to_360_days_amount', '181 a 360 dias', defaultValue: 0),
                        static::moneyField('prepaid_over_360_days_amount', 'Acima de 360 dias', defaultValue: 0),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),

                Section::make('Créditos vinculados')
                    ->description('Distribuição da carteira vinculada por faixa de vencimento.')
                    ->extraAttributes(['class' => 'bsi-ficha-aging'])
                    ->schema([
                        static::moneyField('linked_credits_up_to_30_days_amount', 'Até 30 dias', defaultValue: 0),
                        static::moneyField('linked_credits_31_to_60_days_amount', '31 a 60 dias', defaultValue: 0),
                        static::moneyField('linked_credits_61_to_90_days_amount', '61 a 90 dias', defaultValue: 0),
                        static::moneyField('linked_credits_91_to_120_days_amount', '91 a 120 dias', defaultValue: 0),
                        static::moneyField('linked_credits_121_to_150_days_amount', '121 a 150 dias', defaultValue: 0),
                        static::moneyField('linked_credits_151_to_180_days_amount', '151 a 180 dias', defaultValue: 0),
                        static::moneyField('linked_credits_181_to_360_days_amount', '181 a 360 dias', defaultValue: 0),
                        static::moneyField('linked_credits_over_360_days_amount', 'Acima de 360 dias', defaultValue: 0),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),
            ]);
    }

    protected static function moneyField(string $name, string $label, bool $required = true, ?float $defaultValue = null): TextInput
    {
        $field = TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->extraInputAttributes(['class' => 'bsi-num'])
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
            ->extraInputAttributes(['class' => 'bsi-num'])
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
            ->extraInputAttributes(['class' => 'bsi-num'])
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
