<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\SpreadsheetReferenceFieldData;
use App\Domain\PuCalculator\DTOs\SpreadsheetReferenceRowData;
use App\Domain\PuCalculator\ValueObjects\Decimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use SimpleXMLElement;
use ZipArchive;

class PuSpreadsheetReferenceReader
{
    private const XML_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @var array<int, string>
     */
    private const BUILT_IN_NUMBER_FORMATS = [
        0 => 'General',
        1 => '0',
        2 => '0.00',
        3 => '#,##0',
        4 => '#,##0.00',
        9 => '0%',
        10 => '0.00%',
        11 => '0.00E+00',
        12 => '# ?/?',
        13 => '# ??/??',
        14 => 'mm-dd-yy',
        15 => 'd-mmm-yy',
        16 => 'd-mmm',
        17 => 'mmm-yy',
        18 => 'h:mm AM/PM',
        19 => 'h:mm:ss AM/PM',
        20 => 'h:mm',
        21 => 'h:mm:ss',
        22 => 'm/d/yy h:mm',
        37 => '#,##0 ;(#,##0)',
        38 => '#,##0 ;[Red](#,##0)',
        39 => '#,##0.00;(#,##0.00)',
        40 => '#,##0.00;[Red](#,##0.00)',
        45 => 'mm:ss',
        46 => '[h]:mm:ss',
        47 => 'mmss.0',
        48 => '##0.0E+0',
        49 => '@',
    ];

    /**
     * @var array<string, array{header?: string, headers?: list<string>, property: string, raw_scale?: int, type: string}>
     */
    private const FIELD_DEFINITIONS = [
        'unit_base_value' => ['header' => 'valorunitariobase', 'property' => 'unitBaseValue', 'raw_scale' => DecimalRounder::UNIT_SCALE, 'type' => 'numeric'],
        'unit_corrected_value' => ['header' => 'valorunitariocorrigido', 'property' => 'correctedUnitValue', 'raw_scale' => DecimalRounder::UNIT_SCALE, 'type' => 'numeric'],
        'factor_di' => ['header' => 'fatordi', 'property' => 'factorDi', 'raw_scale' => DecimalRounder::FACTOR_SCALE, 'type' => 'numeric'],
        'factor_di_accumulated' => ['header' => 'fatordiacumulado', 'property' => 'factorDiAccumulated', 'raw_scale' => DecimalRounder::FACTOR_SCALE, 'type' => 'numeric'],
        'factor_spread' => ['header' => 'fatorspread', 'property' => 'factorSpread', 'raw_scale' => DecimalRounder::FACTOR_SCALE, 'type' => 'numeric'],
        'factor_spread_di' => ['header' => 'fatorspreadxdi', 'property' => 'factorSpreadDi', 'raw_scale' => DecimalRounder::FACTOR_SCALE, 'type' => 'numeric'],
        'pu_updated' => [
            'headers' => [
                'valorunitariocorrigidomaisjurosvalorunitarioatualizado',
                'valorunitariocorrigidojurosvalorunitarioatualizado',
            ],
            'property' => 'updatedUnitValue',
            'raw_scale' => DecimalRounder::UNIT_SCALE,
            'type' => 'numeric',
        ],
        'pu_residual' => ['header' => 'valorunitarioresidual', 'property' => 'residualUnitValue', 'raw_scale' => DecimalRounder::UNIT_SCALE, 'type' => 'numeric'],
        'interest_real' => ['header' => 'jurosreal', 'property' => 'interestRealUnitValue', 'raw_scale' => DecimalRounder::UNIT_SCALE, 'type' => 'numeric'],
        'amortization' => ['header' => 'amortizacaoreal', 'property' => 'amortizationUnitValue', 'raw_scale' => DecimalRounder::UNIT_SCALE, 'type' => 'numeric'],
        'quantity' => ['header' => 'quantidade', 'property' => 'quantity', 'raw_scale' => DecimalRounder::QUANTITY_SCALE, 'type' => 'numeric'],
        'total_value' => ['header' => 'valortotal', 'property' => 'totalValue', 'raw_scale' => DecimalRounder::TOTAL_SCALE, 'type' => 'numeric'],
        'payment_interest_total' => ['header' => 'pgtojurostotal', 'property' => 'paymentInterestTotal', 'raw_scale' => DecimalRounder::TOTAL_SCALE, 'type' => 'numeric'],
        'payment_amortization_principal_total' => ['header' => 'pgtoamortordprincipaltotal', 'property' => 'paymentAmortizationPrincipalTotal', 'raw_scale' => DecimalRounder::TOTAL_SCALE, 'type' => 'numeric'],
        'payment_amortization_correction_total' => ['header' => 'pgtoamortordcorrecaototal', 'property' => 'paymentAmortizationCorrectionTotal', 'raw_scale' => DecimalRounder::TOTAL_SCALE, 'type' => 'numeric'],
        'payment_total' => ['header' => 'pgtototalseminadimpencia', 'property' => 'paymentTotalValue', 'raw_scale' => DecimalRounder::TOTAL_SCALE, 'type' => 'numeric'],
        'event_original_date' => ['header' => 'dataoriginalevento', 'property' => 'eventOriginalDate', 'type' => 'date'],
        'event_due_date' => ['header' => 'datadevencimentodoevento', 'property' => 'eventDueDate', 'type' => 'date'],
        'index_rate_date' => ['header' => 'datadoindiceutilizado', 'property' => 'indexRateDate', 'type' => 'date'],
        'index_rate' => ['header' => 'valordoindiceutilizado', 'property' => 'indexRateValue', 'raw_scale' => DecimalRounder::RATE_SCALE, 'type' => 'numeric'],
        'dup_correction' => ['header' => 'dupcorrecao', 'property' => 'dupCorrection', 'type' => 'integer'],
        'dut_correction' => ['header' => 'dutcorrecao', 'property' => 'dutCorrection', 'type' => 'integer'],
        'dup_interest' => ['header' => 'dupjuros', 'property' => 'dupInterest', 'type' => 'integer'],
        'dut_interest' => ['header' => 'dutjuros', 'property' => 'dutInterest', 'type' => 'integer'],
    ];

    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * @return array{sheet_name: string, rows: list<SpreadsheetReferenceRowData>}
     */
    public function read(string $path, string $sheetName = 'PuDiario'): array
    {
        $worksheet = $this->readWorksheet($path, $sheetName);
        $headerRowNumber = null;
        $columnMap = [];

        foreach ($worksheet as $row) {
            $firstCellValue = $row['cells'][1]['display_value'] ?? null;

            if (($this->normalizeHeader($firstCellValue) ?? null) !== 'data') {
                continue;
            }

            $headerRowNumber = $row['row_number'];

            foreach ($row['cells'] as $columnIndex => $cell) {
                $columnMap[$this->normalizeHeader($cell['display_value']) ?? ''] = $columnIndex;
            }

            break;
        }

        if ($headerRowNumber === null) {
            return ['sheet_name' => $sheetName, 'rows' => []];
        }

        $referenceRows = [];

        foreach ($worksheet as $row) {
            if ($row['row_number'] <= $headerRowNumber) {
                continue;
            }

            $dateColumnIndex = $columnMap['data'] ?? 1;
            $date = $this->parseDateCell($row['cells'][$dateColumnIndex] ?? null);

            if ($date === null) {
                continue;
            }

            $properties = [
                'date' => $date,
            ];
            $fieldMetadata = [];

            foreach (self::FIELD_DEFINITIONS as $field => $definition) {
                $columnIndex = $this->resolveColumnIndex($columnMap, $definition);
                $cell = $columnIndex !== null ? ($row['cells'][$columnIndex] ?? null) : null;
                $metadata = $this->buildFieldMetadata($field, $cell, $definition['type']);

                if ($metadata !== null) {
                    $fieldMetadata[$field] = $metadata;
                }

                $properties[$definition['property']] = match ($definition['type']) {
                    'numeric' => $this->parseDecimalCell($cell, $definition['raw_scale'] ?? DecimalRounder::UNIT_SCALE),
                    'integer' => $this->parseIntegerCell($cell),
                    'date' => $this->parseDateCell($cell),
                    default => null,
                };
            }

            $referenceRows[] = new SpreadsheetReferenceRowData(
                date: $properties['date'],
                unitBaseValue: $properties['unitBaseValue'],
                correctedUnitValue: $properties['correctedUnitValue'],
                factorDi: $properties['factorDi'],
                factorDiAccumulated: $properties['factorDiAccumulated'],
                factorSpread: $properties['factorSpread'],
                factorSpreadDi: $properties['factorSpreadDi'],
                updatedUnitValue: $properties['updatedUnitValue'],
                residualUnitValue: $properties['residualUnitValue'],
                interestRealUnitValue: $properties['interestRealUnitValue'],
                amortizationUnitValue: $properties['amortizationUnitValue'],
                quantity: $properties['quantity'],
                totalValue: $properties['totalValue'],
                paymentInterestTotal: $properties['paymentInterestTotal'],
                paymentAmortizationPrincipalTotal: $properties['paymentAmortizationPrincipalTotal'],
                paymentAmortizationCorrectionTotal: $properties['paymentAmortizationCorrectionTotal'],
                paymentTotalValue: $properties['paymentTotalValue'],
                eventOriginalDate: $properties['eventOriginalDate'],
                eventDueDate: $properties['eventDueDate'],
                indexRateDate: $properties['indexRateDate'],
                indexRateValue: $properties['indexRateValue'],
                dupCorrection: $properties['dupCorrection'],
                dutCorrection: $properties['dutCorrection'],
                dupInterest: $properties['dupInterest'],
                dutInterest: $properties['dutInterest'],
                fieldMetadata: $fieldMetadata,
            );
        }

        return [
            'sheet_name' => $sheetName,
            'rows' => $referenceRows,
        ];
    }

    /**
     * @return list<array{
     *     row_number: int,
     *     cells: array<int, array{
     *         column_index: int,
     *         cell_reference: string,
     *         raw_value: mixed,
     *         display_value: ?string,
     *         formula: ?string,
     *         display_scale: ?int,
     *         number_format_code: ?string,
     *         is_date: bool
     *     }>
     * }>
     */
    private function readWorksheet(string $path, string $sheetName): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $styles = $this->readStyles($zip);
            $worksheetPath = $this->resolveWorksheetPath($zip, $sheetName);

            if ($worksheetPath === null) {
                return [];
            }

            $worksheetXml = $zip->getFromName($worksheetPath);

            if (! is_string($worksheetXml)) {
                return [];
            }

            $worksheet = simplexml_load_string($worksheetXml);

            if (! $worksheet instanceof SimpleXMLElement) {
                return [];
            }

            $worksheet->registerXPathNamespace('main', self::XML_NAMESPACE);
            $rowElements = $worksheet->xpath('/main:worksheet/main:sheetData/main:row') ?: [];
            $rows = [];

            foreach ($rowElements as $rowElement) {
                $rowElement->registerXPathNamespace('main', self::XML_NAMESPACE);
                $rowNumber = (int) ($rowElement['r'] ?? 0);
                $cells = [];
                $currentColumnIndex = 0;

                foreach ($rowElement->xpath('./main:c') ?: [] as $cellElement) {
                    $reference = trim((string) ($cellElement['r'] ?? ''));

                    if ($reference !== '') {
                        $currentColumnIndex = $this->columnIndexFromReference($reference);
                    } else {
                        $currentColumnIndex++;
                        $reference = sprintf('%s%d', $this->columnLetterFromIndex($currentColumnIndex), $rowNumber);
                    }

                    $styleIndex = ($styleIndexValue = (string) ($cellElement['s'] ?? '')) !== ''
                        ? (int) $styleIndexValue
                        : null;
                    $style = $styles[$styleIndex] ?? [
                        'display_scale' => null,
                        'number_format_code' => null,
                        'is_date' => false,
                    ];

                    $rawValue = $this->extractCellRawValue(
                        $cellElement,
                        (string) ($cellElement['t'] ?? ''),
                        $sharedStrings,
                        (bool) $style['is_date'],
                    );

                    $cells[$currentColumnIndex] = [
                        'column_index' => $currentColumnIndex,
                        'cell_reference' => $reference,
                        'raw_value' => $rawValue,
                        'display_value' => $this->displayValue($rawValue, $style['display_scale'], (bool) $style['is_date']),
                        'formula' => $this->extractFormula($cellElement),
                        'display_scale' => $style['display_scale'],
                        'number_format_code' => $style['number_format_code'],
                        'is_date' => (bool) $style['is_date'],
                    ];
                }

                $rows[] = [
                    'row_number' => $rowNumber,
                    'cells' => $cells,
                ];
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($xml)) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);

        if (! $sharedStrings instanceof SimpleXMLElement) {
            return [];
        }

        $values = [];

        $sharedStrings->registerXPathNamespace('main', self::XML_NAMESPACE);

        foreach ($sharedStrings->xpath('/main:sst/main:si') ?: [] as $item) {
            $values[] = $this->extractSharedStringValue($item);
        }

        return $values;
    }

    /**
     * @return array<int, array{display_scale: ?int, number_format_code: ?string, is_date: bool}>
     */
    private function readStyles(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');

        if (! is_string($xml)) {
            return [];
        }

        $styles = simplexml_load_string($xml);

        if (! $styles instanceof SimpleXMLElement) {
            return [];
        }

        $styles->registerXPathNamespace('main', self::XML_NAMESPACE);
        $customFormats = [];

        foreach ($styles->xpath('/main:styleSheet/main:numFmts/main:numFmt') ?: [] as $formatElement) {
            $customFormats[(int) $formatElement['numFmtId']] = (string) $formatElement['formatCode'];
        }

        $styleMap = [];
        $xfIndex = 0;

        foreach ($styles->xpath('/main:styleSheet/main:cellXfs/main:xf') ?: [] as $xfElement) {
            $numberFormatId = (int) ($xfElement['numFmtId'] ?? 0);
            $numberFormatCode = $customFormats[$numberFormatId] ?? self::BUILT_IN_NUMBER_FORMATS[$numberFormatId] ?? null;

            $styleMap[$xfIndex++] = [
                'display_scale' => $this->inferDisplayScale($numberFormatCode),
                'number_format_code' => $numberFormatCode,
                'is_date' => $this->isDateFormat($numberFormatCode),
            ];
        }

        return $styleMap;
    }

    private function resolveWorksheetPath(ZipArchive $zip, string $sheetName): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbookXml) || ! is_string($relationshipsXml)) {
            return null;
        }

        $workbook = simplexml_load_string($workbookXml);
        $relationships = simplexml_load_string($relationshipsXml);

        if (! $workbook instanceof SimpleXMLElement || ! $relationships instanceof SimpleXMLElement) {
            return null;
        }

        $workbook->registerXPathNamespace('main', self::XML_NAMESPACE);
        $workbook->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationships->registerXPathNamespace('relpkg', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationshipId = null;

        foreach ($workbook->xpath('/main:workbook/main:sheets/main:sheet') ?: [] as $sheet) {
            if ((string) $sheet['name'] !== $sheetName) {
                continue;
            }

            $relationshipId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            break;
        }

        if ($relationshipId === null) {
            return null;
        }

        foreach ($relationships->xpath('/relpkg:Relationships/relpkg:Relationship') ?: [] as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = ltrim((string) $relationship['Target'], '/');

            if (str_starts_with($target, 'xl/')) {
                return $target;
            }

            return 'xl/'.$target;
        }

        return null;
    }

    private function extractCellRawValue(
        SimpleXMLElement $cellElement,
        string $type,
        array $sharedStrings,
        bool $isDate,
    ): mixed {
        $cellElement->registerXPathNamespace('main', self::XML_NAMESPACE);
        $rawValue = trim((string) (($cellElement->xpath('./main:v')[0] ?? null)));
        $rawValue = $rawValue !== '' ? $rawValue : null;

        if ($type === 'inlineStr') {
            return $this->extractInlineStringValue($cellElement);
        }

        if ($type === 's' && $rawValue !== null && $rawValue !== '') {
            return $sharedStrings[(int) $rawValue] ?? null;
        }

        if ($type === 'b' && $rawValue !== null) {
            return $rawValue === '1' ? 'true' : 'false';
        }

        if ($type === 'd' && $rawValue !== null) {
            return CarbonImmutable::parse($rawValue)->toDateString();
        }

        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        if ($isDate) {
            return $this->excelSerialToDate($rawValue)?->toDateString();
        }

        return $rawValue;
    }

    private function extractFormula(SimpleXMLElement $cellElement): ?string
    {
        $cellElement->registerXPathNamespace('main', self::XML_NAMESPACE);
        $formula = trim((string) (($cellElement->xpath('./main:f')[0] ?? null)));

        return $formula !== '' ? $formula : null;
    }

    private function extractInlineStringValue(SimpleXMLElement $cellElement): ?string
    {
        $cellElement->registerXPathNamespace('main', self::XML_NAMESPACE);
        $inlineString = $cellElement->xpath('./main:is')[0] ?? null;

        if (! $inlineString instanceof SimpleXMLElement) {
            return null;
        }

        $inlineString->registerXPathNamespace('main', self::XML_NAMESPACE);
        $text = trim((string) (($inlineString->xpath('./main:t')[0] ?? null)));

        if ($text !== '') {
            return $text;
        }

        $fragments = [];

        foreach ($inlineString->xpath('./main:r') ?: [] as $run) {
            $run->registerXPathNamespace('main', self::XML_NAMESPACE);
            $fragments[] = (string) (($run->xpath('./main:t')[0] ?? null));
        }

        $combined = trim(implode('', $fragments));

        return $combined !== '' ? $combined : null;
    }

    private function extractSharedStringValue(SimpleXMLElement $sharedStringItem): string
    {
        $sharedStringItem->registerXPathNamespace('main', self::XML_NAMESPACE);
        $text = trim((string) (($sharedStringItem->xpath('./main:t')[0] ?? null)));

        if ($text !== '') {
            return $text;
        }

        $fragments = [];

        foreach ($sharedStringItem->xpath('./main:r') ?: [] as $run) {
            $run->registerXPathNamespace('main', self::XML_NAMESPACE);
            $fragments[] = (string) (($run->xpath('./main:t')[0] ?? null));
        }

        return trim(implode('', $fragments));
    }

    private function displayValue(mixed $rawValue, ?int $displayScale, bool $isDate): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        if ($isDate) {
            return is_string($rawValue) ? $rawValue : null;
        }

        if (! is_string($rawValue) || ! is_numeric($rawValue) || $displayScale === null) {
            return is_scalar($rawValue) ? (string) $rawValue : null;
        }

        return $this->rounder->round(Decimal::of($rawValue)->value(), $displayScale);
    }

    private function parseDateCell(?array $cell): ?CarbonImmutable
    {
        $rawValue = $cell['raw_value'] ?? null;

        if ($rawValue instanceof DateTimeInterface) {
            return CarbonImmutable::instance($rawValue);
        }

        if (! is_string($rawValue) || trim($rawValue) === '') {
            return null;
        }

        return CarbonImmutable::parse($rawValue);
    }

    private function parseDecimalCell(?array $cell, int $scale): ?string
    {
        $rawValue = $cell['raw_value'] ?? null;

        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        return $this->rounder->round(
            Decimal::of(is_string($rawValue) ? trim($rawValue) : $rawValue)->value(),
            $scale,
        );
    }

    private function parseIntegerCell(?array $cell): ?int
    {
        $rawValue = $cell['raw_value'] ?? null;

        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        return (int) $rawValue;
    }

    private function buildFieldMetadata(string $field, ?array $cell, string $type): ?SpreadsheetReferenceFieldData
    {
        if ($cell === null) {
            return null;
        }

        $rawValue = match ($type) {
            'date' => is_string($cell['raw_value'] ?? null) ? $cell['raw_value'] : null,
            'integer' => isset($cell['raw_value']) ? (string) $cell['raw_value'] : null,
            default => is_string($cell['raw_value'] ?? null) ? trim($cell['raw_value']) : null,
        };

        return new SpreadsheetReferenceFieldData(
            field: $field,
            cellReference: $cell['cell_reference'],
            rawValue: $rawValue,
            displayValue: $cell['display_value'],
            formula: $cell['formula'],
            displayScale: $cell['display_scale'],
            numberFormatCode: $cell['number_format_code'],
        );
    }

    private function inferDisplayScale(?string $numberFormatCode): ?int
    {
        if ($numberFormatCode === null) {
            return null;
        }

        if (strcasecmp($numberFormatCode, 'General') === 0 || $this->isDateFormat($numberFormatCode)) {
            return null;
        }

        $section = explode(';', $numberFormatCode)[0] ?? $numberFormatCode;
        $section = preg_replace('/"(?:[^"]|"")*"/', '', $section) ?? $section;
        $section = preg_replace('/\[[^\]]+\]/', '', $section) ?? $section;
        $section = preg_replace('/_.|\\\./', '', $section) ?? $section;
        $section = preg_replace('/\*./', '', $section) ?? $section;

        if (! is_string($section) || ! str_contains($section, '.')) {
            return 0;
        }

        $decimalPart = explode('.', $section, 2)[1] ?? '';
        preg_match_all('/[0#?]/', $decimalPart, $matches);

        return count($matches[0]);
    }

    private function isDateFormat(?string $numberFormatCode): bool
    {
        if ($numberFormatCode === null) {
            return false;
        }

        $normalized = strtolower($numberFormatCode);
        $normalized = preg_replace('/"(?:[^"]|"")*"/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\[[^\]]+\]/', '', $normalized) ?? $normalized;

        return preg_match('/(^|[^a-z])(d|dd|m|mm|mmm|mmmm|yy|yyyy|h|hh|s|ss)([^a-z]|$)/', $normalized) === 1;
    }

    private function excelSerialToDate(string $rawValue): ?CarbonImmutable
    {
        if (! is_numeric($rawValue)) {
            return null;
        }

        $baseDate = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC');
        $days = (int) floor((float) $rawValue);

        return $baseDate->addDays($days);
    }

    private function normalizeHeader(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = Str::of(Str::ascii(trim($value)))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    /**
     * @param  array<string, int>  $columnMap
     * @param  array{header?: string, headers?: list<string>, property: string, raw_scale?: int, type: string}  $definition
     */
    private function resolveColumnIndex(array $columnMap, array $definition): ?int
    {
        $headers = $definition['headers'] ?? [];

        if (isset($definition['header'])) {
            $headers[] = $definition['header'];
        }

        foreach ($headers as $header) {
            if (isset($columnMap[$header])) {
                return $columnMap[$header];
            }
        }

        return null;
    }

    private function columnIndexFromReference(string $reference): int
    {
        preg_match('/[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? '');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function columnLetterFromIndex(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(($index % 26) + 65).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }
}
