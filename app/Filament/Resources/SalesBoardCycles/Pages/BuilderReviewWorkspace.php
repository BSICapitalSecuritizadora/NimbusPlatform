<?php

namespace App\Filament\Resources\SalesBoardCycles\Pages;

use App\DTOs\SalesBoards\BuilderResponseEvidence;
use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\DTOs\SalesBoards\SalesBoardBuilderResponseView;
use App\DTOs\SalesBoards\SalesBoardBuilderReviewWorkspace as WorkspaceData;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceMovementRow;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceSection;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceUnitRow;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderResponseChannel;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBuilderResponseEvidenceStore;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use App\Services\SalesBoards\SalesBoardBuilderReviewWorkspaceBuilder;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\RawJs;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * A tela em que a competência é validada.
 *
 * É a mesma superfície que a construtora usará quando o acesso externo existir.
 * Hoje ela é aberta por um operador interno -- e o que for enviado daqui fica
 * registrado como tal, nunca como se a construtora tivesse enviado por conta
 * própria. A escolha do mecanismo de acesso externo continua em aberto, e nada
 * nesta página depende dela: o domínio recebe uma identidade abstrata, não um
 * usuário autenticado.
 *
 * Tudo o que aparece vem do snapshot congelado, através do workspace. A página
 * não consulta contrato, parcela, tabela nem permuta viva -- se a fonte mudou
 * desde a geração, a tela continua mostrando o quadro sobre o qual a construtora
 * está sendo perguntada, que é exatamente o ponto.
 *
 * As ações de preparo -- confirmar, desfazer, apontar e remover divergência,
 * enviar -- só existem para quem opera a competência: ação oculta não monta nem
 * executa no servidor, e o editor e o envio recusam de novo pela identidade de
 * quem age. Os auxiliares que o Blade usa são protegidos: no Livewire todo
 * método público pode ser chamado pelo navegador e devolve o resultado
 * serializado, e estes devolvem a revisão com a posição inteira.
 *
 * Uma seção aberta por vez. Com 800 unidades, a página inteira passava de
 * 800 KB por clique e as ações ficavam no fim de centenas de linhas; agora só
 * a seção aberta renderiza linhas, 100 por página, com as ações no topo e uma
 * busca por unidade ou contrato. A construtora continua confirmando a seção
 * inteira -- a paginação é só de exibição. A revisão e o workspace são lidos
 * uma vez por requisição e esquecidos depois de cada ação.
 */
class BuilderReviewWorkspace extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SalesBoardCycleResource::class;

    protected string $view = 'filament.resources.sales-board-cycles.pages.builder-review-workspace';

    protected static ?string $title = 'Validação da construtora';

    protected static ?string $breadcrumb = 'Validação';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-builder-review-page',
    ];

    /**
     * Quantas linhas da seção aberta cada página mostra.
     */
    public const ROWS_PER_PAGE = 100;

    /**
     * A partir de quantas linhas a seção ganha a busca.
     */
    public const SEARCHABLE_FROM_ROWS = 20;

    /**
     * Qual tentativa está sendo exibida. Sem parâmetro, a tela abre a validação
     * em andamento -- ou, na falta dela, a mais recente.
     */
    #[Url]
    public ?int $review = null;

    /**
     * A seção aberta, pelo valor do enum. Sem parâmetro, ou com um valor que
     * não é seção, abre a primeira pendente.
     */
    #[Url(as: 'secao')]
    public ?string $openSection = null;

    public int $sectionPage = 1;

    public string $sectionSearch = '';

    /**
     * Revisão e workspace lidos uma vez por requisição. Privados: o Livewire
     * não os serializa, e uma requisição nunca enxerga o que a anterior leu.
     */
    private ?SalesBoardBuilderReview $currentReviewMemo = null;

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
            return 'Nenhuma validação foi aberta para esta competência.';
        }

        return sprintf(
            'Validação da construtora · %s · versão %s da posição · %s',
            $review->attemptLabel(),
            $review->baseline?->versionLabel() ?? '—',
            $review->status->label(),
        );
    }

    /**
     * O que falta para esta rodada andar, a partir do que o workspace já trouxe
     * e da situação do ciclo -- sem consulta nova e sem reproduzir a regra de
     * envio, que continua sendo do serviço.
     *
     * @return array{headline: string, detail: string, color: string, icon: string}
     */
    protected function nextAction(WorkspaceData $workspace): array
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        return match ($workspace->status) {
            SalesBoardBuilderReviewStatus::Draft => $workspace->canSubmit()
                ? [
                    'headline' => 'Envie a validação para a análise da Gestão.',
                    'detail' => 'Todas as seções foram revisadas. O envio é definitivo: correções posteriores exigem uma nova rodada.',
                    'color' => 'info',
                    'icon' => 'heroicon-o-paper-airplane',
                ]
                : [
                    'headline' => 'Conclua a revisão da construtora.',
                    'detail' => sprintf(
                        'Faltam %d de %d seções: %s. Confirme cada seção ou aponte a divergência; o envio fica disponível quando todas estiverem respondidas.',
                        count($workspace->pendingSections()),
                        $workspace->sectionsTotal,
                        collect($workspace->pendingSections())->map(fn (SectionEnum $section): string => $section->label())->implode(', '),
                    ),
                    'color' => 'warning',
                    'icon' => 'heroicon-o-clipboard-document-check',
                ],
            SalesBoardBuilderReviewStatus::Submitted => match ($cycle->status) {
                SalesBoardCycleStatus::ManagementReview => [
                    'headline' => 'Aguardando análise da Gestão.',
                    'detail' => 'A validação foi enviada e não pode mais ser alterada.',
                    'color' => 'info',
                    'icon' => 'heroicon-o-scale',
                ],
                SalesBoardCycleStatus::Approved => [
                    'headline' => 'Competência aprovada e publicada pela Gestão.',
                    'detail' => 'Esta validação sustentou a publicação e permanece registrada como foi enviada.',
                    'color' => 'success',
                    'icon' => 'heroicon-o-check-badge',
                ],
                default => $this->sustainsPublication($workspace) ? [
                    'headline' => 'Esta validação sustentou a posição publicada.',
                    'detail' => 'A competência está em retificação e permanece registrada como foi enviada. Na tela da competência, use “Abrir validação da construtora” para a rodada da retificação.',
                    'color' => 'success',
                    'icon' => 'heroicon-o-check-badge',
                ] : [
                    'headline' => 'Esta rodada já foi enviada.',
                    'detail' => 'A competência voltou à construtora. Na tela da competência, use “Abrir validação da construtora” para a rodada vigente.',
                    'color' => 'gray',
                    'icon' => 'heroicon-o-arrow-uturn-left',
                ],
            },
            SalesBoardBuilderReviewStatus::Superseded => match (true) {
                $cycle->status === SalesBoardCycleStatus::Cancelled => [
                    'headline' => 'A competência foi cancelada pela Gestão.',
                    'detail' => 'Esta rodada foi encerrada sem publicação. As declarações continuam registradas como foram feitas.',
                    'color' => 'gray',
                    'icon' => 'heroicon-o-no-symbol',
                ],
                $this->closedByReopenedCancellation($workspace) => [
                    'headline' => 'Esta rodada foi encerrada pelo cancelamento da competência.',
                    'detail' => 'A competência foi reaberta depois. Na tela da competência, use “Abrir validação da construtora” para a rodada vigente.',
                    'color' => 'gray',
                    'icon' => 'heroicon-o-arrow-uturn-up',
                ],
                $this->closedByAbandonedRectification($workspace) => [
                    'headline' => 'Esta rodada foi encerrada pela desistência da retificação.',
                    'detail' => 'A competência voltou à posição publicada, que continua valendo. As declarações desta rodada continuam registradas como foram feitas.',
                    'color' => 'gray',
                    'icon' => 'heroicon-o-arrow-uturn-left',
                ],
                default => [
                    'headline' => 'Esta rodada foi substituída por uma nova versão da posição.',
                    'detail' => 'As declarações continuam registradas. Na tela da competência, use “Abrir validação da construtora” para validar a versão vigente.',
                    'color' => 'gray',
                    'icon' => 'heroicon-o-arrow-path',
                ],
            },
        };
    }

    /**
     * A rodada foi substituída pelo cancelamento de uma competência que depois
     * foi reaberta -- e não por uma versão nova da posição.
     *
     * Só é perguntado neste caso raro (rodada substituída numa competência que
     * não está cancelada). O workspace não carrega o motivo da substituição; ele
     * vem da própria rodada, já lida nesta requisição. Dizer "substituída por
     * uma nova versão" a quem viu a competência ser cancelada e reaberta
     * contaria outra história.
     */
    protected function closedByReopenedCancellation(WorkspaceData $workspace): bool
    {
        $review = $this->currentReview();

        return ($review !== null)
            && ((int) $review->getKey() === $workspace->reviewId)
            && ($review->superseded_reason === SalesBoardCycleCancellationService::SUPERSEDED_REASON);
    }

    /**
     * A rodada foi substituída porque a Gestão desistiu da retificação -- e não
     * por uma versão nova da posição. Mesmo cuidado da reabertura: o motivo vem
     * da própria rodada, já lida nesta requisição.
     */
    protected function closedByAbandonedRectification(WorkspaceData $workspace): bool
    {
        $review = $this->currentReview();

        return ($review !== null)
            && ((int) $review->getKey() === $workspace->reviewId)
            && ($review->superseded_reason === SalesBoardCycleRectificationService::SUPERSEDED_REASON);
    }

    /**
     * A rodada enviada é a que sustenta a posição publicada -- a análise dela
     * aprovou e publicou --, numa competência que voltou ao fluxo pela
     * retificação.
     */
    protected function sustainsPublication(WorkspaceData $workspace): bool
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        return SalesBoardPublication::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('sales_board_builder_review_id', $workspace->reviewId)
            ->exists();
    }

    /**
     * A rodada exibida, com a posição congelada e a resposta da construtora.
     *
     * Lida uma vez por requisição: o cabeçalho, as ações, o Blade e o
     * formulário de divergência perguntam por ela várias vezes, e cada leitura
     * recarregava as linhas e os movimentos da versão inteira.
     */
    protected function currentReview(): ?SalesBoardBuilderReview
    {
        if ($this->currentReviewResolved) {
            return $this->currentReviewMemo;
        }

        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        $query = SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->with(['baseline.lines', 'baseline.movements', 'sections', 'divergences', 'attachments']);

        $this->currentReviewMemo = $this->review !== null
            ? $query->whereKey($this->review)->first()
            : $query->orderByRaw("CASE WHEN status = 'em_andamento' THEN 0 ELSE 1 END")
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
            : app(SalesBoardBuilderReviewWorkspaceBuilder::class)->build($review);

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
     * A resposta da construtora desta rodada, como a seção "Envio" a mostra --
     * ou `null` enquanto a rodada não foi enviada.
     */
    protected function builderResponse(): ?SalesBoardBuilderResponseView
    {
        $review = $this->currentReview();

        return ($review === null) || ($review->submitted_at === null)
            ? null
            : SalesBoardBuilderResponseView::fromReview($review);
    }

    /**
     * A seção que a tela mostra aberta: a pedida na URL, se for uma seção, ou
     * a primeira pendente na ordem da revisão -- e, com tudo respondido, a
     * primeira.
     */
    protected function openSectionFor(WorkspaceData $workspace): SectionEnum
    {
        $requested = SectionEnum::tryFrom((string) $this->openSection);

        if (($requested !== null) && ($workspace->section($requested) !== null)) {
            return $requested;
        }

        return $this->firstPendingSection($workspace) ?? SectionEnum::ordered()[0];
    }

    /**
     * A primeira seção pendente, começando depois de `$after` e dando a volta
     * -- é o que "avançar para a próxima pendente" quer dizer. A própria
     * `$after` fica de fora: ela acabou de ser respondida.
     */
    protected function firstPendingSection(WorkspaceData $workspace, ?SectionEnum $after = null): ?SectionEnum
    {
        $ordered = SectionEnum::ordered();

        if ($after !== null) {
            $position = (int) array_search($after, $ordered, true);
            $ordered = [...array_slice($ordered, $position + 1), ...array_slice($ordered, 0, $position)];
        }

        foreach ($ordered as $section) {
            $data = $workspace->section($section);

            if (($data !== null) && ! $data->status->isResolved()) {
                return $section;
            }
        }

        return null;
    }

    /**
     * As linhas da seção aberta que esta página mostra, depois da busca.
     *
     * @return array{rows: list<SalesBoardBuilderWorkspaceUnitRow|SalesBoardBuilderWorkspaceMovementRow>, total: int, from: int, to: int, page: int, pages: int, searchable: bool}
     */
    protected function visibleRowsOf(SalesBoardBuilderWorkspaceSection $section): array
    {
        $needle = self::normalizeSearch($this->sectionSearch);

        $rows = $needle === ''
            ? $section->rows
            : array_values(array_filter(
                $section->rows,
                fn (SalesBoardBuilderWorkspaceUnitRow|SalesBoardBuilderWorkspaceMovementRow $row): bool => str_contains(self::normalizeSearch($row->displayName()), $needle)
                    || str_contains(self::normalizeSearch((string) $row->contractCode), $needle),
            ));

        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::ROWS_PER_PAGE));
        $page = min(max(1, $this->sectionPage), $pages);
        $offset = ($page - 1) * self::ROWS_PER_PAGE;
        $visible = array_slice($rows, $offset, self::ROWS_PER_PAGE);

        return [
            'rows' => $visible,
            'total' => $total,
            'from' => $visible === [] ? 0 : $offset + 1,
            'to' => $offset + count($visible),
            'page' => $page,
            'pages' => $pages,
            'searchable' => count($section->rows) > self::SEARCHABLE_FROM_ROWS,
        ];
    }

    /**
     * Abre outra seção, na primeira página e sem busca.
     */
    public function showSection(string $section): void
    {
        $this->openSection = SectionEnum::tryFrom($section)?->value;
        $this->sectionPage = 1;
        $this->sectionSearch = '';
    }

    /**
     * A página seguinte, sem passar da última: a resposta lê o mesmo
     * workspace, então a conta não custa uma leitura a mais.
     */
    public function nextSectionPage(): void
    {
        $workspace = $this->workspace();
        $section = $workspace?->section($this->openSectionFor($workspace));

        $this->sectionPage = $section === null
            ? 1
            : min($this->sectionPage + 1, $this->visibleRowsOf($section)['pages']);
    }

    public function previousSectionPage(): void
    {
        $this->sectionPage = max(1, $this->sectionPage - 1);
    }

    /**
     * Uma busca nova começa da primeira página.
     */
    public function updatedSectionSearch(): void
    {
        $this->sectionPage = 1;
    }

    /**
     * Depois de confirmar uma seção, a tela abre a próxima pendente e rola
     * até ela: com sete seções e centenas de linhas, voltar ao topo e procurar
     * a seguinte era metade do trabalho.
     *
     * A próxima é procurada no workspace que a ação já tinha lido: só a seção
     * confirmada mudou, e ela é a única que fica de fora da busca. Reler a
     * revisão aqui seria uma terceira leitura na mesma requisição -- a
     * resposta já relê tudo depois da ação.
     */
    protected function advanceAfterConfirming(SectionEnum $confirmed, ?WorkspaceData $workspace): void
    {
        $next = $workspace === null ? null : $this->firstPendingSection($workspace, $confirmed);

        if ($next === null) {
            return;
        }

        $this->showSection($next->value);

        $this->js(sprintf(
            "document.getElementById('secao-%s')?.scrollIntoView({ behavior: 'smooth', block: 'start' })",
            $next->value,
        ));
    }

    private static function normalizeSearch(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    /**
     * @return list<SalesBoardBuilderReview>
     */
    protected function attempts(): array
    {
        /** @var SalesBoardCycle $cycle */
        $cycle = $this->getRecord();

        return SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->orderByDesc('attempt')
            ->get()
            ->all();
    }

    /**
     * O motivo pelo qual a Gestão devolveu a rodada anterior.
     *
     * Só aparece enquanto a rodada exibida é a que nasceu da devolução: uma vez
     * enviada, a construtora já respondeu ao pedido, e continuar mostrando-o
     * faria a tela parecer que ainda há algo pendente. Nenhuma divergência
     * antiga é copiada -- o que atravessa é a pergunta, não a resposta.
     *
     * "Nasceu da devolução" é a regra de
     * {@see SalesBoardBuilderReview::originatingReturn()}: a rodada seguinte à
     * devolvida, sobre o mesmo quadro. Depois de um recálculo material o pedido
     * falava de outra versão, e mostrá-lo confundiria quem confere a nova.
     */
    protected function returnReason(): ?string
    {
        $review = $this->currentReview();

        if (($review === null) || ! $review->isEditable()) {
            return null;
        }

        return $review->originatingReturn()?->return_reason;
    }

    public function canEdit(): bool
    {
        return SalesBoardCycleResource::canRecalculate()
            && ($this->currentReview()?->isEditable() ?? false);
    }

    /**
     * A rodada está aberta, mas quem está na tela não opera a competência. A
     * tela diz a quem pedir em vez de simplesmente não mostrar botão -- o
     * espelho do aviso da Gestão na tela da análise.
     */
    protected function awaitsOperator(WorkspaceData $workspace): bool
    {
        return ! SalesBoardCycleResource::canRecalculate()
            && ($workspace->status === SalesBoardBuilderReviewStatus::Draft);
    }

    /**
     * Quem está na tela, na forma que o domínio da revisão conhece.
     *
     * O editor e o envio autorizam por esta identidade, e não pela sessão: o
     * domínio não chama `Auth::user()`. Sem usuário interno identificado não há
     * em nome de quem gravar.
     */
    protected function reviewer(): BuilderReviewerIdentity
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return BuilderReviewerIdentity::forInternalUser($user);
    }

    public function confirmSectionAction(): Action
    {
        return Action::make('confirmSection')
            ->label('Confirmar seção')
            ->icon('heroicon-o-check')
            ->color('success')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Confirmar esta seção')
            ->modalDescription('Você está declarando que as informações desta seção conferem com os registros da construtora.')
            ->modalSubmitActionLabel('Confirmar')
            ->visible(fn (): bool => SalesBoardCycleResource::canRecalculate())
            ->schema([
                Textarea::make('comment')
                    ->label('Observação (opcional)')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->run(function () use ($arguments, $data): void {
                    $section = $this->section((int) $arguments['section']);
                    $workspace = $this->workspace();

                    app(SalesBoardBuilderReviewEditor::class)->confirmSection(
                        $section,
                        $this->reviewer(),
                        $data['comment'] ?? null,
                    );

                    Notification::make()->title('Seção confirmada')->success()->send();

                    $this->advanceAfterConfirming($section->section, $workspace);
                });
            });
    }

    public function reopenSectionAction(): Action
    {
        return Action::make('reopenSection')
            ->label('Desfazer confirmação')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->size('sm')
            /**
             * Sem modal, a ação executa já no mount: a visibilidade é o que
             * impede um mount forjado de desfazer a confirmação.
             */
            ->visible(fn (): bool => SalesBoardCycleResource::canRecalculate())
            ->action(function (array $arguments): void {
                $this->run(function () use ($arguments): void {
                    app(SalesBoardBuilderReviewEditor::class)->reopenSection(
                        $this->section((int) $arguments['section']),
                        $this->reviewer(),
                    );

                    Notification::make()->title('Confirmação desfeita')->success()->send();
                });
            });
    }

    public function declareDivergenceAction(): Action
    {
        return Action::make('declareDivergence')
            ->label('Apontar divergência')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->size('sm')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Apontar divergência')
            ->modalDescription('A posição apresentada não é alterada. A divergência é registrada como declaração da construtora e será analisada pela Gestão.')
            ->modalSubmitActionLabel('Registrar divergência')
            ->visible(fn (): bool => SalesBoardCycleResource::canRecalculate())
            ->schema(fn (array $arguments): array => $this->divergenceForm((int) $arguments['section']))
            ->action(function (array $arguments, array $data): void {
                $this->run(function () use ($arguments, $data): void {
                    app(SalesBoardBuilderReviewEditor::class)->addDivergence(
                        $this->section((int) $arguments['section']),
                        $this->reviewer(),
                        SalesBoardBuilderDivergenceInput::fromArray($data),
                    );

                    Notification::make()->title('Divergência registrada')->success()->send();
                });
            });
    }

    public function removeDivergenceAction(): Action
    {
        return Action::make('removeDivergence')
            ->label('Remover')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Remover esta divergência')
            ->modalDescription('A seção voltará a "pendente de validação" e precisará ser respondida novamente.')
            ->visible(fn (): bool => SalesBoardCycleResource::canRecalculate())
            ->action(function (array $arguments): void {
                $this->run(function () use ($arguments): void {
                    $divergence = SalesBoardBuilderDivergence::query()->findOrFail($arguments['divergence']);

                    abort_unless(
                        (int) $divergence->sales_board_builder_review_id === (int) $this->currentReview()?->getKey(),
                        403,
                    );

                    app(SalesBoardBuilderReviewEditor::class)->removeDivergence($divergence, $this->reviewer());

                    Notification::make()->title('Divergência removida')->success()->send();
                });
            });
    }

    /**
     * "Enviar validação": o registro interno da resposta da construtora.
     *
     * A construtora ainda não tem acesso externo: a posição vai a ela pelo
     * canal combinado, e quem envia daqui registra a resposta dela -- quem
     * respondeu, o canal, quando chegou e os arquivos. O serviço exige tudo
     * isso de novo; o formulário só diz antes o que falta.
     */
    public function submitReviewAction(): Action
    {
        return Action::make('submitReview')
            ->label('Enviar validação')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Enviar a validação para análise')
            ->modalDescription('Registre a resposta da construtora e anexe-a. Depois de enviada, a validação não pode mais ser alterada: correções posteriores exigem uma nova rodada.')
            ->modalSubmitActionLabel('Enviar validação')
            ->visible(fn (): bool => SalesBoardCycleResource::canRecalculate())
            ->schema(fn (): array => $this->submissionForm())
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    $review = $this->currentReview();

                    app(SalesBoardBuilderReviewSubmissionService::class)->submit(
                        $review,
                        $this->reviewer(),
                        $data['overall_comment'] ?? null,
                        BuilderResponseEvidence::fromFormState($data),
                    );

                    /**
                     * O envio leva o ciclo à Gestão numa instância do serviço. Sem
                     * recarregar, a próxima ação desta mesma resposta leria a
                     * situação anterior e diria que a competência voltou à
                     * construtora.
                     */
                    $this->getRecord()->refresh();

                    Notification::make()
                        ->title('Validação enviada')
                        ->body('A competência seguiu para análise da Gestão.')
                        ->success()
                        ->send();
                });
            });
    }

    /**
     * O formulário do envio: a resposta da construtora, as observações e a
     * declaração.
     *
     * A data é dia de negócio, entre a data da posição e hoje, com instância à
     * meia-noite como padrão -- um texto ganharia a hora atual no seletor. Os
     * arquivos ficam no upload temporário até o serviço varrê-los e gravá-los
     * no disco privado.
     *
     * @return list<Component>
     */
    protected function submissionForm(): array
    {
        $today = CarbonImmutable::parse(BusinessTime::dateString());
        $positionDate = $this->workspace()?->positionDate ?? $today;
        $maxKilobytes = SalesBoardBuilderResponseEvidenceStore::maxKilobytes();
        $maxFiles = SalesBoardBuilderResponseEvidenceStore::maxFiles();

        return [
            Section::make('Resposta da construtora')
                ->description('O Nimbus ainda não tem acesso externo para a construtora: a posição foi a ela pelo canal combinado, e aqui fica registrada a resposta dela, com o anexo.')
                ->compact()
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    TextInput::make('builder_respondent_name')
                        ->label('Quem respondeu pela construtora')
                        ->required()
                        ->minLength(SalesBoardBuilderReviewSubmissionService::MINIMUM_RESPONDENT_NAME_LENGTH)
                        ->maxLength(SalesBoardBuilderReviewSubmissionService::MAXIMUM_RESPONDENT_LENGTH)
                        ->validationMessages([
                            'required' => 'Informe quem respondeu pela construtora.',
                            'min' => 'Informe o nome com pelo menos 3 caracteres.',
                        ]),

                    TextInput::make('builder_respondent_email')
                        ->label('E-mail de quem respondeu')
                        ->email()
                        ->required()
                        ->maxLength(SalesBoardBuilderReviewSubmissionService::MAXIMUM_RESPONDENT_LENGTH)
                        ->validationMessages([
                            'required' => 'Informe o e-mail de quem respondeu pela construtora.',
                            'email' => 'Informe um e-mail válido.',
                        ]),

                    Select::make('builder_response_channel')
                        ->label('Canal da resposta')
                        ->options(SalesBoardBuilderResponseChannel::options())
                        ->native(false)
                        ->live()
                        ->required()
                        ->validationMessages(['required' => 'Informe por qual canal a resposta chegou.']),

                    DatePicker::make('builder_response_received_on')
                        ->label('Recebida em')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default($today->greaterThan($positionDate) ? $today : $positionDate)
                        ->minDate($positionDate)
                        ->maxDate($today)
                        ->required()
                        ->validationMessages([
                            'required' => 'Informe a data em que a resposta chegou.',
                            'after_or_equal' => sprintf('A resposta não pode ser anterior à data da posição, %s.', $positionDate->format('d/m/Y')),
                            'before_or_equal' => sprintf('A resposta não pode ter chegado depois de hoje, %s.', $today->format('d/m/Y')),
                        ]),

                    FileUpload::make('builder_response_attachments')
                        ->label('Arquivos da resposta')
                        ->helperText(sprintf(
                            'De 1 a %d arquivos: o e-mail salvo em PDF, a planilha, a ata ou o ofício. PDF, DOC, DOCX, XLS, XLSX, PNG ou JPG, até %d MB cada.',
                            $maxFiles,
                            (int) ceil($maxKilobytes / 1024),
                        ))
                        ->multiple()
                        ->minFiles(1)
                        ->maxFiles($maxFiles)
                        ->storeFiles(false)
                        ->acceptedFileTypes(SalesBoardBuilderResponseEvidenceStore::allowedMimes())
                        ->maxSize($maxKilobytes)
                        ->previewable(false)
                        ->required()
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Anexe pelo menos um arquivo com a resposta da construtora.',
                            'min' => 'Anexe pelo menos um arquivo com a resposta da construtora.',
                            'max' => sprintf('Anexe no máximo %d arquivos.', $maxFiles),
                        ]),
                ]),

            Textarea::make('overall_comment')
                ->label(fn (Get $get): string => SalesBoardBuilderResponseChannel::tryFrom((string) $get('builder_response_channel'))?->requiresComment()
                    ? 'Observações gerais (descreva o canal da resposta)'
                    : 'Observações gerais (opcional)')
                ->required(fn (Get $get): bool => SalesBoardBuilderResponseChannel::tryFrom((string) $get('builder_response_channel'))?->requiresComment() ?? false)
                ->rows(3)
                ->maxLength(2000)
                ->validationMessages(['required' => 'Com "Outro canal", descreva por onde a resposta chegou.']),

            Checkbox::make('declaration')
                ->label('Confirmo que registrei fielmente a resposta da construtora, anexada a esta validação, e que as divergências registradas representam os pontos identificados por ela.')
                ->accepted()
                ->required()
                ->validationMessages(['accepted' => 'É necessário confirmar a declaração para enviar.']),
        ];
    }

    /**
     * Os campos que cada tipo de divergência exige, montados a partir das regras
     * do próprio tipo -- e não de uma lista repetida aqui.
     *
     * @return list<Component>
     */
    protected function divergenceForm(int $sectionId): array
    {
        $section = $this->section($sectionId);
        $workspace = $this->workspace()?->section($section->section);

        $types = array_values(array_filter(
            SalesBoardBuilderDivergenceType::cases(),
            fn (SalesBoardBuilderDivergenceType $type): bool => in_array($section->section, $type->sections(), true),
        ));

        $anchors = collect($workspace?->rows ?? [])
            ->mapWithKeys(fn ($row): array => $section->section->isPosition()
                ? [$row->lineId => $row->displayName().($row->contractCode === null ? '' : ' · '.$row->contractCode)]
                : [$row->movementId => $row->displayName().' · '.($row->contractCode ?? '—')])
            ->all();

        $needs = fn (string $field): callable => fn (Get $get): bool => ($type = SalesBoardBuilderDivergenceType::tryFrom((string) $get('type'))) !== null
            && (in_array($field, $type->requiredDeclarations(), true) || in_array($field, $type->requiredAnyDeclarations(), true));

        return [
            Select::make('type')
                ->label('Tipo de divergência')
                ->options(collect($types)->mapWithKeys(fn (SalesBoardBuilderDivergenceType $type): array => [
                    $type->value => $type->label(),
                ])->all())
                ->default($types[0]->value ?? null)
                ->live()
                ->required(),

            Select::make($section->section->isPosition() ? 'sales_board_cycle_line_id' : 'sales_board_cycle_movement_id')
                ->label($section->section->isPosition() ? 'Unidade' : 'Lançamento')
                ->options($anchors)
                ->searchable()
                ->visible(fn (Get $get): bool => ($type = SalesBoardBuilderDivergenceType::tryFrom((string) $get('type'))) !== null
                    && ($type->requiresLine() || $type->requiresAnyAnchor() || $type->requiredMovementType() !== null)),

            TextInput::make('declared_block')
                ->label('Bloco')
                ->maxLength(50)
                ->visible($needs('declared_unit')),

            TextInput::make('declared_unit')
                ->label('Unidade')
                ->maxLength(50)
                ->required($needs('declared_unit'))
                ->visible($needs('declared_unit')),

            TextInput::make('declared_contract_code')
                ->label('Contrato informado pela construtora')
                ->maxLength(100)
                ->required($needs('declared_contract_code'))
                ->visible(fn (Get $get): bool => in_array(
                    SalesBoardBuilderDivergenceType::tryFrom((string) $get('type')),
                    [SalesBoardBuilderDivergenceType::SaleMissing, SalesBoardBuilderDivergenceType::SaleContractMismatch],
                    true,
                )),

            TextInput::make('declared_value')
                ->label('Valor informado pela construtora')
                ->prefix('R$')
                ->mask(RawJs::make('$money($input, \',\', \'.\')'))
                ->required($needs('declared_value'))
                ->visible($needs('declared_value')),

            DatePicker::make('declared_date')
                ->label('Data informada pela construtora')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->required($needs('declared_date'))
                ->visible($needs('declared_date')),

            Select::make('declared_classification')
                ->label('Situação correta segundo a construtora')
                ->options(collect(SalesBoardUnitClassification::resolvedCases())
                    ->mapWithKeys(fn (SalesBoardUnitClassification $case): array => [$case->value => $case->label()])
                    ->all())
                ->required($needs('declared_classification'))
                ->visible($needs('declared_classification')),

            Textarea::make('reason')
                ->label('Motivo')
                ->helperText('Descreva o que a construtora identificou. Este texto é o que a Gestão vai analisar.')
                ->rows(3)
                ->required()
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
    }

    protected function section(int $sectionId): SalesBoardBuilderReviewSection
    {
        $review = $this->currentReview();

        $section = SalesBoardBuilderReviewSection::query()->findOrFail($sectionId);

        abort_unless((int) $section->sales_board_builder_review_id === (int) $review?->getKey(), 403);

        return $section;
    }

    /**
     * Recusas do domínio viram mensagem, não erro.
     *
     * Todas elas são situações previsíveis -- a versão mudou, a seção tem
     * divergência, a validação já foi enviada -- e quem está na tela precisa
     * saber o que fazer, não ver um stack trace.
     *
     * A recusa de autorização não é capturada e vira 403, como nas telas da
     * Gestão: com as ações ocultas a quem não opera, só uma chamada forjada ou
     * uma permissão revogada no meio do clique chegam até ela.
     */
    protected function run(callable $callback): void
    {
        try {
            $callback();
        } catch (SalesBoardBuilderReviewException $exception) {
            Notification::make()
                ->title('Não foi possível concluir')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            /**
             * A resposta desta mesma requisição relê o que a ação gravou: o
             * memo da revisão e do workspace é de antes dela.
             */
            $this->forgetMemos();
        }
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }
}
