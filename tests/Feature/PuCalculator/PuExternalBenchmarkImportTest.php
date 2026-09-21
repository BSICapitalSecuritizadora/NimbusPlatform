<?php

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkRowData;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuExternalValidationCoverageStatus;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuCandidateExternalValidationEligibilityService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkFileParser;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkFingerprintService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkImportPlanService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkImportService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkIntegrityService;
use App\Domain\PuCalculator\Services\PuExternalValidationActorService;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalBenchmarkRow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuCandidateGovernanceFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Datas exatas da candidate sintética aprovada internamente, reutilizadas por
 * todos os cenários de overlap.
 *
 * @return array<string, string>
 */
function externalBenchmarkCandidateValues(): array
{
    return [
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
        '2026-01-06' => '1020.0000000000000000',
    ];
}

function externalBenchmarkPlan(): PuExternalBenchmarkImportPlanService
{
    return app(PuExternalBenchmarkImportPlanService::class);
}

function externalBenchmarkImport(): PuExternalBenchmarkImportService
{
    return app(PuExternalBenchmarkImportService::class);
}

// ---------------------------------------------------------------------------
// Elegibilidade da candidate — o import é sempre atado a uma candidate elegível
// ---------------------------------------------------------------------------

it('refuses to plan an external benchmark import without any persisted candidate', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalBenchmarkPlan()->plan(
        null,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
    );

    expect($result->action)->toBe(PuCandidateExternalValidationEligibilityService::ACTION_NOT_READY)
        ->and($result->writes)->toBe(0)
        ->and($result->benchmarkId)->toBeNull()
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before)
        ->and($emission->fresh())->not->toBeNull();
});

it('refuses an operational version as the external validation subject', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);

    $result = externalBenchmarkPlan()->plan(
        $operational,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
    );

    expect($result->action)->toBe(PuCandidateExternalValidationEligibilityService::ACTION_NOT_READY)
        ->and($result->writes)->toBe(0)
        ->and($operational->fresh()->curve_role)->toBe(PuCurveRole::Operational);
});

it('refuses a candidate whose internal review is still pending or rejected', function (PuCurveReviewStatus $reviewStatus) {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    DB::table('emission_pu_curve_versions')
        ->where('id', $candidate->id)
        ->update(['review_status' => $reviewStatus->value]);

    $result = externalBenchmarkPlan()->plan(
        $candidate->fresh(),
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
    );

    expect($result->action)->toBe(PuCandidateExternalValidationEligibilityService::ACTION_NOT_READY)
        ->and($result->writes)->toBe(0);
})->with([
    'pending review' => PuCurveReviewStatus::PendingReview,
    'rejected review' => PuCurveReviewStatus::Rejected,
]);

it('accepts only the internally approved candidate as ready to import', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);

    $result = externalBenchmarkPlan()->plan(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
    );

    expect($result->action)->toBe(PuExternalBenchmarkImportPlanService::ACTION_READY)
        ->and($result->writes)->toBe(0)
        ->and($result->coveragePreview)->toBe(PuExternalValidationCoverageStatus::Full->value)
        ->and($candidate->fresh()->review_status)->toBe(PuCurveReviewStatus::Approved);
});

// ---------------------------------------------------------------------------
// Parser — formatos, datas, decimais e falhas duras
// ---------------------------------------------------------------------------

it('parses a governed CSV benchmark into normalized decimal strings', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    $dataset = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv([
        '2026-01-06' => '1020.5',
        '2026-01-02' => '1000',
    ]));

    expect($dataset->rows)->toHaveCount(2)
        ->and($dataset->rows[0]->referenceDate->toDateString())->toBe('2026-01-02')
        ->and($dataset->rows[0]->unitValue)->toBe('1000.0000000000000000')
        ->and($dataset->rows[1]->unitValue)->toBe('1020.5000000000000000')
        ->and($dataset->fromDate)->toBe('2026-01-02')
        ->and($dataset->toDate)->toBe('2026-01-06');
});

it('parses a governed XLSX benchmark including excel serial dates', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    $dataset = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkXlsx(
        externalBenchmarkCandidateValues(),
        excelSerialDates: true,
    ));

    expect($dataset->rows)->toHaveCount(3)
        ->and(array_map(
            fn ($row): string => $row->referenceDate->toDateString(),
            $dataset->rows,
        ))->toBe(['2026-01-02', '2026-01-05', '2026-01-06']);
});

it('accepts a semicolon delimited CSV and the brazilian decimal comma', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    $dataset = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv(
        ['2026-01-02' => '1000,25', '2026-01-05' => '1010'],
        delimiter: ';',
    ));

    expect($dataset->rows[0]->unitValue)->toBe('1000.2500000000000000')
        ->and($dataset->rows[1]->unitValue)->toBe('1010.0000000000000000');
});

it('rejects an ambiguous thousands separator instead of guessing', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv(
        ['2026-01-02' => '1.234,56', '2026-01-05' => '1.010,00'],
        delimiter: ';',
    )))->toThrow(InvalidArgumentException::class, 'invalid PU decimal');
});

it('rejects unsupported spreadsheet formats', function (string $extension) {
    $parser = app(PuExternalBenchmarkFileParser::class);
    $path = PuCandidateGovernanceFixture::externalBenchmarkRawFile("Data,PU\n2026-01-02,1000\n", $extension);

    expect(fn () => $parser->parse($path))->toThrow(InvalidArgumentException::class);
})->with(['xls', 'xlsm', 'ods', 'pdf', 'txt']);

it('rejects a file whose content does not match its extension', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);
    $xlsx = PuCandidateGovernanceFixture::externalBenchmarkXlsx(externalBenchmarkCandidateValues());
    $disguised = PuCandidateGovernanceFixture::externalBenchmarkRawFile(file_get_contents($xlsx), 'csv');

    expect(fn () => $parser->parse($disguised))
        ->toThrow(InvalidArgumentException::class, 'does not match its extension');
});

it('rejects an empty dataset as a hard failure', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(
        PuCandidateGovernanceFixture::externalBenchmarkRawFile("Data,PU\n")
    ))->toThrow(InvalidArgumentException::class, 'at least one data row');
});

it('rejects an empty file before reading it', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkRawFile('')))
        ->toThrow(InvalidArgumentException::class, 'empty');
});

it('rejects a malformed reference date', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(
        PuCandidateGovernanceFixture::externalBenchmarkRawFile("Data,PU\n2026-02-30,1000\n")
    ))->toThrow(InvalidArgumentException::class, 'invalid date');
});

it('rejects a malformed PU value', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(
        PuCandidateGovernanceFixture::externalBenchmarkRawFile("Data,PU\n2026-01-02,mil reais\n")
    ))->toThrow(InvalidArgumentException::class, 'invalid PU decimal');
});

it('rejects a duplicated reference date instead of keeping one silently', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(
        PuCandidateGovernanceFixture::externalBenchmarkRawFile("Data,PU\n2026-01-02,1000\n2026-01-02,1001\n")
    ))->toThrow(InvalidArgumentException::class, 'is duplicated on rows');
});

it('rejects an incomplete row', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(
        PuCandidateGovernanceFixture::externalBenchmarkRawFile("Data,PU\n2026-01-02,1000\n2026-01-05,\n")
    ))->toThrow(InvalidArgumentException::class, 'is incomplete');
});

it('rejects an ambiguous or missing header instead of guessing columns', function (string $header) {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(
        PuCandidateGovernanceFixture::externalBenchmarkRawFile($header."\n2026-01-02,1000\n")
    ))->toThrow(InvalidArgumentException::class, 'exactly one date column and one PU column');
})->with([
    'duplicated PU column' => 'Data,PU,PU',
    'unknown headers' => 'Coluna A,Coluna B',
]);

it('rejects an XLSX formula cell instead of trusting its cached result', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkXlsx(
        externalBenchmarkCandidateValues(),
        formulaCell: true,
    )))->toThrow(InvalidArgumentException::class, 'Formula cells are not accepted');
});

it('rejects a non positive PU as a hard failure at ingestion', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    expect(fn () => $parser->parse(
        PuCandidateGovernanceFixture::externalBenchmarkRawFile("Data,PU\n2026-01-02,0\n")
    ))->toThrow(InvalidArgumentException::class, 'must be greater than zero');
});

it('enforces an explicit maximum benchmark file size', function () {
    expect(PuExternalBenchmarkFileParser::MAX_FILE_SIZE_BYTES)->toBe(10 * 1024 * 1024);
});

// ---------------------------------------------------------------------------
// Fingerprint semântico — dataset SHA versus file SHA
// ---------------------------------------------------------------------------

it('produces the same dataset checksum for the same semantic data', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);
    $values = externalBenchmarkCandidateValues();

    $first = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv($values));
    $second = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv($values));

    expect($second->datasetSha256)->toBe($first->datasetSha256);
});

it('ignores the physical row order in the dataset checksum', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);
    $values = externalBenchmarkCandidateValues();

    $ascending = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv($values));
    $descending = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv(array_reverse($values, true)));

    expect($descending->datasetSha256)->toBe($ascending->datasetSha256)
        ->and($descending->fileSha256)->not->toBe($ascending->fileSha256);
});

it('ignores the filename in the dataset checksum', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);
    $values = externalBenchmarkCandidateValues();

    $first = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv($values));
    $second = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv($values));

    expect($second->inputFileName)->not->toBe($first->inputFileName)
        ->and($second->datasetSha256)->toBe($first->datasetSha256)
        ->and($second->fileSha256)->toBe($first->fileSha256);
});

it('changes the dataset checksum when a PU or a date changes', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);
    $baseline = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv(
        externalBenchmarkCandidateValues()
    ));

    // CSV é texto: o literal decimal chega exato ao domínio, sem passar por
    // float, de modo que um delta de 1e-16 dentro de UNIT_SCALE distingue.
    $changedUnitValue = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv([
        '2026-01-02' => '1000.0000000000000001',
        '2026-01-05' => '1010.0000000000000000',
        '2026-01-06' => '1020.0000000000000000',
    ]));
    $changedDate = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv([
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
        '2026-01-07' => '1020.0000000000000000',
    ]));

    expect($baseline->rows[0]->unitValue)->toBe('1000.0000000000000000')
        ->and($changedUnitValue->rows[0]->referenceDate->toDateString())->toBe('2026-01-02')
        ->and($changedUnitValue->rows[0]->unitValue)->toBe('1000.0000000000000001')
        ->and($changedDate->rows[2]->referenceDate->toDateString())->toBe('2026-01-07')
        ->and($baseline->rows[2]->referenceDate->toDateString())->toBe('2026-01-06')
        ->and($changedUnitValue->datasetSha256)->not->toBe($baseline->datasetSha256)
        ->and($changedDate->datasetSha256)->not->toBe($baseline->datasetSha256);
});

it('canonicalizes equivalent decimal representations to the same dataset checksum', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    $plain = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv([
        '2026-01-02' => '1000',
        '2026-01-05' => '1010',
    ]));
    $fractional = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv([
        '2026-01-02' => '1000.0',
        '2026-01-05' => '1010.0',
    ]));
    $scaled = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv([
        '2026-01-02' => '1000.0000000000000000',
        '2026-01-05' => '1010.0000000000000000',
    ]));

    expect($plain->rows[0]->unitValue)->toBe('1000.0000000000000000')
        ->and($fractional->rows[0]->unitValue)->toBe('1000.0000000000000000')
        ->and($scaled->rows[0]->unitValue)->toBe('1000.0000000000000000')
        ->and($fractional->datasetSha256)->toBe($plain->datasetSha256)
        ->and($scaled->datasetSha256)->toBe($plain->datasetSha256);
});

it('preserves a high precision PU written with the brazilian decimal comma', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    $baseline = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv(
        ['2026-01-02' => '1000.0000000000000000'],
    ));
    $changed = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkCsv(
        ['2026-01-02' => '1000,0000000000000001'],
        delimiter: ';',
    ));

    expect($changed->rows[0]->unitValue)->toBe('1000.0000000000000001')
        ->and($changed->datasetSha256)->not->toBe($baseline->datasetSha256);
});

it('preserves a high precision PU stored as an XLSX text cell', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    $baseline = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkXlsx([
        '2026-01-02' => '1000.0000000000000000',
    ]));
    $changed = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkXlsx([
        '2026-01-02' => '1000.0000000000000001',
    ]));

    expect($changed->rows[0]->unitValue)->toBe('1000.0000000000000001')
        ->and($changed->datasetSha256)->not->toBe($baseline->datasetSha256);
});

it('normalizes an XLSX numeric PU from the actual double stored in the workbook', function () {
    $parser = app(PuExternalBenchmarkFileParser::class);

    $dataset = $parser->parse(PuCandidateGovernanceFixture::externalBenchmarkXlsx(
        ['2026-01-02' => '1020.5'],
        numericPu: true,
    ));

    expect($dataset->rows)->toHaveCount(1)
        ->and($dataset->rows[0]->unitValue)->toBe('1020.5000000000000000');
});

it('keeps the dataset fingerprint free of identifiers, paths and timestamps', function () {
    $fingerprints = app(PuExternalBenchmarkFingerprintService::class);
    $rounder = app(DecimalRounder::class);
    $rows = [
        new PuExternalBenchmarkRowData(
            referenceDate: CarbonImmutable::parse('2026-01-02'),
            unitValue: $rounder->normalize('1000', DecimalRounder::UNIT_SCALE),
        ),
    ];

    expect($fingerprints->dataset($rows))->toBe($fingerprints->dataset($rows))
        ->and($fingerprints->dataset($rows))->toHaveLength(64);
});

// ---------------------------------------------------------------------------
// Write governado — ator, transação, idempotência, append-only
// ---------------------------------------------------------------------------

it('requires an explicit authorized actor to import a benchmark', function (?string $identifier, string $expectedAction) {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        $identifier,
    );

    expect($result->action)->toBe($expectedAction)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
})->with([
    'missing actor' => [null, PuExternalValidationActorService::ACTOR_REQUIRED],
    'unknown actor' => ['9999999', PuExternalValidationActorService::ACTOR_NOT_FOUND],
]);

it('refuses an inactive, unapproved or unauthorized import actor', function (string $state, string $expectedAction) {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $actor = match ($state) {
        'inactive' => tap(PuCandidateGovernanceFixture::checker(), fn (User $user) => $user->forceFill(['is_active' => false])->save()),
        'unapproved' => tap(PuCandidateGovernanceFixture::checker(), fn (User $user) => $user->forceFill(['approved_at' => null])->save()),
        default => PuCandidateGovernanceFixture::actor([]),
    };
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        (string) $actor->id,
    );

    expect($result->action)->toBe($expectedAction)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
})->with([
    'inactive actor' => ['inactive', PuExternalValidationActorService::ACTOR_INACTIVE],
    'unapproved actor' => ['unapproved', PuExternalValidationActorService::ACTOR_UNAPPROVED],
    'unauthorized actor' => ['unauthorized', PuExternalValidationActorService::ACTOR_UNAUTHORIZED],
]);

it('imports an immutable machine readable benchmark with full provenance', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $evidence = PuCandidateGovernanceFixture::proveExternalReference($emission);
    $actor = PuCandidateGovernanceFixture::checker();
    $path = PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues());

    $result = externalBenchmarkImport()->write(
        $candidate,
        $path,
        'external_file',
        'Agente fiduciário independente',
        '2026-01-06',
        $evidence->id,
        (string) $actor->id,
    );

    $benchmark = EmissionPuExternalBenchmark::query()->findOrFail($result->benchmarkId);

    expect($result->action)->toBe(PuExternalBenchmarkImportService::ACTION_IMPORTED)
        ->and($result->writes)->toBe(1)
        ->and($benchmark->emission_id)->toBe($emission->id)
        ->and($benchmark->source_type)->toBe('external_file')
        ->and($benchmark->source_name)->toBe('Agente fiduciário independente')
        ->and($benchmark->source_evidence_id)->toBe($evidence->id)
        ->and($benchmark->source_document_id)->toBe($evidence->document_id)
        ->and($benchmark->reference_as_of->toDateString())->toBe('2026-01-06')
        ->and($benchmark->row_count)->toBe(3)
        ->and($benchmark->from_date->toDateString())->toBe('2026-01-02')
        ->and($benchmark->to_date->toDateString())->toBe('2026-01-06')
        ->and($benchmark->file_sha256)->toBe(hash_file('sha256', $path))
        ->and($benchmark->dataset_sha256)->not->toBe($benchmark->file_sha256)
        ->and($benchmark->created_by)->toBe($actor->id)
        ->and($benchmark->rows()->count())->toBe(3)
        ->and($benchmark->rows()->orderBy('reference_date')->pluck('unit_value')->all())
        ->toBe(array_values(externalBenchmarkCandidateValues()));
});

it('refuses a source evidence that is not an approved external PU reference of the same emission', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $foreign = PuCandidateGovernanceFixture::proveExternalReference(PuCandidateGovernanceFixture::emission());
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        $foreign->id,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuExternalBenchmarkImportPlanService::ACTION_INVALID)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('never derives a PU from the documentary evidence text', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $evidence = PuCandidateGovernanceFixture::proveExternalReference($emission);

    $result = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(['2026-01-02' => '1000']),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        $evidence->id,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    $unitValues = EmissionPuExternalBenchmarkRow::query()
        ->where('benchmark_id', $result->benchmarkId)
        ->pluck('unit_value')
        ->all();

    expect($unitValues)->toBe(['1000.0000000000000000'])
        ->and($evidence->evidenced_value)->not->toBe('1000.0000000000000000');
});

it('rejects a benchmark with no exact date overlap with the candidate horizon', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $before = PuCandidateGovernanceFixture::counts();

    $result = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(['2026-03-02' => '1000']),
        'external_file',
        'Agente fiduciário',
        '2026-03-02',
        null,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );

    expect($result->action)->toBe(PuExternalBenchmarkImportPlanService::ACTION_INVALID)
        ->and($result->coveragePreview)->toBe(PuExternalValidationCoverageStatus::None->value)
        ->and($result->writes)->toBe(0)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('is idempotent for the same governed semantic dataset', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $actor = PuCandidateGovernanceFixture::checker();
    $values = externalBenchmarkCandidateValues();

    $first = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv($values),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        (string) $actor->id,
    );
    $afterFirst = PuCandidateGovernanceFixture::counts();
    $auditAfterFirst = Activity::query()->where('description', 'pu_external_benchmark_imported')->count();

    $second = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(array_reverse($values, true)),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        (string) $actor->id,
    );

    expect($first->action)->toBe(PuExternalBenchmarkImportService::ACTION_IMPORTED)
        ->and($second->action)->toBe(PuExternalBenchmarkImportPlanService::ACTION_ALREADY_IMPORTED)
        ->and($second->writes)->toBe(0)
        ->and($second->benchmarkId)->toBe($first->benchmarkId)
        ->and(PuCandidateGovernanceFixture::counts())->toBe($afterFirst)
        ->and(Activity::query()->where('description', 'pu_external_benchmark_imported')->count())
        ->toBe($auditAfterFirst);
});

it('appends a new artifact for a different dataset and keeps the previous one intact', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $actor = PuCandidateGovernanceFixture::checker();

    $first = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        (string) $actor->id,
    );
    $firstBenchmark = EmissionPuExternalBenchmark::query()->findOrFail($first->benchmarkId);
    $firstChecksum = $firstBenchmark->dataset_sha256;

    $second = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv([
            '2026-01-02' => '1000.5',
            '2026-01-05' => '1010.0',
            '2026-01-06' => '1020.0',
        ]),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        (string) $actor->id,
    );

    expect($second->action)->toBe(PuExternalBenchmarkImportService::ACTION_IMPORTED)
        ->and($second->benchmarkId)->not->toBe($first->benchmarkId)
        ->and(EmissionPuExternalBenchmark::query()->count())->toBe(2)
        ->and($firstBenchmark->fresh()->dataset_sha256)->toBe($firstChecksum)
        ->and($firstBenchmark->fresh()->rows()->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Imutabilidade e integridade do artefato
// ---------------------------------------------------------------------------

it('refuses to update or delete an imported benchmark through the normal flow', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark(
        $emission,
        externalBenchmarkCandidateValues(),
    );

    expect(fn () => $benchmark->update(['source_name' => 'Outro agente']))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $benchmark->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

it('refuses to correct a benchmark row in place', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark(
        $emission,
        externalBenchmarkCandidateValues(),
    );
    $row = $benchmark->rows()->firstOrFail();

    expect(fn () => $row->update(['unit_value' => '1.0000000000000000']))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $row->delete())
        ->toThrow(LogicException::class, 'immutable');
});

it('confirms the integrity of an intact persisted benchmark', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark(
        $emission,
        externalBenchmarkCandidateValues(),
    );

    $integrity = app(PuExternalBenchmarkIntegrityService::class)->inspect($benchmark);

    expect($integrity['valid'])->toBeTrue()
        ->and($integrity['checksum'])->toBe($benchmark->dataset_sha256);
});

it('detects a benchmark row corrupted behind the immutability guard', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $benchmark = PuCandidateGovernanceFixture::persistedExternalBenchmark(
        $emission,
        externalBenchmarkCandidateValues(),
    );
    DB::table('emission_pu_external_benchmark_rows')
        ->where('id', $benchmark->rows()->orderBy('id')->value('id'))
        ->update(['unit_value' => '1.0000000000000000']);

    $integrity = app(PuExternalBenchmarkIntegrityService::class)->inspect($benchmark->fresh());

    expect($integrity['valid'])->toBeFalse()
        ->and($integrity['reason'])->toContain('inconsistent');
});

// ---------------------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------------------

it('audits the benchmark import with hashes and provenance but never a file path', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $actor = PuCandidateGovernanceFixture::checker();
    $path = PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues());

    $result = externalBenchmarkImport()->write(
        $candidate,
        $path,
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        (string) $actor->id,
    );

    $activity = Activity::query()
        ->where('description', 'pu_external_benchmark_imported')
        ->latest('id')
        ->firstOrFail();
    $properties = $activity->properties->all();

    expect($activity->causer_id)->toBe($actor->id)
        ->and($properties['emission_id'])->toBe($emission->id)
        ->and($properties['candidate_version_id'])->toBe($candidate->id)
        ->and($properties['benchmark_id'])->toBe($result->benchmarkId)
        ->and($properties['file_sha256'])->toBe(hash_file('sha256', $path))
        ->and($properties['dataset_sha256'])->toBe($result->dataset->datasetSha256)
        ->and($properties['row_count'])->toBe(3)
        ->and($properties['from_date'])->toBe('2026-01-02')
        ->and($properties['to_date'])->toBe('2026-01-06')
        ->and(json_encode($properties))->not->toContain(dirname($path))
        // dirname($path, 2) é a raiz temporária do sistema, pai do diretório por-PID da fixture.
        ->and(json_encode($properties))->not->toContain(dirname($path, 2));
});

// ---------------------------------------------------------------------------
// Isolamento financeiro e operacional
// ---------------------------------------------------------------------------

it('imports a benchmark with zero financial and zero operational side effects', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $operational = PuCandidateGovernanceFixture::operationalCurve($emission);
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $before = PuCandidateGovernanceFixture::counts();
    $operationalBefore = $operational->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']);

    $result = externalBenchmarkImport()->write(
        $candidate,
        PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        'external_file',
        'Agente fiduciário',
        '2026-01-06',
        null,
        (string) PuCandidateGovernanceFixture::checker()->id,
    );
    $after = PuCandidateGovernanceFixture::counts();

    expect($result->writes)->toBe(1)
        ->and($after['rates'])->toBe($before['rates'])
        ->and($after['events'])->toBe($before['events'])
        ->and($after['parameters'])->toBe($before['parameters'])
        ->and($after['integralizations'])->toBe($before['integralizations'])
        ->and($after['histories'])->toBe($before['histories'])
        ->and($after['payments'])->toBe($before['payments'])
        ->and($after['daily_curves'])->toBe($before['daily_curves'])
        ->and($after['operational_versions'])->toBe($before['operational_versions'])
        ->and($after['candidate_versions'])->toBe($before['candidate_versions'])
        ->and($after['external_benchmarks'])->toBe($before['external_benchmarks'] + 1)
        ->and($after['external_benchmark_rows'])->toBe($before['external_benchmark_rows'] + 3)
        ->and($after['external_validations'])->toBe($before['external_validations'])
        ->and($operational->fresh()->only(['curve_role', 'status', 'rows_count', 'updated_at']))
        ->toEqual($operationalBefore)
        ->and($candidate->fresh()->curve_role)->toBe(PuCurveRole::Candidate)
        ->and($candidate->fresh()->external_validation_status)->toBe(PuCurveExternalValidationStatus::Pending);
});

// ---------------------------------------------------------------------------
// Comando
// ---------------------------------------------------------------------------

it('runs the import command read-only by default', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $before = PuCandidateGovernanceFixture::counts();

    $this->artisan('pu:curve-candidate:import-external-benchmark', [
        'version' => $candidate->id,
        'file' => PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        '--source-name' => 'Agente fiduciário',
        '--as-of' => '2026-01-06',
    ])
        ->expectsOutputToContain('Action: '.PuExternalBenchmarkImportPlanService::ACTION_READY)
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('lets dry-run win over write in the import command', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $before = PuCandidateGovernanceFixture::counts();

    $this->artisan('pu:curve-candidate:import-external-benchmark', [
        'version' => $candidate->id,
        'file' => PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues()),
        '--source-name' => 'Agente fiduciário',
        '--as-of' => '2026-01-06',
        '--actor' => (string) PuCandidateGovernanceFixture::checker()->id,
        '--dry-run' => true,
        '--write' => true,
    ])
        ->expectsOutputToContain('Writes: 0')
        ->assertSuccessful();

    expect(PuCandidateGovernanceFixture::counts())->toBe($before);
});

it('persists through the import command only with an explicit actor and --write', function () {
    $emission = PuCandidateGovernanceFixture::emission();
    $candidate = PuCandidateGovernanceFixture::approvedCandidate($emission);
    $path = PuCandidateGovernanceFixture::externalBenchmarkCsv(externalBenchmarkCandidateValues());

    $this->artisan('pu:curve-candidate:import-external-benchmark', [
        'version' => $candidate->id,
        'file' => $path,
        '--source-name' => 'Agente fiduciário',
        '--as-of' => '2026-01-06',
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: '.PuExternalValidationActorService::ACTOR_REQUIRED)
        ->assertFailed();

    expect(EmissionPuExternalBenchmark::query()->count())->toBe(0);

    $this->artisan('pu:curve-candidate:import-external-benchmark', [
        'version' => $candidate->id,
        'file' => $path,
        '--source-name' => 'Agente fiduciário',
        '--as-of' => '2026-01-06',
        '--actor' => (string) PuCandidateGovernanceFixture::checker()->id,
        '--write' => true,
    ])
        ->expectsOutputToContain('Action: '.PuExternalBenchmarkImportService::ACTION_IMPORTED)
        ->expectsOutputToContain('Writes: 1')
        ->assertSuccessful();

    expect(EmissionPuExternalBenchmark::query()->count())->toBe(1);
});

it('rejects a non numeric version argument in the import command', function () {
    $this->artisan('pu:curve-candidate:import-external-benchmark', [
        'version' => 'abc',
        'file' => PuCandidateGovernanceFixture::externalBenchmarkCsv(['2026-01-02' => '1000']),
        '--source-name' => 'Agente fiduciário',
        '--as-of' => '2026-01-06',
    ])->assertFailed();
});
