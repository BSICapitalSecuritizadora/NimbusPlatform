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
use App\Support\SalesBoards\CompetenceCalendar;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use App\Support\SalesBoards\SalesBoardAccess;
use App\Support\SalesBoards\SalesBoardFrozenWarnings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * Seis portas, nesta ordem:
 *
 * 1. **Emissão em elaboração não gera.** É a fase em que a posição inicial ainda
 *    está sendo composta, permutas inclusive. Congelar ali criaria uma "V1" que
 *    é rascunho, e o baseline mensal existe justamente para ser o oposto disso;
 * 2. **Competência aberta não gera.** A posição é a do último dia do mês, e ela
 *    só existe depois que esse dia passou no calendário de negócio. Um ciclo
 *    congelado antes disso deixaria de fora as vendas e quitações dos últimos
 *    dias e viraria, para a automação, "o ciclo da competência";
 * 3. **Ciclo existente não regera.** Gerar duas vezes a mesma competência é
 *    inofensivo por construção -- a segunda execução não escreve, não recalcula
 *    e devolve o ciclo que já existia. Recalcular é outra operação, explícita e
 *    com motivo;
 * 4. **Competência fora da automação não gera.** O ciclo é o caminho do modo
 *    automatizado: uma Emissão legada, ou uma competência anterior à ativação do
 *    rollout, tem a posição registrada à mão. Publicar um ciclo ali congelaria
 *    uma competência que ninguém homologou e que o legado não conseguiria mais
 *    corrigir (decisão do dono do produto de 25/09/2026).
 *
 *    A cobertura vem depois do ciclo existente porque ela decide se um ciclo
 *    pode *nascer*, não se o que já nasceu é informado. Uma Emissão que voltou
 *    ao legado continua com os ciclos gerados enquanto era automatizada -- o
 *    retorno não cancela nada --, e responder "modo legado" a quem pede uma
 *    dessas competências esconderia um ciclo que existe. É a mesma ordem da
 *    automação, que reconhece o ciclo existente antes de pedir a geração;
 * 5. **Competência posterior publicada não deixa gerar.** A posição publicada
 *    da posterior já reflete os fatos desta, e o que chegar depois dela entra
 *    como extemporâneo na competência seguinte; gerar e publicar esta daria
 *    aos mesmos fatos um segundo dono. É a mesma regra da reabertura recusada.
 *    A automação encerra o alvo com motivo próprio, que não volta -- uma
 *    publicação nunca é desfeita --, em vez de tentar de novo para sempre. A
 *    leitura é comum: a geração que correr com a aprovação da posterior pode
 *    criar o ciclo logo depois da publicação, e é a aprovação dele que o recusa
 *    pela mesma regra ({@see SalesBoardManagementApprovalService});
 * 6. **Prontidão bloqueada não persiste nada.** Nenhum ciclo, nenhuma versão,
 *    nenhuma linha. Um ciclo incompleto "para preencher depois" seria lido como
 *    posição pela primeira pessoa que abrisse a tela.
 *
 * As portas valem para toda entrada -- ação da tela, comando e automação --
 * porque moram aqui, e não em quem chama. Quem chama em nome de uma pessoa
 * passa o ator, e ele precisa poder congelar competência
 * ({@see SalesBoardAccess::authorizeGeneration()}); o ator nulo é a automação
 * ou o comando.
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
     * Gera os ciclos de vários empreendimentos, um de cada vez.
     *
     * Cada empreendimento tem o seu próprio ciclo, a sua própria leitura e a sua
     * própria transação: numa emissão em que A e C estão prontos e B não, A e C
     * são gerados e B é reportado. Não existe "ciclo da emissão" a ser segurado
     * pelo pior empreendimento da carteira.
     *
     * A apuração **não** é feita em lote. Derivar e observar a emissão inteira de
     * uma vez carrega como models as parcelas de todos os contratos da carteira,
     * duas vezes, e o pico de memória vira a soma dos empreendimentos -- numa
     * emissão média o processo morria antes de gravar o primeiro ciclo. Um por
     * vez, o pico é o do maior empreendimento, e o que já foi gravado fica
     * gravado se o seguinte falhar. As consultas a mais custam pouco perto disso.
     *
     * O resultado sai na ordem em que os empreendimentos chegaram.
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
        if ($actor !== null) {
            SalesBoardAccess::authorizeGeneration($actor);
        }

        $month = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $positionDate = $month->endOfMonth()->startOfDay();

        /** @var Collection<int, Construction> $constructions */
        $constructions = collect($constructions)->keyBy(fn (Construction $construction): int => (int) $construction->getKey());

        if ($constructions->isEmpty()) {
            return [];
        }

        /**
         * A Emissão é relida aqui, e não aproveitada de quem chamou: o modo do
         * Quadro decide se a competência pode ser congelada, e uma relação
         * carregada antes de um retorno ao legado responderia pelo modo antigo.
         */
        EloquentCollection::make($constructions->values()->all())->load('emission');

        $results = [];
        $candidates = collect();
        $openCompetenceReason = CompetenceCalendar::isClosed($month)
            ? null
            : $this->openCompetenceReason($month, $positionDate);

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

            if ($openCompetenceReason !== null) {
                $results[$constructionId] = $this->blocked($construction, $month, $positionDate, $openCompetenceReason, dryRun: $dryRun);

                continue;
            }

            $candidates->put($constructionId, $construction);
        }

        $existing = $candidates->isEmpty() ? [] : $this->existingCycles($candidates->keys()->all(), $month);
        $lastPublished = $candidates->isEmpty()
            ? []
            : PublishedCompetenceBoundary::lastPublishedMonths($candidates->keys()->map(fn (mixed $id): int => (int) $id)->values()->all());

        foreach ($candidates as $constructionId => $construction) {
            $constructionId = (int) $constructionId;

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

            $coverageRefusal = $this->coverageRefusal($construction, $month);

            if ($coverageRefusal !== null) {
                $results[$constructionId] = $this->blocked($construction, $month, $positionDate, $coverageRefusal, dryRun: $dryRun);

                continue;
            }

            $laterPublished = $lastPublished[$constructionId] ?? null;

            $results[$constructionId] = (($laterPublished !== null) && $laterPublished->greaterThan($month))
                ? $this->blockedByLaterPublication($construction, $month, $positionDate, $laterPublished, $dryRun)
                : $this->generateOne($construction, $month, $positionDate, $actor, $dryRun);
        }

        return $constructions->keys()
            ->mapWithKeys(fn (mixed $constructionId): array => [(int) $constructionId => $results[(int) $constructionId]])
            ->all();
    }

    /**
     * Apura e, se estiver pronto, congela um único empreendimento.
     */
    private function generateOne(
        Construction $construction,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
        ?User $actor,
        bool $dryRun,
    ): SalesBoardGenerationResult {
        $constructionId = (int) $construction->getKey();

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
        [$position, $observation] = DB::transaction(fn (): array => [
            $this->derivationService->deriveForConstruction($construction, $month),
            $this->fingerprintService->observeForConstruction($construction, $month),
        ]);

        $readiness = $this->readinessService->fromPosition($construction, $position);

        if (! $readiness->isReady()) {
            return $this->blocked(
                $construction,
                $month,
                $positionDate,
                'A fonte da competência está incompleta: '.$this->blockerSummary($readiness),
                $readiness,
                $position,
                $dryRun,
            );
        }

        if ($dryRun) {
            return new SalesBoardGenerationResult(
                outcome: SalesBoardGenerationOutcome::Generated,
                constructionId: $constructionId,
                constructionName: $construction->development_name,
                referenceMonth: $month,
                positionDate: $positionDate,
                readiness: $readiness,
                position: $position,
                dryRun: true,
            );
        }

        return $this->persist(
            construction: $construction,
            month: $month,
            positionDate: $positionDate,
            comparable: SalesBoardComparableSnapshot::fromDerived($position, $observation),
            sourceFingerprint: $observation->fingerprint(),
            readiness: $readiness,
            position: $position,
            actor: $actor,
        );
    }

    /**
     * Por que uma competência ainda aberta não é congelada.
     */
    private function openCompetenceReason(CarbonImmutable $month, CarbonImmutable $positionDate): string
    {
        return sprintf(
            'A competência %s ainda não terminou no calendário de negócio: o último dia é %s. Só se congela competência encerrada; a mais recente hoje é %s.',
            $month->format('m/Y'),
            $positionDate->format('d/m/Y'),
            CompetenceCalendar::lastClosedMonth()->format('m/Y'),
        );
    }

    /**
     * Por que a competência não está coberta pela automação, ou `null` quando está.
     */
    private function coverageRefusal(Construction $construction, CarbonImmutable $month): ?string
    {
        $emission = $construction->emission;

        if (! $emission instanceof Emission) {
            return 'O empreendimento não está vinculado a uma Emissão, e o ciclo mensal só existe para competências cobertas pela automação de uma Emissão.';
        }

        if ($emission->automationCovers($month)) {
            return null;
        }

        $start = $emission->automationStartsAt();

        if (! $emission->usesAutomatedSalesBoard() || $start === null) {
            return sprintf(
                'A Emissão "%s" registra o Quadro de Vendas manualmente (modo legado). O ciclo mensal só existe para competências cobertas pela automação, depois da homologação e da ativação do rollout da Emissão.',
                (string) $emission->name,
            );
        }

        return sprintf(
            'A competência %s é anterior à ativação da automação da Emissão "%s", que cobre a partir de %s. Competências anteriores continuam no registro manual.',
            $month->format('m/Y'),
            (string) $emission->name,
            $start->format('m/Y'),
        );
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
            $created = DB::transaction(function () use ($construction, $month, $positionDate, $comparable, $sourceFingerprint, $actor, $position): array {
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
                    frozenWarnings: SalesBoardFrozenWarnings::fromPosition($position),
                );

                $cycle->forceFill(['current_baseline_id' => $baseline->getKey()])->save();

                return [$cycle, $baseline];
            });
        } catch (UniqueConstraintViolationException) {
            /**
             * Outro processo criou o ciclo desta competência entre a checagem e
             * a escrita. A unique do banco é quem decide, e o perdedor relê em
             * vez de devolver um erro de SQL para quem clicou num botão.
             *
             * A releitura é **com lock**. Quem chama de dentro de uma transação
             * -- a automação, que já leu o ciclo antes de gerar -- tem o
             * snapshot do `REPEATABLE READ` fixado naquela primeira leitura, e
             * um SELECT comum não enxerga o ciclo que o vencedor commitou depois
             * dela: o alvo ficaria satisfeito sem saber por qual ciclo. Leitura
             * com lock ignora o snapshot e lê a versão commitada mais recente.
             */
            $winner = $this->existingCycles([(int) $construction->getKey()], $month, lockForUpdate: true)[(int) $construction->getKey()] ?? null;

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
     * @param  bool  $lockForUpdate  lê a versão commitada mais recente, fora do snapshot
     * @return array<int, SalesBoardCycle>
     */
    private function existingCycles(array $constructionIds, CarbonImmutable $month, bool $lockForUpdate = false): array
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
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->with(['currentBaseline' => fn (BelongsTo $query): BelongsTo => $lockForUpdate ? $query->sharedLock() : $query])
            ->get()
            ->keyBy(fn (SalesBoardCycle $cycle): int => (int) $cycle->construction_id)
            ->all();
    }

    /**
     * A competência ficou para trás de uma publicação do empreendimento.
     *
     * Nada é apurado: a resposta não depende da fonte. O mês da posterior vai
     * no resultado, para a automação encerrar o alvo em vez de tentar de novo.
     */
    private function blockedByLaterPublication(
        Construction $construction,
        CarbonImmutable $month,
        CarbonImmutable $positionDate,
        CarbonImmutable $laterPublished,
        bool $dryRun,
    ): SalesBoardGenerationResult {
        return new SalesBoardGenerationResult(
            outcome: SalesBoardGenerationOutcome::Blocked,
            constructionId: (int) $construction->getKey(),
            constructionName: $construction->development_name,
            referenceMonth: $month,
            positionDate: $positionDate,
            blockedReason: sprintf(
                'A competência %s não pode ser congelada: %s já foi publicada, e a posição dela já reflete os fatos de %s. '
                    .'Os fatos de %s lançados depois entram como extemporâneos na próxima competência a ser publicada; '
                    .'para corrigir a posição publicada, use "Retificar competência" na última competência publicada.',
                $month->format('m/Y'),
                $laterPublished->format('m/Y'),
                $month->format('m/Y'),
                $month->format('m/Y'),
            ),
            dryRun: $dryRun,
            laterPublishedMonth: $laterPublished,
        );
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
