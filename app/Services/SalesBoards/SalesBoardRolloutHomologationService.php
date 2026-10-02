<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Enums\SalesBoardRolloutSupersessionReason;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\User;
use App\Support\SalesBoards\SalesBoardAccess;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O ciclo de vida de uma homologação: abrir, aceitar diferenças, atestar
 * impactos, aprovar, rejeitar.
 *
 * Aprovar **não** ativa. São dois atos com pré-condições e momentos diferentes:
 * entre um e outro a fonte pode mudar, um quadro manual pode aparecer, um
 * empreendimento pode entrar na Emissão. Fundi-los faria a aprovação carregar
 * uma promessa que ela não tem como cumprir.
 *
 * Cada ato confere o ator antes de qualquer derivação. Abrir, reavaliar,
 * analisar diferenças e rejeitar são de quem opera a competência
 * ({@see SalesBoardAccess::authorizeOperation()}); atestar, aprovar e ativar
 * são da Gestão ({@see SalesBoardApprovalAuthority}); conferir se uma
 * homologação aprovada ainda vale é de qualquer um dos dois lados. A tela
 * esconde o que cada um não pode fazer, mas não é ela que garante: uma chamada
 * forjada chega aqui sem passar por ela.
 *
 * Toda escrita numa homologação segue a mesma ordem de locks -- Emissão, depois
 * a homologação -- e relê o status sob eles. O guard do model compara com o
 * status que estava em memória, e uma tela aberta antes da aprovação ainda
 * acha que tem um rascunho na mão.
 */
class SalesBoardRolloutHomologationService
{
    public const MINIMUM_REASON_LENGTH = 10;

    public const MAXIMUM_REASON_LENGTH = 2000;

    public function __construct(
        private readonly SalesBoardRolloutAssessmentService $assessment,
        private readonly SalesBoardRolloutRecipientDirectory $recipients,
    ) {}

    /**
     * Abre a tentativa seguinte de homologação.
     *
     * A competência de comparação nasce como a anterior à de ativação: é a
     * fronteira natural entre a última competência produzida no legado e a
     * primeira que a automação vai produzir. Escolher outra é possível, mas
     * exige dizer por quê -- escolher "qualquer mês com dados" só para a
     * comparação ficar verde é o oposto de homologar.
     *
     * Uma tentativa aprovada e ainda não usada é marcada como substituída na
     * mesma transação: duas homologações "aprovadas" para a mesma Emissão
     * deixariam a trilha dizendo que as duas valem, e só a mais recente pode
     * sustentar uma ativação. A que já foi usada numa ativação não é tocada --
     * ela é o registro do que sustentou aquela ativação.
     */
    public function open(
        Emission $emission,
        CarbonImmutable $proposedStartReferenceMonth,
        ?User $actor,
        bool $autoOpenBuilderReview = false,
        ?CarbonImmutable $comparisonReferenceMonth = null,
        ?string $comparisonMonthReason = null,
    ): SalesBoardRolloutHomologation {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardAccess::authorizeOperation($actor);

        $start = $proposedStartReferenceMonth->startOfMonth();
        $comparison = ($comparisonReferenceMonth ?? $start->subMonth())->startOfMonth();

        return DB::transaction(function () use (
            $emission,
            $start,
            $comparison,
            $comparisonMonthReason,
            $autoOpenBuilderReview,
            $actor,
        ): SalesBoardRolloutHomologation {
            $locked = Emission::query()->whereKey($emission->getKey())->lockForUpdate()->firstOrFail();

            $open = $locked->salesBoardRolloutHomologations()
                ->where('status', SalesBoardRolloutHomologationStatus::Draft)
                ->first();

            if ($open !== null) {
                throw SalesBoardRolloutException::openHomologationExists((int) $open->attempt);
            }

            if ($locked->usesAutomatedSalesBoard()) {
                throw SalesBoardRolloutException::emissionAlreadyAutomated();
            }

            $this->assertEmissionOperating($locked);

            if ($this->assessment->constructionsOf($locked)->isEmpty()) {
                throw SalesBoardRolloutException::withoutConstructions();
            }

            $this->supersedeUnusedApprovedAttempts($locked);

            $homologation = SalesBoardRolloutHomologation::query()->create([
                'emission_id' => $locked->getKey(),
                'attempt' => $this->nextAttempt($locked),
                'status' => SalesBoardRolloutHomologationStatus::Draft,
                'proposed_start_reference_month' => $start->toDateString(),
                'comparison_reference_month' => $comparison->toDateString(),
                'comparison_month_reason' => $this->normalizeReason($comparisonMonthReason),
                'auto_open_builder_review' => $autoOpenBuilderReview,
                'created_by_user_id' => $actor->getKey(),
            ]);

            return $this->assessment->assess($homologation);
        });
    }

    /**
     * Refaz o retrato de um rascunho.
     *
     * A editabilidade é conferida sob lock, e não na instância da tela: entre o
     * clique em "Reavaliar" e a gravação, outra pessoa pode ter aprovado a
     * homologação, e reavaliar depois disso reescreveria as linhas e o hash de
     * um retrato já aprovado. Os locks seguem a ordem da aprovação -- Emissão e
     * depois homologação --, e as linhas e o hash são gravados na mesma
     * transação, para uma reavaliação e uma aprovação simultâneas se
     * serializarem em vez de se intercalarem.
     *
     * Reavaliar refaz a avaliação inteira e reescreve o retrato: é ato de quem
     * opera, e o ator é conferido antes de qualquer derivação.
     */
    public function reassess(SalesBoardRolloutHomologation $homologation, ?User $actor): SalesBoardRolloutHomologation
    {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardAccess::authorizeOperation($actor);

        return DB::transaction(function () use ($homologation): SalesBoardRolloutHomologation {
            Emission::query()
                ->whereKey($homologation->emission_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = SalesBoardRolloutHomologation::query()
                ->whereKey($homologation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked);

            return $this->assessment->assess($locked);
        });
    }

    /**
     * "Entendemos por que o motor novo produz uma posição diferente."
     *
     * Não é aprovação de quadro: nenhuma posição é publicada aqui. É o registro
     * de que uma pessoa olhou o delta e soube explicá-lo.
     */
    public function acceptDifference(
        SalesBoardRolloutHomologationConstruction $row,
        string $reason,
        ?User $actor,
    ): SalesBoardRolloutHomologationConstruction {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardAccess::authorizeOperation($actor);

        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw SalesBoardRolloutException::reasonRequired();
        }

        return DB::transaction(function () use ($row, $reason, $actor): SalesBoardRolloutHomologationConstruction {
            /**
             * A homologação é travada antes da linha, na ordem da aprovação e
             * da reavaliação: travar a linha primeiro deixaria a leitura do
             * status enxergar o rascunho que uma aprovação em andamento está
             * encerrando, e o aceite cairia numa homologação já aprovada.
             */
            $homologation = SalesBoardRolloutHomologation::query()
                ->whereKey(
                    SalesBoardRolloutHomologationConstruction::query()
                        ->whereKey($row->getKey())
                        ->value('sales_board_rollout_homologation_id')
                )
                ->lockForUpdate()
                ->firstOrFail();

            $row = SalesBoardRolloutHomologationConstruction::query()
                ->whereKey($row->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($homologation);

            $row->forceFill([
                'accepted_difference' => true,
                'difference_reason' => $reason,
                'accepted_at' => CarbonImmutable::now(),
                'accepted_by_user_id' => $actor->getKey(),
            ])->save();

            return $row->refresh();
        });
    }

    public function markGuaranteesReviewed(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
    ): SalesBoardRolloutHomologation {
        return $this->markReviewed($homologation, $actor, 'guarantees');
    }

    public function markMonthlyReportReviewed(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
    ): SalesBoardRolloutHomologation {
        return $this->markReviewed($homologation, $actor, 'monthly_report');
    }

    /**
     * A atestação é escrita no rascunho travado e relido, na ordem da
     * aprovação: Emissão e depois homologação.
     *
     * Atestar a instância que a tela carregou deixava passar a corrida em que
     * a aprovação commita enquanto a atestação espera: o guard do model olha o
     * status em memória -- ainda "em homologação" -- e a atestação regravava
     * quem e quando atestou numa homologação já aprovada. Relida sob o lock,
     * ela encontra a aprovação e é recusada.
     */
    private function markReviewed(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
        string $subject,
    ): SalesBoardRolloutHomologation {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        return DB::transaction(function () use ($homologation, $actor, $subject): SalesBoardRolloutHomologation {
            Emission::query()
                ->whereKey($homologation->emission_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = SalesBoardRolloutHomologation::query()
                ->whereKey($homologation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked);

            $locked->forceFill([
                $subject.'_reviewed_at' => CarbonImmutable::now(),
                $subject.'_reviewed_by_user_id' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Aprova a homologação -- e nada mais.
     *
     * A avaliação é refeita antes de aprovar e comparada com a que o operador
     * revisou. Se mudou, a aprovação é **recusada** em vez de aprovar a versão
     * nova: aprovar B quando a pessoa revisou A é exatamente o que a
     * homologação existe para impedir.
     */
    public function approve(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
        string $reason,
    ): SalesBoardRolloutHomologation {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardApprovalAuthority::assertMayApproveHomologation($actor, $homologation);

        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw SalesBoardRolloutException::reasonRequired();
        }

        return DB::transaction(function () use ($homologation, $actor, $reason): SalesBoardRolloutHomologation {
            $emission = Emission::query()
                ->whereKey($homologation->emission_id)
                ->lockForUpdate()
                ->firstOrFail();

            $homologation = SalesBoardRolloutHomologation::query()
                ->whereKey($homologation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($homologation);

            if ($emission->usesAutomatedSalesBoard()) {
                throw SalesBoardRolloutException::emissionAlreadyAutomated();
            }

            /**
             * Antes da reavaliação, que custa uma derivação: uma Emissão que
             * voltou à elaboração -- ou foi liquidada -- depois da abertura não
             * tem o que aprovar.
             */
            $this->assertEmissionOperating($emission);

            $reviewedHash = (string) $homologation->assessment_hash;
            $reviewedScope = (string) $homologation->construction_scope_hash;

            $fresh = $this->assessment->assess($homologation);

            if ((string) $fresh->assessment_hash !== $reviewedHash) {
                throw SalesBoardRolloutException::assessmentStale();
            }

            if ((string) $fresh->construction_scope_hash !== $reviewedScope) {
                throw SalesBoardRolloutException::scopeChanged();
            }

            $this->assertHomologable($fresh, $emission);

            $fresh->forceFill([
                'status' => SalesBoardRolloutHomologationStatus::Approved,
                'approved_at' => CarbonImmutable::now(),
                'approved_by_user_id' => $actor->getKey(),
                'approval_reason' => $reason,
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * "Conferir se ainda vale": a homologação aprovada ainda descreve o mundo?
     *
     * Observa a fonte e o escopo de agora sem reescrever o retrato aprovado --
     * a mesma reconferência da ativação, sem ativar. Se mudaram, a homologação
     * é marcada como substituída, com o motivo, e devolve-se esse motivo; se
     * não, nada é gravado e o retorno é `null`.
     *
     * A derivação só é paga neste ato deliberado e na ativação, nunca ao
     * renderizar a tela. Quem prepara e quem aprova podem conferir: o que se
     * descobre aqui é um fato, e não uma decisão sobre ele.
     */
    public function supersedeIfOutdated(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
    ): ?SalesBoardRolloutSupersessionReason {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardAccess::authorizeOperationOrApproval($actor);

        return DB::transaction(function () use ($homologation): ?SalesBoardRolloutSupersessionReason {
            $emission = Emission::query()
                ->whereKey($homologation->emission_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = SalesBoardRolloutHomologation::query()
                ->whereKey($homologation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved() || $locked->wasActivated()) {
                throw SalesBoardRolloutException::homologationNotCheckable();
            }

            $reason = $this->outdatedReason($locked, $emission);

            if ($reason !== null) {
                $this->markSuperseded($locked, $reason);
            }

            return $reason;
        });
    }

    /**
     * Por que a homologação deixou de descrever o mundo, ou `null`.
     *
     * Escopo primeiro, porque é a leitura barata e a recusa mais específica;
     * depois a observação da fonte, que custa uma derivação e é comparada com o
     * hash aprovado sem gravar nada.
     */
    public function outdatedReason(
        SalesBoardRolloutHomologation $homologation,
        Emission $emission,
    ): ?SalesBoardRolloutSupersessionReason {
        $currentScope = $this->assessment->scopeHash(
            $this->assessment->constructionsOf($emission)->keys()->all()
        );

        if ($currentScope !== (string) $homologation->construction_scope_hash) {
            return SalesBoardRolloutSupersessionReason::ScopeChanged;
        }

        if ($this->assessment->observe($homologation)->assessmentHash !== (string) $homologation->assessment_hash) {
            return SalesBoardRolloutSupersessionReason::SourceChanged;
        }

        return null;
    }

    /**
     * Grava a substituição numa homologação já travada pelo chamador.
     *
     * É a única escrita que a homologação aprovada admite além do registro de
     * uso ({@see SalesBoardRolloutHomologation::FINAL_MUTABLE_FIELDS}), e ela é
     * irreversível: uma fonte que volta ao estado anterior não ressuscita a
     * aprovação -- a Gestão olha de novo, numa tentativa nova.
     */
    public function markSuperseded(
        SalesBoardRolloutHomologation $locked,
        SalesBoardRolloutSupersessionReason $reason,
    ): SalesBoardRolloutHomologation {
        $locked->forceFill([
            'status' => SalesBoardRolloutHomologationStatus::Superseded,
            'superseded_at' => CarbonImmutable::now(),
            'superseded_reason' => $reason->value,
        ])->save();

        return $locked;
    }

    public function reject(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
        string $reason,
    ): SalesBoardRolloutHomologation {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        SalesBoardAccess::authorizeOperation($actor);

        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw SalesBoardRolloutException::reasonRequired();
        }

        return DB::transaction(function () use ($homologation, $actor, $reason): SalesBoardRolloutHomologation {
            $homologation = SalesBoardRolloutHomologation::query()
                ->whereKey($homologation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($homologation);

            $homologation->forceFill([
                'status' => SalesBoardRolloutHomologationStatus::Rejected,
                'rejected_at' => CarbonImmutable::now(),
                'rejected_by_user_id' => $actor->getKey(),
                'rejection_reason' => $reason,
            ])->save();

            return $homologation->refresh();
        });
    }

    /**
     * A Emissão está em operação: fora da elaboração e não liquidada.
     *
     * Em elaboração, a posição inicial ainda está sendo composta -- o
     * `EmissionObserver` só a consolida ao sair do rascunho --, e a homologação
     * compararia contra algo que ainda vai mudar; ativada assim, a automação
     * ficaria bloqueada todo dia pela geração. Liquidada, a operação acabou e
     * não há competência a automatizar.
     *
     * Vale nos três atos -- abrir, aprovar e ativar --, porque a Emissão pode
     * voltar ao rascunho entre um e outro. Pública porque a ativação a chama sob
     * o próprio lock, e a tela a usa para explicar o botão desabilitado.
     *
     * @throws SalesBoardRolloutException
     */
    public function assertEmissionOperating(Emission $emission): void
    {
        if ($emission->isInDraft()) {
            throw SalesBoardRolloutException::emissionInDraft();
        }

        if ($emission->isLiquidated()) {
            throw SalesBoardRolloutException::emissionLiquidated();
        }
    }

    /**
     * Tudo o que precisa ser verdade para a homologação sustentar uma ativação.
     *
     * Público porque a tela mostra exatamente esta lista, e a ativação a
     * reexecuta. Três cópias da mesma regra divergiriam na primeira que mudasse.
     *
     * @return array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>}
     */
    public function gate(SalesBoardRolloutHomologation $homologation, Emission $emission): array
    {
        $rows = $homologation->constructions()->with('construction')->get();

        $notReady = $rows->reject(fn (SalesBoardRolloutHomologationConstruction $row): bool => $row->is_ready);
        $unacknowledged = $rows->filter(
            fn (SalesBoardRolloutHomologationConstruction $row): bool => $row->requiresAcknowledgement()
        );
        $conflicts = $this->legacyConflicts($homologation);

        $operational = $this->recipients->activeFor($emission, SalesBoardRolloutRecipientRole::Operational);
        $management = $this->recipients->activeFor($emission, SalesBoardRolloutRecipientRole::Management);

        $checks = [
            [
                'label' => 'Emissão em operação (fora de elaboração e não liquidada)',
                'passed' => ! $emission->isInDraft() && ! $emission->isLiquidated(),
                'detail' => 'Situação atual: '.$emission->status_label,
            ],
            [
                'label' => 'Empreendimentos avaliados',
                'passed' => $rows->isNotEmpty(),
                'detail' => sprintf('%d empreendimento(s).', $rows->count()),
            ],
            [
                'label' => 'Fonte pronta em todos os empreendimentos',
                'passed' => $notReady->isEmpty(),
                'detail' => $notReady->isEmpty()
                    ? null
                    : sprintf('%d com cadastro incompleto.', $notReady->count()),
            ],
            [
                'label' => 'Comparações com o legado analisadas',
                'passed' => $unacknowledged->isEmpty(),
                'detail' => $unacknowledged->isEmpty()
                    ? null
                    : sprintf('%d diferença(s) sem análise registrada.', $unacknowledged->count()),
            ],
            [
                'label' => 'Impacto sobre as Garantias revisado',
                'passed' => $homologation->guaranteesReviewed(),
                'detail' => null,
            ],
            [
                'label' => 'Impacto sobre o Relatório Mensal revisado',
                'passed' => $homologation->monthlyReportReviewed(),
                'detail' => null,
            ],
            [
                'label' => 'Responsável operacional definido',
                'passed' => $operational !== [],
                'detail' => sprintf('%d ativo(s).', count($operational)),
            ],
            [
                'label' => 'Responsável da Gestão definido',
                'passed' => $management !== [],
                'detail' => sprintf('%d ativo(s).', count($management)),
            ],
            [
                'label' => 'Sem Quadro de Vendas a partir da competência inicial',
                'passed' => $conflicts === [],
                'detail' => $conflicts === []
                    ? null
                    : sprintf('%d conflito(s) com registro manual.', count($conflicts)),
            ],
            [
                'label' => 'Escopo de empreendimentos íntegro',
                'passed' => (string) $homologation->construction_scope_hash
                    === $this->assessment->scopeHash($this->assessment->constructionsOf($emission)->keys()->all()),
                'detail' => null,
            ],
        ];

        return [
            'ready' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            'checks' => $checks,
        ];
    }

    /**
     * Empreendimentos com quadro manual a partir da competência de ativação.
     *
     * @return list<string>
     */
    public function legacyConflicts(SalesBoardRolloutHomologation $homologation): array
    {
        return $homologation->constructions()
            ->with('construction')
            ->whereNotNull('latest_legacy_board_month')
            ->get()
            ->map(fn (SalesBoardRolloutHomologationConstruction $row): string => sprintf(
                '%s (até %s)',
                (string) ($row->construction?->development_name ?? '#'.$row->construction_id),
                $row->latest_legacy_board_month?->format('m/Y') ?? '—',
            ))
            ->values()
            ->all();
    }

    /**
     * As tentativas aprovadas e ainda não usadas da Emissão, travadas e
     * marcadas como substituídas pela tentativa que está sendo aberta. A
     * Emissão já está travada pelo chamador: a ordem é a de sempre.
     */
    private function supersedeUnusedApprovedAttempts(Emission $locked): void
    {
        SalesBoardRolloutHomologation::query()
            ->where('emission_id', $locked->getKey())
            ->where('status', SalesBoardRolloutHomologationStatus::Approved)
            ->whereNull('activated_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(fn (SalesBoardRolloutHomologation $attempt): SalesBoardRolloutHomologation => $this->markSuperseded(
                $attempt,
                SalesBoardRolloutSupersessionReason::NewAttemptOpened,
            ));
    }

    private function assertHomologable(SalesBoardRolloutHomologation $homologation, Emission $emission): void
    {
        $rows = $homologation->constructions()->with('construction')->get();

        if ($rows->isEmpty()) {
            throw SalesBoardRolloutException::withoutConstructions();
        }

        $describe = fn (SalesBoardRolloutHomologationConstruction $row): string => (string) (
            $row->construction?->development_name ?? '#'.$row->construction_id
        );

        $notReady = $rows->reject(fn (SalesBoardRolloutHomologationConstruction $row): bool => $row->is_ready);

        if ($notReady->isNotEmpty()) {
            throw SalesBoardRolloutException::constructionsNotReady($notReady->map($describe)->values()->all());
        }

        $unacknowledged = $rows->filter(
            fn (SalesBoardRolloutHomologationConstruction $row): bool => $row->requiresAcknowledgement()
        );

        if ($unacknowledged->isNotEmpty()) {
            throw SalesBoardRolloutException::comparisonsNotAcknowledged(
                $unacknowledged->map($describe)->values()->all()
            );
        }

        if (! $homologation->guaranteesReviewed()) {
            throw SalesBoardRolloutException::guaranteesNotReviewed();
        }

        if (! $homologation->monthlyReportReviewed()) {
            throw SalesBoardRolloutException::monthlyReportNotReviewed();
        }

        $this->recipients->assertConfigured($emission);

        $conflicts = $this->legacyConflicts($homologation);

        if ($conflicts !== []) {
            throw SalesBoardRolloutException::legacyBoardConflict(
                $conflicts,
                $homologation->startMonthLabel(),
            );
        }
    }

    private function assertEditable(SalesBoardRolloutHomologation $homologation): void
    {
        if (! $homologation->isEditable()) {
            throw SalesBoardRolloutException::homologationNotEditable();
        }
    }

    private function nextAttempt(Emission $emission): int
    {
        return ((int) SalesBoardRolloutHomologation::query()
            ->where('emission_id', $emission->getKey())
            ->max('attempt')) + 1;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
            return null;
        }

        return mb_substr($reason, 0, self::MAXIMUM_REASON_LENGTH);
    }
}
