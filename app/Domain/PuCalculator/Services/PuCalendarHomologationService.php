<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCalendarHomologationDecision;
use App\Domain\PuCalculator\Enums\PuCalendarHomologationStatus;
use App\Domain\PuCalculator\Exceptions\PuCalendarHomologationException;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\PuCalendarHomologation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class PuCalendarHomologationService
{
    public function __construct(
        private readonly PuCalendarHomologationGuard $guard,
        private readonly PuCalendarHomologationComparisonService $comparison,
        private readonly BusinessCalendarSelectionEvidenceService $selectionEvidence,
    ) {}

    /**
     * @param array{
     *   candidate_calendar_code:string,
     *   period_start:string,
     *   period_end:string,
     *   purpose?:string,
     *   evidence_matrix?:list<array<string,mixed>>,
     *   external_reference?:array<string,mixed>,
     *   primary_evidence?:array<string,mixed>
     * } $data
     */
    public function createDraft(Emission $emission, array $data, User $analyst): PuCalendarHomologation
    {
        $this->authorize($analyst, AccessPermission::PuCalendarHomologationExecute);
        $activeParameter = $this->guard->activeCdiParameter($emission);
        $candidateCalendar = $this->guard->assertCandidateCalendarAllowed((string) $data['candidate_calendar_code']);
        $periodStart = CarbonImmutable::parse((string) $data['period_start']);
        $periodEnd = CarbonImmutable::parse((string) $data['period_end']);
        $this->guard->assertPeriod($activeParameter, $periodStart, $periodEnd);
        $legacySnapshot = $this->comparison->parameterSnapshot($activeParameter);
        $candidateSnapshot = [
            ...$legacySnapshot,
            'curve_end_date' => $periodEnd->toDateString(),
            'calendar_code' => $candidateCalendar->code,
            'legacy_projection_enabled' => false,
        ];

        return DB::transaction(function () use (
            $emission,
            $data,
            $analyst,
            $candidateCalendar,
            $periodStart,
            $periodEnd,
            $legacySnapshot,
            $candidateSnapshot,
        ): PuCalendarHomologation {
            $homologation = PuCalendarHomologation::query()->create([
                'emission_id' => $emission->id,
                'candidate_calendar_code' => $candidateCalendar->code,
                'purpose' => $data['purpose'] ?? 'cdi_accrual_dup_and_lookup_lag',
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => PuCalendarHomologationStatus::Draft,
                'legacy_parameter_snapshot' => $legacySnapshot,
                'candidate_parameter_snapshot' => $candidateSnapshot,
                'evidence_matrix' => $data['evidence_matrix'] ?? null,
                'external_reference' => $data['external_reference'] ?? ['availability' => 'not_assessed'],
                'analyzed_by' => $analyst->id,
            ]);
            $primaryEvidence = $data['primary_evidence'] ?? [];

            if ($this->hasPrimaryEvidence($primaryEvidence)) {
                $this->selectionEvidence->record(
                    $homologation,
                    'pu_calendar_homologation_candidate',
                    $candidateCalendar->code,
                    Arr::only($primaryEvidence, [
                        'source_document',
                        'clause_reference',
                        'page_reference',
                        'excerpt',
                        'notes',
                    ]),
                    $analyst->id,
                    (bool) ($primaryEvidence['confirmed'] ?? false),
                );
            }

            return $homologation->fresh(['selectionEvidence']);
        });
    }

    public function execute(PuCalendarHomologation $homologation, User $executor): PuCalendarHomologation
    {
        $this->authorize($executor, AccessPermission::PuCalendarHomologationExecute);
        $homologation->refresh();
        $this->assertDraft($homologation);
        $this->guard->assertEvidenceRecorded($homologation->evidence_matrix ?? []);
        $this->guard->assertPrimarySelectionEvidence($homologation);
        $this->assertActiveParameterUnchanged($homologation);
        $comparison = $this->comparison->compare(
            $homologation->emission,
            $homologation->candidate_calendar_code,
            CarbonImmutable::instance($homologation->period_start),
            CarbonImmutable::instance($homologation->period_end),
        );

        $homologation->update([
            'calendar_governance_snapshot' => $comparison->calendarGovernance,
            'result_summary' => [
                ...$comparison->summary,
                'side_effect_free' => true,
            ],
            'daily_diff' => $comparison->dailyDiff,
            'first_divergence' => $comparison->firstDivergence,
            'comparison_checksum' => $comparison->checksum,
            'executed_by' => $executor->id,
            'executed_at' => now(),
            'decision' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        return $homologation->fresh();
    }

    public function submitForReview(PuCalendarHomologation $homologation, User $submitter): PuCalendarHomologation
    {
        $this->authorize($submitter, AccessPermission::PuCalendarHomologationExecute);
        $homologation->refresh();
        $this->assertDraft($homologation);

        if ($homologation->executed_at === null || $homologation->comparison_checksum === null) {
            throw new PuCalendarHomologationException('Execute a comparação antes de enviá-la para revisão.');
        }

        $this->assertActiveParameterUnchanged($homologation);
        $this->guard->assertEvidenceReadyForReview($homologation->evidence_matrix ?? []);
        $this->guard->assertPrimarySelectionEvidence($homologation, mustBeConfirmed: true);
        $this->guard->assertExternalReferenceAssessed($homologation->external_reference);
        $this->guard->assertGovernanceConfirmed($homologation->calendar_governance_snapshot ?? []);
        $homologation->update(['status' => PuCalendarHomologationStatus::ReadyForReview]);

        return $homologation->fresh();
    }

    public function approve(
        PuCalendarHomologation $homologation,
        User $reviewer,
        string $notes,
    ): PuCalendarHomologation {
        $this->authorize($reviewer, AccessPermission::PuCalendarHomologationReview);
        $homologation->refresh();

        if ($homologation->status !== PuCalendarHomologationStatus::ReadyForReview) {
            throw new PuCalendarHomologationException('Somente uma homologação pronta para revisão pode ser aprovada.');
        }

        $this->assertActiveParameterUnchanged($homologation);
        $this->guard->assertReviewerIsIndependent($homologation->analyzed_by, $homologation->executed_by, $reviewer);
        $homologation->update([
            'status' => PuCalendarHomologationStatus::Approved,
            'decision' => PuCalendarHomologationDecision::RecommendMigration,
            'conclusion_notes' => trim($notes),
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $homologation->fresh();
    }

    public function closeWithoutRecommendation(
        PuCalendarHomologation $homologation,
        User $reviewer,
        PuCalendarHomologationDecision $decision,
        string $notes,
    ): PuCalendarHomologation {
        $this->authorize($reviewer, AccessPermission::PuCalendarHomologationReview);
        $homologation->refresh();

        if (! in_array($homologation->status, [
            PuCalendarHomologationStatus::Draft,
            PuCalendarHomologationStatus::ReadyForReview,
        ], true)) {
            throw new PuCalendarHomologationException('Esta homologação já possui decisão final.');
        }

        if ($decision === PuCalendarHomologationDecision::RecommendMigration) {
            throw new PuCalendarHomologationException('Use o fluxo de aprovação para recomendar uma migração futura.');
        }

        if (blank($notes)) {
            throw new PuCalendarHomologationException('A conclusão sem recomendação precisa de justificativa.');
        }

        $homologation->update([
            'status' => PuCalendarHomologationStatus::Rejected,
            'decision' => $decision,
            'conclusion_notes' => trim($notes),
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $homologation->fresh();
    }

    private function assertDraft(PuCalendarHomologation $homologation): void
    {
        if ($homologation->status !== PuCalendarHomologationStatus::Draft) {
            throw new PuCalendarHomologationException('Somente homologações em rascunho podem ser executadas ou alteradas.');
        }
    }

    private function assertActiveParameterUnchanged(PuCalendarHomologation $homologation): void
    {
        $current = $this->comparison->parameterSnapshot(
            $this->guard->activeCdiParameter($homologation->emission),
        );

        if ($current !== $homologation->legacy_parameter_snapshot) {
            throw new PuCalendarHomologationException(
                'A configuração ativa mudou após a criação do cenário. Crie uma nova homologação para preservar a comparabilidade.',
            );
        }
    }

    /** @param  array<string, mixed>  $evidence */
    private function hasPrimaryEvidence(array $evidence): bool
    {
        return collect($evidence)
            ->except('confirmed')
            ->contains(fn (mixed $value): bool => filled($value));
    }

    private function authorize(User $actor, AccessPermission $permission): void
    {
        if ($actor->can($permission->value)) {
            return;
        }

        throw new AuthorizationException('Você não possui permissão para executar esta etapa da homologação CDI.');
    }
}
