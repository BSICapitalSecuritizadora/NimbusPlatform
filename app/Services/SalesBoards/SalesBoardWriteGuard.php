<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardPublication;
use App\Support\SalesBoards\SalesBoardWriteContext;
use Carbon\CarbonImmutable;

/**
 * Impede que a posição de uma competência automatizada seja escrita à mão.
 *
 * A partir do rollout existe um risco que não existia antes: uma Emissão passa a
 * ter a posição produzida pelo ciclo mensal, e alguém continua registrando o
 * quadro manualmente. As duas escritas disputariam a mesma competência, e a
 * publicação da Fase E seria recusada pelo conflito -- ou, pior, a posição
 * manual chegaria primeiro e o ciclo inteiro viraria trabalho perdido.
 *
 * Duas regras, e ambas no model, não na tela:
 *
 * 1. **competência automatizada não recebe escrita manual.** Vale a partir da
 *    competência inicial; o histórico anterior ao corte continua editável, que
 *    é o que permite manutenção do passado;
 * 2. **quadro publicado não é editado nem apagado**, em nenhum modo. Ele
 *    atravessou validação da construtora, análise da Gestão e aprovação; alterá-lo
 *    por fora mudaria Garantias e Relatório sem passar por nada disso.
 *
 * A tela também barra, mas a tela não é segurança: comando, job, tinker e
 * importação passam por aqui do mesmo jeito.
 */
class SalesBoardWriteGuard
{
    public function __construct(
        private readonly SalesBoardWriteContext $context,
    ) {}

    /**
     * Uma escrita está prestes a acontecer.
     */
    public function assertCanWrite(SalesBoard $salesBoard): void
    {
        if ($salesBoard->exists) {
            $this->assertNotPublished($salesBoard);
        }

        /**
         * A publicação é a escrita legítima do modo automatizado. Ela abre o
         * contexto em volta da própria criação, e nada mais no sistema o abre.
         */
        if ($this->context->isPublishing()) {
            return;
        }

        $this->assertNotAutomatedCompetence($salesBoard);
    }

    public function assertCanDelete(SalesBoard $salesBoard): void
    {
        $this->assertNotPublished($salesBoard);
    }

    /**
     * Um quadro que passou pela governança do ciclo é imutável.
     */
    private function assertNotPublished(SalesBoard $salesBoard): void
    {
        $published = SalesBoardPublication::query()
            ->where('sales_board_id', $salesBoard->getKey())
            ->exists();

        if ($published) {
            throw SalesBoardRolloutException::publishedBoardIsImmutable();
        }
    }

    /**
     * A competência já pertence ao motor automático?
     *
     * A checagem é pela Emissão do próprio quadro, e a competência é
     * normalizada antes de comparar -- o model normaliza no `saving`, mas o
     * guard roda antes e precisa comparar a mesma coisa.
     */
    private function assertNotAutomatedCompetence(SalesBoard $salesBoard): void
    {
        $emission = $salesBoard->relationLoaded('emission')
            ? $salesBoard->emission
            : Emission::query()->find($salesBoard->emission_id);

        if (! $emission instanceof Emission || ! $emission->usesAutomatedSalesBoard()) {
            return;
        }

        $referenceMonth = SalesBoard::normalizeReferenceMonth($salesBoard->reference_month);

        if ($referenceMonth === null) {
            return;
        }

        if (! $emission->automationCovers(CarbonImmutable::parse($referenceMonth))) {
            return;
        }

        throw SalesBoardRolloutException::manualWriteBlocked(
            (string) ($salesBoard->construction?->development_name
                ?? Emission::query()->find($salesBoard->emission_id)?->name
                ?? '—'),
            CarbonImmutable::parse($referenceMonth)->format('m/Y'),
        );
    }
}
