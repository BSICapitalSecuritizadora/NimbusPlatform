<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardApprovalResult;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\Emission;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * O portão. Aprova a análise e publica a posição, ou não faz nada.
 *
 * Coordena; não escreve em `sales_boards`. A escrita é do
 * {@see SalesBoardPublicationService}, que é a porta única -- aqui vive a
 * pergunta "esta posição pode ser publicada?", lá vive "como ela vira quadro".
 *
 * Tudo acontece dentro de uma transação com o ciclo travado, e a verificação
 * final da fonte acontece **dentro** dela. Confiar no selo já gravado no
 * baseline seria aprovar com base numa observação de dez minutos atrás: entre
 * abrir a tela e clicar, alguém pode ter mexido num contrato, e o quadro
 * publicado sairia materialmente desatualizado com um badge verde ao lado.
 *
 * Não trava contratos, parcelas nem unidades individualmente. A decisão é
 * antiga e continua valendo: o `REPEATABLE READ` da transação dá uma leitura
 * coerente da fonte, e travar linha a linha uma obra inteira transformaria a
 * aprovação num bloqueio de escrita sobre a operação inteira.
 */
class SalesBoardManagementApprovalService
{
    /**
     * Versão do texto que a Gestão aceita ao aprovar. Congelada junto com a
     * aprovação para que, se o texto mudar, se saiba qual foi aceito.
     */
    public const DECLARATION_VERSION = '2026-09-v1';

    public function __construct(
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
        private readonly SalesBoardPublicationService $publicationService,
        private readonly SalesBoardPublicationProjection $projection,
    ) {}

    /**
     * @param  bool  $declarationAccepted  a confirmação de que divergências e não
     *                                     conformidades foram analisadas
     * @param  string|null  $sourceChangeReason  obrigatório -- e só admitido --
     *                                           quando a fonte mudou sem alterar
     *                                           a posição
     */
    public function approve(
        SalesBoardManagementReview $review,
        ?User $actor,
        bool $declarationAccepted,
        ?string $sourceChangeReason = null,
    ): SalesBoardApprovalResult {
        if ($actor === null) {
            throw SalesBoardManagementReviewException::actorRequired();
        }

        SalesBoardApprovalAuthority::assertMayApproveManagementReview($actor, $review);

        if (! $declarationAccepted) {
            throw SalesBoardManagementReviewException::declarationRequired();
        }

        $sourceChangeReason = $this->normalizeReason($sourceChangeReason);

        return DB::transaction(function () use ($review, $actor, $sourceChangeReason): SalesBoardApprovalResult {
            /**
             * A ordem de locks é a das fases anteriores: ciclo, depois análise.
             * Invertê-la reabriria o TOCTOU entre aprovar e devolver.
             */
            $cycle = SalesBoardCycle::query()
                ->whereKey($review->sales_board_cycle_id)
                ->lockForUpdate()
                ->with('construction')
                ->firstOrFail();

            /**
             * As pendências vêm travadas, na mesma ordem: ciclo, análise, linhas
             * filhas. A decisão segue essa ordem e espera a aprovação terminar;
             * o lock das pendências é o que impede uma escrita fora dela de
             * mudar uma conclusão entre o portão e a publicação -- que ainda
             * passa pela derivação completa da fonte, logo abaixo.
             */
            $review = SalesBoardManagementReview::query()
                ->whereKey($review->getKey())
                ->lockForUpdate()
                ->with(['nonconformities' => fn (HasMany $query): HasMany => $query->lockForUpdate()])
                ->firstOrFail();

            /**
             * Retry e clique duplo chegam aqui depois de a primeira chamada ter
             * commitado. Nada é criado de novo: devolve-se a publicação que
             * existe. Deixar seguir produziria um segundo quadro para a mesma
             * competência -- ou, na melhor das hipóteses, um erro de banco
             * mostrado ao gestor.
             */
            if ($review->isApproved()) {
                return $this->alreadyApproved($cycle, $review);
            }

            $this->assertReviewOpen($review);
            $this->assertCycleInManagement($cycle);
            $this->assertCoveredByAutomation($cycle);

            $baseline = SalesBoardCycleBaseline::query()
                ->with(['lines', 'movements', 'cycle'])
                ->find($cycle->current_baseline_id);

            if (! $baseline instanceof SalesBoardCycleBaseline) {
                throw SalesBoardManagementReviewException::withoutCurrentBaseline();
            }

            $this->assertReviewApplies($review, $baseline);
            $this->assertBuilderReviewApplies($review, $baseline);
            $this->assertFullyMaterialized($review, $baseline);
            $this->assertNonconformitiesResolved($review);

            /**
             * A projeção acontece antes da publicação e recusa baseline
             * incompleto ou com unidade indeterminada. Chamá-la aqui é defesa em
             * profundidade: mesmo que uma inconsistência tivesse deixado passar
             * uma versão incompleta, ela não chega a virar quadro.
             */
            $payload = $this->projection->project($cycle, $baseline);

            $assessment = $this->staleDetectionService->assessWithoutPersisting($cycle, $baseline);

            $sourceChangeReason = $this->resolveSourceOverride($assessment->impact, $sourceChangeReason);

            $publication = $this->publicationService->publish(
                cycle: $cycle,
                baseline: $baseline,
                review: $review,
                assessment: $assessment,
                sourceChangeReason: $sourceChangeReason,
                actor: $actor,
            );

            $now = CarbonImmutable::now();

            $review->forceFill([
                'status' => SalesBoardManagementReviewStatus::Approved,
                'approved_at' => $now,
                'approved_by_user_id' => $actor->getKey(),
                'source_changed' => $assessment->sourceChanged,
                'source_change_reason' => $sourceChangeReason,
                'approved_source_fingerprint' => $baseline->source_fingerprint,
                'observed_source_fingerprint' => $assessment->observedSourceFingerprint,
                'approval_declaration_version' => self::DECLARATION_VERSION,
            ])->save();

            $cycle->forceFill(['status' => SalesBoardCycleStatus::Approved])->save();

            return new SalesBoardApprovalResult(
                outcome: SalesBoardApprovalOutcome::Approved,
                review: $review->refresh(),
                publication: $publication,
                salesBoard: $publication->salesBoard()->firstOrFail(),
                payload: $payload,
            );
        });
    }

    /**
     * O estado do portão, sem escrever nada.
     *
     * A tela precisa mostrar por que a publicação está ou não liberada, e
     * precisa mostrar exatamente os mesmos critérios que a aprovação aplica.
     * Duas listas -- uma para exibir e outra para decidir -- divergiriam na
     * primeira regra nova.
     *
     * @return array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>, impact: SalesBoardStaleImpact|null}
     */
    public function gate(SalesBoardManagementReview $review): array
    {
        $review->loadMissing(['cycle.construction', 'cycle.currentBaseline', 'nonconformities', 'builderReview']);

        $cycle = $review->cycle;
        $baseline = $cycle?->currentBaseline;
        $builderReview = $review->builderReview;

        $pending = $review->pendingNonconformities();
        $correction = $review->correctionRequiredNonconformities();

        $assessment = ($cycle !== null && $baseline !== null)
            ? $this->staleDetectionService->assessWithoutPersisting($cycle, $baseline)
            : null;

        $impact = $assessment?->impact;

        $legacy = ($cycle === null) ? null : $this->publicationService->existingPosition($cycle);

        $uncoveredSales = ($baseline === null) ? [] : $this->uncoveredSales($review, $baseline);
        $uncoveredDivergences = $this->uncoveredDivergences($review);

        $emission = ($cycle === null) ? null : Emission::query()->find($cycle->emission_id);
        $automated = ($cycle !== null) && $this->automationCovers($emission, $cycle);

        $checks = [
            [
                'label' => 'Competência coberta pela automação',
                'passed' => $automated,
                'detail' => ($automated || ($cycle === null))
                    ? null
                    : sprintf(
                        'A Emissão não cobre %s pela automação. Use "Cancelar competência" para encerrá-la.',
                        $cycle->reference_month->format('m/Y'),
                    ),
            ],
            [
                'label' => 'Validação da construtora aplicável',
                'passed' => ($builderReview?->status === SalesBoardBuilderReviewStatus::Submitted)
                    && ($builderReview?->appliesTo($baseline) ?? false),
                'detail' => $builderReview === null
                    ? 'Nenhuma validação vinculada.'
                    : sprintf('%s · %s', $builderReview->attemptLabel(), $builderReview->status->label()),
            ],
            [
                'label' => 'Pendências cobrem a versão vigente',
                'passed' => ($baseline !== null) && ($uncoveredSales === []) && ($uncoveredDivergences === []),
                'detail' => ($uncoveredSales === []) && ($uncoveredDivergences === [])
                    ? null
                    : sprintf(
                        '%d venda(s) e %d divergência(s) sem pendência nesta análise.',
                        count($uncoveredSales),
                        count($uncoveredDivergences),
                    ),
            ],
            [
                'label' => 'Não conformidades decididas',
                'passed' => $pending === [],
                'detail' => $pending === []
                    ? null
                    : sprintf('%d pendente(s) de decisão.', count($pending)),
            ],
            [
                'label' => 'Nenhuma correção de fonte pendente',
                'passed' => $correction === [],
                'detail' => $correction === []
                    ? null
                    : sprintf('%d item(ns) exigindo correção da fonte.', count($correction)),
            ],
            [
                'label' => 'Fonte sem alteração material',
                'passed' => in_array($impact, [SalesBoardStaleImpact::None, SalesBoardStaleImpact::SourceOnly], true),
                'detail' => $impact?->label(),
            ],
            [
                'label' => 'Posição completa',
                'passed' => ($baseline !== null)
                    && ((int) $baseline->undetermined_units === 0)
                    && (bool) $baseline->is_complete,
                'detail' => ($baseline !== null) && ((int) $baseline->undetermined_units > 0)
                    ? sprintf('%d unidade(s) sem classificação.', (int) $baseline->undetermined_units)
                    : null,
            ],
            [
                'label' => 'Sem conflito com quadro já registrado',
                'passed' => $legacy === null,
                'detail' => $legacy === null
                    ? null
                    : sprintf('Já existe quadro #%d para esta competência.', (int) $legacy->getKey()),
            ],
            [
                'label' => 'Análise em andamento',
                'passed' => $review->isEditable()
                    && $review->appliesTo($baseline)
                    && ($cycle?->status === SalesBoardCycleStatus::ManagementReview),
                'detail' => $review->status->label(),
            ],
        ];

        return [
            'ready' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            'checks' => $checks,
            'impact' => $impact,
        ];
    }

    private function alreadyApproved(SalesBoardCycle $cycle, SalesBoardManagementReview $review): SalesBoardApprovalResult
    {
        $publication = SalesBoardPublication::query()
            ->where('sales_board_management_review_id', $review->getKey())
            ->with('salesBoard')
            ->firstOrFail();

        return new SalesBoardApprovalResult(
            outcome: SalesBoardApprovalOutcome::AlreadyApproved,
            review: $review,
            publication: $publication,
            salesBoard: $publication->salesBoard,
            payload: $this->projection->project(
                $cycle,
                SalesBoardCycleBaseline::query()->findOrFail($publication->sales_board_cycle_baseline_id),
            ),
        );
    }

    private function assertReviewOpen(SalesBoardManagementReview $review): void
    {
        if ($review->isSuperseded()) {
            throw SalesBoardManagementReviewException::reviewSuperseded();
        }

        if (! $review->isEditable()) {
            throw SalesBoardManagementReviewException::reviewNotEditable();
        }
    }

    private function assertCycleInManagement(SalesBoardCycle $cycle): void
    {
        if ($cycle->status !== SalesBoardCycleStatus::ManagementReview) {
            throw SalesBoardManagementReviewException::cycleNotInManagement($cycle->status);
        }
    }

    /**
     * A análise precisa continuar falando do quadro vigente.
     *
     * A tela pode estar aberta há uma hora. Se nesse intervalo alguém recalculou
     * a posição, as decisões são sobre fatos que já não são os do Nimbus -- e o
     * ouvinte que substitui a análise pode nem ter rodado ainda, porque ele sai
     * depois do commit. Comparar o fingerprint aqui não depende desse tempo.
     */
    private function assertReviewApplies(SalesBoardManagementReview $review, SalesBoardCycleBaseline $baseline): void
    {
        if (! $review->appliesTo($baseline)) {
            throw SalesBoardManagementReviewException::baselineChanged();
        }
    }

    private function assertBuilderReviewApplies(SalesBoardManagementReview $review, SalesBoardCycleBaseline $baseline): void
    {
        $builderReview = SalesBoardBuilderReview::query()->find($review->sales_board_builder_review_id);

        if (! $builderReview instanceof SalesBoardBuilderReview
            || $builderReview->status !== SalesBoardBuilderReviewStatus::Submitted) {
            throw SalesBoardManagementReviewException::withoutSubmittedBuilderReview();
        }

        if (! $builderReview->appliesTo($baseline)) {
            throw SalesBoardManagementReviewException::builderReviewNotApplicable();
        }
    }

    /**
     * A competência só é publicada pelo ciclo enquanto a automação a cobre.
     *
     * O rollout decide quem escreve os quadros de uma Emissão. Publicar uma
     * competência que a automação não cobre -- Emissão que voltou ao registro
     * manual, ou competência anterior ao início -- criaria um quadro imutável
     * sem homologação ao lado, e a competência ficaria congelada sem forma de
     * correção. A saída para um ciclo nessa situação é cancelar a competência.
     *
     * A Emissão é relida aqui, dentro da transação: a tela pode ter sido aberta
     * antes de a Emissão voltar ao legado.
     *
     * Sem lock na Emissão, de propósito. O rollout trava a Emissão antes de
     * qualquer ciclo, e aqui o ciclo já está travado: travar a Emissão agora
     * inverteria a ordem e abriria espaço para deadlock entre uma aprovação e
     * um retorno ao legado. O custo é uma janela estreita, e conhecida: um
     * retorno ao legado que feche enquanto esta aprovação deriva a fonte não a
     * impede, e a competência sai publicada pelo ciclo.
     */
    private function assertCoveredByAutomation(SalesBoardCycle $cycle): void
    {
        $emission = Emission::query()->find($cycle->emission_id);

        if (! $this->automationCovers($emission, $cycle)) {
            throw SalesBoardManagementReviewException::competenceNotCoveredByAutomation(
                (string) ($emission?->name ?? '—'),
                $cycle->reference_month->format('m/Y'),
            );
        }
    }

    private function automationCovers(?Emission $emission, SalesBoardCycle $cycle): bool
    {
        return ($emission instanceof Emission)
            && $emission->automationCovers(CarbonImmutable::parse($cycle->reference_month->toDateString()));
    }

    /**
     * Todo fato da versão vigente que exige decisão tem pendência nesta análise.
     *
     * Defesa em profundidade, e não repetição da materialização: a abertura já
     * cria uma pendência por venda decidível e por divergência declarada, e o
     * caminho normal nunca falha aqui. Mas a aprovação é o último ponto antes de
     * a posição virar Quadro de Vendas publicado, e uma venda fora da política
     * -- ou sem conformidade determinável -- ou uma divergência da construtora
     * que atravessasse por um baseline escrito fora do fluxo, por uma
     * materialização defeituosa ou por dado legado seria publicada sem que
     * ninguém a tivesse analisado.
     *
     * A conferência é contra o **baseline congelado**, nunca contra a fonte
     * viva: é o mesmo princípio da fase inteira.
     */
    private function assertFullyMaterialized(
        SalesBoardManagementReview $review,
        SalesBoardCycleBaseline $baseline,
    ): void {
        $uncoveredSales = $this->uncoveredSales($review, $baseline);

        if ($uncoveredSales !== []) {
            throw SalesBoardManagementReviewException::conformityWithoutNonconformity($uncoveredSales);
        }

        $uncoveredDivergences = $this->uncoveredDivergences($review);

        if ($uncoveredDivergences !== []) {
            throw SalesBoardManagementReviewException::divergenceWithoutNonconformity($uncoveredDivergences);
        }
    }

    /**
     * As vendas decidíveis da versão vigente que nenhuma pendência cobre.
     *
     * A cobertura é pela chave natural do movimento -- tipo, contrato, unidade e
     * veredito --, e não pelo id. Um recálculo que só troca a origem material
     * cria uma versão nova com exatamente os mesmos fatos e movimentos de ids
     * novos; a análise continua valendo para ela, e comparar ids faria toda
     * venda já decidida parecer descoberta, travando a aprovação sem saída.
     *
     * @return list<string>
     */
    private function uncoveredSales(SalesBoardManagementReview $review, SalesBoardCycleBaseline $baseline): array
    {
        $decidable = SalesBoardCycleMovement::query()
            ->where('sales_board_cycle_baseline_id', $baseline->getKey())
            ->where('movement_type', SalesBoardMovementType::Sale)
            ->whereIn('conformity_status', SalesBoardNonconformityOrigin::decidableConformityStatuses())
            ->orderBy('id')
            ->get();

        if ($decidable->isEmpty()) {
            return [];
        }

        $movementIds = $review->nonconformities
            ->pluck('sales_board_cycle_movement_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $covered = $movementIds === []
            ? []
            : SalesBoardCycleMovement::query()
                ->whereKey($movementIds)
                ->get()
                ->map(fn (SalesBoardCycleMovement $movement): string => $this->naturalKey($movement))
                ->all();

        return $decidable
            ->reject(fn (SalesBoardCycleMovement $movement): bool => in_array($this->naturalKey($movement), $covered, true))
            ->map(fn (SalesBoardCycleMovement $movement): string => sprintf(
                '%s (%s)',
                $movement->contract_code ?? $movement->displayName(),
                $movement->conformity_status?->label() ?? '—',
            ))
            ->values()
            ->all();
    }

    /**
     * O mesmo fato em qualquer versão do ciclo. Um contrato produz no máximo uma
     * venda por competência -- a unique do movimento garante --, e o veredito
     * entra na chave para que uma venda cujo veredito mudou não herde a decisão
     * tomada sobre o anterior.
     */
    private function naturalKey(SalesBoardCycleMovement $movement): string
    {
        return implode('@', [
            $movement->movement_type->value,
            (int) $movement->contract_id,
            (int) $movement->construction_unit_id,
            $movement->conformity_status?->value ?? '',
        ]);
    }

    /**
     * As divergências da validação vinculada que nenhuma pendência cobre.
     *
     * @return list<string>
     */
    private function uncoveredDivergences(SalesBoardManagementReview $review): array
    {
        if ($review->sales_board_builder_review_id === null) {
            return [];
        }

        $covered = $review->nonconformities
            ->pluck('sales_board_builder_divergence_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return SalesBoardBuilderDivergence::query()
            ->where('sales_board_builder_review_id', $review->sales_board_builder_review_id)
            ->orderBy('id')
            ->get()
            ->reject(fn (SalesBoardBuilderDivergence $divergence): bool => in_array((int) $divergence->getKey(), $covered, true))
            ->map(fn (SalesBoardBuilderDivergence $divergence): string => sprintf(
                '#%d %s',
                (int) $divergence->getKey(),
                $divergence->type->label(),
            ))
            ->values()
            ->all();
    }

    /**
     * Nenhuma pendência sem decisão, nenhuma exigindo correção.
     *
     * As duas recusas são separadas porque significam coisas diferentes para
     * quem lê: uma diz "falta decidir", a outra diz "a fonte precisa ser
     * corrigida antes de qualquer publicação" -- e essa segunda não se resolve
     * nesta tela.
     */
    private function assertNonconformitiesResolved(SalesBoardManagementReview $review): void
    {
        $describe = fn (SalesBoardManagementNonconformity $item): string => sprintf(
            '#%d %s',
            (int) $item->getKey(),
            $item->origin->label(),
        );

        $pending = array_map($describe, $review->pendingNonconformities());

        if ($pending !== []) {
            throw SalesBoardManagementReviewException::nonconformitiesPending($pending);
        }

        $correction = array_map($describe, $review->correctionRequiredNonconformities());

        if ($correction !== []) {
            throw SalesBoardManagementReviewException::correctionRequired($correction);
        }

        /**
         * Defesa em profundidade: uma decisão sem motivo não deveria existir --
         * o serviço de decisão a recusa -- mas o portão não confia nisso, porque
         * o custo de estar errado aqui é uma posição publicada com uma exceção
         * que ninguém consegue explicar.
         */
        foreach ($review->nonconformities as $item) {
            if ($item->decision !== SalesBoardNonconformityDecision::Pending && blank($item->decision_reason)) {
                throw SalesBoardManagementReviewException::decisionReasonRequired();
            }
        }
    }

    /**
     * Traduz a situação da fonte na regra de override.
     *
     * `Material` e `Blocking` não têm override -- essa é a garantia central da
     * fase, e não existe caminho de código que a contorne. `SourceOnly` exige
     * justificativa. `None` recusa justificativa, porque não há o que
     * justificar: aceitar um texto ali criaria um registro dizendo que houve
     * override numa aprovação em que não houve.
     */
    private function resolveSourceOverride(SalesBoardStaleImpact $impact, ?string $reason): ?string
    {
        return match ($impact) {
            SalesBoardStaleImpact::Material,
            SalesBoardStaleImpact::Blocking => throw SalesBoardManagementReviewException::staleBlocksApproval($impact),
            SalesBoardStaleImpact::SourceOnly => $reason ?? throw SalesBoardManagementReviewException::sourceChangeReasonRequired(),
            SalesBoardStaleImpact::None => $reason === null
                ? null
                : throw SalesBoardManagementReviewException::sourceChangeReasonNotAllowed(),
        };
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            return null;
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }
}
