<?php

namespace App\Filament\Resources\SalesBoardCycles\Pages;

use App\DTOs\SalesBoards\SalesBoardManagementReviewWorkspace as WorkspaceData;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardMakerCheckerException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Exceptions\SalesBoardRectificationException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\SalesBoardManagementReturnService;
use App\Services\SalesBoards\SalesBoardManagementReviewWorkspaceBuilder;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\GateChecklistSummary;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
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
 *
 * Os auxiliares que o Blade usa são protegidos: no Livewire todo método público
 * pode ser chamado pelo navegador e devolve o resultado serializado, e estes
 * devolvem a análise, as pendências e a validação que ela analisa. Públicos
 * ficam só os escalares que a tela e os testes leem.
 *
 * A análise e o workspace -- que traz o portão, e o portão apura a fonte -- são
 * lidos uma vez por requisição: o rótulo, o formulário e a conferência do
 * portão na aprovação, a prévia, o Blade e as permissões perguntam por eles
 * várias vezes, e cada pergunta refazia a apuração. Depois de cada ação o memo
 * é esquecido, para a resposta mostrar o que a ação gravou. A verificação
 * dentro da transação da aprovação continua sendo feita de novo, de propósito.
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

    /**
     * Análise e workspace lidos uma vez por requisição. Privados: o Livewire
     * não os serializa, e nenhuma requisição enxerga o que a anterior leu.
     */
    private ?SalesBoardManagementReview $currentReviewMemo = null;

    private bool $currentReviewResolved = false;

    private ?WorkspaceData $workspaceMemo = null;

    private bool $workspaceResolved = false;

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
    protected function nextAction(WorkspaceData $workspace): array
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        if ($cycle->status === SalesBoardCycleStatus::Cancelled) {
            return [
                'headline' => 'A competência foi cancelada pela Gestão.',
                'detail' => 'Encerrada sem publicação. As decisões registradas continuam consultáveis.',
                'color' => 'gray',
                'icon' => 'heroicon-o-no-symbol',
            ];
        }

        if ($this->closedByReopenedCancellation($workspace)) {
            return [
                'headline' => 'Esta análise foi encerrada pelo cancelamento da competência.',
                'detail' => 'A competência foi reaberta depois. As decisões registradas continuam consultáveis; siga pela próxima ação indicada na tela da competência.',
                'color' => 'gray',
                'icon' => 'heroicon-o-arrow-uturn-up',
            ];
        }

        if ($this->closedByAbandonedRectification($workspace)) {
            return [
                'headline' => 'Esta análise foi encerrada pela desistência da retificação.',
                'detail' => 'A competência voltou à posição publicada, que continua valendo. As decisões registradas continuam consultáveis.',
                'color' => 'gray',
                'icon' => 'heroicon-o-arrow-uturn-left',
            ];
        }

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
     * A análise foi substituída pelo cancelamento de uma competência que depois
     * foi reaberta -- e não por uma versão nova da posição.
     *
     * Só responde sim ou não, e só olha o motivo no caso raro de análise
     * substituída numa competência que não está cancelada. O workspace não
     * carrega o motivo da substituição; ele vem da própria análise, já lida
     * nesta requisição. A tela usa a resposta na próxima ação e no aviso de
     * análise que não vale mais, para os dois contarem a mesma história.
     */
    protected function closedByReopenedCancellation(WorkspaceData $workspace): bool
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        if (($workspace->status !== SalesBoardManagementReviewStatus::Superseded) || ($cycle->status === SalesBoardCycleStatus::Cancelled)) {
            return false;
        }

        $review = $this->currentReview();

        return ($review !== null)
            && ((int) $review->getKey() === $workspace->reviewId)
            && ($review->superseded_reason === SalesBoardCycleCancellationService::SUPERSEDED_REASON);
    }

    /**
     * A análise foi substituída porque a Gestão desistiu da retificação -- e não
     * por uma versão nova da posição.
     */
    protected function closedByAbandonedRectification(WorkspaceData $workspace): bool
    {
        if ($workspace->status !== SalesBoardManagementReviewStatus::Superseded) {
            return false;
        }

        $review = $this->currentReview();

        return ($review !== null)
            && ((int) $review->getKey() === $workspace->reviewId)
            && ($review->superseded_reason === SalesBoardCycleRectificationService::SUPERSEDED_REASON);
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
    protected function failedGateChecks(WorkspaceData $workspace): array
    {
        return collect($workspace->gate['checks'])
            ->reject(fn (array $check): bool => $check['passed'])
            ->map(fn (array $check): string => $check['detail'] === null
                ? $check['label']
                : $check['label'].' — '.$check['detail'])
            ->values()
            ->all();
    }

    protected function currentReview(): ?SalesBoardManagementReview
    {
        if ($this->currentReviewResolved) {
            return $this->currentReviewMemo;
        }

        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        $query = SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->with(['baseline', 'builderReview.sections', 'builderReview.divergences', 'builderReview.attachments', 'nonconformities']);

        $this->currentReviewMemo = $this->review !== null
            ? $query->whereKey($this->review)->first()
            : $query->orderByRaw("CASE WHEN status = 'em_analise' THEN 0 ELSE 1 END")
                ->orderByDesc('attempt')
                ->first();

        $this->currentReviewResolved = true;

        return $this->currentReviewMemo;
    }

    protected function workspace(): ?WorkspaceData
    {
        if ($this->workspaceResolved) {
            return $this->workspaceMemo;
        }

        $review = $this->currentReview();

        $this->workspaceMemo = $review === null
            ? null
            : app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);

        $this->workspaceResolved = true;

        return $this->workspaceMemo;
    }

    /**
     * Uma ação acabou de gravar: a próxima leitura vai ao banco.
     */
    protected function forgetMemos(): void
    {
        $this->currentReviewMemo = null;
        $this->currentReviewResolved = false;
        $this->workspaceMemo = null;
        $this->workspaceResolved = false;
    }

    /**
     * @return list<SalesBoardManagementReview>
     */
    protected function attempts(): array
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        return SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->orderByDesc('attempt')
            ->get()
            ->all();
    }

    /**
     * Decidir, devolver e aprovar são da Gestão: exigem a permissão própria de
     * aprovação, e não a de quem opera a competência. É o que impede a mesma
     * pessoa de preparar a validação e concluir a análise sobre ela.
     */
    public function canDecide(): bool
    {
        return SalesBoardCycleResource::canApprove()
            && ($this->currentReview()?->isEditable() ?? false);
    }

    /**
     * A rodada está aberta, mas quem está na tela não tem a autoridade da
     * Gestão. A tela diz a quem pedir em vez de simplesmente não mostrar botão.
     */
    public function awaitsGestao(): bool
    {
        return ! SalesBoardCycleResource::canApprove()
            && ($this->currentReview()?->isEditable() ?? false);
    }

    /**
     * Por que "Aprovar e publicar" está à vista mas indisponível para quem está
     * na tela: quem enviou a validação da construtora desta rodada não aprova a
     * publicação dela. O serviço confere de novo ao aprovar.
     */
    public function approvalConflict(): ?string
    {
        $user = auth()->user();
        $review = $this->currentReview();

        if (! $user instanceof User || $review === null) {
            return null;
        }

        return SalesBoardApprovalAuthority::managementApprovalConflict($user, $review)?->getMessage();
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
            ->visible(fn (): bool => $this->canDecide())
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
            ->visible(fn (): bool => $this->canDecide())
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
     * O rótulo da aprovação: a retificação publica de novo o quadro da
     * competência, e o botão diz isso.
     */
    protected function approveLabel(): string
    {
        return ($this->workspace()?->isRectification() ?? false)
            ? 'Aprovar e publicar retificação'
            : 'Aprovar e publicar';
    }

    /**
     * O texto que a Gestão aceita ao aprovar -- a versão dele fica congelada na
     * análise ({@see SalesBoardManagementApprovalService::DECLARATION_VERSION}).
     */
    protected function declarationText(): string
    {
        $text = 'Confirmo que as divergências, as não conformidades, os movimentos de competências anteriores e a ponte com a competência anterior foram analisados e que a posição está apta para publicação';
        $rectification = $this->workspace()?->rectification;

        if ($rectification === null) {
            return $text.'.';
        }

        return sprintf(
            '%s, e que a posição retificada substitui a publicada em %s (%s).',
            $text,
            $rectification['published_at'] === null ? '—' : BusinessTime::at($rectification['published_at'])->format('d/m/Y'),
            $rectification['published_version'] ?? '—',
        );
    }

    /**
     * "Aprovar e publicar": o portão.
     *
     * A confirmação mostra exatamente o que será escrito no Quadro de Vendas, e
     * a justificativa de fonte alterada só aparece quando ela é realmente
     * exigida -- pedi-la sempre transformaria um campo de exceção em formalidade
     * preenchida no automático.
     *
     * Com retificação aberta, o rótulo e a declaração dizem que a posição
     * aprovada substitui a publicada. O conflito de maker/checker inclui quem
     * abriu a retificação.
     */
    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label(fn (): string => $this->approveLabel())
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (): string => ($this->workspace()?->isRectification() ?? false)
                ? 'Aprovar a retificação e publicar de novo o Quadro de Vendas'
                : 'Aprovar a posição e publicar o Quadro de Vendas')
            ->modalDescription(fn (): string => $this->approvalPreview())
            ->modalSubmitActionLabel(fn (): string => $this->approveLabel())
            /**
             * O botão só aparece com o portão aberto -- quem decide é o Blade,
             * pelo workspace já lido: um botão que aparece e depois é recusado
             * ensina o operador a clicar para descobrir. A ação, porém, depende
             * só da permissão. O Filament reavalia `visible()` ao montar e no
             * envio do modal, e qualquer condição de estado ali -- o portão, a
             * análise ainda em andamento -- descartaria o clique calado quando
             * mudasse com a página aberta: a fonte mudou, a competência anterior
             * entrou em retificação, outra pessoa aprovou ou devolveu a rodada.
             * O estado é conferido ao abrir e de novo no envio, e a recusa diz o
             * porquê ({@see self::refuseUnavailableApproval()}).
             */
            ->visible(fn (): bool => SalesBoardCycleResource::canApprove())
            ->beforeFormFilled(function (Action $action): void {
                $this->refuseUnavailableApproval($action, whileOpening: true);
            })
            ->beforeFormValidated(function (Action $action): void {
                $this->refuseUnavailableApproval($action, whileOpening: false);
            })
            /**
             * Maker/checker não esconde o botão: ele continua à vista para quem
             * tem a permissão, desabilitado e dizendo a quem pedir. Desabilitada,
             * a ação nem monta -- e o serviço recusa de qualquer forma.
             */
            ->disabled(fn (): bool => $this->approvalConflict() !== null)
            ->tooltip(fn (): ?string => $this->approvalConflict())
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
                        ->label($this->declarationText())
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
     * A análise e o portão de novo, ao abrir a confirmação e no envio dela.
     *
     * O workspace desta requisição é o de agora: o memo não atravessa
     * requisições, e o modal e o formulário já o leram ao resolver a ação --
     * conferir aqui não apura a fonte outra vez. Ao abrir, a página pode estar
     * desatualizada (o portão fechou antes do clique); no envio, ele pode ter
     * fechado com o modal aberto. Nos dois casos nada é publicado: a
     * notificação diz o que falhou, nas palavras do serviço, e o modal não abre
     * ou fecha, para a tela mostrar o estado atual. Aberto, segue para o
     * serviço, que confere tudo de novo dentro da transação e cuja recusa
     * também vira mensagem ({@see self::run()}).
     */
    protected function refuseUnavailableApproval(Action $action, bool $whileOpening): void
    {
        if (! ($this->currentReview()?->isEditable() ?? false)) {
            Notification::make()
                ->title('Não foi possível publicar')
                ->body('Esta análise não está mais em andamento: outra pessoa a aprovou, a devolveu à construtora ou ela foi substituída por uma versão nova. Nada foi publicado.')
                ->danger()
                ->send();

            $action->cancel();
        }

        $workspace = $this->workspace();

        if ($workspace?->isReadyToPublish() ?? false) {
            return;
        }

        $failedChecks = $workspace === null ? [] : $this->failedGateChecks($workspace);

        $opening = $whileOpening
            ? 'A publicação não está liberada. Nada foi publicado.'
            : 'O portão de publicação fechou depois que a confirmação foi aberta. Nada foi publicado.';

        Notification::make()
            ->title('Não foi possível publicar')
            ->body($failedChecks === []
                ? $opening
                : $opening.' O que impede a publicação agora: '.GateChecklistSummary::sentence($failedChecks))
            ->danger()
            ->send();

        $action->cancel();
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

        $preview = sprintf(
            'Será registrado o Quadro de Vendas de %s na competência %s: %s. Total de %d unidades, %s.',
            (string) ($payload->constructionName ?? '—'),
            $payload->referenceMonth->format('m/Y'),
            $buckets,
            $payload->totalUnits,
            'R$ '.IntegerMoney::format($payload->totalValueCents()),
        );

        /**
         * Os avisos não impedem a aprovação -- não há aceite de aviso decidido
         * --, mas quem aprova precisa saber que eles existem antes de publicar.
         */
        $warnings = $this->workspace()?->warningCount() ?? 0;

        return $warnings === 0
            ? $preview
            : $preview.sprintf(' Esta versão tem %d aviso(s) da apuração: confira o painel antes de aprovar.', $warnings);
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
        } catch (SalesBoardManagementReviewException|SalesBoardMakerCheckerException|SalesBoardRectificationException $exception) {
            Notification::make()
                ->title('Não foi possível concluir')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        } finally {
            /**
             * A resposta desta mesma requisição relê o que a ação gravou: o
             * memo da análise e do workspace é de antes dela.
             */
            $this->forgetMemos();
        }
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }
}
