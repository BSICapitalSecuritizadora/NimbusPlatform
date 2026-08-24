<?php

namespace App\Filament\Resources\ContractInstallments\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

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
            ->description('Preenchidos apenas quando a parcela for recebida. Data e valor andam juntos.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                self::paymentDateField(),
                self::paidValueField(),
            ]);

        $components[] = Section::make('Cancelamento')
            ->description('Uma parcela cancelada sai do fluxo contratual, mas continua no histórico.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                self::cancellationDateField(),
            ]);

        return $schema->components($components);
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
                ->with('client')
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
                ->with('client')
                ->orderBy('code')
                ->limit(self::CONTRACT_SEARCH_LIMIT)
                ->get()
                ->mapWithKeys(fn (Contract $contract): array => [$contract->getKey() => self::contractOptionLabel($contract)])
                ->all())
            ->getOptionLabelUsing(function (mixed $value): ?string {
                $contract = Contract::with('client')->find($value);

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
            ->helperText('Deixe em branco enquanto a parcela não for recebida.')
            ->validationMessages([
                'required' => 'Informe a data do pagamento junto com o valor pago.',
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

    private static function cancellationDateField(): DatePicker
    {
        return DatePicker::make('cancellation_date')
            ->label('Data de Cancelamento')
            ->native(false)
            ->displayFormat('d/m/Y')
            ->helperText('A parcela deixa de ser considerada a receber, vencida ou inadimplente, e continua visível no histórico.')
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
        return trim(sprintf('%s — %s', $contract->code, $contract->client?->name ?? 'Cliente não informado'));
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
