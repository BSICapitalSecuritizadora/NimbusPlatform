<?php

namespace App\Filament\Resources\ContractInstallments\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Support\BusinessTime;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use WeakMap;

class ContractInstallmentForm
{
    /**
     * State paths of the two selectors that exist only to narrow the contract
     * list below them. The installment reaches both through the contract, so
     * neither is written from here.
     */
    public const EMISSION_FIELD = 'emission_id';

    public const CONSTRUCTION_FIELD = 'construction_id';

    /**
     * How many contracts the picker returns per search, so the query stays
     * bounded once the base holds thousands of sales.
     */
    private const CONTRACT_SEARCH_LIMIT = 50;

    /**
     * The floor of the payment and cancellation dates: the year floor of the
     * spreadsheet imports ({@see SpreadsheetDate::MINIMUM_YEAR}), which the
     * Sales Board also enforces when it derives a position.
     */
    private const MINIMUM_DATE = SpreadsheetDate::MINIMUM_YEAR.'-01-01';

    /**
     * O aviso de competência calculado em cada componente, pela combinação de
     * campos que o produziu. O mapa fraco morre com a instância do componente.
     *
     * @var WeakMap<Component, array<string, string|null>>|null
     */
    private static ?WeakMap $registeredCompetenceNotices = null;

    /**
     * @param  int|null  $contractId  set when the form is opened from inside a
     *                                contract, which is already the whole
     *                                context: emission, development, unit,
     *                                client and contract are known, so none of
     *                                them is asked for again.
     */
    public static function configure(Schema $schema, ?int $contractId = null): Schema
    {
        $components = [];

        if ($contractId === null) {
            $components[] = Section::make('Contrato')
                ->description('A qual venda esta parcela pertence. A seleção desce da emissão até o contrato.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    self::emissionField(),
                    self::constructionField(),
                    self::contractField(),
                ]);
        }

        $components[] = Section::make('Parcela')
            ->description('Identificação, vencimento e valor previsto.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                self::numberField($contractId),
                self::dueDateField(),
                self::expectedValueField(),
            ]);

        $components[] = Section::make('Recebimento')
            ->description('Preenchidos apenas quando a parcela for recebida. Data e valor andam juntos; o desconto, quando houver, é registrado com eles.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                self::paymentDateField(),
                self::paidValueField(),
                self::discountValueField(),
            ]);

        $components[] = Section::make('Cancelamento')
            ->description('Uma parcela cancelada sai do fluxo contratual, mas continua no histórico.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                self::cancellationDateField(),
                self::registeredCompetenceCallout($contractId),
            ]);

        return $schema->components($components);
    }

    /**
     * O aviso de competência já registrada para o pagamento e o cancelamento da
     * parcela -- os dois decidem a quitação. O empreendimento vem do contrato.
     * Não bloqueia; depois de salvar, a tela repete o aviso numa notificação
     * persistente.
     */
    private static function registeredCompetenceCallout(?int $contractId): Callout
    {
        return Callout::make(fn (Get $get, Component $livewire, ?ContractInstallment $record = null): string => str_contains((string) self::registeredCompetenceNotice($get, $livewire, $record, $contractId), 'registrada manualmente')
            ? 'Data em competência já registrada'
            : 'Data em competência já publicada')
            ->warning()
            ->description(fn (Get $get, Component $livewire, ?ContractInstallment $record = null): ?string => self::registeredCompetenceNotice($get, $livewire, $record, $contractId))
            ->visible(fn (Get $get, Component $livewire, ?ContractInstallment $record = null): bool => self::registeredCompetenceNotice($get, $livewire, $record, $contractId) !== null)
            ->columnSpanFull();
    }

    /**
     * O aviso do estado atual do formulário, lembrado na instância do
     * componente pelo estado dos campos que o produzem.
     */
    public static function registeredCompetenceNotice(Get $get, Component $livewire, ?ContractInstallment $record = null, ?int $contractId = null): ?string
    {
        $resolvedContractId = $contractId ?? $get('contract_id') ?? $record?->contract_id;
        $arguments = [$resolvedContractId, $get('payment_date'), $get('cancellation_date'), $record?->getKey()];
        $key = md5(serialize($arguments));

        self::$registeredCompetenceNotices ??= new WeakMap;
        $memo = self::$registeredCompetenceNotices[$livewire] ?? [];

        if (! array_key_exists($key, $memo)) {
            $memo = [$key => SourceEntryCompetenceNotice::forInstallment(
                blank($resolvedContractId) ? null : Contract::query()->whereKey($resolvedContractId)->value('construction_id'),
                $arguments[1],
                $arguments[2],
                $record?->exists ? $record : null,
            )];

            self::$registeredCompetenceNotices[$livewire] = $memo;
        }

        return $memo[$key];
    }

    private static function emissionField(): Select
    {
        return Select::make(self::EMISSION_FIELD)
            ->label('Emissão')
            ->options(fn (): array => Emission::query()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->preload()
            ->required()
            ->dehydrated(false)
            ->live()
            ->afterStateHydrated(function (Select $component, ?ContractInstallment $record): void {
                $component->state($record?->contract?->construction?->emission_id);
            })
            ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                if ($state === $old) {
                    return;
                }

                $set(self::CONSTRUCTION_FIELD, null);
                $set('contract_id', null);
            })
            ->columnSpanFull()
            ->validationMessages([
                'required' => 'Selecione a emissão.',
            ]);
    }

    private static function constructionField(): Select
    {
        return Select::make(self::CONSTRUCTION_FIELD)
            ->label('Empreendimento')
            ->options(fn (Get $get): array => Construction::query()
                ->when(
                    $get(self::EMISSION_FIELD),
                    fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                    fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                )
                ->orderBy('development_name')
                ->pluck('development_name', 'id')
                ->all())
            ->searchable()
            ->preload()
            ->required()
            ->dehydrated(false)
            ->live()
            ->disabled(fn (Get $get): bool => blank($get(self::EMISSION_FIELD)))
            ->afterStateHydrated(function (Select $component, ?ContractInstallment $record): void {
                $component->state($record?->contract?->construction_id);
            })
            ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                if ($state !== $old) {
                    $set('contract_id', null);
                }
            })
            ->helperText('Selecione primeiro a emissão para listar apenas os empreendimentos vinculados.')
            ->columnSpanFull()
            ->validationMessages([
                'required' => 'Selecione o empreendimento.',
            ]);
    }

    /**
     * Scoped to the development, which is how the contracts module made the code
     * unique: "A606" of one development is a different contract from "A606" of
     * another, and picking from a global list would happily book the schedule
     * against the wrong sale.
     */
    private static function contractField(): Select
    {
        return Select::make('contract_id')
            ->label('Contrato')
            ->required()
            ->searchable()
            ->live()
            ->disabled(fn (Get $get): bool => blank($get(self::CONSTRUCTION_FIELD)))
            ->getSearchResultsUsing(fn (string $search, Get $get): array => Contract::query()
                ->when(
                    $get(self::CONSTRUCTION_FIELD),
                    fn (Builder $query, mixed $constructionId): Builder => $query->where('construction_id', $constructionId),
                    fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                )
                ->with('clients')
                ->search($search)
                ->orderBy('code')
                ->limit(self::CONTRACT_SEARCH_LIMIT)
                ->get()
                ->mapWithKeys(fn (Contract $contract): array => [$contract->getKey() => self::contractOptionLabel($contract)])
                ->all())
            ->options(fn (Get $get): array => Contract::query()
                ->when(
                    $get(self::CONSTRUCTION_FIELD),
                    fn (Builder $query, mixed $constructionId): Builder => $query->where('construction_id', $constructionId),
                    fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                )
                ->with('clients')
                ->orderBy('code')
                ->limit(self::CONTRACT_SEARCH_LIMIT)
                ->get()
                ->mapWithKeys(fn (Contract $contract): array => [$contract->getKey() => self::contractOptionLabel($contract)])
                ->all())
            ->getOptionLabelUsing(function (mixed $value): ?string {
                $contract = Contract::with('clients')->find($value);

                return $contract === null ? null : self::contractOptionLabel($contract);
            })
            ->helperText('Busque por código do contrato, cliente ou unidade.')
            ->columnSpanFull()
            ->validationMessages([
                'required' => 'Selecione o contrato.',
            ]);
    }

    private static function numberField(?int $contractId): TextInput
    {
        return TextInput::make('number')
            ->label('Número da Parcela')
            ->required()
            ->maxLength(50)
            ->live(onBlur: true)
            ->placeholder('001, 12, ENTRADA, CHAVES')
            ->helperText('Como a parcela é identificada no contrato. Zeros à esquerda são preservados.')
            ->rule(static fn (Get $get, ?ContractInstallment $record = null): Closure => static function (
                string $attribute,
                mixed $value,
                Closure $fail,
            ) use ($get, $record, $contractId): void {
                $resolvedContractId = $contractId ?? $get('contract_id');

                if (! ContractInstallment::isDuplicateNumber($resolvedContractId, (string) $value, $record?->getKey())) {
                    return;
                }

                $fail('Já existe uma parcela com este número neste contrato.');
            })
            ->validationMessages([
                'required' => 'Informe o número da parcela.',
            ]);
    }

    private static function dueDateField(): DatePicker
    {
        return DatePicker::make('due_date')
            ->label('Vencimento')
            ->required()
            ->native(false)
            ->displayFormat('d/m/Y')
            ->helperText('Data contratual prevista para o pagamento.')
            ->validationMessages([
                'required' => 'Informe a data de vencimento.',
            ]);
    }

    private static function expectedValueField(): TextInput
    {
        return self::moneyField('expected_value', 'Valor Previsto')
            ->required()
            ->minValue(0.01)
            ->placeholder('10.000,00')
            ->validationMessages([
                'required' => 'Informe o valor previsto.',
                'min' => 'O valor previsto deve ser maior que zero.',
            ]);
    }

    /**
     * Payment date and value travel together: either both are filled or neither
     * is. A date without a value would create an installment that looks received
     * and still owes everything; a value without a date would leave a receipt
     * nobody can place in time.
     *
     * Expressed as a conditional `required` on each field rather than as a
     * closure rule, because Laravel skips a non-implicit rule when the value is
     * absent -- which is exactly the case being caught here.
     */
    private static function paymentDateField(): DatePicker
    {
        return DatePicker::make('payment_date')
            ->label('Data do Pagamento')
            ->native(false)
            ->displayFormat('d/m/Y')
            ->live(onBlur: true)
            ->required(fn (Get $get): bool => self::hasReceipt($get('paid_value')))
            /**
             * A receipt is a fact: it is recorded after it happens, in the
             * business calendar -- the same rule the spreadsheet import applies.
             * And no receipt of this portfolio is older than 1990: a year like
             * 0026 is a lost digit, which the Sales Board refuses to publish.
             */
            ->minDate(self::MINIMUM_DATE)
            ->maxDate(fn (): string => BusinessTime::dateString())
            ->helperText('Deixe em branco enquanto a parcela não for recebida.')
            ->validationMessages([
                'required' => 'Informe a data do pagamento junto com o valor pago.',
                'after_or_equal' => 'A data do pagamento não pode ser anterior a 01/01/1990: confira o ano.',
                'before_or_equal' => 'A data do pagamento não pode ser futura: um recebimento só é registrado depois de acontecer.',
            ]);
    }

    /**
     * No ceiling against the expected value on purpose: juros, multa and
     * correção monetária routinely push a receipt above what was originally due,
     * and refusing that would force the operators to lie about what they got.
     */
    private static function paidValueField(): TextInput
    {
        return self::moneyField('paid_value', 'Valor Pago')
            ->minValue(0.01)
            ->placeholder('10.000,00')
            ->live(onBlur: true)
            ->required(fn (Get $get): bool => filled($get('payment_date')))
            ->helperText('Pode superar o previsto quando houver juros, multa ou correção.')
            ->validationMessages([
                'required' => 'Informe o valor pago junto com a data do pagamento.',
                'min' => 'O valor pago deve ser maior que zero.',
            ]);
    }

    /**
     * The discount given on the receipt -- pontualidade, antecipação, a
     * settlement with a discount. It only exists together with the payment, it
     * is positive and it never goes above the expected value.
     *
     * The cross-field checks are a closure rule on purpose: they only matter
     * when a discount was filled, and a non-implicit rule is skipped exactly
     * when the field is empty.
     */
    private static function discountValueField(): TextInput
    {
        return self::moneyField('discount_value', 'Desconto concedido')
            /**
             * `numeric` makes `min` compare the amount itself: without it the
             * rule measures the length of the value, and a zero would pass.
             */
            ->rule('numeric')
            ->minValue(0.01)
            ->placeholder('500,00')
            ->live(onBlur: true)
            ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                $discount = IntegerMoney::cents($value);

                if (($discount === null) || ($discount <= 0)) {
                    return;
                }

                if (blank($get('payment_date')) || ! self::hasReceipt($get('paid_value'))) {
                    $fail('Registre o desconto junto com o pagamento da parcela.');

                    return;
                }

                $expected = IntegerMoney::cents(self::normalizeCurrency($get('expected_value')));

                if (($expected !== null) && ($discount > $expected)) {
                    $fail('O desconto não pode superar o valor previsto.');
                }
            })
            ->helperText('Desconto dado na baixa (pontualidade, antecipação). A parcela conta como paga quando pago + desconto cobre o previsto.')
            ->validationMessages([
                'min' => 'O desconto deve ser maior que zero.',
            ]);
    }

    private static function cancellationDateField(): DatePicker
    {
        return DatePicker::make('cancellation_date')
            ->label('Data de Cancelamento')
            ->native(false)
            ->displayFormat('d/m/Y')
            ->live(onBlur: true)
            ->minDate(self::MINIMUM_DATE)
            ->helperText('A parcela deixa de ser considerada a receber, vencida ou inadimplente, e continua visível no histórico.')
            ->validationMessages([
                'after_or_equal' => 'A data de cancelamento não pode ser anterior a 01/01/1990: confira o ano.',
            ])
            ->columnSpanFull();
    }

    private static function moneyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => blank($state)
                ? null
                : MoneyFormatter::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrency($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrency($state));
    }

    private static function contractOptionLabel(Contract $contract): string
    {
        return trim(sprintf('%s — %s', $contract->code, $contract->buyersLabel(1)));
    }

    /**
     * Whether the paid value describes an actual receipt. A zero is not one, so
     * it does not drag the payment date into being required -- the operator gets
     * the single error that matters instead of two.
     *
     * The same reading the spreadsheet import applies, where a zero in the paid
     * column means the installment has not been received yet.
     */
    private static function hasReceipt(mixed $state): bool
    {
        $value = self::normalizeCurrency($state);

        return ($value !== null) && ($value > 0);
    }

    private static function normalizeCurrency(mixed $state): ?float
    {
        if (blank($state)) {
            return null;
        }

        return MoneyFormatter::normalizeDecimalValue($state);
    }
}
