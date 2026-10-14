<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Domain\PuCalculator\Services\PuOperationalActivationService;
use App\Domain\PuCalculator\Services\PuOperationalPreflightService;
use App\Domain\PuCalculator\Services\PuOperationalReadinessService;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Support\PuRefreshRecoveryAction;
use App\Models\Emission;
use App\Models\User;
use App\Support\PuCalculator\PuEmissionOperationsOverview;
use App\Support\PuCalculator\PuGateMessage;
use App\Support\PuCalculator\PuIncidentGuidance;
use App\Support\PuCalculator\PuReadinessPresentation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Operação do PU de uma emissão (Fase 8, UI-4).
 *
 * Mostra o que separa "configurada" de "autorizada": o interruptor global (só
 * leitura -- é infraestrutura), a decisão de ativação da emissão, a certificação
 * do indexador, a curva oficial e a atualidade dela, incidentes e trabalho
 * pendente. As decisões (ativar, suspender, retomar) passam pelos serviços da
 * Fase 7/6, que autorizam e travam; esta página nunca liga o interruptor global
 * nem ativa uma emissão sem eles.
 */
class PuOperation extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmissionResource::class;

    protected string $view = 'filament.resources.emissions.pages.pu-operation';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-pu-operation-page',
    ];

    /**
     * Retrato memorizado por requisição: o cabeçalho e o corpo leem o mesmo.
     *
     * @var array<string, mixed>|null
     */
    private ?array $overview = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(EmissionResource::canView($this->record), 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can(AccessPermission::PuCurveView->value) ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Operação do PU';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return sprintf('%s — situação operacional, ativação, prontidão e pendências. A página não liga o interruptor global e nenhuma decisão aqui homologa curva.', $this->emission()->name);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return $this->overview ??= app(PuEmissionOperationsOverview::class)->for($this->emission());
    }

    public function obligationsUrl(): string
    {
        return PuIncidentGuidance::obligationsUrl($this->emission());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToEmission')
                ->label('Voltar para a emissão')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->outlined()
                ->url(fn (): string => EmissionResource::getUrl('edit', ['record' => $this->emission()])),
            $this->readinessAction(),
            $this->activateAction(),
            $this->suspendAction(),
            ActionGroup::make([
                PuRefreshRecoveryAction::make('retryObligationRefresh', fn (): int => (int) $this->emission()->getKey()),
                Action::make('curveHistory')
                    ->label('Histórico e auditoria da curva')
                    ->icon('heroicon-o-clock')
                    ->url(fn (): string => EmissionResource::getUrl('pu-history', ['record' => $this->emission()])),
            ])
                ->label('Mais ações')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray')
                ->button(),
        ];
    }

    private function readinessAction(): Action
    {
        return Action::make('assessReadiness')
            ->label('Avaliar prontidão')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->modalHeading('Prontidão operacional — '.$this->emission()->name)
            ->modalDescription('Avaliação somente leitura, refeita agora. Não é autorização operacional: ativar exige uma decisão registrada, e homologar continua sendo maker/checker.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (): Htmlable => view('filament.emissions.pu-readiness', $this->readinessViewData()));
    }

    private function activateAction(): Action
    {
        return Action::make('activateOperations')
            ->label(fn (): string => $this->activationStatus() === PuOperationalActivationStatus::Suspended ? 'Reativar operação do PU' : 'Ativar operação do PU')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (): bool => (auth()->user()?->can(AccessPermission::PuOperationsActivate->value) ?? false)
                && in_array($this->activationStatus(), [PuOperationalActivationStatus::Inactive, PuOperationalActivationStatus::Suspended], true))
            ->disabled(fn (): bool => $this->activationBlocker() !== null)
            ->tooltip(fn (): ?string => $this->activationBlocker())
            ->modalHeading(fn (): string => 'Ativar a operação do PU — '.$this->emission()->name)
            ->modalDescription('Ativar coloca esta emissão no pipeline governado: geração, extensão diária e homologação passam a poder rodar, e liquidações reais passam a ser registráveis. Ativar NÃO homologa curva, NÃO cria curva sozinho e NÃO dispensa maker/checker; o interruptor global continua sendo da infraestrutura.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Registrar ativação')
            ->modalContent(fn (): Htmlable => view('filament.emissions.pu-readiness', [
                ...$this->readinessViewData(withPreflight: false),
                'compact' => true,
            ]))
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da ativação')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                TextInput::make('approval_reference')
                    ->label('Referência da aprovação documentada')
                    ->helperText('Ata, chamado ou e-mail que registra a revisão contratual e a autorização do negócio para esta emissão.')
                    ->required()
                    ->maxLength(255),
                Checkbox::make('checklist_covered')
                    ->label('Confirmo que a lista de verificação contratual (Termo, prêmio, amortização extraordinária, vencimento antecipado, efeitos sem regra, integralização e validação independente) está coberta pela aprovação referenciada.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme a cobertura da lista de verificação contratual.']),
                Checkbox::make('not_homologation')
                    ->label('Entendo que ativar não homologa curva nem autoriza ignorar o maker/checker.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme que entendeu o alcance da ativação.']),
            ])
            ->action(function (array $data): void {
                try {
                    $change = app(PuOperationalActivationService::class)->activate(
                        $this->emission(),
                        $this->currentUser(),
                        (string) ($data['reason'] ?? ''),
                        (string) ($data['approval_reference'] ?? ''),
                        via: 'ui',
                    );
                } catch (AuthorizationException $exception) {
                    Notification::make()->title('Ativação não autorizada.')->body($exception->getMessage().' Nada foi gravado.')->danger()->persistent()->send();

                    return;
                } catch (PuOperationalActivationException $exception) {
                    Notification::make()->title('Ativação não registrada.')->body(PuGateMessage::withoutCode($exception->getMessage()))->danger()->persistent()->send();

                    return;
                }

                $this->overview = null;

                Notification::make()
                    ->title('Operação do PU ativada.')
                    ->body(sprintf('Decisão #%d registrada no livro de ativação (prontidão: %s). Nenhuma curva foi gerada ou homologada por esta ação.', $change->id, (string) $change->readiness_status))
                    ->success()
                    ->send();
            });
    }

    private function suspendAction(): Action
    {
        return Action::make('suspendOperations')
            ->label('Suspender operação do PU')
            ->icon('heroicon-o-pause-circle')
            ->color('danger')
            ->visible(fn (): bool => (auth()->user()?->can(AccessPermission::PuOperationsActivate->value) ?? false)
                && $this->activationStatus() === PuOperationalActivationStatus::Activated)
            ->modalHeading(fn (): string => 'Suspender a operação do PU — '.$this->emission()->name)
            ->modalDescription('Suspender interrompe geração, extensão diária e homologação desta emissão a partir de agora. Não apaga a curva oficial, o histórico nem as liquidações; liquidações reais continuam registráveis, porque o fato aconteceu no mundo. Reativar exige uma nova decisão com aprovação documentada.')
            ->modalIcon('heroicon-o-pause-circle')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Suspender')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da suspensão')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                Checkbox::make('confirm')
                    ->label('Confirmo a suspensão: a curva oficial deixa de avançar até nova ativação.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme a suspensão para continuar.']),
            ])
            ->action(function (array $data): void {
                try {
                    $change = app(PuOperationalActivationService::class)->suspend(
                        $this->emission(),
                        $this->currentUser(),
                        (string) ($data['reason'] ?? ''),
                        via: 'ui',
                    );
                } catch (AuthorizationException $exception) {
                    Notification::make()->title('Suspensão não autorizada.')->body($exception->getMessage().' Nada foi gravado.')->danger()->persistent()->send();

                    return;
                } catch (PuOperationalActivationException $exception) {
                    Notification::make()->title('Suspensão não registrada.')->body(PuGateMessage::withoutCode($exception->getMessage()))->danger()->persistent()->send();

                    return;
                }

                $this->overview = null;

                Notification::make()
                    ->title('Operação do PU suspensa.')
                    ->body(sprintf('Decisão #%d registrada. A curva oficial e o histórico foram preservados.', $change->id))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function readinessViewData(bool $withPreflight = true): array
    {
        $report = app(PuOperationalReadinessService::class)->assess($this->emission());
        $user = auth()->user();
        $preflight = $withPreflight && ($user?->can(AccessPermission::PuOperationsMonitor->value) || $user?->can(AccessPermission::PuOperationsActivate->value))
            ? app(PuOperationalPreflightService::class)->run([(int) $this->emission()->getKey()])
            : null;

        return [
            'report' => $report,
            'presentation' => PuReadinessPresentation::build($report, $preflight, scheduleReadable: app()->runningInConsole()),
            'preflightRan' => $preflight !== null,
            'compact' => false,
        ];
    }

    private function activationBlocker(): ?string
    {
        $policy = app(PuOperationalActivationPolicy::class);

        if (! $policy->globallyEnabled()) {
            return 'Ativação indisponível: as operações do PU estão desligadas neste ambiente (PU_OPERATIONS_ENABLED, controlado pela infraestrutura). Nenhuma emissão pode ser ativada aqui.';
        }

        $indexer = $this->emission()->puParameter?->indexer_enum;

        if ($indexer !== null && ! $indexer->certification()->allowsOperationalUse()) {
            return sprintf('Ativação indisponível: o indexador %s não é certificado para uso oficial (só simulação).', $indexer->value);
        }

        return null;
    }

    private function activationStatus(): PuOperationalActivationStatus
    {
        return app(PuOperationalActivationPolicy::class)->status($this->emission());
    }

    private function emission(): Emission
    {
        /** @var Emission $emission */
        $emission = $this->getRecord();

        return $emission;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
