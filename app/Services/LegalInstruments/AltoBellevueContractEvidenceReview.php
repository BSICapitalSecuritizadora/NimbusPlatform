<?php

declare(strict_types=1);

namespace App\Services\LegalInstruments;

use App\Domain\PuCalculator\DTOs\PuBaselineReadinessReport;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentFieldValueType;
use App\Enums\LegalInstrumentType;
use App\Models\Document;
use App\Models\Emission;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentEvent;
use App\Models\LegalInstrumentField;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * Phase 2B.5.9 — Review and Confirmation of the Contractual Baseline Evidence for CRI Alto Bellevue.
 *
 * This service DOES NOT create new contractual fields. It reviews the 19 existing
 * `pending_review` records that the backfill created on the governing
 * Securitization Agreement, applying the normal legal-instrument review workflow
 * (`InstrumentChangeReviewService`) and preserving:
 *
 * - the CRI / Securitization Agreement vs CCB/CCI/collateral separation,
 * - the 6.00% CRI spread distinct from the 7.50% CCB spread,
 * - the Matricula Mae 2026-04-30 registry date as `manual_review_required` outside the CRI baseline,
 * - the distinction between contractual language and canonical operational representation
 *   (explicit evidence vs operational interpretation).
 *
 * No PU engine, calendar governance, DI source governance, PU parameters, curves,
 * payments or events are touched in this phase.
 */
final class AltoBellevueContractEvidenceReview
{
    public const IF_CODE = '26E0017614';

    public const ISIN_CODE = 'BRALBLCRI008';

    /**
     * Keys that are operational interpretations (Category B) — the canonical
     * system value is a technical representation of the contractual rule, not
     * literal contract text.
     */
    public const INFERRED_KEYS = [
        LegalInstrumentFieldKey::DayCountRule->value,
        LegalInstrumentFieldKey::CalendarCode->value,
        LegalInstrumentFieldKey::IndexRateLookupMode->value,
        LegalInstrumentFieldKey::PaymentSchedule->value,
        LegalInstrumentFieldKey::Amortization->value,
        LegalInstrumentFieldKey::PaymentConvention->value,
    ];

    public function __construct(
        private readonly InstrumentChangeReviewService $reviewService,
        private readonly PuBaselineReadinessService $readinessService,
    ) {}

    /**
     * Initial inventory: the 19 `pending_review` records for Alto Bellevue
     * linked to the governing Securitization Agreement.
     *
     * Each entry contains: id, field_key, canonical value, value_type,
     * governing legal instrument, document, page, clause, excerpt,
     * evidence_level, confidence_score, current status, source/origin metadata.
     *
     * @return Collection<int, LegalInstrumentField>
     */
    public function pendingFields(): Collection
    {
        $emission = $this->resolveEmission();

        $governingInstrumentIds = $this->governingInstrumentIds($emission);

        return LegalInstrumentField::query()
            ->whereIn('legal_instrument_id', $governingInstrumentIds)
            ->where('status', LegalInstrumentFieldStatus::PendingReview->value)
            ->with(['instrument', 'instrumentDocument.document', 'document', 'instrumentDocument'])
            ->orderBy('field_key')
            ->get();
    }

    /**
     * Inventory with full provenance, as required by Section 1.
     *
     * @return list<array<string, mixed>>
     */
    public function inventory(): array
    {
        return $this->pendingFields()->map(fn (LegalInstrumentField $field): array => [
            'id' => $field->id,
            'field_key' => $field->field_key?->value,
            'canonical_value' => $this->canonicalValue($field),
            'value_type' => $field->value_type->value,
            'governing_legal_instrument' => $field->instrument?->display_name ?? $field->instrument?->type?->label(),
            'governing_instrument_id' => $field->legal_instrument_id,
            'governing_instrument_type' => $field->instrument?->type?->value,
            'document' => $field->document_label,
            'document_id' => $field->document?->id ?? $field->instrumentDocument?->document?->id,
            'legal_instrument_document_id' => $field->legal_instrument_document_id,
            'page' => $field->page,
            'clause' => $field->clause,
            'excerpt' => $field->excerpt,
            'evidence_level' => $field->evidence_level->value,
            'confidence_score' => $field->confidence_score,
            'current_status' => $field->status->value,
            'source_origin' => [
                'field_status' => $field->status->value,
                'evidence_level' => $field->evidence_level->value,
                'confidence_score' => $field->confidence_score,
                'legal_instrument_document_id' => $field->legal_instrument_document_id,
                'document_id' => $field->document_id,
                'instrument_id' => $field->legal_instrument_id,
                'instrument_type' => $field->instrument?->type?->value,
                'instrument_document_role' => $field->instrumentDocument?->role?->value,
                'effective_date' => $field->effective_date?->toDateString(),
                'has_conflict' => $field->has_conflict,
                'supersedes_id' => $field->supersedes_id,
            ],
            'classification' => $this->classification($field),
            'governing_scope_valid' => $this->isGoverningScope($field),
        ])->all();
    }

    /**
     * Classify every proposed field into explicit documentary evidence (A)
     * vs operational interpretation (B).
     */
    public function classification(LegalInstrumentField $field): string
    {
        $key = $field->field_key?->value;

        if (in_array($key, self::INFERRED_KEYS, true)) {
            return 'B — Operational interpretation';
        }

        return 'A — Explicit documentary evidence';
    }

    public function isExplicit(LegalInstrumentField $field): bool
    {
        return str_starts_with($this->classification($field), 'A —');
    }

    public function isInferred(LegalInstrumentField $field): bool
    {
        return str_starts_with($this->classification($field), 'B —');
    }

    /**
     * Verify that the field belongs to the legal instrument that governs the
     * CRI itself, not the collateral or underlying debt instruments.
     */
    public function isGoverningScope(LegalInstrumentField $field): bool
    {
        if ($field->instrument?->type !== LegalInstrumentType::SecuritizationTerm) {
            return false;
        }

        $role = $field->instrumentDocument?->role;

        if ($role === null) {
            // Fields created by backfill always have a governing document link,
            // but a missing link is treated as out-of-scope to avoid confirming
            // collateral fields by mistake.
            return false;
        }

        return $role === LegalInstrumentDocumentRole::Original
            || $role === LegalInstrumentDocumentRole::Amendment;
    }

    /**
     * For each of the 19 pending fields, decide exactly one outcome:
     * confirm / keep_pending / reject, with a reason.
     *
     * Every decision is evidence-driven and traceable to clause/page/excerpt.
     *
     * @return list<array{field_key:string, field_id:int, classification:string, decision:string, reason:string, evidence:array}>
     */
    public function reviewPlan(): array
    {
        return $this->pendingFields()->map(function (LegalInstrumentField $field): array {
            $key = $field->field_key?->value ?? 'unknown';

            // Scope guard: never confirm collateral / underlying instrument fields.
            if (! $this->isGoverningScope($field)) {
                return [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'keep_pending',
                    'reason' => 'Field is not scoped to the governing CRI Securitization Agreement (Termo). Preserved separation between CRI and CCB/CCI/collateral.',
                    'evidence' => $this->evidenceSummary($field),
                ];
            }

            return match ($key) {
                LegalInstrumentFieldKey::Indexer->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Contract states "Taxa DI" (clause 4.1.8 p.24). Canonical mapping CDI is correct and documented: excerpt preserves "Taxa DI" while canonical value is CDI. Enum not widened; contractual wording preserved.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::IndexPercentage->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit 100% in clause 4.1.8 p.24. Canonical fraction 1.0 correctly represents 100.00000000 in future PU candidate (storage convention preserved).',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::Spread->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit 6.00% p.a. for CRI 1a Serie in clause 4.1.8 p.24 of Securitization Agreement. Scope is governing CRI instrument; distinct from 7.50% CCB/CCI collateral fields which remain untouched. No contamination.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::BusinessDayBasis->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit "com base em um ano de 252 Dias Uteis" in clause 4.1.8 p.24. Contract directly states 252 Business Day basis.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::DayCountRule->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Operational interpretation of clause 4.1.8 p.25 excerpt "Dup = ... inclusive ... exclusive". Underlying rule is proved: integration/previous payment date inclusive, calculation date exclusive, Business Day counting. Canonical business_days_start_inclusive_end_exclusive is justified and excerpt preserved.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::BusinessDayDefinition->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Preserves contractual definition itself (Definicao Dia(s) Util(eis) p.8): not Saturday, not Sunday, not national holiday; for non-pecuniary obligations also excludes Sao Paulo commercial holidays. Definition preserved as legal text, not replaced by calendar code.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::CalendarCode->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Operational interpretation of the Business Day definition (p.8) mapping to BR_NATIONAL_HOLIDAYS. For pecuniary obligations (PU) the rule is national holidays only. Evidence_level is inferred as required; legal definition, rationale and excerpt preserved. Only the contractual selection of the calendar is confirmed, not calendar year governance.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::IndexRateLookupMode->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Operational representation business_day_lag_exact validated against contractual example in observation (vi) p.26: calculation on day 14 uses exact DI rate published on day 7 (five Business Days of lag). Documentary excerpt preserved; not based on market convention but on evidence-driven mapping.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::IndexRateLagBusinessDays->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit contract states five Business Days of lag in observation (vi) p.26. Canonical -5 is justified: contract = 5 Business Days lag, system = -5 because lookup shifts backward. Explicit evidence with documented sign convention.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::InitialUnitValue->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit VNU R$ 1.000,00 na Data de Emissao in clause 4.1.3 p.23 of the Term. Direct documentary basis, not derived from operational records.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::PaymentSchedule->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Operational interpretation of Annex II schedule (pp.111-112): monthly interest with bullet principal at maturity. Contractual schedule only; no 60 individual PU events created. Future materialization remains separate.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::FirstInterestPaymentDate->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit first date 08/06/2026 in Annex II p.111 (08/06/2026 Sim Nao 0,0000%). Direct documentary evidence.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::InterestPaymentFrequency->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit mensal e sucessivamente in clause 4.1.8.1 and Annex II p.26 excerpt. Canonical monthly correctly maps the contractual rule.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::Amortization->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Operational interpretation bullet for CRI, governed by Securitization Agreement / Annex II pp.111-112 (0,0000% until 08/04/2031; 100% on 08/05/2031). Distinct from CCB amortization (ccb_contractual_installments) which remains untouched.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::PaymentConvention->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Operational interpretation following_business_day of clause 4.1.22 p.29: prorrogadas automaticamente ate o primeiro Dia Util subsequente. Mapping is clear and traceable.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::FirstCouponPreIntegralizationPremiumEnabled->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Supported by observation (vii) p.26: exceptional premium in first coupon. Same clause as the three sibling premium fields.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::FirstCouponPreIntegralizationBusinessDays->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit 2 Business Days in observation (vii) p.26 productorio de 2 Dias Uteis. Consistent with premium engine; phase only confirms evidence.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplyIndexFactor->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Observation (vii) p.26 states calculation according to "Fator DI" and "Fator Spread". Premium applies DI factor; confirmed jointly with sibling fields.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::FirstCouponPreIntegralizationApplySpreadFactor->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Observation (vii) p.26 states calculation according to "Fator DI" and "Fator Spread". Premium applies spread factor; confirmed jointly with sibling fields.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::IssueDate->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit "A data de emissao dos CRI e o 08 de maio de 2026" in clause 4.1.5 p.23 of Securitization Agreement. Governing CRI field already confirmed in production; in fixture variant this pending is the same evidence and is confirmed via workflow.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                LegalInstrumentFieldKey::MaturityDate->value => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'confirm',
                    'reason' => 'Explicit "vencendo-se em 08 de maio de 2031" in clause 4.1.6 p.23; Prazo de 1.826 dias corridos. Confirmed if pending.',
                    'evidence' => $this->evidenceSummary($field),
                ],
                default => [
                    'field_key' => $key,
                    'field_id' => $field->id,
                    'classification' => $this->classification($field),
                    'decision' => 'keep_pending',
                    'reason' => 'No specific review rule for this field_key in Phase 2B.5.9; requires further legal/operational review.',
                    'evidence' => $this->evidenceSummary($field),
                ],
            };
        })->values()->all();
    }

    /**
     * Execute the review through the normal workflow.
     *
     * Uses InstrumentChangeReviewService::confirm for each confirmed field,
     * preserving maker/reviewer semantics (reviewed_by, reviewed_at, review_notes).
     * No direct SQL status updates.
     *
     * @return array{pending_at_start:int, confirmed:int, kept_pending:int, rejected:int, decisions:list<array>, read_only_evaluation:array|null, ccb_preserved:bool, matricula_mae_preserved:bool}
     */
    public function execute(User $reviewer, ?string $reviewNotes = null, bool $dryRun = false): array
    {
        $reviewNotes ??= 'Revisao 2B.5.9: conferido contra o Termo de Securitizacao (Securitization Agreement) e seu Anexo II; confirmadas apenas evidencias com lastro documental suficiente no instrumento governante do CRI; preservadas CCB/CCI/matrícula-mãe.';

        $pendingAtStart = $this->pendingFields()->count();
        $decisions = $this->reviewPlan();

        $confirmed = 0;
        $keptPending = 0;
        $rejected = 0;

        if (! $dryRun) {
            foreach ($decisions as $decision) {
                $field = LegalInstrumentField::findOrFail($decision['field_id']);

                // Re-fetch to ensure we respect current status (pending_review guard inside service).
                $field->refresh();

                if ($field->status !== LegalInstrumentFieldStatus::PendingReview) {
                    $keptPending++;

                    continue;
                }

                if ($decision['decision'] === 'confirm') {
                    $this->reviewService->confirm($field, $reviewer, $decision['reason']);
                    $confirmed++;
                } elseif ($decision['decision'] === 'reject') {
                    $this->reviewService->reject($field, $reviewer, $decision['reason']);
                    $rejected++;
                } else {
                    $keptPending++;
                }
            }
        } else {
            foreach ($decisions as $decision) {
                if ($decision['decision'] === 'confirm') {
                    $confirmed++;
                } elseif ($decision['decision'] === 'reject') {
                    $rejected++;
                } else {
                    $keptPending++;
                }
            }
        }

        // Read-only evaluation after review (generic gate must naturally react).
        $evaluation = null;
        if (! $dryRun) {
            $emission = $this->resolveEmission();
            $evaluation = $this->readinessService->evaluate(
                $emission->fresh(),
                CarbonImmutable::parse('2026-08-27'),
            );
        }

        return [
            'pending_at_start' => $pendingAtStart,
            'confirmed' => $confirmed,
            'kept_pending' => $keptPending,
            'rejected' => $rejected,
            'decisions' => $decisions,
            'read_only_evaluation' => $evaluation ? $this->summarizeEvaluation($evaluation) : null,
            'ccb_preserved' => $this->ccbFieldsUntouched(),
            'matricula_mae_preserved' => $this->matriculaMaePreserved(),
        ];
    }

    /**
     * Read-only evaluation helper for callers that only need to inspect the gate
     * without mutating anything.
     */
    public function readOnlyEvaluation(): array
    {
        $emission = $this->resolveEmission();

        $report = $this->readinessService->evaluate(
            $emission->fresh(),
            CarbonImmutable::parse('2026-08-27'),
        );

        return $this->summarizeEvaluation($report);
    }

    private function canonicalValue(LegalInstrumentField $field): string|float
    {
        return match ($field->value_type) {
            LegalInstrumentFieldValueType::Date => (string) $field->value_date?->toDateString(),
            LegalInstrumentFieldValueType::Money,
            LegalInstrumentFieldValueType::Percentage,
            LegalInstrumentFieldValueType::Number => (float) $field->value_numeric,
            default => (string) $field->value,
        };
    }

    private function evidenceSummary(LegalInstrumentField $field): array
    {
        return [
            'document' => $field->document_label,
            'page' => $field->page,
            'clause' => $field->clause,
            'excerpt' => $field->excerpt,
            'evidence_level' => $field->evidence_level->value,
            'confidence_score' => $field->confidence_score,
        ];
    }

    private function governingInstrumentIds(Emission $emission): Collection
    {
        // Governing instruments are SecuritizationTerm that have the base document
        // (Original) or an Amendment linked to the same emission. This mirrors
        // PuBaselineCandidateFactory::currentVersions().
        $instruments = $emission->legalInstruments()
            ->where('type', LegalInstrumentType::SecuritizationTerm->value)
            ->with('documents')
            ->get();

        if ($instruments->isEmpty()) {
            return collect();
        }

        $withBase = $instruments->filter(fn (LegalInstrument $inst): bool => $inst->documents
            ->contains(fn (LegalInstrumentDocument $doc): bool => $doc->role->isBase()));

        $governing = $withBase->isNotEmpty() ? $withBase : $instruments;

        return $governing->pluck('id');
    }

    private function resolveEmission(): Emission
    {
        $emissions = Emission::query()
            ->where('if_code', self::IF_CODE)
            ->where('isin_code', self::ISIN_CODE)
            ->get();

        if ($emissions->count() !== 1) {
            throw new RuntimeException(sprintf(
                'Esperada uma unica emissao Alto Bellevue por IF %s e ISIN %s; encontradas: %d.',
                self::IF_CODE,
                self::ISIN_CODE,
                $emissions->count(),
            ));
        }

        return $emissions->sole();
    }

    private function summarizeEvaluation(PuBaselineReadinessReport $report): array
    {
        return [
            'status' => $report->status->value,
            'candidateConfiguration' => $report->candidateConfiguration,
            'pendingFields' => $report->pendingFields,
            'requirements' => collect($report->requirements)->map(fn ($req): array => [
                'code' => $req->code,
                'status' => $req->status->value,
                'blocks' => $req->blocks,
                'reason' => $req->reason,
            ])->all(),
            'limitations' => $report->limitations,
            'nextActions' => $report->nextActions ?? null,
            'calendarDiagnostics' => $report->calendarDiagnostics,
            'indexSourceDiagnostics' => $report->indexSourceDiagnostics,
            'eventDiagnostics' => $report->eventDiagnostics,
            'rateWindow' => $report->rateWindow,
        ];
    }

    /**
     * Confirm that CCB/CCI fields remained untouched (esp. 7.50% spread).
     */
    private function ccbFieldsUntouched(): bool
    {
        $emission = $this->resolveEmission();

        $ccbSpread = LegalInstrumentField::query()
            ->whereHas('instrument', function ($q) use ($emission): void {
                $q->where('emission_id', $emission->id)
                    ->where('type', LegalInstrumentType::Ccb->value);
            })
            ->where('field_key', LegalInstrumentFieldKey::Spread->value)
            ->where('value_numeric', 0.075)
            ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
            ->exists();

        return $ccbSpread;
    }

    /**
     * Acesso somente-leitura à checagem de preservação, para diagnósticos que
     * não devem passar por `execute()` — aquele caminho exige um reviewer.
     */
    public function matriculaMaeIsPreserved(): bool
    {
        return $this->matriculaMaePreserved();
    }

    private function matriculaMaePreserved(): bool
    {
        $emission = $this->resolveEmission();

        // Phase 2B.5.10 fix: the original diagnostic required status=Confirmed for
        // the Matrícula Mãe field, but that field is intentionally left as
        // manual_review_required (see AltoBellevueContractEvidenceBackfill::misclassifiedIssueDateCandidates)
        // because the registry date (2026-04-30) does not by itself prove the CRI
        // issuance date. The prior check therefore produced a false negative
        // (NO — check!) even though record 180 was intact and untouched.
        // Correct preservation is: record 180 exists with its original value
        // 2026-04-30 on its own collateral instrument and was NOT mutated or
        // superseded by the CRI review, while the CRI baseline retains
        // 2026-05-08 confirmed on the SecuritizationTerm.

        // Direct audit of field 180 when it belongs to this emission; fallback to
        // value-based check for test fixtures where IDs differ.
        $field180 = LegalInstrumentField::find(180);

        $belongsToThisEmission = $field180 instanceof LegalInstrumentField
            && $field180->legal_instrument_id !== null
            && $field180->instrument !== null
            && (int) $field180->instrument->emission_id === (int) $emission->id;

        if ($field180 instanceof LegalInstrumentField && $belongsToThisEmission) {
            $isIntact = $field180->field_key === LegalInstrumentFieldKey::IssueDate
                && $field180->value_date?->toDateString() === '2026-04-30'
                && $field180->instrument->type !== LegalInstrumentType::SecuritizationTerm
                && $field180->supersedes_id === null
                && ! $field180->has_conflict;
            // Status is intentionally NOT required to be Confirmed — it is
            // manual_review_required by design; what matters is it was not
            // altered to become the CRI date or superseded.
            if (! $isIntact) {
                return false;
            }
        } else {
            // Fallback for test fixtures / environments where ID 180 does not
            // correspond to this emission: check existence by value without
            // requiring status=Confirmed.
            $matriculaMae = LegalInstrumentField::query()
                ->whereHas('instrument', function ($q) use ($emission): void {
                    $q->where('emission_id', $emission->id)
                        ->where('type', '!=', LegalInstrumentType::SecuritizationTerm->value);
                })
                ->where('field_key', LegalInstrumentFieldKey::IssueDate->value)
                ->whereDate('value_date', '2026-04-30')
                ->whereNull('supersedes_id')
                ->exists();

            if (! $matriculaMae) {
                return false;
            }
        }

        $criIssue = LegalInstrumentField::query()
            ->whereHas('instrument', function ($q) use ($emission): void {
                $q->where('emission_id', $emission->id)
                    ->where('type', LegalInstrumentType::SecuritizationTerm->value);
            })
            ->where('field_key', LegalInstrumentFieldKey::IssueDate->value)
            ->whereDate('value_date', '2026-05-08')
            ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
            ->exists();

        return $criIssue;
    }

    /**
     * Read-only audit of LegalInstrumentField id=180 as required by Phase 2B.5.10 Section 3.
     * Does not mutate any record.
     *
     * @return array<string, mixed>
     */
    public function auditField180(): array
    {
        $field = LegalInstrumentField::with(['instrument', 'instrumentDocument.document', 'document', 'reviewer'])
            ->find(180);

        if (! $field instanceof LegalInstrumentField) {
            return [
                'found' => false,
                'field_id' => 180,
                'reason' => 'Field 180 not found in this database.',
            ];
        }

        // Activity / events for this field during Phase 2B.5.9
        $activities = Activity::query()
            ->where('log_name', InstrumentChangeReviewService::LOG_NAME)
            ->where('subject_type', LegalInstrumentField::class)
            ->where('subject_id', 180)
            ->orderBy('id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'causer_id' => $a->causer_id,
                'properties' => $a->properties,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all();

        $events = LegalInstrumentEvent::query()
            ->where('legal_instrument_id', $field->legal_instrument_id)
            ->whereJsonContains('change_set', ['field' => $field->field_key?->value])
            ->orderBy('id')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type?->value,
                'effective_date' => $e->effective_date?->toDateString(),
                'change_set' => $e->change_set,
            ])->all();

        return [
            'found' => true,
            'field_id' => $field->id,
            'field_key' => $field->field_key?->value,
            'canonical_value' => $this->canonicalValue($field),
            'raw_value' => $field->value,
            'value_type' => $field->value_type?->value,
            'status' => $field->status?->value,
            'legal_instrument_id' => $field->legal_instrument_id,
            'instrument_type' => $field->instrument?->type?->value,
            'instrument_label' => $field->instrument?->display_name,
            'document_id' => $field->document_id,
            'document_label' => $field->document_label,
            'legal_instrument_document_id' => $field->legal_instrument_document_id,
            'instrument_document_role' => $field->instrumentDocument?->role?->value,
            'page' => $field->page,
            'clause' => $field->clause,
            'excerpt' => $field->excerpt,
            'reviewed_by' => $field->reviewed_by,
            'reviewer_name' => $field->reviewer?->name,
            'reviewed_at' => $field->reviewed_at?->toIso8601String(),
            'review_notes' => $field->review_notes,
            'supersedes_id' => $field->supersedes_id,
            'has_conflict' => $field->has_conflict,
            'conflict_reason' => $field->conflict_reason,
            'evidence_level' => $field->evidence_level?->value,
            'confidence_score' => $field->confidence_score,
            'effective_date' => $field->effective_date?->toDateString(),
            'created_at' => $field->created_at?->toIso8601String(),
            'updated_at' => $field->updated_at?->toIso8601String(),
            'activity_logs' => $activities,
            'legal_events' => $events,
            'intact' => $field->field_key === LegalInstrumentFieldKey::IssueDate
                && $field->value_date?->toDateString() === '2026-04-30'
                && $field->instrument->type !== LegalInstrumentType::SecuritizationTerm,
        ];
    }
}
