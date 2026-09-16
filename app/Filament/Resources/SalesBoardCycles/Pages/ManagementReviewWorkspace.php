<?php

namespace App\Filament\Resources\SalesBoardCycles\Pages;

use App\DTOs\SalesBoards\SalesBoardManagementReviewWorkspace as WorkspaceData;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\SalesBoardManagementReturnService;
use App\Services\SalesBoards\SalesBoardManagementReviewWorkspaceBuilder;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardCycleNextAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

/**
 * A tela em que a Gestão decide.
 *
 * Decide -- não edita. Não existe aqui nenhum caminho para alterar contrato,
 * unidade, valor ou classificação: se o fato operacional está errado, a
 * conclusão é "correção necessária", e o remédio acontece na origem, seguido de
 * recálculo e nova rodada. Um botão "editar quadro" nesta página desfaria toda a
 * cadeia que as fases anteriores construíram.
 *
 * Tudo o que aparece vem do workspace, montado a partir do que foi congelado. A
 * única leitura da fonte viva é a situação dela -- e ela não monta fato nenhum,
 * só responde se a posição ainda pode ser publicada.
 */
class ManagementReviewWorkspace extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SalesBoardCycleResource::class;

    protected string $view = 'filament.resources.sales-board-cycles.pages.management-review-workspace';

    protected static ?string $title = 'Análise da Gestão';

    protected static ?string $breadcrumb = 'Análise';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-management-review-page',
    ];

    /**
     * Qual rodada está sendo exibida. Sem parâmetro, a tela abre a análise em
     * andamento -- ou, na falta dela, a mais recente.
     */
    #[Url]
    public ?int $review = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(SalesBoardCycleResource::canView($this->record), 403);
    }

    public function getSubheading(): ?string
    {
        $review = $this->currentReview();

        if ($review === null) {
            return 'Nenhuma análise foi aberta para esta competência.';
        }

        return sprintf(
            '%s · versão %s da posição · %s',
            $review->attemptLabel(),
            $review->baseline?->versionLabel() ?? '—',
            $review->status->label(),
        );
    }

    /**
     * O que falta para a análise andar.
     *
     * Lê o portão que o workspace já trouxe -- os mesmos critérios que a
     * aprovação aplica -- e só escolhe como dizer. Nenhuma regra de aprovação é
     * refeita aqui: o que impede a publicação é a lista de itens do portão que
     * não passaram, exatamente como o serviço os descreve.
     *
     * @return array{headline: string, detail: string|null, color: string, icon: string, items?: list<string>}
     */
    public function nextAction(WorkspaceData $workspace): array
    {
        if (! $workspace->isApplicable || ($workspace->status === SalesBoardManagementReviewStatus::Superseded)) {
            return [
                'headline' => 'Esta análise se refere a uma versão que não é mais a vigente.',
                'detail' => 'As decisões registradas continuam consultáveis. Siga pela próxima ação indicada na tela da competência.',
                'color' => 'gray',
                'icon' => 'heroicon-o-archive-box',
            ];
        }

        return match ($workspace->status) {
            SalesBoardManagementReviewStatus::Approved => [
                'headline' => 'Posição aprovada e publicada no Quadro de Vendas.',
                'detail' => 'O quadro publicado é imutável: não pode ser alterado nem excluído manualmente.',
                'color' => 'success',
                'icon' => 'heroicon-o-check-badge',
            ],
            SalesBoardManagementReviewStatus::Returned => [
                'headline' => 'Competência devolvida à construtora.',
                'detail' => 'Aguardando a nova rodada de validação da construtora.',
                'color' => 'gray',
                'icon' => 'heroicon-o-arrow-uturn-left',
            ],
            default => $this->openReviewNextAction($workspace),
        };
    }

    /**
     * @return array{headline: string, detail: string|null, color: string, icon: string, items?: list<string>}
     */
    private function openReviewNextAction(WorkspaceData $workspace): array
    {
        $impact = $workspace->staleImpact();

        if ($impact === SalesBoardStaleImpact::Material) {
            return [
                'headline' => 'Recalcule a posição antes de continuar.',
                'detail' => SalesBoardCycleNextAction::staleGuidance($impact).' O recálculo é feito na tela da competência, e esta análise será substituída por uma nova rodada.',
                'color' => 'warning',
                'icon' => 'heroicon-o-arrow-path',
            ];
        }

        if ($impact === SalesBoardStaleImpact::Blocking) {
            return [
                'headline' => 'Corrija a fonte antes de continuar.',
                'detail' => SalesBoardCycleNextAction::staleGuidance($impact),
                'color' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
            ];
        }

        if ($workspace->isReadyToPublish()) {
            return [
                'headline' => 'Aprove e publique, ou devolva à construtora.',
                'detail' => 'Todos os itens do portão estão atendidos.'
                    .($impact === SalesBoardStaleImpact::SourceOnly ? ' '.SalesBoardCycleNextAction::staleGuidance($impact) : ''),
                'color' => 'info',
                'icon' => 'heroicon-o-check-circle',
            ];
        }

        $correctionRequired = $workspace->blockingCount() - $workspace->pendingCount();

        return [
            'headline' => match (true) {
                $workspace->pendingCount() > 0 => 'Resolva as não conformidades e conclua a análise.',
                $correctionRequired > 0 => 'Corrija a fonte e recalcule a posição, ou devolva à construtora.',
                default => 'Publicação bloqueada.',
            },
            'detail' => 'O que ainda impede a publicação:',
            'color' => 'warning',
            'icon' => 'heroicon-o-scale',
            'items' => $this->failedGateChecks($workspace),
        ];
    }

    /**
     * Os itens do portão que não passaram, como o serviço os descreve.
     *
     * @return list<string>
     */
    public function failedGateChecks(WorkspaceData $workspace): array
    {
        return collect($workspace->gate['checks'])
            ->reject(fn (array $check): bool => $check['passed'])
            ->map(fn (array $check): string => $check['detail'] === null
                ? $check['label']
                : $check['label'].' — '.$check['detail'])
            ->values()
            ->all();
    }

    public function currentReview(): ?SalesBoardManagementReview
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        $query = SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->with(['baseline', 'builderReview.sections', 'builderReview.divergences', 'nonconformities']);

        if ($this->review !== null) {
            return $query->whereKey($this->review)->first();
        }

        return $query->orderByRaw("CASE WHEN status = 'em_analise' THEN 0 ELSE 1 END")
            ->orderByDesc('attempt')
            ->first();
    }

    public function workspace(): ?WorkspaceData
    {
        $review = $this->currentReview();

        return $review === null
            ? null
            : app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);
    }

    /**
     * @return list<SalesBoardManagementReview>
     */
    public function attempts(): array
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        return SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->orderByDesc('attempt')
            ->get()
            ->all();
    }

    public function canDecide(): bool
    {
        return SalesBoardCycleResource::canRecalculate()
            && ($this->currentReview()?->isEditable() ?? false);
    }

    /**
     * A conclusão da Gestão sobre uma pendência.
     *
     * As opções oferecidas vêm da origem da própria pendência, e não de uma
     * lista fixa: é o que impede a tela de exibir "exceção aprovada" para uma
     * declaração da construtora e depois o domínio recusar o clique.
     */
    public function decideAction(): Action
    {
        return Action::make('decide')
            ->label('Decidir')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->size('sm')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Registrar a decisão da Gestão')
            ->modalDescription('A posição apurada não é alterada. A decisão fica registrada com o seu nome e será preservada na trilha da publicação.')
            ->modalSubmitActionLabel('Registrar decisão')
            ->schema(fn (array $arguments): array => $this->decisionForm((int) $arguments['nonconformity']))
            ->action(function (array $arguments, array $data): void {
                $this->run(function () use ($arguments, $data): void {
                    app(SalesBoardManagementDecisionService::class)->decide(
                        $this->nonconformity((int) $arguments['nonconformity']),
                        SalesBoardNonconformityDecision::from((string) $data['decision']),
                        $data['decision_reason'] ?? null,
                        auth()->user(),
                    );

                    Notification::make()->title('Decisão registrada')->success()->send();
                });
            });
    }

    /**
     * Desfaz a conclusão e devolve a pendência ao estado de não analisada.
     */
    public function resetDecisionAction(): Action
    {
        return Action::make('resetDecision')
            ->label('Desfazer decisão')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Desfazer esta decisão')
            ->modalDescription('A pendência volta a "pendente" e o motivo registrado é descartado.')
            ->action(function (array $arguments): void {
                $this->run(function () use ($arguments): void {
                    app(SalesBoardManagementDecisionService::class)->decide(
                        $this->nonconformity((int) $arguments['nonconformity']),
                        SalesBoardNonconformityDecision::Pending,
                        null,
                        auth()->user(),
                    );

                    Notification::make()->title('Decisão desfeita')->success()->send();
                });
            });
    }

    public function returnToBuilderAction(): Action
    {
        return Action::make('returnToBuilder')
            ->label('Devolver para a construtora')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Devolver a competência à construtora')
            ->modalDescription('A validação enviada continua registrada como está. Uma nova rodada é aberta sobre o mesmo quadro, com as sete seções pendentes e nenhuma divergência copiada.')
            ->modalSubmitActionLabel('Devolver')
            ->visible(fn (): bool => $this->canDecide())
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da devolução')
                    ->helperText('É o que a construtora vai ler ao abrir a nova rodada. Descreva o que precisa ser esclarecido ou refeito.')
                    ->required()
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (array $data) {
                return $this->run(function () use ($data) {
                    app(SalesBoardManagementReturnService::class)->returnToBuilder(
                        $this->currentReview(),
                        auth()->user(),
                        (string) $data['reason'],
                    );

                    Notification::make()
                        ->title('Competência devolvida')
                        ->body('Uma nova rodada de validação foi aberta para a construtora.')
                        ->success()
                        ->send();

                    return redirect(BuilderReviewWorkspace::getUrl(['record' => $this->getRecord()]));
                });
            });
    }

    /**
     * "Aprovar e publicar": o portão.
     *
     * A confirmação mostra exatamente o que será escrito no Quadro de Vendas, e
     * a justificativa de fonte alterada só aparece quando ela é realmente
     * exigida -- pedi-la sempre transformaria um campo de exceção em formalidade
     * preenchida no automático.
     */
    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar e publicar')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Aprovar a posição e publicar o Quadro de Vendas')
            ->modalDescription(fn (): string => $this->approvalPreview())
            /**
             * A visibilidade é o portão, não uma conveniência de layout: um
             * botão que aparece e depois é recusado pelo domínio ensina o
             * operador a clicar para descobrir. O checklist logo acima já diz o
             * que falta.
             */
            ->visible(fn (): bool => $this->canDecide() && ($this->workspace()?->isReadyToPublish() ?? false))
            ->schema(function (): array {
                $workspace = $this->workspace();

                return array_values(array_filter([
                    ($workspace?->requiresSourceOverride() ?? false)
                        ? Textarea::make('source_change_reason')
                            ->label('Justificativa para aprovar sem recálculo material')
                            ->helperText('Os dados de origem foram alterados, mas o resultado final da posição permanece igual. A justificativa fica congelada na publicação.')
                            ->required()
                            ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                            ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                            ->rows(3)
                        : null,

                    Checkbox::make('declaration')
                        ->label('Confirmo que as divergências e não conformidades foram analisadas e que a posição está apta para publicação.')
                        ->accepted()
                        ->required()
                        ->validationMessages(['accepted' => 'É necessário confirmar a declaração para publicar.']),
                ]));
            })
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    $result = app(SalesBoardManagementApprovalService::class)->approve(
                        $this->currentReview(),
                        auth()->user(),
                        (bool) ($data['declaration'] ?? false),
                        $data['source_change_reason'] ?? null,
                    );

                    $notification = Notification::make()
                        ->title($result->outcome->label())
                        ->body($result->message());

                    $result->outcome === SalesBoardApprovalOutcome::Approved
                        ? $notification->success()
                        : $notification->info();

                    $notification->send();
                });
            });
    }

    /**
     * @return list<Component>
     */
    protected function decisionForm(int $nonconformityId): array
    {
        $nonconformity = $this->nonconformity($nonconformityId);

        $options = collect($nonconformity->allowedDecisions())
            ->reject(fn (SalesBoardNonconformityDecision $decision): bool => $decision->isPending())
            ->mapWithKeys(fn (SalesBoardNonconformityDecision $decision): array => [
                $decision->value => $decision->label().' — '.$decision->description(),
            ])
            ->all();

        return [
            Radio::make('decision')
                ->label('Conclusão da Gestão')
                ->options($options)
                ->default(array_key_first($options))
                ->required(),

            Textarea::make('decision_reason')
                ->label('Motivo')
                ->helperText('Descreva o que sustenta a conclusão. É o que a auditoria vai ler.')
                ->required()
                ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                ->rows(3),
        ];
    }

    /**
     * O que será escrito, montado pela mesma projeção que a publicação usa.
     */
    protected function approvalPreview(): string
    {
        $payload = $this->workspace()?->publicationPreview;

        if ($payload === null) {
            return 'A posição desta versão não pode ser publicada.';
        }

        $buckets = collect($payload->buckets())
            ->map(fn (array $bucket): string => sprintf(
                '%s %d un. (%s)',
                $bucket['label'],
                $bucket['units'],
                'R$ '.IntegerMoney::format($bucket['valueCents']),
            ))
            ->implode(' · ');

        return sprintf(
            'Será registrado o Quadro de Vendas de %s na competência %s: %s. Total de %d unidades, %s.',
            (string) ($payload->constructionName ?? '—'),
            $payload->referenceMonth->format('m/Y'),
            $buckets,
            $payload->totalUnits,
            'R$ '.IntegerMoney::format($payload->totalValueCents()),
        );
    }

    protected function nonconformity(int $id): SalesBoardManagementNonconformity
    {
        $review = $this->currentReview();

        $nonconformity = SalesBoardManagementNonconformity::query()->findOrFail($id);

        abort_unless(
            (int) $nonconformity->sales_board_management_review_id === (int) $review?->getKey(),
            403,
        );

        return $nonconformity;
    }

    /**
     * Recusas do domínio viram mensagem, não erro.
     *
     * Todas elas são situações previsíveis -- a versão mudou, sobrou pendência,
     * já existe quadro publicado -- e quem está na tela precisa saber o que
     * fazer, não ver um stack trace.
     */
    protected function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (SalesBoardManagementReviewException $exception) {
            Notification::make()
                ->title('Não foi possível concluir')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }
}
