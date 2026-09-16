<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycle;

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
        $impact = $cycle->currentBaseline?->stale_impact ?? SalesBoardStaleImpact::None;

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

        if (self::isInProgress($cycle->status) && ($impact === SalesBoardStaleImpact::SourceOnly)) {
            return new self($action->headline, $action->detail.' '.self::staleGuidance($impact), $action->color, $action->icon);
        }

        return $action;
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
                'O quadro publicado é imutável: não pode ser alterado nem excluído manualmente, e esta competência não é mais recalculada.',
                'success',
                'heroicon-o-check-badge',
            ),
            SalesBoardCycleStatus::Cancelled => new self(
                'Ciclo cancelado.',
                'Nenhuma ação está disponível para esta competência.',
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
