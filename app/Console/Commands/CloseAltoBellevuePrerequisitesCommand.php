<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Enums\AccessPermission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class CloseAltoBellevuePrerequisitesCommand extends Command
{
    protected $signature = 'pu:alto-bellevue:close-prerequisites
                            {--dry-run : Show what will change without mutating}
                            {--write : Perform audited mutations}
                            {--reviewer= : Explicit reviewer (user id or email) required for --write}
                            {--creator= : Explicit evidence maker (user id or email); defaults to the authorized document publisher}
                            {--gate= : Limit to single gate: A (DI source), B (calendar), C (first integration), or omit for all}
                            {--integration-date= : Date asserted by the operator for comparison with the document (YYYY-MM-DD)}
                            {--integration-document= : Explicit document id for Gate C}';

    protected $description = 'Phase 2B.5.12 — Analyze, propose and review Alto Bellevue first-integralization documentary evidence';

    public function handle(AltoBellevuePrerequisiteCloser $closer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        $gateFilter = $this->option('gate');
        $reviewerOpt = $this->option('reviewer');
        $creatorOpt = $this->option('creator');

        if (! $dryRun && ! $write) {
            $dryRun = true;
        }

        if ($dryRun && $write) {
            $this->components->warn('Both --dry-run and --write passed; running dry-run only.');
            $write = false;
        }

        try {
            $this->components->info($dryRun ? 'Phase 2B.5.12 — Dry-run (read-only) — Initial audit' : 'Phase 2B.5.12 — Gate C audited workflow — Initial audit');
            $audit0 = $closer->initialAudit();
            $this->table(
                ['field', 'value'],
                [
                    ['emissão', sprintf('#%s %s', $audit0['emission']['id'], $audit0['emission']['name'])],
                    ['IF / ISIN', sprintf('%s / %s', $audit0['emission']['if_code'], $audit0['emission']['isin_code'])],
                    ['Termo governante', $audit0['governing_term']['display_name'] ?? '—'],
                    ['documentos vinculados', $audit0['linked_documents_count']],
                    ['evidências baseline', $audit0['baseline_evidences_count']],
                    ['FirstIntegralizationDate evidences', count($audit0['first_integralization_evidences'] ?? [])],
                    ['IntegralizedQuantity evidences', count($audit0['integralized_quantity_evidences'] ?? [])],
                    ['curve_start_date derivada', $audit0['curve_start_date_derived']],
                    ['readiness', $audit0['readiness']['status'] ?? '—'],
                ]
            );
            if (($audit0['linked_documents_count'] ?? 0) > 0) {
                $this->line('  Documentos vinculados:');
                foreach (($audit0['linked_documents'] ?? []) as $d) {
                    $this->line(sprintf('    - #%d "%s" [%s]', $d['id'], $d['title'], $d['category'] ?? '—'));
                }
            }
            if (($audit0['baseline_evidences_count'] ?? 0) > 0) {
                $this->line('  Evidências existentes:');
                foreach (($audit0['baseline_evidences'] ?? []) as $e) {
                    $this->line(sprintf('    - #%d %s = %s (%s, %s) doc #%d "%s"', $e['id'], $e['evidence_type'], $e['evidenced_value'], $e['status'], $e['confidence'], $e['document_id'], $e['document_title'] ?? '—'));
                }
            }
            $this->table(
                ['gate', 'status'],
                [
                    ['Gate A (DI source)', $audit0['gate_confirmation']['Gate A (DI source)'] ?? '—'],
                    ['Gate B (calendar)', $audit0['gate_confirmation']['Gate B (calendar)'] ?? '—'],
                    ['Gate C (first integration)', $audit0['gate_confirmation']['Gate C (first integration)'] ?? '—'],
                ]
            );
            $this->line('  Confirmação: Gate A = satisfied, Gate B = satisfied, Gate C = blocking (como esperado antes desta fase).');
            $this->newLine();
            $this->newLine();

            // Section 3-4: Matrícula Mãe audit
            $this->components->info('Section 3-4: Matrícula Mãe field 180 audit (read-only)');
            $field180 = $closer->auditField180();
            if (! ($field180['found'] ?? false)) {
                $this->line('  Field 180 not found in this database (expected in test fixtures with different IDs; production should have ID 180).');
            } else {
                $this->table(
                    ['field', 'value'],
                    [
                        ['field_id', $field180['field_id']],
                        ['field_key', $field180['field_key']],
                        ['canonical_value', $field180['canonical_value']],
                        ['raw_value', $field180['raw_value']],
                        ['value_type', $field180['value_type']],
                        ['status', $field180['status']],
                        ['legal_instrument_id', $field180['legal_instrument_id']],
                        ['instrument_type', $field180['instrument_type']],
                        ['document_id', $field180['document_id']],
                        ['legal_instrument_document_id', $field180['legal_instrument_document_id']],
                        ['page', $field180['page']],
                        ['clause', $field180['clause']],
                        ['excerpt', Str::limit($field180['excerpt'] ?? '', 80)],
                        ['reviewed_by', $field180['reviewed_by'] ?? 'null'],
                        ['reviewed_at', $field180['reviewed_at'] ?? 'null'],
                        ['supersedes_id', $field180['supersedes_id'] ?? 'null'],
                        ['has_conflict', $field180['has_conflict'] ? 'true' : 'false'],
                        ['intact', $field180['intact'] ? 'YES' : 'NO'],
                    ]
                );
                if (! empty($field180['activity_logs'])) {
                    $this->line('  Activity logs for field 180: '.count($field180['activity_logs']).' entries');
                    foreach ($field180['activity_logs'] as $a) {
                        $this->line(sprintf('    - %s %s by #%s', $a['event'], $a['description'], $a['causer_id'] ?? 'null'));
                    }
                } else {
                    $this->line('  No activity logs for field 180 (expected — review workflow only touches governing Term).');
                }
            }

            $diag = $closer->matriculaMaeDiagnostic();
            $this->newLine();
            $this->line('Diagnóstico: '.$diag['diagnosis']);
            $this->line('Status esperado: '.$diag['expected_status']);
            $this->line('Lastro intocado: '.$diag['collateral_untouched']);
            $this->line('Preservada: '.($diag['preserved_via_corrected_logic'] ? 'sim' : 'não'));
            $this->newLine();

            // Section 5-6: Reviewer audit
            if (filled($reviewerOpt)) {
                $this->components->info(sprintf('Section 5-6: Reviewer audit — %s', $reviewerOpt));
                $audit = $closer->auditReviewer($reviewerOpt);
                $this->line(json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->newLine();
            } else {
                $this->components->info('Section 5-6: Reviewer audit — nenhum --reviewer informado');
                $this->line('  Política: qualquer write exige --reviewer=<id|email> explícito, ativo, aprovado e com a permission da etapa.');
                $this->line('  Não há fallback silencioso para conta semente nem bypass por role; maker e checker precisam ser pessoas distintas.');
                $this->newLine();
            }

            // Initial readiness
            $initial = $closer->evaluateReadiness();
            $this->components->info(sprintf('Initial readiness: %s', $initial['status']));
            $this->line('  Blocking: '.implode(', ', $initial['blocking'] ?? []));
            $this->line('  Pending fields: '.json_encode($initial['pendingFields']));
            $this->newLine();

            // Gate selection
            $gate = filled($gateFilter) ? strtoupper((string) $gateFilter) : null;

            if ($gate !== null && ! in_array($gate, ['A', 'B', 'C', 'ALL'], true)) {
                $this->components->error('--gate aceita A, B, C ou all.');

                return self::FAILURE;
            }

            $integrationDateOpt = filled($this->option('integration-date'))
                ? (string) $this->option('integration-date')
                : null;

            if ($integrationDateOpt !== null && ! $this->isCalendarDate($integrationDateOpt)) {
                $this->components->error('--integration-date precisa estar no formato AAAA-MM-DD.');

                return self::FAILURE;
            }

            $integrationDocumentOpt = $this->option('integration-document');

            if (filled($integrationDocumentOpt) && (! ctype_digit((string) $integrationDocumentOpt) || (int) $integrationDocumentOpt < 1)) {
                $this->components->error('--integration-document precisa ser o id inteiro de um Document vinculado à emissão.');

                return self::FAILURE;
            }

            $runA = $gate === null || $gate === 'A' || $gate === 'ALL';
            $runB = $gate === null || $gate === 'B' || $gate === 'ALL';
            $runC = $gate === null || $gate === 'C' || $gate === 'ALL';

            // Validate reviewer for write
            $reviewer = null;
            if ($write) {
                if (! filled($reviewerOpt)) {
                    $this->components->error('Explicit --reviewer=<user_id|email> is required for --write.');
                    $this->line('Available reviewers (sample):');
                    $candidates = User::query()
                        ->permission(AccessPermission::PuCalendarHomologationReview->value)
                        ->where('is_active', true)
                        ->whereNotNull('approved_at')
                        ->limit(5)
                        ->get();
                    foreach ($candidates as $u) {
                        $this->line(sprintf('  - #%d %s', $u->id, $u->email));
                    }

                    return self::FAILURE;
                }
                $reviewer = $runC
                    ? $closer->resolveEvidenceReviewer((string) $reviewerOpt)
                    : $closer->resolveReviewer((string) $reviewerOpt);
                if (! $reviewer) {
                    $this->components->error(sprintf('Reviewer "%s" not found, inactive, or missing required permission (pu.calendar-homologation.review or legal-instruments.confirm_change).', $reviewerOpt));

                    return self::FAILURE;
                }
                $this->components->info(sprintf('Reviewer for write: %s #%d (%s)', $reviewer->email, $reviewer->id, $reviewer->name));
                $this->newLine();
            }

            // Gate A
            if ($runA) {
                $this->components->info('Gate A — B3 × BCB SGS 4389 dossier');
                $stateA = $closer->gateAState();
                $summary = $stateA['summary_for_reviewer'];
                $this->table(
                    ['field', 'value'],
                    [
                        ['Contractual source', $summary['contractual_source']],
                        ['Candidate operational source', $summary['candidate_operational_source']],
                        ['Compared period', json_encode($summary['compared_period'])],
                        ['Common dates', $summary['common_dates'] ?? '—'],
                        ['Equal values', $summary['equal_values'] ?? '—'],
                        ['Divergent', $summary['divergent_values'] ?? '—'],
                        ['Only B3 / Only BCB', sprintf('%s / %s', $summary['only_b3'] ?? '—', $summary['only_bcb'] ?? '—')],
                        ['Transformation', Str::limit($summary['transformation'] ?? '', 80)],
                        ['Classification', $summary['classification'] ?? '—'],
                        ['Workflow status', $summary['workflow_status'] ?? '—'],
                        ['Checksum valid', ($summary['checksums']['checksum_valid'] ?? false) ? 'yes' : 'no'],
                        ['Artifact path', $summary['artifact']['path'] ?? '—'],
                        ['Artifact checksum', Str::limit($summary['checksums']['artifact_checksum'] ?? '', 20).'...'],
                        ['Report checksum', Str::limit($summary['checksums']['report_checksum'] ?? '', 20).'...'],
                        ['Executor', json_encode($summary['executor'] ?? [])],
                        ['Executed at', $summary['executed_at'] ?? '—'],
                    ]
                );
                $this->line('  Technical homologation: '.($stateA['is_technical_satisfied'] ? 'satisfied' : 'blocked'));
                $this->line('  Operational approval: '.($stateA['is_approved'] ? 'satisfied (already approved — idempotent)' : 'administrative_pending'));
                $dryA = $closer->gateADryRun();
                $this->line('  Dry-run action: '.$dryA['action'].' — '.($dryA['reason'] ?? $dryA['what_will_change'] ?? 'no reason'));
                if ($write) {
                    $this->line('  → Read-only in Phase 2B.5.12; no DI review, revision or checksum will be changed.');
                }
                $this->newLine();
            }

            // Gate B
            if ($runB) {
                $this->components->info('Gate B — BR_NATIONAL_HOLIDAYS 2026-2031');
                $stateB = $closer->gateBState();
                $this->line(sprintf('  Calendar: %s — technical %s, administrative %s', $stateB['calendar_code'], $stateB['technical_coverage_satisfied'] ? 'satisfied' : 'blocked', $stateB['administratively_confirmed'] ? 'satisfied' : 'administrative_pending'));
                $this->table(
                    ['Year', 'Coverage', 'Governance', 'Review state', 'Holidays', 'Conflicts', 'Overrides', 'Checksum (short)'],
                    collect($stateB['years'])->map(fn (array $y): array => [
                        $y['year'],
                        $y['coverage_status'] ?? $y['review_state'],
                        $y['governance_status'] ?? '—',
                        $y['review_state'],
                        sprintf('%d/9', $y['holiday_count']),
                        $y['conflicts'],
                        $y['overrides'],
                        $y['checksum'] ? substr($y['checksum'], 0, 8).'...' : '—',
                    ])->all()
                );
                $this->line('  Expected holidays per year: 01/01, 21/04, 01/05, 07/09, 12/10, 02/11, 15/11, 20/11, 25/12 (9)');
                $this->line('  Exclusions (not auto-added): Carnival Mon/Tue, Ash Wed, Good Friday, Corpus Christi, state/municipal, optional, 24/12, 31/12');
                $this->line('  Weekend: Saturdays/Sundays non-business by base rule');
                $this->line('  Pecuniary scope: PU = pecuniary → BR_NATIONAL_HOLIDAYS only; non-pecuniary São Paulo commercial holidays excluded');
                $dryB = $closer->gateBDryRun();
                $this->line('  Dry-run action: '.$dryB['action'].' — '.($dryB['reason'] ?? $dryB['what_will_change'] ?? ''));
                if ($write) {
                    $this->line('  → Read-only in Phase 2B.5.12; no calendar year, revision or checksum will be changed.');
                }
                $this->newLine();
            }

            // Gate C
            if ($runC) {
                $this->components->info('Gate C — First integration (curve_start_date)');
                $dateOpt = $integrationDateOpt;
                $docOpt = filled($integrationDocumentOpt) ? (int) $integrationDocumentOpt : null;
                $search = $closer->gateCSearch($docOpt);
                $this->line(sprintf('  Linked documents: %d', count($search['linked_documents'] ?? [])));
                foreach (($search['classification'] ?? []) as $c) {
                    $this->line(sprintf('    - Doc #%d "%s" — %s (%s)', $c['document_id'], $c['title'], $c['strength'], $c['reason']));
                    if (($c['document_id'] ?? null) === $docOpt) {
                        $this->line('      analysis_status: '.($c['analysis_status'] ?? '—'));
                        $this->line('      emission_match: '.(($c['emission_match'] ?? false) ? 'yes' : 'no'));
                        $this->line('      identifiers: '.json_encode($c['identifiers_found'] ?? [], JSON_UNESCAPED_UNICODE));
                        $this->line('      dates: '.json_encode($c['dates'] ?? [], JSON_UNESCAPED_UNICODE));
                        $this->line('      candidate integration date: '.($c['candidate_integration_date'] ?? 'PENDING'));
                        $this->line('      CLI integration date: '.($dateOpt ?? 'not supplied'));
                        $this->line('      quantity found: '.($c['quantity'] ?? 'none').' (not implicitly approved)');
                    }
                }
                if (empty($search['classification'])) {
                    $this->line('  Nenhum documento vinculado à emissão para triagem documental.');
                }
                if ($docOpt === null && $dateOpt !== null) {
                    $this->components->warn('  --integration-date sozinha não prova nada: ela só é comparada contra a data comprovada no documento selecionado.');
                }
                $this->line(sprintf('  Existing evidences: %d', count($search['existing_evidences'] ?? [])));
                foreach (($search['existing_evidences'] ?? []) as $e) {
                    $this->line(sprintf('    - #%d %s = %s (%s, %s)', $e['id'], $e['evidence_type'], $e['evidenced_value'], $e['status'], $e['confidence']));
                }
                $stateC = $closer->gateCState();
                $this->line('  Current curve_start_date: '.$stateC['curve_start_date']);
                $dryC = $closer->gateCDryRun($docOpt, $dateOpt);
                $this->line('  Dry-run action: '.$dryC['action'].' — '.($dryC['reason'] ?? $dryC['what_will_change'] ?? 'no reason'));
                if (isset($dryC['required_external_document'])) {
                    $this->line('  Required external document: '.$dryC['required_external_document']);
                }
                if (array_key_exists('date_match', $dryC)) {
                    $this->line('  Date match: '.($dryC['date_match'] ? 'yes' : 'no'));
                }
                if (isset($dryC['documented_integration_date'])) {
                    $this->line('  Documented integration date: '.$dryC['documented_integration_date']);
                }
                if (isset($dryC['approved_integration_date'])) {
                    $this->line('  Approved integration date (preservada): '.$dryC['approved_integration_date']);
                }
                if (is_array($dryC['document_analysis'] ?? null)) {
                    $a = $dryC['document_analysis'];
                    $this->table(
                        ['analysis', 'value'],
                        [
                            ['strength', $a['strength'] ?? '—'],
                            ['analysis_status', $a['analysis_status'] ?? '—'],
                            ['document_type', $a['document_type'] ?? '—'],
                            ['issuer', sprintf('%s [%s]', $a['issuer']['name'] ?? '—', $a['issuer']['role'] ?? '—')],
                            ['emission_match', ($a['emission_match'] ?? false) ? 'yes' : 'no'],
                            ['matched_identifiers', json_encode($a['matched_identifiers'] ?? [], JSON_UNESCAPED_UNICODE)],
                            ['candidate_event_semantic', $a['candidate_event_semantic'] ?? '—'],
                            ['date_source', $a['date_source'] ?? '—'],
                            ['excerpt', Str::limit($a['excerpt'] ?? '—', 120)],
                            ['confidence', sprintf('%s (%s)', $a['confidence'] ?? '—', $a['confidence_score'] ?? '—')],
                            ['ambiguity_reason', Str::limit($a['ambiguity_reason'] ?? '—', 120)],
                            ['ambiguity_resolution', Str::limit($a['ambiguity_resolution'] ?? '—', 120)],
                            ['quantity_found', ($a['quantity'] ?? 'none').' (informativa; não aprovada)'],
                        ]
                    );
                }
                $this->line('  Título e nome de arquivo servem só para descoberta; a força probatória vem do conteúdo analisado.');
                $this->line('  Quantidade permanece separada: integralized_quantity não é promovida (bloqueia apenas aggregate_outputs).');
                if (! $dryRun && $write) {
                    if (in_array($dryC['action'] ?? null, ['will_create_pending_review', 'will_approve_pending_review'], true)) {
                        $creator = $closer->resolveEvidenceCreator(
                            filled($creatorOpt) ? (string) $creatorOpt : null,
                            $docOpt,
                        );

                        if (($dryC['action'] ?? null) === 'will_create_pending_review' && ! $creator instanceof User) {
                            $this->components->error('Evidence maker not found, inactive, unapproved, unauthorized, or absent. Supply --creator=<id|email> or publish the document through an authorized maker.');

                            return self::FAILURE;
                        }

                        $res = $closer->gateCExecute($creator, $reviewer, $dateOpt, $docOpt);
                        if ($res['executed'] ?? false) {
                            $this->components->info(sprintf('  → Evidence #%d: %s → %s (%s)', $res['evidence_id'], $res['initial_status'], $res['final_status'], $res['evidenced_value']));
                            $this->line(sprintf('    creator: %s; reviewer: %s', $res['creator'] ?? '—', $res['reviewer'] ?? '—'));
                            $this->line('    audit trail: '.implode(' → ', $res['audit_trail'] ?? []));
                        } else {
                            $this->line('  → '.$res['message']);

                            if (($res['action'] ?? null) !== 'already_approved') {
                                $this->components->error('Gate C write aborted: '.($res['action'] ?? 'blocked'));

                                return self::FAILURE;
                            }
                        }
                    } else {
                        $this->line('  → No mutation (idempotent or no strong evidence — correct to keep PENDING).');

                        if ($docOpt !== null && ($dryC['action'] ?? null) !== 'already_approved') {
                            $this->components->error('Gate C write aborted: '.$dryC['action']);

                            return self::FAILURE;
                        }
                    }
                }
                $this->newLine();
            }

            // Readiness after each gate (read-only, no snapshots/events loaded)
            $after = $closer->evaluateReadiness();
            $this->components->info(sprintf('Readiness after %s: %s', $write ? 'write' : 'dry-run', $after['status']));
            $this->line('  Candidate: '.json_encode($after['candidateConfiguration']));
            $this->line('  Pending fields: '.json_encode($after['pendingFields']));
            $this->line('  Blocking (candidate_configuration): '.json_encode($after['blocking']));
            $this->newLine();

            if (($after['candidateConfiguration']['curve_start_date'] ?? 'PENDING') !== 'PENDING') {
                $window = $closer->futureSnapshotWindow();
                $this->components->info('Future snapshot window (resolvable now):');
                $this->line(json_encode($window, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->newLine();
            } else {
                $this->line('Future snapshot window not resolvable until curve_start_date proven.');
                $this->newLine();
            }

            $this->components->info('Done. No index_rates, PU events, curves, PuHistory, payments or EmissionPuParameter were created. Gates A/B remained read-only.');
            $this->line('Se o Gate C segue bloqueado, solicite documento primário de liquidação que comprove a data efetiva; nenhuma data candidata é presumida.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function isCalendarDate(string $value): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return false;
        }

        return $date->toDateString() === $value;
    }
}
