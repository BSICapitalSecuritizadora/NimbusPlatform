<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycle;
use Carbon\CarbonImmutable;

/**
 * "O que faço agora?" para uma competência, em uma frase.
 *
 * Read model de tela, derivado só do que o ciclo já carrega: a situação e o
 * impacto de fonte **registrado** na versão vigente. Não deriva a posição, não
 * consulta revisão e não decide nada -- quem decide continua sendo cada serviço
 * no momento da ação. Por isso serve tanto ao detalhe quanto a cada linha da
 * lista, sem consulta a mais por linha.
 *
 * O impacto de fonte é o da última verificação. A tela mostra quando ela foi
 * feita, e "Verificar alterações" atualiza.
 */
final readonly class SalesBoardCycleNextAction
{
    public function __construct(
        public string $headline,
        public string $detail,
        public string $color,
        public string $icon,
    ) {}

    public static function for(SalesBoardCycle $cycle): self
    {
        $action = self::forCycle($cycle);

        /**
         * A retificação percorre o fluxo de sempre; a frase diz que é ela, e
         * que a posição publicada continua valendo enquanto isso.
         */
        if ($cycle->isUnderRectification()) {
            return new self(
                'Retificação em andamento. '.$action->headline,
                $action->detail.' A posição publicada continua valendo até a retificação ser aprovada -- ou a Gestão desistir dela.',
                $action->color,
                $action->icon,
            );
        }

        return $action;
    }

    private static function forCycle(SalesBoardCycle $cycle): self
    {
        $impact = $cycle->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None;

        if (($cycle->status === SalesBoardCycleStatus::Approved) && ($impact !== SalesBoardStaleImpact::None)) {
            $approved = self::forStatus(SalesBoardCycleStatus::Approved);

            return new self(
                $approved->headline,
                match ($impact) {
                    SalesBoardStaleImpact::Material => 'A fonte mudou depois da publicação, e a posição publicada não é recalculada: os fatos alterados entram como movimentos extemporâneos na próxima competência a ser publicada. Para corrigir a posição publicada da última competência, a Gestão pode usar “Retificar competência”.',
                    SalesBoardStaleImpact::SourceOnly => 'A fonte mudou depois da publicação sem alterar a posição publicada: nada a fazer.',
                    default => 'A posição publicada continua valendo; a próxima competência só será apurada quando o dado faltante for cadastrado.',
                },
                $approved->color,
                $approved->icon,
            );
        }

        if (self::isInProgress($cycle->status)) {
            $sourceAction = match ($impact) {
                SalesBoardStaleImpact::Material => new self(
                    'Recalcule a posição antes de continuar.',
                    self::staleGuidance($impact).' Use “Recalcular posição”: validações e análises da versão anterior deixam de valer.',
                    'warning',
                    'heroicon-o-arrow-path',
                ),
                SalesBoardStaleImpact::Blocking => new self(
                    'Corrija a fonte antes de continuar.',
                    self::staleGuidance($impact),
                    'danger',
                    'heroicon-o-exclamation-triangle',
                ),
                default => null,
            };

            if ($sourceAction !== null) {
                return $sourceAction;
            }
        }

        $action = self::forStatus($cycle->status);

        if (($cycle->status === SalesBoardCycleStatus::Cancelled) && filled($cycle->cancellation_reason)) {
            return new self(
                $action->headline,
                sprintf('%s Motivo: %s', $action->detail, $cycle->cancellation_reason),
                $action->color,
                $action->icon,
            );
        }

        if (self::isInProgress($cycle->status) && ($impact === SalesBoardStaleImpact::SourceOnly)) {
            return new self($action->headline, $action->detail.' '.self::staleGuidance($impact), $action->color, $action->icon);
        }

        return $action;
    }

    /**
     * "Verificar alterações" numa competência publicada, sem retificação aberta.
     *
     * A posição publicada não é recalculada. O que mudou depois dela entra como
     * movimento extemporâneo na competência seguinte e passa pela validação da
     * construtora e pela Gestão: na última publicada, a seguinte é a próxima a
     * ser publicada; numa mais antiga, os fatos lançados antes da publicação da
     * última já entraram nela -- a aprovação recusa versão desatualizada --, e
     * os posteriores entram na próxima. Só a última competência publicada pode
     * ser retificada, e só para ela a frase oferece o caminho.
     *
     * @param  CarbonImmutable|null  $lastPublishedMonth  a última competência publicada do empreendimento
     */
    public static function publishedStaleGuidance(SalesBoardStaleImpact $impact, SalesBoardCycle $cycle, ?CarbonImmutable $lastPublishedMonth): string
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();
        $last = (($lastPublishedMonth === null) || $lastPublishedMonth->lessThan($month)) ? $month : $lastPublishedMonth->startOfMonth();
        $next = $last->addMonthNoOverflow()->format('m/Y');

        return match ($impact) {
            SalesBoardStaleImpact::None => 'A fonte continua igual à que produziu a posição publicada.',
            SalesBoardStaleImpact::SourceOnly => 'A fonte mudou depois da publicação sem alterar a posição publicada: nada a fazer.',
            SalesBoardStaleImpact::Material => $last->equalTo($month)
                ? sprintf(
                    'A posição publicada de %s não é recalculada: os fatos alterados depois da publicação entram como movimentos extemporâneos na próxima competência a ser publicada (%s) e passam pela validação e pela Gestão. Para corrigir a posição publicada, use “Retificar competência”.',
                    $month->format('m/Y'),
                    $next,
                )
                : sprintf(
                    'A posição publicada de %s não é recalculada: os fatos alterados antes da publicação de %s já entraram nela como movimentos extemporâneos, e os posteriores entram na próxima competência a ser publicada (%s). Só a última competência publicada (%s) pode ser retificada.',
                    $month->format('m/Y'),
                    $last->format('m/Y'),
                    $next,
                    $last->format('m/Y'),
                ),
            SalesBoardStaleImpact::Blocking => 'A posição publicada continua valendo; a próxima competência só será apurada quando o dado faltante for cadastrado.',
        };
    }

    /**
     * A diferença entre "a fonte mudou" e "a posição mudou", dita para quem
     * precisa decidir se recalcula.
     */
    public static function staleGuidance(SalesBoardStaleImpact $impact): string
    {
        return match ($impact) {
            SalesBoardStaleImpact::None => 'A fonte continua igual à que produziu esta versão.',
            SalesBoardStaleImpact::SourceOnly => 'A fonte mudou, mas a posição material permanece igual: não é preciso recalcular. Na análise da Gestão, a aprovação pedirá uma justificativa.',
            SalesBoardStaleImpact::Material => 'A posição calculada mudou e precisa ser recalculada.',
            SalesBoardStaleImpact::Blocking => 'A fonte atual está incompleta: nenhuma versão nova pode ser calculada até que o dado faltante seja cadastrado.',
        };
    }

    private static function forStatus(SalesBoardCycleStatus $status): self
    {
        return match ($status) {
            SalesBoardCycleStatus::Generated => new self(
                'Envie a posição para a validação da construtora.',
                'A competência foi congelada. O próximo passo é “Enviar para validação da construtora”.',
                'info',
                'heroicon-o-paper-airplane',
            ),
            SalesBoardCycleStatus::BuilderReview => new self(
                'Aguardando a validação da construtora.',
                'Abra a validação, revise as sete seções e envie. Depois do envio a competência segue para a Gestão.',
                'warning',
                'heroicon-o-clipboard-document-check',
            ),
            SalesBoardCycleStatus::ManagementReview => new self(
                'Aguardando análise da Gestão.',
                'Abra a “Análise da Gestão”, decida as não conformidades e conclua: aprovar e publicar, ou devolver à construtora.',
                'warning',
                'heroicon-o-scale',
            ),
            SalesBoardCycleStatus::Returned => new self(
                'Devolvida à construtora.',
                'Aguardando uma nova rodada de validação da construtora.',
                'warning',
                'heroicon-o-arrow-uturn-left',
            ),
            SalesBoardCycleStatus::Approved => new self(
                'Posição aprovada e publicada no Quadro de Vendas.',
                'O quadro publicado é imutável: não pode ser alterado nem excluído manualmente, e esta competência não é mais recalculada. Fatos lançados depois entram como movimentos extemporâneos na competência seguinte; a última competência publicada pode ser corrigida pela Gestão com “Retificar competência”.',
                'success',
                'heroicon-o-check-badge',
            ),
            SalesBoardCycleStatus::Cancelled => new self(
                'Competência cancelada.',
                'Encerrada sem publicação. A Gestão pode reabri-la com “Reabrir competência” enquanto ela estiver coberta '
                    .'pela automação da Emissão e nenhuma competência posterior tiver sido publicada.',
                'gray',
                'heroicon-o-no-symbol',
            ),
        };
    }

    /**
     * Situações em que a fonte ainda pode mudar o que acontece a seguir.
     * Depois de aprovado ou cancelado, o ciclo não é recalculado.
     */
    private static function isInProgress(SalesBoardCycleStatus $status): bool
    {
        return in_array($status, [
            SalesBoardCycleStatus::Generated,
            SalesBoardCycleStatus::BuilderReview,
            SalesBoardCycleStatus::ManagementReview,
            SalesBoardCycleStatus::Returned,
        ], true);
    }
}
