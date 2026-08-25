<?php

use App\Domain\PuCalculator\DTOs\CdiSourceDataset;
use App\Domain\PuCalculator\DTOs\CdiSourceRecord;
use App\Domain\PuCalculator\Services\B3DiFileParser;
use App\Domain\PuCalculator\Services\CdiRateNormalizer;
use App\Domain\PuCalculator\Services\CdiSourceComparisonService;
use Carbon\CarbonImmutable;

function cdiDataset(string $source, array $records): CdiSourceDataset
{
    return new CdiSourceDataset(
        source: $source,
        requestedFrom: CarbonImmutable::parse('2025-01-01'),
        requestedTo: CarbonImmutable::parse('2025-12-31'),
        capturedAt: CarbonImmutable::parse('2026-08-25 12:00:00'),
        records: $records,
        payloads: [],
    );
}

function cdiRecord(string $source, string $date, string $raw, ?string $normalized, ?string $issue = null): CdiSourceRecord
{
    return new CdiSourceRecord(
        source: $source,
        referenceDate: CarbonImmutable::parse($date),
        sourceReference: sprintf('%s:%s', $source, $date),
        rawValue: $raw,
        normalizedValue: $normalized,
        issue: $issue,
    );
}

it('parses the documented B3 nine digit encoding without rounding', function (string $raw, string $expected) {
    $record = app(B3DiFileParser::class)->parse('20250102.txt', $raw);

    expect($record->referenceDate?->toDateString())->toBe('2025-01-02')
        ->and($record->rawValue)->toBe($raw)
        ->and($record->normalizedValue)->toBe($expected)
        ->and($record->issue)->toBeNull();
})->with([
    'normal value' => ['000001490', '14.90'],
    'leading zeroes' => ['000002320', '23.20'],
]);

it('rejects malformed B3 files', function (string $file, string $content, string $issue) {
    $record = app(B3DiFileParser::class)->parse($file, $content);

    expect($record->normalizedValue)->toBeNull()
        ->and($record->issue)->toBe($issue);
})->with([
    'empty file' => ['20250102.txt', '', 'empty_file'],
    'invalid value format' => ['20250102.txt', '14.90', 'invalid_b3_format'],
    'invalid date' => ['20250230.txt', '000001490', 'invalid_reference_date'],
    'invalid file name' => ['taxa.txt', '000001490', 'invalid_file_name'],
]);

it('detects duplicate B3 reference dates', function () {
    $parser = app(B3DiFileParser::class);
    $issues = $parser->duplicateIssues([
        $parser->parse('20250102.txt', '000001490', 'ftp://b3/first'),
        $parser->parse('20250102.txt', '000001491', 'ftp://b3/second'),
    ]);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['issue'])->toBe('duplicate_date')
        ->and($issues[0]['reference_date'])->toBe('2025-01-02')
        ->and($issues[0]['source_references'])->toBe(['ftp://b3/first', 'ftp://b3/second']);
});

it('normalizes only representation and rejects excess precision', function () {
    $normalizer = app(CdiRateNormalizer::class);

    expect($normalizer->fromPublishedDecimal('14,9'))->toBe(['value' => '14.90', 'issue' => null])
        ->and($normalizer->fromPublishedDecimal('14.8975'))->toBe([
            'value' => null,
            'issue' => 'precision_exceeds_official_scale',
        ]);
});

it('compares equal sets including the formal B3 representation transformation', function () {
    $b3Record = app(B3DiFileParser::class)->parse('20250102.txt', '000001490');
    $bcbRecord = cdiRecord('bcb_sgs_4389', '2025-01-02', '14.90', '14.90');

    $result = app(CdiSourceComparisonService::class)->compare(
        cdiDataset('b3_di', [$b3Record]),
        cdiDataset('bcb_sgs_4389', [$bcbRecord]),
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
    );

    expect($result->summary['present_equal'])->toBe(1)
        ->and($result->summary['present_different'])->toBe(0)
        ->and($result->rows[0]['b3_raw_value'])->toBe('000001490')
        ->and($result->rows[0]['bcb_raw_value'])->toBe('14.90');
});

it('reports an exact value divergence and its basis point difference', function () {
    $result = app(CdiSourceComparisonService::class)->compare(
        cdiDataset('b3_di', [cdiRecord('b3_di', '2025-01-02', '000001490', '14.90')]),
        cdiDataset('bcb_sgs_4389', [cdiRecord('bcb_sgs_4389', '2025-01-02', '14.91', '14.91')]),
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
    );

    expect($result->summary['present_different'])->toBe(1)
        ->and($result->summary['first_value_divergence']['date'])->toBe('2025-01-02')
        ->and($result->rows[0]['absolute_difference_percentage_points'])->toBe('0.01')
        ->and($result->rows[0]['difference_bps'])->toBe('1.00');
});

it('classifies dates present in only one source', function () {
    $result = app(CdiSourceComparisonService::class)->compare(
        cdiDataset('b3_di', [cdiRecord('b3_di', '2025-01-02', '000001490', '14.90')]),
        cdiDataset('bcb_sgs_4389', [cdiRecord('bcb_sgs_4389', '2025-01-03', '14.90', '14.90')]),
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
    );

    expect($result->summary['only_b3'])->toBe(1)
        ->and($result->summary['only_bcb'])->toBe(1)
        ->and($result->summary['only_b3_dates'])->toBe(['2025-01-02'])
        ->and($result->summary['only_bcb_dates'])->toBe(['2025-01-03']);
});

it('flags duplicate dates instead of silently accepting either value', function () {
    $result = app(CdiSourceComparisonService::class)->compare(
        cdiDataset('b3_di', [
            cdiRecord('b3_di', '2025-01-02', '000001490', '14.90'),
            cdiRecord('b3_di', '2025-01-02', '000001491', '14.91'),
        ]),
        cdiDataset('bcb_sgs_4389', [cdiRecord('bcb_sgs_4389', '2025-01-02', '14.90', '14.90')]),
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
    );

    expect($result->summary['duplicate_date'])->toBe(1)
        ->and($result->rows[0]['b3_occurrences'])->toBe(2);
});

it('flags an invalid value with precision different from the official scale', function () {
    $result = app(CdiSourceComparisonService::class)->compare(
        cdiDataset('b3_di', [cdiRecord('b3_di', '2025-01-02', '000001490', '14.90')]),
        cdiDataset('bcb_sgs_4389', [
            cdiRecord('bcb_sgs_4389', '2025-01-02', '14.8975', null, 'precision_exceeds_official_scale'),
        ]),
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
    );

    expect($result->summary['invalid_value'])->toBe(1);
});
