<?php

declare(strict_types=1);

namespace App\Events\SalesBoards;

use App\Listeners\SalesBoards\RecheckFollowingCompetencesOnPriorPositionChange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A posição de uma competência -- a âncora das seguintes -- mudou.
 *
 * Os extemporâneos de uma competência são apurados contra a posição congelada
 * da anterior. Quando ela muda -- a retificação foi publicada, a competência foi
 * cancelada e a seguinte passou a absorver os fatos dela, ou ela foi reaberta --,
 * as competências seguintes em andamento podem ter ficado desatualizadas pelo
 * conteúdo e, no cancelamento e na reabertura, pela cadeia de onde partem. O
 * ouvinte ({@see RecheckFollowingCompetencesOnPriorPositionChange}) só antecipa
 * o selo "Alterações materiais": a garantia continua no portão da aprovação e
 * na abertura da validação, que sempre derivam a fonte.
 *
 * Disparado depois do commit: um ato desfeito não mudou posição nenhuma.
 */
class SalesBoardPriorPositionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const RECTIFICATION_PUBLISHED = 'retificacao_publicada';

    public const COMPETENCE_CANCELLED = 'competencia_cancelada';

    public const COMPETENCE_REOPENED = 'competencia_reaberta';

    public function __construct(
        public readonly int $constructionId,
        public readonly CarbonImmutable $referenceMonth,
        public readonly string $reason,
    ) {}
}
