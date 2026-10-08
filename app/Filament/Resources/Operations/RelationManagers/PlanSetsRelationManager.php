<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Operations\Schemas\InitialPhysicalProgressFields;
use App\Filament\Resources\Operations\Schemas\PlanVersionFormFields;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Filament\Support\SurfacesUnplacedValidationErrors;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\User;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementPlanVersionService;
use App\Services\OperationContextVisibilityService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PlanSetsRelationManager extends RelationManager
{
    use DetectsConcurrentUpdates;
    use SurfacesUnplacedValidationErrors;

    protected static string $relationship = 'planSets';

    protected static ?string $title = 'Planos de Medição (Evolução da Obra)';

    private const ACTION_NO_LONGER_AVAILABLE_MESSAGE = 'A situação do plano mudou desde que você abriu esta ação. Recarregue a página.';

    private const LOST_AUTHORIZATION_MESSAGE = 'Você não pode mais alterar os planos de medição desta operação.';

    private const CONCURRENT_PLAN_UPDATE_MESSAGE = 'Outra pessoa alterou os planos desta operação ao mesmo tempo. Recarregue a página e tente de novo.';

    /** @var array<int, MeasurementPhysicalProgress>|null */
    private ?array $physicalProgress = null;

    public function form(Schema $schema): Schema
    {
        $emissionId = $this->getOwnerRecord()->emission_id;

        return $schema
            ->columns(['default' => 1, 'lg' => 12])
            ->components([
                Section::make('Plano de Medição')
                    ->contained(false)
                    // Na edição o cronograma não aparece (é da versão): os dados
                    // do plano ocupam a largura toda.
                    ->columnSpan(fn (string $operation): array => $operation === 'create' ? ['lg' => 5] : ['lg' => 12])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->extraAttributes(['class' => 'bsi-plan-set-data'])
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do Plano')
                            ->required()
                            ->maxLength(120)
                            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-name-field'])
                            ->columnSpanFull(),

                        // Obra e incorrido inicial travam quando o plano passa a
                        // valer; a trava é a de quando o modal abriu. Ativado no
                        // meio-tempo, o campo segue editável e a gravação recusa
                        // a troca (MeasurementPlanSet::PLAN_CONTEXT_LOCKED_REFUSAL),
                        // em vez de descartá-la calada e responder "Salvo".
                        Hidden::make('context_locked')
                            ->dehydrated(false)
                            ->afterStateHydrated(fn (Hidden $component, ?MeasurementPlanSet $record) => $component->state(
                                $record instanceof MeasurementPlanSet && $record->hasBeenEffectiveOrMeasured(),
                            )),

                        Select::make('construction_id')
                            ->label('Empreendimento')
                            ->placeholder('Selecione o empreendimento...')
                            ->options(fn (?MeasurementPlanSet $record): array => $this->constructionOptions($emissionId, null, $record))
                            ->getSearchResultsUsing(fn (string $search, ?MeasurementPlanSet $record): array => $this->constructionOptions($emissionId, $search, $record))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->constructionLabel($value, $emissionId))
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get): bool => (bool) $get('context_locked'))
                            ->helperText('Um plano por empreendimento. Para mudar o cronograma ou o Fundo de Obra de um plano em vigor, crie uma revisão na aba Versões dos Planos.')
                            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-development-field'])
                            ->columnSpanFull(),

                        Toggle::make('is_default')
                            ->label('Plano padrão')
                            ->helperText('Definir como plano padrão desta operação.')
                            ->inline()
                            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-default-toggle-wrp'])
                            ->columnSpanFull(),

                        PlanVersionFormFields::fund()
                            ->label('Fundo de Obra (V1)')
                            ->visibleOn('create'),
                        PlanVersionFormFields::money('initial_incurred_amount', 'Incorrido Inicial')
                            ->disabled(fn (Get $get): bool => (bool) $get('context_locked')),

                        static::initialPhysicalProgressField(),
                        static::initialPhysicalProgressDateField(),
                    ]),

                Section::make('Cronograma físico — Previsto')
                    ->description('Pré-cadastre as medições previstas da V1 do plano, que nasce em rascunho e passa a valer quando for ativada (aba Versões dos Planos). O acumulado previsto é da obra inteira e parte do avanço físico inicial; a partir do mês da ativação, ele é recalculado: avanço atual mais os previstos mensais.')
                    ->contained(false)
                    ->columnSpan(['lg' => 7])
                    ->extraAttributes(['class' => 'bsi-plan-set-schedule'])
                    ->visibleOn('create')
                    ->schema([
                        PlanVersionFormFields::linesEmptyState(),
                        PlanVersionFormFields::lines(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->searchPlaceholder('Buscar plano...')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'construction',
                'activeVersion' => fn ($version) => $version->withCount('lines'),
                'draftVersion' => fn ($version) => $version->withCount('lines'),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Plano')
                    ->weight('semiBold')
                    ->searchable()
                    ->extraCellAttributes(['class' => 'bsi-plan-name-cell']),
                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->placeholder('—'),
                TextColumn::make('version')
                    ->label('Versão')
                    ->badge()
                    ->state(fn (MeasurementPlanSet $record): string => $this->versionLabel($record))
                    ->color(fn (MeasurementPlanSet $record): string => $record->activeVersion instanceof MeasurementPlanVersion ? 'success' : 'warning')
                    ->description(fn (MeasurementPlanSet $record): ?string => $this->versionDescription($record)),
                TextColumn::make('lines_count')
                    ->label('Estrutura')
                    // Linhas do cronograma em vigor -- o histórico das outras
                    // versões fica na aba Versões dos Planos.
                    ->state(fn (MeasurementPlanSet $record): int => (int) $record->currentVersion()?->lines_count)
                    ->formatStateUsing(fn (mixed $state): string => ((int) $state).' '.((int) $state === 1 ? 'linha' : 'linhas'))
                    ->icon(fn (MeasurementPlanSet $record): string => $record->is_default ? 'heroicon-o-check-circle' : 'heroicon-o-x-mark')
                    ->iconColor(fn (MeasurementPlanSet $record): string => $record->is_default ? 'success' : 'gray')
                    ->description(fn (MeasurementPlanSet $record): string => $record->is_default ? 'Padrão' : 'Não padrão'),
                ViewColumn::make('financials')
                    ->label('Financeiro')
                    ->view('filament.tables.plan-set-financials')
                    ->extraCellAttributes(['class' => 'bsi-plan-financials-cell']),
                ViewColumn::make('used_percentage')
                    ->label('% Utilizada')
                    ->view('filament.tables.plan-set-utilization')
                    ->extraCellAttributes(['class' => 'bsi-plan-utilization-cell']),
                TextColumn::make('physical_progress')
                    ->label('Avanço físico')
                    ->state(fn (MeasurementPlanSet $record): string => MeasurementPhysicalProgress::format($this->physicalProgressFor($record)->currentBasisPoints()))
                    ->description(fn (MeasurementPlanSet $record): string => $this->physicalProgressDescription($record))
                    ->extraCellAttributes(['class' => 'tabular-nums']),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Novo Plano')
                    ->modalHeading('Criar Plano de Medição')
                    ->modalDescription('Defina os dados do plano e cadastre o cronograma físico previsto.')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalSubmitActionLabel('Criar')
                    ->modalCancelActionLabel('Cancelar')
                    ->createAnotherAction(fn (Action $action): Action => $action->outlined())
                    ->modalCancelAction(fn (Action $action): Action => $action->outlined())
                    ->stickyModalFooter()
                    ->extraModalWindowAttributes(['class' => 'bsi-plan-set-modal-window'])
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    // Operação concluída ou cancelada não recebe plano novo (o
                    // serviço recusa de novo sob o lock).
                    ->visible(fn (): bool => $this->getOwnerRecord()->status->allowsPlanChanges())
                    // O plano, a V1 e o cronograma nascem juntos pelo serviço de
                    // versões, sob o lock da Operation -- o Repeater não grava
                    // linha nenhuma por fora.
                    ->using(fn (array $data): MeasurementPlanSet => app(MeasurementPlanVersionService::class)->createPlan(
                        $this->getOwnerRecord(),
                        $this->actor(),
                        $data,
                        ['construction_fund_amount' => $data['construction_fund_amount'] ?? null],
                        array_values($data['lines'] ?? []),
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalDescription('Dados do plano. O cronograma e o Fundo de Obra são da versão: para mudá-los, crie uma revisão na aba Versões dos Planos.')
                    ->stickyModalFooter()
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->using(fn (MeasurementPlanSet $record, array $data): MeasurementPlanSet => app(MeasurementPlanVersionService::class)->updatePlan(
                        $record,
                        $this->actor(),
                        $data,
                    )),
                Action::make('createPlanRevision')
                    ->label('Criar revisão')
                    ->icon('heroicon-o-document-duplicate')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->visible(fn (MeasurementPlanSet $record): bool => $this->getOwnerRecord()->status->allowsPlanChanges()
                        && $record->activeVersion instanceof MeasurementPlanVersion
                        && ! $record->draftVersion instanceof MeasurementPlanVersion)
                    ->modalHeading(fn (MeasurementPlanSet $record): string => 'Criar revisão do plano '.($record->construction?->development_name ?? $record->name))
                    ->modalDescription('A revisão nasce em rascunho, com o cronograma e o Fundo de Obra da versão vigente, e só vale depois de ativada na aba Versões dos Planos.')
                    ->modalSubmitActionLabel('Criar revisão')
                    ->fillForm(fn (MeasurementPlanSet $record): array => [
                        'expected_active_version_id' => $record->activeVersion?->getKey(),
                    ])
                    ->schema(fn (MeasurementPlanSet $record): array => [
                        Hidden::make('expected_active_version_id'),
                        Placeholder::make('revision_context')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->revisionContext($record)),
                        PlanVersionFormFields::revisionCategory(),
                        PlanVersionFormFields::revisionReason()->required(),
                    ])
                    ->action(function (MeasurementPlanSet $record, array $data): void {
                        $revision = app(MeasurementPlanVersionService::class)->createRevision(
                            $record,
                            $this->actor(),
                            $data,
                            filled($data['expected_active_version_id'] ?? null) ? (int) $data['expected_active_version_id'] : null,
                        );

                        Notification::make()
                            ->success()
                            ->title($revision->label().' criada em rascunho.')
                            ->body('Edite o rascunho e ative-o na aba Versões dos Planos.')
                            ->send();
                    }),
                DeleteAction::make()
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->visible(fn (): bool => $this->getOwnerRecord()->status->allowsPlanChanges())
                    ->using(fn (MeasurementPlanSet $record): bool => $this->deleteUnderOperationLock($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                        ->visible(fn (): bool => $this->getOwnerRecord()->status->allowsPlanChanges())
                        ->using(fn (DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records) => $this->deleteSelectedUnderOperationLock($action, $records)),
                ]),
            ]);
    }

    /**
     * Chama a ação montada e mostra a recusa do domínio da Medição.
     *
     * A criação, a edição e a revisão do plano passam pelo
     * {@see MeasurementPlanVersionService}, que trava a Operation primeiro e
     * grava tudo numa transação: uma recusa desfaz a gravação inteira. As
     * recusas do domínio (`MeasurementWorkflowException`) não são reportadas e,
     * sem tratamento, viravam o aviso genérico de erro; aqui viram o motivo. A
     * validação vai para o campo do modal quando ele existe; perda de
     * permissão, conflito de unicidade e espera por lock estourada viram aviso.
     * A exclusão também passa pela Operation
     * ({@see self::deleteUnderOperationLock()}).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function callMountedAction(array $arguments = []): mixed
    {
        $action = $this->getMountedAction();
        $title = match ($action?->getName()) {
            'delete' => 'Plano não excluído.',
            'edit' => 'Plano não atualizado.',
            'create' => 'Plano não criado.',
            'createPlanRevision' => 'Revisão não criada.',
            default => 'Ação não concluída.',
        };

        // A ação que deixou de valer entre abrir o modal e enviar (o plano
        // ganhou rascunho, a operação foi encerrada, a permissão saiu)
        // responde com o motivo; o Filament só ignoraria o envio, calado.
        if ($action instanceof Action && $action->isDisabled()) {
            $this->notifyRefusal($title, $action->isAuthorized() ? self::ACTION_NO_LONGER_AVAILABLE_MESSAGE : self::LOST_AUTHORIZATION_MESSAGE);
            $this->unmountAction();

            return null;
        }

        try {
            return parent::callMountedAction($arguments);
        } catch (MeasurementWorkflowException $refusal) {
            $this->notifyRefusal($title, $refusal->getMessage());

            return null;
        } catch (ValidationException $exception) {
            $schema = $this->getMountedActionSchema();
            $placed = $this->placeValidationErrors(PlanVersionFormFields::placeLineErrorsOnItems($exception, $schema), $schema, $title);

            // Fora do try do Filament um `Halt` escaparia como erro de
            // servidor: sem campo para o erro, a notificação já saiu e o modal
            // continua aberto.
            if ($placed !== null) {
                throw $placed;
            }

            return null;
        } catch (AuthorizationException) {
            $this->notifyRefusal($title, self::LOST_AUTHORIZATION_MESSAGE);

            return null;
        } catch (UniqueConstraintViolationException $exception) {
            report($exception);
            $this->notifyRefusal($title, self::CONCURRENT_PLAN_UPDATE_MESSAGE);

            return null;
        } catch (Halt|Cancel|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! static::isConcurrentUpdate($exception)) {
                throw $exception;
            }

            report($exception);
            $this->notifyRefusal($title, self::CONCURRENT_OPERATION_UPDATE_MESSAGE);

            return null;
        }
    }

    private function notifyRefusal(string $title, string $message): void
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($message)
            ->persistent()
            ->send();
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    /**
     * "V2 · Vigente" ou, antes da primeira ativação, "V1 · Rascunho".
     */
    private function versionLabel(MeasurementPlanSet $record): string
    {
        $version = $record->currentVersion();

        return $version instanceof MeasurementPlanVersion
            ? $version->label().' · '.$version->status->label()
            : '—';
    }

    /**
     * "Vigente desde 01/07/2027 · V3 em rascunho", ou o lembrete de ativar.
     */
    private function versionDescription(MeasurementPlanSet $record): ?string
    {
        $active = $record->activeVersion;
        $draft = $record->draftVersion;
        $parts = [];

        if ($active instanceof MeasurementPlanVersion && $active->effective_from !== null) {
            $parts[] = 'Vigente desde '.$active->effective_from->format('d/m/Y');
        }

        if ($draft instanceof MeasurementPlanVersion) {
            $parts[] = $active instanceof MeasurementPlanVersion
                ? $draft->label().' em rascunho'
                : 'Ative a V1 na aba Versões dos Planos para receber medições';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Onde o plano está antes da revisão: a revisão planeja só o que resta.
     */
    private function revisionContext(MeasurementPlanSet $record): HtmlString
    {
        $active = $record->activeVersion;
        $progress = app(MeasurementPhysicalProgressService::class)->forPlanSet($record);
        $context = $active instanceof MeasurementPlanVersion
            ? app(MeasurementPlanVersionService::class)->planningContext($active)
            : null;
        $cells = [
            ['Versão vigente', $active instanceof MeasurementPlanVersion ? $active->label().($active->effective_from === null ? '' : ' · desde '.$active->effective_from->format('d/m/Y')) : '—'],
            ['Fundo de Obra vigente', blank($active?->construction_fund_amount) ? '—' : 'R$ '.MoneyFormatter::formatCurrencyForDisplay($active->construction_fund_amount)],
            ['Avanço físico atual', MeasurementPhysicalProgress::format($progress->currentBasisPoints())],
            // O previsto de competências anteriores ao mês corrente que ainda
            // não foram medidas continua a medir e conta antes da revisão.
            ...(($context['pending'] ?? 0) === 0 ? [] : [[
                'Previsto ainda não medido antes de '.$context['effective_from']->format('m/Y'),
                MeasurementPhysicalProgress::format($context['pending']),
            ]]),
            ['Restante a planejar', MeasurementPhysicalProgress::format($context['remaining_to_plan'] ?? $progress->remainingBasisPoints())],
        ];

        return new HtmlString('<dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">'
            .collect($cells)->map(fn (array $cell): string => sprintf(
                '<div><dt class="text-xs text-gray-500 dark:text-gray-400">%s</dt><dd class="text-sm font-medium text-gray-950 dark:text-white">%s</dd></div>',
                e($cell[0]),
                e($cell[1]),
            ))->implode('')
            .'</dl>');
    }

    /**
     * Exclui o plano pelo serviço de versões, com a Operation travada antes e o
     * plano relido sob o lock ({@see MeasurementPlanVersionService::deletePlan()}).
     *
     * A guarda do plano ({@see MeasurementPlanSet::hasMeasurementHistory()})
     * é leitura comum. Sem a Operation à frente, a exclusão lia "sem medição"
     * enquanto o envio, a aprovação da Engenharia ou o pagamento -- que travam
     * a Operation primeiro -- gravavam o vínculo, e a cascata o apagava logo
     * depois. Com o lock, a guarda reavalia com o que já foi gravado; a
     * permissão e a situação da operação também. Plano que outra pessoa já
     * excluiu não é recusa: o pedido está cumprido.
     */
    private function deleteUnderOperationLock(MeasurementPlanSet $record): bool
    {
        return app(MeasurementPlanVersionService::class)->deletePlan($record, $this->actor());
    }

    /**
     * A exclusão em massa, um plano por transação, cada uma com a Operation
     * travada antes ({@see self::deleteUnderOperationLock()}).
     *
     * O Filament conta a recusa por registro e só dizia "N não pôde(m) ser
     * excluído(s)"; agora o motivo do domínio vai na notificação de falha.
     * Falha inesperada continua contada sem texto técnico e vai para o log, a
     * primeira só, como no Filament.
     *
     * @param  EloquentCollection<int, MeasurementPlanSet>|Collection<int, MeasurementPlanSet>|LazyCollection<int, MeasurementPlanSet>  $records
     */
    private function deleteSelectedUnderOperationLock(DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void
    {
        $isFirstUnexpectedFailure = true;

        $records->each(function (MeasurementPlanSet $record) use ($action, &$isFirstUnexpectedFailure): void {
            try {
                if (! $this->deleteUnderOperationLock($record)) {
                    $action->reportBulkProcessingFailure();
                }
            } catch (MeasurementWorkflowException $refusal) {
                $action->reportBulkProcessingFailure($refusal->getMessage(), e($refusal->getMessage()));
            } catch (AuthorizationException) {
                $action->reportBulkProcessingFailure(self::LOST_AUTHORIZATION_MESSAGE, e(self::LOST_AUTHORIZATION_MESSAGE));
            } catch (Throwable $exception) {
                $action->reportBulkProcessingFailure();

                if ($isFirstUnexpectedFailure) {
                    report($exception);

                    $isFirstUnexpectedFailure = false;
                }
            }
        });
    }

    protected static function initialPhysicalProgressField(): TextInput
    {
        return InitialPhysicalProgressFields::percent()->disabledOn('edit');
    }

    protected static function initialPhysicalProgressDateField(): DatePicker
    {
        return InitialPhysicalProgressFields::referenceDate()->disabledOn('edit');
    }

    /**
     * Uma leitura por operação e por requisição: a tabela pede o progresso de
     * cada plano, e cada plano depende das medições da operação inteira.
     */
    private function physicalProgressFor(MeasurementPlanSet $record): MeasurementPhysicalProgress
    {
        $this->physicalProgress ??= app(MeasurementPhysicalProgressService::class)->forOperation((int) $this->getOwnerRecord()->getKey());

        return $this->physicalProgress[(int) $record->getKey()]
            ?? app(MeasurementPhysicalProgressService::class)->forPlanSet($record);
    }

    private function physicalProgressDescription(MeasurementPlanSet $record): string
    {
        $progress = $this->physicalProgressFor($record);
        $reference = $progress->initialReferenceDate?->format('d/m/Y');

        return sprintf(
            'Inicial %s%s · Medido %s',
            MeasurementPhysicalProgress::format($progress->initialBasisPoints),
            $reference === null ? '' : " em {$reference}",
            MeasurementPhysicalProgress::format($progress->measuredBasisPoints()),
        );
    }

    /**
     * @return array<int, string>
     */
    protected function constructionOptions(mixed $emissionId, ?string $search = null, ?MeasurementPlanSet $record = null): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        // Um plano por obra na operação: a obra já planejada não volta a ser
        // oferecida (replanejar é revisar o plano dela).
        $planned = $this->getOwnerRecord()->planSets()
            ->whereNotNull('construction_id')
            ->when($record instanceof MeasurementPlanSet, fn ($others) => $others->whereKeyNot($record->getKey()))
            ->pluck('construction_id')
            ->all();

        $query = app(OperationContextVisibilityService::class)
            ->visibleConstructions($user, $emissionId)
            ->whereKeyNot($planned)
            ->orderBy('development_name');

        if (filled($search)) {
            $query->where('development_name', 'like', '%'.trim((string) $search).'%');
        }

        return $query->limit(50)->pluck('development_name', 'id')->all();
    }

    protected function constructionLabel(mixed $constructionId, mixed $emissionId): ?string
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(OperationContextVisibilityService::class)
                ->findVisibleConstruction($user, $constructionId, $emissionId)?->development_name
            : null;
    }
}
