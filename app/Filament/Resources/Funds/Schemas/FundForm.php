<?php

namespace App\Filament\Resources\Funds\Schemas;

use App\Concerns\MoneyFormatter;
use App\Filament\Resources\Banks\Schemas\BankForm;
use App\Filament\Resources\FundAllocations\FundAllocationResource;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationDraftLines;
use App\Filament\Resources\FundApplications\Schemas\FundApplicationForm;
use App\Filament\Resources\FundNames\Schemas\FundNameForm;
use App\Filament\Resources\FundTypes\Schemas\FundTypeForm;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\Fund;
use App\Models\FundAllocationLine;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Rules\BankApplicationBelongsToAccount;
use App\Services\Funds\FundAllocationService;
use App\Support\Funds\FundAllocationMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;

class FundForm
{
    /**
     * Marcador dos selects pesquisáveis Operação, Tipo de fundo, Nome do fundo e
     * Aplicação de "Classificação" e Banco de "Dados Bancários". O tema libera o popup
     * dos wrappers recortados da página, junto com o bloco dos Responsáveis pelo Fluxo
     * da operação; nenhum outro campo o recebe.
     */
    public const CLASSIFICATION_SELECT_CLASS = 'bsi-fund-classification-select';

    /**
     * Marcador dos selects pesquisáveis Conta bancária global e Aplicação
     * bancária de "Conta e Aplicação Bancária". Segue a mesma convenção do
     * marcador de classificação, com regras próprias no tema (ver
     * `bsi-fund-bank-link-select` em `theme.css`).
     */
    public const BANK_LINK_SELECT_CLASS = 'bsi-fund-bank-link-select';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Classificação')
                ->description('Defina a operação e as características institucionais do fundo.')
                ->schema([
                    Select::make('emission_id')
                        ->label('Operação')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione a operação...')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a operação.',
                        ]),

                    Select::make('fund_type_id')
                        ->label('Tipo de fundo')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione o tipo de fundo...')
                        ->relationship('fundType', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('fund_name_id', null);
                            }
                        })
                        ->validationMessages([
                            'required' => 'Selecione o tipo de fundo.',
                        ])
                        ->createOptionForm(FundTypeForm::fields())
                        ->editOptionForm(FundTypeForm::fields())
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar tipo')
                                ->tooltip('Cadastrar tipo de fundo')
                                ->modalHeading('Cadastrar tipo de fundo')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar tipo')
                                ->tooltip('Editar tipo de fundo')
                                ->modalHeading('Editar tipo de fundo')
                                ->modalWidth('2xl'),
                        ),

                    Select::make('fund_name_id')
                        ->label('Nome do fundo')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione o nome do fundo...')
                        ->relationship(
                            name: 'fundName',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get('fund_type_id'),
                                fn (Builder $query, mixed $fundTypeId): Builder => $query->where('fund_type_id', $fundTypeId),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (Get $get): bool => blank($get('fund_type_id')))
                        ->helperText('Selecione primeiro o tipo de fundo para listar apenas os nomes compatíveis.')
                        ->validationMessages([
                            'required' => 'Selecione o nome do fundo.',
                        ])
                        ->createOptionForm(fn (Get $get): array => FundNameForm::fields(
                            fundTypeId: self::normalizeSelectedId($get('fund_type_id')),
                            lockFundType: true,
                        ))
                        ->editOptionForm(fn (Get $get): array => FundNameForm::fields(
                            fundTypeId: self::normalizeSelectedId($get('fund_type_id')),
                            lockFundType: true,
                        ))
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar nome')
                                ->tooltip('Cadastrar nome do fundo')
                                ->modalHeading('Cadastrar nome do fundo')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar nome')
                                ->tooltip('Editar nome do fundo')
                                ->modalHeading('Editar nome do fundo')
                                ->modalWidth('2xl'),
                        ),

                    Select::make('fund_application_id')
                        ->label('Aplicação')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione a aplicação...')
                        ->relationship('fundApplication', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Selecione a aplicação.',
                        ])
                        ->createOptionForm(FundApplicationForm::fields())
                        ->editOptionForm(FundApplicationForm::fields())
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar aplicação')
                                ->tooltip('Cadastrar aplicação')
                                ->modalHeading('Cadastrar aplicação')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar aplicação')
                                ->tooltip('Editar aplicação')
                                ->modalHeading('Editar aplicação')
                                ->modalWidth('2xl'),
                        ),

                    TextInput::make('trade_name')
                        ->label('Nome fantasia')
                        ->placeholder('Informe o nome fantasia ou denominação comercial (opcional)...')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('Conta e Aplicação Bancária')
                ->description('Vínculo com a conta física global. Opcional na transição: sem conta selecionada, o fundo segue o cadastro legado manual.')
                ->schema([
                    Select::make('bank_account_id')
                        ->label('Conta bancária global')
                        ->extraAttributes(['class' => static::BANK_LINK_SELECT_CLASS])
                        ->placeholder('Selecione a conta (opcional)...')
                        ->relationship('bankAccount', 'display_account')
                        ->getOptionLabelFromRecordUsing(fn (BankAccount $record): string => $record->optionLabel())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state === $old) {
                                return;
                            }

                            $set('bank_application_id', null);

                            if (blank($state)) {
                                return;
                            }

                            $account = BankAccount::query()->with('bank')->find($state);

                            if ($account instanceof BankAccount) {
                                $set('bank_id', $account->bank_id);
                                $set('agency', $account->formattedAgency());
                                $set('account', $account->formattedAccount());
                            }
                        })
                        ->helperText('Uma conta pode atender a várias operações. Ao selecionar, banco, agência e conta legados são derivados da conta.'),

                    Select::make('bank_application_id')
                        ->label('Aplicação bancária')
                        ->extraAttributes(['class' => static::BANK_LINK_SELECT_CLASS])
                        ->placeholder('Selecione a aplicação (opcional)...')
                        ->relationship(
                            name: 'bankApplication',
                            titleAttribute: 'codigo_produto',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get('bank_account_id'),
                                fn (Builder $query, mixed $accountId): Builder => $query->where('bank_account_id', $accountId),
                            ),
                        )
                        ->getOptionLabelFromRecordUsing(fn (BankApplication $record): string => $record->optionLabel())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->disabled(fn (Get $get): bool => blank($get('bank_account_id')))
                        ->helperText('Filtrada pela conta selecionada. Pode ficar pendente: o fundo pode ser vinculado à conta antes da aplicação ser identificada. Não confundir com a "Aplicação" da classificação.')
                        ->rule(new BankApplicationBelongsToAccount),

                    Placeholder::make('bank_application_shared_note')
                        ->hiddenLabel()
                        ->content(fn (Get $get): ?HtmlString => static::sharedApplicationNote($get('bank_application_id')))
                        ->visible(fn (Get $get): bool => filled($get('bank_application_id')))
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('Dados Bancários')
                ->description('Informações da instituição financeira, agência, conta e integração técnica.')
                ->schema([
                    Select::make('bank_id')
                        ->label('Banco')
                        ->extraAttributes(['class' => static::CLASSIFICATION_SELECT_CLASS])
                        ->placeholder('Selecione o banco...')
                        ->relationship('bank', 'name')
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => blank($get('bank_account_id')))
                        ->disabled(fn (Get $get): bool => filled($get('bank_account_id')))
                        ->helperText(fn (Get $get): ?string => filled($get('bank_account_id'))
                            ? 'Derivado da conta bancária global selecionada.'
                            : null)
                        ->validationMessages([
                            'required' => 'Selecione o banco.',
                        ])
                        ->createOptionForm(BankForm::fields())
                        ->editOptionForm(BankForm::fields())
                        ->createOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Cadastrar banco')
                                ->tooltip('Cadastrar banco')
                                ->modalHeading('Cadastrar banco')
                                ->modalWidth('2xl'),
                        )
                        ->editOptionAction(
                            fn (Action $action): Action => $action
                                ->label('Editar banco')
                                ->tooltip('Editar banco')
                                ->modalHeading('Editar banco')
                                ->modalWidth('2xl'),
                        ),

                    TextInput::make('agency')
                        ->label('Agência')
                        ->required(fn (Get $get): bool => blank($get('bank_account_id')))
                        ->mask('9999-9')
                        ->placeholder('1234-5')
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->disabled(fn (Get $get): bool => filled($get('bank_account_id')))
                        ->helperText(fn (Get $get): ?string => filled($get('bank_account_id'))
                            ? 'Derivado da conta bancária global selecionada.'
                            : null)
                        ->rule('max:6', fn (Get $get): bool => blank($get('bank_account_id')))
                        ->rule('regex:/^\d{4}-\d$/', fn (Get $get): bool => blank($get('bank_account_id')))
                        ->rule('max:10', fn (Get $get): bool => filled($get('bank_account_id')))
                        ->validationMessages([
                            'required' => 'Informe a agência.',
                            'regex' => 'Informe a agência no formato 1234-5.',
                        ]),

                    TextInput::make('account')
                        ->label('Conta Corrente')
                        ->required(fn (Get $get): bool => blank($get('bank_account_id')))
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->disabled(fn (Get $get): bool => filled($get('bank_account_id')))
                        ->helperText(fn (Get $get): ?string => filled($get('bank_account_id'))
                            ? 'Derivado da conta bancária global selecionada.'
                            : null)
                        ->mask(RawJs::make(<<<'JS'
                            (() => {
                                const digits = $input.replace(/\D/g, '');

                                if (digits.length <= 6) {
                                    return '99999-9';
                                }

                                if (digits.length <= 7) {
                                    return '999999-9';
                                }

                                if (digits.length <= 8) {
                                    return '9999999-9';
                                }

                                if (digits.length <= 9) {
                                    return '99999999-9';
                                }

                                return '999999999-9';
                            })()
                            JS))
                        ->placeholder('12345-6')
                        ->live(onBlur: true)
                        ->rule('max:11', fn (Get $get): bool => blank($get('bank_account_id')))
                        ->rule('regex:/^\d{5,9}-\d$/', fn (Get $get): bool => blank($get('bank_account_id')))
                        ->rule('max:15', fn (Get $get): bool => filled($get('bank_account_id')))
                        ->unique(
                            table: Fund::class,
                            column: 'account',
                            ignoreRecord: true,
                            modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                                $rule
                                    ->where('emission_id', $get('emission_id'))
                                    ->where('fund_application_id', $get('fund_application_id'));

                                // Mirrors the application-aware identity: funds
                                // linked to distinct bank applications may share
                                // operation, classification and account string.
                                if (filled($get('bank_application_id'))) {
                                    return $rule->where('bank_application_id', $get('bank_application_id'));
                                }

                                return $rule->whereNull('bank_application_id');
                            },
                        )
                        ->validationMessages([
                            'required' => 'Informe a conta corrente.',
                            'regex' => 'Informe a conta corrente no formato 12345-6 até 123456789-0.',
                            'unique' => 'Já existe um fundo cadastrado com esta combinação de operação, aplicação e conta.',
                        ]),

                    TextInput::make('conta_azul_account_id')
                        ->label('ID da conta no Conta Azul')
                        ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
                        ->extraInputAttributes(['class' => 'font-mono'])
                        ->helperText('UUID operacional deste fundo no Conta Azul: a sincronização automática usa este ID. O ID conceitual da conta global é configurado no cadastro da conta bancária e nunca é copiado para cá.')
                        ->unique(table: Fund::class, column: 'conta_azul_account_id', ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Este ID já está vinculado a outro fundo.',
                        ])
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Saldos e Limites')
                ->description('Controle financeiro do saldo atual e valor mínimo exigido para notificações e relatórios.')
                ->schema([
                    TextInput::make('balance')
                        ->label('Saldo')
                        ->required()
                        ->prefix('R$')
                        ->inputMode('decimal')
                        ->extraInputAttributes(['class' => 'font-mono text-right tabular-nums'])
                        ->mask(RawJs::make(<<<'JS'
                            $money($input, ',', '.')
                        JS))
                        ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->validationMessages([
                            'required' => 'Informe o saldo do fundo.',
                        ])
                        ->placeholder('0,00')
                        ->helperText('Atualize o saldo no primeiro dia de cada mês. O valor do mês anterior será salvo automaticamente no histórico de saldo. Se o saldo ficar abaixo do valor mínimo, um alerta será exibido e enviado por e-mail aos investidores vinculados.'),

                    TextInput::make('minimum_balance')
                        ->label('Valor mínimo')
                        ->prefix('R$')
                        ->inputMode('decimal')
                        ->extraInputAttributes(['class' => 'font-mono text-right tabular-nums'])
                        ->mask(RawJs::make(<<<'JS'
                            $money($input, ',', '.')
                        JS))
                        ->formatStateUsing(fn (mixed $state): ?string => self::formatCurrencyForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?float => self::normalizeCurrencyValue($state))
                        ->helperText('Piso financeiro operacional exigido para monitoramento de conformidade.')
                        ->placeholder('0,00'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('Alocação aprovada')
                ->description('Parcela governada deste fundo na competência vigente — leitura da alocação aprovada, sem editar saldos legados.')
                ->columnSpanFull()
                ->visible(function (?Fund $record): bool {
                    if (! $record instanceof Fund || ! $record->exists) {
                        return false;
                    }

                    if (! (auth()->user()?->can(FundAllocationService::PERMISSION_VIEW) ?? false)) {
                        return false;
                    }

                    return $record->bank_application_id !== null;
                })
                ->schema([
                    Placeholder::make('approved_allocation_summary')
                        ->hiddenLabel()
                        ->content(fn (?Fund $record): HtmlString => self::approvedAllocationNote($record)),
                ]),
        ]);
    }

    protected static function normalizeSelectedId(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Read-only shared-application note for the selected bank application.
     *
     * Runs one aggregate plus one emission lookup on a single-record form,
     * so the cost is constant. Emission names render only when the operator
     * holds emissions.view; the shared state itself is fund-domain data.
     */
    protected static function sharedApplicationNote(mixed $applicationId): ?HtmlString
    {
        if (blank($applicationId)) {
            return null;
        }

        $application = BankApplication::query()->find($applicationId);

        if (! $application instanceof BankApplication) {
            return null;
        }

        if (! $application->isShared()) {
            return new HtmlString(
                '<span class="text-sm text-gray-500 dark:text-gray-400">Esta aplicação atende a uma única operação até o momento.</span>'
            );
        }

        $note = '<span class="inline-flex items-center rounded-md bg-warning-500/10 px-2 py-1 text-xs font-semibold text-warning-700 dark:text-warning-400">Compartilhado</span>'
            .' <span class="text-sm text-gray-600 dark:text-gray-300">Esta aplicação física está vinculada a fundos de mais de uma operação. O saldo abaixo é o valor manual legado deste fundo — não é uma parcela de alocação aprovada, e nunca representa o saldo total da aplicação.';

        if (auth()->user()?->can('emissions.view') ?? false) {
            $names = $application->sharedEmissions()->pluck('name')->implode(', ');

            if (filled($names)) {
                $note .= ' Operações: '.e($names).'.';
            }
        }

        return new HtmlString($note.'</span>');
    }

    /**
     * Read-only approved-allocation summary for the fund's application.
     *
     * Shows the effective (approved) line for the latest competence with an
     * approval — never a draft, which the note states explicitly — plus a
     * link to the period. Runs on the single-record edit form only.
     */
    protected static function approvedAllocationNote(?Fund $record): HtmlString
    {
        if (! $record instanceof Fund || $record->bank_application_id === null) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">—</span>');
        }

        $period = FundAllocationPeriod::query()
            ->where('bank_application_id', $record->bank_application_id)
            ->whereNotNull('effective_revision_id')
            ->orderByDesc('competence')
            ->first();

        if (! $period instanceof FundAllocationPeriod) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Sem alocação aprovada para esta aplicação até o momento. O saldo acima é o valor manual legado do fundo.</span>');
        }

        $revision = FundAllocationRevision::query()
            ->whereKey($period->effective_revision_id)
            ->where('status', FundAllocationRevision::STATUS_APPROVED)
            ->first();

        $line = $revision?->lines()->where('fund_id', $record->getKey())->first();
        $competence = $period->competence?->format('m/Y') ?? '—';

        if (! $line instanceof FundAllocationLine) {
            $url = FundAllocationResource::getUrl('view', ['record' => $period]);

            return new HtmlString(
                '<span class="text-sm text-gray-600 dark:text-gray-300">Sem parcela aprovada para este fundo na competência '
                .e($competence).' (saldo não alocado). </span>'
                .'<a class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400" href="'.e($url).'">Abrir período</a>'
            );
        }

        try {
            $amount = FundAllocationDraftLines::formatCents(
                FundAllocationMoney::toCents((string) $line->amount)
            );
        } catch (\InvalidArgumentException) {
            $amount = '—';
        }

        $url = FundAllocationResource::getUrl('view', ['record' => $period]);

        return new HtmlString(
            '<span class="text-sm text-gray-600 dark:text-gray-300">Parcela aprovada na competência '
            .e($competence).': <strong class="font-mono">'.e($amount).'</strong>. Rascunhos e revisões em análise não aparecem aqui. </span>'
            .'<a class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400" href="'.e($url).'">Abrir período</a>'
        );
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
