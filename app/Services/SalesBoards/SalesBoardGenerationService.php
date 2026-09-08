<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\DTOs\SalesBoards\SalesBoardGenerationResult;
use App\DTOs\SalesBoards\SalesBoardReadinessReport;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\User;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Congela a competência de um empreendimento na versão 1 do seu ciclo.
 *
 * Não apura nada por conta própria: pede a posição à {@see SalesBoardDerivationService},
 * a prontidão à {@see SalesBoardReadinessService}, a fonte à
 * {@see SalesBoardFingerprintService}, e persiste. Uma segunda implementação de
 * estoque, financiado, quitado ou permutado aqui seria a segunda resposta para a
 * mesma pergunta, e o Quadro passou três fases eliminando exatamente isso.
 *
 * Três portas, nesta ordem:
 *
 * 1. **Emissão em elaboração não gera.** É a fase em que a posição inicial ainda
 *    está sendo composta, permutas inclusive. Congelar ali criaria uma "V1" que
 *    é rascunho, e o baseline mensal existe justamente para ser o oposto disso;
 * 2. **Ciclo existente não regera.** Gerar duas vezes a mesma competência é
 *    inofensivo por construção -- a segunda execução não escreve, não recalcula
 *    e devolve o ciclo que já existia. Recalcular é outra operação, explícita e
 *    com motivo;
 * 3. **Prontidão bloqueada não persiste nada.** Nenhum ciclo, nenhuma versão,
 *    nenhuma linha. Um ciclo incompleto "para preencher depois" seria lido como
 *    posição pela primeira pessoa que abrisse a tela.
 *
 * Venda fora da política não bloqueia: ela é um fato apurado, não um dado
 * faltando, e é congelada como tal no movimento.
 *
 * Nada é publicado em `sales_boards`. A posição aprovada continua sendo outra
 * coisa, e transformar uma apuração automática em posição publicada é decisão de
 * governança que ainda não foi tomada.
 */
class SalesBoardGenerationService
{
    public function __construct(
        private readonly SalesBoardDerivationService $derivationService,
        private readonly SalesBoardReadinessService $readinessService,
        private readonly SalesBoardFingerprintService $fingerprintService,
        private readonly SalesBoardBaselineWriter $baselineWriter,
    ) {}

    public function generateForConstruction(
        Construction $construction,
        CarbonInterface $referenceMonth,
        ?User $actor = null,
        bool $dryRun = false,
    ): SalesBoardGenerationResult {
        $constructionId = (int) $construction->getKey();

        return $this->generateForConstructions([$construction], $referenceMonth, $actor, $dryRun)[$constructionId];
    }

    /**
     * Gera os ciclos de vários empreendimentos com carregamento em lote.
     *
     * Cada empreendimento continua tendo o seu próprio ciclo e a sua própria
     * transação: numa emissão em que A e C estão prontos e B não, A e C são
     * gerados e B é reportado. Não existe "ciclo da emissão" a ser segurado pelo
     * pior empreendimento da carteira.
     *
     * @param  iterable<Construction>  $constructions
     * @return array<int, SalesBoardGenerationResult> indexado por `construction_id`
     */
    public function generateForConstructions(
        iterable $constructions,
        CarbonInterface $referenceMonth,
        ?User $actor = null,
        bool $dryRun = false,
    ): array {
        $month = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $positionDate = $month->endOfMonth()->startOfDay();

        /** @var Collection<int, Construction> $constructions */
        $constructions = collect($constructions)->keyBy(fn (Construction $construction): int => (int) $construction->getKey());

        if ($constructions->isEmpty()) {
            return [];
        }

        $constructions->each(fn (Construction $construction) => $construction->loadMissing('emission'));

        $results = [];
        $candidates = collect();

        $existing = $this->existingCycles($constructions->keys()->all(), $month);

        foreach ($constructions as $constructionId => $construction) {
            $constructionId = (int) $constructionId;

            if ($construction->emission?->isInDraft() ?? false) {
                $results[$constructionId] = $this->blocked(
                    $construction,
                    $month,
                    $positionDate,
                    sprintf(
                        'A emissão está em "%s". A competência mensal começa depois da elaboração, quando a posição inicial deixa de ser composta.',
                        Emission::STATUS_OPTIONS[Emission::STATUS_DRAFT],
                    ),
                    dryRun: $dryRun,
                );

                continue;
            }

            if (isset($existing[$constructionId])) {
                $results[$constructionId] = new SalesBoardGenerationResult(
                    outcome: SalesBoardGenerationOutcome::AlreadyExists,
                    constructionId: $constructionId,
                    constructionName: $construction->development_name,
                    referenceMonth: $month,
                    positionDate: $positionDate,
                    cycle: $existing[$constructionId],
                    baseline: $existing[$constructionId]->currentBaseline,
                    dryRun: $dryRun,
                );

                continue;
            }

            $candidates->put($constructionId, $construction);
        }

        if ($candidates->isEmpty()) {
            return $results;
        }

        /**
         * As doze leituras -- seis da derivação, seis da observação da fonte --
         * numa transação só.
         *
         * Sem isso cada consulta seria a sua própria transação sob
         * `REPEATABLE READ`, e uma venda cadastrada no meio da apuração poderia
         * aparecer para a carga de contratos e não para a de unidades. Pior:
         * o snapshot sairia de um mundo e o resumo da fonte de outro, e a
         * versão nasceria com dois fingerprints que nunca corresponderam entre
         * si -- a próxima verificação acusaria uma mudança que não houve.
         *
         * Nada é travado. Contratos, parcelas, unidades, tabelas e políticas
         * seguem editáveis durante a apuração; o que a transação garante é que
         * esta leitura veja um mundo só. Se a fonte mudar logo depois, quem
         * responde é a detecção de alterações.
         */
        [$positions, $observations] = DB::transaction(fn (): array => [
            $this->derivationService->deriveForConstructions($candidates, $month),
            $this->fingerprintService->observeForConstructions($candidates, $month),
        ]);

        foreach ($candidates as $constructionId => $construction) {
            $constructionId = (int) $constructionId;
            $position = $positions[$constructionId];
            $readiness = $this->readinessService->fromPosition($construction, $position);

            if (! $readiness->isReady()) {
                $results[$constructionId] = $this->blocked(
                    $construction,
                    $month,
                    $positionDate,
                    'A fonte da competência está incompleta: '.$this->blockerSummary($readiness),
                    $readiness,
                    $position,
                    $dryRun,
                );

                continue;
            }

            if ($dryRun) {
                $results[$constructionId] = new SalesBoardGenerationResult(
                    outcome: SalesBoardGenerationOutcome::Generated,
                    constructionId: $constructionId,
                    constructionName: $construction->development_name,
                    referenceMonth: $month,
                    positionDate: $positionDate,
                    readiness: $readiness,
                    position: $position,
                    dryRun: true,
                );

                continue;
            }

            $comparable = SalesBoardComparableSnapshot::fromDerived($position, $observations[$constructionId]);

            $results[$constructionId] = $this->persist(
                construction: $construction,
                month: $month,
                positionDate: $positionDate,
                comparable: $comparable,
                sourceFingerprint: $observations[$constructionId]->fingerprint(),
                readiness: $readiness,
                position: $position,
                actor: $actor,
            );
        }

        return $results;
    }

    /**
     * Cria ciclo, versão, linhas e movimentos -- ou nada.
     *
     * Os contratos **não** são travados durante a leitura. Bloquear a carteira
     * comercial de um empreendimento para tirar uma foto dela paralisaria o
     * cadastro de vendas por conta de um relatório, e a foto continuaria sendo
     * de um instante. A consistência interna da versão vem da transação; se a
     * fonte mudar logo depois, quem responde é a detecção de alterações, que
     * existe exatamente para isso.
     */
    private function persist(
        Construction $construction,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
        SalesBoardComparableSnapshot $comparable,
        string $sourceFingerprint,
        SalesBoardReadinessReport $readiness,
        SalesBoardDerivedPosition $position,
        ?User $actor,
    ): SalesBoardGenerationResult {
        try {
            /** @var array{0: SalesBoardCycle, 1: SalesBoardCycleBaseline} $created */
            $created = DB::transaction(function () use ($construction, $month, $positionDate, $comparable, $sourceFingerprint, $actor): array {
                $cycle = SalesBoardCycle::query()->create([
                    'emission_id' => $construction->emission_id,
                    'construction_id' => $construction->getKey(),
                    'reference_month' => $month->toDateString(),
                    'position_date' => $positionDate->toDateString(),
                    'status' => SalesBoardCycleStatus::Generated,
                    'created_by_id' => $actor?->getKey(),
                ]);

                $baseline = $this->baselineWriter->write(
                    cycle: $cycle,
                    version: 1,
                    comparable: $comparable,
                    sourceFingerprint: $sourceFingerprint,
                    actor: $actor,
                    reason: null,
                );

                $cycle->forceFill(['current_baseline_id' => $baseline->getKey()])->save();

                return [$cycle, $baseline];
            });
        } catch (UniqueConstraintViolationException) {
            /**
             * Outro processo criou o ciclo desta competência entre a checagem e
             * a escrita. A unique do banco é quem decide, e o perdedor relê em
             * vez de devolver um erro de SQL para quem clicou num botão.
             */
            $winner = $this->existingCycles([(int) $construction->getKey()], $month)[(int) $construction->getKey()] ?? null;

            return new SalesBoardGenerationResult(
                outcome: SalesBoardGenerationOutcome::AlreadyExists,
                constructionId: (int) $construction->getKey(),
                constructionName: $construction->development_name,
                referenceMonth: $month,
                positionDate: $positionDate,
                cycle: $winner,
                baseline: $winner?->currentBaseline,
                readiness: $readiness,
                position: $position,
            );
        }

        return new SalesBoardGenerationResult(
            outcome: SalesBoardGenerationOutcome::Generated,
            constructionId: (int) $construction->getKey(),
            constructionName: $construction->development_name,
            referenceMonth: $month,
            positionDate: $positionDate,
            cycle: $created[0],
            baseline: $created[1],
            readiness: $readiness,
            position: $position,
        );
    }

    /**
     * @param  list<int>  $constructionIds
     * @return array<int, SalesBoardCycle>
     */
    private function existingCycles(array $constructionIds, CarbonImmutable $month): array
    {
        /**
         * Faixa em vez de igualdade: uma coluna `date` gravada pelo Eloquent
         * carrega a hora junto no SQLite e é comparada como texto, então
         * `= '2026-07-01'` perderia a própria linha que se procura. A coluna
         * continua nua na comparação, então o índice da unique segue valendo --
         * o que `whereDate()` custaria.
         */
        return SalesBoardCycle::query()
            ->whereIn('construction_id', $constructionIds)
            ->whereBetween('reference_month', [$month->toDateString(), InclusiveDateBound::upperBound($month)])
            ->with('currentBaseline')
            ->get()
            ->keyBy(fn (SalesBoardCycle $cycle): int => (int) $cycle->construction_id)
            ->all();
    }

    private function blocked(
        Construction $construction,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
        string $reason,
        ?SalesBoardReadinessReport $readiness = null,
        ?SalesBoardDerivedPosition $position = null,
        bool $dryRun = false,
    ): SalesBoardGenerationResult {
        return new SalesBoardGenerationResult(
            outcome: SalesBoardGenerationOutcome::Blocked,
            constructionId: (int) $construction->getKey(),
            constructionName: $construction->development_name,
            referenceMonth: $month,
            positionDate: $positionDate,
            readiness: $readiness,
            position: $position,
            blockedReason: $reason,
            dryRun: $dryRun,
        );
    }

    private function blockerSummary(SalesBoardReadinessReport $readiness): string
    {
        return collect($readiness->blockingIssueCounts())
            ->map(fn (int $count, string $code): string => sprintf('%s (%d)', $code, $count))
            ->implode('; ');
    }
}
