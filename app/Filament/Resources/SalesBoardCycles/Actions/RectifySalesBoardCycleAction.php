<?php

namespace App\Filament\Resources\SalesBoardCycles\Actions;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardRectificationException;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Retificar competência": abre a correção da posição publicada da última
 * competência publicada do empreendimento.
 *
 * É da Gestão, como cancelar e reabrir, e segue o mesmo portão de superfície:
 * aparece para quem conduz a competência, de um lado ou do outro
 * ({@see SalesBoardCycleResource::canOperateOrApprove()}), na competência
 * aprovada e publicada sem retificação aberta. Fica desabilitada, dizendo por
 * quê, sem a permissão de aprovação ou quando a competência não é a última
 * publicada -- desabilitada, a ação nem monta. O serviço confere de novo a
 * permissão e todas as recusas de domínio.
 *
 * O modal mostra, antes de confirmar, o que mudou contra a posição publicada e
 * avisa quando a competência seguinte está em andamento: ela passa a esperar a
 * retificação pela regra de ordem da aprovação.
 */
class RectifySalesBoardCycleAction
{
    public static function make(string $name = 'rectifyCompetence'): Action
    {
        return Action::make($name)
            ->label('Retificar competência')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->outlined()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Retificar a competência publicada')
            ->modalDescription(fn (SalesBoardCycle $record): string => self::description($record))
            ->modalSubmitActionLabel('Abrir retificação')
            ->modalCancelActionLabel('Voltar')
            ->visible(fn (SalesBoardCycle $record): bool => SalesBoardCycleResource::canOperateOrApprove()
                && ($record->status === SalesBoardCycleStatus::Approved)
                && $record->hasPublication()
                && ! $record->isUnderRectification())
            ->disabled(fn (SalesBoardCycle $record): bool => self::unavailableReason($record) !== null)
            ->tooltip(fn (SalesBoardCycle $record): ?string => self::unavailableReason($record))
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da retificação')
                    ->helperText('Fica registrado na retificação, na versão nova e na auditoria. Quem abre a retificação não aprova a publicação dela.')
                    ->required()
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(function (SalesBoardCycle $record, array $data): void {
                try {
                    $rectification = app(SalesBoardCycleRectificationService::class)->open($record, auth()->user(), (string) $data['reason']);
                } catch (SalesBoardRectificationException|AuthorizationException $exception) {
                    Notification::make()
                        ->title('Não foi possível abrir a retificação')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title('Retificação aberta')
                    ->body(sprintf(
                        'A versão %s foi gravada com o motivo e a competência voltou a “Gerado”. Próximo passo: enviar para a validação da construtora. A posição publicada continua valendo até a aprovação.',
                        $rectification->openingBaseline?->versionLabel() ?? '—',
                    ))
                    ->success()
                    ->send();
            });
    }

    /**
     * Por que a retificação não está disponível para quem está na tela, ou
     * `null`. Duas leituras baratas: a permissão e a última competência
     * publicada.
     */
    public static function unavailableReason(SalesBoardCycle $record): ?string
    {
        if (! SalesBoardCycleResource::canApprove()) {
            return 'Retificar a competência é da Gestão: exige a permissão de aprovação do Quadro de Vendas.';
        }

        if ($record->isUnderRectification()) {
            return 'A competência já está em retificação.';
        }

        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $record->construction_id);

        if (($lastPublished !== null) && ($lastPublished->format('Y-m') !== $record->reference_month?->format('Y-m'))) {
            return sprintf(
                'Só a última competência publicada do empreendimento (%s) pode ser retificada. Esta se corrige para frente, pelos movimentos extemporâneos da competência seguinte.',
                $lastPublished->format('m/Y'),
            );
        }

        return null;
    }

    /**
     * O que muda contra a posição publicada, apurado na fonte de agora, e o
     * aviso da competência seguinte em andamento.
     */
    private static function description(SalesBoardCycle $record): string
    {
        $assessment = app(SalesBoardStaleDetectionService::class)
            ->assessWithoutPersisting($record, $record->currentBaseline);

        $difference = match ($assessment->impact) {
            SalesBoardStaleImpact::Material => 'Diferenças contra a posição publicada: '.$assessment->diff->summary(),
            SalesBoardStaleImpact::SourceOnly => 'A fonte mudou depois da publicação, mas a posição apurada continua igual: não há o que retificar.',
            SalesBoardStaleImpact::Blocking => 'A fonte atual está incompleta: a retificação só pode ser aberta depois que o dado faltante for cadastrado.',
            SalesBoardStaleImpact::None => 'A posição apurada hoje é igual à publicada: não há o que retificar.',
        };

        $next = $record->reference_month?->copy()->startOfMonth()->addMonthNoOverflow();

        $following = $next === null ? false : SalesBoardCycle::query()
            ->where('construction_id', $record->construction_id)
            ->whereDate('reference_month', $next->toDateString())
            ->whereNotIn('status', [SalesBoardCycleStatus::Approved->value, SalesBoardCycleStatus::Cancelled->value])
            ->exists();

        return implode(' ', array_filter([
            sprintf(
                'A competência %s de %s ganha uma versão nova, apurada na fonte de agora, e volta a “Gerado”: segue validação da construtora, análise e aprovação. Até a aprovação, a posição publicada (%s) continua valendo para o Quadro, as garantias e o relatório.',
                $record->reference_month?->format('m/Y') ?? '—',
                (string) $record->construction?->development_name,
                $record->currentBaseline?->versionLabel() ?? '—',
            ),
            $difference,
            $following
                ? sprintf('A competência %s está em andamento: ela só poderá ser aprovada depois que esta retificação for concluída ou desistida, e será conferida de novo contra a posição retificada.', $next->format('m/Y'))
                : null,
        ]));
    }
}
