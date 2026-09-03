<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkRowData;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Services\PuBaselineCandidatePersistenceService;
use App\Domain\PuCalculator\Services\PuEventMaterializationService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkComparisonService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkFingerprintService;
use App\Domain\PuCalculator\Services\PuNumericPreparationPlanService;
use App\Domain\PuCalculator\Services\PuPersistedCurveChecksumService;
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
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalBenchmarkRow;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationGap;
use App\Models\EmissionPuExternalValidationRow;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\IndexRateSourceGovernanceReview;
use App\Models\IntegralizationHistory;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentField;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
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

    public static function externalReviewer(): User
    {
        return self::actor([AccessPermission::PuCurveHomologate->value]);
    }

    /**
     * Concede a um usuário existente a permissão de homologação exigida do
     * revisor externo. Uso restrito aos cenários de independência, onde o
     * maker ou o revisor interno precisa primeiro passar na autorização para
     * então ser recusado por segregação de função.
     */
    public static function authorizeExternalReviewer(User $user): User
    {
        Permission::findOrCreate(AccessPermission::PuCurveHomologate->value);
        $user->givePermissionTo(AccessPermission::PuCurveHomologate->value);

        return $user->fresh();
    }

    /**
     * Candidate sintética já aprovada internamente, com linhas e checksum
     * coerentes. A extensão é aditiva e não altera o cenário numérico histórico.
     *
     * @param  array<string, string>  $unitValuesByDate
     */
    public static function approvedCandidate(
        Emission $emission,
        ?User $maker = null,
        ?User $checker = null,
        array $unitValuesByDate = [
            '2026-01-02' => '1000.0000000000000000',
            '2026-01-05' => '1010.0000000000000000',
            '2026-01-06' => '1020.0000000000000000',
        ],
    ): EmissionPuCurveVersion {
        $maker ??= self::maker();
        $checker ??= self::checker();
        $version = EmissionPuCurveVersion::factory()->candidate()->create([
            'emission_id' => $emission->id,
            'calculation_version' => 'external-validation-candidate',
            'candidate_as_of' => max(array_keys($unitValuesByDate)),
            'generated_by' => $maker->id,
            'review_status' => PuCurveReviewStatus::Approved,
            'reviewed_by' => $checker->id,
            'reviewed_at' => now(),
            'rows_count' => count($unitValuesByDate),
            'status' => PuCurveStatus::Validated,
            'curve_role' => PuCurveRole::Candidate,
            'internal_validation_status' => PuCurveInternalValidationStatus::Passed,
            'external_validation_status' => PuCurveExternalValidationStatus::Pending,
        ]);

        foreach ($unitValuesByDate as $date => $unitValue) {
            EmissionPuDailyCurve::factory()->create([
                'emission_id' => $emission->id,
                'curve_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'curve_date' => $date,
                'updated_unit_value' => $unitValue,
            ]);
        }

        $version->forceFill([
            'curve_checksum' => app(PuPersistedCurveChecksumService::class)->checksum($version->fresh()),
        ])->saveQuietly();

        return $version->fresh();
    }

    public static function operationalCurve(Emission $emission): EmissionPuCurveVersion
    {
        $version = EmissionPuCurveVersion::factory()->create([
            'emission_id' => $emission->id,
            'calculation_version' => 'operational-v1',
            'rows_count' => 1,
        ]);

        EmissionPuDailyCurve::factory()->create([
            'emission_id' => $emission->id,
            'curve_version_id' => $version->id,
            'calculation_version' => $version->calculation_version,
            'curve_date' => '2026-01-02',
        ]);

        return $version->fresh();
    }

    public static function proveExternalReference(Emission $emission): EmissionPuBaselineEvidence
    {
        $document = Document::factory()->create(['title' => 'Curva independente do agente fiduciário']);
        $emission->documents()->attach($document);

        return EmissionPuBaselineEvidence::factory()->create([
            'emission_id' => $emission->id,
            'document_id' => $document->id,
            'evidence_type' => PuBaselineEvidenceType::ExternalPuReference,
            'document_type' => 'official_pu_memory',
            'evidenced_value' => 'Referência externa disponível para ingestão estruturada',
            'reference' => 'Agente fiduciário — versão sintética',
            'status' => PuBaselineEvidenceStatus::Approved,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Diretório temporário por processo para os arquivos de benchmark. Fica fora
     * de `Storage::fake` porque o parser recebe um caminho local real, e o token
     * de PID evita que duas execuções simultâneas da suíte apaguem os arquivos
     * uma da outra.
     */
    public static function externalBenchmarkDirectory(): string
    {
        $directory = sys_get_temp_dir().'/pu-external-benchmark-'.getmypid();

        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        return $directory;
    }

    /**
     * Escreve um arquivo bruto de benchmark e devolve o caminho local. Serve
     * tanto para os casos válidos quanto para os malformados.
     */
    public static function externalBenchmarkRawFile(string $contents, string $extension = 'csv'): string
    {
        $path = sprintf(
            '%s/%s.%s',
            self::externalBenchmarkDirectory(),
            'benchmark-'.bin2hex(random_bytes(8)),
            $extension,
        );
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * CSV governado com exatamente uma coluna de data e uma de PU.
     *
     * @param  array<string, string>  $unitValuesByDate
     */
    public static function externalBenchmarkCsv(
        array $unitValuesByDate,
        string $delimiter = ',',
        string $dateHeader = 'Data',
        string $unitValueHeader = 'PU',
    ): string {
        $lines = [$dateHeader.$delimiter.$unitValueHeader];

        foreach ($unitValuesByDate as $date => $unitValue) {
            $lines[] = $date.$delimiter.$unitValue;
        }

        return self::externalBenchmarkRawFile(implode("\n", $lines)."\n");
    }

    /**
     * XLSX governado. Com `$formulaCell` o arquivo passa a conter uma fórmula,
     * que a fase recusa em vez de aceitar o resultado em cache.
     *
     * @param  array<string, string>  $unitValuesByDate
     */
    public static function externalBenchmarkXlsx(
        array $unitValuesByDate,
        bool $formulaCell = false,
        bool $excelSerialDates = false,
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setCellValue('A1', 'Data');
        $sheet->setCellValue('B1', 'PU');
        $rowNumber = 2;

        foreach ($unitValuesByDate as $date => $unitValue) {
            if ($excelSerialDates) {
                $sheet->setCellValue(
                    'A'.$rowNumber,
                    ExcelDate::PHPToExcel(CarbonImmutable::parse($date)->startOfDay()),
                );
            } else {
                $sheet->setCellValueExplicit('A'.$rowNumber, $date, DataType::TYPE_STRING);
            }

            if ($formulaCell && $rowNumber === 2) {
                $sheet->setCellValue('B'.$rowNumber, '=1000+0');
            } else {
                $sheet->setCellValueExplicit('B'.$rowNumber, $unitValue, DataType::TYPE_STRING);
            }

            $rowNumber++;
        }

        $path = sprintf(
            '%s/benchmark-%s.xlsx',
            self::externalBenchmarkDirectory(),
            bin2hex(random_bytes(8)),
        );
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Benchmark já persistido com dataset checksum coerente, sem passar pelo
     * parser. É o único caminho capaz de produzir cenários que o parser recusa
     * na entrada (PU zero, por exemplo) mas que a comparação precisa tratar.
     *
     * @param  array<string, string>  $unitValuesByDate
     */
    public static function persistedExternalBenchmark(
        Emission $emission,
        array $unitValuesByDate,
        ?User $importer = null,
        string $sourceName = 'Agente fiduciário independente',
    ): EmissionPuExternalBenchmark {
        $importer ??= self::checker();
        $rounder = app(DecimalRounder::class);
        $normalized = [];

        foreach ($unitValuesByDate as $date => $unitValue) {
            $normalized[$date] = $rounder->normalize($unitValue, DecimalRounder::UNIT_SCALE);
        }

        ksort($normalized);
        $rowData = [];

        foreach ($normalized as $date => $unitValue) {
            $rowData[] = new PuExternalBenchmarkRowData(
                referenceDate: CarbonImmutable::parse($date)->startOfDay(),
                unitValue: $unitValue,
            );
        }

        $datasetSha256 = app(PuExternalBenchmarkFingerprintService::class)->dataset($rowData);
        $dates = array_keys($normalized);
        $benchmark = EmissionPuExternalBenchmark::factory()->create([
            'emission_id' => $emission->id,
            'source_name' => $sourceName,
            'reference_as_of' => end($dates),
            'dataset_sha256' => $datasetSha256,
            'import_identity_sha256' => hash('sha256', $datasetSha256.'|'.$emission->id.'|'.$sourceName),
            'row_count' => count($normalized),
            'from_date' => reset($dates),
            'to_date' => end($dates),
            'created_by' => $importer->id,
        ]);

        foreach ($normalized as $date => $unitValue) {
            EmissionPuExternalBenchmarkRow::factory()->create([
                'benchmark_id' => $benchmark->id,
                'reference_date' => $date,
                'unit_value' => $unitValue,
            ]);
        }

        return $benchmark->fresh();
    }

    /**
     * Dossiê de comparação persistido pelo serviço real, para que os testes de
     * decisão partam de um artefato reproduzível e não de um mock.
     */
    public static function persistedExternalComparison(
        EmissionPuCurveVersion $candidate,
        EmissionPuExternalBenchmark $benchmark,
        ?User $actor = null,
    ): EmissionPuExternalValidation {
        $actor ??= self::checker();
        $result = app(PuExternalBenchmarkComparisonService::class)->write($candidate, $benchmark, (string) $actor->id);

        if ($result->externalValidationId === null) {
            throw new RuntimeException(sprintf(
                'Expected a persisted external comparison; got %s (%s).',
                $result->action,
                $result->reason,
            ));
        }

        return EmissionPuExternalValidation::query()->findOrFail($result->externalValidationId);
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
            'integralizations' => IntegralizationHistory::query()->count(),
            'operational_versions' => EmissionPuCurveVersion::query()->operational()->count(),
            'candidate_versions' => EmissionPuCurveVersion::query()->candidate()->count(),
            'daily_curves' => EmissionPuDailyCurve::query()->count(),
            'external_benchmarks' => EmissionPuExternalBenchmark::query()->count(),
            'external_benchmark_rows' => EmissionPuExternalBenchmarkRow::query()->count(),
            'external_validations' => EmissionPuExternalValidation::query()->count(),
            'external_validation_rows' => EmissionPuExternalValidationRow::query()->count(),
            'external_validation_gaps' => EmissionPuExternalValidationGap::query()->count(),
            'histories' => PuHistory::query()->count(),
            'payments' => Payment::query()->count(),
        ];
    }
}
