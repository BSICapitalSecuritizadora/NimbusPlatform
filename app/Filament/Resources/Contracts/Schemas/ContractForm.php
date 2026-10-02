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
use App\Support\BusinessTime;
use App\Support\Contracts\ContractOccupancyOverlap;
use App\Support\Contracts\ContractOccupancyPeriod;
use App\Support\Contracts\ContractOccupancyTimeline;
use App\Support\Dates\SpreadsheetDate;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
use App\Support\SalesBoards\UnitRetirementTimeline;
use Closure;
use Filament\Actions\Action;
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

    /**
     * O aviso de competência calculado em cada componente, pela combinação de
     * campos que o produziu. O mapa fraco morre com a instância do componente,
     * sem sobreviver à requisição.
     *
     * @var WeakMap<Component, array<string, string|null>>|null
     */
    private static ?WeakMap $registeredCompetenceNotices = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Unidade e Compradores')
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
                    self::registeredCompetenceCallout(),
                ]),
        ]);
    }

    /**
     * O aviso de competência já registrada, logo abaixo dos campos que o
     * provocam: a data da venda, o valor de uma venda já gravada e a data do
     * distrato. Não bloqueia -- o fato atrasado tem caminho governado --, e
     * depois de salvar a página repete o aviso numa notificação persistente.
     */
    private static function registeredCompetenceCallout(): Callout
    {
        return Callout::make(fn (Get $get, Component $livewire, ?Contract $record = null): string => str_contains((string) self::registeredCompetenceNotice($get, $livewire, $record), 'registrada manualmente')
            ? 'Data em competência já registrada'
            : 'Data em competência já publicada')
            ->warning()
            ->description(fn (Get $get, Component $livewire, ?Contract $record = null): ?string => self::registeredCompetenceNotice($get, $livewire, $record))
            ->visible(fn (Get $get, Component $livewire, ?Contract $record = null): bool => self::registeredCompetenceNotice($get, $livewire, $record) !== null)
            ->columnSpanFull();
    }

    /**
     * O aviso do estado atual do formulário.
     *
     * O título, a descrição e a visibilidade perguntam a mesma coisa na mesma
     * renderização, e a resposta fica lembrada na instância do componente --
     * que morre com a requisição --, pelo estado dos campos que a produzem.
     */
    public static function registeredCompetenceNotice(Get $get, Component $livewire, ?Contract $record = null): ?string
    {
        $arguments = [
            $get('construction_id'),
            $get('sale_date'),
            $get('sale_value'),
            self::requiresCancellationDate($get) ? $get('cancellation_date') : null,
            $record?->getKey(),
        ];

        $key = md5(serialize($arguments));
        self::$registeredCompetenceNotices ??= new WeakMap;
        $memo = self::$registeredCompetenceNotices[$livewire] ?? [];

        if (! array_key_exists($key, $memo)) {
            $memo = [$key => SourceEntryCompetenceNotice::forContract(
                $arguments[0],
                $arguments[1],
                $arguments[2],
                $arguments[3],
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
                ->with('openRetirement:id,construction_unit_id,retired_on')
                ->orderBy('block')
                ->orderBy('unit')
                ->get()
                /**
                 * A unidade baixada continua na lista -- os contratos antigos
                 * dela continuam editáveis --, marcada para ninguém vendê-la
                 * sem saber.
                 */
                ->mapWithKeys(fn (ConstructionUnit $unit): array => [
                    $unit->id => $unit->display_name.($unit->openRetirement === null
                        ? ''
                        : ' · baixada desde '.$unit->openRetirement->retired_on->format('d/m/Y')),
                ])
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

    /**
     * The buyers of the contract.
     *
     * Plural because a sale can be made to more than one person, and there is no
     * hierarchy between them: no main buyer, no order, no share. The set is the
     * relationship.
     *
     * Only live clients can be picked -- an archived one keeps appearing on the
     * contracts it already signed, but must not be given a new one -- while the
     * label resolver reads through the archive so an existing contract still
     * renders the buyer it has.
     */
    private static function clientField(): Select
    {
        return Select::make('client_ids')
            ->label('Compradores')
            ->multiple()
            ->required()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Client::query()
                ->search($search)
                ->orderBy('name')
                ->limit(self::CLIENT_SEARCH_LIMIT)
                ->get()
                ->mapWithKeys(fn (Client $client): array => [$client->getKey() => self::clientOptionLabel($client)])
                ->all())
            ->getOptionLabelsUsing(fn (array $values): array => Client::withTrashed()
                ->whereKey($values)
                ->get()
                ->mapWithKeys(fn (Client $client): array => [$client->getKey() => self::clientOptionLabel($client)])
                ->all())
            ->helperText('Busque por nome, razão social ou CPF/CNPJ. Um contrato pode ter mais de um comprador.')
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
                'required' => 'Selecione ao menos um comprador.',
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
            ->live(onBlur: true)
            /**
             * Today in the business calendar, the bound the spreadsheet import
             * applies too. The UTC day runs ahead of São Paulo from 21:00 on,
             * and in that window a UTC bound accepted a sale dated tomorrow
             * that the import refuses.
             */
            ->maxDate(static fn (): string => BusinessTime::dateString())
            /**
             * No sale of this portfolio is older than 1990: a year like 0026 is a
             * lost digit. The floor of the spreadsheet imports, which the Sales
             * Board also refuses to publish.
             */
            ->minDate(SpreadsheetDate::MINIMUM_YEAR.'-01-01')
            /**
             * The sale cannot begin while the unit is still held by the contract
             * before it. Reported here when this contract is the one starting
             * too early; the mirror case lands on the distrato date instead.
             *
             * Nem pode o contrato ocupar a unidade num período em que ela está
             * baixada: ali a unidade não compõe o Quadro, e a venda sumiria do
             * número.
             */
            ->rule(static fn (Get $get, ?Contract $record = null): Closure => static function (
                string $attribute,
                mixed $value,
                Closure $fail,
            ) use ($get, $record): void {
                $overlap = self::findOverlap($get, $record, $value, $get('cancellation_date'));

                if (($overlap !== null) && $overlap->later->isSubject) {
                    $fail($overlap->describe(self::unitLabel($get)));

                    return;
                }

                self::failWhenUnitIsRetired($get, $value, $get('cancellation_date'), $fail);
            })
            ->validationMessages([
                'required' => 'Informe a data da venda.',
                'before_or_equal' => 'A data da venda não pode ser futura.',
                'after_or_equal' => 'Data da venda anterior a 1990: confira o ano.',
            ]);
    }

    private static function saleValueField(): TextInput
    {
        return TextInput::make('sale_value')
            ->label('Valor da Venda')
            ->required()
            ->live(onBlur: true)
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
            ->live(onBlur: true)
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

    /**
     * O período `[venda, distrato)` do contrato que o formulário vai gravar não
     * pode cruzar um período de baixa da unidade. A regra é a mesma da
     * importação de contratos e da permuta ({@see UnitRetirementTimeline}), com
     * a mesma frase.
     */
    private static function failWhenUnitIsRetired(Get $get, mixed $saleDate, mixed $cancellationDate, Closure $fail): void
    {
        $unitId = $get('construction_unit_id');
        $status = ContractStatus::tryFrom((string) $get('status'));

        if (blank($unitId) || ($status === null) || blank($saleDate)) {
            return;
        }

        $period = ContractOccupancyPeriod::fromValues(
            code: (string) $get('code'),
            clientName: null,
            saleDate: $saleDate,
            cancellationDate: $cancellationDate,
            status: $status,
        );

        if ($period === null) {
            return;
        }

        $conflict = UnitRetirementTimeline::forUnits([(int) $unitId])
            ->conflictWith((int) $unitId, $period->startsOn, $period->endsOn);

        if ($conflict !== null) {
            $fail($conflict->describe(self::unitLabel($get)));
        }
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
            '%s%s — %s: %s',
            $client->name,
            $client->trashed() ? ' (arquivado)' : '',
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
