<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Services\PuBaselineCandidatePersistenceService;
use App\Domain\PuCalculator\Services\PuEventMaterializationService;
use App\Domain\PuCalculator\Services\PuNumericPreparationPlanService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Models\BusinessCalendarYear;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\IndexRateSourceGovernanceReview;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentField;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * Estado sintético `ready_for_numeric_homologation` usado pelas suítes de
 * governança da curva candidata (2B.5.16).
 *
 * As suítes de persistência e de review precisam do mesmo cenário provado pela
 * 2B.5.15 mas não podem depender da ordem de carregamento nem dos helpers globais
 * de `PuNumericHomologationTest`. O cenário vive aqui, em código test-only
 * autocontido, para que cada suíte rode isolada por `--filter`.
 */
final class PuCandidateGovernanceFixture
{
    public const AS_OF = '2026-08-26';

    /**
     * Emissão sintética com parâmetro persistido, snapshots exatos, calendário
     * confirmado, dossiê da fonte aprovado, evidência de primeira integralização
     * e todos os eventos contratuais materializados.
     */
    public static function readyEmission(?CarbonImmutable $asOf = null): Emission
    {
        $asOf = self::asOf($asOf);
        $emission = self::emission();
        self::proveBaseline($emission);
        self::confirmCalendar();
        self::approveRateSourceDossier();
        self::proveFirstIntegralization($emission);
        $emission = $emission->fresh();
        self::persistParameter($emission, $asOf);
        $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
        self::loadRates($plan->requiredRateDates);
        self::materializeEvents($emission, $asOf);

        return $emission->fresh();
    }

    public static function asOf(?CarbonImmutable $asOf = null): CarbonImmutable
    {
        return ($asOf ?? CarbonImmutable::parse(self::AS_OF))->startOfDay();
    }

    public static function emission(): Emission
    {
        return Emission::factory()->create([
            'name' => 'CRI Alto Bellevue',
            'type' => 'CRI',
            'if_code' => '26E0017614',
            'isin_code' => 'BRALBLCRI008',
            'status' => 'active',
            'issue_date' => '2026-05-08',
            'maturity_date' => '2031-05-08',
            'issued_quantity' => 5000,
            'issued_price' => '1000.00',
            'remuneration_indexer' => 'CDI',
            'remuneration_rate' => '6.00',
            'interest_payment_frequency' => 'Mensal',
            'amortization_frequency' => 'Bullet',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function actor(array $permissions = []): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $actor = User::factory()->create(['approved_at' => now()]);
        $actor->givePermissionTo($permissions);

        return $actor->fresh();
    }

    public static function maker(): User
    {
        return self::actor([AccessPermission::PuCurveGenerate->value]);
    }

    public static function checker(): User
    {
        return self::actor([AccessPermission::PuCurveHomologate->value]);
    }

    public static function proveBaseline(Emission $emission): LegalInstrument
    {
        $document = Document::factory()->create(['title' => 'Termo de Securitização sintético']);
        $emission->documents()->attach($document);
        $instrument = LegalInstrument::factory()
            ->ofType(LegalInstrumentType::SecuritizationTerm, '001/CONTRATO')
            ->create(['emission_id' => $emission->id]);
        $fields = [
            'issue_date' => ['2026-05-08', null, '2026-05-08'],
            'indexer' => [PuIndexer::Cdi->value, null, null],
            'index_percentage' => ['100%', 1.0, null],
            'spread' => ['6%', 0.06, null],
            'business_day_basis' => ['252', 252.0, null],
            'day_count_rule' => ['DU/252', null, null],
            'business_day_definition' => ['Dia útil conforme feriados nacionais', null, null],
            'calendar_code' => [BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS, null, null],
            'index_rate_lookup_mode' => [PuIndexRateLookupMode::BusinessDayLagExact->value, null, null],
            'index_rate_lag_business_days' => ['-5', -5.0, null],
            'initial_unit_value' => ['1000,00', 1000.0, null],
            'maturity_date' => ['2031-05-08', null, '2031-05-08'],
            'payment_schedule' => ['Anexo II — cronograma mensal de juros', null, null],
            'first_interest_payment_date' => ['2026-06-08', null, '2026-06-08'],
            'interest_payment_frequency' => ['monthly', null, null],
            'amortization' => ['bullet', null, null],
            'payment_convention' => ['following_business_day', null, null],
            'first_coupon_pre_integralization_premium_enabled' => ['1', null, null],
            'first_coupon_pre_integralization_business_days' => ['2', 2.0, null],
            'first_coupon_pre_integralization_apply_index_factor' => ['1', null, null],
            'first_coupon_pre_integralization_apply_spread_factor' => ['1', null, null],
        ];

        foreach ($fields as $fieldKey => [$value, $numeric, $date]) {
            $key = LegalInstrumentFieldKey::from($fieldKey);
            LegalInstrumentField::factory()->for($instrument, 'instrument')->create([
                'field_key' => $key,
                'value_type' => $key->valueType(),
                'value' => $value,
                'value_numeric' => $numeric,
                'value_date' => $date,
                'effective_date' => '2026-05-08',
                'status' => LegalInstrumentFieldStatus::Confirmed,
                'document_id' => $document->id,
                'clause' => '6.1',
                'page' => 12,
                'confidence_score' => 0.95,
                'has_conflict' => false,
            ]);
        }

        return $instrument;
    }

    public static function confirmCalendar(): void
    {
        $responsible = User::factory()->create();
        app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2031, $responsible->id);

        foreach (range(2026, 2031) as $year) {
            $calendarYear = BusinessCalendarYear::query()
                ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
                ->where('year', $year)
                ->firstOrFail();

            app(BusinessCalendarYearService::class)->confirm(
                BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
                $year,
                (string) $calendarYear->source,
                (string) $calendarYear->source_document,
                $calendarYear->source_revision,
                $calendarYear->checksum,
                $responsible->id,
            );
        }
    }

    public static function proveFirstIntegralization(Emission $emission): EmissionPuBaselineEvidence
    {
        $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
        $emission->documents()->attach($document);

        return EmissionPuBaselineEvidence::factory()->create([
            'emission_id' => $emission->id,
            'document_id' => $document->id,
            'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate,
            'document_type' => 'b3_settlement_statement',
            'evidenced_value' => '2026-05-15',
            'reference' => 'Liquidação sintética de 2026-05-15',
            'confidence' => 'high',
            'status' => PuBaselineEvidenceStatus::Approved,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public static function approveRateSourceDossier(): IndexRateSourceGovernanceReview
    {
        $report = [
            'phase' => '2B.5.4 — Homologação da Fonte da Taxa DI',
            'workflow_status' => 'ready_for_review',
            'approved' => false,
            'classification' => 'B — Equivalência comprovada com transformação',
            'started_at' => '2026-08-25T15:18:54+00:00',
            'completed_at' => '2026-08-25T15:19:27+00:00',
            'requested_period' => ['from' => '2025-01-01', 'to' => '2026-08-25'],
            'compared_period' => ['from' => '2025-01-01', 'to' => '2026-08-24'],
            'normalization' => [
                'b3' => '9 dígitos com duas casas implícitas.',
                'bcb' => 'Separador decimal normalizado sem arredondamento.',
                'comparison' => 'Comparação decimal exata; tolerância zero.',
            ],
            'sources' => [
                'b3' => [
                    'compared_records' => 413,
                    'normalized_checksum' => str_repeat('a', 64),
                    'raw_payload_manifest_checksum' => str_repeat('b', 64),
                    'payloads' => [['source_reference' => 'b3:manifest', 'sha256' => str_repeat('c', 64)]],
                ],
                'bcb' => [
                    'compared_records' => 413,
                    'normalized_checksum' => str_repeat('a', 64),
                    'raw_payload_manifest_checksum' => str_repeat('d', 64),
                    'payloads' => [[
                        'url' => 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.4389/dados',
                        'sha256' => str_repeat('e', 64),
                    ]],
                ],
            ],
            'comparison' => [
                'summary' => [
                    'present_equal' => 413,
                    'present_different' => 0,
                    'only_b3' => 0,
                    'only_bcb' => 0,
                    'common_dates' => 413,
                ],
            ],
            'executor' => [
                'type' => 'console_command',
                'label' => 'pu:index-rates:homologate-di-source',
                'user_id' => null,
            ],
        ];
        $report['report_checksum'] = hash('sha256', (string) json_encode(
            $report,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $path = 'homologations/index-rate-sources/cdi-b3-vs-bcb-4389-candidate-governance.json';
        Storage::disk('local')->put($path, json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return IndexRateSourceGovernanceReview::factory()->create([
            'source_code' => 'bcb_sgs_4389',
            'report_checksum' => $report['report_checksum'],
            'artifact_disk' => 'local',
            'artifact_path' => $path,
            'status' => IndexRateSourceGovernanceReview::STATUS_APPROVED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_notes' => 'Dossiê revisado e aprovado para uso operacional.',
        ]);
    }

    public static function persistParameter(Emission $emission, ?CarbonImmutable $asOf = null): EmissionPuParameter
    {
        $actor = self::actor([AccessPermission::PuParametersConfigure->value]);
        app(PuBaselineCandidatePersistenceService::class)->write(
            $emission,
            $actor->email,
            self::asOf($asOf),
        );

        return EmissionPuParameter::query()->whereBelongsTo($emission)->firstOrFail();
    }

    /**
     * @param  list<string>  $dates
     */
    public static function loadRates(array $dates, string $value = '14.90000000'): void
    {
        foreach ($dates as $date) {
            IndexRate::factory()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date,
                'rate_value' => $value,
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
                'external_series_code' => '4389',
                'is_projected' => false,
            ]);
        }
    }

    public static function materializeEvents(Emission $emission, ?CarbonImmutable $asOf = null): void
    {
        $actor = self::actor([AccessPermission::PuParametersConfigure->value]);
        app(PuEventMaterializationService::class)->write($emission, $actor->email, self::asOf($asOf));
    }

    /**
     * Contadores de tudo que uma candidate isolada NÃO pode alterar.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return [
            'parameters' => EmissionPuParameter::query()->count(),
            'rates' => IndexRate::query()->count(),
            'events' => EmissionPuEvent::query()->count(),
            'operational_versions' => EmissionPuCurveVersion::query()->operational()->count(),
            'candidate_versions' => EmissionPuCurveVersion::query()->candidate()->count(),
            'daily_curves' => EmissionPuDailyCurve::query()->count(),
            'histories' => PuHistory::query()->count(),
            'payments' => Payment::query()->count(),
        ];
    }
}
