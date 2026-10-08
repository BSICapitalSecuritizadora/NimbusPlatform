<?php

namespace App\Filament\Resources\Operations\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPlanVersionComparison;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Operations\Schemas\PlanVersionFormFields;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Filament\Support\GuardsRelationManagerAccess;
use App\Filament\Support\SurfacesUnplacedValidationErrors;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanVersion;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Versões dos planos de medição da operação: a vigente, as substituídas, o
 * rascunho em preparação e os cancelados.
 *
 * Vigente e substituída são histórico e só se leem; replanejar é criar uma
 * revisão (rascunho copiado da vigente), editá-la e ativá-la -- a ativação
 * substitui a vigente na mesma transação. Cada ação aparece só na situação em
 * que vale e para quem pode alterar a operação; o serviço de versões confere
 * tudo de novo sob o lock da Operation, e um rascunho alterado por outra pessoa
 * depois de aberto é recusado pelo contador de revisão do rascunho.
 */
class PlanVersionsRelationManager extends RelationManager
{
    use DetectsConcurrentUpdates;
    use GuardsRelationManagerAccess;
    use SurfacesUnplacedValidationErrors;

    private const REFUSAL_TITLE = 'Versão do plano não alterada.';

    private const UNEXPECTED_FAILURE_MESSAGE = 'Não foi possível concluir a ação. Nada foi gravado; tente novamente.';

    private const LOST_AUTHORIZATION_MESSAGE = 'Você não pode mais alterar os planos de medição desta operação.';

    private const ACTION_NO_LONGER_AVAILABLE_MESSAGE = 'A situação da versão mudou desde que você abriu esta ação. Recarregue a página.';

    private const CONCURRENT_PLAN_UPDATE_MESSAGE = 'Outra pessoa alterou este plano ao mesmo tempo. Nada foi gravado: recarregue a página e confira antes de tentar de novo.';

    protected static string $relationship = 'planVersions';

    protected static ?string $title = 'Versões dos Planos';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Versões do plano de medição')
            ->description('Cada plano tem no máximo uma versão vigente e um rascunho. Vigente e substituídas são histórico: para mudar o cronograma ou o Fundo de Obra, crie uma revisão, edite o rascunho e ative-o.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['planSet.construction', 'createdByUser', 'activatedByUser', 'supersededByVersion'])
                ->withCount('lines'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('plan_set_id')->orderByDesc('version_number'))
            ->columns([
                TextColumn::make('plan')
                    ->label('Plano')
                    ->state(fn (MeasurementPlanVersion $record): ?string => $record->planSet?->construction?->development_name ?? $record->planSet?->name)
                    ->wrap(),
                TextColumn::make('version_number')
                    ->label('Versão')
                    ->formatStateUsing(fn (mixed $state): string => 'V'.(int) $state)
                    ->weight('semiBold'),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (MeasurementPlanVersionStatus $state): string => $state->label())
                    ->color(fn (MeasurementPlanVersionStatus $state): string => $state->color()),
                TextColumn::make('validity')
                    ->label('Vigência')
                    ->state(fn (MeasurementPlanVersion $record): ?string => $this->validity($record))
                    ->placeholder('—'),
                TextColumn::make('construction_fund_amount')
                    ->label('Fundo de Obra')
                    ->state(fn (MeasurementPlanVersion $record): ?string => $this->money($record->construction_fund_amount))
                    ->placeholder('—')
                    ->extraCellAttributes(['class' => 'tabular-nums']),
                TextColumn::make('lines_count')
                    ->label('Cronograma')
                    ->formatStateUsing(fn (mixed $state): string => ((int) $state).' '.((int) $state === 1 ? 'linha' : 'linhas')),
                TextColumn::make('revision_category')
                    ->label('Motivo')
                    ->state(fn (MeasurementPlanVersion $record): ?string => (int) $record->version_number === 1
                        ? 'Plano inicial'
                        : $record->revision_category?->label())
                    ->description(fn (MeasurementPlanVersion $record): ?string => filled($record->revision_reason)
                        ? Str::limit((string) $record->revision_reason, 80)
                        : null)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('activated_at')
                    ->label('Ativada em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(BusinessTime::timezone())
                    ->description(fn (MeasurementPlanVersion $record): ?string => $record->activatedByUser?->name)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(BusinessTime::timezone())
                    ->description(fn (MeasurementPlanVersion $record): ?string => $record->createdByUser?->name)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                $this->viewAction(),
                $this->compareAction(),
                $this->activateAction(),
                $this->editDraftAction(),
                $this->createRevisionAction(),
                ActionGroup::make([
                    $this->generateLinesAction(),
                    $this->cancelDraftAction(),
                ])->label('Rascunho')->visible(fn (MeasurementPlanVersion $record): bool => $record->isDraft()),
            ]);
    }

    private function viewAction(): Action
    {
        return Action::make('viewVersion')
            ->label('Ver')
            ->icon('heroicon-o-eye')
            ->modalHeading(fn (MeasurementPlanVersion $record): string => $this->heading($record))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->schema(fn (MeasurementPlanVersion $record): array => [
                Placeholder::make('summary')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->summaryContent($record)),
                Placeholder::make('schedule')
                    ->label('Cronograma previsto')
                    ->content(fn (): HtmlString => $this->scheduleContent($record)),
            ]);
    }

    private function compareAction(): Action
    {
        return Action::make('compareVersion')
            ->label('Comparar com a anterior')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (MeasurementPlanVersion $record): bool => filled($record->previous_version_id))
            ->modalHeading(fn (MeasurementPlanVersion $record): string => sprintf('%s comparada com a %s', $record->label(), 'V'.($record->previousVersion?->version_number ?? '?')))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->schema(fn (MeasurementPlanVersion $record): array => [
                Placeholder::make('comparison')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->comparisonContent(
                        app(MeasurementPlanVersionService::class)->compare($record->previousVersion, $record),
                    )),
            ]);
    }

    private function activateAction(): Action
    {
        return Action::make('activateVersion')
            ->label(fn (MeasurementPlanVersion $record): string => 'Ativar '.$record->label())
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (MeasurementPlanVersion $record): bool => $record->isDraft() && $this->acceptsPlanChanges())
            ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading(fn (MeasurementPlanVersion $record): string => 'Ativar a '.$record->label().' do plano')
            ->modalDescription(fn (MeasurementPlanVersion $record): string => sprintf(
                filled($record->previous_version_id)
                    ? 'A %1$s passa a valer a partir de %2$s, o mês da ativação, e a versão vigente passa a substituída. Medições já enviadas continuam na versão em que foram enviadas. O avanço físico não muda: a %1$s planeja só o que resta.'
                    : 'A %1$s passa a valer a partir de %2$s, o mês da ativação, e o plano começa a receber medições.',
                $record->label(),
                MeasurementPlanVersionService::activationCompetence()->format('d/m/Y'),
            ))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Ativar')
            ->fillForm(fn (MeasurementPlanVersion $record): array => [
                'expected_revision' => (int) $record->revision,
            ])
            ->schema(fn (MeasurementPlanVersion $record): array => [
                Hidden::make('expected_revision'),
                Placeholder::make('activation_context')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => filled($record->previous_version_id)
                        ? $this->comparisonContent(app(MeasurementPlanVersionService::class)->compare($record->previousVersion, $record))
                        : $this->summaryContent($record)),
                // O acumulado previsto é recalculado na ativação: a pessoa vê,
                // antes de confirmar, o que vai ficar gravado em cada medição
                // prevista -- e o que muda em relação ao rascunho.
                Placeholder::make('activation_schedule')
                    ->label('Cronograma que a ativação grava')
                    ->content(fn (): HtmlString => $this->activationScheduleContent($record)),
            ])
            ->action(function (MeasurementPlanVersion $record, array $data): void {
                $this->guarded(fn () => app(MeasurementPlanVersionService::class)->activate(
                    $record,
                    $this->actor(),
                    (int) ($data['expected_revision'] ?? -1),
                ));

                Notification::make()
                    ->success()
                    ->title($record->label().' ativada.')
                    ->body('As próximas medições deste plano usam esta versão.')
                    ->send();
            });
    }

    private function editDraftAction(): Action
    {
        return Action::make('editDraft')
            ->label('Editar rascunho')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (MeasurementPlanVersion $record): bool => $record->isDraft() && $this->acceptsPlanChanges())
            ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading(fn (MeasurementPlanVersion $record): string => 'Editar o rascunho da '.$record->label())
            ->modalDescription('O cronograma e o Fundo de Obra mudam só neste rascunho; a versão vigente continua valendo até a ativação.')
            ->modalWidth(Width::SevenExtraLarge)
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'bsi-plan-set-modal-window'])
            ->modalSubmitActionLabel('Salvar rascunho')
            ->fillForm(fn (MeasurementPlanVersion $record): array => [
                'expected_revision' => (int) $record->revision,
                'construction_fund_amount' => $record->construction_fund_amount,
                'revision_category' => $record->revision_category?->value,
                'revision_reason' => $record->revision_reason,
                'lines' => PlanVersionFormFields::linesState($record),
            ])
            ->schema(fn (MeasurementPlanVersion $record): array => [
                Hidden::make('expected_revision'),
                Placeholder::make('draft_context')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->draftContext($record)),
                Section::make('Versão')
                    ->contained(false)
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema(array_values(array_filter([
                        PlanVersionFormFields::fund(),
                        (int) $record->version_number > 1 ? PlanVersionFormFields::revisionCategory() : null,
                        (int) $record->version_number > 1 ? PlanVersionFormFields::revisionReason()->columnSpanFull() : null,
                    ]))),
                Section::make('Cronograma físico — Previsto')
                    ->description('O acumulado previsto é da obra inteira e parte do avanço físico inicial. A versão vale a partir do mês da ativação: as medições previstas anteriores a ele continuam iguais às da versão vigente, e o acumulado previsto a partir dele é recalculado na ativação (avanço físico atual mais os previstos mensais).')
                    ->contained(false)
                    ->extraAttributes(['class' => 'bsi-plan-set-schedule'])
                    ->schema([
                        PlanVersionFormFields::linesEmptyState(),
                        PlanVersionFormFields::lines(),
                    ]),
            ])
            ->action(function (MeasurementPlanVersion $record, array $data): void {
                $this->guarded(fn () => app(MeasurementPlanVersionService::class)->updateDraft(
                    $record,
                    $this->actor(),
                    [
                        'construction_fund_amount' => $data['construction_fund_amount'] ?? null,
                        ...((int) $record->version_number > 1 ? [
                            'revision_category' => $data['revision_category'] ?? null,
                            'revision_reason' => $data['revision_reason'] ?? null,
                        ] : []),
                    ],
                    array_values($data['lines'] ?? []),
                    (int) ($data['expected_revision'] ?? -1),
                ));

                Notification::make()->success()->title('Rascunho da '.$record->label().' salvo.')->send();
            });
    }

    private function generateLinesAction(): Action
    {
        return Action::make('generateDraftLines')
            ->label('Gerar medições previstas')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (MeasurementPlanVersion $record): bool => $record->isDraft() && $this->acceptsPlanChanges())
            ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading('Gerar medições previstas no rascunho')
            ->modalDescription('Cria várias medições previstas mensais de uma vez, com previsto zero para preencher depois.')
            ->fillForm(fn (MeasurementPlanVersion $record): array => [
                'expected_revision' => (int) $record->revision,
                'count' => 12,
                'start_date' => now(BusinessTime::timezone())->format('Y-m'),
            ])
            ->schema([
                Hidden::make('expected_revision'),
                TextInput::make('count')
                    ->label('Quantidade de medições')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(60)
                    ->required(),
                TextInput::make('start_date')
                    ->label('Mês da primeira medição')
                    ->type('month')
                    ->required(),
            ])
            ->action(function (MeasurementPlanVersion $record, array $data): void {
                $this->guarded(fn () => app(MeasurementPlanVersionService::class)->addDraftLines(
                    $record,
                    $this->actor(),
                    (int) $data['count'],
                    $data['start_date'] ?? null,
                    (int) ($data['expected_revision'] ?? -1),
                ));

                Notification::make()->success()->title(((int) $data['count']).' medições previstas adicionadas ao rascunho.')->send();
            });
    }

    private function cancelDraftAction(): Action
    {
        return Action::make('cancelDraft')
            ->label('Cancelar rascunho')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (MeasurementPlanVersion $record): bool => $record->isDraft() && (int) $record->version_number > 1)
            ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading(fn (MeasurementPlanVersion $record): string => 'Cancelar o rascunho da '.$record->label())
            ->modalDescription('A revisão fica registrada como cancelada e nunca vale para medição nenhuma. A versão vigente não muda.')
            ->modalSubmitActionLabel('Cancelar rascunho')
            ->fillForm(fn (MeasurementPlanVersion $record): array => ['expected_revision' => (int) $record->revision])
            ->schema([
                Hidden::make('expected_revision'),
                Textarea::make('cancellation_reason')
                    ->label('Motivo do cancelamento')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (MeasurementPlanVersion $record, array $data): void {
                $this->guarded(fn () => app(MeasurementPlanVersionService::class)->cancel(
                    $record,
                    $this->actor(),
                    (string) ($data['cancellation_reason'] ?? ''),
                    (int) ($data['expected_revision'] ?? -1),
                ));

                Notification::make()->success()->title('Rascunho da '.$record->label().' cancelado.')->send();
            });
    }

    private function createRevisionAction(): Action
    {
        return Action::make('createRevision')
            ->label('Criar revisão')
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn (MeasurementPlanVersion $record): bool => $record->isActive()
                && $this->acceptsPlanChanges()
                && ! MeasurementPlanVersion::query()->where('plan_set_id', $record->plan_set_id)->draft()->exists())
            ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
            ->modalHeading(fn (MeasurementPlanVersion $record): string => 'Criar revisão a partir da '.$record->label())
            ->modalDescription('A revisão nasce em rascunho, com o cronograma e o Fundo de Obra da versão vigente. A vigente continua valendo até a revisão ser ativada.')
            ->modalSubmitActionLabel('Criar revisão')
            ->fillForm(fn (MeasurementPlanVersion $record): array => [
                'expected_active_version_id' => (int) $record->getKey(),
            ])
            ->schema(fn (MeasurementPlanVersion $record): array => [
                Hidden::make('expected_active_version_id'),
                Placeholder::make('revision_context')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->revisionContext($record)),
                PlanVersionFormFields::revisionCategory(),
                PlanVersionFormFields::revisionReason()->required(),
            ])
            ->action(function (MeasurementPlanVersion $record, array $data): void {
                $revision = null;
                $this->guarded(function () use ($record, $data, &$revision): void {
                    $revision = app(MeasurementPlanVersionService::class)->createRevision(
                        $record->planSet()->firstOrFail(),
                        $this->actor(),
                        $data,
                        (int) ($data['expected_active_version_id'] ?? $record->getKey()),
                    );
                });

                Notification::make()
                    ->success()
                    ->title(($revision?->label() ?? 'Revisão').' criada em rascunho.')
                    ->body('Edite o rascunho e ative-o quando estiver pronto.')
                    ->send();
            });
    }

    /**
     * A ação que deixou de valer entre abrir o modal e enviar -- o rascunho
     * foi ativado ou cancelado por outra pessoa, a operação foi encerrada, a
     * permissão saiu -- responde com o motivo; o Filament só ignoraria o envio.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function callMountedAction(array $arguments = []): mixed
    {
        $action = $this->getMountedAction();

        if ($action instanceof Action && $action->isDisabled()) {
            Notification::make()
                ->danger()
                ->title(self::REFUSAL_TITLE)
                ->body($action->isAuthorized() ? self::ACTION_NO_LONGER_AVAILABLE_MESSAGE : self::LOST_AUTHORIZATION_MESSAGE)
                ->persistent()
                ->send();
            $this->unmountAction();

            return null;
        }

        return parent::callMountedAction($arguments);
    }

    /**
     * Operação concluída ou cancelada não replaneja: as ações de revisão, de
     * edição e de ativação nem aparecem (o serviço recusa de novo sob o lock).
     */
    private function acceptsPlanChanges(): bool
    {
        return $this->getOwnerRecord()->status->allowsPlanChanges();
    }

    /**
     * Executa a ação de versão e devolve toda recusa como algo que a pessoa
     * lê, com o modal aberto -- o mesmo tratamento das ações da Medição.
     */
    private function guarded(Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $schema = $this->getMountedActionSchema();

            throw $this->placeValidationErrors(PlanVersionFormFields::placeLineErrorsOnItems($exception, $schema), $schema, self::REFUSAL_TITLE) ?? new Halt;
        } catch (MeasurementWorkflowException $exception) {
            $this->refuse($exception->getMessage());
        } catch (AuthorizationException) {
            $this->refuse(self::LOST_AUTHORIZATION_MESSAGE);
        } catch (UniqueConstraintViolationException $exception) {
            // O banco recusou a segunda vigente, o segundo rascunho ou o mesmo
            // número de versão: outra pessoa gravou no mesmo plano ao mesmo
            // tempo. A transação foi desfeita inteira.
            report($exception);

            $this->refuse(self::CONCURRENT_PLAN_UPDATE_MESSAGE);
        } catch (Halt|Cancel|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->refuse(static::isConcurrentUpdate($exception) ? self::CONCURRENT_OPERATION_UPDATE_MESSAGE : self::UNEXPECTED_FAILURE_MESSAGE);
        }
    }

    private function refuse(string $message): never
    {
        Notification::make()
            ->danger()
            ->title(self::REFUSAL_TITLE)
            ->body($message)
            ->persistent()
            ->send();

        throw new Halt;
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function heading(MeasurementPlanVersion $record): string
    {
        $plan = $record->planSet?->construction?->development_name ?? $record->planSet?->name ?? 'Plano';

        return sprintf('%s · %s · %s', $plan, $record->label(), $record->status->label());
    }

    /**
     * "Desde 01/07/2027" para a vigente, "01/07/2027 a 30/09/2027" para a
     * substituída. O rascunho ainda não tem vigência: ela é o mês em que for
     * ativado. Duas ativações no mesmo mês deixam a primeira sem competência
     * nenhuma -- a seguinte vale a partir do mesmo dia 1º --, e o intervalo
     * invertido não é mostrado.
     */
    private function validity(MeasurementPlanVersion $record): ?string
    {
        $from = $record->effective_from?->format('d/m/Y');
        $until = $record->status === MeasurementPlanVersionStatus::Superseded ? $record->effectiveUntil() : null;

        return match ($record->status) {
            MeasurementPlanVersionStatus::Active => $from === null ? null : 'Desde '.$from,
            MeasurementPlanVersionStatus::Superseded => match (true) {
                $from === null => null,
                $until === null => $from.' a —',
                $until->lt($record->effective_from) => $record->assets()->exists()
                    ? 'Substituída no mês da própria ativação: sem competência própria, mas com medições enviadas sob ela'
                    : 'Substituída no mês da própria ativação: não regeu competência',
                default => $from.' a '.$until->format('d/m/Y'),
            },
            MeasurementPlanVersionStatus::Draft => 'Se ativada agora: desde '.MeasurementPlanVersionService::activationCompetence()->format('d/m/Y'),
            MeasurementPlanVersionStatus::Cancelled => null,
        };
    }

    private function money(mixed $amount): ?string
    {
        return blank($amount) ? null : 'R$ '.MoneyFormatter::formatCurrencyForDisplay($amount);
    }

    private function moneyFromCents(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }

    private function summaryContent(MeasurementPlanVersion $record): HtmlString
    {
        $cells = [
            ['Situação', $record->status->label()],
            ['Vigência', $this->validity($record) ?? '—'],
            ['Fundo de Obra', $this->money($record->construction_fund_amount) ?? '—'],
            ['Motivo', (int) $record->version_number === 1 ? 'Plano inicial' : ($record->revision_category?->label() ?? '—')],
        ];

        $justification = filled($record->revision_reason)
            ? '<p class="mt-3 text-sm text-gray-700 dark:text-gray-200">'.e((string) $record->revision_reason).'</p>'
            : '';

        return new HtmlString($this->definitionGrid($cells).$justification);
    }

    private function scheduleContent(MeasurementPlanVersion $record): HtmlString
    {
        $rows = $record->lines()
            ->orderBy('measurement_date')
            ->orderBy('sequence_number')
            ->get()
            ->map(fn (MeasurementPlanLine $line): string => sprintf(
                '<tr><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 tabular-nums">%s</td></tr>',
                e(str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT)),
                e($line->measurement_date?->format('m/Y') ?? '—'),
                e(MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($line->planned_monthly_percent))),
                e(MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($line->planned_cumulative_percent))),
            ))
            ->implode('');

        if ($rows === '') {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma medição prevista nesta versão.</p>');
        }

        return new HtmlString(
            '<table class="w-full text-start text-sm text-gray-700 dark:text-gray-200"><thead><tr class="text-xs text-gray-500 dark:text-gray-400">'
            .'<th class="pb-1 pe-4 text-start font-medium">Medição</th><th class="pb-1 pe-4 text-start font-medium">Mês</th><th class="pb-1 pe-4 text-start font-medium">Previsto mensal</th><th class="pb-1 text-start font-medium">Previsto acumulado</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
        );
    }

    /**
     * Onde o plano está antes da revisão: o avanço físico atual e o que resta
     * -- a revisão planeja só o que resta, e não muda o que já foi executado.
     */
    private function revisionContext(MeasurementPlanVersion $record): HtmlString
    {
        $context = app(MeasurementPlanVersionService::class)->planningContext($record);

        return new HtmlString($this->definitionGrid([
            ['Versão vigente', $record->label().($record->effective_from === null ? '' : ' · desde '.$record->effective_from->format('d/m/Y'))],
            ['Fundo de Obra vigente', $this->money($record->construction_fund_amount) ?? '—'],
            ['Avanço físico atual', MeasurementPhysicalProgress::format($context['current'])],
            ...$this->pendingCell($context),
            ['Restante a planejar', MeasurementPhysicalProgress::format($context['remaining_to_plan'])],
        ]));
    }

    /**
     * O previsto de competências anteriores ao mês corrente que ainda não foram
     * medidas continua a medir e conta antes do que a revisão planejar.
     *
     * @param  array{effective_from: CarbonImmutable, current: int, pending: int, remaining_to_plan: int}  $context
     * @return list<array{0: string, 1: string}>
     */
    private function pendingCell(array $context): array
    {
        return $context['pending'] === 0 ? [] : [[
            'Previsto ainda não medido antes de '.$context['effective_from']->format('m/Y'),
            MeasurementPhysicalProgress::format($context['pending']),
        ]];
    }

    /**
     * Cada medição prevista do rascunho com o acumulado que ele tem e o que a
     * ativação gravaria agora ({@see MeasurementPlanVersionService::activationPreview()}).
     */
    private function activationScheduleContent(MeasurementPlanVersion $record): HtmlString
    {
        $service = app(MeasurementPlanVersionService::class);
        $preview = $service->activationPreview($record);
        $withoutThePlan = $service->monthsMeasuredWithoutThePlan($record->planSet()->firstOrFail());
        $rows = $record->lines()
            ->orderBy('measurement_date')
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get()
            ->map(function (MeasurementPlanLine $line) use ($preview, $withoutThePlan): string {
                $drafted = (int) MeasurementPhysicalProgress::basisPoints($line->planned_cumulative_percent);
                $activated = $preview[(int) $line->getKey()] ?? $drafted;
                $holder = $line->measurement_date === null ? null : ($withoutThePlan[$line->measurement_date->format('Y-m')] ?? null);

                return sprintf(
                    '<tr><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 tabular-nums">%s</td></tr>',
                    e(str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT)),
                    e($line->measurement_date?->format('m/Y') ?? '—'),
                    e(MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($line->planned_monthly_percent))),
                    e(MeasurementPhysicalProgress::format($drafted)),
                    // A competência já medida sem este plano não entra no
                    // cronograma calculado enquanto aquela medição estiver de pé.
                    e($holder !== null
                        ? sprintf('fora do cálculo: competência já medida sem este plano (#%d)', $holder)
                        : MeasurementPhysicalProgress::format($activated).($activated === $drafted ? '' : ' (recalculado)')),
                );
            })
            ->implode('');

        if ($rows === '') {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma medição prevista nesta versão.</p>');
        }

        return new HtmlString(
            '<table class="w-full text-start text-sm text-gray-700 dark:text-gray-200"><thead><tr class="text-xs text-gray-500 dark:text-gray-400">'
            .'<th class="pb-1 pe-4 text-start font-medium">Medição</th><th class="pb-1 pe-4 text-start font-medium">Mês</th><th class="pb-1 pe-4 text-start font-medium">Previsto mensal</th><th class="pb-1 pe-4 text-start font-medium">Acumulado no rascunho</th><th class="pb-1 text-start font-medium">Acumulado na ativação</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
        );
    }

    /**
     * O rascunho planeja só o que resta: o avanço físico atual é do plano e a
     * revisão não o muda.
     */
    private function draftContext(MeasurementPlanVersion $record): HtmlString
    {
        $active = $record->planSet()->firstOrFail()->activeVersion()->first();
        $context = app(MeasurementPlanVersionService::class)->planningContext($record);

        return new HtmlString($this->definitionGrid([
            ['Versão vigente', $active instanceof MeasurementPlanVersion ? $active->label().($active->effective_from === null ? '' : ' · desde '.$active->effective_from->format('d/m/Y')) : 'Nenhuma (plano ainda não ativado)'],
            ['Vigência se ativado agora', 'Desde '.$context['effective_from']->format('d/m/Y')],
            ['Avanço físico atual', MeasurementPhysicalProgress::format($context['current'])],
            ...$this->pendingCell($context),
            ['Restante a planejar', MeasurementPhysicalProgress::format($context['remaining_to_plan'])],
        ]));
    }

    private function comparisonContent(MeasurementPlanVersionComparison $comparison): HtmlString
    {
        $base = $comparison->base?->label() ?? '—';
        $candidate = $comparison->candidate->label();
        $fundVariation = $comparison->fundVariationCents();
        $fundShare = $comparison->fundVariationBasisPoints();
        $days = $comparison->completionVariationDays();

        $rows = [
            [
                'Custo previsto (Fundo de Obra)',
                $this->moneyFromCents($comparison->baseFundCents),
                $this->moneyFromCents($comparison->candidateFundCents),
                $fundVariation === null
                    ? '—'
                    : ($fundVariation === 0
                        ? 'inalterado'
                        : ($fundVariation > 0 ? '+' : '-').'R$ '.IntegerMoney::format(abs($fundVariation))
                            .($fundShare === null ? '' : ' ('.($fundShare > 0 ? '+' : '').IntegerMoney::formatBasisPoints($fundShare).'%)')),
            ],
            [
                'Término previsto',
                $comparison->baseCompletion?->format('d/m/Y') ?? '—',
                $comparison->candidateCompletion?->format('d/m/Y') ?? '—',
                $days === null ? '—' : ($days === 0 ? 'inalterado' : sprintf('%s%d dias', $days > 0 ? '+' : '', $days)),
            ],
            [
                'Avanço físico atual',
                MeasurementPhysicalProgress::format($comparison->currentBasisPoints),
                MeasurementPhysicalProgress::format($comparison->currentBasisPoints),
                'inalterado',
            ],
            [
                'Avanço físico restante',
                MeasurementPhysicalProgress::format($comparison->remainingBasisPoints),
                MeasurementPhysicalProgress::format($comparison->remainingBasisPoints),
                'inalterado',
            ],
            ...(($comparison->pendingBeforeEffectiveBasisPoints ?? 0) === 0 ? [] : [[
                'Previsto ainda não medido antes da vigência',
                MeasurementPhysicalProgress::format((int) $comparison->pendingBeforeEffectiveBasisPoints),
                MeasurementPhysicalProgress::format((int) $comparison->pendingBeforeEffectiveBasisPoints),
                'continua a medir, sob a versão vigente no envio',
            ]]),
            [
                'Acumulado previsto ao final',
                $comparison->baseFinalCumulativeBasisPoints === null ? '—' : MeasurementPhysicalProgress::format($comparison->baseFinalCumulativeBasisPoints),
                $comparison->candidateFinalCumulativeBasisPoints === null ? '—' : MeasurementPhysicalProgress::format($comparison->candidateFinalCumulativeBasisPoints),
                '',
            ],
            [
                'Vigência desde',
                $comparison->base?->effective_from?->format('d/m/Y') ?? '—',
                $comparison->candidate->effective_from?->format('d/m/Y')
                    ?? ($comparison->candidate->isDraft() ? MeasurementPlanVersionService::activationCompetence()->format('d/m/Y').' (se ativada agora)' : '—'),
                '',
            ],
        ];

        $table = '<table class="w-full text-start text-sm text-gray-700 dark:text-gray-200"><thead><tr class="text-xs text-gray-500 dark:text-gray-400">'
            .'<th class="pb-1 pe-4 text-start font-medium"></th>'
            .'<th class="pb-1 pe-4 text-start font-medium">'.e($base).'</th>'
            .'<th class="pb-1 pe-4 text-start font-medium">'.e($candidate).'</th>'
            .'<th class="pb-1 text-start font-medium">Variação</th></tr></thead><tbody>'
            .collect($rows)->map(fn (array $row): string => sprintf(
                '<tr><th scope="row" class="py-1 pe-4 text-start font-medium text-gray-950 dark:text-white">%s</th><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 pe-4 tabular-nums">%s</td><td class="py-1 tabular-nums">%s</td></tr>',
                e($row[0]),
                e($row[1]),
                e($row[2]),
                e($row[3]),
            ))->implode('')
            .'</tbody></table>';

        $lines = $this->lineChangesContent($comparison);
        $open = $comparison->openMeasurementIdsOnBase === []
            ? ''
            : '<p class="mt-3 text-sm text-gray-700 dark:text-gray-200">'.e(sprintf(
                'Medições em andamento enviadas sob a %s continuam nela: %s.',
                $base,
                collect($comparison->openMeasurementIdsOnBase)->map(fn (int $id): string => '#'.$id)->implode(', '),
            )).'</p>';
        // A medição fica ligada à versão vigente no envio, inclusive a de uma
        // competência anterior à vigência enviada depois da ativação: a pessoa
        // decide sabendo qual Fundo de Obra ela vai usar.
        $late = ($comparison->pendingBeforeEffectiveBasisPoints ?? 0) === 0
            ? ''
            : '<p class="mt-3 text-sm text-gray-700 dark:text-gray-200">'.e(sprintf(
                'A medição de uma competência anterior à vigência enviada depois da ativação fica ligada à %s e ao Fundo de Obra dela.',
                $candidate,
            )).'</p>';

        return new HtmlString($table.$lines.$open.$late);
    }

    private function lineChangesContent(MeasurementPlanVersionComparison $comparison): string
    {
        if (! $comparison->hasLineChanges()) {
            return '<p class="mt-3 text-sm text-gray-700 dark:text-gray-200">'.e(sprintf('Cronograma sem mudanças (%d medições previstas iguais).', $comparison->unchangedLines)).'</p>';
        }

        $describe = fn (array $line): string => sprintf(
            '%s (%s, mensal %s, acumulado %s)',
            str_pad((string) $line['sequence_number'], 2, '0', STR_PAD_LEFT),
            $line['measurement_date'] === null ? 'sem mês' : Carbon::parse($line['measurement_date'])->format('m/Y'),
            MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($line['planned_monthly_percent'])),
            MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($line['planned_cumulative_percent'])),
        );

        $items = [
            ...array_map(fn (array $line): string => 'Nova: '.$describe($line), $comparison->addedLines),
            ...array_map(fn (array $line): string => 'Removida: '.$describe($line), $comparison->removedLines),
            ...array_map(fn (array $change): string => 'Alterada: '.$describe($change['before']).' → '.$describe($change['after']), $comparison->changedLines),
        ];

        return '<p class="mt-3 text-sm font-medium text-gray-950 dark:text-white">'.e(sprintf('Cronograma: %d nova(s), %d removida(s), %d alterada(s), %d igual(is).', count($comparison->addedLines), count($comparison->removedLines), count($comparison->changedLines), $comparison->unchangedLines)).'</p>'
            .'<ul class="mt-1 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'
            .collect($items)->map(fn (string $item): string => '<li>'.e($item).'</li>')->implode('')
            .'</ul>';
    }

    /**
     * @param  list<array{0: string, 1: string}>  $cells
     */
    private function definitionGrid(array $cells): string
    {
        return '<dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">'
            .collect($cells)->map(fn (array $cell): string => sprintf(
                '<div><dt class="text-xs text-gray-500 dark:text-gray-400">%s</dt><dd class="text-sm font-medium text-gray-950 dark:text-white">%s</dd></div>',
                e($cell[0]),
                e($cell[1]),
            ))->implode('')
            .'</dl>';
    }
}
