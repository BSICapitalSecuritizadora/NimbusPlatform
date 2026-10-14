<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventDateChangeReason;
use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuBaselineCandidateFactory;
use App\Domain\PuCalculator\Services\PuContractualEventScheduleService;
use App\Domain\PuCalculator\Services\PuContractualEventService;
use App\Domain\PuCalculator\Services\PuFinancialEffectSupport;
use App\Filament\Resources\Emissions\Schemas\PuContractualEventFields;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class PuEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'puEvents';

    protected static ?string $title = 'Eventos de PU';

    protected static ?string $modelLabel = 'Evento de PU';

    protected static ?string $pluralModelLabel = 'Eventos de PU';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('event_type')
                    ->label('Tipo de evento')
                    ->options(PuContractualEventFields::typeOptions())
                    ->helperText('Mora, carência, diferimento, prêmio, multa, juros extraordinários, liquidação antecipada e troca de indexador não têm regra de cálculo e não são oferecidos: cadastrá-los bloquearia a curva. Veja "Tipos de evento e suporte".')
                    ->live()
                    ->required(),
                DatePicker::make('original_date')
                    ->label('Data original')
                    ->helperText('Data do cronograma do Termo.')
                    ->visible(fn (Get $get): bool => self::isScheduledPayment($get))
                    ->live(),
                DatePicker::make('effective_date')
                    ->label(fn (Get $get): string => match ($get('event_type')) {
                        PuEventType::ExtraordinaryAmortization->value => 'Data efetiva (redução do principal)',
                        PuEventType::EarlyMaturity->value => 'Data efetiva do vencimento antecipado',
                        PuEventType::Waiver->value => 'Vigente a partir de',
                        default => 'Data efetiva',
                    })
                    ->helperText(fn (Get $get): ?string => self::isScheduledPayment($get) ? 'Data em que o pagamento acontece e até onde os juros correm.' : null)
                    ->visible(fn (Get $get): bool => $get('event_type') !== PuEventType::SpreadAmendment->value)
                    ->live()
                    ->required(fn (Get $get): bool => $get('event_type') !== PuEventType::SpreadAmendment->value),
                Select::make('effective_date_reason')
                    ->label('Motivo da mudança de data')
                    ->options(PuEventDateChangeReason::options())
                    ->visible(fn (Get $get): bool => self::datesDiffer($get))
                    ->required(fn (Get $get): bool => self::datesDiffer($get)),
                Textarea::make('effective_date_justification')
                    ->label('Justificativa')
                    ->placeholder('Ex.: 08/02/2027 é Carnaval, sem expediente no mercado; pagamento no Dia Útil seguinte.')
                    ->rows(2)
                    ->visible(fn (Get $get): bool => self::datesDiffer($get))
                    ->required(fn (Get $get): bool => self::datesDiffer($get)),
                TextInput::make('effective_date_evidence_reference')
                    ->label('Documento e cláusula')
                    ->placeholder('Ex.: Termo de Securitização, cláusula 4.10, p. 31')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::datesDiffer($get)),
                Textarea::make('effective_date_evidence_excerpt')
                    ->label('Trecho do documento')
                    ->rows(3)
                    ->visible(fn (Get $get): bool => self::datesDiffer($get)),
                Select::make('amortization_type')
                    ->label('Tipo de amortização')
                    ->options([
                        PuAmortizationType::None->value => 'Nenhuma',
                        PuAmortizationType::Residual->value => 'Residual',
                        PuAmortizationType::Percentage->value => 'Percentual',
                        PuAmortizationType::UnitValue->value => 'Valor unitário',
                    ])
                    ->default(PuAmortizationType::None->value)
                    ->visible(fn (Get $get): bool => self::isScheduledPayment($get))
                    ->required(fn (Get $get): bool => self::isScheduledPayment($get)),
                TextInput::make('amortization_value')
                    ->label('Valor da amortização')
                    ->helperText('Percentual: fração da base, como a engine aplica (0,10 = 10%). Valor unitário: R$ por título.')
                    ->inputMode('decimal')
                    ->visible(fn (Get $get): bool => $get('event_type') === PuEventType::Amortization->value)
                    ->required(fn (Get $get): bool => $get('event_type') === PuEventType::Amortization->value),
                ...PuContractualEventFields::components(fn (): Emission => $this->getOwnerRecord()),
                TextInput::make('sequence')
                    ->label('Sequência')
                    ->numeric()
                    ->default(1)
                    ->required(),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('effective_date')
            ->searchPlaceholder('Buscar por data ou descrição...')
            ->columns([
                TextColumn::make('effective_date')
                    ->label('Data Efetiva')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('event_type')
                    ->label('Evento')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PuEventType::InterestPayment->value => 'Pagamento de Juros',
                        PuEventType::Amortization->value => 'Amortização',
                        default => PuEventType::tryFrom($state)?->label() ?? $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        PuEventType::InterestPayment->value => 'info',
                        PuEventType::Amortization->value => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (EmissionPuEvent $record): ?string => PuEventType::tryFrom((string) $record->event_type)?->isScheduledPayment() ?? false
                        ? null
                        : PuContractualEventFields::effectSummary($record)),
                TextColumn::make('financial_support')
                    ->label('Efeito no cálculo')
                    ->badge()
                    ->state(fn (EmissionPuEvent $record): string => $this->supportLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Calculado' => 'success',
                        'Sem efeito no PU' => 'gray',
                        'Cancelado' => 'gray',
                        default => 'danger',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Calculado' => 'heroicon-m-check-badge',
                        'Sem efeito no PU', 'Cancelado' => 'heroicon-m-minus-circle',
                        default => 'heroicon-m-no-symbol',
                    })
                    ->tooltip(fn (EmissionPuEvent $record): ?string => $this->supportIssues($record) !== [] ? implode(' ', $this->supportIssues($record)) : null),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (?PuEventStatus $state): string => ($state ?? PuEventStatus::Active)->label())
                    ->color(fn (?PuEventStatus $state): string => $state === PuEventStatus::Cancelled ? 'gray' : 'success')
                    ->tooltip(fn (EmissionPuEvent $record): ?string => $record->isActive()
                        ? null
                        : sprintf('Cancelado em %s: %s', $record->cancelled_at?->format('d/m/Y H:i') ?? '—', $record->cancellation_reason)),
                TextColumn::make('effective_date_reason')
                    ->label('Data mudou por')
                    ->badge()
                    ->state(fn (EmissionPuEvent $record): ?string => match (true) {
                        $record->effective_date_reason !== null => $record->effective_date_reason->label(),
                        $record->hasUnjustifiedDateChange() => 'Sem motivo registrado',
                        default => null,
                    })
                    ->color(fn (EmissionPuEvent $record): string => $record->hasUnjustifiedDateChange() ? 'danger' : 'gray')
                    ->tooltip(fn (EmissionPuEvent $record): ?string => $record->effective_date_justification
                        ?? ($record->hasUnjustifiedDateChange()
                            ? sprintf('Data original %s. Edite o evento para registrar o motivo.', $record->original_date->format('d/m/Y'))
                            : null))
                    ->placeholder('—'),
                TextColumn::make('amortization_type')
                    ->label('Tipo de Amortização')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PuAmortizationType::Residual->value => 'Residual',
                        PuAmortizationType::Percentage->value => 'Percentual',
                        PuAmortizationType::UnitValue->value => 'Valor Unitário',
                        PuAmortizationType::None->value, null, '' => '—',
                        default => $state,
                    }),
                TextColumn::make('amortization_value')
                    ->label('Valor')
                    ->state(fn (EmissionPuEvent $record): ?string => in_array(PuAmortizationType::tryFrom((string) $record->amortization_type), [PuAmortizationType::Percentage, PuAmortizationType::UnitValue, PuAmortizationType::Residual], true)
                        ? PuContractualEventFields::amortizationLabel($record)
                        : null)
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('sequence')
                    ->label('Seq.')
                    ->tooltip('Sequência do evento na data')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable(),
            ])
            ->defaultSort('effective_date')
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Tipo de Evento')
                    ->options(collect(PuEventType::cases())->mapWithKeys(fn (PuEventType $type): array => [$type->value => $type->label()])->all()),
            ])
            ->headerActions([
                Action::make('generateContractualSchedule')
                    ->label('Gerar eventos do cronograma contratual')
                    ->icon('heroicon-m-calendar-days')
                    ->color('gray')
                    ->visible(fn (): bool => ! $this->isReadOnly()
                        && (auth()->user()?->can('pu.parameters.configure') ?? false)
                        && app(PuBaselineCandidateFactory::class)->supports($this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-calendar-days')
                    ->modalHeading('Gerar eventos do cronograma contratual')
                    ->modalDescription(fn (): string => $this->contractualScheduleSummary())
                    ->modalSubmitActionLabel('Gerar eventos')
                    ->action(fn (): null => $this->generateContractualSchedule()),
                CreateAction::make()
                    ->label('Novo Evento PU')
                    ->icon('heroicon-m-plus')
                    ->tooltip('Cadastrar evento de PU manualmente')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->visible(fn (): bool => auth()->user()?->can('pu.parameters.configure') ?? false)
                    ->mutateDataUsing(fn (array $data, CreateAction $action): array => $this->guardedPayload($data, $action))
                    ->after(fn (): null => $this->logEventChange('created')),
                Action::make('effectSupportMatrix')
                    ->label('Tipos de evento e suporte')
                    ->icon('heroicon-m-information-circle')
                    ->color('gray')
                    ->modalHeading('Tipos de evento contratual e suporte financeiro')
                    ->modalDescription('O que a engine calcula, o que é só informado e o que bloqueia a curva por não ter regra. Nada aqui é configurável pela tela.')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (): Htmlable => view('filament.emissions.pu-effect-support-matrix', [
                        'rows' => app(PuFinancialEffectSupport::class)->matrix(),
                    ])),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        // O formulário cobre os pagamentos do cronograma; os demais tipos do
                        // catálogo não são editados por aqui (cancelar e cadastrar de novo).
                        ->visible(fn (EmissionPuEvent $record): bool => $record->isActive()
                            && (PuEventType::tryFrom((string) $record->event_type)?->isScheduledPayment() ?? false)
                            && (auth()->user()?->can('pu.parameters.configure') ?? false))
                        ->mutateDataUsing(fn (array $data): array => PuContractualEventFields::payload($data))
                        ->after(fn (): null => $this->logEventChange('updated')),
                    Action::make('cancelEvent')
                        ->label('Cancelar evento')
                        ->icon('heroicon-m-no-symbol')
                        ->color('danger')
                        ->visible(fn (EmissionPuEvent $record): bool => ! $this->isReadOnly()
                            && $record->isActive()
                            && (auth()->user()?->can('pu.parameters.configure') ?? false))
                        ->requiresConfirmation()
                        ->modalHeading('Cancelar evento de PU')
                        ->modalDescription('O evento sai do cálculo das próximas versões da curva e fica registrado como cancelado, com o motivo. A curva oficial não muda: se o evento já valia para ela, uma nova versão precisa ser gerada e homologada.')
                        ->form([
                            Textarea::make('cancellation_reason')
                                ->label('Motivo do cancelamento')
                                ->required()
                                ->rows(3)
                                ->maxLength(2000),
                        ])
                        ->action(function (EmissionPuEvent $record, array $data): void {
                            /** @var User $actor */
                            $actor = auth()->user();
                            app(PuContractualEventService::class)->cancel($record, $actor, (string) ($data['cancellation_reason'] ?? ''));

                            Notification::make()->title('Evento cancelado.')->success()->send();
                        }),
                    DeleteAction::make()
                        ->visible(fn (EmissionPuEvent $record): bool => ! $record->isGoverned()
                            && (auth()->user()?->can('pu.parameters.configure') ?? false))
                        ->after(fn (): null => $this->logEventChange('deleted')),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(fn (): null => $this->logEventChange('deleted')),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('Nenhum evento de PU cadastrado')
            ->emptyStateDescription('Registre eventos de juros, amortizações ou outras movimentações financeiras para compor o histórico e a curva de PU desta emissão.')
            ->emptyStateActions([
                CreateAction::make('empty_create')
                    ->label('Cadastrar evento')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->visible(fn (): bool => auth()->user()?->can('pu.parameters.configure') ?? false)
                    ->after(fn (): null => $this->logEventChange('created')),
            ]);
    }

    /**
     * Prévia do que a geração vai fazer, calculada só quando o modal abre.
     */
    private function contractualScheduleSummary(): string
    {
        $plan = app(PuContractualEventScheduleService::class)->plan($this->getOwnerRecord());

        if (! $plan['available']) {
            return (string) $plan['reason'];
        }

        $lines = [sprintf(
            'O contrato prevê %d evento(s) até %s; %d já estão cadastrados.',
            count($plan['contractual_events']),
            $this->brazilianDate($plan['maturity_date']),
            count($plan['present_events']),
        )];
        $lines[] = $plan['creatable_events'] === []
            ? 'Nenhum evento novo a criar.'
            : sprintf(
                'Serão criados %d evento(s), de %s a %s, com a data efetiva no dia útil seguinte do calendário da curva e o motivo de cada adiamento registrado.',
                count($plan['creatable_events']),
                $this->brazilianDate($plan['creatable_events'][0]['effective_date']),
                $this->brazilianDate($plan['creatable_events'][array_key_last($plan['creatable_events'])]['effective_date']),
            );

        if ($plan['creatable_events'] !== [] && $this->getOwnerRecord()->officialPuCurveVersion() !== null) {
            $lines[] = 'A curva oficial vigente não os incorpora sozinha: ela avança até a véspera do primeiro deles e só os aplica depois que uma nova versão for gerada e homologada.';
        }

        if ($plan['missing_in_calculated_period'] !== []) {
            $lines[] = sprintf(
                '%d evento(s) faltam dentro do período já calculado (até %s) e não serão criados aqui: cadastre-os e reprocesse a curva.',
                count($plan['missing_in_calculated_period']),
                $this->brazilianDate($plan['last_calculated_date']),
            );
        }

        if ($plan['conflicting_events'] !== []) {
            $lines[] = sprintf(
                '%d evento(s) cadastrado(s) divergem do contrato e serão mantidos como estão.',
                count($plan['conflicting_events']),
            );
        }

        return implode(' ', $lines);
    }

    private function generateContractualSchedule(): null
    {
        /** @var User $actor */
        $actor = auth()->user();
        $result = app(PuContractualEventScheduleService::class)->write($this->getOwnerRecord(), $actor);

        $justifiedLine = $result['justified'] > 0
            ? sprintf(' %d evento(s) já cadastrado(s) ganharam o motivo do adiamento pelo calendário.', $result['justified'])
            : '';

        match ($result['action']) {
            PuContractualEventScheduleService::ACTION_CREATED => Notification::make()
                ->title(sprintf('%d evento(s) criado(s).', $result['created']))
                ->body(sprintf(
                    'Cronograma contratual cadastrado de %s a %s.%s',
                    $this->brazilianDate($result['first_date']),
                    $this->brazilianDate($result['last_date']),
                    $justifiedLine,
                ))
                ->success()
                ->send(),
            PuContractualEventScheduleService::ACTION_JUSTIFIED => Notification::make()
                ->title('Motivos de data registrados.')
                ->body(trim($justifiedLine).' Nenhum evento novo foi criado.')
                ->success()
                ->send(),
            PuContractualEventScheduleService::ACTION_NOTHING_TO_CREATE => Notification::make()
                ->title('Nenhum evento novo a criar.')
                ->body($result['plan']['missing_in_calculated_period'] === []
                    ? 'O cronograma contratual já está cadastrado.'
                    : 'Os eventos que faltam estão dentro do período já calculado: cadastre-os e reprocesse a curva.')
                ->info()
                ->send(),
            PuContractualEventScheduleService::ACTION_CONCURRENT_CHANGE => Notification::make()
                ->title('Os eventos mudaram durante a geração.')
                ->body('Nenhum evento foi criado. Confira a lista e tente de novo.')
                ->warning()
                ->send(),
            default => Notification::make()
                ->title('Cronograma contratual indisponível.')
                ->body((string) $result['plan']['reason'])
                ->danger()
                ->persistent()
                ->send(),
        };

        return null;
    }

    /**
     * Eventos ativos da emissão no formato canônico, lidos uma vez por requisição
     * para a coluna de suporte.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $activeEventsForSupport = null;

    /**
     * Payload do evento a partir do formulário guiado; recusa, sem gravar, o que
     * a geração da curva recusaria.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function guardedPayload(array $data, CreateAction $action): array
    {
        /** @var Emission $emission */
        $emission = $this->getOwnerRecord();
        $payload = PuContractualEventFields::payload($data);
        $issues = PuContractualEventFields::issues($payload, $emission);

        if ($issues !== []) {
            Notification::make()
                ->title('Evento não cadastrado: a curva o recusaria.')
                ->body(implode(' ', $issues).' Nada foi gravado.')
                ->danger()
                ->persistent()
                ->send();

            $action->halt();
        }

        return $payload;
    }

    private function supportLabel(EmissionPuEvent $record): string
    {
        if (! $record->isActive()) {
            return 'Cancelado';
        }

        if ($this->supportIssues($record) !== []) {
            return 'Bloqueia a curva';
        }

        return app(PuFinancialEffectSupport::class)->isInert(PuFinancialEffectSupport::fromModel($record)) ? 'Sem efeito no PU' : 'Calculado';
    }

    /**
     * @return list<string>
     */
    private function supportIssues(EmissionPuEvent $record): array
    {
        if (! $record->isActive()) {
            return [];
        }

        /** @var Emission $emission */
        $emission = $this->getOwnerRecord();
        $this->activeEventsForSupport ??= $emission->puEvents()->active()->get()
            ->map(fn (EmissionPuEvent $event): array => PuFinancialEffectSupport::fromModel($event))
            ->values()
            ->all();

        return app(PuFinancialEffectSupport::class)->issues(
            PuFinancialEffectSupport::fromModel($record),
            $this->activeEventsForSupport,
            $emission->puParameter?->resolvedCalculationMethod(),
        );
    }

    private static function isScheduledPayment(Get $get): bool
    {
        return PuEventType::tryFrom((string) $get('event_type'))?->isScheduledPayment() ?? true;
    }

    /**
     * As duas datas estão preenchidas e são dias diferentes.
     */
    private static function datesDiffer(Get $get): bool
    {
        $original = substr((string) $get('original_date'), 0, 10);
        $effective = substr((string) $get('effective_date'), 0, 10);

        return $original !== '' && $effective !== '' && $original !== $effective;
    }

    private function brazilianDate(?string $date): string
    {
        return $date !== null ? CarbonImmutable::parse($date)->format('d/m/Y') : '—';
    }

    private function logEventChange(string $action): null
    {
        $emission = $this->getOwnerRecord();

        if ($emission instanceof Emission) {
            app(PuAuditLogService::class)->logEventChange($emission, $action, auth()->id());
        }

        return null;
    }
}
