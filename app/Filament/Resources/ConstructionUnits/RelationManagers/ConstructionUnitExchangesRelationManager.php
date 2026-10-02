<?php

namespace App\Filament\Resources\ConstructionUnits\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\Enums\ConstructionUnitExchangeKind;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Support\GuardsRelationManagerAccess;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Services\SalesBoards\ConstructionUnitExchangeService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\UnitValueResolver;
use App\Support\BusinessTime;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Permutas da unidade.
 *
 * A posição inicial é declarada enquanto o Quadro de Vendas ainda não usou a
 * obra -- Emissão em elaboração, fora do Quadro automatizado e sem competência
 * apurada ({@see ConstructionUnitExchangeService::initialPositionIsFrozen()}).
 * Depois disso ela é a referência contra a qual todo o resto é comparado, e
 * deixá-la editável apagaria essa referência sem deixar rastro.
 *
 * Com a posição congelada, a permuta só muda pela Gestão: "Registrar permuta
 * extraordinária", "Encerrar permuta" -- que distrata, com confirmação, o
 * contrato de permuta que continuaria ocupando a unidade -- e "Substituir
 * permuta", que corrige valor ou vigência sem distratar nada (e que, antes de o
 * Quadro usar a obra, corrige a posição inicial). Todas passam pelo
 * {@see ConstructionUnitExchangeService}, que confere de novo o ator, o motivo e
 * a regra -- a tela só esconde e explica. Aqui não há editar nem excluir: nada
 * do que decidiu uma competência some.
 *
 * Toda data tem o piso de 01/01/1990, como os formulários de contrato e de
 * parcela: o seletor aceita um ano de quatro dígitos digitado na caixa de ano,
 * e um "0026" viraria um bloqueio que a permuta, sem edição, não desfaz. A
 * permuta gravada antes do piso se corrige por "Substituir permuta"; a
 * encerrada antes de o encerramento distratar o contrato de permuta tem
 * "Distratar contrato de permuta".
 */
class ConstructionUnitExchangesRelationManager extends RelationManager
{
    use GuardsRelationManagerAccess;

    /**
     * O piso de toda data de permuta: o das importações, que a apuração do
     * Quadro também aplica.
     */
    private const MINIMUM_DATE = SpreadsheetDate::MINIMUM_YEAR.'-01-01';

    protected static string $relationship = 'exchanges';

    protected static ?string $title = 'Permutas';

    protected static ?string $modelLabel = 'Permuta';

    protected static ?string $pluralModelLabel = 'Permutas';

    /**
     * Se a posição inicial já congelou, lido uma vez por requisição: a tabela,
     * o estado vazio e as três ações perguntam. Privado, o Livewire não o
     * serializa, e a pergunta é refeita a cada requisição.
     */
    private ?bool $initialPositionFrozen = null;

    /**
     * O contrato de permuta que o encerramento distrataria, por permuta e data,
     * lido uma vez por requisição. Privado pelo mesmo motivo.
     *
     * @var array<string, array{contract: Contract|null, error: string|null}>
     */
    private array $exchangeTargets = [];

    /**
     * O contrato de permuta que continua na unidade depois de cada permuta
     * encerrada, lido uma vez por requisição. Privado pelo mesmo motivo.
     *
     * @var array<int, Contract|null>
     */
    private array $endedExchangeContracts = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * O Quadro já usou a obra: a permuta passa a ser decisão da Gestão.
     */
    protected function isInitialPositionFrozen(): bool
    {
        if ($this->initialPositionFrozen === null) {
            /** @var ConstructionUnit $unit */
            $unit = $this->getOwnerRecord();

            $this->initialPositionFrozen = app(ConstructionUnitExchangeService::class)->initialPositionIsFrozen($unit);
        }

        return $this->initialPositionFrozen;
    }

    /**
     * A posição inicial é declarável por quem estrutura a operação, enquanto o
     * Quadro não usou a obra.
     */
    protected function canDeclareBaseline(): bool
    {
        return ! $this->isInitialPositionFrozen()
            && (auth()->user()?->can('constructions.update') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columnManagerTriggerAction(fn (Action $action): Action => $this->getPageClass() === ViewConstructionUnit::class
                ? $action->tooltip('Colunas')
                : $action)
            ->recordTitleAttribute('effective_from')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Vigência')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (ConstructionUnitExchange $record): ?string => $record->ended_on === null
                        ? null
                        : 'até '.$record->ended_on->format('d/m/Y')),

                TextColumn::make('position')
                    ->label('Posição')
                    ->badge()
                    ->state(fn (ConstructionUnitExchange $record): ?string => $record->isEffectiveOn(CarbonImmutable::now())
                        ? 'Vigente'
                        : null)
                    ->color('success')
                    ->placeholder('—'),

                TextColumn::make('exchange_value')
                    ->label('Valor da permuta')
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('bold')
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (ConstructionUnitExchangeKind $state): string => $state->label())
                    ->color(fn (ConstructionUnitExchangeKind $state): string => $state->color()),

                TextColumn::make('contract.code')
                    ->label('Contrato')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('createdBy.name')
                    ->label('Registrada por')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Registrada em')
                    ->dateTime('d/m/Y H:i', BusinessTime::timezone())
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                $this->declareBaselineAction(),
                $this->registerExtraordinaryAction(),
            ])
            ->actions([
                $this->endExchangeAction(),
                $this->substituteExchangeAction(),
                $this->cancelEndedExchangeContractAction(),
                Action::make('viewReason')
                    ->label('Ver motivo')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->modalHeading('Motivo da permuta')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->visible(fn (ConstructionUnitExchange $record): bool => filled($record->reason))
                    ->schema(fn (ConstructionUnitExchange $record): array => [
                        TextEntry::make('registered_by')
                            ->label('Registrada por')
                            ->state($record->createdBy?->name ?? 'Não identificado'),
                        TextEntry::make('registered_at')
                            ->label('Data do registro')
                            ->state($record->created_at === null ? '—' : BusinessTime::at($record->created_at)->format('d/m/Y H:i')),
                        TextEntry::make('effective_from')
                            ->label('Vigência')
                            ->state($record->effective_from?->format('d/m/Y') ?? '—'),
                        TextEntry::make('reason')
                            ->label('Motivo')
                            ->state((string) $record->reason)
                            ->columnSpanFull(),
                        TextEntry::make('ended')
                            ->label('Encerrada')
                            ->state(sprintf(
                                'A partir de %s, por %s em %s.',
                                $record->ended_on?->format('d/m/Y') ?? '—',
                                $record->endedBy?->name ?? 'usuário não identificado',
                                $record->ended_at === null ? '—' : BusinessTime::at($record->ended_at)->format('d/m/Y H:i'),
                            ))
                            ->visible(filled($record->end_reason)),
                        TextEntry::make('end_reason')
                            ->label('Motivo do encerramento')
                            ->state((string) $record->end_reason)
                            ->visible(filled($record->end_reason))
                            ->columnSpanFull(),
                    ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma permuta registrada')
            ->emptyStateDescription(fn (): string => $this->isInitialPositionFrozen()
                ? 'A posição inicial de permuta já foi usada pelo Quadro de Vendas (a obra tem competência apurada ou a Emissão está em operação): uma permuta nova é registrada pela Gestão como permuta extraordinária.'
                : 'Declare aqui a posição inicial de permuta da unidade enquanto a Emissão está em elaboração e a obra ainda não tem competência apurada.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    private function declareBaselineAction(): Action
    {
        return Action::make('declareBaseline')
            ->label('Declarar permuta inicial')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('Declarar permuta inicial da unidade')
            ->modalDescription('Posição inicial da operação. Depois que a obra tiver competência apurada pelo Quadro de Vendas, ou a Emissão sair da elaboração, ela deixa de ser editável por aqui.')
            ->modalSubmitActionLabel('Declarar permuta')
            ->visible(fn (): bool => $this->canDeclareBaseline())
            ->schema([
                self::exchangeValueField(),

                DatePicker::make('effective_from')
                    ->label('Vigência')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate(self::MINIMUM_DATE)
                    ->helperText('Data a partir da qual a unidade passa a contar como permutada.')
                    ->validationMessages([
                        'required' => 'Informe a vigência da permuta.',
                        'after_or_equal' => 'A vigência não pode ser anterior a 01/01/1990: confira o ano.',
                    ]),

                Select::make('contract_id')
                    ->label('Contrato')
                    ->options(fn (): array => Contract::query()
                        ->where('construction_unit_id', $this->getOwnerRecord()->getKey())
                        ->orderByDesc('sale_date')
                        ->pluck('code', 'id')
                        ->all())
                    ->searchable()
                    ->placeholder('Sem contrato vinculado')
                    ->helperText('Opcional: a permuta inicial costuma ser conhecida antes de o contrato existir.'),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(1000)
                    ->placeholder('Permuta acordada na estruturação da operação...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da permuta.',
                        'min' => 'Informe o motivo com pelo menos 10 caracteres.',
                    ]),
            ])
            ->action(function (array $data): void {
                try {
                    $exchange = app(ConstructionUnitExchangeService::class)->declareBaseline(
                        $this->getOwnerRecord(),
                        auth()->user(),
                        $data['exchange_value'],
                        CarbonImmutable::parse($data['effective_from']),
                        filled($data['contract_id'] ?? null) ? (int) $data['contract_id'] : null,
                        (string) $data['reason'],
                    );
                } catch (ConstructionUnitExchangeException|AuthorizationException $exception) {
                    /**
                     * Entre abrir o modal e confirmar a obra pode ter ganhado
                     * competência apurada, ou a Emissão saído da elaboração: o
                     * serviço reconfere sob o lock da unidade.
                     */
                    $this->initialPositionFrozen = null;

                    Notification::make()
                        ->danger()
                        ->title('Permuta não declarada.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Permuta declarada.')
                    ->body(sprintf(
                        'Permuta de R$ %s vigente a partir de %s.',
                        MoneyFormatter::formatCurrencyForDisplay($exchange->exchange_value),
                        $exchange->effective_from->format('d/m/Y'),
                    ))
                    ->send();
            });
    }

    /**
     * A Gestão vê o botão habilitado; quem opera o cadastro vê o botão
     * desabilitado, dizendo de quem é a decisão. O serviço confere de novo.
     */
    private function canSeeExchangeGovernance(): bool
    {
        $user = auth()->user();

        return ($user?->can('constructions.update') ?? false) || SalesBoardApprovalAuthority::holds($user);
    }

    private function managementOnlyTooltip(): ?string
    {
        return SalesBoardApprovalAuthority::holds(auth()->user())
            ? null
            : 'Alterar permuta depois que o Quadro de Vendas usou a obra é decisão da Gestão: exige a permissão de aprovação do Quadro de Vendas.';
    }

    private function registerExtraordinaryAction(): Action
    {
        return Action::make('registerExtraordinary')
            ->label('Registrar permuta extraordinária')
            ->icon('heroicon-o-plus')
            ->color('warning')
            ->modalHeading('Registrar permuta extraordinária')
            ->modalDescription(function (): string {
                $lastPublished = app(ConstructionUnitExchangeService::class)->lastPublishedCompetence($this->getOwnerRecord());

                return 'Permuta depois que o Quadro de Vendas usou a obra. Fica registrada com o seu nome e o motivo, e passa a valer nas competências a partir da vigência.'
                    .($lastPublished === null
                        ? ''
                        : sprintf(' A vigência precisa ser posterior a %s, a última competência publicada.', $lastPublished->endOfMonth()->format('d/m/Y')));
            })
            ->modalSubmitActionLabel('Registrar permuta')
            ->visible(fn (): bool => $this->isInitialPositionFrozen() && $this->canSeeExchangeGovernance())
            ->disabled(fn (): bool => ! SalesBoardApprovalAuthority::holds(auth()->user()))
            ->tooltip(fn (): ?string => $this->managementOnlyTooltip())
            ->schema([
                self::exchangeValueField(),

                DatePicker::make('effective_from')
                    ->label('Vigência')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate(self::MINIMUM_DATE)
                    ->helperText('Data a partir da qual a unidade passa a contar como permutada.')
                    ->validationMessages([
                        'required' => 'Informe a vigência da permuta.',
                        'after_or_equal' => 'A vigência não pode ser anterior a 01/01/1990: confira o ano.',
                    ]),

                Select::make('contract_id')
                    ->label('Contrato')
                    ->options(fn (): array => Contract::query()
                        ->where('construction_unit_id', $this->getOwnerRecord()->getKey())
                        ->orderByDesc('sale_date')
                        ->pluck('code', 'id')
                        ->all())
                    ->searchable()
                    ->placeholder('Sem contrato vinculado'),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da permuta.',
                    ]),
            ])
            ->action(function (array $data): void {
                try {
                    $exchange = app(ConstructionUnitExchangeService::class)->registerExtraordinary(
                        $this->getOwnerRecord(),
                        auth()->user(),
                        $data['exchange_value'],
                        CarbonImmutable::parse($data['effective_from']),
                        filled($data['contract_id'] ?? null) ? (int) $data['contract_id'] : null,
                        (string) $data['reason'],
                    );
                } catch (ConstructionUnitExchangeException|AuthorizationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Permuta não registrada.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Permuta extraordinária registrada.')
                    ->body(sprintf(
                        'Permuta de R$ %s vigente a partir de %s. A competência em andamento percebe a mudança na verificação de fonte.',
                        MoneyFormatter::formatCurrencyForDisplay($exchange->exchange_value),
                        $exchange->effective_from->format('d/m/Y'),
                    ))
                    ->send();
            });
    }

    /**
     * "Encerrar permuta". Se um contrato marcado como permutado continuaria
     * ocupando a unidade, o encerramento o distrata na mesma data: o modal diz
     * qual, pede a confirmação e envia o id do contrato confirmado -- o serviço
     * compara com o alvo que ele mesmo recalcula, e um contrato cadastrado
     * depois de o modal abrir não é distratado sem ninguém ver.
     */
    private function endExchangeAction(): Action
    {
        return Action::make('endExchange')
            ->label('Encerrar permuta')
            ->icon('heroicon-o-stop-circle')
            ->color('danger')
            ->modalHeading('Encerrar a permuta')
            ->modalDescription('A permuta deixa de valer no próprio dia do encerramento e continua registrada. Se um contrato marcado como permutado continuaria ocupando a unidade, ele é distratado na mesma data, com o mesmo motivo -- confirme abaixo. Para corrigir o valor ou a vigência sem distratar nada, use “Substituir permuta”.')
            ->modalSubmitActionLabel('Encerrar permuta')
            ->visible(fn (ConstructionUnitExchange $record): bool => ($record->ended_on === null)
                && $this->isInitialPositionFrozen()
                && $this->canSeeExchangeGovernance())
            ->disabled(fn (): bool => ! SalesBoardApprovalAuthority::holds(auth()->user()))
            ->tooltip(fn (): ?string => $this->managementOnlyTooltip())
            ->schema(fn (ConstructionUnitExchange $record): array => [
                DatePicker::make('ended_on')
                    ->label('Encerrada a partir de')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->live()
                    /**
                     * Outra data pode ter outro contrato de permuta: a
                     * confirmação vale para o contrato que estava à vista.
                     */
                    ->afterStateUpdated(function (Set $set): void {
                        $set('confirm_contract_cancellation', false);
                        $set('confirmed_contract_id', null);
                    })
                    ->minDate(self::MINIMUM_DATE)
                    ->helperText('Primeiro dia em que a unidade deixa de contar como permutada.')
                    ->validationMessages([
                        'required' => 'Informe a data do encerramento.',
                        'after_or_equal' => 'A data do encerramento não pode ser anterior a 01/01/1990: confira o ano.',
                    ]),

                TextEntry::make('exchange_contract_cancellation')
                    ->label('Contrato de permuta')
                    ->state(fn (Get $get): string => $this->exchangeContractCancellationSummary($record, $get('ended_on'))),

                Checkbox::make('confirm_contract_cancellation')
                    ->label(fn (Get $get): string => sprintf(
                        'Confirmo o distrato do contrato de permuta %s nesta data',
                        (string) $this->exchangeContractTarget($record, $get('ended_on'))['contract']?->code,
                    ))
                    ->accepted()
                    ->live()
                    ->visible(fn (Get $get): bool => $this->exchangeContractTarget($record, $get('ended_on'))['contract'] !== null)
                    ->required(fn (Get $get): bool => $this->exchangeContractTarget($record, $get('ended_on'))['contract'] !== null)
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($record): void {
                        $set('confirmed_contract_id', $state
                            ? $this->exchangeContractTarget($record, $get('ended_on'))['contract']?->getKey()
                            : null);
                    })
                    ->validationMessages([
                        'required' => 'Confirme o distrato do contrato de permuta para encerrar a permuta.',
                        'accepted' => 'Confirme o distrato do contrato de permuta para encerrar a permuta.',
                    ]),

                /**
                 * O contrato que quem confirmou viu. O serviço compara com o
                 * alvo que ele recalcula sob lock.
                 */
                Hidden::make('confirmed_contract_id'),

                Textarea::make('end_reason')
                    ->label('Motivo do encerramento')
                    ->required()
                    ->rows(3)
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->validationMessages([
                        'required' => 'Informe o motivo do encerramento.',
                    ]),
            ])
            ->action(function (ConstructionUnitExchange $record, array $data): void {
                $endedOn = CarbonImmutable::parse($data['ended_on'])->startOfDay();
                $confirmedContractId = (($data['confirm_contract_cancellation'] ?? false) && filled($data['confirmed_contract_id'] ?? null))
                    ? (int) $data['confirmed_contract_id']
                    : null;

                try {
                    app(ConstructionUnitExchangeService::class)->end(
                        $record,
                        auth()->user(),
                        $endedOn,
                        (string) $data['end_reason'],
                        $confirmedContractId,
                    );
                } catch (ConstructionUnitExchangeException|AuthorizationException $exception) {
                    $this->exchangeTargets = [];

                    Notification::make()
                        ->danger()
                        ->title('Permuta não encerrada.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $this->exchangeTargets = [];

                Notification::make()
                    ->success()
                    ->title('Permuta encerrada.')
                    ->body($this->endedExchangeSummary($confirmedContractId, $endedOn))
                    ->send();
            });
    }

    /**
     * "Substituir permuta": corrige o valor ou a vigência sem distratar nada.
     *
     * Segue o mesmo predicado das outras ações: antes de o Quadro usar a obra,
     * quem estrutura a operação substitui a posição inicial; depois, é decisão
     * da Gestão -- quem opera o cadastro vê o botão desabilitado, dizendo de
     * quem é a decisão. O serviço confere de novo.
     */
    private function substituteExchangeAction(): Action
    {
        return Action::make('substituteExchange')
            ->label('Substituir permuta')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalHeading('Substituir a permuta')
            ->modalDescription(function (ConstructionUnitExchange $record): string {
                if (ConstructionUnitExchangeService::startsImplausibly($record)) {
                    return $this->implausibleStartCorrectionDescription($record);
                }

                if (! $this->isInitialPositionFrozen()) {
                    return 'Corrige o valor ou a vigência da posição inicial sem distratar nada: a permuta atual é encerrada na data escolhida e a nova, com o mesmo contrato, passa a valer a partir dela. A data igual ao início da atual substitui a permuta desde o começo.';
                }

                $lastPublished = app(ConstructionUnitExchangeService::class)->lastPublishedCompetence($this->getOwnerRecord());

                return 'Corrige o valor ou a vigência sem distratar nada: a permuta atual é encerrada na data escolhida e uma permuta extraordinária, com o mesmo contrato, o seu nome e o motivo, passa a valer a partir dela. A data igual ao início da atual substitui a permuta desde o começo.'
                    .($lastPublished === null
                        ? ''
                        : sprintf(' A nova vigência precisa ser posterior a %s, a última competência publicada.', $lastPublished->endOfMonth()->format('d/m/Y')));
            })
            ->modalSubmitActionLabel('Substituir permuta')
            ->visible(fn (ConstructionUnitExchange $record): bool => ($record->ended_on === null)
                && ($this->isInitialPositionFrozen() ? $this->canSeeExchangeGovernance() : $this->canDeclareBaseline()))
            ->disabled(fn (): bool => $this->isInitialPositionFrozen() && ! SalesBoardApprovalAuthority::holds(auth()->user()))
            ->tooltip(fn (): ?string => $this->isInitialPositionFrozen() ? $this->managementOnlyTooltip() : null)
            ->schema(fn (ConstructionUnitExchange $record): array => [
                TextEntry::make('current_exchange')
                    ->label('Permuta atual')
                    ->state(sprintf(
                        'R$ %s, vigente desde %s%s.',
                        MoneyFormatter::formatCurrencyForDisplay($record->exchange_value),
                        $record->effective_from?->format('d/m/Y') ?? '—',
                        $record->contract === null ? ', sem contrato vinculado' : ', contrato '.$record->contract->code,
                    )),

                self::exchangeValueField('Novo valor da permuta'),

                DatePicker::make('effective_from')
                    ->label('Nova vigência a partir de')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate(self::MINIMUM_DATE)
                    ->helperText(ConstructionUnitExchangeService::startsImplausibly($record)
                        ? 'A data correta do início da permuta: a nova passa a valer neste dia.'
                        : 'A permuta atual deixa de valer neste dia e a nova passa a valer. Igual ao início da atual, a substituição vale desde o começo.')
                    ->validationMessages([
                        'required' => 'Informe a data a partir da qual a nova permuta vale.',
                        'after_or_equal' => 'A nova vigência não pode ser anterior a 01/01/1990: confira o ano.',
                    ]),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->placeholder('Valor da permuta corrigido conforme o aditivo...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da substituição.',
                        'min' => 'Informe o motivo com pelo menos 10 caracteres.',
                    ]),
            ])
            ->action(function (ConstructionUnitExchange $record, array $data): void {
                try {
                    $exchange = app(ConstructionUnitExchangeService::class)->substitute(
                        $record,
                        auth()->user(),
                        CarbonImmutable::parse($data['effective_from']),
                        $data['exchange_value'],
                        (string) $data['reason'],
                    );
                } catch (ConstructionUnitExchangeException|AuthorizationException $exception) {
                    $this->initialPositionFrozen = null;

                    Notification::make()
                        ->danger()
                        ->title('Permuta não substituída.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Permuta substituída.')
                    ->body(sprintf(
                        'Permuta de R$ %s vigente a partir de %s, no lugar da anterior. A competência em andamento percebe a mudança na verificação de fonte.',
                        MoneyFormatter::formatCurrencyForDisplay($exchange->exchange_value),
                        $exchange->effective_from->format('d/m/Y'),
                    ))
                    ->send();
            });
    }

    /**
     * A permuta começa antes de 1990: a substituição corrige a data. O texto
     * diz até onde a atual continua valendo -- nunca, ou só dentro da posição
     * já publicada, que não é reescrita.
     */
    protected function implausibleStartCorrectionDescription(ConstructionUnitExchange $record): string
    {
        $lastPublished = app(ConstructionUnitExchangeService::class)->lastPublishedCompetence($this->getOwnerRecord());

        return sprintf(
            'A vigência atual começa em %s, antes de 01/01/1990 -- quase sempre o ano digitado errado -- e bloqueia a apuração das competências em que vale. '
                .'A substituição corrige a data: %s, e a nova, com o mesmo contrato, passa a valer a partir da data informada.',
            $record->effective_from?->format('d/m/Y') ?? '—',
            $lastPublished === null
                ? 'a permuta atual deixa de valer desde o início'
                : sprintf(
                    'a permuta atual continua valendo só dentro da posição já publicada, até %s, e a nova vigência precisa ser posterior a essa data',
                    $lastPublished->endOfMonth()->format('d/m/Y'),
                ),
        );
    }

    /**
     * "Distratar contrato de permuta": o distrato que o encerramento da permuta
     * não gravou, para a permuta encerrada antes de o encerramento distratar o
     * contrato. O contrato continua ocupando a unidade como permutado, e toda
     * competência seguinte bloqueia. Só aparece quando há esse contrato, para
     * a Gestão -- quem opera o cadastro vê o botão desabilitado --, e o serviço
     * confere de novo o contrato, a data e o ator.
     */
    private function cancelEndedExchangeContractAction(): Action
    {
        return Action::make('cancelEndedExchangeContract')
            ->label('Distratar contrato de permuta')
            ->icon('heroicon-o-document-minus')
            ->color('danger')
            ->modalHeading('Distratar o contrato da permuta encerrada')
            ->modalDescription('A permuta foi encerrada sem distratar o contrato de permuta, que continua ocupando a unidade: as competências depois do encerramento ficam bloqueadas. O contrato é distratado na data do encerramento, com o motivo informado, como o encerramento faz hoje.')
            ->modalSubmitActionLabel('Distratar contrato')
            ->visible(fn (ConstructionUnitExchange $record): bool => ($record->ended_on !== null)
                && $this->isInitialPositionFrozen()
                && $this->canSeeExchangeGovernance()
                && ($this->endedExchangeContract($record) !== null))
            ->disabled(fn (): bool => ! SalesBoardApprovalAuthority::holds(auth()->user()))
            ->tooltip(fn (): ?string => $this->managementOnlyTooltip())
            ->schema(fn (ConstructionUnitExchange $record): array => [
                TextEntry::make('ended_exchange_contract')
                    ->label('Contrato de permuta')
                    ->state(fn (): string => sprintf(
                        'O contrato de permuta %s será distratado em %s, a data do encerramento da permuta.',
                        (string) $this->endedExchangeContract($record)?->code,
                        $record->ended_on?->format('d/m/Y') ?? '—',
                    )),

                Checkbox::make('confirm_contract_cancellation')
                    ->label(fn (): string => sprintf(
                        'Confirmo o distrato do contrato de permuta %s',
                        (string) $this->endedExchangeContract($record)?->code,
                    ))
                    ->accepted()
                    ->required()
                    ->validationMessages([
                        'required' => 'Confirme o distrato do contrato de permuta.',
                        'accepted' => 'Confirme o distrato do contrato de permuta.',
                    ]),

                /**
                 * O contrato que quem confirmou viu. O serviço compara com o
                 * alvo que ele recalcula sob lock.
                 */
                Hidden::make('confirmed_contract_id')
                    ->default(fn (): ?int => $this->endedExchangeContract($record)?->getKey()),

                Textarea::make('reason')
                    ->label('Motivo do distrato')
                    ->required()
                    ->rows(3)
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->placeholder('Permuta desfeita em aditivo; o contrato não foi distratado no encerramento...')
                    ->validationMessages([
                        'required' => 'Informe o motivo do distrato.',
                        'min' => 'Informe o motivo com pelo menos 10 caracteres.',
                    ]),
            ])
            ->action(function (ConstructionUnitExchange $record, array $data): void {
                $confirmedContractId = (($data['confirm_contract_cancellation'] ?? false) && filled($data['confirmed_contract_id'] ?? null))
                    ? (int) $data['confirmed_contract_id']
                    : null;

                try {
                    $contract = app(ConstructionUnitExchangeService::class)->cancelEndedExchangeContract(
                        $record,
                        auth()->user(),
                        (string) $data['reason'],
                        $confirmedContractId,
                    );
                } catch (ConstructionUnitExchangeException|AuthorizationException $exception) {
                    $this->endedExchangeContracts = [];

                    Notification::make()
                        ->danger()
                        ->title('Contrato não distratado.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $this->endedExchangeContracts = [];

                Notification::make()
                    ->success()
                    ->title('Contrato de permuta distratado.')
                    ->body(sprintf(
                        'O contrato de permuta %s foi distratado em %s. A competência em andamento percebe a mudança na verificação de fonte.',
                        (string) $contract->code,
                        $contract->cancellation_date?->format('d/m/Y') ?? '—',
                    ))
                    ->send();
            });
    }

    /**
     * O contrato que "Distratar contrato de permuta" distrataria, lido uma vez
     * por requisição para cada permuta: a visibilidade, o texto, a confirmação
     * e o campo oculto perguntam.
     */
    protected function endedExchangeContract(ConstructionUnitExchange $exchange): ?Contract
    {
        $key = (int) $exchange->getKey();

        if (! array_key_exists($key, $this->endedExchangeContracts)) {
            $this->endedExchangeContracts[$key] = app(ConstructionUnitExchangeService::class)->endedExchangeContract($exchange);
        }

        return $this->endedExchangeContracts[$key];
    }

    /**
     * O campo de valor das três ações que registram permuta. Zero não é valor:
     * é o marcador de "sem valor", e permuta sem valor não compõe o Quadro.
     */
    private static function exchangeValueField(string $label = 'Valor da permuta'): TextInput
    {
        return TextInput::make('exchange_value')
            ->label($label)
            ->required()
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->dehydrateStateUsing(fn (mixed $state): ?string => self::normalizeValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?string => self::normalizeValue($state))
            ->rule('numeric')
            ->minValue(0.01)
            ->placeholder('450.000,00')
            ->helperText('Valor próprio da permuta. Não é o valor de venda do contrato.')
            ->extraInputAttributes(['class' => 'text-right font-mono tabular-nums'])
            ->validationMessages([
                'required' => 'Informe o valor da permuta.',
                'min' => 'O valor da permuta precisa ser maior que zero.',
            ]);
    }

    /**
     * O contrato que o encerramento na data distrataria, lido uma vez por
     * requisição para cada data: o texto, a confirmação e o campo oculto
     * perguntam.
     *
     * @return array{contract: Contract|null, error: string|null}
     */
    protected function exchangeContractTarget(ConstructionUnitExchange $exchange, mixed $endedOn): array
    {
        if (blank($endedOn)) {
            return ['contract' => null, 'error' => null];
        }

        $date = CarbonImmutable::parse((string) $endedOn)->startOfDay();
        $key = $exchange->getKey().'@'.$date->toDateString();

        if (! array_key_exists($key, $this->exchangeTargets)) {
            try {
                $this->exchangeTargets[$key] = [
                    'contract' => app(ConstructionUnitExchangeService::class)->exchangeContractFor($exchange, $date),
                    'error' => null,
                ];
            } catch (ConstructionUnitExchangeException $exception) {
                $this->exchangeTargets[$key] = ['contract' => null, 'error' => $exception->getMessage()];
            }
        }

        return $this->exchangeTargets[$key];
    }

    protected function exchangeContractCancellationSummary(ConstructionUnitExchange $exchange, mixed $endedOn): string
    {
        if (blank($endedOn)) {
            return 'Informe a data do encerramento para ver se algum contrato de permuta será distratado.';
        }

        $target = $this->exchangeContractTarget($exchange, $endedOn);

        if ($target['error'] !== null) {
            return $target['error'];
        }

        return $target['contract'] === null
            ? 'Nenhum contrato de permuta será distratado.'
            : sprintf(
                'O contrato de permuta %s será distratado em %s, junto com o encerramento e com o mesmo motivo.',
                (string) $target['contract']->code,
                CarbonImmutable::parse((string) $endedOn)->format('d/m/Y'),
            );
    }

    /**
     * O que aconteceu além do encerramento: o contrato distratado, e o aviso de
     * que a unidade volta ao estoque sem valor de referência na data -- a
     * competência seguinte ficaria incompleta sem ele.
     */
    protected function endedExchangeSummary(?int $confirmedContractId, CarbonImmutable $endedOn): string
    {
        /** @var ConstructionUnit $unit */
        $unit = $this->getOwnerRecord();
        $parts = [];

        $cancelled = $confirmedContractId === null ? null : Contract::query()->find($confirmedContractId);

        if (($cancelled !== null) && ($cancelled->cancellation_date?->toDateString() === $endedOn->toDateString())) {
            $parts[] = sprintf('O contrato de permuta %s foi distratado em %s.', (string) $cancelled->code, $endedOn->format('d/m/Y'));
        }

        $occupied = Contract::query()
            ->where('construction_unit_id', $unit->getKey())
            ->occupyingOn($endedOn)
            ->exists();

        if (! $occupied && app(UnitValueResolver::class)->forUnit($unit, $endedOn)->isAbsent()) {
            $parts[] = sprintf(
                'A unidade volta ao estoque sem valor de referência vigente em %s: registre o valor da unidade na aba Histórico de Valores, senão a competência fica incompleta.',
                $endedOn->format('d/m/Y'),
            );
        }

        return $parts === [] ? 'A permuta deixa de valer a partir de '.$endedOn->format('d/m/Y').'.' : implode(' ', $parts);
    }

    private static function normalizeValue(mixed $state): ?string
    {
        $cents = IntegerMoney::cents($state);

        return $cents === null ? null : IntegerMoney::decimalString($cents);
    }
}
