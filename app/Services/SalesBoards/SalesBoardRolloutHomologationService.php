<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\User;
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

            if ($this->assessment->constructionsOf($locked)->isEmpty()) {
                throw SalesBoardRolloutException::withoutConstructions();
            }

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

    public function reassess(SalesBoardRolloutHomologation $homologation): SalesBoardRolloutHomologation
    {
        $this->assertEditable($homologation);

        return $this->assessment->assess($homologation->fresh());
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

        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw SalesBoardRolloutException::reasonRequired();
        }

        return DB::transaction(function () use ($row, $reason, $actor): SalesBoardRolloutHomologationConstruction {
            $row = SalesBoardRolloutHomologationConstruction::query()
                ->whereKey($row->getKey())
                ->lockForUpdate()
                ->with('homologation')
                ->firstOrFail();

            $this->assertEditable($row->homologation);

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

    private function markReviewed(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
        string $subject,
    ): SalesBoardRolloutHomologation {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

        $this->assertEditable($homologation);

        $homologation->forceFill([
            $subject.'_reviewed_at' => CarbonImmutable::now(),
            $subject.'_reviewed_by_user_id' => $actor->getKey(),
        ])->save();

        return $homologation->refresh();
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

    public function reject(
        SalesBoardRolloutHomologation $homologation,
        ?User $actor,
        string $reason,
    ): SalesBoardRolloutHomologation {
        if ($actor === null) {
            throw SalesBoardRolloutException::actorRequired();
        }

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
