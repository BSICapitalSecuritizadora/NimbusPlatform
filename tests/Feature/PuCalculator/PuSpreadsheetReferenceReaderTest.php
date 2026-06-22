<?php

use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Enums\PuValidationStatus;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Domain\PuCalculator\Services\PuValidationService;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads raw values display values and formulas from a synthetic workbook', function () {
    $path = createSyntheticWorkbook([
        [
            'date_serial' => 46084,
            'updated' => ['style' => 2, 'raw' => '3.1234564', 'formula' => '1+2'],
            'total' => ['style' => 3, 'raw' => '31.23456789', 'formula' => 'B2*10'],
        ],
    ]);

    $rows = app(PuSpreadsheetReferenceReader::class)->read($path)['rows'];
    $metadata = $rows[0]->metadataFor('pu_updated');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->date->toDateString())->toBe('2026-03-03')
        ->and($rows[0]->updatedUnitValue)->toBe('3.1234564000000000')
        ->and($metadata)->not()->toBeNull()
        ->and($metadata?->cellReference)->toBe('B2')
        ->and($metadata?->rawValue)->toBe('3.1234564')
        ->and($metadata?->displayValue)->toBe('3.123456')
        ->and($metadata?->formula)->toBe('1+2')
        ->and($metadata?->displayScale)->toBe(6)
        ->and($rows[0]->metadataFor('total_value')?->formula)->toBe('B2*10');
});

it('supports display-scale and raw-scale validation modes', function () {
    $path = createSyntheticWorkbook([
        [
            'date_serial' => 46084,
            'updated' => ['style' => 2, 'raw' => '100.12345644', 'formula' => '10+90.12345644'],
        ],
    ]);

    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    EmissionPuDailyCurve::query()->insert([
        ...baseCurvePayload(
            emissionId: $emission->id,
            curveDate: '2026-03-03',
            updatedUnitValue: '100.12345649',
        ),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $displayReport = app(PuValidationService::class)->handle(
        $emission,
        $path,
        'v1',
        PuValidationMode::DisplayScale,
    );
    $rawReport = app(PuValidationService::class)->handle(
        $emission,
        $path,
        'v1',
        PuValidationMode::RawScale,
    );

    expect($displayReport->mode)->toBe(PuValidationMode::DisplayScale)
        ->and($displayReport->status)->toBe(PuValidationStatus::Approved)
        ->and($displayReport->totalDivergences)->toBe(0)
        ->and($rawReport->mode)->toBe(PuValidationMode::RawScale)
        ->and($rawReport->status)->toBe(PuValidationStatus::Rejected)
        ->and($rawReport->totalDivergences)->toBe(1)
        ->and($rawReport->largestDifferencesByField)->toHaveKey('pu_updated')
        ->and($rawReport->largestDifferencesByField['pu_updated']->spreadsheetFormula)->toBe('10+90.12345644')
        ->and($rawReport->largestDifferencesByField['pu_updated']->displayScale)->toBe(6);
});

it('filters the AMANI validation analysis to the requested date range', function () {
    $spreadsheetPath = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword('AMANI');
    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    persistReferenceRowsForSpreadsheetReader($emission, $referenceRows);

    $displayReport = app(PuValidationService::class)->handle(
        $emission,
        $spreadsheetPath,
        'v1',
        PuValidationMode::DisplayScale,
        CarbonImmutable::parse('2026-03-02'),
        CarbonImmutable::parse('2026-03-09'),
    );
    $rawReport = app(PuValidationService::class)->handle(
        $emission,
        $spreadsheetPath,
        'v1',
        PuValidationMode::RawScale,
        CarbonImmutable::parse('2026-03-02'),
        CarbonImmutable::parse('2026-03-09'),
    );

    $firstRowMetadata = collect($referenceRows)
        ->first(fn ($row) => $row->date->toDateString() === '2026-03-02')
        ?->metadataFor('total_value');

    expect($displayReport->totalRowsCompared)->toBe(8)
        ->and($displayReport->status)->toBe(PuValidationStatus::Approved)
        ->and($displayReport->mode)->toBe(PuValidationMode::DisplayScale)
        ->and($rawReport->totalRowsCompared)->toBe(8)
        ->and($rawReport->mode)->toBe(PuValidationMode::RawScale)
        ->and($firstRowMetadata)->not()->toBeNull()
        ->and($firstRowMetadata?->cellReference)->toBe('T11')
        ->and($firstRowMetadata?->displayScale)->toBe(8)
        ->and($firstRowMetadata?->formula)->toBeNull();
});

function createSyntheticWorkbook(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'pu-ref-');

    if ($path === false) {
        throw new RuntimeException('Unable to create temporary workbook path.');
    }

    unlink($path);
    $path .= '.xlsx';

    $zip = new ZipArchive;
    $result = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($result !== true) {
        throw new RuntimeException('Unable to create workbook archive.');
    }

    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML);
    $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
    $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="PuDiario" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML);
    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML);
    $zip->addFromString('xl/styles.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="2">
        <numFmt numFmtId="164" formatCode="0.000000"/>
        <numFmt numFmtId="165" formatCode="0.00000000"/>
    </numFmts>
    <fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>
    <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
    <borders count="1"><border/></borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="4">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
        <xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
    </cellXfs>
</styleSheet>
XML);
    $zip->addFromString('xl/worksheets/sheet1.xml', syntheticSheetXml($rows));
    $zip->close();

    return $path;
}

function syntheticSheetXml(array $rows): string
{
    $xmlRows = [
        '<row r="1">'
        .inlineCell('A1', 'Data')
        .inlineCell('B1', 'Valor Unitario Corrigido + Juros / Valor Unitario Atualizado')
        .inlineCell('C1', 'Valor Total')
        .'</row>',
    ];

    foreach ($rows as $index => $row) {
        $rowNumber = $index + 2;
        $xml = sprintf('<row r="%d">', $rowNumber);
        $xml .= sprintf('<c r="A%d" s="1"><v>%s</v></c>', $rowNumber, $row['date_serial']);

        if (isset($row['updated'])) {
            $xml .= numericCell("B{$rowNumber}", $row['updated']['style'], $row['updated']['raw'], $row['updated']['formula'] ?? null);
        }

        if (isset($row['total'])) {
            $xml .= numericCell("C{$rowNumber}", $row['total']['style'], $row['total']['raw'], $row['total']['formula'] ?? null);
        }

        $xml .= '</row>';
        $xmlRows[] = $xml;
    }

    return sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        %s
    </sheetData>
</worksheet>
XML, implode('', $xmlRows));
}

function inlineCell(string $reference, string $value): string
{
    return sprintf(
        '<c r="%s" t="inlineStr"><is><t>%s</t></is></c>',
        $reference,
        htmlspecialchars($value, ENT_XML1),
    );
}

function numericCell(string $reference, int $style, string $rawValue, ?string $formula = null): string
{
    $formulaXml = $formula !== null ? sprintf('<f>%s</f>', htmlspecialchars($formula, ENT_XML1)) : '';

    return sprintf(
        '<c r="%s" s="%d">%s<v>%s</v></c>',
        $reference,
        $style,
        $formulaXml,
        htmlspecialchars($rawValue, ENT_XML1),
    );
}

function baseCurvePayload(int $emissionId, string $curveDate, string $updatedUnitValue): array
{
    return [
        'emission_id' => $emissionId,
        'curve_date' => $curveDate,
        'calculation_version' => 'v1',
        'is_business_day' => true,
        'unit_base_value' => '0.0000000000000000',
        'unit_corrected_value' => '0.0000000000000000',
        'factor_di' => '1.0000000000000000',
        'factor_di_accumulated' => '1.0000000000000000',
        'factor_spread' => '1.0000000000000000',
        'factor_spread_di' => '1.0000000000000000',
        'interest_real_unit_value' => '0.0000000000000000',
        'updated_unit_value' => $updatedUnitValue,
        'amortization_ratio' => '0.0000000000000000',
        'amortization_unit_value' => '0.0000000000000000',
        'residual_unit_value' => '0.0000000000000000',
        'quantity' => '0.0000',
        'total_value' => '0.0000000000000000',
        'interest_payment_unit_value' => '0.0000000000000000',
        'interest_payment_value' => '0.0000000000000000',
        'payment_total_unit_value' => '0.0000000000000000',
        'payment_total_value' => '0.0000000000000000',
    ];
}

function persistReferenceRowsForSpreadsheetReader(Emission $emission, array $referenceRows, string $calculationVersion = 'v1'): void
{
    $timestamp = now();
    $rows = array_map(function ($row) use ($emission, $timestamp, $calculationVersion): array {
        $amortizationUnitValue = $row->amortizationUnitValue ?? '0.0000000000000000';
        $quantity = $row->quantity ?? '0.0000';

        return [
            'emission_id' => $emission->id,
            'curve_date' => $row->date->toDateString(),
            'calculation_version' => $calculationVersion,
            'is_business_day' => true,
            'unit_base_value' => $row->unitBaseValue ?? $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'unit_corrected_value' => $row->correctedUnitValue ?? $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'factor_di' => $row->factorDi ?? '1.0000000000000000',
            'factor_di_accumulated' => $row->factorDiAccumulated ?? '1.0000000000000000',
            'factor_spread' => $row->factorSpread ?? '1.0000000000000000',
            'factor_spread_di' => $row->factorSpreadDi ?? '1.0000000000000000',
            'interest_real_unit_value' => $row->interestRealUnitValue ?? '0.0000000000000000',
            'updated_unit_value' => $row->updatedUnitValue ?? '0.0000000000000000',
            'amortization_ratio' => '0.0000000000000000',
            'amortization_unit_value' => $amortizationUnitValue,
            'amortization_value' => bcmul($amortizationUnitValue, $quantity, 16),
            'residual_unit_value' => $row->residualUnitValue ?? '0.0000000000000000',
            'quantity' => $quantity,
            'total_value' => $row->totalValue ?? '0.0000000000000000',
            'interest_payment_unit_value' => $quantity !== '0.0000' && $row->paymentInterestTotal !== null
                ? bcdiv($row->paymentInterestTotal, $quantity, 16)
                : '0.0000000000000000',
            'interest_payment_value' => $row->paymentInterestTotal ?? '0.0000000000000000',
            'payment_total_unit_value' => $quantity !== '0.0000' && $row->paymentTotalValue !== null
                ? bcdiv($row->paymentTotalValue, $quantity, 16)
                : '0.0000000000000000',
            'payment_total_value' => $row->paymentTotalValue ?? '0.0000000000000000',
            'dup_correction' => $row->dupCorrection,
            'dut_correction' => $row->dutCorrection,
            'dup_interest' => $row->dupInterest,
            'dut_interest' => $row->dutInterest,
            'index_rate_date' => $row->indexRateDate?->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'event_original_date' => $row->eventOriginalDate?->toDateString(),
            'event_effective_date' => $row->eventDueDate?->toDateString(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }, $referenceRows);

    foreach (array_chunk($rows, 500) as $chunk) {
        EmissionPuDailyCurve::query()->insert($chunk);
    }
}
