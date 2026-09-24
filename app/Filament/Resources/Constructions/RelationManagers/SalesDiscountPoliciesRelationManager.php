<?php

namespace App\Filament\Resources\Constructions\RelationManagers;

use App\DTOs\SalesBoards\SalesDiscountPolicyPeriodAssessment;
use App\Enums\SalesDiscountPolicyPosition;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use App\Services\SalesBoards\SalesDiscountPolicyResolver;
use App\Support\BusinessTime;
use App\Support\SalesBoards\SalesDiscountPolicyTimeline;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Política comercial de desconto do empreendimento.
 *
 * Vive aqui, dentro da obra, e não numa página global: é a obra que tem tabela
 * de preço e limite de negociação, e uma tela desconectada convidaria a tratar
 * o desconto como parâmetro do sistema em vez de decisão comercial datada.
 *
 * Append-only, como o histórico de valores: sem editar, sem excluir, sem ação em
 * massa. Cada política tem início e fim; mudar o limite é registrar outra, que
 * substitui a anterior a partir do próprio início -- com confirmação explícita
 * quando os períodos se cruzam. A anterior continua respondendo pelas vendas
 * feitas enquanto ela valia.
 */
class SalesDiscountPoliciesRelationManager extends RelationManager
{
    protected static string $relationship = 'salesDiscountPolicies';

    protected static ?string $title = 'Política Comercial de Desconto';

    protected static ?string $modelLabel = 'Política de desconto';

    protected static ?string $pluralModelLabel = 'Políticas de desconto';

    /**
     * Avaliações de período já calculadas nesta requisição, por `início|fim`.
     *
     * Protegida de propósito: o Livewire não a serializa, então nada sobrevive
     * de uma requisição para outra e a tela nunca mostra uma avaliação velha.
     *
     * @var array<string, SalesDiscountPolicyPeriodAssessment>
     */
    protected array $periodAssessments = [];

    /**
     * Linha do tempo das políticas e a vigente de hoje, calculadas uma vez por
     * requisição e descartadas quando uma política é registrada: a tabela é
     * redesenhada na mesma requisição do registro e não pode mostrar a posição
     * de antes dele.
     *
     * @var array{today: CarbonImmutable, currentPolicyId: int|null, timeline: SalesDiscountPolicyTimeline}|null
     */
    protected ?array $positionContext = null;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Data de negócio de hoje, e não a data UTC: entre 21h e meia-noite em
     * Brasília o dia UTC já é o seguinte.
     */
    protected static function businessToday(): CarbonImmutable
    {
        return CarbonImmutable::parse(BusinessTime::dateString());
    }

    /**
     * Id da política que vale hoje, pela mesma regra que decide as vendas. Uma
     * política com vigência futura já está registrada, mas ainda não é a
     * vigente; uma que já passou do fim deixou de ser.
     */
    protected function currentPolicyId(CarbonImmutable $today): ?int
    {
        /** @var Construction $construction */
        $construction = $this->getOwnerRecord();

        $policy = app(SalesDiscountPolicyResolver::class)->policyAt($construction, $today)->policy;

        return $policy === null ? null : (int) $policy->getKey();
    }

    /**
     * @return array{today: CarbonImmutable, currentPolicyId: int|null, timeline: SalesDiscountPolicyTimeline}
     */
    protected function positionContext(): array
    {
        if ($this->positionContext === null) {
            $today = self::businessToday();

            $this->positionContext = [
                'today' => $today,
                'currentPolicyId' => $this->currentPolicyId($today),
                'timeline' => app(SalesDiscountPolicyRegistrar::class)->timeline((int) $this->getOwnerRecord()->getKey()),
            ];
        }

        return $this->positionContext;
    }

    protected function positionOf(SalesDiscountPolicy $policy): SalesDiscountPolicyPosition
    {
        ['today' => $today, 'currentPolicyId' => $currentPolicyId, 'timeline' => $timeline] = $this->positionContext();

        return $timeline->positionOf($policy, $today, $currentPolicyId);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('effective_from')
            ->description('Regras comerciais e limites aplicáveis aos descontos da obra.')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Vigência')
                    ->formatStateUsing(fn (SalesDiscountPolicy $record): string => self::describePeriod($record))
                    ->sortable()
                    ->extraHeaderAttributes(['class' => 'bsi-col-effective-from'])
                    ->extraCellAttributes(['class' => 'bsi-col-effective-from tabular-nums'])
                    ->description(fn (SalesDiscountPolicy $record): string => self::describeDuration($record)),

                TextColumn::make('position')
                    ->label('Posição')
                    ->badge()
                    ->state(fn (SalesDiscountPolicy $record): SalesDiscountPolicyPosition => $this->positionOf($record))
                    ->formatStateUsing(fn (SalesDiscountPolicyPosition $state): string => $state->label())
                    ->color(fn (SalesDiscountPolicyPosition $state): string => $state->color())
                    ->description(fn (SalesDiscountPolicy $record): ?string => $this->describePositionDetail($record))
                    ->placeholder('—')
                    ->extraHeaderAttributes(['class' => 'bsi-col-position'])
                    ->extraCellAttributes(['class' => 'bsi-col-position']),

                TextColumn::make('maximum_discount_percent')
                    ->label('Desconto máximo')
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2, ',', '.').'%')
                    ->alignEnd()
                    ->weight('bold')
                    ->extraHeaderAttributes(['class' => 'bsi-col-maximum-discount'])
                    ->extraCellAttributes(['class' => 'bsi-col-maximum-discount font-mono tabular-nums'])
                    ->sortable(),

                TextColumn::make('createdBy.name')
                    ->label('Registrada por')
                    ->placeholder('—')
                    ->toggleable()
                    ->extraHeaderAttributes(['class' => 'bsi-col-registered-by'])
                    ->extraCellAttributes(['class' => 'bsi-col-registered-by']),

                TextColumn::make('created_at')
                    ->label('Registrada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->extraHeaderAttributes(['class' => 'bsi-col-registered-at'])
                    ->extraCellAttributes(['class' => 'bsi-col-registered-at tabular-nums'])
                    ->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                $this->newPolicyAction(),
            ])
            ->actions([
                Action::make('viewReason')
                    ->label('Ver motivo')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->extraAttributes(['class' => 'bsi-btn-view-reason'])
                    ->modalHeading('Motivo da política')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->visible(fn (SalesDiscountPolicy $record): bool => filled($record->reason))
                    ->schema(fn (SalesDiscountPolicy $record): array => [
                        TextEntry::make('registered_by')
                            ->label('Registrada por')
                            ->state($record->createdBy?->name ?? 'Não identificado'),
                        TextEntry::make('registered_at')
                            ->label('Data do registro')
                            ->state($record->created_at?->format('d/m/Y H:i') ?? '—'),
                        TextEntry::make('effective_from')
                            ->label('Vigência')
                            ->state(self::describePeriod($record).' ('.self::describeDuration($record).')'),
                        TextEntry::make('reason')
                            ->label('Motivo')
                            ->state((string) $record->reason)
                            ->columnSpanFull(),
                    ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma política registrada')
            ->emptyStateDescription('Sem política vigente, uma venda deste empreendimento não pode ser classificada como conforme nem como não conforme.')
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->emptyStateActions([
                $this->newPolicyAction(),
            ]);
    }

    private function newPolicyAction(): Action
    {
        return Action::make('newPolicy')
            ->label('Nova política')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('Nova política de desconto')
            ->modalDescription('A política anterior permanece no histórico e continua respondendo pelas vendas feitas enquanto valia.')
            ->modalSubmitActionLabel('Registrar política')
            ->visible(fn (): bool => auth()->user()?->can('emissions.update') ?? false)
            ->schema([
                TextInput::make('maximum_discount_percent')
                    ->label('Desconto máximo autorizado')
                    ->required()
                    ->numeric()
                    ->suffix('%')
                    ->minValue(SalesDiscountPolicy::MINIMUM_DISCOUNT_PERCENT)
                    ->maxValue(SalesDiscountPolicy::MAXIMUM_DISCOUNT_PERCENT)
                    ->step('0.01')
                    ->placeholder('5,00')
                    ->helperText('Limite máximo. Uma venda pode ter desconto menor, nunca maior.')
                    ->extraInputAttributes(['class' => 'text-right font-mono tabular-nums'])
                    ->validationMessages([
                        'required' => 'Informe o desconto máximo autorizado.',
                        'min' => 'O desconto não pode ser negativo.',
                        'max' => 'O desconto não pode ultrapassar 100%.',
                    ]),

                Section::make('Período de vigência')
                    ->description('Período em que esta política poderá ser aplicada às vendas. Datas futuras são permitidas e não retroagem.')
                    ->compact()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->extraAttributes(['class' => 'bsi-sales-discount-period-section'])
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label('Início')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            /**
                             * Instância à meia-noite, não texto: o seletor guarda
                             * `Y-m-d H:i:s`, e um texto `Y-m-d` ganharia a hora
                             * atual na conversão -- o início "de hoje" ficaria
                             * depois de um fim escolhido para hoje.
                             */
                            ->default(fn (): CarbonImmutable => self::businessToday())
                            ->live()
                            ->afterStateUpdated(function (DatePicker $component, Get $get, Set $set): void {
                                self::resetSubstitutionConfirmation($set);

                                if (filled($get('effective_until'))) {
                                    $this->validateLive($component, $component->resolveRelativeStatePath('effective_until'));
                                }
                            })
                            ->validationMessages([
                                'required' => 'Informe o início da vigência.',
                            ]),

                        DatePicker::make('effective_until')
                            ->label('Fim')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->minDate(fn (Get $get): ?string => self::parseFormDate($get('effective_from'))?->toDateString())
                            /**
                             * O `minDate()` já recusa fim anterior ao início,
                             * comparando com a data do início sem hora. Esta
                             * regra acrescenta o bloqueio por política que começa
                             * dentro do período e repete a comparação por dia,
                             * para não depender do seletor.
                             */
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if (self::isInvertedPeriod($get('effective_from'), $value)) {
                                    $fail('O fim precisa ser igual ou posterior ao início.');

                                    return;
                                }

                                $blockingMessage = $this->periodAssessment($get)?->blockingMessage();

                                if ($blockingMessage !== null) {
                                    $fail($blockingMessage);
                                }
                            })
                            ->live()
                            ->afterStateUpdated(function (DatePicker $component, Set $set): void {
                                self::resetSubstitutionConfirmation($set);

                                $this->validateLive($component, $component->getStatePath());
                            })
                            ->validationMessages([
                                'required' => 'Informe o fim da vigência.',
                                'after_or_equal' => 'O fim precisa ser igual ou posterior ao início.',
                            ]),

                        TextEntry::make('duration')
                            ->label('Duração')
                            ->state(fn (Get $get): string => self::previewDuration($get('effective_from'), $get('effective_until')))
                            ->belowContent(fn (Get $get): array => [
                                Text::make(self::previewRange($get('effective_from'), $get('effective_until')))
                                    ->color('gray')
                                    ->extraAttributes(['class' => 'bsi-duration-range tabular-nums text-xs']),
                            ])
                            ->weight('semibold')
                            ->columnSpanFull(),
                    ]),

                Callout::make('Esta política substitui outra')
                    ->warning()
                    ->description(fn (Get $get): ?string => $this->periodAssessment($get)?->substitutionMessage())
                    ->visible(fn (Get $get): bool => $this->periodAssessment($get)?->substitutes() ?? false),

                Checkbox::make('confirm_substitution')
                    ->label('Confirmo a substituição')
                    ->accepted()
                    ->live()
                    ->visible(fn (Get $get): bool => $this->periodAssessment($get)?->substitutes() ?? false)
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        $set('confirmed_substitution_id', $state ? $this->periodAssessment($get)?->substitutedPolicyId() : null);
                    })
                    ->validationMessages([
                        'accepted' => 'Confirme a substituição para registrar a política.',
                    ]),

                /**
                 * Qual política o usuário viu e aceitou substituir. O servidor
                 * compara com a que encontra na hora de gravar; se outra
                 * política foi registrada no meio, a confirmação não vale.
                 */
                Hidden::make('confirmed_substitution_id'),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('Aprovação comercial, revisão de margem, campanha de vendas...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da política.',
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                /** @var Construction $construction */
                $construction = $this->getOwnerRecord();
                $user = auth()->user();

                try {
                    $policy = app(SalesDiscountPolicyRegistrar::class)->register(
                        construction: $construction,
                        attributes: [
                            'maximum_discount_percent' => $data['maximum_discount_percent'],
                            'effective_from' => (string) $data['effective_from'],
                            'effective_until' => (string) $data['effective_until'],
                            'reason' => (string) $data['reason'],
                        ],
                        confirmedSubstitutionId: filled($data['confirmed_substitution_id'] ?? null)
                            ? (int) $data['confirmed_substitution_id']
                            : null,
                        registeredBy: $user instanceof User ? $user : null,
                    );
                } catch (SalesDiscountPolicyPeriodException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Não foi possível registrar a política.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    $action->halt();

                    return;
                }

                $this->periodAssessments = [];
                $this->positionContext = null;

                Notification::make()
                    ->success()
                    ->title('Política registrada.')
                    ->body(sprintf(
                        'Desconto máximo de %s de %s (%s).',
                        $policy->formatted_maximum_discount_percent,
                        self::describePeriod($policy),
                        self::describeDuration($policy),
                    ))
                    ->send();
            });
    }

    /**
     * Avaliação do período digitado no formulário, ou nulo enquanto ele estiver
     * incompleto ou invertido -- aí não há substituição nem bloqueio a mostrar,
     * só a validação do próprio campo.
     */
    protected function periodAssessment(Get $get): ?SalesDiscountPolicyPeriodAssessment
    {
        $from = self::parseFormDate($get('effective_from'));
        $until = self::parseFormDate($get('effective_until'));

        if (($from === null) || ($until === null) || $until->lessThan($from)) {
            return null;
        }

        $key = $from->toDateString().'|'.$until->toDateString();

        return $this->periodAssessments[$key] ??= app(SalesDiscountPolicyRegistrar::class)->assess(
            (int) $this->getOwnerRecord()->getKey(),
            $from,
            $until,
        );
    }

    /**
     * Validação em tempo real de um campo do período.
     *
     * As mensagens vêm do schema: o `validateOnly()` do Livewire não as conhece
     * e mostraria o texto padrão do Laravel. A exceção não sobe -- ela
     * interromperia a requisição e descartaria as outras alterações do
     * formulário enviadas junto com esta; os erros vão direto para o componente,
     * como o Livewire faria.
     */
    protected function validateLive(DatePicker $component, string $statePath): void
    {
        $schema = $component->getRootContainer();

        try {
            $this->validateOnly($statePath, null, $schema->getValidationMessages(), $schema->getValidationAttributes());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
        }
    }

    /**
     * A confirmação vale para a substituição que estava na tela. Mudou o
     * período, mudou a substituição: a confirmação precisa ser dada de novo.
     */
    protected static function resetSubstitutionConfirmation(Set $set): void
    {
        $set('confirm_substitution', false);
        $set('confirmed_substitution_id', null);
    }

    protected static function previewDuration(mixed $from, mixed $until): string
    {
        $fromDate = self::parseFormDate($from);
        $untilDate = self::parseFormDate($until);

        if (($fromDate === null) || ($untilDate === null)) {
            return '—';
        }

        $days = SalesDiscountPolicy::inclusiveDayCount($fromDate, $untilDate);

        return $days === null ? '—' : SalesDiscountPolicy::formatDayCount($days);
    }

    protected static function isInvertedPeriod(mixed $from, mixed $until): bool
    {
        $fromDate = self::parseFormDate($from);
        $untilDate = self::parseFormDate($until);

        return ($fromDate !== null) && ($untilDate !== null) && $untilDate->lessThan($fromDate);
    }

    protected static function previewRange(mixed $from, mixed $until): string
    {
        $fromDate = self::parseFormDate($from);
        $untilDate = self::parseFormDate($until);

        if (($fromDate === null) || ($untilDate === null)) {
            return 'Calculada automaticamente após informar início e fim.';
        }

        if ($untilDate->lessThan($fromDate)) {
            return 'Ajuste o período para calcular a duração.';
        }

        return sprintf('%s até %s', $fromDate->format('d/m/Y'), $untilDate->format('d/m/Y'));
    }

    protected static function describePeriod(SalesDiscountPolicy $policy): string
    {
        $start = $policy->effective_from?->format('d/m/Y') ?? '—';

        if ($policy->effective_until === null) {
            return 'Desde '.$start;
        }

        return $start.' a '.$policy->effective_until->format('d/m/Y');
    }

    /**
     * Duração registrada. Linhas anteriores ao fim explícito não têm uma: valem
     * até a próxima política começar.
     */
    protected static function describeDuration(SalesDiscountPolicy $policy): string
    {
        $days = $policy->durationInDays();

        return $days === null ? 'Sem data de fim' : SalesDiscountPolicy::formatDayCount($days);
    }

    /**
     * Quando uma substituição encurta a política, o período registrado deixa de
     * ser o período em que ela vale. A posição diz a data real.
     */
    protected function describePositionDetail(SalesDiscountPolicy $policy): ?string
    {
        $supersededOn = $this->positionContext()['timeline']->supersededOn($policy);

        if ($supersededOn === null) {
            return null;
        }

        return match ($this->positionOf($policy)) {
            SalesDiscountPolicyPosition::Superseded => 'em '.$supersededOn->format('d/m/Y'),
            SalesDiscountPolicyPosition::Current, SalesDiscountPolicyPosition::Scheduled => 'até '.$supersededOn->subDay()->format('d/m/Y'),
            default => null,
        };
    }

    private static function parseFormDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value) || (! is_string($value))) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
