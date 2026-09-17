<?php

namespace App\Filament\Resources\SalesBoardRollouts\Pages;

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Exceptions\SalesBoardRolloutException;
use App\Filament\Resources\SalesBoardRollouts\SalesBoardRolloutResource;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use App\Services\SalesBoards\DatabaseSalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use App\Services\SalesBoards\SalesBoardRolloutRecipientDirectory;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;

/**
 * A tela em que uma Emissão é homologada e ativada.
 *
 * Tudo o que ela mostra vem do retrato congelado na homologação -- comparação,
 * prontidão, delta -- e não de uma releitura da fonte no momento do render.
 * A única leitura viva é o portão, que precisa dizer se a homologação ainda
 * descreve o mundo.
 *
 * Não existe aqui nenhum caminho para editar posição, aprovar quadro ou publicar
 * competência. O que se decide nesta tela é qual workflow produz os próximos
 * quadros -- e nada além disso.
 */
class ManageSalesBoardRollout extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SalesBoardRolloutResource::class;

    protected string $view = 'filament.resources.sales-board-rollouts.pages.manage-sales-board-rollout';

    protected static ?string $title = 'Rollout do Quadro de Vendas';

    protected static ?string $breadcrumb = 'Rollout';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-sales-board-rollout-manage-page',
    ];

    /**
     * A homologação aprovada cuja ativação acabou de ser recusada por não
     * descrever mais a fonte ou o escopo.
     *
     * Só feedback de tela, e só enquanto a página está aberta: a homologação
     * continua "aprovada" no banco -- ela não é reescrita --, e quem decide de
     * novo, a cada tentativa, é o serviço de ativação. Travada contra alteração
     * pelo navegador porque desabilita um botão.
     */
    #[Locked]
    public ?int $outdatedHomologationId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(SalesBoardRolloutResource::canView($this->record), 403);
    }

    public function emission(): Emission
    {
        /** @var Emission $emission */
        $emission = $this->getRecord();

        return $emission;
    }

    public function getSubheading(): ?string
    {
        $emission = $this->emission();

        return sprintf(
            '%s · %s',
            $emission->sales_board_source->label(),
            $emission->usesAutomatedSalesBoard()
                ? 'desde a competência '.($emission->sales_board_automation_start_reference_month?->format('m/Y') ?? '—')
                : $emission->sales_board_source->description(),
        );
    }

    public function currentHomologation(): ?SalesBoardRolloutHomologation
    {
        return $this->emission()
            ->salesBoardRolloutHomologations()
            ->with(['constructions.construction', 'approvedBy'])
            ->first();
    }

    /**
     * @return array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>}|null
     */
    public function gate(): ?array
    {
        $homologation = $this->currentHomologation();

        return $homologation === null
            ? null
            : app(SalesBoardRolloutHomologationService::class)->gate($homologation, $this->emission());
    }

    public function hasScopeDrift(): bool
    {
        return app(DatabaseSalesBoardAutomationEligibilityProvider::class)
            ->hasScopeDrift($this->emission());
    }

    public function globalAutomationEnabled(): bool
    {
        return SalesBoardAutomationConfig::enabled();
    }

    /**
     * "O que faço agora?" para o rollout desta Emissão.
     *
     * Recebe o que a tela já carregou -- homologação, portão e escopo -- em vez
     * de reler. Não decide nada: aprovar continua sendo do portão, e ativar
     * continua sendo do serviço de ativação, que reconfere tudo sob lock.
     *
     * @param  array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>}|null  $gate
     * @return array{headline: string, detail: string|null, color: string, icon: string, items?: list<string>}
     */
    public function nextAction(?SalesBoardRolloutHomologation $homologation, ?array $gate, bool $scopeDrift): array
    {
        $emission = $this->emission();

        if ($emission->usesAutomatedSalesBoard()) {
            return match (true) {
                $scopeDrift => [
                    'headline' => 'O escopo da Emissão mudou. É necessária nova homologação.',
                    'detail' => 'Os empreendimentos da Emissão não são mais os homologados, e a automação está suspensa para a Emissão inteira. '
                        .'Para retomá-la, retorne ao modo legado e abra uma nova homologação que cubra os empreendimentos atuais.',
                    'color' => 'danger',
                    'icon' => 'heroicon-o-pause-circle',
                ],
                ! $this->globalAutomationEnabled() => [
                    'headline' => 'A Emissão está configurada para automação, mas o interruptor global está desligado.',
                    'detail' => 'Nenhuma competência será processada até que a automação global seja ligada.',
                    'color' => 'warning',
                    'icon' => 'heroicon-o-power',
                ],
                default => [
                    'headline' => 'Automação ativa.',
                    'detail' => sprintf(
                        'As competências a partir de %s são apuradas pelo agendador. Acompanhe em “Automação do Quadro” e conduza cada competência em “Ciclos do Quadro”.',
                        $emission->sales_board_automation_start_reference_month?->format('m/Y') ?? '—',
                    ),
                    'color' => 'success',
                    'icon' => 'heroicon-o-check-circle',
                ],
            };
        }

        $openNew = [
            'headline' => 'Abra uma homologação para preparar a automação.',
            'color' => 'info',
            'icon' => 'heroicon-o-clipboard-document-check',
        ];

        if ($homologation === null) {
            return [...$openNew, 'detail' => 'Nenhuma homologação foi aberta para esta Emissão.'];
        }

        if ($homologation->isEditable()) {
            return ($gate['ready'] ?? false)
                ? [
                    'headline' => 'Aprove ou rejeite a homologação.',
                    'detail' => 'Todos os itens do portão estão atendidos. Aprovar não ativa a automação: a ativação é um passo à parte.',
                    'color' => 'info',
                    'icon' => 'heroicon-o-check-badge',
                ]
                : [
                    'headline' => 'Conclua os itens pendentes da homologação.',
                    'detail' => 'O que ainda impede a aprovação:',
                    'color' => 'warning',
                    'icon' => 'heroicon-o-clipboard-document-check',
                    'items' => $this->failedGateChecks($gate),
                ];
        }

        if ($homologation->isApproved() && ! $homologation->wasActivated()) {
            return ($this->outdatedHomologationId === (int) $homologation->getKey())
                ? [
                    'headline' => 'Esta homologação não representa mais o estado atual das fontes.',
                    'detail' => 'A ativação foi recusada. Abra uma nova homologação antes de ativar.',
                    'color' => 'danger',
                    'icon' => 'heroicon-o-exclamation-triangle',
                ]
                : [
                    'headline' => 'A homologação está aprovada. Ative a automação quando for o momento.',
                    'detail' => 'A validade será reavaliada ao ativar: se a fonte, o escopo ou os responsáveis tiverem mudado, a ativação é recusada.',
                    'color' => 'info',
                    'icon' => 'heroicon-o-rocket-launch',
                ];
        }

        return [...$openNew, 'detail' => match (true) {
            $homologation->wasActivated() => 'A última homologação já foi usada numa ativação. Reativar exige uma nova homologação.',
            $homologation->status === SalesBoardRolloutHomologationStatus::Rejected => 'A última homologação foi rejeitada.',
            default => 'A última homologação foi substituída porque a fonte mudou.',
        }];
    }

    /**
     * Os itens do portão que não passaram, como o serviço os descreve.
     *
     * @param  array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>}|null  $gate
     * @return list<string>
     */
    public function failedGateChecks(?array $gate): array
    {
        return collect($gate['checks'] ?? [])
            ->reject(fn (array $check): bool => $check['passed'])
            ->map(fn (array $check): string => $check['detail'] === null
                ? $check['label']
                : $check['label'].' — '.$check['detail'])
            ->values()
            ->all();
    }

    /**
     * Situação da última homologação, sem carregar empreendimentos: é o que os
     * botões precisam para decidir se aparecem.
     */
    protected function latestHomologationState(): ?SalesBoardRolloutHomologation
    {
        return $this->emission()
            ->salesBoardRolloutHomologations()
            ->first(['id', 'emission_id', 'attempt', 'status', 'activated_at']);
    }

    /**
     * Por que "Ativar automação" está à vista mas indisponível.
     */
    protected function activationBlockedReason(): ?string
    {
        $homologation = $this->latestHomologationState();

        return match (true) {
            $homologation === null => null,
            $homologation->isEditable() => 'A homologação precisa estar aprovada. A validade dela é reavaliada no momento da ativação.',
            $this->outdatedHomologationId === (int) $homologation->getKey() => 'Esta homologação não representa mais o estado atual das fontes. Abra uma nova homologação antes de ativar.',
            default => null,
        };
    }

    /**
     * @return array<string, list<SalesBoardRolloutRecipient>>
     */
    public function recipients(): array
    {
        $grouped = [];

        foreach (SalesBoardRolloutRecipientRole::cases() as $role) {
            $grouped[$role->value] = SalesBoardRolloutRecipient::query()
                ->where('emission_id', $this->emission()->getKey())
                ->forRole($role)
                ->with('user')
                ->get()
                ->all();
        }

        return $grouped;
    }

    public function canManage(): bool
    {
        return SalesBoardRolloutResource::canManageRollout();
    }

    public function openHomologationAction(): Action
    {
        return Action::make('openHomologation')
            ->label(fn (): string => $this->latestHomologationState() === null ? 'Abrir homologação' : 'Abrir nova homologação')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('primary')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Abrir a homologação desta Emissão')
            ->modalDescription('A homologação compara a posição do legado com a que o motor novo produz, em cada empreendimento. Nada é ativado nem publicado ao abrir.')
            ->modalSubmitActionLabel('Abrir e avaliar')
            ->visible(fn (): bool => $this->canManage()
                && ! $this->emission()->usesAutomatedSalesBoard()
                && ($this->currentHomologation()?->isEditable() !== true))
            ->schema([
                DatePicker::make('start_reference_month')
                    ->label('Competência inicial da automação')
                    ->helperText('A primeira competência que o motor automático vai produzir. Precisa ser posterior a qualquer Quadro de Vendas já registrado.')
                    ->displayFormat('m/Y')
                    ->native(false)
                    ->required(),

                Toggle::make('auto_open_builder_review')
                    ->label('Abrir a validação da construtora automaticamente')
                    ->helperText('Cria a validação interna em rascunho assim que a competência é apurada. Não envia nada à construtora: o canal externo ainda não existe.')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    app(SalesBoardRolloutHomologationService::class)->open(
                        $this->emission(),
                        CarbonImmutable::parse((string) $data['start_reference_month'])->startOfMonth(),
                        auth()->user(),
                        (bool) ($data['auto_open_builder_review'] ?? false),
                    );

                    Notification::make()->title('Homologação aberta e avaliada')->success()->send();
                });
            });
    }

    public function reassessAction(): Action
    {
        return Action::make('reassess')
            ->label('Reavaliar')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => $this->canManage() && ($this->currentHomologation()?->isEditable() ?? false))
            ->action(function (): void {
                $this->run(function (): void {
                    app(SalesBoardRolloutHomologationService::class)->reassess($this->currentHomologation());

                    Notification::make()
                        ->title('Homologação reavaliada')
                        ->body('Diferenças cuja fonte mudou voltaram a exigir análise.')
                        ->success()
                        ->send();
                });
            });
    }

    public function acceptDifferenceAction(): Action
    {
        return Action::make('acceptDifference')
            ->label('Analisar diferença')
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->size('sm')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Registrar a análise da diferença')
            ->modalDescription('Isto não aprova nenhum quadro: registra que a diferença entre o legado e a apuração foi entendida.')
            ->modalSubmitActionLabel('Registrar análise')
            ->schema([
                Textarea::make('reason')
                    ->label('O que explica a diferença')
                    ->required()
                    ->minLength(SalesBoardRolloutHomologationService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardRolloutHomologationService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->run(function () use ($arguments, $data): void {
                    app(SalesBoardRolloutHomologationService::class)->acceptDifference(
                        $this->constructionRow((int) $arguments['row']),
                        (string) $data['reason'],
                        auth()->user(),
                    );

                    Notification::make()->title('Análise registrada')->success()->send();
                });
            });
    }

    public function markGuaranteesReviewedAction(): Action
    {
        return Action::make('markGuaranteesReviewed')
            ->label('Marcar impacto sobre Garantias como revisado')
            ->icon('heroicon-o-shield-check')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Confirmar a revisão do impacto sobre as Garantias')
            ->modalDescription('O Nimbus não simula o resultado das Garantias: o que está registrado é que a Gestão revisou os deltas apresentados.')
            ->visible(fn (): bool => $this->canManage() && ($this->currentHomologation()?->isEditable() ?? false))
            ->action(function (): void {
                $this->run(function (): void {
                    app(SalesBoardRolloutHomologationService::class)
                        ->markGuaranteesReviewed($this->currentHomologation(), auth()->user());

                    Notification::make()->title('Impacto sobre Garantias revisado')->success()->send();
                });
            });
    }

    public function markMonthlyReportReviewedAction(): Action
    {
        return Action::make('markMonthlyReportReviewed')
            ->label('Marcar impacto sobre o Relatório Mensal como revisado')
            ->icon('heroicon-o-document-chart-bar')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Confirmar a revisão do impacto sobre o Relatório Mensal')
            ->modalDescription('O Nimbus não gera um relatório de prévia: o que está registrado é que a Gestão revisou os deltas apresentados.')
            ->visible(fn (): bool => $this->canManage() && ($this->currentHomologation()?->isEditable() ?? false))
            ->action(function (): void {
                $this->run(function (): void {
                    app(SalesBoardRolloutHomologationService::class)
                        ->markMonthlyReportReviewed($this->currentHomologation(), auth()->user());

                    Notification::make()->title('Impacto sobre o Relatório revisado')->success()->send();
                });
            });
    }

    public function addRecipientAction(): Action
    {
        return Action::make('addRecipient')
            ->label('Adicionar responsável')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->size('sm')
            ->modalHeading('Adicionar responsável')
            ->modalDescription('Ser responsável define para quem os avisos da automação vão. Não concede permissão nenhuma.')
            ->visible(fn (): bool => $this->canManage())
            ->schema(fn (array $arguments): array => [
                Select::make('user_id')
                    ->label(SalesBoardRolloutRecipientRole::from((string) $arguments['role'])->label())
                    ->options(fn (): array => User::query()
                        ->operational()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->run(function () use ($arguments, $data): void {
                    app(SalesBoardRolloutRecipientDirectory::class)->add(
                        $this->emission(),
                        SalesBoardRolloutRecipientRole::from((string) $arguments['role']),
                        User::query()->findOrFail($data['user_id']),
                        auth()->user(),
                    );

                    Notification::make()->title('Responsável adicionado')->success()->send();
                });
            });
    }

    public function removeRecipientAction(): Action
    {
        return Action::make('removeRecipient')
            ->label('Remover')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManage())
            ->action(function (array $arguments): void {
                $recipient = SalesBoardRolloutRecipient::query()->findOrFail($arguments['recipient']);

                abort_unless((int) $recipient->emission_id === (int) $this->emission()->getKey(), 403);

                app(SalesBoardRolloutRecipientDirectory::class)->remove($recipient);

                Notification::make()->title('Responsável removido')->success()->send();
            });
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar homologação')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Aprovar a homologação')
            ->modalDescription('Aprovar não ativa a automação: é o registro de que os fatos foram revisados. A ativação é um passo à parte.')
            ->modalSubmitActionLabel('Aprovar')
            ->visible(fn (): bool => $this->canManage()
                && ($this->currentHomologation()?->isEditable() ?? false)
                && ($this->gate()['ready'] ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label('Registro da aprovação')
                    ->helperText('O que sustenta a decisão. É o que a auditoria vai ler.')
                    ->required()
                    ->minLength(SalesBoardRolloutHomologationService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardRolloutHomologationService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    app(SalesBoardRolloutHomologationService::class)
                        ->approve($this->currentHomologation(), auth()->user(), (string) $data['reason']);

                    Notification::make()
                        ->title('Homologação aprovada')
                        ->body('A Emissão continua em modo legado até a ativação.')
                        ->success()
                        ->send();
                });
            });
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rejeitar homologação')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Rejeitar a homologação')
            ->modalSubmitActionLabel('Rejeitar')
            ->visible(fn (): bool => $this->canManage() && ($this->currentHomologation()?->isEditable() ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da rejeição')
                    ->required()
                    ->minLength(SalesBoardRolloutHomologationService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardRolloutHomologationService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    app(SalesBoardRolloutHomologationService::class)
                        ->reject($this->currentHomologation(), auth()->user(), (string) $data['reason']);

                    Notification::make()->title('Homologação rejeitada')->success()->send();
                });
            });
    }

    public function activateAction(): Action
    {
        return Action::make('activate')
            ->label('Ativar automação')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Ativar o modo automatizado')
            ->modalDescription(fn (): string => $this->activationPreview())
            ->modalSubmitActionLabel('Ativar')
            /**
             * À vista desde o rascunho, desabilitada e dizendo o que falta: é o
             * passo que o fluxo espera, e escondê-lo faria a tela parecer não ter
             * caminho. Desabilitada, a ação nem monta -- e o serviço reconfere
             * tudo de qualquer forma.
             */
            ->visible(fn (): bool => $this->canManage()
                && ! $this->emission()->usesAutomatedSalesBoard()
                && (($homologation = $this->latestHomologationState()) !== null)
                && ($homologation->isEditable() || $homologation->isApproved())
                && ! $homologation->wasActivated())
            ->disabled(fn (): bool => $this->activationBlockedReason() !== null)
            ->tooltip(fn (): ?string => $this->activationBlockedReason())
            ->schema([
                Textarea::make('reason')
                    ->label('Registro da ativação')
                    ->required()
                    ->minLength(SalesBoardRolloutHomologationService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardRolloutHomologationService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $homologation = $this->currentHomologation();

                try {
                    app(SalesBoardRolloutActivationService::class)->activate(
                        $this->emission(),
                        $homologation,
                        auth()->user(),
                        (string) $data['reason'],
                    );
                } catch (SalesBoardRolloutException $exception) {
                    if ($this->requiresNewHomologation($exception)) {
                        $this->outdatedHomologationId = $homologation?->getKey();
                    }

                    $this->refusalNotification($exception, activating: true)->send();

                    return;
                }

                $this->refreshEmission();

                Notification::make()
                    ->title('Automação ativada')
                    ->body($this->globalAutomationEnabled()
                        ? 'Nenhuma competência foi gerada agora: a próxima execução do agendador cuida disso.'
                        : 'Nenhuma competência foi gerada agora, e nenhuma será enquanto a automação global estiver desligada.')
                    ->success()
                    ->send();
            });
    }

    public function returnToLegacyAction(): Action
    {
        return Action::make('returnToLegacy')
            ->label('Retornar ao modo legado')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Retornar esta Emissão ao modo legado')
            ->modalDescription(
                'Interrompe novas tentativas automáticas. Nada é apagado: ciclos, validações, análises, '
                    .'publicações e Quadros de Vendas já publicados permanecem, e continuam sendo lidos normalmente. '
                    .'Reativar depois exigirá uma nova homologação.'
            )
            ->modalSubmitActionLabel('Retornar ao legado')
            ->visible(fn (): bool => $this->canManage() && $this->emission()->usesAutomatedSalesBoard())
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo do retorno')
                    ->required()
                    ->minLength(SalesBoardRolloutHomologationService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardRolloutHomologationService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    app(SalesBoardRolloutActivationService::class)
                        ->returnToLegacy($this->emission(), auth()->user(), (string) $data['reason']);

                    $this->refreshEmission();

                    Notification::make()->title('Emissão retornada ao modo legado')->success()->send();
                });
            });
    }

    /**
     * O serviço grava numa instância própria, travada. A da página continua com
     * o modo de antes, e a resposta desta mesma requisição mostraria "Legado"
     * logo depois de ativar -- e mandaria abrir uma homologação nova.
     */
    protected function refreshEmission(): void
    {
        $this->emission()->refresh();
    }

    protected function activationPreview(): string
    {
        $homologation = $this->currentHomologation();

        return sprintf(
            'Modo atual: %s → Automatizado. Competência inicial: %s. Empreendimentos: %d. Abre validação: %s. Agendador global: %s.',
            $this->emission()->sales_board_source->label(),
            $homologation?->startMonthLabel() ?? '—',
            $homologation?->constructions->count() ?? 0,
            ($homologation?->auto_open_builder_review ?? false) ? 'sim' : 'não',
            $this->globalAutomationEnabled() ? 'ligado' : 'DESLIGADO — nenhuma competência será processada',
        );
    }

    protected function constructionRow(int $id): SalesBoardRolloutHomologationConstruction
    {
        $row = SalesBoardRolloutHomologationConstruction::query()->findOrFail($id);

        abort_unless(
            (int) $row->sales_board_rollout_homologation_id === (int) $this->currentHomologation()?->getKey(),
            403,
        );

        return $row;
    }

    /**
     * Recusas do domínio viram mensagem, não erro.
     */
    protected function run(callable $callback): void
    {
        try {
            $callback();
        } catch (SalesBoardRolloutException $exception) {
            $this->refusalNotification($exception)->send();
        }
    }

    /**
     * Na ativação, as duas recusas que só se resolvem com uma homologação nova
     * recebem título próprio e dizem o próximo passo antes do detalhe do
     * domínio. Fora dela a mesma recusa de escopo tem outro remédio -- um
     * rascunho se reavalia --, e a mensagem do domínio já diz qual.
     *
     * A recusa não carrega código, e a mensagem é produzida sempre pela mesma
     * fábrica estática -- comparar com ela é comparar com a própria recusa, sem
     * reescrever a regra que a produziu.
     */
    protected function refusalNotification(SalesBoardRolloutException $exception, bool $activating = false): Notification
    {
        [$title, $guidance] = match (true) {
            $activating && ($exception->getMessage() === SalesBoardRolloutException::homologationStale()->getMessage()) => [
                'Homologação desatualizada',
                'Esta homologação não representa mais o estado atual das fontes. Abra uma nova homologação antes de ativar.',
            ],
            $activating && ($exception->getMessage() === SalesBoardRolloutException::scopeChanged()->getMessage()) => [
                'Escopo da Emissão alterado',
                'O escopo da Emissão mudou desde a homologação aprovada. É necessária nova homologação.',
            ],
            default => ['Não foi possível concluir', null],
        };

        return Notification::make()
            ->title($title)
            ->body($guidance === null ? $exception->getMessage() : $guidance.' '.$exception->getMessage())
            ->danger();
    }

    protected function requiresNewHomologation(SalesBoardRolloutException $exception): bool
    {
        return in_array($exception->getMessage(), [
            SalesBoardRolloutException::homologationStale()->getMessage(),
            SalesBoardRolloutException::scopeChanged()->getMessage(),
        ], true);
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }

    public function signedMoney(int $cents): string
    {
        return ($cents > 0 ? '+' : '').$this->money($cents);
    }
}
