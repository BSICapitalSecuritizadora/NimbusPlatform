<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Support\Contracts\ContractOccupancyOverlap;
use App\Support\Contracts\ContractOccupancyPeriod;
use App\Support\Contracts\ContractOccupancyTimeline;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

class ContractForm
{
    /**
     * State path of the emission selector. Like the construction one, it exists
     * only to narrow the list below it: the contract reaches both through the
     * unit, so neither is written from here.
     */
    public const EMISSION_FIELD = 'emission_id';

    /**
     * How many clients the picker returns per search. The base will hold
     * thousands, so the query is always bounded.
     */
    private const CLIENT_SEARCH_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Unidade e Cliente')
                ->description('Quem comprou e qual imóvel. A seleção desce da emissão até a unidade.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    self::emissionField(),
                    self::constructionField(),
                    self::constructionUnitField(),
                    self::clientField(),
                ]),

            Section::make('Dados Comerciais')
                ->description('Identificação e valores da venda.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    self::codeField(),
                    self::statusField(),
                    self::saleDateField(),
                    self::saleValueField(),
                    self::cancellationDateField(),
                ]),
        ]);
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
            ->afterStateHydrated(function (Select $component, ?Contract $record): void {
                $component->state($record?->construction?->emission_id);
            })
            ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                if ($state === $old) {
                    return;
                }

                $set('construction_id', null);
                $set('construction_unit_id', null);
            })
            ->columnSpanFull()
            ->validationMessages([
                'required' => 'Selecione a emissão.',
            ]);
    }

    private static function constructionField(): Select
    {
        return Select::make('construction_id')
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
            ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                if ($state !== $old) {
                    $set('construction_unit_id', null);
                }
            })
            ->helperText('Selecione primeiro a emissão para listar apenas os empreendimentos vinculados.')
            ->columnSpanFull()
            ->validationMessages([
                'required' => 'Selecione o empreendimento.',
            ]);
    }

    private static function constructionUnitField(): Select
    {
        return Select::make('construction_unit_id')
            ->label('Unidade')
            ->options(fn (Get $get): array => ConstructionUnit::query()
                ->when(
                    $get('construction_id'),
                    fn (Builder $query, mixed $constructionId): Builder => $query->where('construction_id', $constructionId),
                    fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                )
                ->orderBy('block')
                ->orderBy('unit')
                ->get()
                ->mapWithKeys(fn (ConstructionUnit $unit): array => [$unit->id => $unit->display_name])
                ->all())
            ->searchable()
            ->required()
            ->live()
            ->disabled(fn (Get $get): bool => blank($get('construction_id')))
            ->helperText('Somente unidades do empreendimento selecionado.')
            ->rule(static fn (Get $get, ?Contract $record = null): Closure => static function (
                string $attribute,
                mixed $value,
                Closure $fail,
            ) use ($get, $record): void {
                self::failWhenUnitIsTaken($value, $get('status'), $record?->getKey(), $fail);
            })
            ->columnSpanFull()
            ->validationMessages([
                'required' => 'Selecione a unidade.',
            ]);
    }

    private static function clientField(): Select
    {
        return Select::make('client_id')
            ->label('Cliente')
            ->required()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Client::query()
                ->search($search)
                ->orderBy('name')
                ->limit(self::CLIENT_SEARCH_LIMIT)
                ->get()
                ->mapWithKeys(fn (Client $client): array => [$client->getKey() => self::clientOptionLabel($client)])
                ->all())
            ->getOptionLabelUsing(function (mixed $value): ?string {
                $client = Client::withTrashed()->find($value);

                return $client === null ? null : self::clientOptionLabel($client);
            })
            ->helperText('Busque por nome, razão social ou CPF/CNPJ.')
            /**
             * Opens the very same Client registration, in another tab: a buyer
             * created from here must be the same record the Clients module
             * manages, with the same document rules.
             */
            ->hintAction(
                Action::make('createClient')
                    ->label('Cadastrar novo cliente')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (): string => ClientResource::getUrl('create'), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => ClientResource::canCreate()),
            )
            ->columnSpanFull()
            ->validationMessages([
                'required' => 'Selecione o cliente.',
            ]);
    }

    private static function codeField(): TextInput
    {
        return TextInput::make('code')
            ->label('Código do Contrato')
            ->required()
            ->maxLength(100)
            ->live(onBlur: true)
            ->placeholder('A606, CVC-00123, CTR-2026-0001')
            ->helperText('Identificador usado pela incorporadora. Aceita letras, números e separadores.')
            ->rule(static fn (Get $get, ?Contract $record = null): Closure => static function (
                string $attribute,
                mixed $value,
                Closure $fail,
            ) use ($get, $record): void {
                if (! Contract::isDuplicateCode($get('construction_id'), (string) $value, $record?->getKey())) {
                    return;
                }

                $fail('Já existe um contrato com este código neste empreendimento.');
            })
            ->validationMessages([
                'required' => 'Informe o código do contrato.',
            ]);
    }

    private static function statusField(): Select
    {
        return Select::make('status')
            ->label('Status')
            ->options(ContractStatus::options())
            ->default(ContractStatus::Active->value)
            ->required()
            ->live()
            ->afterStateUpdated(function (Set $set, mixed $state): void {
                if (! (ContractStatus::tryFrom((string) $state)?->requiresCancellationDate() ?? false)) {
                    $set('cancellation_date', null);
                }
            })
            ->validationMessages([
                'required' => 'Selecione o status do contrato.',
            ]);
    }

    private static function saleDateField(): DatePicker
    {
        return DatePicker::make('sale_date')
            ->label('Data da Venda')
            ->required()
            ->native(false)
            ->displayFormat('d/m/Y')
            ->maxDate(now()->endOfDay())
            /**
             * The sale cannot begin while the unit is still held by the contract
             * before it. Reported here when this contract is the one starting
             * too early; the mirror case lands on the distrato date instead.
             */
            ->rule(static fn (Get $get, ?Contract $record = null): Closure => static function (
                string $attribute,
                mixed $value,
                Closure $fail,
            ) use ($get, $record): void {
                $overlap = self::findOverlap($get, $record, $value, $get('cancellation_date'));

                if (($overlap !== null) && $overlap->later->isSubject) {
                    $fail($overlap->describe(self::unitLabel($get)));
                }
            })
            ->validationMessages([
                'required' => 'Informe a data da venda.',
                'max' => 'A data da venda não pode ser futura.',
            ]);
    }

    private static function saleValueField(): TextInput
    {
        return TextInput::make('sale_value')
            ->label('Valor da Venda')
            ->required()
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => blank($state)
                ? null
                : MoneyFormatter::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrency($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrency($state))
            ->minValue(0.01)
            ->placeholder('850.000,00')
            ->validationMessages([
                'required' => 'Informe o valor da venda.',
                'min' => 'O valor da venda deve ser maior que zero.',
            ]);
    }

    private static function cancellationDateField(): DatePicker
    {
        return DatePicker::make('cancellation_date')
            ->label('Data do Distrato')
            ->native(false)
            ->displayFormat('d/m/Y')
            ->visible(fn (Get $get): bool => self::requiresCancellationDate($get))
            ->required(fn (Get $get): bool => self::requiresCancellationDate($get))
            ->afterOrEqual('sale_date')
            /**
             * A distrato is a fact, never a schedule. The rule and the reason
             * live on {@see Contract::cancellationDateHasTakenEffect()}, which is
             * what the import checks; `maxDate` states the same bound in the
             * vocabulary the picker understands, so the calendar cannot even
             * offer a day the rule would refuse.
             */
            ->maxDate(now()->endOfDay())
            /**
             * Moving a distrato forward can swallow a sale that came after it,
             * which is the same overlap seen from the other side.
             */
            ->rule(static fn (Get $get, ?Contract $record = null): Closure => static function (
                string $attribute,
                mixed $value,
                Closure $fail,
            ) use ($get, $record): void {
                $overlap = self::findOverlap($get, $record, $get('sale_date'), $value);

                if (($overlap !== null) && $overlap->earlier->isSubject) {
                    $fail($overlap->describe(self::unitLabel($get)));
                }
            })
            ->helperText('Preenchida apenas em contratos distratados.')
            ->validationMessages([
                'required' => 'Informe a data do distrato.',
                'after_or_equal' => 'A data do distrato não pode ser anterior à data da venda.',
                'before_or_equal' => 'A data do distrato não pode ser futura: enquanto o distrato não ocorrer, o contrato permanece ativo.',
            ]);
    }

    private static function requiresCancellationDate(Get $get): bool
    {
        return ContractStatus::tryFrom((string) $get('status'))?->requiresCancellationDate() ?? false;
    }

    /**
     * Whether saving this contract would leave the unit held by two contracts at
     * once at some point in time.
     *
     * The rule itself lives in {@see ContractOccupancyTimeline}, shared with the
     * spreadsheet import: a history the monthly reconciliation refuses to write
     * must not be reachable by typing it in by hand either.
     */
    private static function findOverlap(
        Get $get,
        ?Contract $record,
        mixed $saleDate,
        mixed $cancellationDate,
    ): ?ContractOccupancyOverlap {
        $unitId = $get('construction_unit_id');
        $status = ContractStatus::tryFrom((string) $get('status'));

        if (blank($unitId) || ($status === null) || blank($saleDate)) {
            return null;
        }

        $subject = ContractOccupancyPeriod::fromValues(
            code: (string) $get('code'),
            clientName: null,
            saleDate: $saleDate,
            cancellationDate: $cancellationDate,
            status: $status,
        );

        if ($subject === null) {
            return null;
        }

        return ContractOccupancyTimeline::of([
            ...Contract::occupancyPeriodsFor($unitId, $record?->getKey()),
            $subject,
        ])->firstOverlap();
    }

    private static function unitLabel(Get $get): string
    {
        return ConstructionUnit::query()->whereKey($get('construction_unit_id'))->first()?->display_name
            ?? 'selecionada';
    }

    /**
     * A unit holds at most one live contract. The previous one has to be
     * distratado first -- a resale is a new contract, never an edit of the old.
     */
    private static function failWhenUnitIsTaken(mixed $unitId, mixed $status, mixed $ignoreId, Closure $fail): void
    {
        $status = ContractStatus::tryFrom((string) $status);

        if (($status === null) || ! $status->occupiesUnit()) {
            return;
        }

        $occupying = Contract::occupyingContract($unitId, $ignoreId);

        if ($occupying === null) {
            return;
        }

        $fail(sprintf(
            'Esta unidade já possui um contrato %s (%s). Registre o distrato antes de cadastrar um novo contrato.',
            mb_strtolower($occupying->status->label()),
            $occupying->code,
        ));
    }

    private static function clientOptionLabel(Client $client): string
    {
        return sprintf(
            '%s — %s: %s',
            $client->name,
            $client->person_type->documentLabel(),
            Client::maskDocument($client->document),
        );
    }

    private static function normalizeCurrency(mixed $state): ?float
    {
        if (blank($state)) {
            return null;
        }

        return MoneyFormatter::normalizeDecimalValue($state);
    }
}
