<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardApprovalResult;
use App\DTOs\SalesBoards\SalesBoardCompetenceBridge;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardRectificationStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Events\SalesBoards\SalesBoardPriorPositionChanged;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Exceptions\SalesBoardRectificationException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
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
 *
 * A obra é a exceção, em modo compartilhado e logo depois do ciclo -- antes de
 * qualquer leitura comum, que é o que fixa o instantâneo do `REPEATABLE READ`.
 * A política de desconto é registrada com a obra travada em modo exclusivo
 * ({@see SalesDiscountPolicyRegistrar}), e a obra é o ponto em que as duas se
 * encontram: ou o registro commita antes e a derivação daqui enxerga a política
 * (a aprovação recusa por alteração material), ou ele espera esta aprovação
 * terminar e enxerga a publicação (a política que alcança a competência é
 * recusada). Com a obra lida só no fim, na publicação, uma política commitada
 * durante a derivação passaria pelas duas checagens -- a competência sairia
 * publicada com a régua antiga e a política nova valeria sobre ela.
 *
 * Três regras que a apuração por competência anterior trouxe:
 *
 * - **ordem**: aprovar M exige que a âncora -- M-1 ou, com M-1 cancelada, a
 *   primeira competência não cancelada antes dela -- esteja aprovada sem
 *   retificação aberta ({@see SalesBoardPriorCompetenceGate}); sem ciclo antes
 *   da cadeia de canceladas não há exigência. Os ciclos da cadeia são lidos com
 *   `sharedLock()` depois do lock de M -- do mês mais recente para o mais
 *   antigo, a ordem de locks do Quadro;
 * - **nada publicado depois**: fora da retificação, M não é publicada quando
 *   uma competência posterior do empreendimento já foi. A posterior já reflete
 *   os fatos de M, e os que chegaram depois dela entram como extemporâneos na
 *   seguinte; publicar M daria a eles um segundo dono. É a mesma regra da
 *   reabertura recusada, e a saída também: cancelar M. Protege o ciclo gerado
 *   antes da recusa da geração, ou numa corrida com ela;
 * - **retificação**: com retificação aberta, a aprovação publica de novo o
 *   mesmo quadro ({@see SalesBoardPublicationService::republish()}), é isenta
 *   da cobertura da automação (funciona depois de voltar ao legado), recusa se
 *   outra competência foi publicada depois ou se a versão ficou igual à
 *   publicada, e fecha a retificação. Quem abriu a retificação não aprova.
 *
 * O instantâneo do `REPEATABLE READ` nasce na primeira leitura comum da
 * transação, e por isso nenhuma leitura comum acontece antes do portão de
 * ordem: as esperas pela obra (o registro de uma política) e pelos locks da
 * cadeia (uma reabertura, um cancelamento ou uma retificação em andamento)
 * terminam antes de a fonte ser derivada, e a derivação enxerga a obra e a
 * cadeia como o outro ato as deixou.
 */
class SalesBoardManagementApprovalService
{
    /**
     * Versão do texto que a Gestão aceita ao aprovar. Congelada junto com a
     * aprovação para que, se o texto mudar, se saiba qual foi aceito.
     */
    public const DECLARATION_VERSION = '2026-10-v2';

    public function __construct(
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
        private readonly SalesBoardPublicationService $publicationService,
        private readonly SalesBoardPublicationProjection $projection,
        private readonly SalesBoardPriorCompetenceGate $priorCompetenceGate,
        private readonly SalesBoardCompetenceBridgeBuilder $bridgeBuilder,
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
             *
             * Até o portão de ordem a transação faz só leituras travadas --
             * inclusive a da obra, logo abaixo --, e o instantâneo dela nasce
             * depois das esperas da obra e da cadeia.
             */
            $cycle = SalesBoardCycle::query()
                ->whereKey($review->sales_board_cycle_id)
                ->lockForUpdate()
                ->firstOrFail();

            /**
             * A obra, compartilhada, entre o ciclo e a análise -- e por leitura
             * travada, nunca por eager load: uma leitura comum aqui fixaria o
             * instantâneo antes de esperar o registro de política em andamento
             * e os locks da cadeia, no portão de ordem.
             * Ninguém trava a obra em modo exclusivo e depois pede um ciclo, nem
             * trava um ciclo e depois pede a obra em modo exclusivo -- a
             * reabertura, que trava os ciclos posteriores antes do próprio, lê a
             * obra sem lock --, e a geração da competência seguinte pega a obra
             * em modo compartilhado (a FK do ciclo novo): a ordem não cria espera
             * circular.
             */
            $cycle->setRelation('construction', Construction::query()
                ->whereKey($cycle->construction_id)
                ->sharedLock()
                ->firstOrFail());

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

            /**
             * A regra de ordem, com a cadeia até a âncora lida em modo
             * compartilhado depois do lock deste ciclo. Vale também para a
             * aprovação de uma retificação.
             */
            $this->priorCompetenceGate->assertClosed($cycle);

            $rectification = SalesBoardCycleRectification::query()
                ->where('sales_board_cycle_id', $cycle->getKey())
                ->where('status', SalesBoardRectificationStatus::Open->value)
                ->lockForUpdate()
                ->first();

            $this->assertReviewOpen($review);
            $this->assertCycleInManagement($cycle);

            /**
             * A retificação corrige uma posição que já foi publicada pelo ciclo:
             * ela continua possível depois de a Emissão voltar ao legado, que é
             * justamente quando a competência deixa de ser coberta. E ela tem a
             * própria conferência de "publicada depois"
             * ({@see self::assertRectificationStillApplies()}).
             */
            if (! $rectification instanceof SalesBoardCycleRectification) {
                $this->assertCoveredByAutomation($cycle);
                $this->assertNoLaterPublication($cycle);
            }

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

            if ($rectification instanceof SalesBoardCycleRectification) {
                $this->assertRectificationStillApplies($cycle, $baseline);
            }

            /**
             * A projeção acontece antes da publicação e recusa baseline
             * incompleto ou com unidade indeterminada. Chamá-la aqui é defesa em
             * profundidade: mesmo que uma inconsistência tivesse deixado passar
             * uma versão incompleta, ela não chega a virar quadro.
             */
            $payload = $this->projection->project($cycle, $baseline);

            $assessment = $this->staleDetectionService->assessWithoutPersisting($cycle, $baseline);

            $sourceChangeReason = $this->resolveSourceOverride($assessment->impact, $sourceChangeReason);

            $publication = $rectification instanceof SalesBoardCycleRectification
                ? $this->publicationService->republish(
                    cycle: $cycle,
                    baseline: $baseline,
                    review: $review,
                    assessment: $assessment,
                    sourceChangeReason: $sourceChangeReason,
                    actor: $actor,
                    rectification: $rectification,
                )
                : $this->publicationService->publish(
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

            if ($rectification instanceof SalesBoardCycleRectification) {
                $rectification->forceFill([
                    'status' => SalesBoardRectificationStatus::Published,
                    'closed_at' => $now,
                    'closed_by_user_id' => $actor->getKey(),
                ])->save();

                /**
                 * A posição publicada desta competência mudou: as seguintes em
                 * andamento são conferidas de novo depois do commit.
                 */
                SalesBoardPriorPositionChanged::dispatch(
                    (int) $cycle->construction_id,
                    CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth(),
                    SalesBoardPriorPositionChanged::RECTIFICATION_PUBLISHED,
                );
            }

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
     * A "Ponte com a competência anterior" entra como item informativo
     * (`informative`): ela não bloqueia -- correções legítimas sem fato datado
     * também aparecem nela --, mas a Gestão a confere antes de aprovar. Quem já
     * montou a ponte a entrega pronta.
     *
     * @return array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null, informative?: bool}>, impact: SalesBoardStaleImpact|null}
     */
    public function gate(SalesBoardManagementReview $review, ?SalesBoardCompetenceBridge $bridge = null): array
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

        $rectification = ($cycle === null) ? null : SalesBoardCycleRectification::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardRectificationStatus::Open->value)
            ->with('rectifiedPublication')
            ->first();

        $legacy = ($cycle === null) ? null : $this->publicationService->existingPosition($cycle);

        /**
         * Na retificação, o quadro registrado da competência é o próprio quadro
         * publicado -- é ele que a aprovação vai atualizar.
         */
        if (($legacy !== null) && ($rectification !== null)
            && ((int) $legacy->getKey() === (int) $rectification->rectifiedPublication?->sales_board_id)) {
            $legacy = null;
        }

        $uncoveredSales = ($baseline === null) ? [] : $this->uncoveredSales($review, $baseline);
        $uncoveredDivergences = $this->uncoveredDivergences($review);

        $emission = ($cycle === null) ? null : Emission::query()->find($cycle->emission_id);
        $automated = ($cycle !== null) && $this->automationCovers($emission, $cycle);

        $priorRefusal = ($cycle === null) ? null : $this->priorCompetenceGate->assess($cycle);
        $laterPublished = (($cycle === null) || ($rectification !== null)) ? null : $this->laterPublishedMonth($cycle);

        $checks = [
            [
                'label' => 'Competência coberta pela automação',
                'passed' => $automated || ($rectification !== null),
                'detail' => match (true) {
                    $automated || ($cycle === null) => null,
                    $rectification !== null => 'Retificação de competência publicada: não depende da cobertura da automação.',
                    default => sprintf(
                        'A Emissão não cobre %s pela automação. Use "Cancelar competência", na tela da competência, para encerrá-la.',
                        $cycle->reference_month->format('m/Y'),
                    ),
                },
            ],
            [
                'label' => 'Competência anterior encerrada',
                'passed' => $priorRefusal === null,
                'detail' => $priorRefusal,
            ],
            ...($rectification !== null || $cycle === null ? [] : [[
                'label' => 'Nenhuma competência posterior publicada',
                'passed' => $laterPublished === null,
                'detail' => $laterPublished === null
                    ? null
                    : SalesBoardManagementReviewException::laterCompetencePublished(
                        $cycle->reference_month->format('m/Y'),
                        $laterPublished->format('m/Y'),
                    )->getMessage(),
            ]]),
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
                /**
                 * A cadeia que mudou sem mudar número nenhum não aparece no
                 * diff: o motivo vai junto, para a Gestão saber o que recalcular.
                 */
                'detail' => ($assessment?->chainChange === null)
                    ? $impact?->label()
                    : $impact?->label().'. '.$assessment->chainChange,
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
            ...($rectification === null || $cycle === null ? [] : $this->rectificationChecks($cycle, $baseline, $rectification)),
            ...$this->bridgeCheck($bridge, $baseline),
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

    /**
     * Os dois itens próprios da retificação: ela continua sendo da última
     * competência publicada, e a versão em análise muda a posição publicada.
     *
     * @return list<array{label: string, passed: bool, detail: string|null}>
     */
    private function rectificationChecks(SalesBoardCycle $cycle, ?SalesBoardCycleBaseline $baseline, SalesBoardCycleRectification $rectification): array
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();
        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $cycle->construction_id);
        $stillLast = ($lastPublished === null) || ! $lastPublished->greaterThan($month);

        $publishedFingerprint = (string) $rectification->rectifiedPublication?->snapshot_fingerprint;
        $changes = ($baseline !== null) && ((string) $baseline->snapshot_fingerprint !== $publishedFingerprint);

        return [
            [
                'label' => 'Última competência publicada do empreendimento',
                'passed' => $stillLast,
                'detail' => $stillLast ? null : sprintf('%s foi publicada depois desta competência.', $lastPublished->format('m/Y')),
            ],
            [
                'label' => 'A retificação muda a posição publicada',
                'passed' => $changes,
                'detail' => $changes ? null : 'A versão em análise é igual à posição publicada.',
            ],
        ];
    }

    /**
     * O item informativo da ponte, quando há competência anterior.
     *
     * @return list<array{label: string, passed: bool, detail: string|null, informative: bool}>
     */
    private function bridgeCheck(?SalesBoardCompetenceBridge $bridge, ?SalesBoardCycleBaseline $baseline): array
    {
        if (($bridge === null) && ($baseline !== null)) {
            $bridge = $this->bridgeBuilder->forBaseline($baseline);
        }

        if (($bridge === null) || ! $bridge->hasAnchor()) {
            return [];
        }

        return [[
            'label' => 'Ponte com a competência anterior',
            'passed' => true,
            'detail' => $bridge->summary(),
            'informative' => true,
        ]];
    }

    /**
     * A retificação continua cabendo no instante da aprovação.
     *
     * Outra competência do empreendimento publicada no meio do caminho -- só
     * possível por um caminho que contorne a regra de ordem, como um estado
     * anterior a ela -- e a versão em análise igual à publicada são recusadas:
     * a primeira republicaria uma posição que já não é a última, e a segunda
     * criaria uma publicação sem nada a publicar.
     */
    private function assertRectificationStillApplies(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline): void
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();

        /**
         * Leitura comum, sem lock: travar os ciclos posteriores depois deste
         * inverteria a ordem de locks do Quadro. A competência seguinte não é
         * publicada enquanto esta está em retificação -- a regra de ordem da
         * aprovação dela a segura --, e esta conferência é a defesa para o que
         * tiver contornado essa regra.
         */
        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $cycle->construction_id);

        if (($lastPublished !== null) && $lastPublished->greaterThan($month)) {
            throw SalesBoardRectificationException::noLongerLastPublished($month->format('m/Y'), $lastPublished->format('m/Y'));
        }

        $publishedFingerprint = SalesBoardPublication::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->orderByDesc('sequence_number')
            ->value('snapshot_fingerprint');

        if ((string) $publishedFingerprint === (string) $baseline->snapshot_fingerprint) {
            throw SalesBoardRectificationException::unchangedAtApproval($month->format('m/Y'));
        }
    }

    /**
     * Fora da retificação, a competência só é publicada se nenhuma posterior
     * do empreendimento já foi.
     *
     * Leitura comum, sem lock: travar os ciclos posteriores depois deste
     * inverteria a ordem de locks do Quadro. O instantâneo nasce depois do
     * portão de ordem, e a competência posterior em aprovação neste instante
     * não termina -- a regra de ordem dela espera esta, que é a âncora dela.
     *
     * @throws SalesBoardManagementReviewException
     */
    private function assertNoLaterPublication(SalesBoardCycle $cycle): void
    {
        $laterPublished = $this->laterPublishedMonth($cycle);

        if ($laterPublished !== null) {
            throw SalesBoardManagementReviewException::laterCompetencePublished(
                $cycle->reference_month->format('m/Y'),
                $laterPublished->format('m/Y'),
            );
        }
    }

    /**
     * A última competência publicada do empreendimento, quando ela é posterior
     * a esta -- ou `null`.
     */
    private function laterPublishedMonth(SalesBoardCycle $cycle): ?CarbonImmutable
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();
        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $cycle->construction_id);

        return ($lastPublished !== null) && $lastPublished->greaterThan($month) ? $lastPublished : null;
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
