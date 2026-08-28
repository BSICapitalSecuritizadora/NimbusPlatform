<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentType;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\User;
use App\Services\LegalInstruments\AltoBellevueContractEvidenceReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AltoBellevuePrerequisiteCloser
{
    public const IF_CODE = '26E0017614';

    public const ISIN_CODE = 'BRALBLCRI008';

    public const REQUIRED_CALENDAR_YEARS = [2026, 2027, 2028, 2029, 2030, 2031];

    public function __construct(
        private readonly CdiSourceDossierGovernanceService $diDossier,
        private readonly NationalLegalCalendarReviewService $calendarReview,
        private readonly BusinessCalendarYearService $calendarYears,
        private readonly PuBaselineEvidenceReviewService $evidenceReview,
        private readonly PuBaselineReadinessService $readiness,
        private readonly AltoBellevueContractEvidenceReview $contractReview,
        private readonly PuIndexRateRequirementResolver $rateResolver,
        private readonly FirstIntegralizationDocumentAnalyzer $firstIntegralizationAnalyzer,
    ) {}

    // =========================================================================
    // Section 3-4: Matrícula Mãe diagnostic (delegated, with explanation)
    // =========================================================================

    public function auditField180(): array
    {
        return $this->contractReview->auditField180();
    }

    /**
     * Diagnóstico estritamente somente-leitura da Matrícula Mãe. Não passa por
     * `AltoBellevueContractEvidenceReview::execute()`: aquele caminho exige um
     * `User` reviewer e esta fase não fabrica usuário nem para dry-run.
     *
     * @return array<string, mixed>
     */
    public function matriculaMaeDiagnostic(): array
    {
        $audit = $this->auditField180();

        return [
            'field_180_audit' => $audit,
            'preserved_via_corrected_logic' => $this->contractReview->matriculaMaeIsPreserved(),
            'diagnosis' => $audit['found'] && ($audit['intact'] ?? false)
                ? 'Campo íntegro: data de registro no instrumento de lastro, não superseded e não promovida ao CRI. O diagnóstico anterior exigia status=Confirmed, quando o status correto é manual_review_required/provisional — o campo estava preservado e a checagem estava errada.'
                : 'Campo exige investigação — não íntegro ou não encontrado.',
            'expected_status' => 'manual_review_required/provisional — data da matrícula não comprova emissão do CRI.',
            'collateral_untouched' => empty($audit['activity_logs'])
                ? 'Nenhum activity log para o campo (esperado): a revisão só toca campos do Termo de Securitização governante.'
                : 'Há activity logs para o campo — conferir se a origem é anterior a esta fase.',
        ];
    }

    /**
     * Read-only initial audit of Alto Bellevue.
     */
    public function initialAudit(): array
    {
        $emission = $this->resolveEmission();
        $term = $emission->legalInstruments()->where('type', LegalInstrumentType::SecuritizationTerm->value)->first();
        $documents = $emission->documents()->get()->map(fn (Document $d) => [
            'id' => $d->id, 'title' => $d->title, 'category' => $d->category, 'file_path' => $d->file_path, 'checksum' => $d->checksum, 'is_published' => $d->is_published,
        ])->all();
        $evidences = EmissionPuBaselineEvidence::where('emission_id', $emission->id)->with(['document', 'reviewedBy', 'createdBy'])->orderBy('id')->get()->map(fn (EmissionPuBaselineEvidence $e) => [
            'id' => $e->id, 'evidence_type' => $e->evidence_type->value, 'document_type' => $e->document_type->value, 'evidenced_value' => $e->evidenced_value, 'reference' => $e->reference, 'confidence' => $e->confidence, 'status' => $e->status->value, 'document_id' => $e->document_id, 'document_title' => $e->document?->title, 'created_by' => $e->created_by, 'reviewed_by' => $e->reviewed_by, 'notes' => $e->notes,
        ])->all();
        $firstInt = collect($evidences)->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)->values()->all();
        $qty = collect($evidences)->where('evidence_type', PuBaselineEvidenceType::IntegralizedQuantity->value)->values()->all();
        $readiness = $this->evaluateReadiness();
        $a = $this->gateAState();
        $b = $this->gateBState();
        $c = $this->gateCState();

        return [
            'emission' => ['id' => $emission->id, 'name' => $emission->name, 'if_code' => $emission->if_code, 'isin_code' => $emission->isin_code, 'status' => $emission->status],
            'governing_term' => $term ? ['id' => $term->id, 'type' => $term->type->value, 'name' => $term->name, 'display_name' => $term->display_name] : null,
            'linked_documents_count' => count($documents), 'linked_documents' => $documents,
            'baseline_evidences_count' => count($evidences), 'baseline_evidences' => $evidences,
            'first_integralization_evidences' => $firstInt, 'integralized_quantity_evidences' => $qty,
            'curve_start_date_derived' => $readiness['candidateConfiguration']['curve_start_date'] ?? 'PENDING',
            'readiness' => $readiness,
            'gate_confirmation' => [
                'Gate A (DI source)' => ($a['is_technical_satisfied'] ?? false) && ($a['is_approved'] ?? false) ? 'satisfied' : 'NOT satisfied — '.json_encode(['technical' => $a['is_technical_satisfied'] ?? null, 'approved' => $a['is_approved'] ?? null]),
                'Gate B (calendar)' => ($b['technical_coverage_satisfied'] ?? false) && ($b['administratively_confirmed'] ?? false) ? 'satisfied' : 'NOT satisfied — '.json_encode(['tech' => $b['technical_coverage_satisfied'] ?? null, 'admin' => $b['administratively_confirmed'] ?? null]),
                'Gate C (first integration)' => ($c['approved'] ? 'satisfied — '.$c['approved']['evidenced_value'] : 'blocking — curve_start_date PENDING, no strong evidence'),
            ],
        ];
    }

    // =========================================================================
    // Section 5-6: Reviewer governance audit
    // =========================================================================

    public function auditReviewer(string $email): array
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return [
                'found' => false,
                'email' => $email,
                'is_seed_account' => $email === 'admin@bsi.local',
                'note' => 'User not found.',
            ];
        }

        return [
            'found' => true,
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'is_active' => $user->is_active,
            'is_approved' => $user->approved_at !== null,
            'approved_at' => $user->approved_at?->toIso8601String(),
            'roles' => $user->roles->pluck('name')->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->all(),
            'can_confirm_legal' => $user->can('legal-instruments.confirm_change'),
            'can_review_legal' => $user->can('legal-instruments.review_changes'),
            'can_review_calendar' => $user->can('pu.calendar-homologation.review'),
            'is_seed_account' => $email === 'admin@bsi.local',
            'created_at' => $user->created_at?->toIso8601String(),
            'how_created' => $email === 'admin@bsi.local'
                ? 'InitialDemoSeeder::firstOrCreate([email => admin@bsi.local], name=Admin Super, password hashed, approved_at now, is_active true) + assignRole(super-admin). Also created by RolesAndPermissionsSeeder via super-admin sync. Used as default admin in dev/demo.'
                : 'Human or factory-created reviewer.',
            'is_official_admin' => $user->hasRole('super-admin'),
            'is_generic' => $email === 'admin@bsi.local',
            'command_selection' => 'O comando exige --reviewer=<id|email> explícito para qualquer write, validado por usuário ativo, aprovado e com a permission da etapa. Não há fallback silencioso para o primeiro administrador nem bypass por role.',
            'audit_trail' => [
                'reviewed_by' => 'Recorded in legal_instrument_fields.reviewed_by + activity_log legal_instrument_changes + legal_instrument_events.recorded_by for the 19 confirmed fields.',
                'do_not_retroactively_change' => 'No retroactive change to the 19 confirmed fields is performed unless concrete governance reason; they remain attributed to admin@bsi.local #1 as executed.',
            ],
        ];
    }

    public function resolveReviewer(string $identifier): ?User
    {
        $user = is_numeric($identifier)
            ? User::find((int) $identifier)
            : User::where('email', $identifier)->first();

        if (! $user) {
            return null;
        }
        if (! $user->isActive() || ! $user->isApproved()) {
            return null;
        }
        $hasAny = $user->can('pu.calendar-homologation.review')
            || $user->can('legal-instruments.confirm_change');
        if (! $hasAny) {
            return null;
        }

        return $user;
    }

    public function resolveEvidenceCreator(?string $identifier, ?int $documentId): ?User
    {
        $creator = filled($identifier)
            ? $this->resolveUser((string) $identifier)
            : Document::query()->with('publisher')->find($documentId)?->publisher;

        if (! $creator instanceof User
            || ! $creator->isActive()
            || ! $creator->isApproved()
            || ! $creator->can(AccessPermission::PuParametersConfigure->value)) {
            return null;
        }

        return $creator;
    }

    public function resolveEvidenceReviewer(string $identifier): ?User
    {
        $reviewer = $this->resolveUser($identifier);

        if (! $reviewer instanceof User
            || ! $reviewer->isActive()
            || ! $reviewer->isApproved()
            || ! $reviewer->can(AccessPermission::PuCalendarHomologationReview->value)) {
            return null;
        }

        return $reviewer;
    }

    private function resolveUser(string $identifier): ?User
    {
        return is_numeric($identifier)
            ? User::find((int) $identifier)
            : User::where('email', $identifier)->first();
    }

    // =========================================================================
    // Gate A — DI source B3 × BCB SGS 4389
    // =========================================================================

    public function gateAState(): array
    {
        $dossier = $this->diDossier->latest();
        $report = $dossier['report'] ?? null;

        return [
            'dossier' => $dossier,
            'summary_for_reviewer' => [
                'contractual_source' => 'B3 Taxa DI',
                'candidate_operational_source' => 'BCB SGS 4389 (https://api.bcb.gov.br/dados/serie/bcdata.sgs.4389/dados)',
                'compared_period' => $report['compared_period'] ?? ['from' => '2025-01-01', 'to' => '2026-08-24'],
                'requested_period' => $report['requested_period'] ?? null,
                'common_dates' => data_get($report, 'comparison.summary.common_dates'),
                'equal_values' => data_get($report, 'comparison.summary.present_equal'),
                'divergent_values' => data_get($report, 'comparison.summary.present_different'),
                'only_b3' => data_get($report, 'comparison.summary.only_b3'),
                'only_bcb' => data_get($report, 'comparison.summary.only_bcb'),
                'transformation' => 'representation/format only (B3: 9 digits with 2 implicit decimals; BCB: decimal separator normalized, no rounding; comparison: exact decimal, zero tolerance)',
                'checksums' => [
                    'artifact_checksum' => $dossier['artifact_checksum'],
                    'report_checksum' => $dossier['report_checksum'],
                    'checksum_valid' => $dossier['checksum_valid'],
                    'b3_normalized_checksum' => data_get($report, 'sources.b3.normalized_checksum'),
                    'bcb_normalized_checksum' => data_get($report, 'sources.bcb.normalized_checksum'),
                ],
                'artifact' => [
                    'disk' => $dossier['artifact_disk'],
                    'path' => $dossier['artifact_path'],
                ],
                'executor' => $dossier['executor'],
                'executed_at' => $dossier['executed_at'],
                'classification' => $dossier['classification'],
                'workflow_status' => $dossier['workflow_status'],
                'official_sources_used' => [
                    'B3 payloads' => data_get($report, 'sources.b3.payloads'),
                    'BCB payloads' => data_get($report, 'sources.bcb.payloads'),
                ],
                'normalization' => data_get($report, 'normalization'),
            ],
            'is_technical_satisfied' => $dossier['technical_homologation_satisfied'] ?? false,
            'is_approved' => $dossier['approved'] ?? false,
            'review' => $dossier['review'],
        ];
    }

    public function gateADryRun(): array
    {
        $state = $this->gateAState();
        $dossier = $state['dossier'];

        if (! ($state['is_technical_satisfied'] ?? false)) {
            return [
                'action' => 'blocked',
                'reason' => 'Dossier not technically satisfied (not B classification or not ready_for_review or checksum invalid). Cannot approve.',
                'state' => $state,
            ];
        }

        if ($state['is_approved'] ?? false) {
            return [
                'action' => 'already_approved',
                'reason' => 'The latest dossier is already operationally approved. Idempotent — no duplicate approval.',
                'state' => $state,
            ];
        }

        return [
            'action' => 'will_approve',
            'reason' => 'Dossier is B classification, 413/413 equal, representation transformation only, checksums valid and ready_for_review — will create operational approval (IndexRateSourceGovernanceReview).',
            'what_will_change' => 'Creates IndexRateSourceGovernanceReview (source_code=bcb_sgs_4389, report_checksum, artifact_path) with status approved, reviewed_by, reviewed_at, review_notes.',
            'current_state' => [
                'technical_homologation' => 'satisfied',
                'operational_approval' => 'administrative_pending',
            ],
            'future_state' => [
                'technical_homologation' => 'satisfied',
                'operational_approval' => 'satisfied',
            ],
            'artifact_path' => $dossier['artifact_path'],
            'artifact_checksum' => $dossier['artifact_checksum'],
            'report_checksum' => $dossier['report_checksum'],
            'classification' => $dossier['classification'],
            'executor' => $dossier['executor'],
            'state' => $state,
        ];
    }

    public function gateAExecute(User $reviewer, string $notes): array
    {
        $dry = $this->gateADryRun();

        if (($dry['action'] ?? null) === 'already_approved') {
            return [
                'executed' => false,
                'idempotent' => true,
                'message' => 'Already approved — no duplicate created.',
                'state' => $this->gateAState(),
            ];
        }

        if (($dry['action'] ?? null) !== 'will_approve') {
            throw new RuntimeException($dry['reason'] ?? 'Cannot approve dossier in current state.');
        }

        $review = $this->diDossier->approveLatest($reviewer, $notes);

        return [
            'executed' => true,
            'idempotent' => false,
            'review_id' => $review->id,
            'review' => $review->toArray(),
            'state' => $this->gateAState(),
        ];
    }

    // =========================================================================
    // Gate B — BR_NATIONAL_HOLIDAYS 2026-2031
    // =========================================================================

    public function gateBState(): array
    {
        $review = $this->calendarReview->reviewRange(2026, 2031);
        $years = $review['years'] ?? [];

        // Map years to summary for reviewer
        $yearRows = collect($years)->map(fn (array $y, int $year): array => [
            'year' => $year,
            'coverage_status' => $y['coverage_status'] ?? $y['coverage'] ?? null,
            'governance_status' => $y['governance_status'],
            'review_state' => $y['review_state'],
            'technical_criteria_satisfied' => $y['technical_criteria_satisfied'] ?? null,
            'holiday_count' => $y['holiday_count'],
            'expected_holiday_count' => 9,
            'conflicts' => $y['conflicts'],
            'overrides' => $y['overrides'],
            'checksum' => $y['checksum'],
            'checksum_reproducible' => $y['checksum_reproducible'] ?? null,
            'holidays' => $y['holidays'],
            'missing_legal_dates' => $y['missing_legal_dates'] ?? [],
            'unexpected_dates' => $y['unexpected_dates'] ?? [],
            'excluded_observances' => $y['excluded_observances'] ?? [],
            'source' => $y['source'] ?? null,
            'confirmed_at' => $y['confirmed_at'],
        ])->values()->all();

        return [
            'calendar_code' => $review['calendar_code'],
            'technical_coverage_satisfied' => $review['technical_coverage_satisfied'],
            'administratively_confirmed' => $review['administratively_confirmed'],
            'years' => $yearRows,
            'year_range' => [2026, 2031],
            'expected_holidays_per_year' => [
                '01/01' => 'Confraternização Universal',
                '21/04' => 'Tiradentes',
                '01/05' => 'Dia do Trabalho',
                '07/09' => 'Independência',
                '12/10' => 'Nossa Senhora Aparecida',
                '02/11' => 'Finados',
                '15/11' => 'Proclamação da República',
                '20/11' => 'Consciência Negra (legal national from 2024, effective 2024-06-... )',
                '25/12' => 'Natal',
            ],
            'exclusions' => [
                'not_automatically_added' => [
                    'Carnival Monday', 'Carnival Tuesday', 'Ash Wednesday', 'Good Friday',
                    'Corpus Christi', 'state holidays', 'municipal holidays', 'optional/government optional days', '2026-12-24', '2026-12-31',
                ],
                'weekend_rule' => 'Saturdays and Sundays are non-business by base rule; not materialized as holidays.',
                'pecuniary_scope' => 'PU is pecuniary → BR_NATIONAL_HOLIDAYS only (national holidays). Non-pecuniary São Paulo commercial holidays explicitly excluded from PU calendar.',
            ],
            'meaning' => 'Sábados, domingos e feriados de âmbito nacional instituídos por legislação federal. Não inclui automaticamente feriados bancários, Carnaval, Corpus Christi, feriados locais ou sessões B3.',
        ];
    }

    public function gateBDryRun(): array
    {
        $state = $this->gateBState();
        $years = $state['years'];

        $provisionalYears = collect($years)->filter(fn (array $y): bool => ($y['governance_status'] ?? null) === BusinessCalendarYear::STATUS_PROVISIONAL || ($y['review_state'] ?? null) === 'ready_for_administrative_review')->pluck('year')->all();
        $confirmedYears = collect($years)->filter(fn (array $y): bool => ($y['governance_status'] ?? null) === BusinessCalendarYear::STATUS_CONFIRMED)->pluck('year')->all();
        $blockedYears = collect($years)->filter(fn (array $y): bool => ($y['review_state'] ?? null) === 'technical_review_blocked')->pluck('year')->all();

        if ($blockedYears !== []) {
            return [
                'action' => 'blocked',
                'reason' => 'Some years have technical_review_blocked (missing complete coverage, conflicts, non-reproducible checksum, or unexpected dates). Cannot confirm.',
                'provisional_years' => $provisionalYears,
                'confirmed_years' => $confirmedYears,
                'blocked_years' => $blockedYears,
                'state' => $state,
            ];
        }

        if ($provisionalYears === []) {
            return [
                'action' => 'already_confirmed',
                'reason' => 'All required years 2026-2031 are already administratively confirmed. Idempotent.',
                'provisional_years' => [],
                'confirmed_years' => $confirmedYears,
                'state' => $state,
            ];
        }

        return [
            'action' => 'will_confirm',
            'reason' => sprintf('%d year(s) 2026–2031 are technically complete (9/9 holidays, 0 conflicts, 0 overrides, checksum reproducible) and ready_for_administrative_review — will change governance from provisional to confirmed.', count($provisionalYears)),
            'what_will_change' => sprintf('Will confirm %d year(s): %s — change governance from provisional to confirmed, preserving legal definition, dates, checksums, source.', count($provisionalYears), implode(', ', $provisionalYears)),
            'current_state' => [
                'technical_coverage' => $state['technical_coverage_satisfied'] ? 'satisfied' : 'blocked',
                'administrative_confirmation' => $state['administratively_confirmed'] ? 'satisfied' : 'administrative_pending',
            ],
            'future_state' => [
                'technical_coverage' => 'satisfied',
                'administrative_confirmation' => count($provisionalYears) === 0 ? 'satisfied' : 'will_be_satisfied_after_confirm',
            ],
            'provisional_years' => $provisionalYears,
            'confirmed_years' => $confirmedYears,
            'state' => $state,
        ];
    }

    public function gateBExecute(User $reviewer): array
    {
        $dry = $this->gateBDryRun();

        if (($dry['action'] ?? null) === 'already_confirmed') {
            return [
                'executed' => false,
                'idempotent' => true,
                'message' => 'All years already confirmed.',
                'state' => $this->gateBState(),
            ];
        }

        if (($dry['action'] ?? null) !== 'will_confirm') {
            throw new RuntimeException($dry['reason'] ?? 'Cannot confirm calendar in current state.');
        }

        $results = [];
        $calendarCode = BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS;

        foreach ($dry['provisional_years'] as $year) {
            $year = (int) $year;
            // Load coverage to get source, checksum, etc. for confirm()
            $coverage = $this->calendarYears->coverage($calendarCode, $year);
            $yearModel = BusinessCalendarYear::where('calendar_code', $calendarCode)->where('year', $year)->first();
            $importRun = BusinessCalendarImportRun::where('calendar_code', $calendarCode)->where('year', $year)->where('dry_run', false)->latest('id')->first();

            // Use existing source/checksum to preserve legal definition/dates/checksums
            $source = $yearModel?->source ?? $importRun?->source ?? 'national_legal';
            $sourceDocument = $yearModel?->source_document ?? $importRun?->source_document ?? 'Lei federal — feriados nacionais';
            $sourceRevision = $yearModel?->source_revision ?? $importRun?->source_revision ?? null;
            $checksum = $yearModel?->checksum ?? $importRun?->checksum ?? $coverage['checksum'];

            $confirmed = $this->calendarYears->confirm(
                $calendarCode,
                $year,
                (string) $source,
                (string) $sourceDocument,
                $sourceRevision,
                $checksum,
                $reviewer->id,
                true
            );

            $results[] = [
                'year' => $year,
                'status' => $confirmed->status,
                'confirmed_at' => $confirmed->confirmed_at?->toIso8601String(),
                'checksum' => $confirmed->checksum,
            ];
        }

        return [
            'executed' => true,
            'idempotent' => false,
            'confirmed_years' => $results,
            'state' => $this->gateBState(),
        ];
    }

    // =========================================================================
    // Gate C — First integration (curve_start_date)
    // =========================================================================

    public function gateCSearch(?int $documentId = null): array
    {
        $emission = $this->resolveEmission();
        $linkedDocuments = $emission->documents()->orderBy('documents.id')->get()->unique('id')->values();
        $classification = $linkedDocuments
            ->map(fn (Document $document): array => $this->discoverDocument($document))
            ->values();
        $selectedDocumentError = null;

        if ($documentId !== null) {
            $selectedDocument = $linkedDocuments->firstWhere('id', $documentId);

            if (! $selectedDocument instanceof Document) {
                $selectedDocumentError = 'integration_document_not_linked_to_emission';
            } else {
                $discovery = $classification->firstWhere('document_id', $documentId);

                // Documento inequivocamente indicativo ou meramente confirmatório
                // pelo tipo já conhecido não vai ao extrator: não há promoção
                // possível e a chamada externa seria desperdício.
                $analysis = in_array($discovery['strength'] ?? null, ['weak', 'medium'], true)
                    ? $discovery
                    : $this->firstIntegralizationAnalyzer->analyze($emission, $selectedDocument);

                $classification = $classification
                    ->reject(fn (array $entry): bool => $entry['document_id'] === $documentId)
                    ->push($analysis)
                    ->sortBy('document_id')
                    ->values();
            }
        }

        $evidences = EmissionPuBaselineEvidence::query()
            ->whereBelongsTo($emission)
            ->with(['document', 'reviewedBy', 'createdBy'])
            ->oldest('id')
            ->get()
            ->map(fn (EmissionPuBaselineEvidence $evidence): array => [
                'id' => $evidence->id,
                'evidence_type' => $evidence->evidence_type->value,
                'document_type' => $evidence->document_type->value,
                'evidenced_value' => $evidence->evidenced_value,
                'reference' => $evidence->reference,
                'confidence' => $evidence->confidence,
                'status' => $evidence->status->value,
                'document_id' => $evidence->document_id,
                'document_title' => $evidence->document?->title,
                'created_by' => $evidence->created_by,
                'creator' => $evidence->createdBy?->email,
                'reviewed_by' => $evidence->reviewed_by,
                'reviewer' => $evidence->reviewedBy?->email,
                'reviewed_at' => $evidence->reviewed_at?->toIso8601String(),
                'notes' => $evidence->notes,
                'review_notes' => $evidence->review_notes,
            ])
            ->all();

        return [
            'emission_id' => $emission->id,
            'emission_identifiers' => [
                'name' => $emission->name,
                'if_code' => $emission->if_code,
                'isin_code' => $emission->isin_code,
                'bsi_code' => $emission->bsi_code,
            ],
            'linked_documents' => $linkedDocuments->map(fn (Document $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->category,
            ])->all(),
            'selected_document_id' => $documentId,
            'selected_document_error' => $selectedDocumentError,
            'existing_evidences' => $evidences,
            'classification' => $classification->all(),
        ];
    }

    /**
     * Descoberta preliminar por título/nome de arquivo. Serve para ordenar a fila
     * do operador e para dispensar a análise de conteúdo de itens inequivocamente
     * indicativos — nunca para provar nada. Todo documento vinculado aparece na
     * lista: um título irreconhecível é `unverified`, não invisível.
     *
     * @return array<string, mixed>
     */
    private function discoverDocument(Document $document): array
    {
        $title = mb_strtolower((string) $document->title);
        $filename = mb_strtolower((string) ($document->file_name ?: basename((string) $document->file_path)));
        $discoveryText = trim($title.' '.$filename);
        $weak = collect(['anúncio de início', 'cronograma indicativo', 'previsão de integralização', 'portal'])
            ->contains(fn (string $term): bool => str_contains($discoveryText, $term));
        $medium = ! $weak && collect(['mapa de distribuição', 'relatório de distribuição', 'anúncio de encerramento'])
            ->contains(fn (string $term): bool => str_contains($discoveryText, $term));
        $potentialPrimary = ! $weak && ! $medium && collect([
            'extrato de liquidação',
            'b3 settlement',
            'posição do escriturador',
            'posição do registrador',
            'posição do custodiante',
            'boletim de subscrição',
            'comprovante de liquidação',
            'comprovante do banco liquidante',
        ])->contains(fn (string $term): bool => str_contains($discoveryText, $term));

        return [
            'document_id' => $document->id,
            'title' => $document->title,
            'strength' => $weak ? 'weak' : ($medium ? 'medium' : 'unverified'),
            'analysis_status' => $weak
                ? 'insufficient_documentary_evidence'
                : ($medium ? 'manual_documentary_review_required' : 'document_content_analysis_required'),
            'reason' => $weak
                ? 'Natureza indicativa: não prova liquidação ou integralização efetiva.'
                : ($medium
                    ? 'Fonte confirmatória secundária: requer revisão documental manual.'
                    : 'Nome/título serve apenas para descoberta; o conteúdo ainda precisa comprovar natureza, emissor, emissão, evento e data.'),
            'discovery_basis' => $title !== '' ? 'title' : ($filename !== '' ? 'filename' : 'none'),
            'candidate_integration_date' => null,
            'emission_match' => null,
            'identifiers_found' => [],
            'dates' => [],
            'quantity' => null,
        ];
    }

    public function gateCState(): array
    {
        $emission = $this->resolveEmission();
        $evidences = EmissionPuBaselineEvidence::where('emission_id', $emission->id)
            ->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)
            ->with(['document', 'createdBy', 'reviewedBy'])
            ->orderByDesc('id')
            ->get();

        $approved = $evidences->firstWhere('status', PuBaselineEvidenceStatus::Approved);
        $pending = $evidences->firstWhere('status', PuBaselineEvidenceStatus::PendingReview);

        return [
            'approved' => $approved ? [
                'id' => $approved->id,
                'evidenced_value' => $approved->evidenced_value,
                'document_title' => $approved->document?->title,
                'document_id' => $approved->document_id,
                'status' => $approved->status->value,
                'confidence' => $approved->confidence,
                'creator' => $approved->createdBy?->email,
                'reviewer' => $approved->reviewedBy?->email,
            ] : null,
            'pending' => $pending ? [
                'id' => $pending->id,
                'evidenced_value' => $pending->evidenced_value,
                'document_title' => $pending->document?->title,
                'document_id' => $pending->document_id,
                'status' => $pending->status->value,
                'creator' => $pending->createdBy?->email,
            ] : null,
            'count' => $evidences->count(),
            'all' => $evidences->map(fn ($e) => ['id' => $e->id, 'value' => $e->evidenced_value, 'status' => $e->status->value])->all(),
            'curve_start_date' => $approved?->evidenced_value ?? 'PENDING',
            'quantity_separate' => [
                'note' => 'integralized_quantity remains separate; proving curve_start_date does not promote quantity.',
                'quantity_evidence' => EmissionPuBaselineEvidence::where('emission_id', $emission->id)->where('evidence_type', PuBaselineEvidenceType::IntegralizedQuantity->value)->count(),
            ],
        ];
    }

    /**
     * Precedência: entrada inválida, evidência aprovada, proposta pendente,
     * ausência de documento selecionado e, só então, análise probatória do
     * documento explicitamente escolhido pelo operador.
     *
     * @return array<string, mixed>
     */
    public function gateCDryRun(?int $documentId = null, ?string $integrationDate = null): array
    {
        $state = $this->gateCState();
        $search = $this->gateCSearch($documentId);
        $approvedDate = $state['approved']['evidenced_value'] ?? null;

        if ($search['selected_document_error'] !== null) {
            return [
                'action' => $search['selected_document_error'],
                'reason' => 'O documento informado não está efetivamente vinculado à emissão Alto Bellevue.',
                'search' => $search,
                'state' => $state,
            ];
        }

        if ($documentId === null) {
            if ($approvedDate !== null) {
                return [
                    'action' => 'already_approved',
                    'reason' => 'Primeira integralização já aprovada em '.$approvedDate.'.',
                    'documented_integration_date' => $approvedDate,
                    'search' => $search,
                    'state' => $state,
                ];
            }

            if ($state['pending'] !== null) {
                return [
                    'action' => 'pending_review_awaiting_reviewer',
                    'reason' => sprintf(
                        'Existe proposta #%d em pending_review para %s aguardando revisor distinto do maker; o Gate C permanece bloqueado até a aprovação humana.',
                        $state['pending']['id'],
                        $state['pending']['evidenced_value'],
                    ),
                    'what_will_change' => 'Nothing — a aprovação exige --integration-document e revisor explícito.',
                    'search' => $search,
                    'state' => $state,
                ];
            }

            return [
                'action' => 'no_strong_evidence',
                'reason' => 'Nenhum documento strong foi selecionado e comprovado pelo conteúdo. O Gate C permanece bloqueado.',
                'what_will_change' => 'Nothing — gate remains blocked, readiness stays blocked.',
                'search' => $search,
                'state' => $state,
                'required_external_document' => 'Extrato B3, posição oficial de escriturador/registrador/custodiante, boletim liquidado ou comprovante oficial equivalente da primeira liquidação.',
            ];
        }

        $analysis = collect($search['classification'])->firstWhere('document_id', $documentId);

        if (! is_array($analysis)) {
            return [
                'action' => 'document_analysis_failed',
                'reason' => 'Não foi possível produzir análise estruturada do documento selecionado.',
                'search' => $search,
                'state' => $state,
            ];
        }

        $analysisStatus = $analysis['analysis_status'] ?? 'insufficient_documentary_evidence';
        $documentedDate = $analysis['candidate_integration_date'] ?? null;
        $proved = $analysisStatus === 'eligible'
            && ($analysis['strength'] ?? null) === 'strong'
            && is_string($documentedDate);

        // Documento sem força probatória nunca reabre nem contradiz o aprovado.
        if ($approvedDate !== null && ! $proved) {
            return [
                'action' => 'already_approved',
                'reason' => sprintf(
                    'Primeira integralização já aprovada em %s. O documento selecionado não comprova data divergente (%s).',
                    $approvedDate,
                    $analysisStatus,
                ),
                'documented_integration_date' => $approvedDate,
                'document_analysis' => $analysis,
                'search' => $search,
                'state' => $state,
            ];
        }

        if (! $proved) {
            return [
                'action' => $analysisStatus === 'eligible'
                    ? 'document_does_not_prove_integration_date'
                    : $analysisStatus,
                'reason' => $analysisStatus === 'eligible'
                    ? 'O conteúdo não comprova uma data efetiva e inequívoca de primeira integralização.'
                    : $analysis['reason'],
                'document_analysis' => $analysis,
                'search' => $search,
                'state' => $state,
            ];
        }

        if ($integrationDate !== null && $integrationDate !== $documentedDate) {
            return [
                'action' => 'integration_date_does_not_match_document_evidence',
                'reason' => sprintf('A data informada no CLI (%s) diverge da data comprovada no documento (%s).', $integrationDate, $documentedDate),
                'documented_integration_date' => $documentedDate,
                'cli_integration_date' => $integrationDate,
                'date_match' => false,
                'document_analysis' => $analysis,
                'search' => $search,
                'state' => $state,
            ];
        }

        if ($approvedDate !== null) {
            return $approvedDate === $documentedDate
                ? [
                    'action' => 'already_approved',
                    'reason' => 'Primeira integralização já aprovada em '.$approvedDate.', idêntica à data comprovada pelo documento selecionado.',
                    'documented_integration_date' => $documentedDate,
                    'cli_integration_date' => $integrationDate,
                    'date_match' => true,
                    'document_analysis' => $analysis,
                    'search' => $search,
                    'state' => $state,
                ]
                : [
                    'action' => 'first_integralization_evidence_conflict',
                    'reason' => sprintf(
                        'Conflito documental: existe evidência aprovada em %s e o documento #%d comprova %s. Nenhuma substituição automática é feita — o conflito precisa de revisão humana.',
                        $approvedDate,
                        $documentId,
                        $documentedDate,
                    ),
                    'what_will_change' => 'Nothing — a evidência aprovada é preservada e nenhuma segunda aprovada concorrente é criada.',
                    'approved_integration_date' => $approvedDate,
                    'documented_integration_date' => $documentedDate,
                    'document_analysis' => $analysis,
                    'search' => $search,
                    'state' => $state,
                ];
        }

        $existingPending = EmissionPuBaselineEvidence::query()
            ->where('emission_id', $search['emission_id'])
            ->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)
            ->where('document_id', $documentId)
            ->where('evidenced_value', $documentedDate)
            ->where('status', PuBaselineEvidenceStatus::PendingReview->value)
            ->first();

        return [
            'action' => $existingPending instanceof EmissionPuBaselineEvidence
                ? 'will_approve_pending_review'
                : 'will_create_pending_review',
            'reason' => 'Documento strong, vinculado, emitido por participante responsável e com data efetiva comprovada; a evidência seguirá pending_review -> approved via serviço de revisão.',
            'what_will_change' => $existingPending instanceof EmissionPuBaselineEvidence
                ? 'A proposta pending_review existente será submetida ao reviewer explícito.'
                : 'Será criada uma EmissionPuBaselineEvidence FirstIntegralizationDate em pending_review e submetida ao reviewer explícito.',
            'documented_integration_date' => $documentedDate,
            'cli_integration_date' => $integrationDate,
            'date_match' => $integrationDate === null || $integrationDate === $documentedDate,
            'existing_evidence' => $existingPending?->id,
            'document_analysis' => $analysis,
            'search' => $search,
            'state' => $state,
        ];
    }

    /**
     * Orchestrate the explicit pending_review -> approved workflow after the
     * document has passed every deterministic documentary guard.
     * Quantity remains separate.
     *
     * @return array<string, mixed>
     */
    public function gateCExecute(?User $creator, User $reviewer, ?string $evidencedDate = null, ?int $documentId = null): array
    {
        $dry = $this->gateCDryRun($documentId, $evidencedDate);

        if (($dry['action'] ?? null) === 'already_approved') {
            return [
                'executed' => false,
                'idempotent' => true,
                'action' => 'already_approved',
                'message' => $dry['reason'],
                'document_analysis' => $dry['document_analysis'] ?? null,
                'state' => $this->gateCState(),
            ];
        }

        if (! in_array($dry['action'] ?? null, ['will_create_pending_review', 'will_approve_pending_review'], true)) {
            return [
                'executed' => false,
                'idempotent' => false,
                'action' => $dry['action'] ?? 'blocked',
                'message' => $dry['reason'] ?? 'Gate C blocked.',
                'document_analysis' => $dry['document_analysis'] ?? null,
                'search' => $dry['search'] ?? null,
                'state' => $this->gateCState(),
            ];
        }

        if ($documentId === null) {
            return ['executed' => false, 'action' => 'integration_document_required', 'message' => 'Informe --integration-document vinculado à emissão.'];
        }

        $emission = $this->resolveEmission();
        $analysis = $dry['document_analysis'];
        $chosenDate = $dry['documented_integration_date'];
        $documentType = PuBaselineEvidenceDocumentType::from($analysis['document_type']);

        if (($dry['action'] ?? null) === 'will_create_pending_review' && ! $creator instanceof User) {
            return [
                'executed' => false,
                'action' => 'evidence_creator_required',
                'message' => 'A criação da proposta exige maker explícito/autorizado, distinto do reviewer, ou publisher autorizado do documento.',
                'state' => $this->gateCState(),
            ];
        }

        $result = DB::transaction(function () use ($emission, $documentId, $chosenDate, $documentType, $analysis, $creator, $reviewer): array {
            Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();

            $approvedEvidence = EmissionPuBaselineEvidence::query()
                ->whereBelongsTo($emission)
                ->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)
                ->where('status', PuBaselineEvidenceStatus::Approved->value)
                ->lockForUpdate()
                ->first();

            // Sob lock: o dry-run já comparou as datas, mas uma aprovação
            // concorrente pode ter entrado no intervalo. Divergência nunca
            // substitui a aprovada existente nem cria uma segunda concorrente.
            if ($approvedEvidence instanceof EmissionPuBaselineEvidence) {
                return $approvedEvidence->evidenced_value === $chosenDate
                    ? ['approved' => $approvedEvidence, 'created' => false, 'idempotent' => true, 'conflict' => false]
                    : ['approved' => $approvedEvidence, 'created' => false, 'idempotent' => false, 'conflict' => true];
            }

            $evidence = EmissionPuBaselineEvidence::query()
                ->whereBelongsTo($emission)
                ->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)
                ->where('document_id', $documentId)
                ->where('evidenced_value', $chosenDate)
                ->where('status', PuBaselineEvidenceStatus::PendingReview->value)
                ->lockForUpdate()
                ->first();
            $created = false;

            if (! $evidence instanceof EmissionPuBaselineEvidence) {
                if (! $creator instanceof User) {
                    throw new RuntimeException('evidence_creator_required');
                }

                $evidence = $this->evidenceReview->create($emission, [
                    'document_id' => $documentId,
                    'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate->value,
                    'document_type' => $documentType->value,
                    'evidenced_value' => $chosenDate,
                    'reference' => $analysis['date_source'],
                    'confidence' => $analysis['confidence'],
                    'notes' => json_encode([
                        'phase' => '2B.5.12',
                        'strength' => $analysis['strength'],
                        'excerpt' => $analysis['excerpt'],
                        'identifiers_found' => $analysis['identifiers_found'],
                        'matched_identifiers' => $analysis['matched_identifiers'],
                        'issuer' => $analysis['issuer'],
                        'dates' => $analysis['dates'],
                        'ambiguity_resolution' => $analysis['ambiguity_resolution'],
                        'quantity_found' => $analysis['quantity'],
                        'quantity_implicitly_approved' => false,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ], $creator);
                $created = true;
            }

            $approved = $this->evidenceReview->approve(
                $evidence,
                $reviewer,
                sprintf('Conteúdo documental revisado: primeira integralização comprovada em %s (%s).', $chosenDate, $analysis['date_source']),
            );

            return ['approved' => $approved, 'created' => $created, 'idempotent' => false, 'conflict' => false];
        });

        /** @var EmissionPuBaselineEvidence $approved */
        $approved = $result['approved'];

        if ($result['conflict'] === true) {
            return [
                'executed' => false,
                'idempotent' => false,
                'action' => 'first_integralization_evidence_conflict',
                'message' => sprintf(
                    'Conflito documental: evidência aprovada em %s permanece intacta e a data comprovada agora (%s) exige revisão humana.',
                    $approved->evidenced_value,
                    $chosenDate,
                ),
                'approved_integration_date' => $approved->evidenced_value,
                'documented_integration_date' => $chosenDate,
                'document_analysis' => $analysis,
                'state' => $this->gateCState(),
            ];
        }

        $readiness = $this->evaluateReadiness();

        return [
            'executed' => ! $result['idempotent'],
            'idempotent' => $result['idempotent'],
            'action' => $result['idempotent'] ? 'already_approved' : 'approved',
            'evidence_id' => $approved->id,
            'evidence_type' => $approved->evidence_type->value,
            'evidenced_value' => $approved->evidenced_value,
            'initial_status' => $result['created'] ? PuBaselineEvidenceStatus::PendingReview->value : 'existing_pending_review',
            'creator' => $approved->createdBy?->email,
            'reviewer' => $approved->reviewedBy?->email,
            'final_status' => $approved->status->value,
            'audit_trail' => ['pending_review', 'approved'],
            'document_analysis' => $analysis,
            'quantity_implicitly_approved' => false,
            'state' => $this->gateCState(),
            'readiness' => $readiness,
            'future_window' => $this->futureSnapshotWindow(),
        ];
    }

    // =========================================================================
    // Readiness & future window
    // =========================================================================

    public function evaluateReadiness(?CarbonImmutable $asOf = null): array
    {
        $emission = $this->resolveEmission();
        $report = $this->readiness->evaluate($emission->fresh(), $asOf ?? CarbonImmutable::now());

        return [
            'status' => $report->status->value,
            'candidateConfiguration' => $report->candidateConfiguration,
            'pendingFields' => $report->pendingFields,
            'requirements' => collect($report->requirements)->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'status' => $r->status->value,
                'blocks' => $r->blocks,
                'reason' => $r->reason,
            ])->all(),
            'limitations' => $report->limitations,
            'calendarDiagnostics' => $report->calendarDiagnostics,
            'indexSourceDiagnostics' => $report->indexSourceDiagnostics,
            'rateWindow' => $report->rateWindow,
            'eventDiagnostics' => $report->eventDiagnostics,
            'satisfied' => collect($report->requirements)->filter(fn ($r) => $r->status->value === 'satisfied')->keys()->all(),
            'blocking' => collect($report->requirements)->filter(fn ($r) => in_array('candidate_configuration', $r->blocks, true) && $r->status->value !== 'satisfied')->pluck('code')->all(),
        ];
    }

    /**
     * Resolve, sem importar nada, a janela de snapshots e taxas que a próxima
     * fase precisará. As datas saem dos resolvers reais a partir da
     * `curve_start_date` comprovada — nenhuma janela é presumida aqui.
     *
     * @return array<string, mixed>
     */
    public function futureSnapshotWindow(?CarbonImmutable $asOf = null): array
    {
        $emission = $this->resolveEmission();
        $report = $this->readiness->evaluate($emission->fresh(), $asOf ?? CarbonImmutable::now());
        $curveStart = $report->candidateConfiguration['curve_start_date'] ?? 'PENDING';

        if ($curveStart === 'PENDING') {
            return [
                'resolvable' => false,
                'reason' => 'curve_start_date is still PENDING — cannot determine required snapshot window until Gate C is proven.',
                'curve_start_date' => 'PENDING',
            ];
        }

        $rateWindow = $report->rateWindow;
        $calendarFrom = $report->calendarDiagnostics['required_from'] ?? null;
        $calendarTo = $report->calendarDiagnostics['required_to'] ?? null;
        $requiredRateDates = collect($rateWindow['required_rate_dates'] ?? [])->values();

        $premiumEnabled = $report->candidateConfiguration['first_coupon_pre_integralization_premium_enabled'] ?? false;
        $premiumDays = (int) ($report->candidateConfiguration['first_coupon_pre_integralization_business_days'] ?? 0);
        $lagDays = abs((int) ($report->candidateConfiguration['index_rate_lag_business_days'] ?? 0));

        return [
            'resolvable' => true,
            'curve_start_date' => $curveStart,
            'premium_enabled' => $premiumEnabled,
            'premium_business_days' => $premiumDays,
            'lag_business_days' => $lagDays,
            'required_window' => $rateWindow,
            'calendar_required_from' => $calendarFrom,
            'calendar_required_to' => $calendarTo,
            'rate_required_from' => $requiredRateDates->first(),
            'rate_required_to' => $requiredRateDates->last(),
            'required_rate_dates' => $requiredRateDates->all(),
            'first_rate_lookups' => $requiredRateDates->take(10)->all(),
            'events_still_pending' => $report->eventDiagnostics['missing_events'] ?? [],
            'numeric_homologation_blockers' => collect($report->requirements)
                ->filter(fn ($requirement): bool => in_array('numeric_homologation', $requirement->blocks, true)
                    && ! $requirement->isSatisfied())
                ->pluck('code')
                ->values()
                ->all(),
            'next_phase' => 'Load index snapshots for premium + lag window and materialize 60+1 PU events (ready_for_numeric_homologation), without running curves yet.',
        ];
    }

    private function resolveEmission(): Emission
    {
        $emissions = Emission::query()->where('if_code', self::IF_CODE)->where('isin_code', self::ISIN_CODE)->get();
        if ($emissions->count() !== 1) {
            throw new RuntimeException(sprintf('Expected single Alto Bellevue emission by IF %s ISIN %s; found %d.', self::IF_CODE, self::ISIN_CODE, $emissions->count()));
        }

        return $emissions->sole();
    }
}
